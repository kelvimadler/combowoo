<?php
/**
 * Plugin Name: ComboWoo - Combos Dinâmicos para WooCommerce
 * Plugin URI: https://github.com/kelvimadler/combowoo
 * Description: Cria produtos do tipo "Combo" que agrupam produtos simples e variáveis. Vende como combo no front (preço, peso e dimensões próprios); no pedido, o combo permanece com o preço total e os produtos componentes entram a R$ 0,00 (com seus SKUs) — assim o WooCommerce e o Bling dão baixa no estoque de cada produto.
 * Version: 2.0.1
 * Author: Kelvim Adler
 * Author URI: https://github.com/kelvimadler
 * Text Domain: combowoo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * WC requires at least: 6.0
 * WC tested up to: 9.5
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COMBOWOO_VERSION', '2.0.1' );
define( 'COMBOWOO_FILE', __FILE__ );
define( 'COMBOWOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'COMBOWOO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declara compatibilidade com HPOS (armazenamento de pedidos em tabelas próprias).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', COMBOWOO_FILE, true );
		}
	}
);

/**
 * Inicializa o plugin depois que o WooCommerce estiver carregado.
 */
add_action( 'plugins_loaded', 'combowoo_init' );
function combowoo_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'ComboWoo precisa do WooCommerce ativo para funcionar.', 'combowoo' ) . '</p></div>';
			}
		);
		return;
	}

	require_once COMBOWOO_PATH . 'includes/class-wc-product-combo.php';
	require_once COMBOWOO_PATH . 'includes/class-combo-admin.php';
	require_once COMBOWOO_PATH . 'includes/class-combo-cart.php';
	require_once COMBOWOO_PATH . 'includes/class-combo-order.php';
	require_once COMBOWOO_PATH . 'includes/class-combo-frontend.php';
	require_once COMBOWOO_PATH . 'includes/class-combo-stock.php';

	// Registra o tipo de produto "combo".
	add_filter( 'woocommerce_product_class', 'combowoo_product_class', 10, 2 );
	add_filter( 'product_type_selector', 'combowoo_product_type_selector' );
	add_filter(
		'woocommerce_data_stores',
		function ( $stores ) {
			$stores['product-combo'] = 'WC_Product_Data_Store_CPT';
			return $stores;
		}
	);

	Combo_Admin::init();
	Combo_Cart::init();
	Combo_Order::init();
	Combo_Frontend::init();
	Combo_Stock::init();
}

/**
 * Mapeia o tipo "combo" para a classe do produto.
 *
 * @param string $classname    Classe do produto.
 * @param string $product_type Tipo do produto.
 * @return string
 */
function combowoo_product_class( $classname, $product_type ) {
	if ( 'combo' === $product_type ) {
		$classname = 'WC_Product_Combo';
	}
	return $classname;
}

/**
 * Adiciona "Combo" ao seletor de tipo de produto no admin.
 *
 * @param array $types Tipos de produto.
 * @return array
 */
function combowoo_product_type_selector( $types ) {
	$types['combo'] = __( 'Combo', 'combowoo' );
	return $types;
}
