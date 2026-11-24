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
