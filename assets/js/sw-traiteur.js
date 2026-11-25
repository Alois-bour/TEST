/*
 * Service worker de l'espace traiteur (servi par ETR_PWA, précédé de « const ETR = {…} ») :
 * seulement les notifications (fonction « Notifications sur l'iPhone du traiteur »).
 * Aucune page n'est mise en cache.
 */
/* global ETR */
'use strict';

self.addEventListener( 'install', () => self.skipWaiting() );
self.addEventListener( 'activate', ( evenement ) => evenement.waitUntil( self.clients.claim() ) );

// Seulement une page de ce site, sinon l'accueil de l'espace.
function adresseSure( adresse ) {
	try {
		const url = new URL( adresse, self.location.origin );
		if ( url.origin === self.location.origin ) {
			return url.href;
		}
	} catch ( e ) {}
	return ETR.accueil;
}

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

self.addEventListener( 'notificationclick', ( evenement ) => {
	evenement.notification.close();
	const cible = adresseSure( ( evenement.notification.data && evenement.notification.data.url ) || ETR.accueil );
	evenement.waitUntil(
		self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( ( fenetres ) => {
			const fenetre = fenetres.find( ( f ) => 'focus' in f );
			if ( fenetre ) {
				return fenetre.focus().then( ( f ) => ( f || fenetre ).navigate( cible ) ).catch( () => self.clients.openWindow( cible ) );
			}
			return self.clients.openWindow( cible );
		} )
	);
} );
