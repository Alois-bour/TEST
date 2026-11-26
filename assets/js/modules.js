/*
 * Fonctions activables de l'application de l'équipe :
 * - « En route » sur la tournée (choix du temps de trajet, traiteur prévenu) ;
 * - planning : glisser une livraison ou une reprise sur un autre jour (bureau, ordinateur).
 * Appels à l'API etr/v1/terrain/ (configuration etrTerrain, comme terrain.js).
 */
/* global etrTerrain */
( function () {
	'use strict';
	var C = window.etrTerrain;
	if ( ! C ) {
		return;
	}

	function api( chemin, donnees ) {
		return fetch( C.api + chemin, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify( donnees ),
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! r.ok ) {
					throw new Error( json.message || 'Erreur ' + r.status + '. Vérifiez la connexion et réessayez.' );
				}
				return json;
			} );
		} );
	}

	function toast( texte, type ) {
		if ( window.etrToast ) {
			window.etrToast( texte, type );
		} else {
			window.alert( texte ); // eslint-disable-line no-alert
		}
	}

	/* ------------------------------------------------------------------ */
	/* En route                                                           */
	/* ------------------------------------------------------------------ */

	var DUREES = [ 10, 15, 20, 30, 45, 60, 90 ];

	document.addEventListener( 'click', function ( e ) {
		var bouton = e.target.closest( '[data-en-route]' );
		if ( ! bouton ) {
			return;
		}
		var feuille = document.createElement( 'dialog' );
		feuille.className = 'etr-feuille';
		feuille.innerHTML = '<div class="etr-feuille__poignee" aria-hidden="true"></div><div class="etr-feuille__tete"><p class="etr-feuille__titre">Arrivée dans…</p><button type="button" class="etr-feuille__fermer" aria-label="Fermer">×</button></div><p class="etr-muted">Le traiteur reçoit l’heure d’arrivée estimée.</p><div class="etr-en-route__choix"></div>';
		var choix = feuille.querySelector( '.etr-en-route__choix' );
		DUREES.forEach( function ( minutes ) {
			var b = document.createElement( 'button' );
			b.type        = 'button';
			b.className   = 'etr-btn etr-btn--discret';
			b.textContent = minutes < 60 ? minutes + ' min' : Math.floor( minutes / 60 ) + ' h' + ( minutes % 60 ? ' ' + ( minutes % 60 ) : '' );
			b.addEventListener( 'click', function () {
				choix.querySelectorAll( 'button' ).forEach( function ( x ) {
					x.disabled = true;
				} );
				api( bouton.getAttribute( 'data-en-route' ) + '/en-route', { etape: bouton.getAttribute( 'data-etape' ), minutes: minutes } ).then( function ( r ) {
					bouton.querySelector( 'span' ).textContent = 'Arrivée vers ' + r.arrivee;
					feuille.close();
					toast( r.message );
				} ).catch( function ( erreur ) {
					feuille.close();
					toast( erreur.message, 'erreur' );
				} );
			} );
			choix.appendChild( b );
		} );
		( document.querySelector( '.etr-app' ) || document.body ).appendChild( feuille );
		feuille.querySelector( '.etr-feuille__fermer' ).addEventListener( 'click', function () {
			feuille.close();
		} );
		feuille.addEventListener( 'close', function () {
			feuille.remove();
		} );
		feuille.showModal();
		feuille.classList.add( 'est-ouverte' );
	} );

	/* ------------------------------------------------------------------ */
	/* Planning : glisser une étape sur un autre jour                     */
	/* ------------------------------------------------------------------ */

	var tire = null;
	document.addEventListener( 'dragstart', function ( e ) {
		var mvt = e.target.closest && e.target.closest( '[data-deplacer-id]' );
		if ( ! mvt ) {
			return;
		}
		tire = mvt;
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', mvt.getAttribute( 'data-deplacer-id' ) );
		document.documentElement.classList.add( 'etr-glisse' );
	} );
	document.addEventListener( 'dragend', function () {
		tire = null;
		document.documentElement.classList.remove( 'etr-glisse' );
		document.querySelectorAll( '.etr-calendrier__jour.est-cible' ).forEach( function ( j ) {
			j.classList.remove( 'est-cible' );
		} );
	} );
	document.addEventListener( 'dragover', function ( e ) {
		var jour = tire && e.target.closest && e.target.closest( '[data-jour]' );
		if ( jour ) {
			e.preventDefault();
			jour.classList.add( 'est-cible' );
		}
	} );
	document.addEventListener( 'dragleave', function ( e ) {
		var jour = e.target.closest && e.target.closest( '[data-jour]' );
		if ( jour && ! jour.contains( e.relatedTarget ) ) {
			jour.classList.remove( 'est-cible' );
		}
	} );
	document.addEventListener( 'drop', function ( e ) {
		var jour = tire && e.target.closest && e.target.closest( '[data-jour]' );
		if ( ! jour ) {
			return;
		}
		e.preventDefault();
		var mvt   = tire;
		var date  = jour.getAttribute( 'data-jour' );
		var avant = mvt.closest( '[data-jour]' );
		if ( avant === jour ) {
			return;
		}
		api( mvt.getAttribute( 'data-deplacer-id' ) + '/dates', { etape: mvt.getAttribute( 'data-deplacer-etape' ), date: date } ).then( function ( r ) {
			var liste = jour.querySelector( 'ul' );
			if ( ! liste ) {
				liste = document.createElement( 'ul' );
				jour.appendChild( liste );
			}
			var li = mvt.closest( 'li' );
			liste.appendChild( li );
			toast( r.message );
		} ).catch( function ( erreur ) {
			toast( erreur.message, 'erreur' );
		} );
	} );
}() );
