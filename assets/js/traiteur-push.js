/*
 * Espace traiteur : notifications sur l'iPhone (espace installé sur l'écran d'accueil).
 * Abonnement enregistré par admin-ajax (les traiteurs n'ont pas accès à l'API REST).
 */
/* global etrTraiteurPush */
( function () {
	'use strict';
	var C    = window.etrTraiteurPush;
	var zone = document.getElementById( 'etr-push-traiteur' );
	if ( ! C || ! zone || ! ( 'serviceWorker' in navigator ) || ! ( 'PushManager' in window ) || ! ( 'Notification' in window ) ) {
		return;
	}
	var bouton  = zone.querySelector( '[data-push-activer]' );
	var etat    = zone.querySelector( '[data-push-etat]' );
	var CLE     = 'etr-push-traiteur-ferme';

	function cle( texte ) {
		var b64  = texte.replace( /-/g, '+' ).replace( /_/g, '/' );
		var brut = window.atob( b64 + '='.repeat( ( 4 - b64.length % 4 ) % 4 ) );
		var o    = new Uint8Array( brut.length );
		for ( var i = 0; i < brut.length; i++ ) {
			o[ i ] = brut.charCodeAt( i );
		}
		return o;
	}

	function envoyer( action, abonnement ) {
		var donnees = new FormData();
		donnees.append( 'action', 'etr_traiteur_push' );
		donnees.append( 'nonce', C.nonce );
		donnees.append( 'quoi', action );
		var json = abonnement.toJSON();
		donnees.append( 'endpoint', json.endpoint );
		donnees.append( 'p256dh', ( json.keys && json.keys.p256dh ) || '' );
		donnees.append( 'auth', ( json.keys && json.keys.auth ) || '' );
		return fetch( C.ajax, { method: 'POST', credentials: 'same-origin', body: donnees } ).then( function ( r ) {
			return r.json();
		} ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				throw new Error( ( r && r.data && r.data.message ) || 'Erreur' );
			}
		} );
	}

	var installee = document.documentElement.classList.contains( 'etr-installee' );
	var fermee    = false;
	try {
		fermee = window.localStorage.getItem( CLE ) === '1';
	} catch ( e ) {}

	navigator.serviceWorker.register( C.sw, { scope: C.portee } ).then( function ( enregistrement ) {
		return enregistrement.pushManager.getSubscription().then( function ( abonnement ) {
			if ( abonnement ) {
				envoyer( 'ajouter', abonnement ).catch( function () {} ); // Garde le compte à jour.
				return;
			}
			if ( Notification.permission === 'denied' || fermee || ( ! installee && C.ios ) ) {
				return;
			}
			zone.hidden = false;
			bouton.addEventListener( 'click', function () {
				bouton.disabled = true;
				Notification.requestPermission().then( function ( permission ) {
					if ( permission !== 'granted' ) {
						throw new Error( 'Notifications refusées dans les réglages de l’iPhone.' );
					}
					return enregistrement.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: cle( C.cle ) } );
				} ).then( function ( nouvel ) {
					return envoyer( 'ajouter', nouvel );
				} ).then( function () {
					etat.textContent = 'Notifications activées sur cet appareil.';
					bouton.hidden    = true;
					window.setTimeout( function () {
						zone.hidden = true;
					}, 2500 );
				} ).catch( function ( erreur ) {
					etat.textContent = erreur.message;
					bouton.disabled  = false;
				} );
			} );
		} );
	} ).catch( function () {} );

	var fermer = zone.querySelector( '.etr-installer__fermer' );
	if ( fermer ) {
		fermer.addEventListener( 'click', function () {
			zone.hidden = true;
			try {
				window.localStorage.setItem( CLE, '1' );
			} catch ( e ) {}
		} );
	}
}() );
