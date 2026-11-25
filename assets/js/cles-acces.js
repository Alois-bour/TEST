/*
 * Connexion par Face ID / Touch ID (clés d'accès, WebAuthn) : bouton de l'écran de connexion,
 * activation et retrait depuis l'application, invitation à l'activer.
 * La vérification se fait sur le serveur (ETR_Cles_Acces).
 */
/* global etrCles */
( function () {
	'use strict';

	if ( ! window.etrCles ) {
		return;
	}
	var C = window.etrCles;

	/* ------------------------------------------------------------------ */
	/* Outils : base64url ↔ octets, appels                                */
	/* ------------------------------------------------------------------ */

	function versOctets( texte ) {
		var b64  = texte.replace( /-/g, '+' ).replace( /_/g, '/' );
		var brut = window.atob( b64 + '='.repeat( ( 4 - b64.length % 4 ) % 4 ) );
		var o    = new Uint8Array( brut.length );
		for ( var i = 0; i < brut.length; i++ ) {
			o[ i ] = brut.charCodeAt( i );
		}
		return o.buffer;
	}

	function versTexte( tampon ) {
		var o    = new Uint8Array( tampon );
		var brut = '';
		for ( var i = 0; i < o.length; i++ ) {
			brut += String.fromCharCode( o[ i ] );
		}
		return window.btoa( brut ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}

	function api( chemin, donnees ) {
		var entetes = { 'Content-Type': 'application/json' };
		if ( C.nonce ) {
			entetes[ 'X-WP-Nonce' ] = C.nonce;
		}
		return fetch( C.api + chemin, { method: 'POST', credentials: 'same-origin', headers: entetes, body: JSON.stringify( donnees || {} ) } ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! r.ok ) {
					throw new Error( json.message || 'Erreur ' + r.status );
				}
				return json;
			} );
		} );
	}

	// L'appareil sait-il utiliser une clé d'accès protégée par Face ID / Touch ID ?
	function possible() {
		if ( ! window.PublicKeyCredential || ! PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable ) {
			return Promise.resolve( false );
		}
		return PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().catch( function () {
			return false;
		} );
	}

	// Message d'erreur compréhensible (refus de Face ID, annulation…).
	function texteErreur( erreur ) {
		if ( erreur && ( erreur.name === 'NotAllowedError' || erreur.name === 'AbortError' ) ) {
			return 'Opération annulée ou Face ID non confirmé.';
		}
		if ( erreur && erreur.name === 'InvalidStateError' ) {
			return 'Face ID est déjà activé sur cet appareil.';
		}
		return ( erreur && erreur.message ) || 'Une erreur est survenue.';
	}

	function nomAppareil() {
		var ua = navigator.userAgent;
		if ( /iphone/i.test( ua ) ) {
			return 'iPhone';
		}
		if ( /ipad/i.test( ua ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 ) ) {
			return 'iPad';
		}
		if ( /macintosh/i.test( ua ) ) {
			return 'Mac';
		}
		return /android/i.test( ua ) ? 'Android' : 'Ordinateur';
	}

	/* ------------------------------------------------------------------ */
	/* Écran de connexion                                                 */
	/* ------------------------------------------------------------------ */

	var zoneConnexion = document.querySelector( '[data-etr-cle-connexion]' );
	if ( zoneConnexion ) {
		possible().then( function ( ok ) {
			zoneConnexion.hidden = ! ok;
		} );
		var bouton  = zoneConnexion.querySelector( 'button' );
		var message = zoneConnexion.querySelector( '[data-cle-message]' );
		bouton.addEventListener( 'click', function () {
			bouton.disabled  = true;
			message.hidden   = true;
			var jeton        = '';
			api( 'connexion/options' ).then( function ( options ) {
				jeton = options.jeton;
				var cle = options.publicKey;
				cle.challenge = versOctets( cle.challenge );
				return navigator.credentials.get( { publicKey: cle } );
			} ).then( function ( reponse ) {
				return api( 'connexion', {
					jeton: jeton,
					id: reponse.id,
					clientDataJSON: versTexte( reponse.response.clientDataJSON ),
					authenticatorData: versTexte( reponse.response.authenticatorData ),
					signature: versTexte( reponse.response.signature ),
					userHandle: reponse.response.userHandle ? versTexte( reponse.response.userHandle ) : '',
				} );
			} ).then( function ( r ) {
				window.location.href = r.url;
			} ).catch( function ( erreur ) {
				message.textContent = texteErreur( erreur );
				message.hidden      = false;
				bouton.disabled     = false;
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Application : activer, retirer, inviter                            */
	/* ------------------------------------------------------------------ */

	var zone = document.querySelector( '[data-etr-cles]' );
	if ( zone ) {
		var etat    = zone.querySelector( '[data-cle-etat]' );
		var activer = zone.querySelector( '[data-cle-activer]' );
		possible().then( function ( ok ) {
			etat.textContent = ok
				? 'Cet appareil peut se connecter avec Face ID (ou Touch ID).'
				: 'Cet appareil ne propose pas Face ID pour les sites. Sur iPhone : iOS 16 ou plus, avec un code et Face ID activés.';
			etat.className = 'etr-notifs__etat' + ( ok ? '' : ' etr-notifs__etat--info' );
			activer.hidden = ! ok;
		} );
		activer.addEventListener( 'click', function () {
			activer.disabled = true;
			var jeton        = '';
			api( 'inscription/options' ).then( function ( options ) {
				jeton = options.jeton;
				var cle = options.publicKey;
				cle.challenge = versOctets( cle.challenge );
				cle.user.id   = versOctets( cle.user.id );
				cle.excludeCredentials = ( cle.excludeCredentials || [] ).map( function ( c ) {
					return { type: c.type, id: versOctets( c.id ) };
				} );
				return navigator.credentials.create( { publicKey: cle } );
			} ).then( function ( reponse ) {
				return api( 'inscription', {
					jeton: jeton,
					id: reponse.id,
					clientDataJSON: versTexte( reponse.response.clientDataJSON ),
					attestationObject: versTexte( reponse.response.attestationObject ),
					nom: nomAppareil(),
				} );
			} ).then( function () {
				try {
					window.localStorage.setItem( 'etr-cle-invite-fermee', '1' );
				} catch ( e ) {}
				window.location.reload();
			} ).catch( function ( erreur ) {
				etat.textContent = texteErreur( erreur );
				etat.className   = 'etr-notifs__etat etr-notifs__etat--erreur';
				activer.disabled = false;
			} );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var bouton = e.target.closest( '[data-cle-retirer]' );
		if ( ! bouton ) {
			return;
		}
		var confirmer = window.etrConfirmer ? window.etrConfirmer( 'Retirer cet appareil ? Il faudra le mot de passe pour se connecter depuis celui-ci.', { valider: 'Retirer', danger: true } ) : Promise.resolve( true );
		confirmer.then( function ( ok ) {
			if ( ! ok ) {
				return null;
			}
			return api( 'retirer', { id: bouton.getAttribute( 'data-cle-retirer' ) } ).then( function () {
				window.location.reload();
			} );
		} ).catch( function ( erreur ) {
			if ( window.etrToast ) {
				window.etrToast( texteErreur( erreur ), 'erreur' );
			}
		} );
	} );

	// Invitation à activer Face ID (une fois les notifications réglées, pour ne pas tout empiler).
	var invite = document.getElementById( 'etr-cle-invite' );
	var invNotifs = document.getElementById( 'etr-notifs-invite' );
	if ( invite ) {
		var fermee = false;
		try {
			fermee = window.localStorage.getItem( 'etr-cle-invite-fermee' ) === '1';
		} catch ( e ) {}
		possible().then( function ( ok ) {
			if ( ok && ! fermee && ( ! invNotifs || invNotifs.hidden ) ) {
				invite.hidden = false;
			}
		} );
		invite.querySelector( '.etr-installer__fermer' ).addEventListener( 'click', function () {
			invite.hidden = true;
			try {
				window.localStorage.setItem( 'etr-cle-invite-fermee', '1' );
			} catch ( e ) {}
		} );
	}
}() );
