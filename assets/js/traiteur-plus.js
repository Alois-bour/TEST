/*
 * Formulaire de demande : partir d'un pack, quantités suggérées selon le nombre d'invités.
 * Les quantités respectent le maximum disponible de chaque article (attribut max).
 */
/* global etrQuantites */
( function () {
	'use strict';
	var C    = window.etrQuantites;
	var zone = document.getElementById( 'etr-aide-quantites' );
	var form = document.querySelector( '.etr-form' );
	if ( ! C || ! zone || ! form ) {
		return;
	}
	var message = zone.querySelector( '.etr-aide-quantites__message' );

	function invites() {
		var champ = form.querySelector( '#etr-nb_invites' );
		var n     = champ ? parseInt( champ.value, 10 ) : 0;
		return isNaN( n ) ? 0 : n;
	}

	function champQuantite( article ) {
		return form.querySelector( 'input[name="quantites[' + article + ']"]' );
	}

	// Pose une quantité (bornée au disponible) ; renvoie la quantité retenue, ou -1 si l'article est absent.
	function poser( article, quantite, seulementVide ) {
		var champ = champQuantite( article );
		if ( ! champ || champ.disabled ) {
			return -1;
		}
		if ( seulementVide && champ.value !== '' && parseInt( champ.value, 10 ) > 0 ) {
			return -2;
		}
		var max = champ.max === '' ? Infinity : parseInt( champ.max, 10 );
		var q   = Math.max( 0, Math.min( quantite, max ) );
		champ.value = q > 0 ? String( q ) : '';
		champ.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		champ.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		return q < quantite ? -3 : q;
	}

	function dire( texte ) {
		message.textContent = texte;
	}

	// Packs.
	var select = zone.querySelector( '#etr-pack' );
	var desc   = zone.querySelector( '[data-pack-description]' );
	function pack() {
		var id = select ? parseInt( select.value, 10 ) : 0;
		return ( C.packs || [] ).filter( function ( p ) {
			return p.id === id;
		} )[ 0 ];
	}
	if ( select ) {
		select.addEventListener( 'change', function () {
			var p = pack();
			desc.textContent = p ? p.description : '';
		} );
		zone.querySelector( '[data-appliquer-pack]' ).addEventListener( 'click', function () {
			var p = pack();
			if ( ! p ) {
				dire( 'Choisissez d’abord un pack.' );
				return;
			}
			var n         = invites();
			var parInvite = Object.keys( p.lignes ).some( function ( a ) {
				return p.lignes[ a ].invite;
			} );
			if ( parInvite && ! n ) {
				dire( 'Indiquez d’abord le nombre d’invités (étape 1) : certaines quantités du pack en dépendent.' );
				var champ = form.querySelector( '#etr-nb_invites' );
				if ( champ ) {
					champ.focus();
				}
				return;
			}
			var ajoutes = 0;
			var limites = 0;
			var absents = 0;
			Object.keys( p.lignes ).forEach( function ( article ) {
				var l   = p.lignes[ article ];
				var q   = Math.ceil( l.invite ? l.q * n : l.q );
				var r   = poser( article, q, false );
				if ( r === -1 ) {
					absents++;
				} else if ( r === -3 ) {
					limites++;
				} else if ( r > 0 ) {
					ajoutes++;
				}
			} );
			dire( ajoutes + limites + ' article(s) du pack « ' + p.titre + ' » ajouté(s).' + ( limites ? ' ' + limites + ' limité(s) au disponible sur vos dates.' : '' ) + ( absents ? ' ' + absents + ' indisponible(s).' : '' ) );
		} );
	}

	// Suggestions.
	var bouton = zone.querySelector( '[data-suggerer]' );
	if ( bouton ) {
		bouton.addEventListener( 'click', function () {
			var n = invites();
			if ( ! n ) {
				dire( 'Indiquez d’abord le nombre d’invités (étape 1).' );
				return;
			}
			var remplis = 0;
			Object.keys( C.ratios || {} ).forEach( function ( article ) {
				var r = poser( article, Math.ceil( C.ratios[ article ] * n ), true );
				if ( r > 0 || r === -3 ) {
					remplis++;
				}
			} );
			dire( remplis ? remplis + ' quantité(s) suggérée(s) pour ' + n + ' invités : ajustez-les librement.' : 'Rien à suggérer : les quantités sont déjà remplies.' );
		} );
	}
}() );
