<?php
/**
 * Pedido: mantém o combo (pai) com o preço total e adiciona os produtos
 * componentes (filhos) a R$ 0,00, apenas para baixar o estoque de cada um.
 *
 * O WooCommerce baixa o estoque de todos os itens do pedido; o combo não
 * gerencia estoque próprio, então só os componentes têm baixa. O Bling
 * enxerga o combo (com o preço) + os produtos individuais (por SKU).
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combo_Order
 */
class Combo_Order {

	/**
	 * Registra hooks.
	 */
	public static function init() {
		// Checkout clássico (shortcode).
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'add_combo_meta_to_line_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'decompose_on_create' ), 20, 1 );

		// Checkout em blocos / Store API.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'decompose_store_api' ), 20, 1 );
	}

	/**
	 * Copia os dados resolvidos do combo para o item do pedido (checkout clássico).
	 *
	 * @param WC_Order_Item_Product $item          Item do pedido.
	 * @param string                $cart_item_key Chave do item no carrinho.
	 * @param array                 $values        Item do carrinho.
	 * @param WC_Order              $order         Pedido.
	 */
	public static function add_combo_meta_to_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['combo_data'] ) ) {
			$item->add_meta_data( '_combo_data', $values['combo_data'], true );
		}
	}

	/**
	 * Adiciona os componentes no checkout clássico (o WC salva depois deste hook).
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function decompose_on_create( $order ) {
		self::decompose_order( $order );
	}

	/**
	 * Adiciona os componentes no checkout via Store API (precisa salvar o pedido).
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function decompose_store_api( $order ) {
		self::attach_combo_data_from_cart( $order );
		if ( self::decompose_order( $order ) ) {
			$order->save();
		}
	}

	/**
	 * Anexa combo_data aos itens do pedido a partir do carrinho (Store API).
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected static function attach_combo_data_from_cart( $order ) {
		if ( ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart->get_cart();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( ! $product || ! $product->is_type( 'combo' ) || $item->get_meta( '_combo_data' ) ) {
				continue;
			}

			foreach ( $cart as $cart_item ) {
				if ( absint( $cart_item['product_id'] ) === $product->get_id() && ! empty( $cart_item['combo_data'] ) ) {
					$item->add_meta_data( '_combo_data', $cart_item['combo_data'], true );
					break;
				}
			}
		}
	}

	/**
	 * Para cada item combo do pedido, adiciona os produtos componentes a R$ 0,00.
	 * O combo (pai) PERMANECE no pedido carregando o preço total.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool True se algum combo foi processado.
	 */
	public static function decompose_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		if ( 'yes' === $order->get_meta( '_combowoo_decomposed' ) ) {
			return false;
		}

		$combo_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( $product && $product->is_type( 'combo' ) ) {
				$combo_items[ $item_id ] = $item;
			}
		}

		if ( empty( $combo_items ) ) {
			return false;
		}

		foreach ( $combo_items as $combo_item ) {
			self::add_component_items( $order, $combo_item );
			// O combo NÃO é removido: ele fica com o preço total.
		}

		$order->update_meta_data( '_combowoo_decomposed', 'yes' );

		return true;
	}

	/**
	 * Adiciona os itens dos componentes ao pedido, todos a R$ 0,00.
	 *
	 * @param WC_Order              $order      Pedido.
	 * @param WC_Order_Item_Product $combo_item Item do combo.
	 */
	protected static function add_component_items( $order, $combo_item ) {
		$combo_product = $combo_item->get_product();
		$combo_qty     = max( 1, (int) $combo_item->get_quantity() );
		$combo_data    = $combo_item->get_meta( '_combo_data' );

		if ( empty( $combo_data ) || ! is_array( $combo_data ) ) {
			$combo_data = self::resolve_default_combo_data( $combo_product );
		}

		if ( empty( $combo_data ) ) {
			return;
		}

		foreach ( $combo_data as $comp ) {
			$pid     = ! empty( $comp['variation_id'] ) ? $comp['variation_id'] : $comp['product_id'];
			$product = wc_get_product( $pid );

			if ( ! $product ) {
				continue;
			}

			$line_qty = max( 1, (int) $comp['qty'] ) * $combo_qty;

			$order_item = new WC_Order_Item_Product();
			$order_item->set_product( $product );
			$order_item->set_quantity( $line_qty );

			// Filho entra zerado — o preço fica todo no combo (pai).
			$order_item->set_subtotal( 0 );
			$order_item->set_total( 0 );

			// Atributos da variação (para exibição e clareza no pedido/NF).
			if ( $product->is_type( 'variation' ) ) {
				self::add_variation_meta( $order_item, $product );
			}

			// Referência ao combo de origem.
			$order_item->add_meta_data( __( 'Combo', 'combowoo' ), $combo_product->get_name(), true );
			$order_item->add_meta_data( '_combowoo_parent_id', $combo_product->get_id(), true );

			$order->add_item( $order_item );
		}
	}

	/**
	 * Adiciona os atributos da variação como meta legível no item do pedido.
	 *
	 * @param WC_Order_Item_Product $order_item Item do pedido.
	 * @param WC_Product_Variation  $variation  Variação.
	 */
	protected static function add_variation_meta( $order_item, $variation ) {
		foreach ( $variation->get_variation_attributes() as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$taxonomy = str_replace( 'attribute_', '', $name );

			if ( taxonomy_exists( $taxonomy ) ) {
				$term    = get_term_by( 'slug', $value, $taxonomy );
				$label   = wc_attribute_label( $taxonomy );
				$display = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
			} else {
				$label   = wc_attribute_label( str_replace( 'pa_', '', $taxonomy ) );
				$display = $value;
			}

			$order_item->add_meta_data( $label, $display, true );
		}
	}

	/**
	 * Resolve componentes padrão quando combo_data não está disponível
	 * (fallback: para "cliente escolhe", pega a primeira variação com estoque).
	 *
	 * @param WC_Product_Combo $combo_product Produto combo.
	 * @return array
	 */
	protected static function resolve_default_combo_data( $combo_product ) {
		if ( ! $combo_product || ! is_callable( array( $combo_product, 'get_combo_components' ) ) ) {
			return array();
		}

		$resolved = array();

		foreach ( $combo_product->get_combo_components() as $comp ) {
			$mode  = isset( $comp['mode'] ) ? $comp['mode'] : 'simple';
			$entry = array(
				'product_id'   => absint( $comp['product_id'] ),
				'variation_id' => 0,
				'qty'          => max( 1, (int) ( isset( $comp['qty'] ) ? $comp['qty'] : 1 ) ),
				'mode'         => $mode,
			);

			if ( 'specific' === $mode ) {
				$entry['variation_id'] = absint( $comp['variation_id'] );
			} elseif ( 'choose' === $mode ) {
				$parent = wc_get_product( $comp['product_id'] );
				if ( $parent && $parent->is_type( 'variable' ) ) {
					foreach ( $parent->get_children() as $vid ) {
						$variation = wc_get_product( $vid );
						if ( $variation && $variation->is_in_stock() ) {
							$entry['variation_id'] = $vid;
							break;
						}
					}
				}
			}

			$resolved[] = $entry;
		}

		return $resolved;
	}
}
