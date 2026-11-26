<?php
/**
 * Écrans du terrain dans l'application de l'équipe : tournée du jour, préparation,
 * étiquettes QR, scanner, livraison signée, reprise ; et les panneaux de suivi d'une demande.
 *
 * Les enregistrements passent par l'API (ETR_Operations), appelée par assets/js/terrain.js.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écrans du terrain.
 */
class ETR_Terrain {

	/**
	 * Icône.
	 *
	 * @param string $nom Nom.
	 * @return string
	 */
	private static function icone( $nom ) {
		return ETR_Frontend::icone( $nom );
	}

	/**
	 * Lien interne.
	 *
	 * @param array $args Paramètres.
	 * @return string
	 */
	private static function url( $args ) {
		return ETR_Gestion::url( $args );
	}

	/**
	 * Lien de retour vers la fiche d'une demande.
	 *
	 * @param int $id Demande.
	 * @return string HTML.
	 */
	private static function retour_demande( $id ) {
		return '<a class="etr-retour" href="' . esc_url( self::url( array( 'vue' => 'demande', 'id' => $id ) ) ) . '">' . self::icone( 'retour' ) . '<span>' . esc_html__( 'Demande', 'espace-traiteur' ) . ' n° ' . (int) $id . '</span></a>';
	}

	/**
	 * Demande introuvable ou au mauvais statut.
	 *
	 * @param int      $id      Demande.
	 * @param string[] $statuts Statuts permis.
	 * @return bool Vrai si l'écran peut s'afficher.
	 */
	private static function controle( $id, $statuts ) {
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Cette demande n’existe pas ou ne vous est pas visible.', 'espace-traiteur' ) . '</p></div>';
			return false;
		}
		if ( ! in_array( etr_get_statut( $id ), $statuts, true ) ) {
			echo self::retour_demande( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé.
			/* translators: %s : statut. */
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html( sprintf( __( 'Rien à faire ici : la demande est « %s ».', 'espace-traiteur' ), etr_statuts()[ etr_get_statut( $id ) ] ) ) . '</p></div>';
			return false;
		}
		return true;
	}

	/**
	 * Lien d'itinéraire (Plans sur iPhone).
	 *
	 * @param string $adresse Adresse.
	 * @return string
	 */
	public static function url_itineraire( $adresse ) {
		return 'https://maps.apple.com/?daddr=' . rawurlencode( preg_replace( '/\s+/', ' ', trim( $adresse ) ) );
	}

	/**
	 * Texte avec numéros de téléphone cliquables.
	 *
	 * @param string $texte Texte libre (ex. « Julie 06 12 34 56 78 »).
	 * @return string HTML.
	 */
	public static function telephones_cliquables( $texte ) {
		$html = esc_html( $texte );
		return (string) preg_replace_callback(
			'/(?:\+33\s?|0)[1-9](?:[\s.\-]?\d{2}){4}/',
			function ( $m ) {
				return '<a class="etr-lien" href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $m[0] ) ) . '">' . $m[0] . '</a>';
			},
			$html
		);
	}

	/* ------------------------------------------------------------------ */
	/* Tournée                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Livraisons et reprises du jour.
	 */
	public static function vue_tournee() {
		$jour = isset( $_GET['jour'] ) ? sanitize_text_field( wp_unslash( $_GET['jour'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$jour = etr_date_valide( $jour ) ? $jour : current_datetime()->format( 'Y-m-d' );
		$date = new DateTimeImmutable( $jour, wp_timezone() );

		$nav  = '<nav class="etr-tournee__nav" aria-label="' . esc_attr__( 'Jour', 'espace-traiteur' ) . '">';
		$nav .= '<a class="etr-btn etr-btn--discret etr-btn--icone" href="' . esc_url( self::url( array( 'vue' => 'tournee', 'jour' => $date->modify( '-1 day' )->format( 'Y-m-d' ) ) ) ) . '" aria-label="' . esc_attr__( 'Jour précédent', 'espace-traiteur' ) . '">' . self::icone( 'retour' ) . '</a>';
		$nav .= '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'tournee' ) ) ) . '">' . esc_html__( 'Aujourd’hui', 'espace-traiteur' ) . '</a>';
		$nav .= '<a class="etr-btn etr-btn--discret etr-btn--icone" href="' . esc_url( self::url( array( 'vue' => 'tournee', 'jour' => $date->modify( '+1 day' )->format( 'Y-m-d' ) ) ) ) . '" aria-label="' . esc_attr__( 'Jour suivant', 'espace-traiteur' ) . '">' . self::icone( 'suite' ) . '</a>';
		$nav .= '</nav>';

		ETR_Gestion::entete( __( 'Tournée', 'espace-traiteur' ), ucfirst( wp_date( 'l j F Y', $date->getTimestamp() ) ), $nav );

		// Gardés sur le téléphone pour le hors connexion (hors-ligne.js) : scanner et lecteur de QR.
		echo '<a hidden data-hors-ligne href="' . esc_url( self::url( array( 'vue' => 'scanner' ) ) ) . '"></a><a hidden data-hors-ligne href="' . esc_url( ETR_URL . 'assets/vendor/jsqr.min.js?ver=' . ETR_VERSION ) . '"></a>';
		// Le bureau voit et répartit toute la tournée ; un livreur voit ses étapes et celles à répartir.
		$bureau  = ETR_Equipe::bureau();
		$tournee = $bureau ? ETR_Operations::tournee( $jour ) : ETR_Operations::tournee_de( $jour, get_current_user_id() );
		if ( ! $tournee['livraisons'] && ! $tournee['reprises'] ) {
			echo '<div class="etr-vide"><p>' . esc_html__( 'Aucune livraison ni reprise ce jour-là.', 'espace-traiteur' ) . '</p></div>';
			return;
		}
		$itineraires = self::itineraires( self::lieux_tournee( array_merge( $tournee['livraisons'], $tournee['reprises'] ) ) );
		if ( $itineraires ) {
			echo '<p class="etr-tournee__route">';
			foreach ( $itineraires as $part ) {
				$libelle = 1 === count( $itineraires )
					? __( 'Itinéraire de la tournée', 'espace-traiteur' )
					/* translators: 1 : première étape, 2 : dernière étape. */
					: sprintf( __( 'Itinéraire, étapes %1$d à %2$d', 'espace-traiteur' ), $part['de'], $part['a'] );
				echo '<a class="etr-btn" href="' . esc_url( $part['url'] ) . '" target="_blank" rel="noopener">' . self::icone( 'lieu' ) . '<span>' . esc_html( $libelle ) . '</span></a> '; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<small>' . esc_html( count( $itineraires ) > 1 ? __( 'Google Maps limite un itinéraire à 10 étapes : ouvrez les tronçons l’un après l’autre.', 'espace-traiteur' ) : __( 'Toutes les étapes, dans l’ordre (Google Maps).', 'espace-traiteur' ) ) . '</small></p>';
		}
		do_action( 'etr_tournee_haut', $jour, $tournee, $bureau ); // Volume du jour, optimisation de l'ordre.
		$livreurs = $bureau ? ETR_Operations::livreurs() : array();
		foreach ( array( 'livraisons' => __( 'Livraisons', 'espace-traiteur' ), 'reprises' => __( 'Reprises', 'espace-traiteur' ) ) as $cle => $titre ) {
			if ( ! $tournee[ $cle ] ) {
				continue;
			}
			echo '<section class="etr-tournee"><h2 class="etr-tournee__titre">' . esc_html( $titre ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $tournee[ $cle ] ) ) ) . '</span></h2><ul class="etr-tournee__liste"' . ( $bureau ? ' data-ordre="' . ( 'livraisons' === $cle ? 'livraison' : 'reprise' ) . '"' : '' ) . '>';
			foreach ( $tournee[ $cle ] as $id ) {
				self::carte_tournee( $id, 'livraisons' === $cle, $livreurs );
			}
			echo '</ul></section>';
		}
	}

	/**
	 * Itinéraires de toute la tournée (Google Maps : départ de la position actuelle, étapes dans
	 * l'ordre), par tronçons de 10 étapes au plus : afficher tous les tronçons.
	 *
	 * @param int[] $ids Demandes, dans l'ordre de passage.
	 * @return array[] { url, de, a } ; vide sans lieu connu.
	 */
	public static function urls_itineraire_tournee( $ids ) {
		return self::itineraires( self::lieux_tournee( $ids ) );
	}

	/**
	 * Itinéraires de la tournée par tronçons de 10 étapes au plus (limite de Google Maps) :
	 * le tronçon suivant part de la dernière étape du précédent.
	 *
	 * @param string[] $lieux Lieux, dans l'ordre.
	 * @return array[] { url, de, a } (numéros d'étapes).
	 */
	public static function itineraires( $lieux ) {
		$parts = array();
		$total = count( $lieux );
		for ( $debut = 0; $debut < $total; $debut += 9 ) {
			$troncon     = array_slice( $lieux, $debut, 10 );
			$destination = array_pop( $troncon );
			$url         = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . rawurlencode( $destination );
			if ( $debut > 0 ) {
				$url    .= '&origin=' . rawurlencode( array_shift( $troncon ) ); // Départ : dernière étape du tronçon précédent.
			}
			if ( $troncon ) {
				$url .= '&waypoints=' . implode( '%7C', array_map( 'rawurlencode', $troncon ) );
			}
			$parts[] = array( 'url' => $url, 'de' => $debut + 1, 'a' => min( $total, $debut + 10 ) );
			if ( $debut + 10 >= $total ) {
				break;
			}
		}
		return $parts;
	}

	/**
	 * Lieux des étapes, dans l'ordre.
	 *
	 * @param int[] $ids Demandes.
	 * @return string[]
	 */
	public static function lieux_tournee( $ids ) {
		$lieux = array();
		foreach ( $ids as $id ) {
			$lieu = trim( preg_replace( '/\s+/', ' ', (string) get_post_meta( $id, '_etr_lieu', true ) ) );
			if ( '' !== $lieu ) {
				$lieux[] = $lieu;
			}
		}
		return $lieux;
	}

	/**
	 * Carte d'une étape de tournée.
	 *
	 * @param int      $id        Demande.
	 * @param bool     $livraison Livraison (sinon reprise).
	 * @param string[] $livreurs  Livreurs (bureau : répartition des étapes), sinon vide.
	 */
	private static function carte_tournee( $id, $livraison, $livreurs = array() ) {
		$lieu    = (string) get_post_meta( $id, '_etr_lieu', true );
		$contact = (string) get_post_meta( $id, '_etr_contact', true );
		$prepa   = ETR_Operations::preparation( $id );
		$bilan   = ETR_Operations::bilan_colis( $id );

		echo '<li class="etr-arret" data-demande="' . (int) $id . '" data-etape="' . ( $livraison ? 'livraison' : 'reprise' ) . '">';
		echo '<div class="etr-arret__tete"><a class="etr-arret__nom" data-hors-ligne href="' . esc_url( self::url( array( 'vue' => 'demande', 'id' => $id ) ) ) . '"><strong>' . esc_html( ETR_Operations::traiteur( $id ) ) . '</strong><span>' . esc_html( get_the_title( $id ) ) . '</span></a>';
		echo self::badge_preparation( $id, $livraison ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé.
		echo '</div>';
		echo self::mini_suite( $id, $livraison ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé.
		$etape   = $livraison ? 'livraison' : 'reprise';
		$confiee = ETR_Operations::livreur_de( $id, $etape );
		if ( $livreurs ) {
			// Bureau : à qui confier l'étape, et ordre de passage.
			echo '<div class="etr-arret__repartition"><label><span>' . esc_html__( 'Livreur', 'espace-traiteur' ) . '</span><select data-affecter data-id="' . (int) $id . '" data-etape="' . esc_attr( $etape ) . '"><option value="0">' . esc_html__( '— À répartir —', 'espace-traiteur' ) . '</option>';
			foreach ( $livreurs as $livreur_id => $nom ) {
				echo '<option value="' . (int) $livreur_id . '"' . selected( $confiee, $livreur_id, false ) . '>' . esc_html( $nom ) . '</option>';
			}
			echo '</select></label><span class="etr-arret__ordre"><button type="button" class="etr-btn etr-btn--discret etr-btn--icone" data-deplacer="-1" aria-label="' . esc_attr__( 'Plus tôt dans la tournée', 'espace-traiteur' ) . '">↑</button><button type="button" class="etr-btn etr-btn--discret etr-btn--icone" data-deplacer="1" aria-label="' . esc_attr__( 'Plus tard dans la tournée', 'espace-traiteur' ) . '">↓</button></span></div>';
		} elseif ( ! $confiee ) {
			echo '<p class="etr-arret__libre">' . esc_html__( 'À répartir : pas encore confiée à un livreur', 'espace-traiteur' ) . '</p>';
		}
		do_action( 'etr_tournee_carte_infos', $id, $etape ); // Créneau, volume.
		if ( $lieu ) {
			echo '<p class="etr-arret__ligne">' . self::icone( 'lieu' ) . '<span>' . nl2br( esc_html( $lieu ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $contact ) {
			echo '<p class="etr-arret__ligne">' . self::icone( 'telephone' ) . '<span>' . self::telephones_cliquables( $contact ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $bilan['colis'] ) {
			$fait = $livraison ? $bilan['sortis'] : $bilan['revenus'];
			/* translators: 1 : colis scannés, 2 : total. */
			$texte = $livraison ? __( '%1$s / %2$s colis chargés', 'espace-traiteur' ) : __( '%1$s / %2$s colis revenus', 'espace-traiteur' );
			echo '<p class="etr-arret__ligne' . ( $fait < $bilan['colis'] ? '' : ' etr-arret__ligne--ok' ) . '">' . self::icone( 'catalogue' ) . '<span>' . esc_html( sprintf( $texte, number_format_i18n( $fait ), number_format_i18n( $bilan['colis'] ) ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( $livraison && ! $prepa['prete'] ) {
			echo '<p class="etr-arret__ligne etr-muted">' . self::icone( 'catalogue' ) . '<span>' . esc_html__( 'Colis pas encore comptés', 'espace-traiteur' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<div class="etr-arret__actions">';
		echo '<a class="etr-btn etr-btn--discret etr-btn--icone" href="' . esc_url( self::url( array( 'vue' => 'incident', 'id' => $id ) ) ) . '" data-hors-ligne aria-label="' . esc_attr__( 'Signaler un incident', 'espace-traiteur' ) . '" title="' . esc_attr__( 'Signaler un incident', 'espace-traiteur' ) . '">' . self::icone( 'alerte' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $lieu ) {
			echo '<a class="etr-btn etr-btn--discret etr-arret__itineraire" href="' . esc_url( self::url_itineraire( $lieu ) ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Itinéraire', 'espace-traiteur' ) . '">' . self::icone( 'lieu' ) . '<span>' . esc_html__( 'Itinéraire', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		do_action( 'etr_tournee_actions', $id, $etape ); // Fonctions activables : « En route ».
		if ( ETR_Equipe::livreur() ) {
			if ( ! $livraison ) {
				echo '<a class="etr-btn" data-hors-ligne data-action-etape href="' . esc_url( self::url( array( 'vue' => 'reprendre', 'id' => $id ) ) ) . '">' . self::icone( 'reprise' ) . '<span>' . esc_html__( 'Reprendre', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( ! $prepa['prete'] ) {
				// Pas encore prête : on ne part pas avec une commande incomplète (le bureau peut forcer).
				echo '<span class="etr-btn etr-btn--attente" data-action-etape aria-disabled="true">' . esc_html__( 'Pas encore prête', 'espace-traiteur' ) . '</span>';
				if ( ETR_Equipe::bureau() ) {
					echo '<a class="etr-btn etr-btn--discret" data-hors-ligne href="' . esc_url( self::url( array( 'vue' => 'livrer', 'id' => $id ) ) ) . '" data-confirmer="' . esc_attr__( 'La commande n’est pas marquée prête. Livrer quand même ?', 'espace-traiteur' ) . '">' . esc_html__( 'Livrer quand même', 'espace-traiteur' ) . '</a>';
				}
			} elseif ( $bilan['colis'] && $bilan['sortis'] < $bilan['colis'] ) {
				// Prête : d'abord charger (scanner les colis), puis livrer.
				echo '<a class="etr-btn" data-action-etape href="' . esc_url( self::url( array( 'vue' => 'scanner', 'mode' => 'sortie', 'd' => $id ) ) ) . '">' . self::icone( 'scan' ) . '<span>' . esc_html__( 'Charger les colis', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<a class="etr-btn etr-btn--discret" data-hors-ligne href="' . esc_url( self::url( array( 'vue' => 'livrer', 'id' => $id ) ) ) . '">' . self::icone( 'coche' ) . '<span>' . esc_html__( 'Livrer', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				echo '<a class="etr-btn" data-hors-ligne data-action-etape href="' . esc_url( self::url( array( 'vue' => 'livrer', 'id' => $id ) ) ) . '">' . self::icone( 'coche' ) . '<span>' . esc_html__( 'Livrer', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '</div></li>';
	}

	/**
	 * Titre d'une étape d'un écran du terrain (« 1 Compter le matériel »…).
	 *
	 * @param int    $n     Numéro.
	 * @param string $titre Titre.
	 * @param string $aide  Aide (facultative).
	 * @return string HTML.
	 */
	private static function pas( $n, $titre, $aide = '' ) {
		return '<h2 class="etr-pas-titre"><span class="etr-pas-titre__n" aria-hidden="true">' . (int) $n . '</span><span>' . esc_html( $titre ) . ( $aide ? '<small>' . esc_html( $aide ) . '</small>' : '' ) . '</span></h2>';
	}

	/**
	 * Mini-suite d'une étape de tournée : Prête → Chargée → Livrée (ou Livrée → Reprise).
	 *
	 * @param int  $id        Demande.
	 * @param bool $livraison Livraison (sinon reprise).
	 * @return string HTML.
	 */
	private static function mini_suite( $id, $livraison ) {
		$e      = ETR_Parcours::etapes( $id );
		$cles   = $livraison ? array( 'preparee', 'chargee', 'livree' ) : array( 'livree', 'reprise' );
		$html   = '<ol class="etr-mini-suite">';
		$vu     = false;
		foreach ( $cles as $cle ) {
			$fait  = $e[ $cle ][1];
			$etat  = $fait ? 'est-fait' : ( $vu ? 'est-a-venir' : 'est-courant' );
			$vu    = $vu || ! $fait;
			$html .= '<li class="' . $etat . '">' . ( $fait ? self::icone( 'coche' ) : '' ) . '<span>' . esc_html( $e[ $cle ][0] ) . '</span></li>';
		}
		return $html . '</ol>';
	}

	/**
	 * Badge de préparation (livraisons) ou d'écarts (reprises).
	 *
	 * @param int  $id        Demande.
	 * @param bool $livraison Livraison.
	 * @return string HTML.
	 */
	private static function badge_preparation( $id, $livraison = true ) {
		if ( ! $livraison ) {
			return '';
		}
		$prepa = ETR_Operations::preparation( $id );
		if ( $prepa['prete'] ) {
			return '<span class="etr-pastille-etat etr-pastille-etat--ok">' . esc_html__( 'Prête', 'espace-traiteur' ) . '</span>';
		}
		if ( $prepa['prepare'] ) {
			/* translators: %s : pourcentage. */
			return '<span class="etr-pastille-etat etr-pastille-etat--cours">' . esc_html( sprintf( __( 'Préparée à %s %%', 'espace-traiteur' ), number_format_i18n( floor( 100 * $prepa['prepare'] / max( 1, $prepa['total'] ) ) ) ) ) . '</span>';
		}
		return '<span class="etr-pastille-etat">' . esc_html__( 'À préparer', 'espace-traiteur' ) . '</span>';
	}

	/* ------------------------------------------------------------------ */
	/* Préparation                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Liste à cocher de la préparation.
	 *
	 * @param int $id Demande.
	 */
	public static function vue_preparer( $id ) {
		if ( ! self::controle( $id, array( 'validee' ) ) ) {
			return;
		}
		$prepa  = ETR_Operations::preparation( $id );
		$lignes = etr_get_lignes( $id );
		list( $jour ) = ETR_Operations::jours( $id );
		/* translators: 1 : traiteur, 2 : date. */
		ETR_Gestion::entete( __( 'Préparation', 'espace-traiteur' ), sprintf( __( '%1$s · livraison le %2$s', 'espace-traiteur' ), ETR_Operations::traiteur( $id ) . ' — ' . get_the_title( $id ), $jour ? etr_format_date( $jour ) : '—' ), '', self::retour_demande( $id ) );

		echo '<div class="etr-prepa" data-etr-preparation="' . (int) $id . '" data-prete="' . ( $prepa['prete'] ? '1' : '0' ) . '">';
		echo '<div class="etr-prepa__progres" aria-live="polite"><div class="etr-prepa__barre"><span style="width:' . esc_attr( (string) floor( 100 * $prepa['prepare'] / max( 1, $prepa['total'] ) ) ) . '%"></span></div>';
		/* translators: 1 : préparés, 2 : total. */
		echo '<p class="etr-prepa__compte"><strong data-prepa-fait>' . esc_html( number_format_i18n( $prepa['prepare'] ) ) . '</strong> / <span data-prepa-total>' . esc_html( number_format_i18n( $prepa['total'] ) ) . '</span> ' . esc_html__( 'articles prêts', 'espace-traiteur' ) . '</p></div>';

		echo self::pas( 1, __( 'Préparer le matériel', 'espace-traiteur' ), __( 'Touchez le rond quand une ligne est complète, ou ajustez la quantité.', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<ul class="etr-prepa__lignes">';
		foreach ( $lignes as $index => $ligne ) {
			$demande = (int) $ligne['quantite'];
			$fait    = $prepa['lignes'][ $index ];
			$article = etr_get_article( $ligne['article_id'] );
			echo '<li class="etr-prepa__ligne' . ( $fait >= $demande ? ' est-complete' : '' ) . '" data-index="' . (int) $index . '" data-demande="' . (int) $demande . '">';
			echo '<button type="button" class="etr-prepa__coche" data-action="tout" aria-pressed="' . ( $fait >= $demande ? 'true' : 'false' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s : article. */ __( 'Tout préparé : %s', 'espace-traiteur' ), $ligne['titre'] ) ) . '">' . self::icone( 'coche' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<span class="etr-prepa__nom">';
			if ( $article && $article['image'] ) {
				echo wp_kses_post( $article['image'] );
			}
			echo '<span><strong>' . esc_html( $ligne['titre'] ) . '</strong>';
			$unite = isset( $ligne['unite'] ) ? trim( (string) $ligne['unite'] ) : '';
			echo '<small>' . esc_html(
				'' !== $unite
					/* translators: 1 : quantité, 2 : unité (pièce(s), lot(s)…). */
					? sprintf( __( 'À préparer : %1$s %2$s', 'espace-traiteur' ), number_format_i18n( $demande ), $unite )
					/* translators: %s : quantité. */
					: sprintf( __( 'À préparer : %s', 'espace-traiteur' ), number_format_i18n( $demande ) )
			) . '</small></span></span>';
			echo '<span class="etr-pas" role="group" aria-label="' . esc_attr__( 'Quantité prête', 'espace-traiteur' ) . '">';
			echo '<button type="button" data-action="moins" aria-label="' . esc_attr__( 'Un de moins', 'espace-traiteur' ) . '">−</button>';
			echo '<input type="number" inputmode="numeric" min="0" max="' . (int) $demande . '" value="' . (int) $fait . '" aria-label="' . esc_attr__( 'Quantité prête', 'espace-traiteur' ) . '">';
			echo '<button type="button" data-action="plus" aria-label="' . esc_attr__( 'Un de plus', 'espace-traiteur' ) . '">+</button>';
			echo '</span></li>';
		}
		echo '</ul>';

		echo self::pas( 2, __( 'Compter les colis', 'espace-traiteur' ), __( 'Une étiquette par colis, à imprimer ensuite.', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="etr-panneau etr-prepa__fin">';
		echo '<label class="etr-prepa__colis"><span><strong>' . esc_html__( 'Nombre de colis', 'espace-traiteur' ) . '</strong><small>' . esc_html__( 'Caisses, chariots, piles de tables… une étiquette par colis.', 'espace-traiteur' ) . '</small></span>';
		echo '<span class="etr-pas"><button type="button" data-action="colis-moins" aria-label="' . esc_attr__( 'Un colis de moins', 'espace-traiteur' ) . '">−</button><input type="number" inputmode="numeric" min="0" max="' . (int) ETR_Operations::MAX_COLIS . '" value="' . (int) $prepa['colis'] . '" data-colis><button type="button" data-action="colis-plus" aria-label="' . esc_attr__( 'Un colis de plus', 'espace-traiteur' ) . '">+</button></span></label>';
		echo '</div>';
		echo self::pas( 3, __( 'Valider : commande prête', 'espace-traiteur' ), __( 'Le livreur est prévenu ; collez les étiquettes sur les colis.', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="etr-barre-action"><p class="etr-prepa__message" data-prepa-message role="status"></p>';
		echo '<div class="etr-prepa__actions">';
		echo '<button type="button" class="etr-btn" data-action="prete"' . ( $prepa['prete'] ? ' hidden' : '' ) . '>' . self::icone( 'coche' ) . '<span>' . esc_html__( 'Commande prête', 'espace-traiteur' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<button type="button" class="etr-btn etr-btn--discret" data-action="rouvrir"' . ( $prepa['prete'] ? '' : ' hidden' ) . '>' . esc_html__( 'Rouvrir la préparation', 'espace-traiteur' ) . '</button>';
		echo '<a class="etr-btn etr-btn--discret" data-etiquettes href="' . esc_url( self::url( array( 'vue' => 'etiquettes', 'id' => $id ) ) ) . '" target="_blank"' . ( $prepa['colis'] ? '' : ' hidden' ) . '>' . self::icone( 'telecharger' ) . '<span>' . esc_html__( 'Étiquettes', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div></div>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Étiquettes                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Planche d'étiquettes QR (A4, 8 étiquettes de 99 × 67 mm), page autonome.
	 *
	 * @param int $id Demande.
	 */
	public static function etiquettes( $id ) {
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$colis = ETR_Operations::preparation( $id )['colis'];
		list( $jour ) = ETR_Operations::jours( $id );
		$lieu  = trim( preg_replace( '/\s+/', ' ', (string) get_post_meta( $id, '_etr_lieu', true ) ) );
		$lieu  = function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $lieu, 0, 70, '…' ) : substr( $lieu, 0, 70 );
		$site  = etr_nom_site();

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( /* translators: %d : numéro. */ __( 'Étiquettes — demande n° %d', 'espace-traiteur' ), $id ) ); ?></title>
<style>
	@page { size: A4; margin: 13mm 6mm; }
	* { box-sizing: border-box; }
	body { margin: 0; background: #f4f1ec; color: #1d1d1b; font: 13px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
	.outils { display: flex; gap: 12px; align-items: center; justify-content: space-between; max-width: 210mm; margin: 0 auto; padding: 16px; }
	.outils a { color: inherit; }
	.outils button { min-height: 44px; padding: 0 22px; border: 0; border-radius: 10px; background: #1d1d1b; color: #fff; font: 600 15px/1 inherit; cursor: pointer; }
	.vide { max-width: 210mm; margin: 0 auto; padding: 40px 16px; text-align: center; }
	.planche { display: grid; grid-template-columns: repeat(2, 99.1mm); grid-auto-rows: 67.7mm; gap: 0 2.5mm; justify-content: center; width: 210mm; margin: 0 auto 24px; padding: 13mm 0; background: #fff; }
	.etiquette { display: grid; grid-template-columns: 1fr 34mm; gap: 3mm; padding: 5mm 5mm 4mm; overflow: hidden; border: 1px dashed #ddd6cc; break-inside: avoid; }
	.etiquette__site { margin: 0 0 1.5mm; font-size: 8.5pt; letter-spacing: .08em; text-transform: uppercase; color: #6b655f; }
	.etiquette__colis { margin: 0; font-size: 22pt; font-weight: 700; line-height: 1; }
	.etiquette__colis small { font-size: 12pt; font-weight: 500; color: #6b655f; }
	.etiquette__traiteur { margin: 2.5mm 0 .5mm; font-size: 11.5pt; font-weight: 700; line-height: 1.2; }
	.etiquette p { margin: 0; }
	.etiquette__detail { font-size: 9pt; color: #3a3833; }
	.etiquette__qr { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1mm; font-size: 7.5pt; color: #6b655f; text-align: center; }
	.etiquette__qr svg { display: block; width: 32mm; height: 32mm; }
	@media print {
		body { background: #fff; }
		.outils { display: none; }
		.planche { margin: 0; padding: 0; break-after: page; }
		.etiquette { border-color: transparent; }
	}
</style>
</head>
<body>
<div class="outils">
	<a href="<?php echo esc_url( self::url( array( 'vue' => 'preparer', 'id' => $id ) ) ); ?>">← <?php esc_html_e( 'Retour à la préparation', 'espace-traiteur' ); ?></a>
	<?php if ( $colis ) : ?>
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimer', 'espace-traiteur' ); ?></button>
	<?php endif; ?>
</div>
		<?php
		if ( ! $colis ) {
			echo '<p class="vide">' . esc_html__( 'Indiquez d’abord le nombre de colis dans la préparation.', 'espace-traiteur' ) . '</p></body></html>';
			exit;
		}
		foreach ( array_chunk( range( 1, $colis ), 8 ) as $page ) {
			echo '<main class="planche">';
			foreach ( $page as $numero ) {
				$qr = self::url( array( 'vue' => 'colis', 'd' => $id, 'c' => $numero ) );
				echo '<section class="etiquette">';
				echo '<div><p class="etiquette__site">' . esc_html( $site ) . '</p>';
				/* translators: 1 : numéro du colis, 2 : total. */
				echo '<p class="etiquette__colis">' . esc_html( sprintf( __( 'Colis %1$s', 'espace-traiteur' ), number_format_i18n( $numero ) ) ) . ' <small>/ ' . esc_html( number_format_i18n( $colis ) ) . '</small></p>';
				echo '<p class="etiquette__traiteur">' . esc_html( ETR_Operations::traiteur( $id ) ) . '</p>';
				echo '<p class="etiquette__detail">' . esc_html( get_the_title( $id ) ) . '</p>';
				if ( $jour ) {
					/* translators: %s : date. */
					echo '<p class="etiquette__detail">' . esc_html( sprintf( __( 'Livraison le %s', 'espace-traiteur' ), etr_format_date( $jour ) ) ) . '</p>';
				}
				if ( $lieu ) {
					echo '<p class="etiquette__detail">' . esc_html( $lieu ) . '</p>';
				}
				echo '</div>';
				/* translators: %d : numéro de demande. */
				echo '<div class="etiquette__qr"><span data-qr="' . esc_attr( $qr ) . '"></span>' . esc_html( sprintf( __( 'Demande n° %d', 'espace-traiteur' ), $id ) ) . '</div>';
				echo '</section>';
			}
			echo '</main>';
		}
		?>
<script src="<?php echo esc_url( ETR_URL . 'assets/vendor/qrcode.min.js?ver=' . ETR_VERSION ); ?>"></script>
<script>
	document.querySelectorAll( '[data-qr]' ).forEach( function ( zone ) {
		var qr = qrcode( 0, 'M' );
		qr.addData( zone.getAttribute( 'data-qr' ) );
		qr.make();
		zone.outerHTML = qr.createSvgTag( { cellSize: 4, margin: 3, scalable: true } );
	} );
</script>
</body>
</html>
		<?php
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Scanner                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Scanner d'étiquettes : chargement ou retour.
	 */
	public static function vue_scanner() {
		// phpcs:disable WordPress.Security.NonceVerification -- simple affichage.
		$mode    = isset( $_GET['mode'] ) && 'retour' === sanitize_key( wp_unslash( $_GET['mode'] ) ) ? 'retour' : 'sortie';
		$demande = isset( $_GET['d'] ) ? absint( $_GET['d'] ) : 0;
		// phpcs:enable
		$retour  = $demande && ETR_Gestion::demande_visible( $demande ) ? self::retour_demande( $demande ) : '';
		ETR_Gestion::entete( 'sortie' === $mode && $retour ? __( 'Charger les colis', 'espace-traiteur' ) : __( 'Scanner', 'espace-traiteur' ), __( 'Visez l’étiquette d’un colis : il est compté aussitôt.', 'espace-traiteur' ), '', $retour );
		if ( $retour ) {
			$bilan = ETR_Operations::bilan_colis( $demande );
			/* translators: 1 : traiteur et événement, 2 : chargés ou revenus, 3 : colis. */
			echo '<p class="etr-scanner__contexte">' . esc_html( sprintf( 'sortie' === $mode ? __( '%1$s : %2$s / %3$s colis chargés', 'espace-traiteur' ) : __( '%1$s : %2$s / %3$s colis revenus', 'espace-traiteur' ), ETR_Operations::traiteur( $demande ) . ' — ' . get_the_title( $demande ), number_format_i18n( 'sortie' === $mode ? $bilan['sortis'] : $bilan['revenus'] ), number_format_i18n( $bilan['colis'] ) ) ) . '</p>';
		}
		echo '<div class="etr-scanner" data-etr-scanner>';
		echo '<div class="etr-segments" role="radiogroup" aria-label="' . esc_attr__( 'Type de scan', 'espace-traiteur' ) . '">';
		echo '<label><input type="radio" name="etr-mode" value="sortie"' . checked( $mode, 'sortie', false ) . '><span>' . esc_html__( 'Chargement', 'espace-traiteur' ) . '</span></label>';
		echo '<label><input type="radio" name="etr-mode" value="retour"' . checked( $mode, 'retour', false ) . '><span>' . esc_html__( 'Retour', 'espace-traiteur' ) . '</span></label>';
		echo '</div>';
		echo '<div class="etr-scanner__camera"><video playsinline muted></video><span class="etr-scanner__viseur" aria-hidden="true"></span>';
		echo '<button type="button" class="etr-btn etr-scanner__demarrer" data-action="camera">' . self::icone( 'scan' ) . '<span>' . esc_html__( 'Ouvrir la caméra', 'espace-traiteur' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="etr-scanner__resultat" data-resultat role="status" aria-live="polite" hidden></div>';
		echo '<details class="etr-scanner__manuel"><summary>' . esc_html__( 'Étiquette abîmée ? Saisir les numéros', 'espace-traiteur' ) . '</summary>';
		echo '<form data-manuel><label>' . esc_html__( 'Demande n°', 'espace-traiteur' ) . '<input type="number" inputmode="numeric" name="d" min="1" required' . ( $retour ? ' value="' . (int) $demande . '"' : '' ) . '></label>';
		echo '<label>' . esc_html__( 'Colis n°', 'espace-traiteur' ) . '<input type="number" inputmode="numeric" name="c" min="1" required></label>';
		echo '<button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Compter', 'espace-traiteur' ) . '</button></form></details>';
		echo '<h2 class="etr-scanner__titre">' . esc_html__( 'Scans de cette session', 'espace-traiteur' ) . '</h2><ul class="etr-scanner__historique" data-historique><li class="etr-muted">' . esc_html__( 'Aucun pour l’instant.', 'espace-traiteur' ) . '</li></ul>';
		echo '</div>';
	}

	/**
	 * Page d'un colis (étiquette lue avec l'appareil photo de l'iPhone, hors application).
	 */
	public static function vue_colis() {
		// phpcs:disable WordPress.Security.NonceVerification -- simple affichage, l'action passe par l'API.
		$id     = isset( $_GET['d'] ) ? absint( $_GET['d'] ) : 0;
		$numero = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0;
		// phpcs:enable
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			echo '<div class="etr-vide"><p>' . esc_html__( 'Étiquette inconnue : la demande n’existe pas ou ne vous est pas visible.', 'espace-traiteur' ) . '</p></div>';
			return;
		}
		$bilan = ETR_Operations::bilan_colis( $id );
		$scan  = ETR_Operations::scans( $id );
		$scan  = isset( $scan[ $numero ] ) ? $scan[ $numero ] : array();
		/* translators: 1 : numéro, 2 : total. */
		ETR_Gestion::entete( sprintf( __( 'Colis %1$s / %2$s', 'espace-traiteur' ), number_format_i18n( $numero ), number_format_i18n( $bilan['colis'] ) ), ETR_Operations::traiteur( $id ) . ' — ' . get_the_title( $id ), '', self::retour_demande( $id ) );
		echo '<section class="etr-panneau etr-colis" data-etr-colis data-d="' . (int) $id . '" data-c="' . (int) $numero . '">';
		echo '<ul class="etr-colis__etats">';
		/* translators: %s : date et heure. */
		echo '<li>' . esc_html( empty( $scan['sortie'] ) ? __( 'Pas encore chargé', 'espace-traiteur' ) : sprintf( __( 'Chargé le %s', 'espace-traiteur' ), wp_date( 'j M, H:i', $scan['sortie'] ) ) ) . '</li>';
		/* translators: %s : date et heure. */
		echo '<li>' . esc_html( empty( $scan['retour'] ) ? __( 'Pas encore revenu', 'espace-traiteur' ) : sprintf( __( 'Revenu le %s', 'espace-traiteur' ), wp_date( 'j M, H:i', $scan['retour'] ) ) ) . '</li>';
		echo '</ul><p class="etr-prepa__message" data-colis-message role="status"></p><div class="etr-prepa__actions">';
		echo '<button type="button" class="etr-btn" data-mode="sortie">' . esc_html__( 'Chargé dans le camion', 'espace-traiteur' ) . '</button>';
		echo '<button type="button" class="etr-btn etr-btn--discret" data-mode="retour">' . esc_html__( 'Revenu à l’entrepôt', 'espace-traiteur' ) . '</button>';
		echo '</div></section>';
	}

	/* ------------------------------------------------------------------ */
	/* Livraison et reprise                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Livraison : signature et photos.
	 *
	 * @param int $id Demande.
	 */
	public static function vue_livrer( $id ) {
		if ( ! self::controle( $id, array( 'validee' ) ) ) {
			return;
		}
		$bilan = ETR_Operations::bilan_colis( $id );
		ETR_Gestion::entete( __( 'Livraison', 'espace-traiteur' ), ETR_Operations::traiteur( $id ) . ' — ' . get_the_title( $id ), '', self::retour_demande( $id ) );

		echo '<form class="etr-terrain-form" data-etr-envoi="livraison" data-id="' . (int) $id . '" novalidate>';
		if ( ! ETR_Operations::preparation( $id )['prete'] ) {
			echo '<p class="etr-alerte">' . self::icone( 'alerte' ) . esc_html__( 'Cette commande n’est pas marquée prête : vérifiez le matériel avant de livrer.', 'espace-traiteur' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo self::pas( 1, __( 'Vérifier le matériel', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::recap_materiel( $id );
		if ( $bilan['colis'] && $bilan['sortis'] < $bilan['colis'] ) {
			/* translators: 1 : colis chargés, 2 : total. */
			echo '<p class="etr-alerte">' . self::icone( 'alerte' ) . esc_html( sprintf( __( 'Seulement %1$s colis sur %2$s ont été scannés au chargement.', 'espace-traiteur' ), number_format_i18n( $bilan['sortis'] ), number_format_i18n( $bilan['colis'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo self::pas( 2, __( 'Faire signer la réception', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<section class="etr-panneau">';
		echo '<p class="etr-champ"><label for="etr-signataire">' . esc_html__( 'Nom de la personne qui réceptionne', 'espace-traiteur' ) . '</label><input type="text" id="etr-signataire" name="signataire" autocomplete="name" required></p>';
		self::zone_signature( __( 'Signature', 'espace-traiteur' ), true );
		echo '</section>';
		echo self::pas( 3, __( 'Photos et remarque', 'espace-traiteur' ), __( 'Facultatif : l’état du matériel à la livraison.', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::zone_photos( __( 'Photos du matériel livré', 'espace-traiteur' ) );
		self::zone_note();
		echo self::pas( 4, __( 'Valider la livraison', 'espace-traiteur' ), __( 'Le traiteur reçoit le bon de livraison signé.', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="etr-barre-action"><p class="etr-prepa__message" data-envoi-message role="status"></p>';
		echo '<button type="submit" class="etr-btn etr-btn--plein">' . self::icone( 'coche' ) . '<span>' . esc_html__( 'Valider la livraison', 'espace-traiteur' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</form>';
	}

	/**
	 * Reprise : quantités rendues et casse.
	 *
	 * @param int $id Demande.
	 */
	public static function vue_reprendre( $id ) {
		if ( ! self::controle( $id, array( 'livree' ) ) ) {
			return;
		}
		$bilan = ETR_Operations::bilan_colis( $id );
		ETR_Gestion::entete( __( 'Reprise', 'espace-traiteur' ), ETR_Operations::traiteur( $id ) . ' — ' . get_the_title( $id ), '', self::retour_demande( $id ) );

		echo '<form class="etr-terrain-form" data-etr-envoi="reprise" data-id="' . (int) $id . '" novalidate>';
		echo self::pas( 1, __( 'Compter le matériel rendu', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<section class="etr-panneau">';
		echo '<p class="etr-muted">' . esc_html__( 'Comptez ce qui revient. Tout est prérempli : corrigez seulement ce qui manque ou est cassé.', 'espace-traiteur' ) . '</p>';
		echo '<ul class="etr-reprise__lignes">';
		foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
			$demande = (int) $ligne['quantite'];
			echo '<li class="etr-reprise__ligne" data-demande="' . (int) $demande . '"><span class="etr-reprise__nom"><strong>' . esc_html( $ligne['titre'] ) . '</strong>';
			/* translators: %s : quantité. */
			echo '<small>' . esc_html( sprintf( __( '%s livré(s)', 'espace-traiteur' ), number_format_i18n( $demande ) ) ) . '</small><small class="etr-reprise__ecart" data-ecart></small></span>';
			echo '<label class="etr-reprise__champ"><span>' . esc_html__( 'Rendus', 'espace-traiteur' ) . '</span><span class="etr-pas"><button type="button" data-action="moins" aria-label="' . esc_attr__( 'Un de moins', 'espace-traiteur' ) . '">−</button><input type="number" inputmode="numeric" name="lignes[' . (int) $index . '][rendu]" min="0" max="' . (int) $demande . '" value="' . (int) $demande . '" data-rendu><button type="button" data-action="plus" aria-label="' . esc_attr__( 'Un de plus', 'espace-traiteur' ) . '">+</button></span></label>';
			echo '<label class="etr-reprise__champ"><span>' . esc_html__( 'Dont cassés', 'espace-traiteur' ) . '</span><span class="etr-pas"><button type="button" data-action="moins" aria-label="' . esc_attr__( 'Un de moins', 'espace-traiteur' ) . '">−</button><input type="number" inputmode="numeric" name="lignes[' . (int) $index . '][casse]" min="0" max="' . (int) $demande . '" value="0" data-casse><button type="button" data-action="plus" aria-label="' . esc_attr__( 'Un de plus', 'espace-traiteur' ) . '">+</button></span></label>';
			echo '</li>';
		}
		echo '</ul>';
		if ( $bilan['colis'] ) {
			/* translators: 1 : colis revenus, 2 : total. */
			echo '<p class="etr-muted">' . esc_html( sprintf( __( 'Colis scannés au retour : %1$s / %2$s.', 'espace-traiteur' ), number_format_i18n( $bilan['revenus'] ), number_format_i18n( $bilan['colis'] ) ) ) . ' <a class="etr-lien" href="' . esc_url( self::url( array( 'vue' => 'scanner', 'mode' => 'retour', 'd' => $id ) ) ) . '">' . esc_html__( 'Scanner les colis revenus', 'espace-traiteur' ) . '</a></p>';
		}
		echo '</section>';
		echo self::pas( 2, __( 'Photos et remarque', 'espace-traiteur' ), __( 'Photographiez la casse : elle sert à la facturer.', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::zone_photos( __( 'Photos (casse, état du matériel)', 'espace-traiteur' ) );
		self::zone_note();
		echo self::pas( 3, __( 'Signature du client', 'espace-traiteur' ), __( 'Facultatif.', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<details class="etr-panneau etr-reprise__signature"><summary>' . esc_html__( 'Faire signer le client (facultatif)', 'espace-traiteur' ) . '</summary>';
		echo '<p class="etr-champ"><label for="etr-signataire">' . esc_html__( 'Nom', 'espace-traiteur' ) . '</label><input type="text" id="etr-signataire" name="signataire" autocomplete="name"></p>';
		self::zone_signature( __( 'Signature', 'espace-traiteur' ), false );
		echo '</details>';
		echo self::pas( 4, __( 'Valider la reprise', 'espace-traiteur' ), __( 'Sans écart, la demande est clôturée ; sinon le bureau est prévenu.', 'espace-traiteur' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="etr-barre-action"><p class="etr-prepa__message" data-envoi-message role="status"></p>';
		echo '<button type="submit" class="etr-btn etr-btn--plein">' . self::icone( 'coche' ) . '<span>' . esc_html__( 'Valider la reprise', 'espace-traiteur' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</form>';
	}

	/**
	 * Rappel du matériel (quantités).
	 *
	 * @param int $id Demande.
	 */
	private static function recap_materiel( $id ) {
		echo '<details class="etr-panneau etr-materiel-recap"><summary>' . esc_html__( 'Matériel à livrer', 'espace-traiteur' ) . '</summary><ul class="etr-materiel-recap__liste">';
		foreach ( etr_get_lignes( $id ) as $ligne ) {
			echo '<li><span>' . esc_html( $ligne['titre'] ) . '</span><strong>' . esc_html( number_format_i18n( (int) $ligne['quantite'] ) ) . '</strong></li>';
		}
		echo '</ul></details>';
	}

	/**
	 * Zone de signature au doigt.
	 *
	 * @param string $libelle Libellé.
	 * @param bool   $requise Obligatoire.
	 */
	private static function zone_signature( $libelle, $requise ) {
		echo '<div class="etr-signature" data-signature' . ( $requise ? ' data-requise' : '' ) . '><div class="etr-signature__tete"><span>' . esc_html( $libelle ) . '</span><button type="button" class="etr-lien" data-action="effacer">' . esc_html__( 'Effacer', 'espace-traiteur' ) . '</button></div>';
		echo '<canvas aria-label="' . esc_attr__( 'Zone de signature : signez avec le doigt', 'espace-traiteur' ) . '"></canvas><p class="etr-signature__aide">' . esc_html__( 'Signez avec le doigt dans le cadre.', 'espace-traiteur' ) . '</p></div>';
	}

	/**
	 * Zone de photos.
	 *
	 * @param string $libelle Libellé.
	 */
	private static function zone_photos( $libelle ) {
		echo '<section class="etr-panneau etr-photos" data-photos><h2 class="etr-panneau__titre">' . esc_html( $libelle ) . '</h2>';
		echo '<label class="etr-btn etr-btn--discret etr-photos__ajout">' . self::icone( 'image' ) . '<span>' . esc_html__( 'Prendre ou ajouter des photos', 'espace-traiteur' ) . '</span><input type="file" accept="image/*" multiple hidden></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		/* translators: %s : nombre. */
		echo '<ul class="etr-photos__liste" data-liste></ul><p class="etr-muted">' . esc_html( sprintf( __( '%s photos au plus, réduites avant l’envoi.', 'espace-traiteur' ), number_format_i18n( ETR_Operations::MAX_PHOTOS ) ) ) . '</p></section>';
	}

	/**
	 * Remarque libre.
	 */
	private static function zone_note() {
		echo '<p class="etr-champ etr-panneau"><label for="etr-note">' . esc_html__( 'Remarque (facultatif)', 'espace-traiteur' ) . '</label><textarea id="etr-note" name="note" rows="3"></textarea></p>';
	}

	/* ------------------------------------------------------------------ */
	/* Incidents et notes internes                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Signaler un incident : type, description, photos.
	 *
	 * @param int $id Demande.
	 */
	public static function vue_incident( $id ) {
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Cette demande n’existe pas ou ne vous est pas visible.', 'espace-traiteur' ) . '</p></div>';
			return;
		}
		ETR_Gestion::entete( __( 'Signaler un incident', 'espace-traiteur' ), ETR_Operations::traiteur( $id ) . ' — ' . get_the_title( $id ), '', self::retour_demande( $id ) );
		echo '<form class="etr-terrain-form" data-etr-envoi="incident" data-id="' . (int) $id . '" novalidate>';
		echo '<section class="etr-panneau"><h2 class="etr-panneau__titre">' . esc_html__( 'Que se passe-t-il ?', 'espace-traiteur' ) . '</h2><div class="etr-choix" role="radiogroup" aria-label="' . esc_attr__( 'Type d’incident', 'espace-traiteur' ) . '">';
		foreach ( ETR_Operations::types_incident() as $cle => $libelle ) {
			echo '<label><input type="radio" name="type" value="' . esc_attr( $cle ) . '"><span>' . esc_html( $libelle ) . '</span></label>';
		}
		echo '</div>';
		echo '<p class="etr-champ"><label for="etr-incident-texte">' . esc_html__( 'Description', 'espace-traiteur' ) . '</label><textarea id="etr-incident-texte" name="texte" rows="4" required placeholder="' . esc_attr__( 'Ex. : 3 assiettes cassées au déchargement, client prévenu.', 'espace-traiteur' ) . '"></textarea></p></section>';
		self::zone_photos( __( 'Photos (facultatif)', 'espace-traiteur' ) );
		echo '<div class="etr-barre-action"><p class="etr-prepa__message" data-envoi-message role="status"></p>';
		echo '<button type="submit" class="etr-btn etr-btn--plein">' . self::icone( 'alerte' ) . '<span>' . esc_html__( 'Envoyer au bureau', 'espace-traiteur' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</form>';
	}

	/**
	 * Incidents d'une demande (les non résolus d'abord) et bouton pour en signaler un.
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_incidents( $id ) {
		$incidents = ETR_Operations::incidents( $id );
		$types     = ETR_Operations::types_incident();
		$ouverts   = ETR_Operations::incidents_ouverts( $id );
		echo '<section class="etr-panneau etr-incidents" id="incidents"><h2 class="etr-panneau__titre">' . esc_html__( 'Incidents', 'espace-traiteur' ) . ( $ouverts ? ' <span class="etr-compteur etr-compteur--alerte">' . esc_html( number_format_i18n( $ouverts ) ) . '</span>' : '' ) . '</h2>';
		if ( $incidents ) {
			echo '<ul class="etr-incidents__liste">';
			uksort(
				$incidents,
				function ( $a, $b ) use ( $incidents ) {
					return ( (int) ! empty( $incidents[ $a ]['resolu'] ) - (int) ! empty( $incidents[ $b ]['resolu'] ) ) ?: $b - $a;
				}
			);
			foreach ( $incidents as $n => $incident ) {
				$resolu = ! empty( $incident['resolu'] );
				echo '<li class="etr-incident' . ( $resolu ? ' est-resolu' : '' ) . '"><p class="etr-incident__tete"><strong>' . esc_html( isset( $types[ $incident['type'] ] ) ? $types[ $incident['type'] ] : $incident['type'] ) . '</strong>';
				/* translators: 1 : personne, 2 : date. */
				echo '<small>' . esc_html( sprintf( __( '%1$s, le %2$s', 'espace-traiteur' ), etr_nom( get_userdata( $incident['par'] ) ), wp_date( 'j M, H:i', $incident['le'] ) ) ) . '</small></p>';
				echo '<p class="etr-incident__texte">' . nl2br( esc_html( $incident['texte'] ) ) . '</p>';
				self::photos_cr( isset( $incident['photos'] ) ? $incident['photos'] : array() );
				if ( $resolu ) {
					/* translators: 1 : personne, 2 : date. */
					echo '<p class="etr-incident__resolu">' . self::icone( 'coche' ) . esc_html( sprintf( __( 'Résolu par %1$s le %2$s', 'espace-traiteur' ), etr_nom( get_userdata( $incident['resolu_par'] ) ), wp_date( 'j M, H:i', $incident['resolu'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				} elseif ( ETR_Equipe::bureau() ) {
					echo '<button type="button" class="etr-btn etr-btn--discret" data-resoudre="' . (int) $n . '" data-id="' . (int) $id . '">' . self::icone( 'coche' ) . '<span>' . esc_html__( 'Marquer résolu', 'espace-traiteur' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="etr-muted">' . esc_html__( 'Aucun incident. En cas de casse, de retard ou d’accès impossible, prévenez le bureau ici.', 'espace-traiteur' ) . '</p>';
		}
		echo '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'incident', 'id' => $id ) ) ) . '" data-hors-ligne>' . self::icone( 'alerte' ) . '<span>' . esc_html__( 'Signaler un incident', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</section>';
	}

	/**
	 * Notes internes de l'équipe (jamais visibles par le traiteur).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_notes( $id ) {
		echo '<section class="etr-panneau etr-notes"><h2 class="etr-panneau__titre">' . esc_html__( 'Notes de l’équipe', 'espace-traiteur' ) . '</h2>';
		echo '<p class="etr-muted">' . esc_html__( 'Visibles seulement par l’équipe, jamais par le traiteur.', 'espace-traiteur' ) . '</p>';
		echo '<ul class="etr-notes__liste" data-notes>';
		foreach ( array_reverse( ETR_Operations::notes( $id ) ) as $note ) {
			echo '<li><p>' . nl2br( esc_html( $note['texte'] ) ) . '</p><small>' . esc_html( etr_nom( get_userdata( $note['par'] ) ) . ' · ' . wp_date( 'j M, H:i', $note['le'] ) ) . '</small></li>';
		}
		echo '</ul>';
		echo '<form class="etr-notes__form" data-etr-note="' . (int) $id . '"><label class="screen-reader-text" for="etr-note-' . (int) $id . '">' . esc_html__( 'Nouvelle note', 'espace-traiteur' ) . '</label>';
		echo '<textarea id="etr-note-' . (int) $id . '" name="texte" rows="2" placeholder="' . esc_attr__( 'Ex. : portail fermé après 18 h, sonner chez le gardien.', 'espace-traiteur' ) . '"></textarea>';
		echo '<button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Ajouter', 'espace-traiteur' ) . '</button></form>';
		echo '</section>';
	}

	/* ------------------------------------------------------------------ */
	/* Suivi détaillé pour le traiteur (espace traiteur)                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Où en est la commande : préparation, en route, livrée, reprise (dates et heures).
	 *
	 * @param int $id Demande.
	 */
	public static function suivi_traiteur( $id ) {
		$statut = etr_get_statut( $id );
		if ( ! in_array( $statut, array( 'validee', 'livree', 'cloturee' ), true ) ) {
			return;
		}
		$prepa     = ETR_Operations::preparation( $id );
		$livraison = ETR_Operations::livraison( $id );
		$reprise   = ETR_Operations::reprise( $id );
		list( $jour_livraison, $jour_reprise ) = ETR_Operations::jours( $id );
		$depart    = 0;
		foreach ( ETR_Operations::scans( $id ) as $scan ) {
			if ( ! empty( $scan['sortie'] ) && ( ! $depart || $scan['sortie'] < $depart ) ) {
				$depart = (int) $scan['sortie'];
			}
		}
		$quand = function ( $horodatage ) {
			return wp_date( 'j F à H:i', $horodatage );
		};
		$etapes = array();
		if ( $prepa['prete'] ) {
			/* translators: %s : date et heure. */
			$etapes[] = array( true, __( 'Matériel préparé', 'espace-traiteur' ), sprintf( __( 'Prêt le %s', 'espace-traiteur' ), $quand( $prepa['le'] ) ) );
		} else {
			$etapes[] = array( false, __( 'Préparation', 'espace-traiteur' ), $prepa['prepare'] ? __( 'En cours dans notre entrepôt', 'espace-traiteur' ) : __( 'À venir', 'espace-traiteur' ) );
		}
		if ( $depart || $livraison ) {
			/* translators: %s : date et heure. */
			$etapes[] = array( true, __( 'En route', 'espace-traiteur' ), $depart ? sprintf( __( 'Chargé le %s', 'espace-traiteur' ), $quand( $depart ) ) : __( 'Parti de l’entrepôt', 'espace-traiteur' ) );
		}
		if ( $livraison ) {
			/* translators: 1 : date et heure, 2 : personne qui a signé. */
			$etapes[] = array( true, __( 'Livré', 'espace-traiteur' ), sprintf( __( 'Le %1$s, réceptionné par %2$s', 'espace-traiteur' ), $quand( $livraison['le'] ), $livraison['signataire'] ) );
		} else {
			/* translators: %s : date. */
			$etapes[] = array( false, __( 'Livraison', 'espace-traiteur' ), $jour_livraison ? sprintf( __( 'Prévue le %s', 'espace-traiteur' ), etr_format_date( $jour_livraison ) ) : __( 'Date à confirmer', 'espace-traiteur' ) );
		}
		if ( $reprise ) {
			$ecarts = ETR_Operations::texte_ecarts( (int) $reprise['manquants'], (int) $reprise['casses'] );
			/* translators: 1 : date et heure, 2 : écarts ou « complet ». */
			$etapes[] = array( true, __( 'Repris', 'espace-traiteur' ), sprintf( __( 'Le %1$s · %2$s', 'espace-traiteur' ), $quand( $reprise['le'] ), $ecarts ? $ecarts : __( 'complet', 'espace-traiteur' ) ) );
		} else {
			/* translators: %s : date. */
			$etapes[] = array( false, __( 'Reprise', 'espace-traiteur' ), $jour_reprise ? sprintf( __( 'Prévue le %s', 'espace-traiteur' ), etr_format_date( $jour_reprise ) ) : __( 'Date à confirmer', 'espace-traiteur' ) );
		}
		echo '<section class="etr-suivi-detail"><h2 class="etr-suivi-detail__titre">' . esc_html__( 'Suivi de votre commande', 'espace-traiteur' ) . '</h2><ol>';
		foreach ( $etapes as $etape ) {
			echo '<li class="' . ( $etape[0] ? 'est-faite' : '' ) . '"><span class="etr-suivi-detail__puce" aria-hidden="true">' . ( $etape[0] ? self::icone( 'coche' ) : '' ) . '</span><span><strong>' . esc_html( $etape[1] ) . '</strong><small>' . esc_html( $etape[2] ) . '</small></span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ol></section>';
	}

	/* ------------------------------------------------------------------ */
	/* Panneaux de la fiche d'une demande                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Suivi : préparation, colis, actions du terrain.
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_suivi( $id ) {
		$statut = etr_get_statut( $id );
		if ( ! in_array( $statut, array( 'validee', 'livree', 'cloturee' ), true ) || ! etr_get_lignes( $id ) ) {
			return;
		}
		$prepa = ETR_Operations::preparation( $id );
		$bilan = ETR_Operations::bilan_colis( $id );
		echo '<section class="etr-panneau etr-operations"><h2 class="etr-panneau__titre">' . esc_html__( 'Suivi', 'espace-traiteur' ) . '</h2><ul class="etr-operations__etapes">';

		$prep_texte = $prepa['prete']
			/* translators: 1 : date, 2 : personne. */
			? sprintf( __( 'Prête le %1$s par %2$s', 'espace-traiteur' ), wp_date( 'j M, H:i', $prepa['le'] ), etr_nom( get_userdata( $prepa['par'] ) ) )
			/* translators: 1 : préparés, 2 : total. */
			: sprintf( __( '%1$s / %2$s articles prêts', 'espace-traiteur' ), number_format_i18n( $prepa['prepare'] ), number_format_i18n( $prepa['total'] ) );
		self::etape( __( 'Préparation', 'espace-traiteur' ), $prep_texte, $prepa['prete'] || 'validee' !== $statut );
		if ( $bilan['colis'] ) {
			/* translators: 1 : colis, 2 : chargés, 3 : revenus. */
			self::etape( __( 'Colis', 'espace-traiteur' ), sprintf( __( '%1$s colis · %2$s chargés · %3$s revenus', 'espace-traiteur' ), number_format_i18n( $bilan['colis'] ), number_format_i18n( $bilan['sortis'] ), number_format_i18n( $bilan['revenus'] ) ), $bilan['sortis'] >= $bilan['colis'] );
		}
		$livraison = ETR_Operations::livraison( $id );
		if ( $livraison ) {
			/* translators: 1 : date, 2 : signataire. */
			self::etape( __( 'Livraison', 'espace-traiteur' ), sprintf( __( 'Le %1$s, signée par %2$s', 'espace-traiteur' ), wp_date( 'j M, H:i', $livraison['le'] ), $livraison['signataire'] ), true );
		}
		$reprise = ETR_Operations::reprise( $id );
		if ( $reprise ) {
			$ecarts = ETR_Operations::texte_ecarts( (int) $reprise['manquants'], (int) $reprise['casses'] );
			/* translators: 1 : date, 2 : écarts ou « complète ». */
			self::etape( __( 'Reprise', 'espace-traiteur' ), sprintf( __( 'Le %1$s · %2$s', 'espace-traiteur' ), wp_date( 'j M, H:i', $reprise['le'] ), $ecarts ? $ecarts : __( 'complète', 'espace-traiteur' ) ), ! $ecarts );
		}
		echo '</ul><div class="etr-operations__actions">';
		if ( 'validee' === $statut && ETR_Equipe::preparateur() ) {
			echo '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'preparer', 'id' => $id ) ) ) . '">' . self::icone( 'coche' ) . '<span>' . esc_html( $prepa['prete'] ? __( 'Voir la préparation', 'espace-traiteur' ) : __( 'Préparer', 'espace-traiteur' ) ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $bilan['colis'] ) {
			echo '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'etiquettes', 'id' => $id ) ) ) . '" target="_blank">' . self::icone( 'telecharger' ) . '<span>' . esc_html__( 'Étiquettes', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( 'validee' === $statut && ETR_Equipe::livreur() ) {
			echo '<a class="etr-btn" href="' . esc_url( self::url( array( 'vue' => 'livrer', 'id' => $id ) ) ) . '">' . self::icone( 'camion' ) . '<span>' . esc_html__( 'Livrer', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( 'livree' === $statut && ETR_Equipe::livreur() ) {
			echo '<a class="etr-btn" href="' . esc_url( self::url( array( 'vue' => 'reprendre', 'id' => $id ) ) ) . '">' . self::icone( 'reprise' ) . '<span>' . esc_html( $reprise ? __( 'Refaire la reprise', 'espace-traiteur' ) : __( 'Reprendre le matériel', 'espace-traiteur' ) ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div></section>';
	}

	/**
	 * Étape du suivi.
	 *
	 * @param string $titre Titre.
	 * @param string $texte Texte.
	 * @param bool   $ok    Terminée.
	 */
	private static function etape( $titre, $texte, $ok ) {
		echo '<li class="etr-operations__etape' . ( $ok ? ' est-ok' : '' ) . '"><span class="etr-operations__puce" aria-hidden="true">' . ( $ok ? self::icone( 'coche' ) : '' ) . '</span><span><strong>' . esc_html( $titre ) . '</strong><small>' . esc_html( $texte ) . '</small></span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Comptes rendus de livraison et de reprise (signature, photos, écarts).
	 *
	 * @param int $id Demande.
	 */
	public static function panneaux_comptes_rendus( $id ) {
		$livraison = ETR_Operations::livraison( $id );
		if ( $livraison ) {
			echo '<section class="etr-panneau etr-cr"><h2 class="etr-panneau__titre">' . esc_html__( 'Livraison', 'espace-traiteur' ) . '</h2>';
			/* translators: 1 : date, 2 : livreur, 3 : signataire. */
			echo '<p>' . esc_html( sprintf( __( 'Livrée le %1$s par %2$s. Réceptionnée par %3$s.', 'espace-traiteur' ), wp_date( 'j F Y, H:i', $livraison['le'] ), etr_nom( get_userdata( $livraison['par'] ) ), $livraison['signataire'] ) ) . '</p>';
			if ( ! empty( $livraison['signature'] ) ) {
				echo '<figure class="etr-cr__signature"><img src="' . esc_url( ETR_Fichiers::url( $livraison['signature'] ) ) . '" alt="' . esc_attr__( 'Signature', 'espace-traiteur' ) . '"><figcaption>' . esc_html( $livraison['signataire'] ) . '</figcaption></figure>';
			}
			self::photos_cr( isset( $livraison['photos'] ) ? $livraison['photos'] : array() );
			if ( ! empty( $livraison['note'] ) ) {
				echo '<p class="etr-cr__note">' . nl2br( esc_html( $livraison['note'] ) ) . '</p>';
			}
			if ( ETR_Modules::actif( 'bon_livraison' ) ) {
				echo '<a class="etr-btn etr-btn--discret" href="' . esc_url( ETR_Bon_Livraison::url( $id ) ) . '" target="_blank">' . self::icone( 'telecharger' ) . '<span>' . esc_html__( 'Bon de livraison signé (PDF)', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</section>';
		}
		$reprise = ETR_Operations::reprise( $id );
		if ( $reprise ) {
			$ecarts = ETR_Operations::texte_ecarts( (int) $reprise['manquants'], (int) $reprise['casses'] );
			echo '<section class="etr-panneau etr-cr"><h2 class="etr-panneau__titre">' . esc_html__( 'Reprise', 'espace-traiteur' ) . '</h2>';
			/* translators: 1 : date, 2 : livreur. */
			echo '<p>' . esc_html( sprintf( __( 'Reprise le %1$s par %2$s.', 'espace-traiteur' ), wp_date( 'j F Y, H:i', $reprise['le'] ), etr_nom( get_userdata( (int) ( $reprise['par'] ?? 0 ) ) ) ) ) . ' <strong class="' . ( $ecarts ? 'etr-manque' : 'etr-ok' ) . '">' . esc_html( $ecarts ? $ecarts : __( 'Complète, en bon état.', 'espace-traiteur' ) ) . '</strong></p>';
			if ( $ecarts ) {
				echo '<div class="etr-table-cadre etr-cr__ecarts" tabindex="0" role="region" aria-label="' . esc_attr__( 'Écarts à la reprise', 'espace-traiteur' ) . '"><table class="etr-table"><thead><tr><th>' . esc_html__( 'Article', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Livrés', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Rendus', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Manquants', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Cassés', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
				foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
					$saisie = isset( $reprise['lignes'][ $index ] ) ? $reprise['lignes'][ $index ] : array( 'rendu' => (int) $ligne['quantite'], 'casse' => 0 );
					$manque = (int) $ligne['quantite'] - (int) $saisie['rendu'];
					if ( ! $manque && ! $saisie['casse'] ) {
						continue;
					}
					echo '<tr><td>' . esc_html( $ligne['titre'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $ligne['quantite'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $saisie['rendu'] ) ) . '</td><td>' . esc_html( number_format_i18n( $manque ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $saisie['casse'] ) ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
				if ( ETR_Equipe::bureau() && 'livree' === etr_get_statut( $id ) ) {
					echo '<p class="etr-muted">' . esc_html__( 'Une fois les écarts réglés avec le traiteur, passez la demande en « Clôturée ».', 'espace-traiteur' ) . '</p>';
				}
			}
			if ( ! empty( $reprise['signature'] ) ) {
				echo '<figure class="etr-cr__signature"><img src="' . esc_url( ETR_Fichiers::url( $reprise['signature'] ) ) . '" alt="' . esc_attr__( 'Signature', 'espace-traiteur' ) . '"><figcaption>' . esc_html( $reprise['signataire'] ) . '</figcaption></figure>';
			}
			self::photos_cr( isset( $reprise['photos'] ) ? $reprise['photos'] : array() );
			if ( ! empty( $reprise['note'] ) ) {
				echo '<p class="etr-cr__note">' . nl2br( esc_html( $reprise['note'] ) ) . '</p>';
			}
			echo '</section>';
		}
	}

	/**
	 * Vignettes de photos.
	 *
	 * @param string[] $photos Noms.
	 */
	private static function photos_cr( $photos ) {
		if ( ! $photos ) {
			return;
		}
		echo '<ul class="etr-cr__photos">';
		foreach ( $photos as $nom ) {
			$url = ETR_Fichiers::url( $nom );
			echo '<li><a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Photo', 'espace-traiteur' ) . '" loading="lazy"></a></li>';
		}
		echo '</ul>';
	}
}
