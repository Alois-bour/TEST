<?php
/**
 * Matériel et planning (fonctions activables) :
 * - déplacer une livraison ou une reprise (« deplacer_dates ») ;
 * - matériel en réparation (« reparation ») : la casse sort du stock jusqu'à remise en stock.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions « matériel et planning ».
 */
class ETR_Materiel {

	const OPTION_REPARATION = 'etr_hors_service';
	const META_CASSE_STOCK  = '_etr_casse_en_reparation';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'etr_planning_mouvement_attributs', array( __CLASS__, 'attributs_mouvement' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'etr_gestion_fiche_cote', array( __CLASS__, 'panneau_dates' ), 5 );
		add_action( 'etr_gestion_action', array( __CLASS__, 'actions' ) );

		add_filter( 'etr_stock_total', array( __CLASS__, 'stock_disponible' ), 10, 2 );
		add_action( 'etr_reprise_enregistree', array( __CLASS__, 'casse_en_reparation' ), 10, 2 );
		add_action( 'etr_gestion_catalogue', array( __CLASS__, 'panneau_reparation' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Déplacer une livraison ou une reprise                              */
	/* ------------------------------------------------------------------ */

	/**
	 * L'étape peut-elle encore être déplacée ?
	 *
	 * @param int    $id    Demande.
	 * @param string $etape livraison | reprise.
	 * @return bool
	 */
	public static function deplacable( $id, $etape ) {
		$statut = etr_get_statut( $id );
		if ( 'livraison' === $etape ) {
			return in_array( $statut, array( 'en_attente', 'validee' ), true ) && ! ETR_Operations::livraison( $id );
		}
		return in_array( $statut, array( 'en_attente', 'validee', 'livree' ), true ) && ! ETR_Operations::reprise( $id );
	}

	/**
	 * Planning : livraisons et reprises glissables (bureau, ordinateur).
	 *
	 * @param string $attrs Attributs.
	 * @param array  $mvt   { type, id }.
	 * @return string
	 */
	public static function attributs_mouvement( $attrs, $mvt ) {
		if ( ! ETR_Modules::actif( 'deplacer_dates' ) || ! ETR_Equipe::bureau() || ! in_array( $mvt['type'], array( 'livraison', 'reprise' ), true ) || ! self::deplacable( $mvt['id'], $mvt['type'] ) ) {
			return $attrs;
		}
		return $attrs . ' draggable="true" data-deplacer-id="' . (int) $mvt['id'] . '" data-deplacer-etape="' . esc_attr( $mvt['type'] ) . '"';
	}

	/**
	 * Routes : POST terrain/{id}/dates (bureau).
	 */
	public static function routes() {
		if ( ! ETR_Modules::actif( 'deplacer_dates' ) ) {
			return;
		}
		register_rest_route(
			ETR_Push::API,
			'/terrain/(?P<id>\d+)/dates',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'api_dates' ),
				'permission_callback' => function () {
					return ETR_Operations::autorise( 'bureau' );
				},
			)
		);
	}

	/**
	 * { etape, date }.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_dates( $requete ) {
		$id    = (int) $requete['id'];
		$etape = 'reprise' === $requete->get_param( 'etape' ) ? 'reprise' : 'livraison';
		$ok    = self::deplacer( $id, $etape, sanitize_text_field( (string) $requete->get_param( 'date' ) ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return rest_ensure_response( array( 'message' => $ok ) );
	}

	/**
	 * Change la date d'une livraison ou d'une reprise.
	 *
	 * @param int    $id    Demande.
	 * @param string $etape livraison | reprise.
	 * @param string $date  AAAA-MM-JJ.
	 * @return string|WP_Error Message de confirmation.
	 */
	public static function deplacer( $id, $etape, $date ) {
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			return new WP_Error( 'etr_demande', __( 'Demande introuvable.', 'espace-traiteur' ), array( 'status' => 404 ) );
		}
		if ( ! self::deplacable( $id, $etape ) ) {
			return new WP_Error( 'etr_statut', __( 'Cette étape est déjà faite ou la demande est terminée.', 'espace-traiteur' ), array( 'status' => 409 ) );
		}
		if ( ! etr_date_valide( $date ) ) {
			return new WP_Error( 'etr_date', __( 'Date invalide.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}
		$evenement = (string) get_post_meta( $id, '_etr_date_evenement', true );
		$livraison = 'livraison' === $etape ? $date : (string) get_post_meta( $id, '_etr_date_livraison', true );
		$reprise   = 'reprise' === $etape ? $date : (string) get_post_meta( $id, '_etr_date_reprise', true );
		if ( etr_date_valide( $evenement ) && ( ( 'livraison' === $etape && $date > $evenement ) || ( 'reprise' === $etape && $date < $evenement ) ) ) {
			return new WP_Error( 'etr_date', 'livraison' === $etape ? __( 'La livraison doit avoir lieu au plus tard le jour de l’événement.', 'espace-traiteur' ) : __( 'La reprise doit avoir lieu au plus tôt le jour de l’événement.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}
		if ( etr_date_valide( $livraison ) && etr_date_valide( $reprise ) && $livraison > $reprise ) {
			return new WP_Error( 'etr_date', __( 'La reprise ne peut pas précéder la livraison.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}

		// Le matériel doit rester disponible sur la nouvelle période.
		list( $debut, $fin ) = etr_periode( $evenement, $livraison, $reprise );
		if ( $debut && in_array( etr_get_statut( $id ), etr_statuts_reservant(), true ) ) {
			$reservees = etr_quantites_reservees( $debut, $fin, $id );
			$manques   = array();
			foreach ( etr_get_lignes( $id ) as $ligne ) {
				$dispo = etr_disponibilite( (int) $ligne['article_id'], $reservees );
				if ( null !== $dispo && (int) $ligne['quantite'] > $dispo ) {
					/* translators: 1 : article, 2 : disponible. */
					$manques[] = sprintf( __( '%1$s (%2$s disponibles)', 'espace-traiteur' ), $ligne['titre'], number_format_i18n( $dispo ) );
				}
			}
			if ( $manques ) {
				/* translators: %s : articles. */
				return new WP_Error( 'etr_stock', sprintf( __( 'Matériel insuffisant sur la nouvelle période : %s.', 'espace-traiteur' ), implode( ', ', $manques ) ), array( 'status' => 409 ) );
			}
		}

		$ancienne = ETR_Operations::jours( $id );
		update_post_meta( $id, '_etr_date_' . $etape, $date );
		$texte = sprintf(
			/* translators: 1 : ancienne date, 2 : nouvelle date. */
			'livraison' === $etape ? __( 'Livraison déplacée du %1$s au %2$s', 'espace-traiteur' ) : __( 'Reprise déplacée du %1$s au %2$s', 'espace-traiteur' ),
			etr_format_date( 'livraison' === $etape ? $ancienne[0] : $ancienne[1] ),
			etr_format_date( $date )
		);
		ETR_Historique::ajouter( $id, 'dates', $texte );
		ETR_Traiteur_Direct::prevenir(
			$id,
			'livraison' === $etape ? __( 'Date de livraison modifiée', 'espace-traiteur' ) : __( 'Date de reprise modifiée', 'espace-traiteur' ),
			/* translators: 1 : événement, 2 : changement. */
			sprintf( __( '%1$s : %2$s.', 'espace-traiteur' ), get_the_title( $id ), $texte )
		);
		return $texte;
	}

	/**
	 * Panneau « Dates » de la fiche (bureau) : sur téléphone, à la place du glisser.
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_dates( $id ) {
		if ( ! ETR_Modules::actif( 'deplacer_dates' ) ) {
			return;
		}
		$etapes = array_filter(
			array( 'livraison', 'reprise' ),
			function ( $etape ) use ( $id ) {
				return self::deplacable( $id, $etape );
			}
		);
		if ( ! $etapes ) {
			return;
		}
		list( $livraison, $reprise ) = ETR_Operations::jours( $id );
		$valeurs = array(
			'livraison' => array( __( 'Livraison', 'espace-traiteur' ), $livraison ),
			'reprise'   => array( __( 'Reprise', 'espace-traiteur' ), $reprise ),
		);
		echo '<form class="etr-panneau etr-dates-form" method="post" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) ) . '"><h2 class="etr-panneau__titre">' . esc_html__( 'Changer les dates', 'espace-traiteur' ) . '</h2>';
		wp_nonce_field( 'etr_dates_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="dates"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
		foreach ( $etapes as $etape ) {
			echo '<p class="etr-champ"><label for="etr-date-' . esc_attr( $etape ) . '">' . esc_html( $valeurs[ $etape ][0] ) . '</label><input type="date" id="etr-date-' . esc_attr( $etape ) . '" name="etr_dates[' . esc_attr( $etape ) . ']" value="' . esc_attr( $valeurs[ $etape ][1] ) . '"></p>';
		}
		echo '<p class="etr-muted">' . esc_html__( 'Le stock est vérifié sur la nouvelle période ; le traiteur est prévenu.', 'espace-traiteur' ) . '</p>';
		echo '<button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Enregistrer les dates', 'espace-traiteur' ) . '</button></form>';
	}

	/**
	 * Actions du bureau : dates, remise en stock.
	 *
	 * @param string $action Action.
	 */
	public static function actions( $action ) {
		if ( 'dates' === $action && ETR_Modules::actif( 'deplacer_dates' ) ) {
			$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
			check_admin_referer( 'etr_dates_' . $id, 'etr_nonce' );
			$saisies = isset( $_POST['etr_dates'] ) && is_array( $_POST['etr_dates'] ) ? wp_unslash( $_POST['etr_dates'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- chaque valeur est nettoyée ci-dessous.
			list( $livraison, $reprise ) = ETR_Operations::jours( $id );
			$actuelles = array( 'livraison' => $livraison, 'reprise' => $reprise );
			$erreur    = '';
			// Reprise d'abord si elle recule, sinon livraison d'abord : chaque étape reste cohérente.
			$ordre = ( isset( $saisies['livraison'] ) && sanitize_text_field( $saisies['livraison'] ) > $actuelles['livraison'] ) ? array( 'reprise', 'livraison' ) : array( 'livraison', 'reprise' );
			foreach ( $ordre as $etape ) {
				$date = isset( $saisies[ $etape ] ) ? sanitize_text_field( $saisies[ $etape ] ) : '';
				if ( '' === $date || $date === $actuelles[ $etape ] ) {
					continue;
				}
				$ok = self::deplacer( $id, $etape, $date );
				if ( is_wp_error( $ok ) ) {
					$erreur = $ok->get_error_message();
					break;
				}
			}
			if ( $erreur ) {
				set_transient( 'etr_erreur_dates_' . get_current_user_id(), $erreur, 60 );
			}
			wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => $erreur ? 'dates_erreur' : 'dates' ) ) );
			exit;
		}
		if ( 'reparation' === $action && ETR_Modules::actif( 'reparation' ) ) {
			check_admin_referer( 'etr_reparation', 'etr_nonce' );
			$article  = isset( $_POST['article'] ) ? absint( $_POST['article'] ) : 0;
			$quantite = isset( $_POST['quantite'] ) ? absint( $_POST['quantite'] ) : 0;
			$sens     = isset( $_POST['sens'] ) && 'sortir' === $_POST['sens'] ? 1 : -1;
			if ( $article && $quantite && etr_get_article( $article ) ) {
				self::modifier_reparation( $article, $sens * $quantite );
			}
			wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'catalogue', 'etr_msg' => 'fait' ) ) );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Matériel en réparation                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Quantités en réparation : article => quantité.
	 *
	 * @return array<int,int>
	 */
	public static function en_reparation() {
		$liste = get_option( self::OPTION_REPARATION, array() );
		return is_array( $liste ) ? array_filter( array_map( 'intval', $liste ) ) : array();
	}

	/**
	 * Ajoute (ou retire, si négatif) du matériel en réparation.
	 *
	 * @param int $article  Article.
	 * @param int $quantite Quantité.
	 */
	public static function modifier_reparation( $article, $quantite ) {
		$liste             = self::en_reparation();
		$liste[ $article ] = max( 0, ( isset( $liste[ $article ] ) ? $liste[ $article ] : 0 ) + (int) $quantite );
		$stock             = etr_stock_brut( $article );
		if ( null !== $stock ) {
			$liste[ $article ] = min( $stock, $liste[ $article ] );
		}
		update_option( self::OPTION_REPARATION, array_filter( $liste ), false );
	}

	/**
	 * Stock utilisable : possédé moins en réparation.
	 *
	 * @param int $stock      Stock possédé.
	 * @param int $article_id Article.
	 * @return int
	 */
	public static function stock_disponible( $stock, $article_id ) {
		if ( ! ETR_Modules::actif( 'reparation' ) ) {
			return $stock;
		}
		$liste = self::en_reparation();
		return isset( $liste[ $article_id ] ) ? $stock - $liste[ $article_id ] : $stock;
	}

	/**
	 * Reprise : la casse part en réparation (une reprise refaite ne compte que la différence).
	 *
	 * @param int   $id     Demande.
	 * @param array $lignes index => { rendu, casse }.
	 */
	public static function casse_en_reparation( $id, $lignes ) {
		if ( ! ETR_Modules::actif( 'reparation' ) ) {
			return;
		}
		$deja    = get_post_meta( $id, self::META_CASSE_STOCK, true );
		$deja    = is_array( $deja ) ? $deja : array();
		$nouveau = array();
		foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
			$casse = isset( $lignes[ $index ]['casse'] ) ? (int) $lignes[ $index ]['casse'] : 0;
			if ( $casse > 0 ) {
				$article             = (int) $ligne['article_id'];
				$nouveau[ $article ] = ( isset( $nouveau[ $article ] ) ? $nouveau[ $article ] : 0 ) + $casse;
			}
		}
		foreach ( array_unique( array_merge( array_keys( $deja ), array_keys( $nouveau ) ) ) as $article ) {
			$ecart = ( isset( $nouveau[ $article ] ) ? $nouveau[ $article ] : 0 ) - ( isset( $deja[ $article ] ) ? $deja[ $article ] : 0 );
			if ( $ecart ) {
				self::modifier_reparation( $article, $ecart );
			}
		}
		update_post_meta( $id, self::META_CASSE_STOCK, $nouveau );
	}

	/**
	 * Catalogue : matériel en réparation, remise en stock et sortie manuelle (bureau).
	 */
	public static function panneau_reparation() {
		if ( ! ETR_Modules::actif( 'reparation' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		$liste = self::en_reparation();
		echo '<section class="etr-panneau etr-reparation"><h2 class="etr-panneau__titre">' . esc_html__( 'Matériel en réparation', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( array_sum( $liste ) ) ) . '</span></h2>';
		if ( ! $liste ) {
			echo '<p class="etr-muted">' . esc_html__( 'Rien en réparation. La casse constatée aux reprises arrive ici et sort du stock disponible.', 'espace-traiteur' ) . '</p>';
		} else {
			echo '<ul class="etr-reparation__liste">';
			foreach ( $liste as $article_id => $quantite ) {
				$article = etr_get_article( $article_id );
				echo '<li><span><strong>' . esc_html( $article ? $article['titre'] : '#' . $article_id ) . '</strong> · ' . esc_html( sprintf( /* translators: %s : quantité. */ _n( '%s en réparation', '%s en réparation', $quantite, 'espace-traiteur' ), number_format_i18n( $quantite ) ) ) . '</span>';
				echo '<form method="post" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'catalogue' ) ) ) . '">';
				wp_nonce_field( 'etr_reparation', 'etr_nonce' );
				echo '<input type="hidden" name="etr_gestion_action" value="reparation"><input type="hidden" name="sens" value="remettre"><input type="hidden" name="article" value="' . (int) $article_id . '">';
				echo '<label class="screen-reader-text" for="etr-rep-' . (int) $article_id . '">' . esc_html__( 'Quantité', 'espace-traiteur' ) . '</label><input type="number" id="etr-rep-' . (int) $article_id . '" name="quantite" min="1" max="' . (int) $quantite . '" value="' . (int) $quantite . '" inputmode="numeric">';
				echo '<button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Remis en stock', 'espace-traiteur' ) . '</button></form></li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}
}
