/*
 * Espace traiteur : aide à l'installation sur l'iPhone (Safari, espace pas encore installé).
 */
( function () {
	'use strict';
	var aide = document.getElementById( 'etr-installer-traiteur' );
	if ( ! aide ) {
		return;
	}
	var ios       = /iphone|ipad|ipod/i.test( navigator.userAgent ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
	var installee = document.documentElement.classList.contains( 'etr-installee' );
	var CLE       = 'etr-installer-traiteur-ferme';
	var fermee    = false;
	try {
		fermee = window.localStorage.getItem( CLE ) === '1';
	} catch ( e ) {}
	if ( ! ios || installee || fermee ) {
		return;
	}
	aide.hidden = false;
	aide.querySelector( '.etr-installer__fermer' ).addEventListener( 'click', function () {
		aide.hidden = true;
		try {
			window.localStorage.setItem( CLE, '1' );
		} catch ( e ) {}
	} );
}() );
