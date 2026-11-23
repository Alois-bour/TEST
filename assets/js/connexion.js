/**
 * Espace Traiteur : écran de connexion (afficher le mot de passe, état d'envoi).
 */
( function () {
	'use strict';

	var auth = document.querySelector( '.etr-auth' );
	if ( ! auth ) {
		return;
	}
	auth.classList.add( 'etr-auth--js' );

	// Afficher / masquer le mot de passe.
	auth.querySelectorAll( '.etr-auth__oeil' ).forEach( function ( bouton ) {
		bouton.addEventListener( 'click', function () {
			var champ = document.getElementById( bouton.getAttribute( 'aria-controls' ) );
			if ( ! champ ) {
				return;
			}
			var visible = champ.type === 'password';
			champ.type = visible ? 'text' : 'password';
			bouton.setAttribute( 'aria-pressed', visible ? 'true' : 'false' );
			bouton.textContent = visible ? bouton.dataset.masquer : bouton.dataset.afficher;
			champ.focus();
		} );
	} );

	// État « en cours » sur le bouton, et pas de double envoi.
	auth.querySelectorAll( 'form.etr-auth__form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			var bouton = form.querySelector( '.etr-auth__bouton' );
			if ( ! bouton ) {
				return;
			}
			if ( bouton.getAttribute( 'aria-busy' ) === 'true' ) {
				e.preventDefault();
				return;
			}
			var texte = bouton.querySelector( 'span' );
			if ( texte ) {
				bouton.dataset.libelle = texte.textContent;
				texte.textContent = bouton.dataset.chargement || texte.textContent;
			}
			bouton.setAttribute( 'aria-busy', 'true' );
		} );
	} );

	// Retour arrière du navigateur : on rétablit le bouton.
	window.addEventListener( 'pageshow', function ( e ) {
		if ( ! e.persisted ) {
			return;
		}
		auth.querySelectorAll( '.etr-auth__bouton[aria-busy="true"]' ).forEach( function ( bouton ) {
			var texte = bouton.querySelector( 'span' );
			if ( texte && bouton.dataset.libelle ) {
				texte.textContent = bouton.dataset.libelle;
			}
			bouton.removeAttribute( 'aria-busy' );
		} );
	} );
} )();
