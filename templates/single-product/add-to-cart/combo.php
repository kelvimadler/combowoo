<?php
/**
 * Formulário de adicionar combo ao carrinho.
 *
 * Este template pode ser sobrescrito copiando-o para
 * yourtheme/woocommerce/single-product/add-to-cart/combo.php
 *
 * Variáveis:
 *  - $product (WC_Product_Combo)
 *  - $choices (array) Componentes com variação escolhida pelo cliente.
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $product;

if ( ! $product->is_purchasable() ) {
	return;
}

echo wc_get_stock_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

// Um único componente esgotado deixa o combo inteiro indisponível.
if ( ! $product->is_in_stock() ) {
	$missing = $product->get_unavailable_components();

	if ( ! empty( $missing ) ) {
		printf(
			'<p class="combowoo-unavailable">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: lista de produtos sem estoque. */
					__( 'Este combo não está disponível porque estes itens estão sem estoque: %s.', 'combowoo' ),
					implode( ', ', array_unique( $missing ) )
				)
			)
		);
	}

	return;
}

do_action( 'woocommerce_before_add_to_cart_form' );
?>

<form class="cart combowoo-cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">

	<?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

	<?php if ( ! empty( $choices ) ) : ?>
		<div class="combowoo-choices">
			<?php foreach ( $choices as $index => $choice ) : ?>
				<p class="form-row combowoo-choice">
					<label for="combo_variation_<?php echo esc_attr( $index ); ?>">
						<?php
						/* translators: %s: nome do produto. */
						echo esc_html( sprintf( __( 'Escolha: %s', 'combowoo' ), $choice['label'] ) );
						?>
						<abbr class="required" title="<?php esc_attr_e( 'obrigatório', 'combowoo' ); ?>">*</abbr>
					</label>
					<select name="combo_variation[<?php echo esc_attr( $index ); ?>]" id="combo_variation_<?php echo esc_attr( $index ); ?>" required>
						<option value=""><?php esc_html_e( 'Selecione…', 'combowoo' ); ?></option>
						<?php foreach ( $choice['options'] as $vid => $label ) : ?>
							<option value="<?php echo esc_attr( $vid ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	if ( ! $product->is_sold_individually() ) {
		woocommerce_quantity_input(
			array(
				'min_value'   => apply_filters( 'woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product ),
				'max_value'   => apply_filters( 'woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product ),
				'input_value' => isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : $product->get_min_purchase_quantity(), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			),
			$product
		);
	}
	?>

	<button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="single_add_to_cart_button button alt combowoo-add-to-cart">
		<?php echo esc_html( $product->single_add_to_cart_text() ); ?>
	</button>

	<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
</form>

<?php
do_action( 'woocommerce_after_add_to_cart_form' );
