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
		var demande = form.querySelector( 'input[name="demande_id"]' );
		if ( demande ) {
			donnees.append( 'demande_id', demande.value );
		}
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

	/* Dates : cohérence et aide au choix --------------------------------- */
	/*
	 * Livraison au plus tard le jour de l'événement (et au plus tôt N jours avant),
	 * reprise au plus tôt le jour de l'événement et après la livraison (au plus tard N jours après).
	 * Raccourcis « La veille », « Le jour même », « Le lendemain » ; date écrite en toutes lettres.
	 * Le serveur refait les mêmes contrôles (etr_erreurs_dates).
	 */
	( function () {
		var evt = champ( 'date_evenement' );
		var liv = champ( 'date_livraison' );
		var rep = champ( 'date_reprise' );
		if ( ! evt || ! liv || ! rep ) {
			return;
		}
		var auj    = etrDispo.aujourdhui;
		var ecarts = etrDispo.ecarts || { avant: 7, apres: 7 };
		var format = window.Intl ? new Intl.DateTimeFormat( 'fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' } ) : null;

		function decaler( date, jours ) {
			var d = new Date( date + 'T00:00:00Z' );
			d.setUTCDate( d.getUTCDate() + jours );
			return d.toISOString().slice( 0, 10 );
		}
		function plusTard( a, b ) {
			return a > b ? a : b;
		}
		function lisible( date ) {
			return format && date ? format.format( new Date( date + 'T00:00:00Z' ) ) : '';
		}

		// Sous chaque champ : la date en toutes lettres, un message, des raccourcis.
		function aide( input, raccourcis ) {
			var zone = document.createElement( 'span' );
			zone.className = 'etr-date-aide';
			zone.innerHTML = '<span class="etr-date-aide__lisible"></span><span class="etr-date-aide__message" role="status"></span>';
			if ( raccourcis.length ) {
				var puces = document.createElement( 'span' );
				puces.className = 'etr-date-aide__puces';
				raccourcis.forEach( function ( r ) {
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'etr-puce-date';
					b.textContent = r.libelle;
					b.addEventListener( 'click', function () {
						input.value = r.date();
						input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					} );
					r.bouton = b;
					puces.appendChild( b );
				} );
				zone.appendChild( puces );
			}
			input.insertAdjacentElement( 'afterend', zone );
			return { lisible: zone.querySelector( '.etr-date-aide__lisible' ), message: zone.querySelector( '.etr-date-aide__message' ), raccourcis: raccourcis };
		}

		var raccLiv = [
			{ libelle: 'La veille', date: function () { return decaler( evt.value, -1 ); } },
			{ libelle: 'Le jour même', date: function () { return evt.value; } },
		];
		var raccRep = [
			{ libelle: 'Le jour même', date: function () { return evt.value; } },
			{ libelle: 'Le lendemain', date: function () { return decaler( evt.value, 1 ); } },
		];
		var aides = { evt: aide( evt, [] ), liv: aide( liv, raccLiv ), rep: aide( rep, raccRep ) };

		// Livraison et reprise : une liste des seules dates possibles autour de l'événement, à la
		// place du calendrier (celui de l'iPhone ignore les dates limites et laissait choisir le
		// lendemain de l'événement pour la livraison). Le champ date reste celui qui est envoyé.
		var formatCourt = window.Intl ? new Intl.DateTimeFormat( 'fr-FR', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' } ) : null;
		function jours( a, b ) {
			return Math.round( ( new Date( b + 'T00:00:00Z' ) - new Date( a + 'T00:00:00Z' ) ) / 86400000 );
		}
		function relatif( date, e ) {
			var n = jours( e, date );
			if ( 0 === n ) {
				return 'le jour de l’événement';
			}
			if ( -1 === n ) {
				return 'la veille';
			}
			if ( 1 === n ) {
				return 'le lendemain';
			}
			return n < 0 ? ( -n ) + ' jours avant' : n + ' jours après';
		}
		function liste( input, aideDate ) {
			var choix = document.createElement( 'select' );
			choix.className = 'etr-select-date';
			choix.id        = input.id + '-choix';
			var label = document.querySelector( 'label[for="' + input.id + '"]' );
			if ( label ) {
				label.setAttribute( 'for', choix.id ); // Le libellé désigne la liste.
			}
			input.insertAdjacentElement( 'beforebegin', choix );
			input.hidden = true;
			aideDate.lisible.hidden = true; // La liste montre déjà la date en toutes lettres.
			var puces = aideDate.lisible.parentNode.querySelector( '.etr-date-aide__puces' );
			if ( puces ) {
				puces.hidden = true;
			}
			choix.addEventListener( 'change', function () {
				input.value = choix.value;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
			return choix;
		}
		var choixLiv = liste( liv, aides.liv );
		var choixRep = liste( rep, aides.rep );
		function remplir( choix, input, debut, fin, e ) {
			var options = [];
			if ( ! e ) {
				options.push( [ '', 'Choisissez d’abord la date de l’événement' ] );
			} else {
				options.push( [ '', 'Le jour de l’événement (par défaut)' ] );
				for ( var d = debut; d <= fin; d = decaler( d, 1 ) ) {
					var texte = formatCourt ? formatCourt.format( new Date( d + 'T00:00:00Z' ) ) : d;
					options.push( [ d, texte.charAt( 0 ).toUpperCase() + texte.slice( 1 ) + ' · ' + relatif( d, e ) ] );
				}
			}
			var cle = options.map( function ( o ) {
				return o[ 0 ];
			} ).join( '|' );
			if ( choix.getAttribute( 'data-cle' ) !== cle ) {
				choix.innerHTML = '';
				options.forEach( function ( o ) {
					var opt = document.createElement( 'option' );
					opt.value       = o[ 0 ];
					opt.textContent = o[ 1 ];
					choix.appendChild( opt );
				} );
				choix.setAttribute( 'data-cle', cle );
			}
			choix.disabled = ! e;
			// Une date enregistrée hors des choix (ancienne demande) reste affichée, signalée par le message.
			if ( input.value && ! choix.querySelector( 'option[value="' + input.value + '"]' ) ) {
				var hors = document.createElement( 'option' );
				hors.value       = input.value;
				hors.textContent = ( formatCourt ? formatCourt.format( new Date( input.value + 'T00:00:00Z' ) ) : input.value ) + ' · à changer';
				choix.appendChild( hors );
				choix.setAttribute( 'data-cle', '' );
			}
			choix.value = input.value;
		}
		function majListes() {
			var e = evt.value;
			if ( ! e ) {
				remplir( choixLiv, liv, '', '', '' );
				remplir( choixRep, rep, '', '', '' );
				return;
			}
			remplir( choixLiv, liv, plusTard( auj, decaler( e, -ecarts.avant ) ), e, e );
			remplir( choixRep, rep, plusTard( e, liv.value || e ), decaler( e, ecarts.apres ), e );
		}

		function signaler( input, a, texte ) {
			// Champ caché : l'erreur est portée par la liste (le navigateur la montre à l'envoi).
			var cible = input.hidden && input.previousElementSibling && 'SELECT' === input.previousElementSibling.tagName ? input.previousElementSibling : input;
			input.setCustomValidity( '' );
			cible.setCustomValidity( texte || '' );
			a.message.textContent = texte || '';
			a.message.classList.toggle( 'est-erreur', !! texte );
			input.closest( '.etr-champ' ).classList.toggle( 'etr-champ--erreur', !! texte );
		}

		function borner( input, min, max ) {
			input.min = min || '';
			input.max = max || '';
		}

		function controler() {
			var e = evt.value;
			borner( evt, auj, '' );
			if ( ! e ) {
				borner( liv, auj, '' );
				borner( rep, auj, '' );
			} else {
				var livMin = plusTard( auj, decaler( e, -ecarts.avant ) );
				borner( liv, livMin, e );
				borner( rep, plusTard( e, liv.value || e ), decaler( e, ecarts.apres ) );
			}
			[ [ evt, aides.evt ], [ liv, aides.liv ], [ rep, aides.rep ] ].forEach( function ( c ) {
				c[ 1 ].lisible.textContent = lisible( c[ 0 ].value );
			} );

			signaler( evt, aides.evt, e && e < auj ? 'La date de l’événement ne peut pas être passée.' : '' );
			var mLiv = '';
			if ( liv.value ) {
				if ( liv.value < auj ) {
					mLiv = 'La date de livraison ne peut pas être passée.';
				} else if ( e && liv.value > e ) {
					mLiv = 'La livraison doit avoir lieu au plus tard le jour de l’événement.';
				} else if ( e && liv.value < decaler( e, -ecarts.avant ) ) {
					mLiv = 'La livraison peut avoir lieu au plus tôt ' + ecarts.avant + ' jours avant l’événement.';
				}
			}
			signaler( liv, aides.liv, mLiv );
			var mRep = '';
			if ( rep.value ) {
				if ( e && rep.value < e ) {
					mRep = 'La reprise doit avoir lieu au plus tôt le jour de l’événement.';
				} else if ( liv.value && rep.value < liv.value ) {
					mRep = 'La reprise ne peut pas avoir lieu avant la livraison.';
				} else if ( e && rep.value > decaler( e, ecarts.apres ) ) {
					mRep = 'La reprise peut avoir lieu au plus tard ' + ecarts.apres + ' jours après l’événement.';
				}
			}
			signaler( rep, aides.rep, mRep );
			majListes();
			if ( retraits.liv && ! liv.value ) {
				aides.liv.message.textContent = retraits.liv;
			}
			if ( retraits.rep && ! rep.value ) {
				aides.rep.message.textContent = retraits.rep;
			}

			// Raccourcis : seulement avec une date d'événement, et s'ils respectent les règles.
			[ [ raccLiv, liv ], [ raccRep, rep ] ].forEach( function ( r ) {
				r[ 0 ].forEach( function ( racc ) {
					var d = e ? racc.date() : '';
					racc.bouton.disabled = ! d || ( r[ 1 ].min && d < r[ 1 ].min ) || ( r[ 1 ].max && d > r[ 1 ].max );
					racc.bouton.classList.toggle( 'est-choisi', !! d && r[ 1 ].value === d );
				} );
			} );
		}

		// L'événement change : une livraison ou une reprise devenue incohérente est retirée, et on le
		// dit sous le champ jusqu'à ce que la personne y choisisse une nouvelle date.
		var retraits = { liv: '', rep: '' };
		var auto     = false;
		function retirer( input, cle, texte ) {
			input.value    = '';
			retraits[ cle ] = texte;
			auto           = true;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) ); // Disponibilités recalculées.
			auto           = false;
		}
		evt.addEventListener( 'change', function () {
			var e = evt.value;
			if ( e && liv.value && ( liv.value > e || liv.value < decaler( e, -ecarts.avant ) ) ) {
				retirer( liv, 'liv', 'Date de livraison retirée : elle ne correspondait plus à l’événement.' );
			}
			if ( e && rep.value && ( rep.value < e || rep.value > decaler( e, ecarts.apres ) ) ) {
				retirer( rep, 'rep', 'Date de reprise retirée : elle ne correspondait plus à l’événement.' );
			}
			controler();
		} );
		[ [ liv, 'liv' ], [ rep, 'rep' ] ].forEach( function ( c ) {
			c[ 0 ].addEventListener( 'change', function () {
				if ( ! auto ) {
					retraits[ c[ 1 ] ] = '';
				}
				controler();
			} );
		} );
		[ evt, liv, rep ].forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				controler();
			} );
		} );
		controler();
	}() );

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
