/*
 * Application de l'équipe : enregistrement du service worker et aide à l'installation sur iPhone.
 */
/* global etrApp */
( function () {
	'use strict';

	if ( ! window.etrApp ) {
		return;
	}

	// Classe « etr-installee » posée dès l'en-tête (ETR_PWA::balises), avant l'affichage.
	var installee = document.documentElement.classList.contains( 'etr-installee' );

	// Service worker (ouverture hors connexion, démarrage rapide).
	if ( 'serviceWorker' in navigator ) {
		window.addEventListener( 'load', function () {
			navigator.serviceWorker.register( etrApp.sw, { scope: etrApp.perimetre } ).catch( function () {} );
		} );
	}

	// Aide à l'installation : iPhone/iPad avec Safari, application pas encore installée.
	var aide = document.getElementById( 'etr-installer' );
	if ( ! aide ) {
		return;
	}
	var ios        = /iphone|ipad|ipod/i.test( navigator.userAgent ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
	var CLE        = 'etr-installer-ferme';
	var dejaFermee = false;
	try {
		dejaFermee = window.localStorage.getItem( CLE ) === '1';
	} catch ( e ) {}

	if ( ! ios || installee || dejaFermee ) {
		return;
	}
	aide.hidden = false;
	var fermer = aide.querySelector( '.etr-installer__fermer' );
	if ( fermer ) {
		fermer.addEventListener( 'click', function () {
			aide.hidden = true;
			try {
				window.localStorage.setItem( CLE, '1' );
			} catch ( e ) {}
		} );
	}
} )();
