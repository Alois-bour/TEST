/**
 * Espace Traiteur : met à jour les quantités disponibles du catalogue
 * dès que le traiteur choisit ses dates.
 */
( function () {
	'use strict';

	var form = document.querySelector( '.etr-form' );
	if ( ! form || ! window.etrDispo ) {
		return;
	}

	var cles = [ 'date_evenement', 'date_livraison', 'date_reprise' ];
	var info = form.querySelector( '.etr-dispo-info' );
	var minuteur = null;
	var requete = 0;

	function champ( cle ) {
		return form.querySelector( '#etr-' + cle );
	}

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
				return;
			}

			var indispo = donnee.dispo === 0;
			input.max = donnee.dispo;
			input.disabled = indispo;
			if ( indispo ) {
				input.value = '';
			} else if ( input.value && parseInt( input.value, 10 ) > donnee.dispo ) {
				input.value = donnee.dispo;
			}
			ligne.classList.toggle( 'etr-article--indispo', indispo );
		} );
	}

	function mettreAJour() {
		var dateEvenement = champ( 'date_evenement' );
		if ( ! dateEvenement || ! dateEvenement.value ) {
			if ( info ) {
				info.textContent = etrDispo.i18n.sansDate;
			}
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
		if ( info ) {
			info.textContent = etrDispo.i18n.chargement;
		}

		fetch( etrDispo.ajaxUrl, { method: 'POST', body: donnees, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( reponse ) {
				if ( numero !== requete ) {
					return; // Une requête plus récente est en cours.
				}
				if ( ! reponse.success ) {
					if ( info ) {
						info.textContent = ( reponse.data && reponse.data.message ) || etrDispo.i18n.erreur;
					}
					return;
				}
				if ( info ) {
					info.textContent = reponse.data.periode;
				}
				appliquer( reponse.data.articles );
			} )
			.catch( function () {
				if ( numero === requete && info ) {
					info.textContent = etrDispo.i18n.erreur;
				}
			} );
	}

	cles.forEach( function ( cle ) {
		var c = champ( cle );
		if ( c ) {
			c.addEventListener( 'change', function () {
				clearTimeout( minuteur );
				minuteur = setTimeout( mettreAJour, 250 );
			} );
		}
	} );

	if ( champ( 'date_evenement' ) && champ( 'date_evenement' ).value ) {
		mettreAJour();
	}
} )();
