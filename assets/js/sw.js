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

	// Pages de l'application : réseau, sinon page hors ligne.
	if ( requete.mode === 'navigate' ) {
		if ( dansApplication( url ) ) {
			evenement.respondWith(
				fetch( requete ).catch( () => caches.match( ETR.horsLigne ) )
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
