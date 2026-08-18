<?php
/**
 * Classe do produto do tipo Combo.
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Product_Combo
 *
 * Um combo é um produto próprio (com preço, peso e dimensões próprios) que
 * agrupa outros produtos. O estoque é DERIVADO dos componentes e, no pedido,
 * o combo é decomposto nos produtos individuais (ver Combo_Order).
 *
 * Regra principal: se QUALQUER componente estiver sem estoque, o combo inteiro
 * fica indisponível ("Fora de estoque") e não pode ser comprado.
 */
class WC_Product_Combo extends WC_Product {

	/**
	 * Cache do cálculo de estoque derivado (por instância).
	 *
	 * @var array|null
	 */
	protected $combo_stock_cache = null;

	/**
	 * Combos em processamento, para evitar recursão infinita caso um combo
	 * contenha outro combo como componente (referência circular).
	 *
	 * @var array
	 */
	protected static $resolving = array();

	/**
	 * Tipo do produto.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'combo';
	}

	/**
	 * Retorna os componentes do combo.
	 *
	 * Cada componente é um array:
	 *  - product_id   (int)    ID do produto (pai, se variável).
	 *  - variation_id (int)    ID da variação (quando mode = specific).
	 *  - mode         (string) simple | specific | choose.
	 *  - qty          (int)    Quantidade do componente por combo.
	 *
	 * @return array
	 */
	public function get_combo_components() {
		$components = $this->get_meta( '_combo_components', true );
		return is_array( $components ) ? $components : array();
	}

	/**
	 * Define os componentes do combo e mantém o índice reverso atualizado.
	 *
	 * O índice `_combo_component_ids` (uma linha de meta por ID) permite
	 * descobrir rapidamente quais combos usam um produto quando o estoque
	 * dele muda. Ver Combo_Stock.
	 *
	 * @param array $components Componentes.
	 */
	public function set_combo_components( $components ) {
		$components = is_array( $components ) ? $components : array();

		$this->update_meta_data( '_combo_components', $components );
		$this->combo_stock_cache = null;

		$this->delete_meta_data( '_combo_component_ids' );
		foreach ( $this->get_component_ids() as $id ) {
			$this->add_meta_data( '_combo_component_ids', $id, false );
		}
	}

	/**
	 * IDs de todos os produtos e variações envolvidos no combo.
	 *
	 * Inclui sempre o produto pai (necessário para o modo "cliente escolhe",
	 * em que a variação só é definida na hora da compra).
	 *
	 * @return array Lista de IDs únicos.
	 */
	public function get_component_ids() {
		$ids = array();

		foreach ( $this->get_combo_components() as $comp ) {
			$product_id = isset( $comp['product_id'] ) ? absint( $comp['product_id'] ) : 0;
			if ( $product_id ) {
				$ids[] = $product_id;
			}

			$variation_id = isset( $comp['variation_id'] ) ? absint( $comp['variation_id'] ) : 0;
			if ( $variation_id ) {
				$ids[] = $variation_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * O combo nunca é virtual (precisa de peso/dimensões para o frete).
	 *
	 * @return bool
	 */
	public function is_virtual() {
		return false;
	}

	/**
	 * O combo é comprável se tiver preço definido e existir.
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		return apply_filters( 'woocommerce_is_purchasable', ( '' !== $this->get_price() && $this->exists() ), $this );
	}

	/*
	|--------------------------------------------------------------------------
	| Estoque derivado dos componentes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Status de estoque derivado dos componentes.
	 *
	 * No contexto "edit" devolve o valor gravado no banco (usado pelo data
	 * store ao salvar e mantido em dia por Combo_Stock), para não interferir
	 * na gravação do meta `_stock_status`.
	 *
	 * @param string $context view | edit.
	 * @return string
	 */
	public function get_stock_status( $context = 'view' ) {
		if ( 'view' !== $context ) {
			return parent::get_stock_status( $context );
		}

		$status = $this->components_in_stock() ? 'instock' : 'outofstock';

		return apply_filters( 'woocommerce_product_get_stock_status', $status, $this );
	}

	/**
	 * Disponibilidade derivada dos componentes.
	 *
	 * @return bool
	 */
	public function is_in_stock() {
		return apply_filters( 'woocommerce_product_is_in_stock', $this->components_in_stock(), $this );
	}

	/**
	 * Verifica se TODOS os componentes têm estoque suficiente para N combos.
	 *
	 * @param int $combo_qty Quantidade de combos desejada.
	 * @return bool
	 */
	public function components_in_stock( $combo_qty = 1 ) {
		$max = $this->get_max_combos_available();

		return ( null === $max || $max >= max( 1, (int) $combo_qty ) );
	}

	/**
	 * Sobrescreve a checagem de estoque do WooCommerce (usada por WC_Cart).
	 *
	 * O combo não gerencia estoque próprio, então sem isto o WooCommerce
	 * aceitaria qualquer quantidade.
	 *
	 * @param int $quantity Quantidade.
	 * @return bool
	 */
	public function has_enough_stock( $quantity ) {
		return $this->components_in_stock( $quantity );
	}

	/**
	 * Limite do campo de quantidade na página do produto.
	 *
	 * @return int Quantidade máxima, ou -1 quando não há limite.
	 */
	public function get_max_purchase_quantity() {
		if ( $this->is_sold_individually() ) {
			return 1;
		}

		$max = $this->get_max_combos_available();

		return ( null === $max ) ? -1 : (int) $max;
	}

	/**
	 * Quantos combos completos ainda podem ser montados com o estoque atual.
	 *
	 * @return int|null Quantidade máxima, ou null quando não há limite
	 *                  (nenhum componente gerencia estoque).
	 */
	public function get_max_combos_available() {
		$data = $this->get_combo_stock_data();
		return $data['max'];
	}

	/**
	 * Nomes dos componentes que estão indisponíveis (sem estoque, apagados ou
	 * mal configurados). Usado para explicar ao cliente por que o combo está
	 * fora de estoque.
	 *
	 * @return array
	 */
	public function get_unavailable_components() {
		$data = $this->get_combo_stock_data();
		return $data['missing'];
	}

	/**
	 * Limpa o cache do cálculo de estoque desta instância.
	 */
	public function flush_combo_stock_cache() {
		$this->combo_stock_cache = null;
	}

	/**
	 * Calcula, uma única vez por instância, o estoque derivado do combo.
	 *
	 * @return array {
	 *     @type int|null $max     Combos possíveis (null = ilimitado, 0 = sem estoque).
	 *     @type array    $missing Nomes dos componentes indisponíveis.
	 * }
	 */
	protected function get_combo_stock_data() {
		if ( null !== $this->combo_stock_cache ) {
			return $this->combo_stock_cache;
		}

		$combo_id = $this->get_id();

		// Referência circular: trata como indisponível em vez de travar o site.
		if ( isset( self::$resolving[ $combo_id ] ) ) {
			return array(
				'max'     => 0,
				'missing' => array(),
			);
		}

		self::$resolving[ $combo_id ] = true;

		$components = $this->get_combo_components();
		$data       = array(
			'max'     => null,
			'missing' => array(),
		);

		// Combo sem componentes não pode ser vendido.
		if ( empty( $components ) ) {
			$data['max']                  = 0;
			$this->combo_stock_cache      = $data;
			unset( self::$resolving[ $combo_id ] );
			return $data;
		}

		foreach ( $components as $comp ) {
			$needed = max( 1, (int) ( isset( $comp['qty'] ) ? $comp['qty'] : 1 ) );
			$mode   = isset( $comp['mode'] ) ? $comp['mode'] : 'simple';

			if ( 'choose' === $mode ) {
				// Cliente escolhe: basta o pai ter ao menos uma variação disponível.
				$parent = wc_get_product( isset( $comp['product_id'] ) ? $comp['product_id'] : 0 );

				if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
					$data['max']       = 0;
					$data['missing'][] = $parent ? $parent->get_name() : __( 'Produto removido', 'combowoo' );
					continue;
				}

				$limit = $this->get_variable_stock_limit( $parent, $needed );
				$name  = $parent->get_name();
			} else {
				// Variação específica ou produto simples.
				$product_id = ( 'specific' === $mode && ! empty( $comp['variation_id'] ) )
					? (int) $comp['variation_id']
					: (int) ( isset( $comp['product_id'] ) ? $comp['product_id'] : 0 );

				$product = wc_get_product( $product_id );
				$limit   = $this->get_product_stock_limit( $product, $needed );
				$name    = $product ? $product->get_name() : __( 'Produto removido', 'combowoo' );
			}

			if ( 0 === $limit ) {
				$data['missing'][] = $name;
			}

			$data['max'] = self::lowest_limit( $data['max'], $limit );
		}

		$this->combo_stock_cache = $data;
		unset( self::$resolving[ $combo_id ] );

		return $data;
	}

	/**
	 * Quantos combos um produto simples (ou variação específica) comporta.
	 *
	 * @param WC_Product|false $product Produto do componente.
	 * @param int              $needed  Unidades necessárias por combo.
	 * @return int|null 0 = sem estoque, null = ilimitado.
	 */
	protected function get_product_stock_limit( $product, $needed ) {
		if ( ! $product || ! $product->is_in_stock() ) {
			return 0;
		}

		if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
			return null;
		}

		$qty = $product->get_stock_quantity();

		if ( null === $qty ) {
			return null;
		}

		if ( $qty < $needed ) {
			return 0;
		}

		return (int) floor( $qty / $needed );
	}

	/**
	 * Quantos combos um produto variável comporta no modo "cliente escolhe".
	 *
	 * Como o cliente escolhe UMA variação, vale a variação com maior estoque.
	 *
	 * @param WC_Product $variable_product Produto variável.
	 * @param int        $needed           Unidades necessárias por combo.
	 * @return int|null 0 = nenhuma variação disponível, null = ilimitado.
	 */
	protected function get_variable_stock_limit( $variable_product, $needed ) {
		$best = 0;

		foreach ( $variable_product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child || ! $child->is_purchasable() ) {
				continue;
			}

			$limit = $this->get_product_stock_limit( $child, $needed );

			if ( null === $limit ) {
				return null;
			}

			$best = max( $best, $limit );
		}

		return $best;
	}

	/**
	 * Menor limite entre dois valores, tratando null como "ilimitado".
	 *
	 * @param int|null $current Limite acumulado.
	 * @param int|null $limit   Novo limite.
	 * @return int|null
	 */
	protected static function lowest_limit( $current, $limit ) {
		if ( null === $limit ) {
			return $current;
		}

		if ( null === $current ) {
			return $limit;
		}

		return min( $current, $limit );
	}

	/**
	 * Verifica se um produto variável possui pelo menos uma variação
	 * comprável e com estoque suficiente.
	 *
	 * Mantido por compatibilidade com extensões que já usavam este método.
	 *
	 * @param WC_Product $variable_product Produto variável.
	 * @param int        $needed           Quantidade necessária.
	 * @return bool
	 */
	protected function variable_has_stock( $variable_product, $needed ) {
		$limit = $this->get_variable_stock_limit( $variable_product, $needed );

		return ( null === $limit || $limit > 0 );
	}

	/*
	|--------------------------------------------------------------------------
	| Botões
	|--------------------------------------------------------------------------
	*/

	/**
	 * Texto do botão no arquivo/loja.
	 *
	 * @return string
	 */
	public function add_to_cart_text() {
		$text = $this->is_purchasable() && $this->is_in_stock()
			? __( 'Comprar combo', 'combowoo' )
			: __( 'Sem estoque', 'combowoo' );

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * URL do botão no arquivo/loja: sempre a página do produto (pode exigir
	 * escolha de variação).
	 *
	 * @return string
	 */
	public function add_to_cart_url() {
		return apply_filters( 'woocommerce_product_add_to_cart_url', get_permalink( $this->get_id() ), $this );
	}

	/**
	 * Texto do botão na página do produto.
	 *
	 * @return string
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', __( 'Adicionar ao carrinho', 'combowoo' ), $this );
	}
}
