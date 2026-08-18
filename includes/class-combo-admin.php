<?php
/**
 * Administração: aba do combo, salvamento e AJAX.
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combo_Admin
 */
class Combo_Admin {

	/**
	 * Registra hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_data_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_combowoo_get_variations', array( __CLASS__, 'ajax_get_variations' ) );
	}

	/**
	 * Adiciona a aba "Combo" no data box do produto.
	 *
	 * @param array $tabs Abas.
	 * @return array
	 */
	public static function product_data_tabs( $tabs ) {
		$tabs['combo'] = array(
			'label'    => __( 'Combo', 'combowoo' ),
			'target'   => 'combowoo_product_data',
			'class'    => array( 'show_if_combo', 'hide_if_simple', 'hide_if_variable', 'hide_if_grouped', 'hide_if_external' ),
			'priority' => 21,
		);
		return $tabs;
	}

	/**
	 * Renderiza o painel da aba "Combo".
	 */
	public static function product_data_panel() {
		global $post;

		$product    = $post ? wc_get_product( $post->ID ) : null;
		$components = ( $product && $product->is_type( 'combo' ) ) ? $product->get_combo_components() : array();

		include COMBOWOO_PATH . 'includes/views/product-panel.php';
	}

	/**
	 * Renderiza uma linha de componente (usada no carregamento e no template JS).
	 *
	 * @param mixed $index Índice (não usado nos names, que são arrays).
	 * @param array $comp  Dados do componente.
	 * @return string HTML já escapado.
	 */
	public static function render_component_row( $index = 0, $comp = array() ) {
		$product_id   = isset( $comp['product_id'] ) ? absint( $comp['product_id'] ) : 0;
		$mode         = isset( $comp['mode'] ) ? $comp['mode'] : 'simple';
		$variation_id = isset( $comp['variation_id'] ) ? absint( $comp['variation_id'] ) : 0;
		$qty          = isset( $comp['qty'] ) ? max( 1, (int) $comp['qty'] ) : 1;

		$product     = $product_id ? wc_get_product( $product_id ) : null;
		$is_variable = $product && $product->is_type( 'variable' );

		ob_start();
		?>
		<tr class="combowoo-row">
			<td>
				<select class="wc-product-search combowoo-product" name="combo_component_product[]"
					data-placeholder="<?php esc_attr_e( 'Buscar produto…', 'combowoo' ); ?>"
					data-action="woocommerce_json_search_products" style="width: 260px;">
					<option value=""></option>
					<?php if ( $product ) : ?>
						<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected">
							<?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?>
						</option>
					<?php endif; ?>
				</select>
			</td>
			<td class="combowoo-variation-cell">
				<select class="combowoo-mode" name="combo_component_mode[]" <?php echo $is_variable ? '' : 'style="display:none;"'; ?>>
					<option value="specific" <?php selected( $mode, 'specific' ); ?>><?php esc_html_e( 'Vender variação específica', 'combowoo' ); ?></option>
					<option value="choose" <?php selected( $mode, 'choose' ); ?>><?php esc_html_e( 'Cliente escolhe a variação', 'combowoo' ); ?></option>
				</select>
				<select class="combowoo-variation" name="combo_component_variation[]" <?php echo ( $is_variable && 'specific' === $mode ) ? '' : 'style="display:none;"'; ?>>
					<option value="0">&mdash;</option>
					<?php
					if ( $is_variable ) {
						foreach ( $product->get_children() as $vid ) {
							$variation = wc_get_product( $vid );
							if ( ! $variation ) {
								continue;
							}
							printf(
								'<option value="%d" %s>%s</option>',
								(int) $vid,
								selected( $variation_id, $vid, false ),
								esc_html( wc_get_formatted_variation( $variation, true ) )
							);
						}
					}
					?>
				</select>
				<span class="combowoo-simple-label" <?php echo $is_variable ? 'style="display:none;"' : ''; ?>>
					<?php esc_html_e( 'Produto simples', 'combowoo' ); ?>
				</span>
			</td>
			<td>
				<input type="number" class="combowoo-qty" name="combo_component_qty[]" value="<?php echo esc_attr( $qty ); ?>" min="1" step="1" style="width:70px;">
			</td>
			<td>
				<button type="button" class="button combowoo-remove-row" aria-label="<?php esc_attr_e( 'Remover', 'combowoo' ); ?>">&times;</button>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Salva os campos do combo no objeto do produto.
	 *
	 * @param WC_Product $product Produto.
	 */
	public static function save( $product ) {
		if ( ! $product instanceof WC_Product || 'combo' !== $product->get_type() ) {
			return;
		}

		$components = array();

		if ( isset( $_POST['combo_component_product'] ) && is_array( $_POST['combo_component_product'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado pelo meta box do WC.
			$products   = array_map( 'absint', (array) wp_unslash( $_POST['combo_component_product'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$modes      = isset( $_POST['combo_component_mode'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['combo_component_mode'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$variations = isset( $_POST['combo_component_variation'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['combo_component_variation'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$qtys       = isset( $_POST['combo_component_qty'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['combo_component_qty'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			foreach ( $products as $i => $pid ) {
				$pid = absint( $pid );
				if ( ! $pid ) {
					continue;
				}

				$qty  = isset( $qtys[ $i ] ) ? max( 1, (int) $qtys[ $i ] ) : 1;
				$mode = isset( $modes[ $i ] ) ? $modes[ $i ] : 'simple';
				$prod = wc_get_product( $pid );

				if ( ! $prod ) {
					continue;
				}

				if ( $prod->is_type( 'variable' ) && in_array( $mode, array( 'specific', 'choose' ), true ) ) {
					if ( 'choose' === $mode ) {
						$components[] = array(
							'product_id'   => $pid,
							'variation_id' => 0,
							'mode'         => 'choose',
							'qty'          => $qty,
						);
					} else {
						$vid = isset( $variations[ $i ] ) ? absint( $variations[ $i ] ) : 0;
						if ( ! $vid ) {
							continue;
						}
						$components[] = array(
							'product_id'   => $pid,
							'variation_id' => $vid,
							'mode'         => 'specific',
							'qty'          => $qty,
						);
					}
				} else {
					$components[] = array(
						'product_id'   => $pid,
						'variation_id' => 0,
						'mode'         => 'simple',
						'qty'          => $qty,
					);
				}
			}
		}

		// Usa o setter para manter o índice reverso `_combo_component_ids`.
		$product->set_combo_components( $components );

		// O combo nunca gerencia estoque próprio (é derivado dos componentes).
		$product->set_manage_stock( false );
		$product->set_stock_status( $product->components_in_stock() ? 'instock' : 'outofstock' );
	}

	/**
	 * Enfileira scripts e estilos no editor de produto.
	 *
	 * @param string $hook Hook atual.
	 */
	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'combowoo-admin', COMBOWOO_URL . 'assets/css/combo-admin.css', array(), COMBOWOO_VERSION );
		wp_enqueue_script( 'combowoo-admin', COMBOWOO_URL . 'assets/js/combo-admin.js', array( 'jquery', 'wc-enhanced-select' ), COMBOWOO_VERSION, true );

		wp_localize_script(
			'combowoo-admin',
			'combowoo_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'combowoo_admin' ),
				'i18n'     => array(
					'dash'   => '—',
					'simple' => __( 'Produto simples', 'combowoo' ),
				),
			)
		);
	}

	/**
	 * AJAX: retorna as variações de um produto variável.
	 */
	public static function ajax_get_variations() {
		check_ajax_referer( 'combowoo_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error();
		}

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$product    = wc_get_product( $product_id );
		$result     = array(
			'is_variable' => false,
			'variations'  => array(),
		);

		if ( $product && $product->is_type( 'variable' ) ) {
			$result['is_variable'] = true;
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child ) {
					continue;
				}
				$label                   = wc_get_formatted_variation( $child, true );
				$result['variations'][] = array(
					'id'    => $child_id,
					'label' => $label ? $label : ( '#' . $child_id ),
				);
			}
		}

		wp_send_json_success( $result );
	}
}
