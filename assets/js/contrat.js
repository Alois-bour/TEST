/*
 * Espace traiteur : signature du contrat de location au doigt (ou à la souris).
 * La signature part en image PNG dans un champ caché ; sans tracé, l'envoi est bloqué.
 */
( function () {
	'use strict';
	var form = document.querySelector( '.etr-contrat__signer' );
	if ( ! form ) {
		return;
	}
	var zone   = form.querySelector( '[data-contrat-zone]' );
	var toile  = zone.querySelector( 'canvas' );
	var champ  = form.querySelector( '[data-contrat-signature]' );
	var aide   = zone.querySelector( '[data-contrat-aide]' );
	var ctx    = null;
	var vide   = true;
	var trace  = false;
	var texteAide = aide.textContent;
	var textes    = window.etrContrat || { vide: 'Signez dans le cadre avant d’envoyer.', envoi: 'Signature en cours…' };
	var taille    = { l: 0, h: 0 }; // Taille de la toile au dernier calage.

	function preparer() {
		var cadre = toile.getBoundingClientRect();
		if ( ! cadre.width ) {
			return;
		}
		var ratio    = window.devicePixelRatio || 1;
		taille       = { l: cadre.width, h: cadre.height };
		toile.width  = Math.round( cadre.width * ratio );
		toile.height = Math.round( cadre.height * ratio );
		ctx          = toile.getContext( '2d' );
		ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
		ctx.lineWidth   = 2.4;
		ctx.lineCap     = 'round';
		ctx.lineJoin    = 'round';
		ctx.strokeStyle = '#1d1d1b';
		vide            = true;
		champ.value     = '';
		zone.classList.remove( 'est-signee', 'est-erreur' );
		aide.textContent = texteAide;
	}
	function point( e ) {
		var cadre = toile.getBoundingClientRect();
		return { x: e.clientX - cadre.left, y: e.clientY - cadre.top };
	}

	// La toile change de taille (barre de défilement qui apparaît, texte du contrat ouvert,
	// téléphone tourné) : on recale, en gardant le tracé à l'échelle ; sinon le trait
	// s'écarterait du doigt et la signature serait déformée.
	function recaler() {
		var cadre = toile.getBoundingClientRect();
		if ( ! cadre.width || ( Math.abs( cadre.width - taille.l ) < 1 && Math.abs( cadre.height - taille.h ) < 1 ) ) {
			return;
		}
		if ( vide ) {
			preparer();
			return;
		}
		var copie    = document.createElement( 'canvas' );
		copie.width  = toile.width;
		copie.height = toile.height;
		copie.getContext( '2d' ).drawImage( toile, 0, 0 );
		var garder   = champ.value;
		preparer();
		ctx.drawImage( copie, 0, 0, cadre.width, cadre.height );
		vide        = false;
		champ.value = garder;
		zone.classList.add( 'est-signee' );
	}
	if ( window.ResizeObserver ) {
		new window.ResizeObserver( recaler ).observe( toile );
	}

	toile.addEventListener( 'pointerdown', function ( e ) {
		if ( ! ctx ) {
			preparer();
		}
		recaler(); // Au cas où la taille a changé sans être signalée.
		e.preventDefault();
		toile.setPointerCapture( e.pointerId );
		trace = true;
		var p = point( e );
		ctx.beginPath();
		ctx.moveTo( p.x, p.y );
		ctx.lineTo( p.x + 0.1, p.y + 0.1 );
		ctx.stroke();
	} );
	toile.addEventListener( 'pointermove', function ( e ) {
		if ( ! trace ) {
			return;
		}
		var p = point( e );
		ctx.lineTo( p.x, p.y );
		ctx.stroke();
		vide = false;
		zone.classList.add( 'est-signee' );
		zone.classList.remove( 'est-erreur' );
	} );
	[ 'pointerup', 'pointercancel', 'pointerleave' ].forEach( function ( type ) {
		toile.addEventListener( type, function () {
			trace = false;
		} );
	} );
	zone.querySelector( '[data-contrat-effacer]' ).addEventListener( 'click', preparer );

	window.addEventListener( 'resize', recaler );
	preparer();

	form.addEventListener( 'submit', function ( e ) {
		if ( vide ) {
			e.preventDefault();
			zone.classList.add( 'est-erreur' );
			aide.textContent = textes.vide;
			zone.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			return;
		}
		// Fond blanc sous les traits (une image transparente se lit mal sur le PDF).
		var copie    = document.createElement( 'canvas' );
		copie.width  = toile.width;
		copie.height = toile.height;
		var c        = copie.getContext( '2d' );
		c.fillStyle  = '#ffffff';
		c.fillRect( 0, 0, copie.width, copie.height );
		c.drawImage( toile, 0, 0 );
		champ.value = copie.toDataURL( 'image/png' );
		var bouton = form.querySelector( 'button[type="submit"]' );
		if ( bouton ) {
			bouton.disabled    = true;
			bouton.textContent = textes.envoi;
		}
	} );
}() );
