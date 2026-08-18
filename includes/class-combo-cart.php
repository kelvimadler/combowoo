<?php
/**
 * Carrinho: dados do item, validação e exibição.
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combo_Cart
 */
class Combo_Cart {

	/**
	 * Registra hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 10, 5 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'validate_update' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_from_session' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
	}

	/**
	 * Resolve os componentes escolhidos e guarda no item do carrinho.
	 *
	 * @param array $cart_item_data Dados do item.
	 * @param int   $product_id     ID do produto.
	 * @param int   $variation_id   ID da variação.
	 * @return array
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'combo' ) ) {
			return $cart_item_data;
		}

		$components = $product->get_combo_components();
		$resolved   = array();

		foreach ( $components as $i => $comp ) {
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
				$entry['variation_id'] = isset( $_POST['combo_variation'][ $i ] ) ? absint( wp_unslash( $_POST['combo_variation'][ $i ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validado em validate().
			}

			$resolved[] = $entry;
		}

		$cart_item_data['combo_data'] = $resolved;

		return $cart_item_data;
	}

	/**
	 * Valida a adição do combo ao carrinho.
	 *
	 * @param bool  $passed       Aprovado até aqui.
	 * @param int   $product_id   ID do produto.
	 * @param int   $quantity     Quantidade.
	 * @param int   $variation_id ID da variação.
	 * @param array $variations   Variações.
	 * @return bool
	 */
	public static function validate( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'combo' ) ) {
			return $passed;
		}

		$quantity   = max( 1, (int) $quantity );
		$components = $product->get_combo_components();

		if ( empty( $components ) ) {
			wc_add_notice( __( 'Este combo ainda não possui produtos configurados.', 'combowoo' ), 'error' );
			return false;
		}

		// O combo inteiro fica indisponível se qualquer componente faltar.
		if ( ! $product->components_in_stock() ) {
			wc_add_notice( self::out_of_stock_message( $product ), 'error' );
			return false;
		}

		foreach ( $components as $i => $comp ) {
			if ( ! isset( $comp['mode'] ) || 'choose' !== $comp['mode'] ) {
				continue;
			}

			$chosen = isset( $_POST['combo_variation'][ $i ] ) ? absint( wp_unslash( $_POST['combo_variation'][ $i ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( ! $chosen ) {
				wc_add_notice( __( 'Selecione todas as opções do combo antes de adicionar ao carrinho.', 'combowoo' ), 'error' );
				return false;
			}

			$variation = wc_get_product( $chosen );
			if ( ! $variation || (int) $variation->get_parent_id() !== absint( $comp['product_id'] ) ) {
				wc_add_notice( __( 'Variação inválida selecionada no combo.', 'combowoo' ), 'error' );
				return false;
			}

			if ( ! $variation->is_in_stock() ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: nome da variação. */
						__( '"%s" está sem estoque, então este combo não pode ser comprado.', 'combowoo' ),
						$variation->get_name()
					),
					'error'
				);
				return false;
			}

			// Estoque da variação escolhida para a quantidade pedida.
			$needed = max( 1, (int) ( isset( $comp['qty'] ) ? $comp['qty'] : 1 ) ) * $quantity;

			if ( ! $variation->has_enough_stock( $needed ) ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: nome da variação, 2: estoque disponível. */
						__( 'Não há estoque suficiente de "%1$s" para esta quantidade de combos (disponível: %2$s).', 'combowoo' ),
						$variation->get_name(),
						wc_stock_amount( $variation->get_stock_quantity() )
					),
					'error'
				);
				return false;
			}
		}

		// Quantidade pedida + a que já está no carrinho.
		$max = $product->get_max_combos_available();

		if ( null !== $max ) {
			$in_cart = self::get_combo_qty_in_cart( $product->get_id() );

			if ( ( $in_cart + $quantity ) > $max ) {
				if ( $in_cart >= $max ) {
					wc_add_notice(
						sprintf(
							/* translators: 1: nome do combo, 2: quantidade no carrinho. */
							__( 'Você já tem a quantidade máxima disponível do combo "%1$s" no carrinho (%2$d).', 'combowoo' ),
							$product->get_name(),
							$in_cart
						),
						'error'
					);
				} else {
					wc_add_notice(
						sprintf(
							/* translators: 1: nome do combo, 2: quantidade máxima. */
							__( 'O estoque dos produtos que compõem o combo "%1$s" permite no máximo %2$d unidade(s).', 'combowoo' ),
							$product->get_name(),
							$max
						),
						'error'
					);
				}

				return false;
			}
		}

		return $passed;
	}

	/**
	 * Mensagem de indisponibilidade citando os componentes em falta.
	 *
	 * @param WC_Product_Combo $product Combo.
	 * @return string
	 */
	public static function out_of_stock_message( $product ) {
		$missing = $product->get_unavailable_components();

		if ( empty( $missing ) ) {
			return sprintf(
				/* translators: %s: nome do combo. */
				__( 'O combo "%s" está sem estoque.', 'combowoo' ),
				$product->get_name()
			);
		}

		return sprintf(
			/* translators: 1: nome do combo, 2: lista de produtos sem estoque. */
			__( 'O combo "%1$s" está sem estoque porque estes itens acabaram: %2$s.', 'combowoo' ),
			$product->get_name(),
			implode( ', ', array_unique( $missing ) )
		);
	}

	/**
	 * Quantidade de um combo já presente no carrinho.
	 *
	 * @param int $combo_id ID do combo.
	 * @return int
	 */
	protected static function get_combo_qty_in_cart( $combo_id ) {
		if ( ! WC()->cart ) {
			return 0;
		}

		$qty = 0;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( absint( $cart_item['product_id'] ) === absint( $combo_id ) ) {
				$qty += (int) $cart_item['quantity'];
			}
		}

		return $qty;
	}

	/**
	 * Mostra os itens que compõem o combo no carrinho/checkout.
	 *
	 * @param array $item_data Dados exibidos.
	 * @param array $cart_item Item do carrinho.
	 * @return array
	 */
	public static function item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['combo_data'] ) ) {
			return $item_data;
		}

		foreach ( $cart_item['combo_data'] as $comp ) {
			$pid     = ! empty( $comp['variation_id'] ) ? $comp['variation_id'] : $comp['product_id'];
			$product = wc_get_product( $pid );

			if ( ! $product ) {
				continue;
			}

			$item_data[] = array(
				'key'   => __( 'Inclui', 'combowoo' ),
				'value' => wp_strip_all_tags( $product->get_name() ) . ' × ' . (int) $comp['qty'],
			);
		}

		return $item_data;
	}

	/**
	 * Restaura os dados do combo a partir da sessão.
	 *
	 * @param array $cart_item Item do carrinho.
	 * @param array $values    Valores salvos na sessão.
	 * @return array
	 */
	public static function get_from_session( $cart_item, $values ) {
		if ( isset( $values['combo_data'] ) ) {
			$cart_item['combo_data'] = $values['combo_data'];
		}
		return $cart_item;
	}

	/**
	 * Valida a alteração de quantidade na página do carrinho.
	 *
	 * @param bool   $passed        Aprovado até aqui.
	 * @param string $cart_item_key Chave do item.
	 * @param array  $values        Item do carrinho.
	 * @param int    $quantity      Nova quantidade.
	 * @return bool
	 */
	public static function validate_update( $passed, $cart_item_key, $values, $quantity ) {
		$product = isset( $values['data'] ) ? $values['data'] : null;

		if ( ! $product || ! $product->is_type( 'combo' ) || $quantity <= 0 ) {
			return $passed;
		}

		if ( ! $product->components_in_stock() ) {
			wc_add_notice( self::out_of_stock_message( $product ), 'error' );
			return false;
		}

		if ( ! $product->components_in_stock( $quantity ) ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: nome do combo, 2: quantidade máxima. */
					__( 'O estoque dos produtos que compõem o combo "%1$s" permite no máximo %2$d unidade(s).', 'combowoo' ),
					$product->get_name(),
					(int) $product->get_max_combos_available()
				),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Revalida o estoque dos combos no carrinho/checkout.
	 *
	 * Além de checar cada combo isoladamente, soma o que TODOS os itens do
	 * carrinho consomem de cada produto (combos + itens avulsos) para impedir
	 * venda acima do estoque quando o mesmo produto aparece em mais de um item.
	 */
	public static function check_cart_items() {
		if ( ! WC()->cart ) {
			return;
		}

		$has_combo = false;
		$required  = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			$quantity = max( 1, (int) $cart_item['quantity'] );

			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'combo' ) ) {
				$has_combo = true;

				// 1) O combo inteiro precisa estar disponível.
				if ( ! $product->components_in_stock() ) {
					wc_add_notice( self::out_of_stock_message( $product ), 'error' );
					continue;
				}

				// 2) E precisa haver estoque para a quantidade no carrinho.
				if ( ! $product->components_in_stock( $quantity ) ) {
					wc_add_notice(
						sprintf(
							/* translators: 1: nome do combo, 2: quantidade no carrinho, 3: quantidade máxima. */
							__( 'Você tem %2$d unidade(s) do combo "%1$s" no carrinho, mas o estoque dos produtos que o compõem permite apenas %3$d.', 'combowoo' ),
							$product->get_name(),
							$quantity,
							(int) $product->get_max_combos_available()
						),
						'error'
					);
					continue;
				}

				// 3) Acumula o consumo de cada componente.
				$combo_data = ! empty( $cart_item['combo_data'] ) ? $cart_item['combo_data'] : array();

				foreach ( $combo_data as $comp ) {
					$pid = ! empty( $comp['variation_id'] ) ? absint( $comp['variation_id'] ) : absint( $comp['product_id'] );

					if ( ! $pid ) {
						continue;
					}

					$needed           = max( 1, (int) $comp['qty'] ) * $quantity;
					$required[ $pid ] = isset( $required[ $pid ] ) ? $required[ $pid ] + $needed : $needed;
				}

				continue;
			}

			// Item avulso: também consome estoque do mesmo produto.
			$pid = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : absint( $cart_item['product_id'] );

			if ( $pid ) {
				$required[ $pid ] = isset( $required[ $pid ] ) ? $required[ $pid ] + $quantity : $quantity;
			}
		}

		// Sem combos no carrinho o WooCommerce já faz a checagem padrão.
		if ( ! $has_combo ) {
			return;
		}

		foreach ( $required as $pid => $needed ) {
			$product = wc_get_product( $pid );

			if ( ! $product ) {
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: nome do produto. */
						__( '"%s" está sem estoque e faz parte de um combo no seu carrinho.', 'combowoo' ),
						$product->get_name()
					),
					'error'
				);
				continue;
			}

			if ( ! $product->has_enough_stock( $needed ) ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: nome do produto, 2: estoque disponível, 3: quantidade necessária. */
						__( 'Não há estoque suficiente de "%1$s" (disponível: %2$s, necessário: %3$d somando os combos do carrinho).', 'combowoo' ),
						$product->get_name(),
						wc_stock_amount( $product->get_stock_quantity() ),
						$needed
					),
					'error'
				);
			}
		}
	}
}
