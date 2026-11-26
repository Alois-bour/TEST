/*
 * Écrans du terrain : préparation (enregistrée à chaque toucher), scanner d'étiquettes,
 * page d'un colis, livraison (signature, photos) et reprise (quantités, casse, photos).
 * Les enregistrements passent par l'API « etr/v1/terrain/ » (voir ETR_Operations).
 */
/* global etrTerrain, jsQR */
( function () {
	'use strict';

	if ( ! window.etrTerrain ) {
		return;
	}
	var C = window.etrTerrain;

	/* ------------------------------------------------------------------ */
	/* Outils                                                             */
	/* ------------------------------------------------------------------ */

	function api( chemin, donnees, multipart, garder, reessai ) {
		var options = { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
		if ( garder ) {
			options.keepalive = true; // L'envoi survit au changement de page.
		}
		if ( multipart ) {
			options.body = donnees;
		} else {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( donnees || {} );
		}
		return fetch( C.api + chemin, options ).then( function ( reponse ) {
			return reponse.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				// Page ouverte depuis longtemps : jeton de sécurité expiré, on en prend un frais.
				if ( reponse.status === 403 && json.code === 'rest_cookie_invalid_nonce' && ! reessai && window.etrHorsLigne ) {
					return window.etrHorsLigne.jeton().then( function () {
						return api( chemin, donnees, multipart, garder, true );
					} );
				}
				if ( ! reponse.ok ) {
					throw new Error( json.message || ( 'Erreur ' + reponse.status + '. Vérifiez la connexion et réessayez.' ) );
				}
				return json;
			} );
		}, function () {
			var erreur = new Error( 'La connexion a été coupée : impossible de savoir si l’enregistrement a abouti. Rechargez la page pour vérifier avant de recommencer.' );
			erreur.reseau = true;
			throw erreur;
		} );
	}

	/**
	 * Comme api(), mais sans réseau la saisie est gardée sur le téléphone (hors-ligne.js) et
	 * partira toute seule : la promesse donne alors { horsLigne: true }.
	 * Les renvois sont sans risque (jeton pour livraisons et reprises, scans et préparation rejouables).
	 *
	 * @param {string}  chemin    Route.
	 * @param {*}       donnees   JSON ou FormData.
	 * @param {boolean} multipart FormData.
	 * @param {Object}  infos     { libelle, demande, type } pour la liste des envois en attente.
	 * @return {Promise}
	 */
	function apiOuAttente( chemin, donnees, multipart, infos ) {
		var attendre = function () {
			return window.etrHorsLigne.garder( chemin, donnees, infos ).then( function () {
				return { horsLigne: true };
			} );
		};
		if ( ! window.etrHorsLigne ) {
			return api( chemin, donnees, multipart );
		}
		if ( navigator.onLine === false ) {
			return attendre();
		}
		var direct = function () {
			return api( chemin, donnees, multipart ).catch( function ( erreur ) {
				if ( erreur.reseau ) {
					return attendre();
				}
				throw erreur;
			} );
		};
		// Des saisies plus anciennes de la même demande attendent encore : elles partent d'abord ;
		// s'il en reste, celle-ci les suit dans la file (jamais une ancienne valeur après une récente).
		var demande = infos && infos.demande;
		if ( ! demande ) {
			return direct();
		}
		var enAttente = function () {
			return window.etrHorsLigne.pourDemande( demande ).then( function ( liste ) {
				return liste.filter( function ( e ) {
					return ! e.erreur;
				} ).length;
			} );
		};
		return enAttente().then( function ( n ) {
			if ( ! n ) {
				return direct();
			}
			return window.etrHorsLigne.envoyer().then( enAttente ).then( function ( reste ) {
				return reste ? attendre() : direct();
			} );
		} ).catch( direct );
	}

	function message( zone, texte, type ) {
		if ( ! zone ) {
			return;
		}
		zone.textContent = texte || '';
		zone.classList.remove( 'est-ok', 'est-erreur', 'est-info' );
		if ( type ) {
			zone.classList.add( 'est-' + type );
		}
	}

	function entier( valeur ) {
		var n = parseInt( valeur, 10 );
		return isNaN( n ) ? 0 : n;
	}

	function pluriel( n, un, plusieurs ) {
		return n + ' ' + ( n > 1 ? plusieurs : un );
	}

	// Confirmation dans une feuille de l'application (app.js), sinon celle du navigateur.
	function confirmer( texte, valider ) {
		if ( window.etrConfirmer ) {
			return window.etrConfirmer( texte, { valider: valider } );
		}
		return Promise.resolve( window.confirm( texte ) ); // eslint-disable-line no-alert
	}

	// Boutons − / + des champs de quantité (préparation, colis, reprise).
	document.addEventListener( 'click', function ( e ) {
		var bouton = e.target.closest( '.etr-pas [data-action]' );
		if ( ! bouton ) {
			return;
		}
		var champ = bouton.parentNode.querySelector( 'input' );
		var min   = champ.min === '' ? 0 : entier( champ.min );
		var max   = champ.max === '' ? Infinity : entier( champ.max );
		var val   = Math.max( min, Math.min( max, entier( champ.value ) + ( /moins$/.test( bouton.getAttribute( 'data-action' ) ) ? -1 : 1 ) ) );
		if ( String( val ) !== champ.value ) {
			champ.value = val;
			champ.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Préparation                                                        */
	/* ------------------------------------------------------------------ */

	var prepa = document.querySelector( '[data-etr-preparation]' );
	if ( prepa ) {
		var idPrepa    = prepa.getAttribute( 'data-etr-preparation' );
		var msgPrepa   = prepa.querySelector( '[data-prepa-message]' );
		var enAttente  = {};
		var file       = Promise.resolve();
		var enCours    = 0; // Enregistrements partis, sans réponse encore.

		var progres = function () {
			var fait  = 0;
			var total = 0;
			prepa.querySelectorAll( '.etr-prepa__ligne' ).forEach( function ( ligne ) {
				var demande = entier( ligne.getAttribute( 'data-demande' ) );
				var valeur  = entier( ligne.querySelector( 'input' ).value );
				fait  += valeur;
				total += demande;
				ligne.classList.toggle( 'est-complete', valeur >= demande );
				ligne.querySelector( '[data-action="tout"]' ).setAttribute( 'aria-pressed', valeur >= demande ? 'true' : 'false' );
			} );
			prepa.querySelector( '[data-prepa-fait]' ).textContent = fait;
			prepa.querySelector( '.etr-prepa__barre span' ).style.width = Math.floor( 100 * fait / Math.max( 1, total ) ) + '%';
			return { fait: fait, total: total };
		};

		var appliquer = function ( etat ) {
			prepa.setAttribute( 'data-prete', etat.prete ? '1' : '0' );
			prepa.querySelector( '[data-action="prete"]' ).hidden   = etat.prete;
			prepa.querySelector( '[data-action="rouvrir"]' ).hidden = ! etat.prete;
			prepa.querySelector( '[data-etiquettes]' ).hidden       = ! etat.colis;
		};

		// Les envois partent dans l'ordre ; chaque champ attend que l'on ait fini de toucher (350 ms).
		var envoyer = function ( chemin, donnees, garder ) {
			enCours++;
			file = file.catch( function () {} ).then( function () {
				return garder ? api( chemin, donnees, false, true ) : apiOuAttente( chemin, donnees, false, { libelle: 'Préparation, demande n° ' + idPrepa, demande: entier( idPrepa ), type: 'preparation' } );
			} );
			file.then( function () {
				enCours--;
			}, function () {
				enCours--;
			} );
			return file;
		};
		var programmer = function ( cle, donnees ) {
			if ( enAttente[ cle ] ) {
				clearTimeout( enAttente[ cle ].minuteur );
			}
			var partir = function ( garder ) {
				delete enAttente[ cle ];
				message( msgPrepa, 'Enregistrement…', 'info' );
				envoyer( idPrepa + '/preparation', donnees, garder ).then( function ( etat ) {
					if ( etat.horsLigne ) {
						message( msgPrepa, 'Hors connexion : enregistré sur le téléphone, envoyé dès le retour du réseau.', 'info' );
						return;
					}
					appliquer( etat );
					message( msgPrepa, etat.prete ? 'Enregistré. La commande est prête.' : 'Enregistré.', 'ok' );
				} ).catch( function ( erreur ) {
					message( msgPrepa, erreur.message, 'erreur' );
				} );
			};
			enAttente[ cle ] = {
				minuteur: setTimeout( partir, 350 ),
				partir: partir,
				partirSeul: function () {
					if ( navigator.onLine === false && window.etrHorsLigne ) {
						window.etrHorsLigne.garder( idPrepa + '/preparation', donnees, { libelle: 'Préparation, demande n° ' + idPrepa, demande: entier( idPrepa ), type: 'preparation' } );
						return;
					}
					api( idPrepa + '/preparation', donnees, false, true ).catch( function () {} );
				},
			};
		};
		var toutEnvoyer = function ( garder ) {
			Object.keys( enAttente ).forEach( function ( cle ) {
				clearTimeout( enAttente[ cle ].minuteur );
				enAttente[ cle ].partir( garder === true );
			} );
			return file.catch( function () {} );
		};

		prepa.addEventListener( 'click', function ( e ) {
			var coche = e.target.closest( '[data-action="tout"]' );
			if ( coche ) {
				var ligne = coche.closest( '.etr-prepa__ligne' );
				var champ = ligne.querySelector( 'input' );
				var dem   = ligne.getAttribute( 'data-demande' );
				champ.value = entier( champ.value ) >= entier( dem ) ? 0 : dem;
				champ.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				return;
			}
			var action = e.target.closest( '[data-action="prete"], [data-action="rouvrir"]' );
			if ( ! action ) {
				return;
			}
			var prete = action.getAttribute( 'data-action' ) === 'prete';
			var accord = Promise.resolve( true );
			if ( prete ) {
				var etat  = progres();
				var colis = entier( prepa.querySelector( '[data-colis]' ).value );
				if ( colis < 1 ) {
					message( msgPrepa, 'Indiquez d’abord le nombre de colis.', 'erreur' );
					prepa.querySelector( '[data-colis]' ).focus();
					return;
				}
				if ( etat.fait < etat.total ) {
					accord = confirmer( 'Il manque ' + pluriel( etat.total - etat.fait, 'article', 'articles' ) + '. Marquer la commande prête quand même ? Les livreurs seront prévenus qu’elle est incomplète.', 'Marquer prête' );
				}
			}
			accord.then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				action.disabled = true;
				toutEnvoyer().then( function () {
					return envoyer( idPrepa + '/preparation/prete', { prete: prete } );
				} ).then( function ( etat ) {
					if ( etat.horsLigne ) {
						appliquer( { prete: prete, colis: entier( prepa.querySelector( '[data-colis]' ).value ) } );
						message( msgPrepa, '', '' );
						if ( window.etrToast ) {
							window.etrToast( prete ? 'Hors connexion : « prête » sera envoyée dès le retour du réseau.' : 'Hors connexion : la réouverture sera envoyée dès le retour du réseau.' );
						}
						return;
					}
					appliquer( etat );
					message( msgPrepa, '', '' );
					if ( window.etrToast ) {
						window.etrToast( prete ? 'Commande prête : les livreurs sont prévenus.' : 'Préparation rouverte.' );
					} else {
						message( msgPrepa, prete ? 'Commande prête : les livreurs sont prévenus.' : 'Préparation rouverte.', 'ok' );
					}
				} ).catch( function ( erreur ) {
					message( msgPrepa, erreur.message, 'erreur' );
				} ).then( function () {
					action.disabled = false;
				} );
			} );
		} );

		prepa.addEventListener( 'change', function ( e ) {
			var champ = e.target;
			if ( champ.matches( '[data-colis]' ) ) {
				champ.value = Math.max( 0, Math.min( entier( champ.max ), entier( champ.value ) ) );
				programmer( 'colis', { colis: entier( champ.value ) } );
				return;
			}
			var ligne = champ.closest( '.etr-prepa__ligne' );
			if ( ! ligne ) {
				return;
			}
			champ.value = Math.max( 0, Math.min( entier( ligne.getAttribute( 'data-demande' ) ), entier( champ.value ) ) );
			progres();
			programmer( 'ligne' + ligne.getAttribute( 'data-index' ), { index: entier( ligne.getAttribute( 'data-index' ) ), quantite: entier( champ.value ) } );
		} );

		// Le lien « Étiquettes » attend que le nombre de colis soit enregistré.
		prepa.querySelector( '[data-etiquettes]' ).addEventListener( 'click', function ( e ) {
			if ( Object.keys( enAttente ).length ) {
				e.preventDefault();
				var lien = this.href;
				toutEnvoyer().then( function () {
					window.open( lien, '_blank' );
				} );
			}
		} );
		// Un lien touché alors qu'un enregistrement est en attente : on envoie d'abord, puis on change
		// d'écran (une requête lancée au moment de quitter la page peut être perdue).
		document.addEventListener( 'click', function ( e ) {
			var lien = e.target.closest( 'a[href]' );
			if ( ! lien || lien.target || e.defaultPrevented || ( ! Object.keys( enAttente ).length && ! enCours ) ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			message( msgPrepa, 'Enregistrement…', 'info' );
			toutEnvoyer().then( function () {
				window.location.href = lien.href;
			} );
		}, true );

		// En quittant la page autrement (app fermée…) : les derniers touchers partent en « keepalive »
		// (une requête ordinaire serait annulée par le changement de page).
		window.addEventListener( 'pagehide', function () {
			Object.keys( enAttente ).forEach( function ( cle ) {
				clearTimeout( enAttente[ cle ].minuteur );
				var donnees = enAttente[ cle ];
				delete enAttente[ cle ];
				donnees.partirSeul();
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Scanner                                                            */
	/* ------------------------------------------------------------------ */

	// Même site, avec ou sans « www. » (l'app peut avoir été installée depuis l'une ou l'autre adresse).
	function hote( adresse ) {
		return adresse.replace( /^www\./i, '' ).toLowerCase();
	}

	function lireEtiquette( texte ) {
		try {
			var url   = new URL( texte );
			var d     = entier( url.searchParams.get( 'd' ) );
			var c     = entier( url.searchParams.get( 'c' ) );
			var hotes = [ hote( window.location.host ), hote( C.hote || '' ) ];
			if ( hotes.indexOf( hote( url.host ) ) === -1 || url.searchParams.get( 'vue' ) !== 'colis' || ! d || ! c ) {
				return null;
			}
			return { d: d, c: c };
		} catch ( e ) {
			return null;
		}
	}

	function signal( ok ) {
		try {
			var Audio = window.AudioContext || window.webkitAudioContext;
			var son   = new Audio();
			var osc   = son.createOscillator();
			var gain  = son.createGain();
			osc.frequency.value = ok ? 880 : 220;
			gain.gain.value     = 0.08;
			osc.connect( gain );
			gain.connect( son.destination );
			osc.start();
			osc.stop( son.currentTime + ( ok ? 0.12 : 0.3 ) );
			setTimeout( function () {
				son.close();
			}, 500 );
		} catch ( e ) {}
		if ( navigator.vibrate ) {
			navigator.vibrate( ok ? 60 : [ 80, 60, 80 ] );
		}
	}

	var scanner = document.querySelector( '[data-etr-scanner]' );
	if ( scanner ) {
		var video      = scanner.querySelector( 'video' );
		var camera     = scanner.querySelector( '[data-action="camera"]' );
		var resultat   = scanner.querySelector( '[data-resultat]' );
		var historique = scanner.querySelector( '[data-historique]' );
		var toile      = document.createElement( 'canvas' );
		var contexte   = toile.getContext( '2d', { willReadFrequently: true } );
		var flux       = null;
		var occupe     = false;
		var dernier    = { texte: '', moment: 0 };

		var mode = function () {
			return scanner.querySelector( 'input[name="etr-mode"]:checked' ).value;
		};

		var afficher = function ( ok, titre, details ) {
			resultat.hidden    = false;
			resultat.className = 'etr-scanner__resultat ' + ( ok ? 'est-ok' : 'est-erreur' );
			resultat.textContent = '';
			var fort = document.createElement( 'strong' );
			fort.textContent = titre;
			resultat.appendChild( fort );
			if ( details ) {
				var p = document.createElement( 'span' );
				p.textContent = details;
				resultat.appendChild( p );
			}
		};

		var noter = function ( texte, ok ) {
			var vide = historique.querySelector( '.etr-muted' );
			if ( vide ) {
				vide.remove();
			}
			var li = document.createElement( 'li' );
			li.className   = ok ? 'est-ok' : 'est-erreur';
			li.textContent = new Date().toLocaleTimeString( 'fr-FR', { hour: '2-digit', minute: '2-digit' } ) + ' · ' + texte;
			historique.insertBefore( li, historique.firstChild );
		};

		var compter = function ( code ) {
			occupe = true;
			var modeScan = mode();
			return apiOuAttente( 'scan', { d: code.d, c: code.c, mode: modeScan }, false, { libelle: 'Scan ' + ( modeScan === 'sortie' ? 'chargement' : 'retour' ) + ' : colis ' + code.c + ', demande n° ' + code.d, demande: code.d, type: 'scan' } ).then( function ( r ) {
				if ( r.horsLigne ) {
					var texte = 'Colis ' + code.c + ' compté sur le téléphone';
					afficher( true, texte, 'Demande n° ' + code.d + ' · hors connexion, envoyé dès le retour du réseau' );
					noter( texte + ' (demande n° ' + code.d + ', hors connexion)', true );
					signal( true );
					return;
				}
				var faits = r.mode === 'sortie' ? r.bilan.sortis : r.bilan.revenus;
				var verbe = r.mode === 'sortie' ? 'chargés' : 'revenus';
				var titre = ( r.deja ? 'Déjà compté : ' : '' ) + 'colis ' + r.numero + ' / ' + r.bilan.colis + ' — ' + r.traiteur;
				afficher( ! r.deja, titre, r.titre + ' · ' + faits + ' / ' + r.bilan.colis + ' ' + verbe + ( faits === r.bilan.colis ? ' ✓ tout est là' : '' ) );
				noter( titre, ! r.deja );
				signal( ! r.deja );
			} ).catch( function ( erreur ) {
				afficher( false, 'Non compté', erreur.message );
				noter( 'Demande ' + code.d + ', colis ' + code.c + ' : ' + erreur.message, false );
				signal( false );
			} ).then( function () {
				occupe = false;
			} );
		};

		var boucle = function () {
			if ( ! flux ) {
				return;
			}
			if ( video.readyState >= 2 && ! occupe && window.jsQR ) {
				var echelle = Math.min( 1, 720 / Math.max( video.videoWidth, video.videoHeight ) );
				toile.width  = Math.round( video.videoWidth * echelle );
				toile.height = Math.round( video.videoHeight * echelle );
				contexte.drawImage( video, 0, 0, toile.width, toile.height );
				var image = contexte.getImageData( 0, 0, toile.width, toile.height );
				var lu    = jsQR( image.data, image.width, image.height, { inversionAttempts: 'dontInvert' } );
				if ( lu && lu.data && ( lu.data !== dernier.texte || Date.now() - dernier.moment > 4000 ) ) {
					dernier = { texte: lu.data, moment: Date.now() };
					var code = lireEtiquette( lu.data );
					if ( code ) {
						compter( code );
					} else {
						afficher( false, 'Ce QR code n’est pas une étiquette de colis', '' );
						signal( false );
					}
				}
			}
			window.requestAnimationFrame( boucle );
		};

		var arreter = function () {
			if ( flux ) {
				flux.getTracks().forEach( function ( piste ) {
					piste.stop();
				} );
			}
			flux = null;
			scanner.classList.remove( 'est-actif' );
			camera.querySelector( 'span' ).textContent = 'Ouvrir la caméra';
		};

		var chargerLecteur = function () {
			if ( window.jsQR ) {
				return Promise.resolve();
			}
			return new Promise( function ( ok, ko ) {
				var script     = document.createElement( 'script' );
				script.src     = C.jsqr;
				script.onload  = ok;
				script.onerror = function () {
					ko( new Error( 'Le lecteur de QR code n’a pas pu se charger.' ) );
				};
				document.head.appendChild( script );
			} );
		};

		camera.addEventListener( 'click', function () {
			if ( flux ) {
				arreter();
				return;
			}
			if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
				afficher( false, 'Caméra indisponible', 'Ouvrez l’application en HTTPS sur le téléphone, ou utilisez la saisie des numéros ci-dessous.' );
				return;
			}
			chargerLecteur().then( function () {
				return navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' }, audio: false } );
			} ).then( function ( s ) {
				flux            = s;
				video.srcObject = s;
				return video.play();
			} ).then( function () {
				scanner.classList.add( 'est-actif' );
				camera.querySelector( 'span' ).textContent = 'Arrêter la caméra';
				window.requestAnimationFrame( boucle );
			} ).catch( function ( erreur ) {
				arreter();
				afficher( false, 'Caméra refusée ou indisponible', erreur && erreur.name === 'NotAllowedError'
					? 'Autorisez la caméra : Réglages de l’iPhone › Safari › Caméra (ou touchez « Autoriser » quand l’app le demande), puis réessayez.'
					: ( erreur && erreur.message ) || '' );
			} );
		} );

		scanner.querySelector( '[data-manuel]' ).addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			compter( { d: entier( this.elements.d.value ), c: entier( this.elements.c.value ) } );
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				arreter();
			}
		} );
		window.addEventListener( 'pagehide', arreter );
	}

	/* ------------------------------------------------------------------ */
	/* Page d'un colis (étiquette lue hors de l'application)              */
	/* ------------------------------------------------------------------ */

	var colis = document.querySelector( '[data-etr-colis]' );
	if ( colis ) {
		colis.addEventListener( 'click', function ( e ) {
			var bouton = e.target.closest( '[data-mode]' );
			if ( ! bouton ) {
				return;
			}
			var zone = colis.querySelector( '[data-colis-message]' );
			bouton.disabled = true;
			var d = entier( colis.getAttribute( 'data-d' ) );
			var c = entier( colis.getAttribute( 'data-c' ) );
			apiOuAttente( 'scan', { d: d, c: c, mode: bouton.getAttribute( 'data-mode' ) }, false, { libelle: 'Colis ' + c + ', demande n° ' + d, demande: d, type: 'scan' } ).then( function ( r ) {
				if ( r.horsLigne ) {
					message( zone, 'Hors connexion : compté sur le téléphone, envoyé dès le retour du réseau.', 'info' );
					bouton.disabled = false;
					return;
				}
				var faits = r.mode === 'sortie' ? r.bilan.sortis : r.bilan.revenus;
				message( zone, ( r.deja ? 'Déjà compté. ' : 'Compté. ' ) + faits + ' / ' + r.bilan.colis + ( r.mode === 'sortie' ? ' colis chargés.' : ' colis revenus.' ), 'ok' );
				setTimeout( function () {
					window.location.reload();
				}, 1200 );
			} ).catch( function ( erreur ) {
				message( zone, erreur.message, 'erreur' );
				bouton.disabled = false;
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Tournée (bureau) : livreur de chaque étape, ordre de passage        */
	/* ------------------------------------------------------------------ */

	function toastOuMessage( texte, type ) {
		if ( window.etrToast ) {
			window.etrToast( texte, type );
		}
	}

	document.addEventListener( 'change', function ( e ) {
		var choix = e.target.closest( '[data-affecter]' );
		if ( ! choix ) {
			return;
		}
		choix.disabled = true;
		api( choix.getAttribute( 'data-id' ) + '/affectation', { etape: choix.getAttribute( 'data-etape' ), livreur: entier( choix.value ) } ).then( function () {
			toastOuMessage( entier( choix.value ) ? 'Étape confiée à ' + choix.options[ choix.selectedIndex ].text + ' (prévenu).' : 'Étape à répartir.' );
		} ).catch( function ( erreur ) {
			toastOuMessage( erreur.message, 'erreur' );
		} ).then( function () {
			choix.disabled = false;
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var bouton = e.target.closest( '[data-deplacer]' );
		if ( ! bouton ) {
			return;
		}
		var arret = bouton.closest( '.etr-arret' );
		var liste = arret.parentNode;
		if ( entier( bouton.getAttribute( 'data-deplacer' ) ) < 0 && arret.previousElementSibling ) {
			liste.insertBefore( arret, arret.previousElementSibling );
		} else if ( entier( bouton.getAttribute( 'data-deplacer' ) ) > 0 && arret.nextElementSibling ) {
			liste.insertBefore( arret.nextElementSibling, arret );
		} else {
			return;
		}
		var ids = Array.prototype.map.call( liste.querySelectorAll( '.etr-arret' ), function ( a ) {
			return entier( a.getAttribute( 'data-demande' ) );
		} );
		api( 'ordre', { etape: liste.getAttribute( 'data-ordre' ), ids: ids } ).then( function () {
			toastOuMessage( 'Ordre de passage enregistré.' );
		} ).catch( function ( erreur ) {
			toastOuMessage( erreur.message, 'erreur' );
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Notes de l'équipe, incidents résolus                               */
	/* ------------------------------------------------------------------ */

	document.querySelectorAll( '[data-etr-note]' ).forEach( function ( form ) {
		var id    = form.getAttribute( 'data-etr-note' );
		var liste = form.parentNode.querySelector( '[data-notes]' );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var champ = form.elements.texte;
			var texte = champ.value.trim();
			if ( ! texte ) {
				champ.focus();
				return;
			}
			var jeton  = Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 12 );
			var bouton = form.querySelector( 'button' );
			bouton.disabled = true;
			apiOuAttente( id + '/note', { texte: texte, jeton: jeton }, false, { libelle: 'Note, demande n° ' + id, demande: entier( id ), type: 'note' } ).then( function ( r ) {
				var li = document.createElement( 'li' );
				li.innerHTML = '<p></p><small></small>';
				li.querySelector( 'p' ).textContent = texte;
				li.querySelector( 'small' ).textContent = r.horsLigne ? 'Vous · envoi en attente du réseau' : r.auteur + ' · ' + r.le;
				liste.insertBefore( li, liste.firstChild );
				champ.value = '';
			} ).catch( function ( erreur ) {
				if ( window.etrToast ) {
					window.etrToast( erreur.message, 'erreur' );
				}
			} ).then( function () {
				bouton.disabled = false;
			} );
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var bouton = e.target.closest( '[data-resoudre]' );
		if ( ! bouton ) {
			return;
		}
		bouton.disabled = true;
		api( bouton.getAttribute( 'data-id' ) + '/incident/' + bouton.getAttribute( 'data-resoudre' ) + '/resolu', {} ).then( function () {
			window.location.reload();
		} ).catch( function ( erreur ) {
			bouton.disabled = false;
			if ( window.etrToast ) {
				window.etrToast( erreur.message, 'erreur' );
			}
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Signature, photos, envoi (livraison et reprise)                    */
	/* ------------------------------------------------------------------ */

	function zoneSignature( zone ) {
		var toileSig = zone.querySelector( 'canvas' );
		var ctx      = null;
		var vide     = true;
		var trace    = false;

		var preparer = function () {
			var cadre = toileSig.getBoundingClientRect();
			if ( ! cadre.width ) {
				return;
			}
			var ratio       = window.devicePixelRatio || 1;
			toileSig.width  = Math.round( cadre.width * ratio );
			toileSig.height = Math.round( cadre.height * ratio );
			ctx             = toileSig.getContext( '2d' );
			ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
			ctx.lineWidth   = 2.4;
			ctx.lineCap     = 'round';
			ctx.lineJoin    = 'round';
			ctx.strokeStyle = '#1d1d1b';
			vide            = true;
			zone.classList.remove( 'est-signee' );
		};
		var point = function ( e ) {
			var cadre = toileSig.getBoundingClientRect();
			return { x: e.clientX - cadre.left, y: e.clientY - cadre.top };
		};

		toileSig.addEventListener( 'pointerdown', function ( e ) {
			if ( ! ctx ) {
				preparer();
			}
			e.preventDefault();
			toileSig.setPointerCapture( e.pointerId );
			trace = true;
			var p = point( e );
			ctx.beginPath();
			ctx.moveTo( p.x, p.y );
			ctx.lineTo( p.x + 0.1, p.y + 0.1 );
			ctx.stroke();
		} );
		toileSig.addEventListener( 'pointermove', function ( e ) {
			if ( ! trace ) {
				return;
			}
			var p = point( e );
			ctx.lineTo( p.x, p.y );
			ctx.stroke();
			vide = false;
			zone.classList.add( 'est-signee' );
		} );
		[ 'pointerup', 'pointercancel', 'pointerleave' ].forEach( function ( type ) {
			toileSig.addEventListener( type, function () {
				trace = false;
			} );
		} );
		zone.querySelector( '[data-action="effacer"]' ).addEventListener( 'click', preparer );
		var details = zone.closest( 'details' );
		if ( details ) {
			details.addEventListener( 'toggle', function () {
				if ( details.open && vide ) {
					preparer();
				}
			} );
		}
		// Téléphone tourné : la toile prend la nouvelle largeur, la signature déjà tracée est
		// redessinée à l'échelle (sinon les traits suivants seraient décalés).
		var largeur = toileSig.getBoundingClientRect().width;
		window.addEventListener( 'resize', function () {
			var nouvelle = toileSig.getBoundingClientRect().width;
			if ( ! nouvelle || nouvelle === largeur ) {
				return; // Barre d'adresse de Safari qui apparaît ou disparaît : rien à faire.
			}
			largeur = nouvelle;
			if ( vide ) {
				preparer();
				return;
			}
			var copie    = document.createElement( 'canvas' );
			copie.width  = toileSig.width;
			copie.height = toileSig.height;
			copie.getContext( '2d' ).drawImage( toileSig, 0, 0 );
			preparer();
			var cadre = toileSig.getBoundingClientRect();
			ctx.drawImage( copie, 0, 0, cadre.width, cadre.height );
			vide = false;
			zone.classList.add( 'est-signee' );
		} );
		preparer();

		return {
			vide: function () {
				return vide;
			},
			image: function () {
				// Fond blanc : lisible partout (aperçu, impression).
				var copie    = document.createElement( 'canvas' );
				copie.width  = toileSig.width;
				copie.height = toileSig.height;
				var c        = copie.getContext( '2d' );
				c.fillStyle  = '#fff';
				c.fillRect( 0, 0, copie.width, copie.height );
				c.drawImage( toileSig, 0, 0 );
				return copie.toDataURL( 'image/png' );
			},
		};
	}

	// Photo réduite (1600 px, JPEG) : envoi rapide en 4G, et formats iPhone (HEIC) convertis.
	function reduire( fichier ) {
		return new Promise( function ( ok, ko ) {
			var adresse = URL.createObjectURL( fichier );
			var image   = new Image();
			image.onload = function () {
				var echelle = Math.min( 1, 1600 / Math.max( image.naturalWidth, image.naturalHeight ) );
				var toileP  = document.createElement( 'canvas' );
				toileP.width  = Math.round( image.naturalWidth * echelle );
				toileP.height = Math.round( image.naturalHeight * echelle );
				toileP.getContext( '2d' ).drawImage( image, 0, 0, toileP.width, toileP.height );
				URL.revokeObjectURL( adresse );
				toileP.toBlob( function ( blob ) {
					if ( blob ) {
						ok( blob );
					} else {
						ko( new Error( 'Photo illisible.' ) );
					}
				}, 'image/jpeg', 0.82 );
			};
			image.onerror = function () {
				URL.revokeObjectURL( adresse );
				ko( new Error( 'Cette photo n’a pas pu être lue.' ) );
			};
			image.src = adresse;
		} );
	}

	function zonePhotos( zone, msg ) {
		var photos  = [];
		var enCours = 0; // Photos choisies, encore en cours de réduction.
		var liste   = zone.querySelector( '[data-liste]' );
		var champ   = zone.querySelector( 'input[type="file"]' );

		champ.addEventListener( 'change', function () {
			var fichiers = Array.prototype.slice.call( champ.files );
			champ.value  = '';
			var place    = C.maxPhotos - photos.length - enCours;
			if ( fichiers.length > place ) {
				message( msg, C.maxPhotos + ' photos au plus : les suivantes sont ignorées.', 'erreur' );
				fichiers = fichiers.slice( 0, Math.max( 0, place ) );
			}
			enCours += fichiers.length;
			fichiers.reduce( function ( suite, fichier ) {
				return suite.then( function () {
					return reduire( fichier ).then( function ( blob ) {
						enCours--;
						var photo = { blob: blob };
						photos.push( photo );
						var li     = document.createElement( 'li' );
						var img    = document.createElement( 'img' );
						var retirer = document.createElement( 'button' );
						img.src    = URL.createObjectURL( blob );
						img.alt    = 'Photo ' + photos.length;
						retirer.type = 'button';
						retirer.textContent = '×';
						retirer.setAttribute( 'aria-label', 'Retirer cette photo' );
						retirer.addEventListener( 'click', function () {
							photos.splice( photos.indexOf( photo ), 1 );
							URL.revokeObjectURL( img.src );
							li.remove();
						} );
						li.appendChild( img );
						li.appendChild( retirer );
						liste.appendChild( li );
					} ).catch( function ( erreur ) {
						enCours--;
						message( msg, erreur.message, 'erreur' );
					} );
				} );
			}, Promise.resolve() );
		} );

		photos.enCours = function () {
			return enCours;
		};
		return photos;
	}

	function ecartsReprise( ligne ) {
		var demande = entier( ligne.getAttribute( 'data-demande' ) );
		var rendu   = ligne.querySelector( '[data-rendu]' );
		var casse   = ligne.querySelector( '[data-casse]' );
		rendu.value = Math.max( 0, Math.min( demande, entier( rendu.value ) ) );
		casse.max   = rendu.value;
		casse.value = Math.max( 0, Math.min( entier( rendu.value ), entier( casse.value ) ) );
		var manque  = demande - entier( rendu.value );
		var parties = [];
		if ( manque ) {
			parties.push( pluriel( manque, 'manquant', 'manquants' ) );
		}
		if ( entier( casse.value ) ) {
			parties.push( pluriel( entier( casse.value ), 'cassé', 'cassés' ) );
		}
		ligne.querySelector( '[data-ecart]' ).textContent = parties.join( ' · ' );
		ligne.classList.toggle( 'a-ecart', parties.length > 0 );
	}

	// Livraison ou reprise faite sans réseau : confirmation sur place, puis retour à la tournée.
	function confirmationHorsLigne( form, type ) {
		var bloc = document.createElement( 'section' );
		bloc.className = 'etr-panneau etr-hors-ligne-ok';
		bloc.setAttribute( 'role', 'status' );
		bloc.innerHTML = '<h2 class="etr-panneau__titre"></h2><p></p><a class="etr-btn etr-btn--plein"></a>';
		bloc.querySelector( 'h2' ).textContent = { livraison: 'Livraison enregistrée sur le téléphone', reprise: 'Reprise enregistrée sur le téléphone', incident: 'Incident enregistré sur le téléphone' }[ type ];
		bloc.querySelector( 'p' ).textContent = 'Pas de réseau ici : elle sera envoyée automatiquement dès le retour de la connexion (signature et photos comprises). Vous pouvez continuer la tournée.';
		var lien = bloc.querySelector( 'a' );
		lien.textContent = 'Retour à la tournée';
		lien.href = ( C.gestion || '/' ) + ( ( C.gestion || '' ).indexOf( '?' ) === -1 ? '?' : '&' ) + 'vue=tournee';
		form.replaceWith( bloc );
		window.scrollTo( 0, 0 );
	}

	// Tournée : étapes faites hors connexion, pas encore envoyées.
	( function () {
		var arrets = document.querySelectorAll( '.etr-arret[data-demande]' );
		if ( ! arrets.length || ! window.etrHorsLigne ) {
			return;
		}
		var marquer = function () {
			arrets.forEach( function ( arret ) {
				var etape = arret.getAttribute( 'data-etape' );
				window.etrHorsLigne.pourDemande( entier( arret.getAttribute( 'data-demande' ) ) ).then( function ( envois ) {
					var fait = envois.some( function ( e ) {
						return e.type === etape;
					} );
					var badge = arret.querySelector( '.etr-arret__attente' );
					if ( fait && ! badge ) {
						badge = document.createElement( 'p' );
						badge.className = 'etr-arret__attente';
						badge.textContent = ( etape === 'livraison' ? 'Livrée' : 'Reprise faite' ) + ' sur le téléphone · envoi en attente du réseau';
						arret.querySelector( '.etr-arret__tete' ).after( badge );
					} else if ( ! fait && badge ) {
						badge.remove();
					}
					var bouton = arret.querySelector( '[data-action-etape]' );
					if ( bouton ) {
						bouton.hidden = fait;
					}
				} );
			} );
		};
		marquer();
		document.addEventListener( 'etr-envois', function ( e ) {
			// Envoyées : la tournée est rechargée pour refléter le nouvel état.
			if ( e.detail && e.detail.envoyes && navigator.onLine !== false ) {
				window.location.reload();
			} else {
				marquer();
			}
		} );
	}() );

	document.querySelectorAll( '[data-etr-envoi]' ).forEach( function ( form ) {
		var type      = form.getAttribute( 'data-etr-envoi' );
		var id        = form.getAttribute( 'data-id' );
		var msg       = form.querySelector( '[data-envoi-message]' );
		var zoneSig   = form.querySelector( '[data-signature]' );
		var signature = zoneSig ? zoneSignature( zoneSig ) : null;
		var zoneP     = form.querySelector( '[data-photos]' );
		var photos    = zoneP ? zonePhotos( zoneP, msg ) : [];
		var bouton    = form.querySelector( 'button[type="submit"]' );
		// Jeton de ce formulaire : renvoyé après une coupure, il n'est pas enregistré deux fois.
		var jeton     = Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 12 );

		form.addEventListener( 'change', function ( e ) {
			var ligne = e.target.closest( '.etr-reprise__ligne' );
			if ( ligne ) {
				ecartsReprise( ligne );
			}
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var nom = form.elements.signataire ? form.elements.signataire.value.trim() : '';
			if ( type === 'livraison' && ! nom ) {
				message( msg, 'Indiquez le nom de la personne qui réceptionne.', 'erreur' );
				form.elements.signataire.focus();
				return;
			}
			if ( signature && zoneSig.hasAttribute( 'data-requise' ) && signature.vide() ) {
				message( msg, 'Faites signer la personne qui réceptionne.', 'erreur' );
				zoneSig.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				return;
			}
			if ( type === 'reprise' && signature && ! signature.vide() && ! nom ) {
				message( msg, 'Indiquez le nom de la personne qui a signé.', 'erreur' );
				return;
			}
			var choixIncident = form.querySelector( 'input[name="type"]:checked' );
			if ( type === 'incident' && ! choixIncident ) {
				message( msg, 'Choisissez le type d’incident.', 'erreur' );
				return;
			}
			if ( type === 'incident' && ! form.elements.texte.value.trim() ) {
				message( msg, 'Décrivez l’incident en quelques mots.', 'erreur' );
				form.elements.texte.focus();
				return;
			}

			if ( photos.enCours && photos.enCours() ) {
				message( msg, 'Patientez : les photos sont en cours de préparation.', 'info' );
				return;
			}

			var donnees = new FormData();
			donnees.append( 'jeton', jeton );
			donnees.append( 'signataire', nom );
			donnees.append( 'note', form.elements.note ? form.elements.note.value : '' );
			if ( type === 'incident' ) {
				donnees.append( 'type', choixIncident.value );
				donnees.append( 'texte', form.elements.texte.value );
			}
			if ( signature && ! signature.vide() ) {
				donnees.append( 'signature', signature.image() );
			}
			form.querySelectorAll( 'input[name^="lignes["]' ).forEach( function ( champ ) {
				donnees.append( champ.name, champ.value );
			} );
			photos.forEach( function ( photo, i ) {
				donnees.append( 'photos[]', photo.blob, 'photo-' + ( i + 1 ) + '.jpg' );
			} );

			bouton.disabled = true;
			message( msg, photos.length ? 'Envoi des photos…' : 'Enregistrement…', 'info' );
			var libelle = type === 'livraison' ? 'Livraison n° ' + id + ( nom ? ', signée par ' + nom : '' ) : ( type === 'incident' ? 'Incident, demande n° ' + id : 'Reprise n° ' + id );
			apiOuAttente( id + '/' + type, donnees, true, { libelle: libelle, demande: entier( id ), type: type } ).then( function ( r ) {
				if ( r.horsLigne ) {
					confirmationHorsLigne( form, type );
					return;
				}
				window.location.href = r.url;
			} ).catch( function ( erreur ) {
				message( msg, erreur.reseau
					? 'La connexion a été coupée. Touchez de nouveau le bouton quand le réseau revient : l’envoi ne sera pas enregistré deux fois.'
					: erreur.message, 'erreur' );
				bouton.disabled = false;
			} );
		} );
	} );
} )();
