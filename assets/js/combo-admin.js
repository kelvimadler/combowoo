/* global jQuery, combowoo_admin */
( function ( $ ) {
	'use strict';

	/**
	 * Busca as variações de um produto e ajusta os controles da linha.
	 *
	 * @param {number} productId  ID do produto.
	 * @param {jQuery} $row        Linha do componente.
	 * @param {boolean} keepCurrent Manter a variação atualmente selecionada.
	 */
	function fetchVariations( productId, $row, keepCurrent ) {
		var $mode   = $row.find( '.combowoo-mode' );
		var $var    = $row.find( '.combowoo-variation' );
		var $simple = $row.find( '.combowoo-simple-label' );

		if ( ! productId ) {
			$mode.hide();
			$var.hide().html( '<option value="0">' + combowoo_admin.i18n.dash + '</option>' );
			$simple.hide();
			return;
		}

		$.get(
			combowoo_admin.ajax_url,
			{
				action: 'combowoo_get_variations',
				nonce: combowoo_admin.nonce,
				product_id: productId
			},
			function ( response ) {
				if ( response && response.success && response.data.is_variable ) {
					$simple.hide();
					$mode.show();

					var current = keepCurrent ? $var.val() : 0;
					var html    = '<option value="0">' + combowoo_admin.i18n.dash + '</option>';

					$.each( response.data.variations, function ( i, v ) {
						html += '<option value="' + v.id + '">' + v.label + '</option>';
					} );

					$var.html( html );

					if ( current ) {
						$var.val( current );
					}

					if ( 'specific' === $mode.val() ) {
						$var.show();
					} else {
						$var.hide();
					}
				} else {
					$mode.hide();
					$var.hide().html( '<option value="0">' + combowoo_admin.i18n.dash + '</option>' );
					$simple.show();
				}
			}
		);
	}

	$( function () {
		// Mostra as abas/campos nativos (preço, entrega, estoque) para o tipo combo.
		$( '.general_tab, .options_group.pricing, .shipping_tab, .inventory_tab' ).addClass( 'show_if_combo' );
		$( 'select#product-type' ).trigger( 'change' );

		// Trocar de produto: recarrega as variações.
		$( document ).on( 'change', '.combowoo-product', function () {
			var $row = $( this ).closest( '.combowoo-row' );
			fetchVariations( $( this ).val(), $row, false );
		} );

		// Trocar de modo: mostra/oculta o seletor de variação específica.
		$( document ).on( 'change', '.combowoo-mode', function () {
			var $row = $( this ).closest( '.combowoo-row' );
			if ( 'specific' === $( this ).val() ) {
				$row.find( '.combowoo-variation' ).show();
			} else {
				$row.find( '.combowoo-variation' ).hide();
			}
		} );

		// Adicionar linha.
		$( document ).on( 'click', '.combowoo-add-row', function ( e ) {
			e.preventDefault();
			var html = $( '#combowoo-row-template' ).html();
			var $row = $( html );
			$( '.combowoo-components-table tbody' ).append( $row );
			$( document.body ).trigger( 'wc-enhanced-select-init' );
		} );

		// Remover linha.
		$( document ).on( 'click', '.combowoo-remove-row', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.combowoo-row' ).remove();
		} );
	} );
} )( jQuery );
