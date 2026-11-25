/*
 * Notifications de l'application de l'équipe : activer, tester ou désactiver sur cet appareil,
 * et inviter à les activer dans l'application installée.
 *
 * Sur iPhone, les notifications ne sont possibles que dans l'application installée sur
 * l'écran d'accueil (iOS 16.4 ou plus), et l'autorisation se demande après un toucher.
 */
/* global etrNotifs */
( function () {
	'use strict';

	if ( ! window.etrNotifs ) {
		return;
	}

	var installee = document.documentElement.classList.contains( 'etr-installee' );
	var ios       = /iphone|ipad|ipod/i.test( navigator.userAgent ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
	var possible  = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

	/* ------------------------------------------------------------------ */
	/* Invitation (toutes les pages)                                      */
	/* ------------------------------------------------------------------ */

	var invite = document.getElementById( 'etr-notifs-invite' );
	var CLE    = 'etr-notifs-invite-fermee';
	if ( invite && possible && installee && Notification.permission === 'default' ) {
		var fermee = false;
		try {
			fermee = window.localStorage.getItem( CLE ) === '1';
		} catch ( e ) {}
		if ( ! fermee ) {
			invite.hidden = false;
			invite.querySelector( '.etr-installer__fermer' ).addEventListener( 'click', function () {
				invite.hidden = true;
				try {
					window.localStorage.setItem( CLE, '1' );
				} catch ( e ) {}
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Types d'alertes : un interrupteur par type, enregistré aussitôt    */
	/* ------------------------------------------------------------------ */

	var preferences = document.querySelector( '[data-etr-preferences]' );
	if ( preferences ) {
		preferences.addEventListener( 'change', function () {
			var sans = Array.prototype.filter.call( preferences.querySelectorAll( 'input' ), function ( champ ) {
				return ! champ.checked;
			} ).map( function ( champ ) {
				return champ.value;
			} );
			fetch( etrNotifs.api + 'preferences', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': etrNotifs.nonce },
				body: JSON.stringify( { sans: sans } )
			} ).then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error();
				}
				if ( window.etrToast ) {
					window.etrToast( 'Préférences enregistrées.' );
				}
			} ).catch( function () {
				if ( window.etrToast ) {
					window.etrToast( 'Préférences non enregistrées : vérifiez la connexion.', 'erreur' );
				}
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Page « Notifications »                                             */
	/* ------------------------------------------------------------------ */

	var etat = document.getElementById( 'etr-notifs-etat' );
	if ( ! etat ) {
		return;
	}
	var boutons = {
		activer: document.getElementById( 'etr-notifs-activer' ),
		test: document.getElementById( 'etr-notifs-test' ),
		desactiver: document.getElementById( 'etr-notifs-desactiver' )
	};

	function afficher( texte, type, visibles ) {
		etat.textContent = texte;
		etat.className   = 'etr-notifs__etat' + ( type ? ' etr-notifs__etat--' + type : '' );
		Object.keys( boutons ).forEach( function ( cle ) {
			boutons[ cle ].hidden   = ( visibles || [] ).indexOf( cle ) === -1;
			boutons[ cle ].disabled = false;
		} );
	}

	function occupe( bouton ) {
		Object.keys( boutons ).forEach( function ( cle ) {
			boutons[ cle ].disabled = true;
		} );
		bouton.setAttribute( 'aria-busy', 'true' );
	}

	function appel( chemin, methode, donnees ) {
		return fetch( etrNotifs.api + chemin, {
			method: methode,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': etrNotifs.nonce },
			body: JSON.stringify( donnees )
		} ).then( function ( reponse ) {
			return reponse.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! reponse.ok ) {
					throw new Error( json.message || ( 'HTTP ' + reponse.status ) );
				}
				return json;
			} );
		} );
	}

	function cleServeur() {
		var texte  = etrNotifs.cle.replace( /-/g, '+' ).replace( /_/g, '/' );
		var brut   = window.atob( texte + '='.repeat( ( 4 - texte.length % 4 ) % 4 ) );
		var octets = new Uint8Array( brut.length );
		for ( var i = 0; i < brut.length; i++ ) {
			octets[ i ] = brut.charCodeAt( i );
		}
		return octets;
	}

	function nomAppareil() {
		var ua = navigator.userAgent;
		if ( /iphone/i.test( ua ) ) {
			return 'iPhone';
		}
		if ( /ipad/i.test( ua ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 ) ) {
			return 'iPad';
		}
		if ( /android/i.test( ua ) ) {
			return 'Android';
		}
		if ( /macintosh/i.test( ua ) ) {
			return 'Mac';
		}
		return /windows/i.test( ua ) ? 'PC Windows' : 'Ordinateur';
	}

	function enregistrer( abonnement ) {
		var json = abonnement.toJSON();
		return appel( 'appareil', 'POST', { endpoint: json.endpoint, keys: json.keys, appareil: nomAppareil() } );
	}

	function abonnementActuel() {
		return navigator.serviceWorker.ready.then( function ( enregistrement ) {
			return enregistrement.pushManager.getSubscription().then( function ( abonnement ) {
				return { enregistrement: enregistrement, abonnement: abonnement };
			} );
		} );
	}

	function etatActif( message ) {
		afficher( message || 'Les notifications sont activées sur cet appareil.', 'ok', [ 'test', 'desactiver' ] );
	}

	function verifier() {
		if ( ! possible ) {
			if ( ios && ! installee ) {
				afficher( 'Sur iPhone, les notifications fonctionnent dans l’application installée : dans Safari, touchez « Partager » puis « Sur l’écran d’accueil », ouvrez l’application depuis son icône et revenez sur cette page.', 'info' );
			} else {
				afficher( 'Cet appareil ne gère pas les notifications. Sur iPhone, il faut iOS 16.4 ou plus et l’application installée sur l’écran d’accueil.', 'erreur' );
			}
			return;
		}
		if ( Notification.permission === 'denied' ) {
			afficher( ios
				? 'Les notifications sont bloquées pour cette application. Ouvrez Réglages › Notifications, choisissez l’application et activez « Autoriser les notifications », puis revenez ici.'
				: 'Les notifications sont bloquées pour ce site dans le navigateur. Autorisez-les dans les réglages du site, puis rechargez la page.', 'erreur' );
			return;
		}
		abonnementActuel().then( function ( etatSw ) {
			if ( etatSw.abonnement && Notification.permission === 'granted' ) {
				// Le site garde la trace de cet appareil sur le bon compte (changement de personne, réinstallation).
				return enregistrer( etatSw.abonnement ).then( function () {
					etatActif();
				} );
			}
			afficher( 'Les notifications ne sont pas activées sur cet appareil.', '', [ 'activer' ] );
		} ).catch( function ( erreur ) {
			afficher( 'Impossible de vérifier cet appareil : ' + erreur.message, 'erreur', [ 'activer' ] );
		} );
	}

	function tester() {
		return abonnementActuel().then( function ( etatSw ) {
			if ( ! etatSw.abonnement ) {
				throw new Error( 'cet appareil n’est plus abonné' );
			}
			return appel( 'test', 'POST', { endpoint: etatSw.abonnement.endpoint } );
		} );
	}

	boutons.activer.addEventListener( 'click', function () {
		occupe( boutons.activer );
		// L'autorisation doit être demandée directement après le toucher (exigence d'iOS).
		Notification.requestPermission().then( function ( reponse ) {
			if ( reponse !== 'granted' ) {
				verifier();
				return null;
			}
			return navigator.serviceWorker.ready.then( function ( enregistrement ) {
				return enregistrement.pushManager.getSubscription().then( function ( existant ) {
					return existant || enregistrement.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: cleServeur() } );
				} );
			} ).then( enregistrer ).then( tester ).then( function () {
				etatActif( 'Notifications activées : une notification de test vient d’être envoyée.' );
			} );
		} ).catch( function ( erreur ) {
			afficher( 'L’activation n’a pas abouti : ' + erreur.message, 'erreur', [ 'activer' ] );
		} ).then( function () {
			boutons.activer.removeAttribute( 'aria-busy' );
		} );
	} );

	boutons.test.addEventListener( 'click', function () {
		occupe( boutons.test );
		tester().then( function () {
			etatActif( 'Notification de test envoyée : elle arrive dans quelques secondes.' );
		} ).catch( function ( erreur ) {
			afficher( erreur.message, 'erreur', [ 'test', 'desactiver' ] );
		} ).then( function () {
			boutons.test.removeAttribute( 'aria-busy' );
		} );
	} );

	boutons.desactiver.addEventListener( 'click', function () {
		occupe( boutons.desactiver );
		abonnementActuel().then( function ( etatSw ) {
			if ( ! etatSw.abonnement ) {
				return null;
			}
			var endpoint = etatSw.abonnement.endpoint;
			return etatSw.abonnement.unsubscribe().then( function () {
				return appel( 'appareil', 'DELETE', { endpoint: endpoint } );
			} );
		} ).then( function () {
			afficher( 'Notifications désactivées sur cet appareil.', '', [ 'activer' ] );
		} ).catch( function ( erreur ) {
			afficher( 'La désactivation n’a pas abouti : ' + erreur.message, 'erreur', [ 'test', 'desactiver' ] );
		} ).then( function () {
			boutons.desactiver.removeAttribute( 'aria-busy' );
		} );
	} );

	verifier();
} )();
