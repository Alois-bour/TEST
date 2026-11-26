/*
 * Terrain sans réseau : boîte d'envoi du téléphone.
 *
 * Une saisie faite sans réseau (scan, préparation, livraison signée, reprise, photos comprises)
 * est gardée sur le téléphone (IndexedDB), puis envoyée dans l'ordre dès que le réseau revient :
 * au retour de la connexion, à l'ouverture d'un écran, et régulièrement tant qu'il en reste.
 * Les renvois sont sans risque : livraisons et reprises portent un jeton (jamais enregistrées
 * deux fois), les scans et la préparation donnent le même résultat si on les rejoue.
 *
 * Une pastille « 3 envois en attente » s'affiche ; la toucher ouvre le détail.
 */
/* global etrTerrain */
( function () {
	'use strict';

	if ( ! window.etrTerrain || ! window.indexedDB ) {
		return;
	}
	var C     = window.etrTerrain;
	var BASE  = 'etr-terrain';
	var TABLE = 'envois';
	var base  = null;

	/* ------------------------------------------------------------------ */
	/* Stockage (IndexedDB)                                               */
	/* ------------------------------------------------------------------ */

	function ouvrir() {
		if ( base ) {
			return base;
		}
		base = new Promise( function ( ok, ko ) {
			var demande = window.indexedDB.open( BASE, 1 );
			demande.onupgradeneeded = function () {
				demande.result.createObjectStore( TABLE, { keyPath: 'id', autoIncrement: true } );
			};
			demande.onsuccess = function () {
				ok( demande.result );
			};
			demande.onerror = function () {
				ko( demande.error );
			};
		} );
		return base;
	}

	function transaction( mode, action ) {
		return ouvrir().then( function ( db ) {
			return new Promise( function ( ok, ko ) {
				var t       = db.transaction( TABLE, mode );
				var magasin = t.objectStore( TABLE );
				var res     = action( magasin );
				t.oncomplete = function () {
					ok( res && 'result' in res ? res.result : undefined );
				};
				t.onerror = t.onabort = function () {
					ko( t.error );
				};
			} );
		} );
	}

	function tous() {
		return transaction( 'readonly', function ( m ) {
			return m.getAll();
		} ).then( function ( liste ) {
			return ( liste || [] ).sort( function ( a, b ) {
				return a.id - b.id;
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Mise en attente                                                    */
	/* ------------------------------------------------------------------ */

	// FormData → liste [nom, valeur] (texte ou Blob avec son nom de fichier), conservable.
	function serialiser( donnees ) {
		if ( ! ( donnees instanceof FormData ) ) {
			return { json: donnees || {} };
		}
		var champs = [];
		donnees.forEach( function ( valeur, nom ) {
			champs.push( valeur instanceof Blob ? [ nom, valeur, valeur.name || 'photo.jpg' ] : [ nom, String( valeur ) ] );
		} );
		return { champs: champs };
	}

	/**
	 * Saisie de la personne connectée (les autres ne sont jamais rejouées pour elle).
	 *
	 * @param {Object} e Saisie gardée.
	 * @return {boolean}
	 */
	function aMoi( e ) {
		return ! e.par || ! C.utilisateur || parseInt( e.par, 10 ) === parseInt( C.utilisateur, 10 );
	}

	function reconstruire( envoi ) {
		if ( ! envoi.champs ) {
			return { corps: JSON.stringify( envoi.json ), json: true };
		}
		var donnees = new FormData();
		envoi.champs.forEach( function ( champ ) {
			if ( champ.length === 3 ) {
				donnees.append( champ[ 0 ], champ[ 1 ], champ[ 2 ] );
			} else {
				donnees.append( champ[ 0 ], champ[ 1 ] );
			}
		} );
		return { corps: donnees, json: false };
	}

	/**
	 * Garde une saisie pour l'envoyer plus tard.
	 *
	 * @param {string} chemin  Route (ex. « 12/livraison »).
	 * @param {*}      donnees Objet JSON ou FormData.
	 * @param {Object} infos   { libelle, demande, type }.
	 * @return {Promise}
	 */
	function garder( chemin, donnees, infos ) {
		var envoi = serialiser( donnees );
		envoi.chemin  = chemin;
		envoi.libelle = ( infos && infos.libelle ) || 'Saisie';
		envoi.demande = parseInt( ( infos && infos.demande ) || 0, 10 ) || 0;
		envoi.par     = C.utilisateur || 0; // Rejouée seulement pour la même personne.
		envoi.type    = ( infos && infos.type ) || '';
		envoi.cree    = Date.now();
		envoi.erreur  = '';
		return transaction( 'readwrite', function ( m ) {
			return m.add( envoi );
		} ).then( function () {
			majIndicateur();
			programmer();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Envoi                                                              */
	/* ------------------------------------------------------------------ */

	var envoiEnCours = null;

	function jetonFrais() {
		var url = new URL( window.location.href );
		url.search = '';
		url.searchParams.set( 'etr_jeton', '1' );
		if ( C.gestion ) {
			url = new URL( C.gestion );
			url.searchParams.set( 'etr_jeton', '1' );
		}
		return fetch( url.href, { credentials: 'same-origin', cache: 'no-store' } ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! r.ok || ! json.success ) {
					var e = new Error( ( json.data && json.data.message ) || 'Reconnectez-vous pour envoyer les saisies en attente.' );
					e.connexion = true;
					throw e;
				}
				C.nonce = json.data.nonce; // Les écrans ouverts en profitent aussi.
				return json.data.nonce;
			} );
		} );
	}

	/**
	 * Envoie les saisies en attente, dans l'ordre. S'arrête au premier problème de réseau.
	 *
	 * @return {Promise<{envoyes:number, restants:number}>}
	 */
	function envoyer() {
		if ( envoiEnCours ) {
			return envoiEnCours;
		}
		var envoyes = 0;
		envoiEnCours = tous().then( function ( liste ) {
			var aEnvoyer = liste.filter( function ( e ) {
				return ! e.erreur && aMoi( e );
			} );
			if ( ! aEnvoyer.length || navigator.onLine === false ) {
				return null;
			}
			majIndicateur( 'envoi' );
			return jetonFrais().then( function ( nonce ) {
				return aEnvoyer.reduce( function ( suite, envoi ) {
					return suite.then( function () {
						var corps   = reconstruire( envoi );
						var entetes = { 'X-WP-Nonce': nonce };
						if ( corps.json ) {
							entetes[ 'Content-Type' ] = 'application/json';
						}
						return fetch( C.api + envoi.chemin, { method: 'POST', credentials: 'same-origin', headers: entetes, body: corps.corps } ).then( function ( r ) {
							return r.json().catch( function () {
								return {};
							} ).then( function ( json ) {
								if ( r.ok ) {
									envoyes++;
									return transaction( 'readwrite', function ( m ) {
										return m.delete( envoi.id );
									} );
								}
								// Erreur passagère (serveur occupé ou en panne, délai dépassé) : la saisie reste
								// en attente et la file s'arrête ; elle repartira au prochain essai.
								if ( r.status >= 500 || r.status === 408 || r.status === 429 || ( r.status === 409 && json.code === 'etr_occupe' ) ) {
									var passager = new Error( 'passager' );
									passager.passager = true;
									throw passager;
								}
								// Refus du serveur (commande déjà livrée par quelqu'un d'autre…) : gardé, signalé.
								envoi.erreur = json.message || ( 'Refusé (erreur ' + r.status + ')' );
								return transaction( 'readwrite', function ( m ) {
									return m.put( envoi );
								} );
							} );
						} );
					} );
				}, Promise.resolve() );
			} );
		} ).catch( function ( erreur ) {
			if ( erreur && erreur.connexion ) {
				afficherAlerte( erreur.message );
			}
			// Réseau coupé en cours de route : on réessaiera.
		} ).then( function () {
			envoiEnCours = null;
			if ( envoyes && window.etrToast ) {
				window.etrToast( envoyes > 1 ? envoyes + ' saisies envoyées.' : 'Saisie envoyée.' );
			}
			return majIndicateur().then( function ( n ) {
				document.dispatchEvent( new CustomEvent( 'etr-envois', { detail: { envoyes: envoyes, restants: n } } ) );
				return { envoyes: envoyes, restants: n };
			} );
		} );
		return envoiEnCours;
	}

	var minuteur = null;
	function programmer() {
		clearTimeout( minuteur );
		minuteur = setTimeout( function () {
			envoyer().then( function ( r ) {
				if ( r && r.restants ) {
					programmer();
				}
			} );
		}, 30000 );
	}

	/* ------------------------------------------------------------------ */
	/* Indicateur et détail                                               */
	/* ------------------------------------------------------------------ */

	var indicateur = null;
	var alerte     = '';

	function afficherAlerte( texte ) {
		alerte = texte;
		majIndicateur();
	}

	function majIndicateur( etat ) {
		return tous().then( function ( liste ) {
			var n         = liste.length;
			var refuses   = liste.filter( function ( e ) {
				return e.erreur;
			} ).length;
			var horsLigne = navigator.onLine === false;
			if ( ! indicateur ) {
				indicateur = document.createElement( 'button' );
				indicateur.type      = 'button';
				indicateur.className = 'etr-envois';
				indicateur.addEventListener( 'click', ouvrirDetail );
				( document.querySelector( '.etr-app' ) || document.body ).appendChild( indicateur );
			}
			indicateur.hidden = ! n && ! horsLigne;
			indicateur.classList.toggle( 'est-hors-ligne', horsLigne );
			indicateur.classList.toggle( 'est-envoi', etat === 'envoi' );
			indicateur.classList.toggle( 'est-refus', refuses > 0 );
			var texte = horsLigne ? 'Hors connexion' : '';
			if ( n ) {
				texte += ( texte ? ' · ' : '' ) + ( refuses ? refuses + ' refusée' + ( refuses > 1 ? 's' : '' ) + ' · ' : '' ) + ( n - refuses ? ( n - refuses ) + ' en attente' : '' );
			}
			indicateur.textContent = texte.replace( / · $/, '' );
			indicateur.setAttribute( 'aria-label', texte + ( n ? '. Toucher pour voir le détail.' : '' ) );
			return n;
		} ).catch( function () {
			return 0;
		} );
	}

	function ouvrirDetail() {
		tous().then( function ( liste ) {
			var feuille = document.createElement( 'dialog' );
			feuille.className = 'etr-feuille';
			feuille.innerHTML = '<div class="etr-feuille__poignee" aria-hidden="true"></div><div class="etr-feuille__tete"><p class="etr-feuille__titre">Saisies sur le téléphone<small></small></p><button type="button" class="etr-feuille__fermer" aria-label="Fermer">×</button></div><ul class="etr-envois__liste"></ul><div class="etr-confirmation__actions"></div>';
			feuille.querySelector( 'small' ).textContent = navigator.onLine === false
				? 'Hors connexion : elles partiront dès le retour du réseau.'
				: ( alerte || 'Elles partent automatiquement dès que le réseau le permet.' );
			var ul = feuille.querySelector( 'ul' );
			if ( ! liste.length ) {
				ul.innerHTML = '<li class="etr-muted">Rien en attente.</li>';
			}
			liste.forEach( function ( envoi ) {
				var li = document.createElement( 'li' );
				li.className = envoi.erreur ? 'est-refus' : '';
				li.innerHTML = '<strong></strong><small></small>';
				li.querySelector( 'strong' ).textContent = envoi.libelle;
				li.querySelector( 'small' ).textContent = new Date( envoi.cree ).toLocaleString( 'fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } ) + ( envoi.erreur ? ' · ' + envoi.erreur : ' · en attente' );
				if ( envoi.erreur ) {
					var retirer = document.createElement( 'button' );
					retirer.type        = 'button';
					retirer.className   = 'etr-lien';
					retirer.textContent = 'Retirer';
					retirer.addEventListener( 'click', function () {
						transaction( 'readwrite', function ( m ) {
							return m.delete( envoi.id );
						} ).then( function () {
							li.remove();
							majIndicateur();
						} );
					} );
					var reessayer = document.createElement( 'button' );
					reessayer.type        = 'button';
					reessayer.className   = 'etr-lien';
					reessayer.textContent = 'Réessayer';
					reessayer.addEventListener( 'click', function () {
						envoi.erreur = '';
						transaction( 'readwrite', function ( m ) {
							return m.put( envoi );
						} ).then( function () {
							li.className = '';
							li.querySelector( 'small' ).textContent = 'en attente';
							reessayer.remove();
							retirer.remove();
							envoyer();
						} );
					} );
					li.appendChild( reessayer );
					li.appendChild( retirer );
				}
				ul.appendChild( li );
			} );
			var actions = feuille.querySelector( '.etr-confirmation__actions' );
			if ( liste.some( function ( e ) {
				return ! e.erreur;
			} ) ) {
				var bouton = document.createElement( 'button' );
				bouton.type        = 'button';
				bouton.className   = 'etr-btn';
				bouton.textContent = 'Envoyer maintenant';
				bouton.disabled    = navigator.onLine === false;
				bouton.addEventListener( 'click', function () {
					bouton.disabled    = true;
					bouton.textContent = 'Envoi…';
					envoyer().then( function () {
						feuille.querySelector( '.etr-feuille__fermer' ).click();
					} );
				} );
				actions.appendChild( bouton );
			}
			( document.querySelector( '.etr-app' ) || document.body ).appendChild( feuille );
			feuille.querySelector( '.etr-feuille__fermer' ).addEventListener( 'click', function () {
				feuille.classList.remove( 'est-ouverte' );
				setTimeout( function () {
					feuille.close();
					feuille.remove();
				}, 320 );
			} );
			feuille.addEventListener( 'click', function ( e ) {
				if ( e.target === feuille ) {
					feuille.querySelector( '.etr-feuille__fermer' ).click();
				}
			} );
			feuille.showModal();
			window.requestAnimationFrame( function () {
				window.requestAnimationFrame( function () {
					feuille.classList.add( 'est-ouverte' );
				} );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Déclencheurs                                                       */
	/* ------------------------------------------------------------------ */

	window.addEventListener( 'online', function () {
		majIndicateur();
		envoyer();
	} );
	window.addEventListener( 'offline', function () {
		majIndicateur();
	} );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			envoyer();
		}
	} );
	majIndicateur().then( function ( n ) {
		if ( n ) {
			envoyer();
		}
	} );

	// Déconnexion : pages gardées effacées, saisies en attente envoyées ou abandonnées (après
	// confirmation), et ce téléphone ne reçoit plus les notifications de la personne qui part.
	var deconnexionEnCours = false;
	document.addEventListener( 'click', function ( e ) {
		var lien = e.target.closest( 'a[href*="action=logout"]' );
		if ( ! lien || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return;
		}
		e.preventDefault();
		if ( deconnexionEnCours ) {
			return; // Un seul déroulé, même si l'on touche plusieurs fois.
		}
		deconnexionEnCours = true;
		lien.setAttribute( 'aria-busy', 'true' );
		var partir = function () {
			if ( navigator.serviceWorker && navigator.serviceWorker.controller ) {
				navigator.serviceWorker.controller.postMessage( { type: 'etr-vider' } );
			}
			var fin = Promise.resolve();
			if ( navigator.serviceWorker && window.PushManager && C.apiNotifs ) {
				fin = navigator.serviceWorker.ready.then( function ( reg ) {
					return reg.pushManager.getSubscription();
				} ).then( function ( abonnement ) {
					if ( ! abonnement ) {
						return null;
					}
					return fetch( C.apiNotifs + 'appareil', { method: 'DELETE', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce }, body: JSON.stringify( { endpoint: abonnement.endpoint } ) } ).catch( function () {} );
				} ).catch( function () {} );
			}
			Promise.race( [ fin, new Promise( function ( r ) {
				setTimeout( r, 1500 );
			} ) ] ).then( function () {
				window.location.href = lien.href;
			} );
		};
		// L'envoi des saisies en attente a 8 s au plus ; ce qui reste est proposé à l'abandon.
		Promise.race( [ envoyer().catch( function () {} ), new Promise( function ( r ) {
			setTimeout( r, 8000 );
		} ) ] ).then( function () {
			return tous();
		} ).then( function ( liste ) {
			if ( ! liste.length ) {
				return partir();
			}
			var question = liste.length + ( liste.length > 1 ? ' saisies ne sont pas encore envoyées' : ' saisie n’est pas encore envoyée' ) + ' (réseau). En vous déconnectant, elles seront perdues.';
			var confirmer = window.etrConfirmer ? window.etrConfirmer( question, { valider: 'Se déconnecter quand même', danger: true } ) : Promise.resolve( window.confirm( question ) ); // eslint-disable-line no-alert
			return confirmer.then( function ( ok ) {
				if ( ! ok ) {
					deconnexionEnCours = false;
					lien.removeAttribute( 'aria-busy' );
					return null;
				}
				return transaction( 'readwrite', function ( m ) {
					return m.clear();
				} ).then( partir );
			} );
		} ).catch( partir );
	} );

	// Pages utiles sans réseau : gardées sur le téléphone à l'ouverture de la tournée.
	window.addEventListener( 'load', function () {
		var adresses = Array.prototype.map.call( document.querySelectorAll( '[data-hors-ligne]' ), function ( a ) {
			return a.href;
		} );
		if ( adresses.length && navigator.onLine !== false && navigator.serviceWorker ) {
			navigator.serviceWorker.ready.then( function ( reg ) {
				if ( reg.active ) {
					reg.active.postMessage( { type: 'etr-precharger', adresses: adresses } );
				}
			} );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* API pour terrain.js                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Saisies en attente pour une demande (ex. livraison faite hors connexion).
	 *
	 * @param {number} demande Demande.
	 * @return {Promise<Array>}
	 */
	function pourDemande( demande ) {
		return tous().then( function ( liste ) {
			return liste.filter( function ( e ) {
				return aMoi( e ) && parseInt( e.demande, 10 ) === parseInt( demande, 10 );
			} );
		} );
	}

	window.etrHorsLigne = {
		jeton: jetonFrais,
		garder: garder,
		envoyer: envoyer,
		pourDemande: pourDemande,
		maj: majIndicateur,
	};
}() );
