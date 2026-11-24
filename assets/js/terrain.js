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

	function api( chemin, donnees, multipart ) {
		var options = { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
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
				if ( ! reponse.ok ) {
					throw new Error( json.message || ( 'Erreur ' + reponse.status + '. Vérifiez la connexion et réessayez.' ) );
				}
				return json;
			} );
		}, function () {
			throw new Error( 'Pas de connexion : rien n’a été enregistré. Réessayez quand le réseau revient.' );
		} );
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
		var envoyer = function ( chemin, donnees ) {
			file = file.catch( function () {} ).then( function () {
				return api( chemin, donnees );
			} );
			return file;
		};
		var programmer = function ( cle, donnees ) {
			if ( enAttente[ cle ] ) {
				clearTimeout( enAttente[ cle ].minuteur );
			}
			var partir = function () {
				delete enAttente[ cle ];
				message( msgPrepa, 'Enregistrement…', 'info' );
				envoyer( idPrepa + '/preparation', donnees ).then( function ( etat ) {
					appliquer( etat );
					message( msgPrepa, etat.prete ? 'Enregistré. La commande est prête.' : 'Enregistré.', 'ok' );
				} ).catch( function ( erreur ) {
					message( msgPrepa, erreur.message, 'erreur' );
				} );
			};
			enAttente[ cle ] = { minuteur: setTimeout( partir, 350 ), partir: partir };
		};
		var toutEnvoyer = function () {
			Object.keys( enAttente ).forEach( function ( cle ) {
				clearTimeout( enAttente[ cle ].minuteur );
				enAttente[ cle ].partir();
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
			if ( prete ) {
				var etat  = progres();
				var colis = entier( prepa.querySelector( '[data-colis]' ).value );
				if ( colis < 1 ) {
					message( msgPrepa, 'Indiquez d’abord le nombre de colis.', 'erreur' );
					prepa.querySelector( '[data-colis]' ).focus();
					return;
				}
				// eslint-disable-next-line no-alert
				if ( etat.fait < etat.total && ! window.confirm( 'Il manque ' + pluriel( etat.total - etat.fait, 'article', 'articles' ) + '. Marquer la commande prête quand même ?' ) ) {
					return;
				}
			}
			action.disabled = true;
			toutEnvoyer().then( function () {
				return envoyer( idPrepa + '/preparation/prete', { prete: prete } );
			} ).then( function ( etat ) {
				appliquer( etat );
				message( msgPrepa, prete ? 'Commande prête : les livreurs sont prévenus.' : 'Préparation rouverte.', 'ok' );
			} ).catch( function ( erreur ) {
				message( msgPrepa, erreur.message, 'erreur' );
			} ).then( function () {
				action.disabled = false;
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
		window.addEventListener( 'pagehide', toutEnvoyer );
	}

	/* ------------------------------------------------------------------ */
	/* Scanner                                                            */
	/* ------------------------------------------------------------------ */

	function lireEtiquette( texte ) {
		try {
			var url = new URL( texte );
			var d   = entier( url.searchParams.get( 'd' ) );
			var c   = entier( url.searchParams.get( 'c' ) );
			if ( url.origin !== window.location.origin || url.searchParams.get( 'vue' ) !== 'colis' || ! d || ! c ) {
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
			return api( 'scan', { d: code.d, c: code.c, mode: mode() } ).then( function ( r ) {
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
			api( 'scan', { d: entier( colis.getAttribute( 'data-d' ) ), c: entier( colis.getAttribute( 'data-c' ) ), mode: bouton.getAttribute( 'data-mode' ) } ).then( function ( r ) {
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
		window.addEventListener( 'resize', function () {
			if ( vide ) {
				preparer();
			}
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
		var photos = [];
		var liste  = zone.querySelector( '[data-liste]' );
		var champ  = zone.querySelector( 'input[type="file"]' );

		champ.addEventListener( 'change', function () {
			var fichiers = Array.prototype.slice.call( champ.files );
			champ.value  = '';
			var place    = C.maxPhotos - photos.length;
			if ( fichiers.length > place ) {
				message( msg, C.maxPhotos + ' photos au plus : les suivantes sont ignorées.', 'erreur' );
				fichiers = fichiers.slice( 0, Math.max( 0, place ) );
			}
			fichiers.reduce( function ( suite, fichier ) {
				return suite.then( function () {
					return reduire( fichier ).then( function ( blob ) {
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
						message( msg, erreur.message, 'erreur' );
					} );
				} );
			}, Promise.resolve() );
		} );

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

	document.querySelectorAll( '[data-etr-envoi]' ).forEach( function ( form ) {
		var type      = form.getAttribute( 'data-etr-envoi' );
		var id        = form.getAttribute( 'data-id' );
		var msg       = form.querySelector( '[data-envoi-message]' );
		var zoneSig   = form.querySelector( '[data-signature]' );
		var signature = zoneSig ? zoneSignature( zoneSig ) : null;
		var zoneP     = form.querySelector( '[data-photos]' );
		var photos    = zoneP ? zonePhotos( zoneP, msg ) : [];
		var bouton    = form.querySelector( 'button[type="submit"]' );

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

			var donnees = new FormData();
			donnees.append( 'signataire', nom );
			donnees.append( 'note', form.elements.note ? form.elements.note.value : '' );
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
			api( id + '/' + type, donnees, true ).then( function ( r ) {
				window.location.href = r.url;
			} ).catch( function ( erreur ) {
				message( msg, erreur.message, 'erreur' );
				bouton.disabled = false;
			} );
		} );
	} );
} )();
