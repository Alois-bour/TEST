/**
 * Espace Traiteur : formulaire de demande.
 *
 * - met à jour les quantités disponibles du catalogue dès que le traiteur choisit ses dates ;
 * - boutons − / + sur les quantités ;
 * - recherche et filtre par famille dans le catalogue ;
 * - récapitulatif « Votre sélection ».
 */
( function () {
	'use strict';

	var form = document.querySelector( '.etr-form' );
	if ( ! form || ! window.etrDispo ) {
		return;
	}

	var i18n = etrDispo.i18n;
	var cles = [ 'date_evenement', 'date_livraison', 'date_reprise' ];
	var info = form.querySelector( '.etr-dispo-info' );
	var infoTexte = info ? info.querySelector( '.etr-dispo-info__texte' ) || info : null;
	var lignes = Array.prototype.slice.call( form.querySelectorAll( '.etr-article' ) );
	var minuteur = null;
	var requete = 0;

	function champ( cle ) {
		return form.querySelector( '#etr-' + cle );
	}

	function afficherInfo( texte, avecDates ) {
		if ( ! info ) {
			return;
		}
		infoTexte.textContent = texte;
		info.classList.toggle( 'etr-dispo-info--dates', !! avecDates );
	}

	/* Quantités ------------------------------------------------------- */

	function quantite( input ) {
		var v = parseInt( input.value, 10 );
		return isNaN( v ) || v < 0 ? 0 : v;
	}

	function etatLigne( ligne ) {
		var input = ligne.querySelector( '.etr-article__qte' );
		if ( ! input ) {
			return;
		}
		var q = quantite( input );
		var max = input.max === '' ? Infinity : parseInt( input.max, 10 );
		var moins = ligne.querySelector( '.etr-qte__btn--moins' );
		var plus = ligne.querySelector( '.etr-qte__btn--plus' );
		ligne.classList.toggle( 'etr-article--choisi', q > 0 );
		if ( moins ) {
			moins.disabled = input.disabled || q <= 0;
		}
		if ( plus ) {
			plus.disabled = input.disabled || q >= max;
		}
	}

	function bouton( classe, texte, libelle ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'etr-qte__btn ' + classe;
		b.textContent = texte;
		b.setAttribute( 'aria-label', libelle );
		return b;
	}

	lignes.forEach( function ( ligne ) {
		var boite = ligne.querySelector( '.etr-qte' );
		var input = ligne.querySelector( '.etr-article__qte' );
		if ( ! boite || ! input ) {
			return;
		}
		var nom = ligne.getAttribute( 'data-titre' ) || '';
		var moins = bouton( 'etr-qte__btn--moins', '−', i18n.moins + ' : ' + nom );
		var plus = bouton( 'etr-qte__btn--plus', '+', i18n.plus + ' : ' + nom );
		boite.insertBefore( moins, input );
		boite.appendChild( plus );
		boite.classList.add( 'etr-qte--js' );

		function changer( delta ) {
			var max = input.max === '' ? Infinity : parseInt( input.max, 10 );
			var q = Math.min( max, Math.max( 0, quantite( input ) + delta ) );
			input.value = q ? String( q ) : '';
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
		moins.addEventListener( 'click', function () {
			changer( -1 );
		} );
		plus.addEventListener( 'click', function () {
			changer( 1 );
		} );
		input.addEventListener( 'input', function () {
			etatLigne( ligne );
			recapituler();
		} );
		etatLigne( ligne );
	} );

	/* Récapitulatif --------------------------------------------------- */

	var recap = form.querySelector( '.etr-recap-commande' );
	var recapListe = recap ? recap.querySelector( '.etr-recap-commande__liste' ) : null;
	var recapResume = recap ? recap.querySelector( '.etr-recap-commande__resume' ) : null;

	function recapituler() {
		if ( ! recapListe ) {
			return;
		}
		recapListe.textContent = '';
		var nombre = 0;
		lignes.forEach( function ( ligne ) {
			var input = ligne.querySelector( '.etr-article__qte' );
			var q = input && ! input.disabled ? quantite( input ) : 0;
			if ( ! q ) {
				return;
			}
			nombre++;
			var unite = ligne.getAttribute( 'data-unite' );
			var li = document.createElement( 'li' );
			var a = document.createElement( 'a' );
			var nom = document.createElement( 'span' );
			var qte = document.createElement( 'span' );
			a.href = '#' + input.id;
			nom.textContent = ligne.getAttribute( 'data-titre' );
			qte.textContent = q.toLocaleString( document.documentElement.lang || 'fr' ) + ( unite ? ' ' + unite : '' );
			a.appendChild( nom );
			a.appendChild( qte );
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				ligne.hidden = false;
				ligne.scrollIntoView( { block: 'center', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
				input.focus( { preventScroll: true } );
			} );
			li.appendChild( a );
			recapListe.appendChild( li );
		} );
		if ( recapResume ) {
			recapResume.textContent = nombre
				? ( nombre > 1 ? i18n.articles : i18n.unArticle ).replace( '%s', nombre )
				: i18n.aucun;
		}
	}

	/* Recherche et familles ------------------------------------------- */

	var outils = form.querySelector( '.etr-outils' );
	var recherche = form.querySelector( '.etr-recherche__champ' );
	var aucun = form.querySelector( '.etr-catalogue__aucun' );
	var familles = Array.prototype.slice.call( form.querySelectorAll( '.etr-famille' ) );
	var boutonsFamille = Array.prototype.slice.call( form.querySelectorAll( '.etr-familles__btn' ) );
	var familleActive = '';

	function normaliser( texte ) {
		return texte.toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).trim();
	}

	function filtrer() {
		var terme = recherche ? normaliser( recherche.value ) : '';
		var visibles = 0;
		familles.forEach( function ( famille ) {
			var dansFamille = '' === familleActive || famille.getAttribute( 'data-famille' ) === familleActive;
			var compteFamille = 0;
			famille.querySelectorAll( '.etr-sous' ).forEach( function ( sous ) {
				var compteSous = 0;
				sous.querySelectorAll( '.etr-article' ).forEach( function ( ligne ) {
					var ok = dansFamille && ( ! terme || ( ligne.getAttribute( 'data-recherche' ) || '' ).indexOf( terme ) !== -1 );
					ligne.hidden = ! ok;
					if ( ok ) {
						compteSous++;
					}
				} );
				sous.hidden = ! compteSous;
				compteFamille += compteSous;
			} );
			famille.hidden = ! compteFamille;
			visibles += compteFamille;
		} );
		if ( aucun ) {
			aucun.hidden = visibles > 0;
		}
	}

	if ( outils ) {
		outils.hidden = false;
	}
	if ( recherche ) {
		recherche.addEventListener( 'input', filtrer );
	}
	boutonsFamille.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			familleActive = b.getAttribute( 'data-famille' );
			boutonsFamille.forEach( function ( autre ) {
				autre.setAttribute( 'aria-pressed', autre === b ? 'true' : 'false' );
			} );
			filtrer();
		} );
	} );

	/* Disponibilités -------------------------------------------------- */

	function appliquer( articles ) {
		Object.keys( articles ).forEach( function ( id ) {
			var ligne = form.querySelector( '.etr-article[data-article="' + id + '"]' );
			if ( ! ligne ) {
				return;
			}
			var donnee = articles[ id ];
			var input = ligne.querySelector( '.etr-article__qte' );
			var texte = ligne.querySelector( '.etr-dispo' );

			if ( texte ) {
				texte.textContent = donnee.texte;
			}
			if ( donnee.dispo === null ) {
				input.removeAttribute( 'max' );
				input.disabled = false;
				ligne.classList.remove( 'etr-article--indispo' );
			} else {
				var indispo = donnee.dispo === 0;
				input.max = donnee.dispo;
				input.disabled = indispo && ! input.value;
				ligne.classList.toggle( 'etr-article--indispo', indispo );
			}
			etatLigne( ligne );
		} );
		recapituler();
	}

	function mettreAJour() {
		var dateEvenement = champ( 'date_evenement' );
		if ( ! dateEvenement || ! dateEvenement.value ) {
			afficherInfo( i18n.sansDate, false );
			return;
		}

		var donnees = new FormData();
		donnees.append( 'action', 'etr_disponibilites' );
		donnees.append( 'nonce', etrDispo.nonce );
		cles.forEach( function ( cle ) {
			var c = champ( cle );
			donnees.append( cle, c ? c.value : '' );
		} );

		var numero = ++requete;
		afficherInfo( i18n.chargement, true );

		fetch( etrDispo.ajaxUrl, { method: 'POST', body: donnees, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( reponse ) {
				if ( numero !== requete ) {
					return; // Une requête plus récente est en cours.
				}
				if ( ! reponse.success ) {
					afficherInfo( ( reponse.data && reponse.data.message ) || i18n.erreur, false );
					return;
				}
				afficherInfo( reponse.data.periode, true );
				appliquer( reponse.data.articles );
			} )
			.catch( function () {
				if ( numero === requete ) {
					afficherInfo( i18n.erreur, false );
				}
			} );
	}

	cles.forEach( function ( cle ) {
		var c = champ( cle );
		if ( c ) {
			c.addEventListener( 'change', function () {
				requete++;
				clearTimeout( minuteur );
				minuteur = setTimeout( mettreAJour, 250 );
			} );
		}
	} );

	recapituler();
	if ( champ( 'date_evenement' ) && champ( 'date_evenement' ).value ) {
		mettreAJour();
	}
} )();
