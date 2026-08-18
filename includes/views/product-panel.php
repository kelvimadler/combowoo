<?php
/**
 * Painel da aba "Combo" no editor de produto.
 *
 * Variáveis disponíveis:
 *  - $components (array) Componentes já salvos.
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="combowoo_product_data" class="panel woocommerce_options_panel hidden">

	<div class="options_group">
		<p class="form-field">
			<label style="font-weight:600;"><?php esc_html_e( 'Componentes do combo', 'combowoo' ); ?></label>
		</p>
		<p class="form-field combowoo-help">
			<?php esc_html_e( 'Defina o preço, o peso e as dimensões do combo nas abas "Geral" e "Entrega". No pedido, o combo mantém o preço total e estes produtos entram como linhas a R$ 0,00 (cada um com seu SKU) — apenas para baixar o estoque de cada item no WooCommerce e no Bling.', 'combowoo' ); ?>
		</p>
	</div>

	<div class="options_group">
		<table class="widefat combowoo-components-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Produto', 'combowoo' ); ?></th>
					<th><?php esc_html_e( 'Variação', 'combowoo' ); ?></th>
					<th class="combowoo-col-qty"><?php esc_html_e( 'Qtd', 'combowoo' ); ?></th>
					<th class="combowoo-col-actions">&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( ! empty( $components ) ) {
					foreach ( $components as $i => $comp ) {
						echo Combo_Admin::render_component_row( $i, $comp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro do método.
					}
				}
				?>
			</tbody>
		</table>
		<p class="combowoo-add-wrap">
			<button type="button" class="button combowoo-add-row">+ <?php esc_html_e( 'Adicionar produto', 'combowoo' ); ?></button>
		</p>
	</div>

	<script type="text/template" id="combowoo-row-template">
		<?php echo Combo_Admin::render_component_row( '__i__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro do método. ?>
	</script>
</div>
