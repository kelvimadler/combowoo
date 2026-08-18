<?php
/**
 * Front-end: formulário de adicionar combo ao carrinho.
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combo_Frontend
 */
class Combo_Frontend {

	/**
	 * Registra hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_combo_add_to_cart', array( __CLASS__, 'add_to_cart_template' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enfileira o CSS do front (apenas em páginas de produto).
	 */
	public static function enqueue() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_style( 'combowoo-frontend', COMBOWOO_URL . 'assets/css/combo-frontend.css', array(), COMBOWOO_VERSION );
		}
	}

	/**
	 * Renderiza o formulário de compra do combo, com os seletores de variação
	 * para os componentes marcados como "cliente escolhe".
	 */
	public static function add_to_cart_template() {
		global $product;

		if ( ! $product || ! $product->is_type( 'combo' ) ) {
			return;
		}

		$components = $product->get_combo_components();
		$choices    = array();

		foreach ( $components as $i => $comp ) {
			if ( ! isset( $comp['mode'] ) || 'choose' !== $comp['mode'] ) {
				continue;
			}

			$parent = wc_get_product( $comp['product_id'] );
			if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
				continue;
			}

			$options = array();
			foreach ( $parent->get_children() as $vid ) {
				$variation = wc_get_product( $vid );
				if ( ! $variation || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
					continue;
				}
				$options[ $vid ] = wc_get_formatted_variation( $variation, true );
			}

			if ( ! empty( $options ) ) {
				$choices[ $i ] = array(
					'label'   => $parent->get_name(),
					'options' => $options,
				);
			}
		}

		wc_get_template(
			'single-product/add-to-cart/combo.php',
			array(
				'product' => $product,
				'choices' => $choices,
			),
			'',
			COMBOWOO_PATH . 'templates/'
		);
	}
}
