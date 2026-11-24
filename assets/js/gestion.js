/* Espace de gestion : recherche instantanée dans le catalogue. */
( function () {
	'use strict';

	var champ = document.getElementById( 'etr-cat-q' );
	var cadre = document.getElementById( 'etr-cat-table' );
	if ( ! champ || ! cadre ) {
		return;
	}
	var vide    = document.getElementById( 'etr-cat-vide' );
	var lignes  = cadre.querySelectorAll( '.etr-cat-ligne' );
	var groupes = cadre.querySelectorAll( '.etr-table__groupe' );

	// Même normalisation que ETR_Gestion::cle_recherche().
	function normaliser( texte ) {
		return String( texte )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.replace( /œ/gi, 'oe' )
			.replace( /æ/gi, 'ae' )
			.replace( /ß/g, 'ss' )
			.toLowerCase()
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function filtrer() {
		var mots     = normaliser( champ.value ).split( ' ' ).filter( Boolean );
		var visibles = {};
		var total    = 0;

		Array.prototype.forEach.call( lignes, function ( ligne ) {
			var texte = ligne.getAttribute( 'data-recherche' );
			var ok    = mots.every( function ( mot ) {
				return -1 !== texte.indexOf( mot );
			} );
			ligne.hidden = ! ok;
			if ( ok ) {
				visibles[ ligne.getAttribute( 'data-groupe' ) ] = true;
				total++;
			}
		} );
		Array.prototype.forEach.call( groupes, function ( groupe ) {
			groupe.hidden = ! visibles[ groupe.getAttribute( 'data-groupe' ) ];
		} );
		cadre.hidden = 0 === total;
		if ( vide ) {
			vide.hidden = 0 !== total;
		}

		// L'adresse garde la recherche (rechargement, partage), sans recharger la page.
		if ( window.history && window.history.replaceState ) {
			var url = new URL( window.location.href );
			if ( champ.value.trim() ) {
				url.searchParams.set( 'q', champ.value.trim() );
			} else {
				url.searchParams.delete( 'q' );
			}
			window.history.replaceState( null, '', url.toString() );
		}
	}

	champ.addEventListener( 'input', filtrer );
	champ.form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		filtrer();
	} );
	champ.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && champ.value ) {
			champ.value = '';
			filtrer();
		}
	} );
} )();
