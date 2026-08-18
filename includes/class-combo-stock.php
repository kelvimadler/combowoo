<?php
/**
 * Sincronização do estoque: propaga a disponibilidade dos componentes para o
 * status de estoque gravado do combo.
 *
 * O combo já calcula a disponibilidade em tempo real (WC_Product_Combo), mas o
 * WooCommerce usa o meta `_stock_status` (e a tabela wc_product_meta_lookup) em
 * consultas — loja, "ocultar produtos fora de estoque", filtros do admin, REST
 * e feeds. Sem esta sincronização, um combo continuaria listado como disponível
 * depois que um componente esgotasse.
 *
 * @package ComboWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combo_Stock
 */
class Combo_Stock {

	/**
	 * Versão do índice reverso `_combo_component_ids`.
	 */
	const INDEX_VERSION = '1';

	/**
	 * Trava contra recursão durante a sincronização.
	 *
	 * @var bool
	 */
	protected static $syncing = false;

	/**
	 * Registra hooks.
	 */
	public static function init() {
		// Estoque de um produto simples ou variação foi alterado.
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_object_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_object_change' ) );

		// Status de estoque alterado (inclusive manualmente no admin).
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock_status_change' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_stock_status_change' ), 10, 3 );

		// Baixa/estorno de estoque em pedidos e alterações via REST/importador.
		add_action( 'woocommerce_updated_product_stock', array( __CLASS__, 'on_stock_id_change' ) );

		// Produto componente apagado ou restaurado.
		add_action( 'trashed_post', array( __CLASS__, 'on_stock_id_change' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_stock_id_change' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_stock_id_change' ) );

		// Constrói o índice de combos criados antes desta versão.
		add_action( 'admin_init', array( __CLASS__, 'maybe_reindex_all' ) );
	}

	/**
	 * Handler dos hooks que recebem o objeto do produto.
	 *
	 * @param WC_Product $product Produto.
	 */
	public static function on_stock_object_change( $product ) {
		if ( $product instanceof WC_Product ) {
			self::sync_combos_containing( $product->get_id() );
		}
	}

	/**
	 * Handler dos hooks de mudança de status de estoque.
	 *
	 * @param int        $product_id   ID do produto.
	 * @param string     $stock_status Novo status (não usado).
	 * @param WC_Product $product      Produto (não usado).
	 */
	public static function on_stock_status_change( $product_id, $stock_status = '', $product = null ) {
		self::sync_combos_containing( absint( $product_id ) );
	}

	/**
	 * Handler dos hooks que recebem apenas o ID.
	 *
	 * @param int $product_id ID do produto.
	 */
	public static function on_stock_id_change( $product_id ) {
		self::sync_combos_containing( absint( $product_id ) );
	}

	/**
	 * Recalcula o status de estoque de todos os combos que usam um produto.
	 *
	 * @param int $product_id ID do produto ou da variação.
	 */
	public static function sync_combos_containing( $product_id ) {
		if ( self::$syncing || ! $product_id ) {
			return;
		}

		$ids     = array( $product_id );
		$product = wc_get_product( $product_id );

		// Variação: o combo pode referenciar o pai (modo "cliente escolhe").
		if ( $product && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$ids[] = $parent_id;
			}
		} elseif ( ! $product ) {
			// Produto apagado: ainda pode ter sido uma variação.
			$parent_id = absint( wp_get_post_parent_id( $product_id ) );
			if ( $parent_id ) {
				$ids[] = $parent_id;
			}
		}

		$combo_ids = self::find_combos_by_component( $ids );

		if ( empty( $combo_ids ) ) {
			return;
		}

		self::$syncing = true;

		foreach ( $combo_ids as $combo_id ) {
			self::sync_combo( $combo_id );
		}

		self::$syncing = false;
	}

	/**
	 * Grava no combo o status de estoque derivado dos componentes.
	 *
	 * @param int|WC_Product $combo Combo ou ID.
	 * @return bool True se o status mudou.
	 */
	public static function sync_combo( $combo ) {
		$combo = is_numeric( $combo ) ? wc_get_product( $combo ) : $combo;

		if ( ! $combo || ! $combo->is_type( 'combo' ) ) {
			return false;
		}

		$combo->flush_combo_stock_cache();

		$status = $combo->components_in_stock() ? 'instock' : 'outofstock';

		if ( $status === $combo->get_stock_status( 'edit' ) ) {
			return false;
		}

		// O combo nunca gerencia estoque próprio (é derivado dos componentes).
		$combo->set_manage_stock( false );
		$combo->set_stock_status( $status );
		$combo->save();

		return true;
	}

	/**
	 * Localiza os combos que contêm algum dos IDs informados.
	 *
	 * @param array $ids IDs de produtos/variações.
	 * @return array IDs dos combos.
	 */
	protected static function find_combos_by_component( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$combo_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_combo_component_ids' AND meta_value IN ( {$placeholders} )",
				$ids
			)
		);
		// phpcs:enable

		return array_filter( array_map( 'absint', (array) $combo_ids ) );
	}

	/**
	 * Reconstrói o índice reverso e o status de estoque de todos os combos.
	 *
	 * Roda uma única vez por versão do índice — combos salvos antes desta
	 * versão não possuem `_combo_component_ids`.
	 */
	public static function maybe_reindex_all() {
		if ( get_option( 'combowoo_index_version' ) === self::INDEX_VERSION ) {
			return;
		}

		self::reindex_all();

		update_option( 'combowoo_index_version', self::INDEX_VERSION );
	}

	/**
	 * Reindexa e ressincroniza todos os combos existentes.
	 *
	 * @return int Quantidade de combos processados.
	 */
	public static function reindex_all() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$combo_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_combo_components'"
		);
		// phpcs:enable

		$count         = 0;
		self::$syncing = true;

		foreach ( array_map( 'absint', (array) $combo_ids ) as $combo_id ) {
			$combo = wc_get_product( $combo_id );

			if ( ! $combo || ! $combo->is_type( 'combo' ) ) {
				continue;
			}

			// Reaplicar os componentes regrava o índice `_combo_component_ids`.
			$combo->set_combo_components( $combo->get_combo_components() );
			$combo->set_manage_stock( false );
			$combo->set_stock_status( $combo->components_in_stock() ? 'instock' : 'outofstock' );
			$combo->save();

			++$count;
		}

		self::$syncing = false;

		return $count;
	}
}
