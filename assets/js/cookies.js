/*
 * Bouton flottant des extensions de cookies (CookieYes, Complianz…) : masqué dans l'application
 * sur téléphone (il recouvrait la barre d'onglets, voir espace-traiteur.css) ; le lien « Cookies »
 * du compte rouvre le même réglage. Le bandeau de première visite n'est jamais masqué.
 */
( function () {
	'use strict';
	var SELECTEURS = [
		'.cky-btn-revisit-wrapper', // CookieYes.
		'#cookie-law-info-again', // CookieYes (ancienne version).
		'.cmplz-manage-consent', // Complianz.
		'#moove_gdpr_save_popup_settings_button', // GDPR Cookie Compliance (Moove).
		'.cn-revoke-cookie', // Cookie Notice.
		'#BorlabsCookieWidget', // Borlabs.
		'.brlbs-cmpnt-widget', // Borlabs 3.
		'.iubenda-tp-btn', // iubenda.
		'#axeptio_main_button', // Axeptio.
		'#CookiebotWidget', // Cookiebot.
	];
	var liens = document.querySelectorAll( 'a[href$="#etr-cookies"]' );
	if ( ! liens.length ) {
		return;
	}

	function bouton() {
		for ( var i = 0; i < SELECTEURS.length; i++ ) {
			var b = document.querySelector( SELECTEURS[ i ] );
			if ( b ) {
				return b.querySelector( 'button, a' ) || b;
			}
		}
		return null;
	}
	function ouvrir() {
		if ( typeof window.revisitCkyConsent === 'function' ) {
			window.revisitCkyConsent();
			return true;
		}
		if ( window.Cookiebot && typeof window.Cookiebot.renew === 'function' ) {
			window.Cookiebot.renew();
			return true;
		}
		var b = bouton();
		if ( b ) {
			b.click(); // Fonctionne même masqué.
			return true;
		}
		return false;
	}
	function disponible() {
		return !! ( bouton() || typeof window.revisitCkyConsent === 'function' || ( window.Cookiebot && window.Cookiebot.renew ) );
	}
	// Le lien n'apparaît que si le site a une extension de cookies connue (elle s'installe parfois tard).
	function afficher() {
		var ok = disponible();
		liens.forEach( function ( a ) {
			var li = a.closest( 'li' ) || a;
			li.hidden = ! ok;
		} );
		return ok;
	}
	if ( ! afficher() ) {
		window.addEventListener( 'load', function () {
			if ( ! afficher() ) {
				setTimeout( afficher, 2500 );
			}
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var lien = e.target.closest( 'a[href$="#etr-cookies"]' );
		if ( ! lien ) {
			return;
		}
		e.preventDefault();
		var feuille = lien.closest( 'dialog' );
		if ( feuille && feuille.open ) {
			var fermer = feuille.querySelector( '[data-fermer-feuille]' );
			if ( fermer ) {
				fermer.click(); // La feuille « Plus » se ferme, le panneau des cookies s'ouvre dessus.
			} else {
				feuille.close();
			}
		}
		setTimeout( ouvrir, feuille ? 250 : 0 );
	} );
}() );
