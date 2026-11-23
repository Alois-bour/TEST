/**
 * Espace Traiteur : choix d'images dans la médiathèque (page Réglages).
 */
( function ( $ ) {
	'use strict';

	$( '.etr-media' ).each( function () {
		var bloc = $( this );
		var champ = bloc.find( '.etr-media__id' );
		var apercu = bloc.find( '.etr-media__apercu' );
		var retirer = bloc.find( '.etr-media__retirer' );
		var cadre = null;

		bloc.find( '.etr-media__choisir' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! cadre ) {
				cadre = wp.media( {
					title: bloc.data( 'titre' ),
					library: { type: 'image' },
					multiple: false
				} );
				cadre.on( 'select', function () {
					var image = cadre.state().get( 'selection' ).first().toJSON();
					var taille = ( image.sizes && ( image.sizes.medium || image.sizes.full ) ) || image;
					champ.val( image.id );
					apercu.html( $( '<img>', { src: taille.url, alt: '', css: { maxWidth: '240px', height: 'auto', display: 'block' } } ) );
					retirer.prop( 'hidden', false );
				} );
			}
			cadre.open();
		} );

		retirer.on( 'click', function ( e ) {
			e.preventDefault();
			champ.val( '0' );
			apercu.empty();
			retirer.prop( 'hidden', true );
		} );
	} );
} )( jQuery );
