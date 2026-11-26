/*
 * Service worker de l'application de l'équipe (servi par ETR_PWA, précédé de « const ETR = {…} »).
 *
 * - Pages de l'application : toujours depuis le réseau (données à jour, rien n'est stocké) ;
 *   sans réseau, page « Pas de connexion ».
 * - Fichiers CSS, JS et images du plugin : depuis le cache, rafraîchis en arrière-plan.
 * - Tout le reste (site public, administration, autres extensions) n'est pas touché.
 */
/* global ETR */
'use strict';

const CACHE = 'etr-gestion-' + ETR.version;
// Pages de l'application gardées pour le hors connexion (tournée, demandes, préparation…).
// Effacées à la déconnexion ; les plus anciennes partent au-delà de PAGES_MAX.
const PAGES     = 'etr-pages';
const PAGES_MAX = 60;

self.addEventListener( 'install', ( evenement ) => {
	evenement.waitUntil(
		caches.open( CACHE )
			.then( ( cache ) => cache.add( new Request( ETR.horsLigne, { cache: 'reload' } ) ) )
			.then( () => self.skipWaiting() )
	);
} );

self.addEventListener( 'activate', ( evenement ) => {
	evenement.waitUntil(
		caches.keys()
			.then( ( cles ) => Promise.all(
				cles.filter( ( cle ) => cle.startsWith( 'etr-gestion-' ) && cle !== CACHE ).map( ( cle ) => caches.delete( cle ) )
			) )
			// Pas de préchargement des navigations : il faisait perdre les derniers enregistrements
			// envoyés en quittant une page (requêtes « keepalive »). Désactivé s'il l'avait été.
			.then( () => self.registration.navigationPreload ? self.registration.navigationPreload.disable() : null )
			.then( () => self.clients.claim() )
	);
} );

/**
 * La page fait-elle partie de l'application ?
 *
 * @param {URL} url Adresse.
 * @return {boolean}
 */
function dansApplication( url ) {
	if ( ETR.permalien ) {
		return url.pathname === ETR.perimetre || url.pathname.startsWith( ETR.perimetre + '/' );
	}
	return url.searchParams.get( 'etr_gestion' ) === '1';
}

self.addEventListener( 'fetch', ( evenement ) => {
	const requete = evenement.request;
	if ( requete.method !== 'GET' ) {
		return;
	}
	const url = new URL( requete.url );
	if ( url.origin !== self.location.origin ) {
		return;
	}

	// Pages de l'application : réseau d'abord (toujours à jour) ; sans réseau, la dernière
	// version gardée sur le téléphone, sinon la page « Pas de connexion ».
	if ( requete.mode === 'navigate' ) {
		if ( dansApplication( url ) ) {
			evenement.respondWith(
				fetch( requete )
					.then( ( reponse ) => {
						garderPage( requete, reponse );
						return reponse;
					} )
					.catch( () => caches.open( PAGES )
						.then( ( cache ) => cache.match( requete ) )
						.then( ( gardee ) => gardee || caches.match( ETR.horsLigne ) ) )
			);
		}
		return;
	}

	// Fichiers du plugin : cache d'abord, mise à jour en arrière-plan.
	if ( requete.url.startsWith( ETR.assets ) ) {
		evenement.respondWith(
			caches.open( CACHE ).then( ( cache ) =>
				cache.match( requete ).then( ( enCache ) => {
					const reseau = fetch( requete )
						.then( ( reponse ) => {
							if ( reponse.ok ) {
								cache.put( requete, reponse.clone() );
							}
							return reponse;
						} )
						.catch( () => enCache );
					return enCache || reseau;
				} )
			)
		);
	}
} );

/**
 * Garde une page de l'application (seulement une vraie page de l'app, jamais l'écran de connexion).
 *
 * @param {Request}  requete Requête.
 * @param {Response} reponse Réponse du réseau.
 * @return {Promise}
 */
function garderPage( requete, reponse ) {
	const type = reponse.headers.get( 'X-ETR-Page' );
	const html = ( reponse.headers.get( 'Content-Type' ) || '' ).indexOf( 'text/html' ) !== -1;
	if ( 'document' === type || ! html || reponse.status >= 500 ) {
		// Bon imprimable, export, PDF, photo, panne du serveur : rien n'est gardé ni effacé.
		return Promise.resolve();
	}
	if ( 'app' !== type || ! reponse.ok ) {
		// Écran de connexion, renvoi vers l'espace traiteur, accès refusé… : la personne a
		// changé ou n'a plus accès, les pages gardées sont effacées.
		return caches.delete( PAGES ).catch( () => {} );
	}
	const copie = reponse.clone();
	return caches.open( PAGES ).then( ( cache ) => cache.put( requete.url, copie ).then( () => cache.keys() ).then( ( cles ) => {
		// Les plus anciennes d'abord (ordre d'insertion).
		return Promise.all( cles.slice( 0, Math.max( 0, cles.length - PAGES_MAX ) ).map( ( cle ) => cache.delete( cle ) ) );
	} ) ).catch( () => {} );
}

// Messages de l'application : garder des pages pour le hors connexion, ou tout effacer (déconnexion).
self.addEventListener( 'message', ( evenement ) => {
	const message = evenement.data || {};
	if ( message.type === 'etr-vider' ) {
		evenement.waitUntil( caches.delete( PAGES ) );
	} else if ( message.type === 'etr-precharger' && Array.isArray( message.adresses ) ) {
		evenement.waitUntil( Promise.all( message.adresses.slice( 0, 30 ).map( ( adresse ) => {
			const url = new URL( adresse, self.location.origin );
			if ( url.origin !== self.location.origin ) {
				return null;
			}
			if ( url.href.startsWith( ETR.assets ) ) {
				return caches.open( CACHE ).then( ( cache ) => cache.add( url.href ) ).catch( () => {} );
			}
			if ( ! dansApplication( url ) ) {
				return null;
			}
			return fetch( url.href, { credentials: 'same-origin' } ).then( ( reponse ) => garderPage( new Request( url.href ), reponse ) ).catch( () => {} );
		} ) ) );
	}
} );

/* ------------------------------------------------------------------ */
/* Notifications (envoyées par ETR_Push, chiffrées pour cet appareil)  */
/* ------------------------------------------------------------------ */

/**
 * Adresse à ouvrir : seulement une page de ce site, sinon l'accueil de l'application.
 *
 * @param {string} adresse Adresse reçue.
 * @return {string}
 */
function adresseSure( adresse ) {
	try {
		const url = new URL( adresse, self.location.origin );
		if ( url.origin === self.location.origin ) {
			return url.href;
		}
	} catch ( e ) {}
	return ETR.accueil;
}

// Toujours afficher une notification (exigé par iOS pour chaque message reçu).
self.addEventListener( 'push', ( evenement ) => {
	let message = {};
	try {
		message = evenement.data ? evenement.data.json() : {};
	} catch ( e ) {}
	// Pastille sur l'icône de l'application (iOS 16.4+, application installée).
	if ( typeof message.badge === 'number' && self.navigator && 'setAppBadge' in self.navigator ) {
		( message.badge > 0 ? self.navigator.setAppBadge( message.badge ) : self.navigator.clearAppBadge() ).catch( () => {} );
	}
	evenement.waitUntil(
		self.registration.showNotification( message.title || ETR.nom, {
			body: message.body || '',
			tag: message.tag || 'etr',
			icon: ETR.icone,
			badge: ETR.icone,
			data: { url: adresseSure( message.url || ETR.accueil ) },
		} )
	);
} );

// Toucher la notification : ouvre la demande, dans l'application déjà ouverte si possible.
self.addEventListener( 'notificationclick', ( evenement ) => {
	evenement.notification.close();
	const cible = adresseSure( ( evenement.notification.data && evenement.notification.data.url ) || ETR.accueil );
	evenement.waitUntil(
		self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( ( fenetres ) => {
			const fenetre = fenetres.find( ( f ) => dansApplication( new URL( f.url ) ) && 'focus' in f );
			if ( fenetre ) {
				return fenetre.focus().then( ( f ) => ( f || fenetre ).navigate( cible ) ).catch( () => self.clients.openWindow( cible ) );
			}
			return self.clients.openWindow( cible );
		} )
	);
} );
