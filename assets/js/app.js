/*
 * Application de l'équipe : comportements d'une application iPhone.
 *
 * - service worker et aide à l'installation ;
 * - feuilles qui montent du bas (onglet « Plus », statut, confirmations) ;
 * - notifications temporaires (toasts) à la place des bandeaux de confirmation ;
 * - transitions entre écrans, barre de chargement, grand titre qui se replie ;
 * - tirer pour actualiser, glisser une demande pour appeler ou ouvrir l'itinéraire ;
 * - pastille sur l'icône de l'application.
 */
/* global etrApp */
( function () {
	'use strict';

	if ( ! window.etrApp ) {
		return;
	}

	var racine    = document.documentElement;
	var installee = racine.classList.contains( 'etr-installee' );
	var app       = document.querySelector( '.etr-app' ) || document.body;
	var telephone = window.matchMedia( '(max-width: 900px)' );

	// Service worker (ouverture hors connexion, démarrage rapide).
	if ( 'serviceWorker' in navigator ) {
		window.addEventListener( 'load', function () {
			navigator.serviceWorker.register( etrApp.sw, { scope: etrApp.perimetre } ).catch( function () {} );
		} );
	}

	// Pastille sur l'icône de l'application (iOS 16.4+, application installée).
	// WordPress transmet les nombres en texte : « 3 ».
	var pastille = etrApp.badge === null || etrApp.badge === '' || etrApp.badge === undefined ? NaN : parseInt( etrApp.badge, 10 );
	if ( ! isNaN( pastille ) && 'setAppBadge' in navigator ) {
		( pastille > 0 ? navigator.setAppBadge( pastille ) : navigator.clearAppBadge() ).catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* Aide à l'installation (Safari sur iPhone)                          */
	/* ------------------------------------------------------------------ */

	( function () {
		var aide = document.getElementById( 'etr-installer' );
		var ios  = /iphone|ipad|ipod/i.test( navigator.userAgent ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
		var CLE  = 'etr-installer-ferme';
		var deja = false;
		try {
			deja = window.localStorage.getItem( CLE ) === '1';
		} catch ( e ) {}
		if ( ! aide || ! ios || installee || deja ) {
			return;
		}
		aide.hidden = false;
		var fermer = aide.querySelector( '.etr-installer__fermer' );
		if ( fermer ) {
			fermer.addEventListener( 'click', function () {
				aide.hidden = true;
				try {
					window.localStorage.setItem( CLE, '1' );
				} catch ( e ) {}
			} );
		}
	}() );

	/* ------------------------------------------------------------------ */
	/* Feuilles (dialogues qui montent du bas)                            */
	/* ------------------------------------------------------------------ */

	function ouvrirFeuille( feuille ) {
		if ( ! feuille || feuille.open ) {
			return;
		}
		feuille.showModal();
		feuille.scrollTop = 0;
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				feuille.classList.add( 'est-ouverte' );
			} );
		} );
	}

	function fermerFeuille( feuille ) {
		if ( ! feuille || ! feuille.open ) {
			return;
		}
		feuille.classList.remove( 'est-ouverte' );
		feuille.style.removeProperty( '--etr-tirage' );
		var fini = false;
		var terminer = function () {
			if ( ! fini ) {
				fini = true;
				feuille.close();
				feuille.dispatchEvent( new Event( 'etr-fermee' ) );
			}
		};
		feuille.addEventListener( 'transitionend', terminer, { once: true } );
		setTimeout( terminer, 400 );
	}

	function preparerFeuille( feuille ) {
		if ( feuille.dataset.etrPrete ) {
			return;
		}
		feuille.dataset.etrPrete = '1';
		// Toucher le fond : fermer. Échap : animation de fermeture.
		feuille.addEventListener( 'click', function ( e ) {
			if ( e.target === feuille ) {
				var cadre = feuille.getBoundingClientRect();
				if ( e.clientY < cadre.top ) {
					fermerFeuille( feuille );
				}
			}
			if ( e.target.closest( '[data-fermer-feuille]' ) ) {
				fermerFeuille( feuille );
			}
		} );
		feuille.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			fermerFeuille( feuille );
		} );
		// Tirer la feuille vers le bas pour la fermer (depuis la poignée ou l'en-tête).
		var depart = null;
		feuille.addEventListener( 'touchstart', function ( e ) {
			if ( e.target.closest( '.etr-feuille__poignee, .etr-feuille__tete' ) || feuille.scrollTop <= 0 && e.target.closest( '.etr-feuille__poignee' ) ) {
				depart = e.touches[ 0 ].clientY;
				feuille.classList.add( 'est-tiree' );
			}
		}, { passive: true } );
		feuille.addEventListener( 'touchmove', function ( e ) {
			if ( depart !== null ) {
				feuille.style.setProperty( '--etr-tirage', Math.max( 0, e.touches[ 0 ].clientY - depart ) + 'px' );
			}
		}, { passive: true } );
		feuille.addEventListener( 'touchend', function ( e ) {
			if ( depart === null ) {
				return;
			}
			var tire = e.changedTouches[ 0 ].clientY - depart;
			depart   = null;
			feuille.classList.remove( 'est-tiree' );
			if ( tire > 90 ) {
				fermerFeuille( feuille );
			} else {
				feuille.style.removeProperty( '--etr-tirage' );
			}
		} );
	}

	function nouvelleFeuille( titre ) {
		var feuille = document.createElement( 'dialog' );
		feuille.className = 'etr-feuille';
		feuille.innerHTML = '<div class="etr-feuille__poignee" aria-hidden="true"></div><div class="etr-feuille__tete"><p class="etr-feuille__titre"></p><button type="button" class="etr-feuille__fermer" data-fermer-feuille aria-label="Fermer">×</button></div>';
		feuille.querySelector( '.etr-feuille__titre' ).textContent = titre;
		app.appendChild( feuille ); // Dans l'application : mêmes couleurs et styles.
		preparerFeuille( feuille );
		return feuille;
	}

	document.querySelectorAll( 'dialog.etr-feuille' ).forEach( preparerFeuille );
	document.addEventListener( 'click', function ( e ) {
		var bouton = e.target.closest( '[data-ouvrir-feuille]' );
		if ( bouton ) {
			ouvrirFeuille( document.getElementById( bouton.getAttribute( 'data-ouvrir-feuille' ) ) );
		}
	} );

	// Panneaux de la fiche (statut, tarif) : sur téléphone, ouverts en feuille depuis une barre en bas.
	( function () {
		var panneaux = Array.prototype.slice.call( document.querySelectorAll( '[data-feuille]' ) );
		if ( ! panneaux.length ) {
			return;
		}
		var barre = document.createElement( 'div' );
		barre.className = 'etr-barre-action etr-barre-action--feuilles';
		var principal = document.querySelector( '.etr-app__principal' );
		var places    = [];
		panneaux.forEach( function ( panneau ) {
			var feuille = null;
			var bouton  = document.createElement( 'button' );
			bouton.type      = 'button';
			bouton.className = 'etr-btn' + ( panneau.hasAttribute( 'data-feuille-principale' ) ? '' : ' etr-btn--discret' );
			bouton.textContent = panneau.getAttribute( 'data-feuille' );
			bouton.addEventListener( 'click', function () {
				if ( ! feuille ) {
					feuille = nouvelleFeuille( panneau.getAttribute( 'data-feuille' ) );
				}
				feuille.appendChild( panneau );
				ouvrirFeuille( feuille );
			} );
			barre.appendChild( bouton );
			places.push( { panneau: panneau, parent: panneau.parentNode, suivant: panneau.nextSibling } );
		} );
		principal.appendChild( barre );
		// Écran agrandi (iPad tourné) : les panneaux reprennent leur place.
		telephone.addEventListener( 'change', function () {
			if ( ! telephone.matches ) {
				places.forEach( function ( p ) {
					p.parent.insertBefore( p.panneau, p.suivant );
				} );
			}
		} );
	}() );

	/* ------------------------------------------------------------------ */
	/* Confirmations                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Demande de confirmation dans une feuille (remplace window.confirm).
	 *
	 * @param {string} texte   Question.
	 * @param {Object} options { valider: libellé, danger: bool }.
	 * @return {Promise<boolean>}
	 */
	function confirmer( texte, options ) {
		options = options || {};
		return new Promise( function ( resoudre ) {
			var feuille = nouvelleFeuille( options.titre || 'Confirmer' );
			var corps   = document.createElement( 'div' );
			corps.innerHTML = '<p class="etr-confirmation__texte"></p><div class="etr-confirmation__actions"><button type="button" class="etr-btn" data-oui></button><button type="button" class="etr-btn etr-btn--discret" data-fermer-feuille>Annuler</button></div>';
			corps.querySelector( 'p' ).textContent = texte;
			var oui = corps.querySelector( '[data-oui]' );
			oui.textContent = options.valider || 'Confirmer';
			if ( options.danger ) {
				oui.classList.add( 'etr-btn--alerte' );
			}
			feuille.appendChild( corps );
			var reponse = false;
			oui.addEventListener( 'click', function () {
				reponse = true;
				fermerFeuille( feuille );
			} );
			feuille.addEventListener( 'etr-fermee', function () {
				feuille.remove();
				resoudre( reponse );
			} );
			ouvrirFeuille( feuille );
		} );
	}
	window.etrConfirmer = confirmer;

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.hasAttribute( 'data-confirmer' ) || form.dataset.etrConfirme ) {
			return;
		}
		e.preventDefault();
		confirmer( form.getAttribute( 'data-confirmer' ), { danger: true } ).then( function ( ok ) {
			if ( ok ) {
				form.dataset.etrConfirme = '1';
				chargement( true );
				form.submit();
			}
		} );
	} );

	// Liens à confirmer (« Livrer quand même »…).
	document.addEventListener( 'click', function ( e ) {
		var lien = e.target.closest( 'a[data-confirmer]' );
		if ( ! lien || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 ) {
			return;
		}
		e.preventDefault();
		confirmer( lien.getAttribute( 'data-confirmer' ), { valider: lien.textContent.trim() || 'Continuer' } ).then( function ( ok ) {
			if ( ok ) {
				window.location.href = lien.href;
			}
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Notifications temporaires (toasts)                                 */
	/* ------------------------------------------------------------------ */

	var zoneToasts = null;
	function toast( texte, type ) {
		if ( ! zoneToasts ) {
			zoneToasts = document.createElement( 'div' );
			zoneToasts.className = 'etr-toasts';
			zoneToasts.setAttribute( 'role', 'status' );
			zoneToasts.setAttribute( 'aria-live', 'polite' );
			app.appendChild( zoneToasts );
		}
		var element = document.createElement( 'div' );
		element.className = 'etr-toast' + ( type === 'erreur' ? ' etr-toast--erreur' : '' );
		element.innerHTML = '<svg class="etr-icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ( type === 'erreur' ? '<path d="M12 8v5M12 16.5v.01"/><circle cx="12" cy="12" r="9"/>' : '<path d="M5 12.5l4.5 4.5L19 7.5"/>' ) + '</svg><span></span>';
		element.querySelector( 'span' ).textContent = texte;
		zoneToasts.appendChild( element );
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				element.classList.add( 'est-visible' );
			} );
		} );
		var cacher = function () {
			element.classList.remove( 'est-visible' );
			setTimeout( function () {
				element.remove();
			}, 400 );
		};
		element.addEventListener( 'click', cacher );
		setTimeout( cacher, type === 'erreur' ? 6000 : 3500 );
	}
	window.etrToast = toast;

	// Estimation de la livraison : « Utiliser ce prix » remplit les frais de livraison.
	document.addEventListener( 'click', function ( e ) {
		var bouton = e.target.closest( '[data-remplir-livraison]' );
		var champ  = document.getElementById( 'etr-livraison' );
		if ( ! bouton || ! champ ) {
			return;
		}
		champ.value = bouton.getAttribute( 'data-remplir-livraison' );
		champ.focus();
		toast( 'Prix reporté : touchez « Mettre à jour le prix » pour l’enregistrer.' );
	} );

	// Confirmation après une action (« Statut enregistré… ») : en toast, et l'adresse est nettoyée
	// pour qu'un rechargement ne la réaffiche pas.
	( function () {
		var notice = document.querySelector( '.etr-app__principal .etr-notice--succes' );
		if ( notice ) {
			toast( notice.textContent.trim() );
			notice.remove();
		}
		if ( window.history.replaceState && /[?&]etr_msg=/.test( window.location.search ) ) {
			var url = new URL( window.location.href );
			url.searchParams.delete( 'etr_msg' );
			window.history.replaceState( window.history.state, '', url.href );
		}
	}() );

	/* ------------------------------------------------------------------ */
	/* Navigation : transitions, barre de chargement                      */
	/* ------------------------------------------------------------------ */

	var barreChargement = document.createElement( 'div' );
	barreChargement.className = 'etr-chargement';
	barreChargement.setAttribute( 'aria-hidden', 'true' );
	document.body.appendChild( barreChargement );
	var minuteurChargement = null;

	function chargement( actif ) {
		clearTimeout( minuteurChargement );
		if ( actif ) {
			minuteurChargement = setTimeout( function () {
				barreChargement.classList.add( 'est-actif' );
			}, 120 );
		} else {
			barreChargement.classList.remove( 'est-actif' );
		}
	}

	function lienInterne( lien, e ) {
		if ( ! lien || lien.target || lien.hasAttribute( 'download' ) || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return false;
		}
		var url;
		try {
			url = new URL( lien.href, window.location.href );
		} catch ( err ) {
			return false;
		}
		return url.origin === window.location.origin && ! ( url.pathname === window.location.pathname && url.search === window.location.search && url.hash );
	}

	document.addEventListener( 'click', function ( e ) {
		var lien = e.target.closest( 'a[href]' );
		if ( ! lienInterne( lien, e ) ) {
			return;
		}
		// Sens de la transition : retour, changement d'onglet, ou écran suivant.
		var sens = 'avance';
		if ( lien.matches( '.etr-retour, .etr-retour *' ) || lien.closest( '.etr-retour' ) ) {
			sens = 'retour';
		} else if ( lien.closest( '.etr-app__nav, .etr-feuille__liste, .etr-app__logo' ) ) {
			sens = 'onglet';
		}
		try {
			window.sessionStorage.setItem( 'etr-sens', sens );
		} catch ( err ) {}
		chargement( true );
	} );
	document.addEventListener( 'submit', function ( e ) {
		if ( ! e.defaultPrevented && ! e.target.hasAttribute( 'data-etr-envoi' ) ) {
			try {
				window.sessionStorage.setItem( 'etr-sens', 'onglet' );
			} catch ( err ) {}
			chargement( true );
		}
	} );
	window.addEventListener( 'pageshow', function () {
		chargement( false );
	} );
	// Page quittée : une transition abandonnée (téléchargement, formulaire) est sans conséquence.
	window.addEventListener( 'pageswap', function ( e ) {
		if ( e.viewTransition ) {
			[ 'ready', 'finished', 'updateCallbackDone' ].forEach( function ( promesse ) {
				if ( e.viewTransition[ promesse ] ) {
					e.viewTransition[ promesse ].catch( function () {} );
				}
			} );
		}
	} );
	// Écran suivant : le sens de la transition est appliqué dans l'en-tête (ETR_PWA::balises),
	// car l'événement « pagereveal » a lieu avant le chargement de ce script.

	/* ------------------------------------------------------------------ */
	/* Grand titre qui se replie dans l'en-tête                           */
	/* ------------------------------------------------------------------ */

	( function () {
		var titre   = document.querySelector( '.etr-app__principal .etr-page-entete__titre' );
		var compact = document.querySelector( '.etr-app__titre-compact' );
		var barre   = document.querySelector( '.etr-app__barre' );
		if ( ! titre || ! compact || ! barre || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}
		compact.textContent = titre.textContent.trim();
		var observateur = null;
		var observer = function () {
			if ( observateur ) {
				observateur.disconnect();
			}
			observateur = new IntersectionObserver( function ( entrees ) {
				racine.classList.toggle( 'etr-titre-replie', ! entrees[ 0 ].isIntersecting && entrees[ 0 ].boundingClientRect.top < barre.offsetHeight );
			}, { rootMargin: '-' + barre.offsetHeight + 'px 0px 0px 0px' } );
			observateur.observe( titre );
		};
		observer();
		window.addEventListener( 'resize', observer );
	}() );

	/* ------------------------------------------------------------------ */
	/* Tirer pour actualiser (application installée)                      */
	/* ------------------------------------------------------------------ */

	( function () {
		var principal = document.querySelector( '.etr-app__principal' );
		var vue       = principal ? principal.getAttribute( 'data-vue' ) : '';
		// Pas sur les écrans de saisie (préparation, livraison…) : un geste raté ferait perdre la saisie.
		if ( ! installee || ! principal || [ 'preparer', 'livrer', 'reprendre', 'scanner', 'colis' ].indexOf( vue ) !== -1 ) {
			return;
		}
		var indicateur = document.createElement( 'div' );
		indicateur.className = 'etr-rafraichir';
		indicateur.setAttribute( 'aria-hidden', 'true' );
		indicateur.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v5h-5"/></svg>';
		app.appendChild( indicateur );
		var SEUIL  = 72;
		var depart = null;
		var tire   = 0;

		document.addEventListener( 'touchstart', function ( e ) {
			var cible = e.target && e.target.closest ? e.target : document.body;
			if ( window.scrollY > 0 || e.touches.length > 1 || cible.closest( 'dialog, input, select, textarea, canvas, .etr-table--demandes tr.est-ouvert' ) ) {
				depart = null;
				return;
			}
			depart = e.touches[ 0 ].clientY;
			tire   = 0;
		}, { passive: true } );
		document.addEventListener( 'touchmove', function ( e ) {
			if ( depart === null ) {
				return;
			}
			tire = Math.max( 0, e.touches[ 0 ].clientY - depart );
			if ( window.scrollY > 0 ) {
				tire = 0;
			}
			var progression = Math.min( 1, tire / SEUIL );
			indicateur.style.opacity   = progression;
			indicateur.style.transform = 'translateY(' + ( Math.min( tire, SEUIL ) - 60 + 20 * progression ) + 'px) rotate(' + ( progression * 270 ) + 'deg)';
			indicateur.classList.toggle( 'est-pret', tire >= SEUIL );
		}, { passive: true } );
		document.addEventListener( 'touchend', function () {
			if ( depart === null ) {
				return;
			}
			depart = null;
			if ( tire >= SEUIL ) {
				indicateur.classList.add( 'est-actif' );
				indicateur.style.transform = 'translateY(16px)';
				window.location.reload();
				return;
			}
			indicateur.style.opacity   = 0;
			indicateur.style.transform = '';
		} );
	}() );

	/* ------------------------------------------------------------------ */
	/* Liste des demandes (téléphone) : carte entière cliquable, glisser  */
	/* ------------------------------------------------------------------ */

	( function () {
		var lignes = document.querySelectorAll( '.etr-table--demandes tbody tr' );
		if ( ! lignes.length ) {
			return;
		}
		var ouverte = null;
		var fermer  = function ( ligne ) {
			if ( ligne ) {
				ligne.style.setProperty( '--glisser', '0px' );
				ligne.classList.remove( 'est-ouvert' );
			}
			if ( ligne === ouverte ) {
				ouverte = null;
			}
		};

		lignes.forEach( function ( ligne ) {
			var actions = ligne.querySelector( '.etr-glisser__actions' );
			var lien    = ligne.querySelector( '.etr-table__principal' );
			var depart  = null;
			var deplace = false;
			var largeur = actions ? actions.children.length * 84 : 0; // 84 px par action.
			ligne.style.setProperty( '--largeur-actions', largeur + 'px' );

			ligne.addEventListener( 'click', function ( e ) {
				if ( deplace ) {
					e.preventDefault();
					deplace = false;
					return;
				}
				if ( ligne.classList.contains( 'est-ouvert' ) && ! e.target.closest( '.etr-glisser__actions' ) ) {
					e.preventDefault();
					fermer( ligne );
					return;
				}
				if ( telephone.matches && lien && ! e.target.closest( 'a' ) ) {
					lien.click(); // Toute la carte ouvre la demande.
				}
			} );
			if ( ! actions ) {
				return;
			}
			ligne.addEventListener( 'touchstart', function ( e ) {
				if ( ! telephone.matches ) {
					return;
				}
				if ( ouverte && ouverte !== ligne ) {
					fermer( ouverte );
				}
				depart  = { x: e.touches[ 0 ].clientX, y: e.touches[ 0 ].clientY, base: ligne.classList.contains( 'est-ouvert' ) ? -largeur : 0, horizontal: null };
				deplace = false;
			}, { passive: true } );
			ligne.addEventListener( 'touchmove', function ( e ) {
				if ( ! depart ) {
					return;
				}
				var dx = e.touches[ 0 ].clientX - depart.x;
				var dy = e.touches[ 0 ].clientY - depart.y;
				if ( depart.horizontal === null && ( Math.abs( dx ) > 8 || Math.abs( dy ) > 8 ) ) {
					depart.horizontal = Math.abs( dx ) > Math.abs( dy );
				}
				if ( ! depart.horizontal ) {
					return;
				}
				deplace = true;
				ligne.classList.add( 'est-glisse' );
				ligne.style.setProperty( '--glisser', Math.max( -largeur - 24, Math.min( 0, depart.base + dx ) ) + 'px' );
			}, { passive: true } );
			ligne.addEventListener( 'touchend', function ( e ) {
				if ( ! depart ) {
					return;
				}
				ligne.classList.remove( 'est-glisse' );
				if ( depart.horizontal ) {
					var fin = depart.base + ( e.changedTouches[ 0 ].clientX - depart.x );
					if ( fin < -largeur / 2 ) {
						ligne.style.setProperty( '--glisser', -largeur + 'px' );
						ligne.classList.add( 'est-ouvert' );
						ouverte = ligne;
					} else {
						fermer( ligne );
					}
				}
				depart = null;
			} );
		} );
		document.addEventListener( 'touchstart', function ( e ) {
			if ( ouverte && ! ouverte.contains( e.target ) ) {
				fermer( ouverte );
			}
		}, { passive: true } );
	}() );
	/* ------------------------------------------------------------------ */
	/* Fiche d'une demande : onglets                                      */
	/* ------------------------------------------------------------------ */

	( function () {
		var barreOnglets = document.querySelector( '.etr-fiche-onglets' );
		if ( ! barreOnglets ) {
			return;
		}
		var fiche  = barreOnglets.getAttribute( 'data-fiche' );
		var RETOUR = 'etr-fiche-onglet';
		var liste  = barreOnglets.querySelector( 'ul' );

		// Onglets plus larges que l'écran : fondu du côté où il en reste.
		function bords() {
			barreOnglets.classList.toggle( 'a-gauche', liste.scrollLeft > 4 );
			barreOnglets.classList.toggle( 'a-droite', liste.scrollLeft + liste.clientWidth < liste.scrollWidth - 4 );
		}
		liste.addEventListener( 'scroll', bords, { passive: true } );
		window.addEventListener( 'resize', bords );
		var actifDepart = barreOnglets.querySelector( '[aria-selected="true"]' );
		if ( actifDepart && actifDepart.offsetLeft - liste.offsetLeft + actifDepart.offsetWidth > liste.clientWidth ) {
			liste.scrollLeft = actifDepart.offsetLeft - liste.offsetLeft - 24;
		}
		bords();

		function afficher( cle, garderAdresse ) {
			var partie = document.querySelector( '[data-partie="' + cle + '"]' );
			if ( ! partie ) {
				return;
			}
			document.querySelectorAll( '[data-partie]' ).forEach( function ( p ) {
				p.hidden = p !== partie;
			} );
			barreOnglets.querySelectorAll( '[role="tab"]' ).forEach( function ( a ) {
				a.setAttribute( 'aria-selected', a.getAttribute( 'data-onglet' ) === cle ? 'true' : 'false' );
			} );
			var actif = barreOnglets.querySelector( '[aria-selected="true"]' );
			if ( actif ) {
				// Onglet choisi bien en vue dans la barre (sans faire défiler la page).
				var debut = actif.offsetLeft - liste.offsetLeft;
				if ( debut < liste.scrollLeft || debut + actif.offsetWidth > liste.scrollLeft + liste.clientWidth ) {
					liste.scrollTo( { left: Math.max( 0, debut - 24 ), behavior: 'smooth' } );
				}
			}
			if ( ! garderAdresse ) {
				var url = new URL( window.location.href );
				if ( 'resume' === cle ) {
					url.searchParams.delete( 'onglet' );
				} else {
					url.searchParams.set( 'onglet', cle );
				}
				url.searchParams.delete( 'etr_msg' );
				window.history.replaceState( window.history.state, '', url.href );
			}
			// La barre d'onglets reste en vue ; le haut de la partie aussi.
			var haut = barreOnglets.getBoundingClientRect().top;
			if ( haut < 0 || haut > window.innerHeight / 2 ) {
				barreOnglets.scrollIntoView( { block: 'start' } );
			}
		}

		// Onglets et liens « Y aller » (prochaine étape) : sans rechargement.
		document.addEventListener( 'click', function ( e ) {
			var lien = e.target.closest( 'a[data-onglet]' );
			if ( ! lien || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 ) {
				return;
			}
			e.preventDefault();
			afficher( lien.getAttribute( 'data-onglet' ) );
		} );
		barreOnglets.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowRight' !== e.key && 'ArrowLeft' !== e.key ) {
				return;
			}
			var tabs = Array.prototype.slice.call( barreOnglets.querySelectorAll( '[role="tab"]' ) );
			var i    = tabs.indexOf( document.activeElement );
			if ( i < 0 ) {
				return;
			}
			var suivant = tabs[ ( i + ( 'ArrowRight' === e.key ? 1 : tabs.length - 1 ) ) % tabs.length ];
			suivant.focus();
			afficher( suivant.getAttribute( 'data-onglet' ) );
		} );

		// Après un enregistrement (la page revient sur la fiche), le même onglet est rouvert.
		document.addEventListener( 'submit', function ( e ) {
			var partie = e.target.closest( '[data-partie]' );
			var cle    = partie ? partie.getAttribute( 'data-partie' ) : '';
			if ( ! cle && e.target.closest( 'dialog' ) ) {
				var visible = document.querySelector( '[data-partie]:not([hidden])' );
				cle         = visible ? visible.getAttribute( 'data-partie' ) : '';
			}
			try {
				window.sessionStorage.setItem( RETOUR, JSON.stringify( { fiche: fiche, onglet: cle, le: Date.now() } ) );
			} catch ( err ) {}
		}, true );
		try {
			var retour = JSON.parse( window.sessionStorage.getItem( RETOUR ) || 'null' );
			window.sessionStorage.removeItem( RETOUR );
			if ( retour && retour.fiche === fiche && retour.onglet && Date.now() - retour.le < 120000 && ! new URL( window.location.href ).searchParams.get( 'onglet' ) ) {
				afficher( retour.onglet, true );
			}
		} catch ( err ) {}

		// « Valider ou refuser » (prochaine étape) : le panneau du statut.
		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest( '[data-ouvrir-statut]' ) ) {
				return;
			}
			var bouton = document.querySelector( '.etr-barre-action--feuilles .etr-btn:not(.etr-btn--discret)' );
			if ( telephone.matches && bouton ) {
				bouton.click();
				return;
			}
			var panneau = document.querySelector( '.etr-decision' );
			if ( panneau ) {
				panneau.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				var choix = panneau.querySelector( 'input[type="radio"]:checked, input[type="radio"]' );
				if ( choix ) {
					choix.focus( { preventScroll: true } );
				}
			}
		} );
	}() );
}() );
