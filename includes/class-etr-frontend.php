<?php
/**
 * Espace traiteur côté site : shortcode [espace_traiteur].
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Espace privé du traiteur.
 */
class ETR_Frontend {

	/**
	 * Erreurs de validation du formulaire en cours.
	 *
	 * @var string[]
	 */
	private static $erreurs = array();

	/**
	 * Demande ouverte en modification.
	 *
	 * @var int
	 */
	private static $demande_modifiee_id = 0;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'espace_traiteur', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'traiter_formulaires' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_etr_disponibilites', array( __CLASS__, 'ajax_disponibilites' ) );
		add_filter( 'body_class', array( __CLASS__, 'classe_body' ) );
		add_filter( 'hello_elementor_page_title', array( __CLASS__, 'titre_theme' ) );
		add_filter( 'template_include', array( __CLASS__, 'gabarit_plein_ecran' ), 99 );
	}

	/**
	 * Connexion et espace connecté en plein écran : page autonome, sans l'en-tête
	 * ni le pied de page du thème.
	 *
	 * @param string $gabarit Gabarit prévu par le thème.
	 * @return string
	 */
	public static function gabarit_plein_ecran( $gabarit ) {
		return self::est_plein_ecran() ? ETR_DIR . 'templates/connexion.php' : $gabarit;
	}

	/**
	 * La page courante s'affiche-t-elle en plein écran ?
	 *
	 * @return bool
	 */
	private static function est_plein_ecran() {
		if ( ! self::sur_page_espace() ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			return (bool) get_option( 'etr_connexion_plein_ecran', '1' );
		}
		return etr_est_traiteur() && self::plein_ecran();
	}

	/**
	 * Sommes-nous sur la page de l'espace traiteur ?
	 *
	 * @return bool
	 */
	private static function sur_page_espace() {
		return is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'espace_traiteur' );
	}

	/**
	 * Classe sur <body> pour l'écran de connexion (permet de masquer le titre du thème).
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function classe_body( $classes ) {
		if ( ! self::sur_page_espace() ) {
			return $classes;
		}
		if ( ! is_user_logged_in() ) {
			$classes[] = 'etr-page-connexion';
		} elseif ( etr_est_traiteur() ) {
			$classes[] = 'etr-page-application';
		}
		if ( self::est_plein_ecran() ) {
			$classes[] = 'etr-plein-ecran';
		}
		return $classes;
	}

	/**
	 * Thème Hello Elementor : pas de titre de page au-dessus de la connexion ni de l'application
	 * (chaque écran a son propre titre).
	 *
	 * @param bool $afficher Valeur par défaut.
	 * @return bool
	 */
	public static function titre_theme( $afficher ) {
		return ( self::sur_page_espace() && ( ! is_user_logged_in() || etr_est_traiteur() ) ) ? false : $afficher;
	}

	/**
	 * Styles et script sur la page contenant le shortcode.
	 */
	public static function assets() {
		if ( ! self::sur_page_espace() ) {
			return;
		}
		wp_enqueue_style( 'espace-traiteur', ETR_URL . 'assets/css/espace-traiteur.css', array(), ETR_VERSION );

		if ( ! is_user_logged_in() ) {
			wp_enqueue_script( 'espace-traiteur-connexion', ETR_URL . 'assets/js/connexion.js', array(), ETR_VERSION, true );
		}

		if ( etr_est_traiteur() ) {
			wp_enqueue_script( 'espace-traiteur', ETR_URL . 'assets/js/espace-traiteur.js', array(), ETR_VERSION, true );
			wp_localize_script(
				'espace-traiteur',
				'etrDispo',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'etr_disponibilites' ),
					'i18n'    => array(
						'chargement' => __( 'Calcul des disponibilités…', 'espace-traiteur' ),
						'erreur'     => __( 'Impossible de calculer les disponibilités pour le moment.', 'espace-traiteur' ),
						'sansDate'   => __( 'Choisissez la date de l’événement pour voir les quantités disponibles.', 'espace-traiteur' ),
						'aucun'      => __( 'Aucun article pour l’instant.', 'espace-traiteur' ),
						/* translators: %s : nombre d'articles. */
						'unArticle'  => __( '%s article sélectionné', 'espace-traiteur' ),
						/* translators: %s : nombre d'articles. */
						'articles'   => __( '%s articles sélectionnés', 'espace-traiteur' ),
						'retirer'    => __( 'Retirer', 'espace-traiteur' ),
						'moins'      => __( 'Diminuer la quantité', 'espace-traiteur' ),
						'plus'       => __( 'Augmenter la quantité', 'espace-traiteur' ),
					),
				)
			);
		}
	}

	/**
	 * AJAX : disponibilités de tout le catalogue pour les dates saisies.
	 */
	public static function ajax_disponibilites() {
		check_ajax_referer( 'etr_disponibilites', 'nonce' );
		if ( ! etr_est_traiteur() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'espace-traiteur' ) ), 403 );
		}

		$dates = array();
		foreach ( array( 'date_evenement', 'date_livraison', 'date_reprise' ) as $cle ) {
			$valeur        = isset( $_POST[ $cle ] ) ? sanitize_text_field( wp_unslash( $_POST[ $cle ] ) ) : '';
			$dates[ $cle ] = etr_date_valide( $valeur ) ? $valeur : '';
		}
		$demande_id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		if ( $demande_id ) {
			$demande = get_post( $demande_id );
			if ( ! $demande || (int) $demande->post_author !== get_current_user_id() || 'en_attente' !== etr_get_statut( $demande_id ) ) {
				$demande_id = 0;
			}
		}
		list( $debut, $fin ) = etr_periode( $dates['date_evenement'], $dates['date_livraison'], $dates['date_reprise'] );
		if ( ! $debut ) {
			wp_send_json_error( array( 'message' => __( 'Choisissez la date de l’événement pour voir les quantités disponibles.', 'espace-traiteur' ) ) );
		}

		wp_send_json_success(
			array(
				'periode'  => self::texte_periode( $debut, $fin ),
				'articles' => self::disponibilites_catalogue( etr_quantites_reservees( $debut, $fin, $demande_id ) ),
			)
		);
	}

	/**
	 * Disponibilité de chaque article publié.
	 *
	 * @param array $reservees Quantités réservées par article.
	 * @return array<int,array{dispo:int|null,texte:string}>
	 */
	private static function disponibilites_catalogue( $reservees ) {
		$resultat = array();
		$ids      = wp_list_pluck( etr_catalogue(), 'id' );
		foreach ( $ids as $id ) {
			$dispo           = etr_disponibilite( $id, $reservees );
			$resultat[ $id ] = array(
				'dispo' => $dispo,
				'texte' => etr_texte_disponibilite( $id, $dispo ),
			);
		}
		return $resultat;
	}

	/**
	 * Phrase décrivant la période de calcul.
	 *
	 * @param string $debut Début.
	 * @param string $fin   Fin.
	 * @return string
	 */
	private static function texte_periode( $debut, $fin ) {
		if ( $debut === $fin ) {
			/* translators: %s : date. */
			return sprintf( __( 'Disponibilités pour le %s.', 'espace-traiteur' ), etr_format_date( $debut ) );
		}
		/* translators: 1 : date de début, 2 : date de fin. */
		return sprintf( __( 'Disponibilités du %1$s au %2$s (livraison → reprise).', 'espace-traiteur' ), etr_format_date( $debut ), etr_format_date( $fin ) );
	}

	/* ------------------------------------------------------------------ */
	/* Traitement des formulaires                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Aiguillage des formulaires envoyés.
	 */
	public static function traiter_formulaires() {
		if ( empty( $_POST['etr_action'] ) || ! etr_est_traiteur() ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['etr_action'] ) );
		if ( 'nouvelle_demande' === $action ) {
			self::creer_demande();
		} elseif ( 'modifier_demande' === $action ) {
			self::modifier_demande();
		} elseif ( 'annuler_demande' === $action ) {
			self::annuler_demande();
		}
	}

	/**
	 * Modification d'une demande tant qu'elle est en attente.
	 */
	private static function modifier_demande() {
		$demande_id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		if ( ! $demande_id || ! isset( $_POST['etr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_nonce'] ) ), 'etr_modifier_' . $demande_id ) ) {
			self::$erreurs[] = __( 'Votre session a expiré, merci de renvoyer le formulaire.', 'espace-traiteur' );
			return;
		}
		$demande = get_post( $demande_id );
		if ( ! $demande || 'etr_demande' !== $demande->post_type || (int) $demande->post_author !== get_current_user_id() || 'en_attente' !== etr_get_statut( $demande_id ) ) {
			self::$erreurs[] = __( 'Cette demande ne peut plus être modifiée.', 'espace-traiteur' );
			return;
		}

		$saisie  = isset( $_POST['etr'] ) && is_array( $_POST['etr'] ) ? wp_unslash( $_POST['etr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$donnees = array();
		foreach ( etr_champs_demande() as $cle => $champ ) {
			$brut   = isset( $saisie[ $cle ] ) && is_string( $saisie[ $cle ] ) ? $saisie[ $cle ] : '';
			$valeur = 'textarea' === $champ['type'] ? sanitize_textarea_field( $brut ) : sanitize_text_field( $brut );
			if ( 'number' === $champ['type'] && '' !== $valeur ) {
				$valeur = (string) absint( $valeur );
			}
			if ( 'date' === $champ['type'] && '' !== $valeur && ! etr_date_valide( $valeur ) ) {
				self::$erreurs[] = sprintf( __( 'La date « %s » est invalide.', 'espace-traiteur' ), $champ['label'] );
				$valeur          = '';
			}
			if ( ! empty( $champ['requis'] ) && '' === $valeur ) {
				self::$erreurs[] = sprintf( __( 'Le champ « %s » est obligatoire.', 'espace-traiteur' ), $champ['label'] );
			}
			$donnees[ $cle ] = $valeur;
		}
		if ( ! empty( $donnees['date_evenement'] ) && $donnees['date_evenement'] < current_time( 'Y-m-d' ) ) {
			self::$erreurs[] = __( 'La date de l’événement ne peut pas être passée.', 'espace-traiteur' );
		}
		if ( ! empty( $donnees['date_livraison'] ) && ! empty( $donnees['date_reprise'] ) && $donnees['date_reprise'] < $donnees['date_livraison'] ) {
			self::$erreurs[] = __( 'La date de reprise doit être postérieure à la date de livraison.', 'espace-traiteur' );
		}
		if ( self::$erreurs ) {
			return;
		}

		global $wpdb;
		$lock_name = 'etr_reservations_' . md5( $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock_name ) ) ) {
			self::$erreurs[] = __( 'La demande n’a pas pu être enregistrée. Merci de réessayer.', 'espace-traiteur' );
			return;
		}
		try {
			list( $debut, $fin ) = etr_periode( $donnees['date_evenement'], $donnees['date_livraison'], $donnees['date_reprise'] );
			$reservees           = $debut ? etr_quantites_reservees( $debut, $fin, $demande_id ) : array();
			$quantites           = isset( $_POST['quantites'] ) && is_array( $_POST['quantites'] ) ? wp_unslash( $_POST['quantites'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$lignes              = array();
			foreach ( $quantites as $article_id => $quantite ) {
				$article_id = absint( $article_id );
				$quantite   = is_scalar( $quantite ) ? absint( $quantite ) : 0;
				if ( ! $article_id || ! $quantite ) {
					continue;
				}
				$article = etr_get_article( $article_id );
				if ( ! $article ) {
					continue;
				}
				$dispo = etr_disponibilite( $article_id, $reservees );
				if ( 0 === $dispo ) {
					self::$erreurs[] = sprintf( __( '« %s » n’est plus disponible sur ces dates.', 'espace-traiteur' ), $article['titre'] );
				} elseif ( null !== $dispo && $quantite > $dispo ) {
					self::$erreurs[] = sprintf( __( 'La quantité demandée pour « %1$s » dépasse la quantité disponible sur ces dates (%2$s).', 'espace-traiteur' ), $article['titre'], number_format_i18n( $dispo ) );
				}
				$lignes[] = array(
					'article_id' => $article_id,
					'quantite'   => $quantite,
					'titre'      => $article['titre'],
					'unite'      => $article['unite'],
				);
			}
			if ( empty( $lignes ) && empty( $donnees['autres_besoins'] ) ) {
				self::$erreurs[] = __( 'Indiquez au moins un article du catalogue ou un besoin hors catalogue.', 'espace-traiteur' );
			}
			if ( self::$erreurs ) {
				return;
			}
			wp_update_post( array( 'ID' => $demande_id, 'post_title' => $donnees['evenement'] ) );
			foreach ( $donnees as $cle => $valeur ) {
				update_post_meta( $demande_id, '_etr_' . $cle, $valeur );
			}
			update_post_meta( $demande_id, '_etr_lignes', $lignes );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
		wp_safe_redirect( etr_url( array( 'etr_vue' => 'demande', 'id' => $demande_id, 'etr_msg' => 'modifiee' ) ) );
		exit;
	}

	/**
	 * Création d'une demande.
	 */
	private static function creer_demande() {
		if ( ! isset( $_POST['etr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_nonce'] ) ), 'etr_nouvelle_demande' ) ) {
			self::$erreurs[] = __( 'Votre session a expiré, merci de renvoyer le formulaire.', 'espace-traiteur' );
			return;
		}

		$saisie  = isset( $_POST['etr'] ) && is_array( $_POST['etr'] ) ? wp_unslash( $_POST['etr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- assaini champ par champ.
		$donnees = array();

		foreach ( etr_champs_demande() as $cle => $champ ) {
			$brut   = isset( $saisie[ $cle ] ) && is_string( $saisie[ $cle ] ) ? $saisie[ $cle ] : '';
			$valeur = 'textarea' === $champ['type'] ? sanitize_textarea_field( $brut ) : sanitize_text_field( $brut );

			if ( 'number' === $champ['type'] && '' !== $valeur ) {
				$valeur = (string) absint( $valeur );
			}
			if ( 'date' === $champ['type'] && '' !== $valeur && ! etr_date_valide( $valeur ) ) {
				/* translators: %s : libellé du champ. */
				self::$erreurs[] = sprintf( __( 'La date « %s » est invalide.', 'espace-traiteur' ), $champ['label'] );
				$valeur          = '';
			}
			if ( ! empty( $champ['requis'] ) && '' === $valeur ) {
				/* translators: %s : libellé du champ. */
				self::$erreurs[] = sprintf( __( 'Le champ « %s » est obligatoire.', 'espace-traiteur' ), $champ['label'] );
			}
			$donnees[ $cle ] = $valeur;
		}

		$aujourdhui = current_time( 'Y-m-d' );
		if ( ! empty( $donnees['date_evenement'] ) && $donnees['date_evenement'] < $aujourdhui ) {
			self::$erreurs[] = __( 'La date de l’événement ne peut pas être passée.', 'espace-traiteur' );
		}
		if ( ! empty( $donnees['date_livraison'] ) && ! empty( $donnees['date_reprise'] ) && $donnees['date_reprise'] < $donnees['date_livraison'] ) {
			self::$erreurs[] = __( 'La date de reprise doit être postérieure à la date de livraison.', 'espace-traiteur' );
		}

		if ( self::$erreurs ) {
			return;
		}

		global $wpdb;
		$lock_name = 'etr_reservations_' . md5( $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock_name ) ) ) {
			self::$erreurs[] = __( 'La demande n’a pas pu être enregistrée. Merci de réessayer.', 'espace-traiteur' );
			return;
		}

		$demande_id = 0;
		try {
			// Recalculer le stock sous verrou, puis conserver le verrou jusqu'à l'enregistrement complet.
			list( $debut, $fin ) = etr_periode( $donnees['date_evenement'], $donnees['date_livraison'], $donnees['date_reprise'] );
			$reservees           = $debut ? etr_quantites_reservees( $debut, $fin ) : array();
			$quantites           = isset( $_POST['quantites'] ) && is_array( $_POST['quantites'] ) ? wp_unslash( $_POST['quantites'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- converti en entiers ci-dessous.
			$lignes              = array();

			foreach ( $quantites as $article_id => $quantite ) {
				if ( (string) absint( $article_id ) !== (string) $article_id ) {
					continue;
				}
				$article_id = absint( $article_id );
				$quantite   = is_scalar( $quantite ) ? absint( $quantite ) : 0;
				if ( ! $article_id || ! $quantite ) {
					continue;
				}
				$article = etr_get_article( $article_id );
				if ( ! $article ) {
					continue;
				}
				$dispo = etr_disponibilite( $article_id, $reservees );
				if ( 0 === $dispo ) {
					/* translators: %s : article. */
					self::$erreurs[] = sprintf( __( '« %s » n’est plus disponible sur ces dates.', 'espace-traiteur' ), $article['titre'] );
				} elseif ( null !== $dispo && $quantite > $dispo ) {
					self::$erreurs[] = sprintf(
						/* translators: 1 : article, 2 : quantité disponible. */
						__( 'La quantité demandée pour « %1$s » dépasse la quantité disponible sur ces dates (%2$s).', 'espace-traiteur' ),
						$article['titre'],
						number_format_i18n( $dispo )
					);
				}
				$lignes[] = array(
					'article_id' => $article_id,
					'quantite'   => $quantite,
					'titre'      => $article['titre'],
					'unite'      => $article['unite'],
				);
			}

			if ( empty( $lignes ) && empty( $donnees['autres_besoins'] ) ) {
				self::$erreurs[] = __( 'Indiquez au moins un article du catalogue ou un besoin hors catalogue.', 'espace-traiteur' );
			}
			if ( self::$erreurs ) {
				return;
			}

			$demande_id = wp_insert_post(
				array(
					'post_type'   => 'etr_demande',
					'post_status' => 'publish',
					'post_author' => get_current_user_id(),
					'post_title'  => $donnees['evenement'],
				),
				true
			);
			if ( is_wp_error( $demande_id ) ) {
				self::$erreurs[] = __( 'La demande n’a pas pu être enregistrée. Merci de réessayer.', 'espace-traiteur' );
				return;
			}

			foreach ( $donnees as $cle => $valeur ) {
				if ( false === update_post_meta( $demande_id, '_etr_' . $cle, $valeur ) ) {
					throw new RuntimeException( 'Impossible d’enregistrer les détails de la demande.' );
				}
			}
			if ( false === update_post_meta( $demande_id, '_etr_lignes', $lignes ) || false === update_post_meta( $demande_id, '_etr_statut', 'en_attente' ) ) {
				throw new RuntimeException( 'Impossible d’enregistrer les articles de la demande.' );
			}
		} catch ( Throwable $e ) {
			if ( $demande_id && ! is_wp_error( $demande_id ) ) {
				wp_delete_post( $demande_id, true );
			}
			self::$erreurs[] = __( 'La demande n’a pas pu être enregistrée. Merci de réessayer.', 'espace-traiteur' );
			return;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		do_action( 'etr_demande_creee', $demande_id );

		wp_safe_redirect(
			etr_url(
				array(
					'etr_vue' => 'demande',
					'id'      => $demande_id,
					'etr_msg' => 'creee',
				)
			)
		);
		exit;
	}

	/**
	 * Annulation d'une demande en attente par son auteur.
	 */
	private static function annuler_demande() {
		$demande_id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		if ( ! $demande_id || ! isset( $_POST['etr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_nonce'] ) ), 'etr_annuler_' . $demande_id ) ) {
			return;
		}
		$demande = get_post( $demande_id );
		if ( ! $demande || 'etr_demande' !== $demande->post_type || (int) $demande->post_author !== get_current_user_id() ) {
			return;
		}
		if ( 'en_attente' !== etr_get_statut( $demande_id ) ) {
			return;
		}

		update_post_meta( $demande_id, '_etr_statut', 'annulee' );
		do_action( 'etr_demande_annulee', $demande_id );

		wp_safe_redirect( etr_url( array( 'etr_msg' => 'annulee' ) ) );
		exit;
	}

	/**
	 * Valeur saisie précédemment (réaffichage après erreur).
	 *
	 * @param string $cle Clé du champ.
	 * @return string
	 */
	private static function ancienne_valeur( $cle ) {
		if ( ! self::$erreurs || ! isset( $_POST['etr'][ $cle ] ) || ! is_string( $_POST['etr'][ $cle ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return self::$demande_modifiee_id ? (string) get_post_meta( self::$demande_modifiee_id, '_etr_' . $cle, true ) : '';
		}
		return sanitize_textarea_field( wp_unslash( $_POST['etr'][ $cle ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Quantité saisie précédemment pour un article.
	 *
	 * @param int $article_id ID article.
	 * @return string
	 */
	private static function ancienne_quantite( $article_id ) {
		if ( ! self::$erreurs || ! isset( $_POST['quantites'][ $article_id ] ) || ! is_scalar( $_POST['quantites'][ $article_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( self::$demande_modifiee_id ) {
				foreach ( etr_get_lignes( self::$demande_modifiee_id ) as $ligne ) {
					if ( (int) $ligne['article_id'] === (int) $article_id ) {
						return (string) (int) $ligne['quantite'];
					}
				}
			}
			return '';
		}
		$q = absint( $_POST['quantites'][ $article_id ] ); // phpcs:ignore WordPress.Security.NonceVerification
		return $q ? (string) $q : '';
	}

	/* ------------------------------------------------------------------ */
	/* Affichage                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Icône SVG (décorative).
	 *
	 * @param string $nom Nom de l'icône.
	 * @return string
	 */
	public static function icone( $nom ) {
		$traces = array(
			'demandes'     => '<path d="M8 6h12M8 12h12M8 18h12"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
			'plus'         => '<path d="M12 5v14M5 12h14"/>',
			'sortie'       => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5M5 12h11"/>',
			'site'         => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
			'retour'       => '<path d="M15 18l-6-6 6-6"/>',
			'suite'        => '<path d="M9 18l6-6-6-6"/>',
			'recherche'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
			'calendrier'   => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
			'lieu'         => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
			'invites'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
			'camion'       => '<path d="M2 6h12v10H2zM14 10h4l3 3v3h-7"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
			'message'      => '<path d="M4 5h16v11H8l-4 4z"/>',
			'coche'        => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
			'image'        => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5.5-5.5L5 20"/>',
			'accueil'      => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
			'catalogue'    => '<path d="M3 7.5L12 3l9 4.5-9 4.5z"/><path d="M3 7.5V16.5L12 21l9-4.5V7.5M12 12v9"/>',
			'reglages'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
			'alerte'       => '<path d="M12 3.5L2.5 20h19z"/><path d="M12 10v4.5M12 17.5h.01"/>',
			'courriel'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/>',
			'telephone'    => '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
			'externe'      => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
			'reprise'      => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
			'fete'         => '<path d="M8 21h8M12 15v6M6 3h12l-1 7a5 5 0 0 1-10 0z"/>',
			'telecharger'  => '<path d="M12 4v11M7 10l5 5 5-5"/><path d="M4 19h16"/>',
			'statistiques' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
			'dupliquer'    => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
			'modifier'     => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/>',
			'scan'         => '<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/>',
			'plus-onglet'  => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
			'cloche'       => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
		);
		if ( ! isset( $traces[ $nom ] ) ) {
			return '';
		}
		return '<svg class="etr-icone" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $traces[ $nom ] . '</svg>';
	}

	/**
	 * Shortcode [espace_traiteur].
	 *
	 * @return string
	 */
	public static function shortcode() {
		ob_start();

		if ( ! is_user_logged_in() ) {
			echo '<div class="etr-espace etr-espace--auth">';
			ETR_Comptes::vue();
			echo '</div>';
			return ob_get_clean();
		}

		if ( ! etr_est_traiteur() ) {
			echo '<div class="etr-espace"><p class="etr-notice etr-notice--erreur">' . esc_html__( 'Cet espace est réservé aux traiteurs partenaires.', 'espace-traiteur' ) . '</p></div>';
			return ob_get_clean();
		}

		$vue = isset( $_GET['etr_vue'] ) ? sanitize_key( wp_unslash( $_GET['etr_vue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( self::$erreurs ) {
			$vue = ! empty( $_POST['demande_id'] ) ? 'modifier' : 'nouvelle';
			$_GET['id'] = ! empty( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		}
		if ( 'demande' === $vue && ! isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$vue = '';
		}

		echo '<div class="etr-espace etr-app' . ( self::plein_ecran() ? '' : ' etr-app--integre' ) . '">';
		self::barre_laterale( 'nouvelle' === $vue ? 'nouvelle' : 'demandes' );
		echo '<main class="etr-app__principal" id="etr-contenu">';
		self::messages();

		if ( 'nouvelle' === $vue ) {
			self::vue_formulaire();
		} elseif ( 'modifier' === $vue && isset( $_GET['id'] ) ) {
			self::vue_formulaire( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( 'demande' === $vue ) {
			self::vue_demande( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			self::vue_tableau_de_bord();
		}

		echo '</main></div>';
		return ob_get_clean();
	}

	/**
	 * L'espace connecté s'affiche-t-il en plein écran (sans le thème) ?
	 *
	 * @return bool
	 */
	public static function plein_ecran() {
		return (bool) get_option( 'etr_espace_plein_ecran', '1' );
	}

	/**
	 * Barre latérale : logo, navigation, compte.
	 *
	 * @param string $actif Entrée de navigation active.
	 */
	private static function barre_laterale( $actif ) {
		$user    = wp_get_current_user();
		$logo_id = ETR_Comptes::logo_id();
		$site    = get_bloginfo( 'name' );

		echo '<header class="etr-app__barre">';
		echo '<a class="etr-app__logo" href="' . esc_url( etr_url() ) . '">';
		if ( $logo_id ) {
			echo wp_get_attachment_image( $logo_id, 'medium', false, array( 'alt' => $site ) );
		} else {
			echo '<span class="etr-app__nom-site">' . esc_html( $site ) . '</span>';
		}
		echo '</a>';

		echo '<nav class="etr-app__nav" aria-label="' . esc_attr__( 'Espace traiteur', 'espace-traiteur' ) . '"><ul>';
		$liens = array(
			'demandes' => array( etr_url(), __( 'Mes demandes', 'espace-traiteur' ), 'demandes' ),
			'nouvelle' => array( etr_url( array( 'etr_vue' => 'nouvelle' ) ), __( 'Nouvelle demande', 'espace-traiteur' ), 'plus' ),
		);
		foreach ( $liens as $cle => $lien ) {
			echo '<li><a href="' . esc_url( $lien[0] ) . '"' . ( $cle === $actif ? ' aria-current="page"' : '' ) . '>' . self::icone( $lien[2] ) . '<span>' . esc_html( $lien[1] ) . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
		}
		echo '</ul></nav>';

		echo '<div class="etr-app__compte">';
		echo '<p class="etr-app__societe">' . esc_html( etr_nom( $user ) ) . '<span>' . esc_html( $user->user_email ) . '</span></p>';
		echo '<ul class="etr-app__liens">';
		echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . self::icone( 'site' ) . '<span>' . esc_html__( 'Retour au site', 'espace-traiteur' ) . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<li><a href="' . esc_url( wp_logout_url( etr_url() ) ) . '">' . self::icone( 'sortie' ) . '<span>' . esc_html__( 'Se déconnecter', 'espace-traiteur' ) . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</ul></div>';
		echo '</header>';
	}

	/**
	 * En-tête d'une page de l'application.
	 *
	 * @param string $titre    Titre.
	 * @param string $sous     Sous-titre (texte brut).
	 * @param string $action   Bouton d'action (HTML échappé).
	 * @param string $retour   Lien de retour (HTML échappé).
	 */
	private static function entete_page( $titre, $sous = '', $action = '', $retour = '' ) {
		echo '<div class="etr-page-entete">';
		echo '<div class="etr-page-entete__texte">';
		echo $retour; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé par l'appelant.
		echo '<h1 class="etr-page-entete__titre">' . esc_html( $titre ) . '</h1>';
		if ( $sous ) {
			echo '<p class="etr-page-entete__sous">' . esc_html( $sous ) . '</p>';
		}
		echo '</div>';
		echo $action; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé par l'appelant.
		echo '</div>';
	}

	/**
	 * Bouton « Nouvelle demande ».
	 *
	 * @return string
	 */
	private static function bouton_nouvelle() {
		return '<a class="etr-btn" href="' . esc_url( etr_url( array( 'etr_vue' => 'nouvelle' ) ) ) . '">' . self::icone( 'plus' ) . '<span>' . esc_html__( 'Nouvelle demande', 'espace-traiteur' ) . '</span></a>';
	}

	/**
	 * Messages de confirmation et d'erreur.
	 */
	private static function messages() {
		$msg = isset( $_GET['etr_msg'] ) ? sanitize_key( wp_unslash( $_GET['etr_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$textes = array(
			'creee'   => __( 'Votre demande a bien été envoyée. Vous serez prévenu par e-mail de son traitement.', 'espace-traiteur' ),
			'modifiee' => __( 'Votre demande a bien été modifiée.', 'espace-traiteur' ),
			'annulee' => __( 'Votre demande a été annulée.', 'espace-traiteur' ),
		);
		if ( isset( $textes[ $msg ] ) && ! self::$erreurs ) {
			echo '<div class="etr-notice etr-notice--succes" role="status">' . self::icone( 'coche' ) . '<p>' . esc_html( $textes[ $msg ] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( self::$erreurs ) {
			echo '<div class="etr-notice etr-notice--erreur" role="alert"><div><p><strong>' . esc_html__( 'La demande n’a pas été envoyée. Corrigez les points suivants :', 'espace-traiteur' ) . '</strong></p><ul>';
			foreach ( self::$erreurs as $erreur ) {
				echo '<li>' . esc_html( $erreur ) . '</li>';
			}
			echo '</ul></div></div>';
		}
	}

	/**
	 * Bloc date (jour, mois, année) d'un événement.
	 *
	 * @param string $date   Date AAAA-MM-JJ.
	 * @param string $classe Classe supplémentaire.
	 * @return string
	 */
	public static function bloc_date( $date, $classe = '' ) {
		$ts = etr_date_valide( $date ) ? strtotime( $date ) : false;
		if ( ! $ts ) {
			return '<span class="etr-date ' . esc_attr( $classe ) . '" aria-hidden="true"><span class="etr-date__jour">–</span></span>';
		}
		$annee = date_i18n( 'Y', $ts );
		$html  = '<time class="etr-date ' . esc_attr( $classe ) . '" datetime="' . esc_attr( $date ) . '">';
		$html .= '<span class="etr-date__jour">' . esc_html( date_i18n( 'j', $ts ) ) . '</span>';
		$html .= '<span class="etr-date__mois">' . esc_html( date_i18n( 'M', $ts ) ) . ( current_time( 'Y' ) !== $annee ? ' ' . esc_html( $annee ) : '' ) . '</span>';
		$html .= '</time>';
		return $html;
	}

	/**
	 * Délai avant un événement (« Demain », « Dans 12 jours »…).
	 *
	 * @param string $date Date AAAA-MM-JJ.
	 * @return string
	 */
	public static function texte_delai( $date ) {
		$jours = (int) round( ( strtotime( $date ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
		if ( 0 === $jours ) {
			return __( 'Aujourd’hui', 'espace-traiteur' );
		}
		if ( 1 === $jours ) {
			return __( 'Demain', 'espace-traiteur' );
		}
		/* translators: %s : nombre de jours. */
		return sprintf( _n( 'Dans %s jour', 'Dans %s jours', $jours, 'espace-traiteur' ), number_format_i18n( $jours ) );
	}

	/**
	 * Première ligne d'une adresse.
	 *
	 * @param int $demande_id ID.
	 * @return string
	 */
	public static function lieu_court( $demande_id ) {
		$lignes = preg_split( '/\R/', trim( (string) get_post_meta( $demande_id, '_etr_lieu', true ) ) );
		return $lignes ? $lignes[0] : '';
	}

	/**
	 * Nombre d'articles d'une demande, en texte.
	 *
	 * @param int $demande_id ID.
	 * @return string
	 */
	public static function texte_articles( $demande_id ) {
		$nb = count( etr_get_lignes( $demande_id ) );
		if ( ! $nb ) {
			return __( 'Hors catalogue', 'espace-traiteur' );
		}
		/* translators: %s : nombre d'articles. */
		return sprintf( _n( '%s article', '%s articles', $nb, 'espace-traiteur' ), number_format_i18n( $nb ) );
	}

	/**
	 * Tableau de bord : prochain événement, filtres par statut, liste des demandes.
	 */
	private static function vue_tableau_de_bord() {
		$user_id = get_current_user_id();
		$statuts = etr_statuts();
		$filtre  = isset( $_GET['etr_statut'] ) ? sanitize_key( wp_unslash( $_GET['etr_statut'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $statuts[ $filtre ] ) ) {
			$filtre = '';
		}

		// Toutes les demandes du traiteur : compteurs par statut et prochain événement.
		$toutes = get_posts(
			array(
				'post_type'   => 'etr_demande',
				'post_status' => 'publish',
				'author'      => $user_id,
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		update_meta_cache( 'post', $toutes );

		$compteurs  = array_fill_keys( array_keys( $statuts ), 0 );
		$prochain   = 0;
		$date_proch = '';
		$aujourdhui = current_time( 'Y-m-d' );
		foreach ( $toutes as $id ) {
			$statut = etr_get_statut( $id );
			++$compteurs[ $statut ];
			$date = (string) get_post_meta( $id, '_etr_date_evenement', true );
			if ( in_array( $statut, etr_statuts_reservant(), true ) && 'livree' !== $statut && $date >= $aujourdhui && ( ! $date_proch || $date < $date_proch ) ) {
				$prochain   = $id;
				$date_proch = $date;
			}
		}

		$sous = '';
		if ( $compteurs['en_attente'] ?? 0 ) {
			/* translators: %s : nombre de demandes. */
			$sous = sprintf( _n( '%s demande en attente de réponse de notre équipe.', '%s demandes en attente de réponse de notre équipe.', $compteurs['en_attente'], 'espace-traiteur' ), number_format_i18n( $compteurs['en_attente'] ) );
		} elseif ( $toutes ) {
			$sous = __( 'Toutes vos demandes ont reçu une réponse.', 'espace-traiteur' );
		}
		self::entete_page( __( 'Mes demandes', 'espace-traiteur' ), $sous, $toutes ? self::bouton_nouvelle() : '' );

		if ( ! $toutes ) {
			echo '<div class="etr-vide">';
			echo '<div class="etr-vide__date" aria-hidden="true">' . self::icone( 'calendrier' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<h2 class="etr-vide__titre">' . esc_html__( 'Préparez votre première réception', 'espace-traiteur' ) . '</h2>';
			echo '<p>' . esc_html__( 'Indiquez la date et le lieu de l’événement, puis choisissez le mobilier, l’art de la table et la décoration. Les quantités disponibles s’affichent pour vos dates.', 'espace-traiteur' ) . '</p>';
			echo self::bouton_nouvelle(); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';
			return;
		}

		if ( $prochain && ! $filtre ) {
			self::carte_prochain( $prochain );
		}

		// Filtres par statut.
		echo '<div class="etr-liste-entete">';
		echo '<nav class="etr-filtres" aria-label="' . esc_attr__( 'Filtrer par statut', 'espace-traiteur' ) . '"><ul>';
		echo '<li><a href="' . esc_url( etr_url() ) . '"' . ( '' === $filtre ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'Toutes', 'espace-traiteur' ) . ' <span>' . esc_html( number_format_i18n( count( $toutes ) ) ) . '</span></a></li>';
		foreach ( $statuts as $cle => $libelle ) {
			if ( ! $compteurs[ $cle ] && $cle !== $filtre ) {
				continue;
			}
			echo '<li><a href="' . esc_url( etr_url( array( 'etr_statut' => $cle ) ) ) . '"' . ( $cle === $filtre ? ' aria-current="true"' : '' ) . '>' . esc_html( $libelle ) . ' <span>' . esc_html( number_format_i18n( $compteurs[ $cle ] ) ) . '</span></a></li>';
		}
		echo '</ul></nav></div>';

		// Liste paginée.
		$page = isset( $_GET['etr_page'] ) ? max( 1, absint( $_GET['etr_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		// Tri par date d'événement (les demandes sans date restent listées, en dernier).
		$args = array(
			'post_type'      => 'etr_demande',
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => 20,
			'paged'          => $page,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation'        => 'OR',
				'date_evenement'  => array(
					'key'     => '_etr_date_evenement',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_etr_date_evenement',
					'compare' => 'NOT EXISTS',
				),
			),
			'orderby'        => array(
				'date_evenement' => 'DESC',
				'date'           => 'DESC',
			),
		);
		if ( $filtre ) {
			$condition = array(
				'key'   => '_etr_statut',
				'value' => $filtre,
			);
			// Une demande sans statut enregistré est « En attente » (voir etr_get_statut()).
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				$args['meta_query'],
				'en_attente' === $filtre
					? array(
						'relation' => 'OR',
						$condition,
						array(
							'key'     => '_etr_statut',
							'compare' => 'NOT EXISTS',
						),
					)
					: $condition,
			);
		}
		$demandes = new WP_Query( $args );

		// Page au-delà de la dernière : WordPress ne calcule pas le nombre de pages quand
		// la page demandée est vide, on revient donc sur la dernière page existante.
		if ( ! $demandes->posts && $page > 1 ) {
			$total         = $filtre ? $compteurs[ $filtre ] : count( $toutes );
			$page          = max( 1, (int) ceil( $total / $args['posts_per_page'] ) );
			$args['paged'] = $page;
			$demandes      = new WP_Query( $args );
		}

		if ( ! $demandes->posts ) {
			echo '<div class="etr-vide etr-vide--compact"><p>';
			/* translators: %s : statut. */
			echo esc_html( sprintf( __( 'Aucune demande « %s ».', 'espace-traiteur' ), $statuts[ $filtre ] ?? '' ) );
			echo '</p><a class="etr-lien" href="' . esc_url( etr_url() ) . '">' . esc_html__( 'Voir toutes les demandes', 'espace-traiteur' ) . '</a></div>';
			return;
		}

		echo '<ul class="etr-agenda">';
		foreach ( $demandes->posts as $demande ) {
			$date   = (string) get_post_meta( $demande->ID, '_etr_date_evenement', true );
			$statut = etr_get_statut( $demande->ID );
			$lieu   = self::lieu_court( $demande->ID );
			$url    = etr_url(
				array(
					'etr_vue' => 'demande',
					'id'      => $demande->ID,
				)
			);
			$passe  = $date && $date < $aujourdhui;

			echo '<li class="etr-agenda__item' . ( $passe ? ' etr-agenda__item--passe' : '' ) . '">';
			echo '<a class="etr-agenda__lien" href="' . esc_url( $url ) . '">';
			echo self::bloc_date( $date ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<span class="etr-agenda__corps">';
			echo '<span class="etr-agenda__titre">' . esc_html( get_the_title( $demande ) ) . '</span>';
			echo '<span class="etr-agenda__meta">';
			if ( $lieu ) {
				echo '<span>' . esc_html( $lieu ) . '</span>';
			}
			echo '<span>' . esc_html( self::texte_articles( $demande->ID ) ) . '</span>';
			/* translators: %d : numéro de la demande. */
			echo '<span>' . esc_html( sprintf( __( 'Demande n° %d', 'espace-traiteur' ), $demande->ID ) ) . '</span>';
			echo '</span></span>';
			echo etr_statut_badge( $statut ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<span class="etr-agenda__chevron">' . self::icone( 'suite' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</a></li>';
		}
		echo '</ul>';

		if ( $demandes->max_num_pages > 1 ) {
			$grand = 999999999;
			$base  = array( 'etr_page' => $grand );
			if ( $filtre ) {
				$base['etr_statut'] = $filtre;
			}
			$liens = paginate_links(
				array(
					'base'      => str_replace( $grand, '%#%', esc_url( etr_url( $base ) ) ),
					'format'    => '',
					'current'   => $page,
					'total'     => $demandes->max_num_pages,
					'type'      => 'list',
					'prev_text' => __( 'Précédent', 'espace-traiteur' ),
					'next_text' => __( 'Suivant', 'espace-traiteur' ),
				)
			);
			if ( $liens ) {
				echo '<nav class="etr-pagination" aria-label="' . esc_attr__( 'Navigation des demandes', 'espace-traiteur' ) . '">' . wp_kses_post( $liens ) . '</nav>';
			}
		}
	}

	/**
	 * Carte du prochain événement.
	 *
	 * @param int $demande_id ID.
	 */
	private static function carte_prochain( $demande_id ) {
		$date      = (string) get_post_meta( $demande_id, '_etr_date_evenement', true );
		$invites   = (string) get_post_meta( $demande_id, '_etr_nb_invites', true );
		$livraison = (string) get_post_meta( $demande_id, '_etr_date_livraison', true );
		$reprise   = (string) get_post_meta( $demande_id, '_etr_date_reprise', true );
		$lieu      = self::lieu_court( $demande_id );
		$url       = etr_url(
			array(
				'etr_vue' => 'demande',
				'id'      => $demande_id,
			)
		);

		echo '<section class="etr-prochain" aria-labelledby="etr-prochain-titre">';
		echo self::bloc_date( $date, 'etr-date--grande' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="etr-prochain__corps">';
		echo '<p class="etr-prochain__delai">' . esc_html__( 'Prochain événement', 'espace-traiteur' ) . ' <strong>' . esc_html( self::texte_delai( $date ) ) . '</strong></p>';
		echo '<h2 class="etr-prochain__titre" id="etr-prochain-titre"><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $demande_id ) ) . '</a></h2>';
		echo '<ul class="etr-prochain__faits">';
		if ( $lieu ) {
			echo '<li>' . self::icone( 'lieu' ) . esc_html( $lieu ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( '' !== $invites ) {
			/* translators: %s : nombre d'invités. */
			echo '<li>' . self::icone( 'invites' ) . esc_html( sprintf( _n( '%s invité', '%s invités', (int) $invites, 'espace-traiteur' ), number_format_i18n( (int) $invites ) ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $livraison || $reprise ) {
			$texte = $livraison && $reprise
				/* translators: 1 : date de livraison, 2 : date de reprise. */
				? sprintf( __( 'Livraison le %1$s, reprise le %2$s', 'espace-traiteur' ), etr_format_date( $livraison ), etr_format_date( $reprise ) )
				: ( $livraison
					/* translators: %s : date. */
					? sprintf( __( 'Livraison le %s', 'espace-traiteur' ), etr_format_date( $livraison ) )
					/* translators: %s : date. */
					: sprintf( __( 'Reprise le %s', 'espace-traiteur' ), etr_format_date( $reprise ) ) );
			echo '<li>' . self::icone( 'camion' ) . esc_html( $texte ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></div>';
		echo '<div class="etr-prochain__statut">' . etr_statut_badge( etr_get_statut( $demande_id ) ) . '<span class="etr-muted">' . esc_html( self::texte_articles( $demande_id ) ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</section>';
	}

	/**
	 * Étapes de suivi d'une demande.
	 *
	 * @param string $statut Statut courant.
	 */
	private static function suivi( $statut ) {
		$etapes = array(
			'en_attente' => __( 'Envoyée', 'espace-traiteur' ),
			'validee'    => __( 'Validée', 'espace-traiteur' ),
			'livree'     => __( 'Livrée', 'espace-traiteur' ),
			'cloturee'   => __( 'Reprise et clôturée', 'espace-traiteur' ),
		);
		$explications = array(
			'en_attente' => __( 'Notre équipe étudie votre demande. Vous serez prévenu par e-mail dès qu’elle sera traitée.', 'espace-traiteur' ),
			'validee'    => __( 'Le matériel est réservé pour votre événement.', 'espace-traiteur' ),
			'livree'     => __( 'Le matériel a été livré. Il sera repris à la date prévue.', 'espace-traiteur' ),
			'cloturee'   => __( 'Le matériel a été repris. Cette demande est terminée.', 'espace-traiteur' ),
			'refusee'    => __( 'Notre équipe n’a pas pu donner suite à cette demande.', 'espace-traiteur' ),
			'annulee'    => __( 'Vous avez annulé cette demande. Le matériel a été libéré.', 'espace-traiteur' ),
		);

		echo '<div class="etr-suivi">';
		if ( isset( $etapes[ $statut ] ) ) {
			$position = array_search( $statut, array_keys( $etapes ), true );
			echo '<ol class="etr-suivi__etapes">';
			$i = 0;
			foreach ( $etapes as $libelle ) {
				$etat = $i < $position ? 'faite' : ( $i === $position ? 'actuelle' : 'a-venir' );
				echo '<li class="etr-suivi__etape etr-suivi__etape--' . esc_attr( $etat ) . '"' . ( 'actuelle' === $etat ? ' aria-current="step"' : '' ) . '><span class="etr-suivi__point">' . ( $i <= $position ? self::icone( 'coche' ) : '' ) . '</span><span>' . esc_html( $libelle ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
				++$i;
			}
			echo '</ol>';
		}
		if ( isset( $explications[ $statut ] ) ) {
			echo '<p class="etr-suivi__texte">' . esc_html( $explications[ $statut ] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Détail d'une demande.
	 *
	 * @param int $demande_id ID.
	 */
	private static function vue_demande( $demande_id ) {
		$demande = get_post( $demande_id );
		$retour  = '<a class="etr-retour" href="' . esc_url( etr_url() ) . '">' . self::icone( 'retour' ) . '<span>' . esc_html__( 'Mes demandes', 'espace-traiteur' ) . '</span></a>';

		if ( ! $demande || 'etr_demande' !== $demande->post_type || ( (int) $demande->post_author !== get_current_user_id() && ! etr_est_gestionnaire() ) ) {
			echo $retour; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Cette demande n’existe pas ou n’est pas liée à votre compte.', 'espace-traiteur' ) . '</p></div>';
			return;
		}

		$statut = etr_get_statut( $demande_id );
		/* translators: 1 : numéro, 2 : date d'envoi. */
		$sous = sprintf( __( 'Demande n° %1$d, envoyée le %2$s', 'espace-traiteur' ), $demande_id, get_the_date( '', $demande ) );
		self::entete_page( get_the_title( $demande ), $sous, '<div class="etr-page-entete__statut">' . etr_statut_badge( $statut ) . '</div>', $retour );

		self::suivi( $statut );

		$reponse = get_post_meta( $demande_id, '_etr_reponse', true );
		if ( $reponse ) {
			echo '<section class="etr-reponse">' . self::icone( 'message' ) . '<div><h2>' . esc_html__( 'Message de notre équipe', 'espace-traiteur' ) . '</h2><p>' . nl2br( esc_html( $reponse ) ) . '</p></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		$champs     = etr_champs_demande();
		$groupes    = array(
			__( 'Événement', 'espace-traiteur' )           => array( 'evenement', 'date_evenement', 'nb_invites', 'lieu', 'contact' ),
			__( 'Livraison et reprise', 'espace-traiteur' ) => array( 'date_livraison', 'date_reprise' ),
		);
		$deja       = array_merge( ...array_values( $groupes ) );
		$complement = array_diff( array_keys( $champs ), $deja );

		echo '<div class="etr-detail">';
		echo '<div class="etr-detail__infos">';
		foreach ( $groupes as $titre => $cles ) {
			echo '<section class="etr-panneau"><h2 class="etr-panneau__titre">' . esc_html( $titre ) . '</h2><dl class="etr-details">';
			foreach ( $cles as $cle ) {
				if ( isset( $champs[ $cle ] ) && 'evenement' !== $cle ) {
					echo '<div><dt>' . esc_html( $champs[ $cle ]['label'] ) . '</dt><dd>' . etr_valeur_affichee( $demande_id, $cle, $champs[ $cle ]['type'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
			echo '</dl></section>';
		}
		$remplis = array_filter(
			$complement,
			function ( $cle ) use ( $demande_id ) {
				return '' !== (string) get_post_meta( $demande_id, '_etr_' . $cle, true );
			}
		);
		if ( $remplis ) {
			echo '<section class="etr-panneau"><h2 class="etr-panneau__titre">' . esc_html__( 'Compléments', 'espace-traiteur' ) . '</h2><dl class="etr-details etr-details--empile">';
			foreach ( $remplis as $cle ) {
				echo '<div><dt>' . esc_html( $champs[ $cle ]['label'] ) . '</dt><dd>' . etr_valeur_affichee( $demande_id, $cle, $champs[ $cle ]['type'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</dl></section>';
		}
		echo '</div>';

		$lignes = etr_get_lignes( $demande_id );
		echo '<section class="etr-panneau etr-detail__materiel"><h2 class="etr-panneau__titre">' . esc_html__( 'Matériel demandé', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $lignes ) ) ) . '</span></h2>';
		if ( ! $lignes ) {
			echo '<p class="etr-muted">' . esc_html__( 'Aucun article du catalogue : voir les besoins hors catalogue.', 'espace-traiteur' ) . '</p>';
		} else {
			$chiffrage = ( get_option( 'etr_afficher_prix' ) || etr_est_gestionnaire() ) ? etr_chiffrage( $demande_id ) : null;
			echo '<ul class="etr-lignes' . ( $chiffrage ? ' etr-lignes--prix' : '' ) . '">';
			foreach ( $lignes as $index => $ligne ) {
				$article = etr_get_article( $ligne['article_id'] );
				echo '<li class="etr-ligne">';
				echo ( $article && $article['image'] ) ? wp_kses_post( $article['image'] ) : '<span class="etr-article__img etr-article__img--vide">' . self::icone( 'image' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<span class="etr-ligne__nom">' . esc_html( $ligne['titre'] );
				if ( $article && $article['reference'] ) {
					echo '<span class="etr-muted">' . esc_html( $article['reference'] ) . '</span>';
				}
				echo '</span>';
				echo '<span class="etr-ligne__qte">' . esc_html( number_format_i18n( $ligne['quantite'] ) ) . ( ! empty( $ligne['unite'] ) ? '<small>' . esc_html( $ligne['unite'] ) . '</small>' : '' ) . '</span>';
				if ( $chiffrage ) {
					echo etr_prix_ligne_html( $chiffrage['lignes'][ $index ], (int) $ligne['quantite'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans etr_prix_ligne_html().
				}
				echo '</li>';
			}
			echo '</ul>';
			if ( $chiffrage ) {
				echo etr_total_indicatif_html( $chiffrage ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans etr_total_indicatif_html().
			}
		}
		echo '</section>';
		echo '</div>';

		if ( 'en_attente' === $statut && (int) $demande->post_author === get_current_user_id() ) {
			echo '<p><a class="etr-btn etr-btn--secondaire" href="' . esc_url( etr_url( array( 'etr_vue' => 'modifier', 'id' => $demande_id ) ) ) . '">' . esc_html__( 'Modifier la demande', 'espace-traiteur' ) . '</a></p>';
			echo '<form method="post" class="etr-annuler" onsubmit="return confirm(\'' . esc_js( __( 'Annuler cette demande ? Le matériel sera libéré.', 'espace-traiteur' ) ) . '\');">';
			wp_nonce_field( 'etr_annuler_' . $demande_id, 'etr_nonce' );
			echo '<input type="hidden" name="etr_action" value="annuler_demande">';
			echo '<input type="hidden" name="demande_id" value="' . (int) $demande_id . '">';
			echo '<p class="etr-muted">' . esc_html__( 'Vous pouvez annuler tant que la demande n’est pas validée.', 'espace-traiteur' ) . '</p>';
			echo '<button type="submit" class="etr-btn etr-btn--danger">' . esc_html__( 'Annuler la demande', 'espace-traiteur' ) . '</button>';
			echo '</form>';
		}
	}

	/**
	 * Formulaire de nouvelle demande.
	 */
	private static function vue_formulaire( $demande_id = 0 ) {
		$demande = $demande_id ? get_post( $demande_id ) : null;
		if ( $demande && ( 'etr_demande' !== $demande->post_type || (int) $demande->post_author !== get_current_user_id() || 'en_attente' !== etr_get_statut( $demande_id ) ) ) {
			$demande = null;
		}
		self::$demande_modifiee_id = $demande ? (int) $demande_id : 0;
		self::entete_page(
			$demande ? __( 'Modifier la demande', 'espace-traiteur' ) : __( 'Nouvelle demande', 'espace-traiteur' ),
			__( 'Décrivez votre réception, puis choisissez le matériel. Notre équipe vous répond par e-mail.', 'espace-traiteur' ),
			'',
			'<a class="etr-retour" href="' . esc_url( etr_url() ) . '">' . self::icone( 'retour' ) . '<span>' . esc_html__( 'Mes demandes', 'espace-traiteur' ) . '</span></a>'
		);

		echo '<form method="post" class="etr-form" action="' . esc_url( etr_url( array( 'etr_vue' => $demande ? 'modifier' : 'nouvelle', 'id' => $demande ? $demande_id : null ) ) ) . '">';
		if ( $demande ) {
			wp_nonce_field( 'etr_modifier_' . $demande_id, 'etr_nonce' );
			echo '<input type="hidden" name="etr_action" value="modifier_demande">';
			echo '<input type="hidden" name="demande_id" value="' . (int) $demande_id . '">';
		} else {
			wp_nonce_field( 'etr_nouvelle_demande', 'etr_nonce' );
			echo '<input type="hidden" name="etr_action" value="nouvelle_demande">';
		}
		echo '<div class="etr-form__etapes">';

		$champs   = etr_champs_demande();
		$sections = array(
			array( __( 'Votre événement', 'espace-traiteur' ), '', array( 'evenement', 'date_evenement', 'nb_invites', 'lieu', 'contact' ) ),
			array( __( 'Livraison et reprise', 'espace-traiteur' ), __( 'Le matériel vous est réservé de la livraison à la reprise. Sans date, il l’est pour le jour de l’événement.', 'espace-traiteur' ), array( 'date_livraison', 'date_reprise' ) ),
		);
		$places   = array();
		$numero   = 0;

		foreach ( $sections as $section ) {
			self::ouvrir_etape( ++$numero, $section[0], $section[1] );
			echo '<div class="etr-grille">';
			foreach ( $section[2] as $cle ) {
				if ( isset( $champs[ $cle ] ) ) {
					self::champ( $cle, $champs[ $cle ] );
				}
				$places[] = $cle;
			}
			echo '</div></fieldset>';
		}

		self::catalogue( ++$numero );

		self::ouvrir_etape( ++$numero, __( 'Compléments', 'espace-traiteur' ), __( 'Un besoin hors catalogue, une contrainte d’accès, un horaire de livraison : précisez-le ici.', 'espace-traiteur' ) );
		echo '<div class="etr-grille">';
		foreach ( $champs as $cle => $champ ) {
			if ( ! in_array( $cle, $places, true ) ) {
				self::champ( $cle, $champ );
			}
		}
		echo '</div></fieldset>';
		echo '</div>';

		// Récapitulatif (rempli par le script ; sans script, seul le bouton d'envoi est utile).
		echo '<aside class="etr-recap-commande" aria-labelledby="etr-recap-titre">';
		echo '<h2 class="etr-recap-commande__titre" id="etr-recap-titre">' . esc_html__( 'Votre sélection', 'espace-traiteur' ) . '</h2>';
		echo '<p class="etr-recap-commande__resume" aria-live="polite">' . esc_html__( 'Aucun article pour l’instant.', 'espace-traiteur' ) . '</p>';
		echo '<ul class="etr-recap-commande__liste"></ul>';
		echo '<button type="submit" class="etr-btn etr-btn--plein">' . esc_html__( 'Envoyer la demande', 'espace-traiteur' ) . '</button>';
		echo '<p class="etr-recap-commande__note">' . esc_html__( 'Rien n’est facturé à l’envoi : notre équipe valide d’abord la demande avec vous.', 'espace-traiteur' ) . '</p>';
		echo '</aside>';
		echo '</form>';
	}

	/**
	 * Ouvre une étape numérotée du formulaire.
	 *
	 * @param int    $numero Numéro de l'étape.
	 * @param string $titre  Titre.
	 * @param string $aide   Texte d'aide.
	 */
	private static function ouvrir_etape( $numero, $titre, $aide = '' ) {
		echo '<fieldset class="etr-etape"><legend class="etr-etape__titre"><span class="etr-etape__numero" aria-hidden="true">' . (int) $numero . '</span>' . esc_html( $titre ) . '</legend>';
		if ( $aide ) {
			echo '<p class="etr-etape__aide">' . esc_html( $aide ) . '</p>';
		}
	}

	/**
	 * Un champ du formulaire.
	 *
	 * @param string $cle   Clé.
	 * @param array  $champ Définition.
	 */
	private static function champ( $cle, $champ ) {
		$id     = 'etr-' . $cle;
		$requis = ! empty( $champ['requis'] );
		$valeur = self::ancienne_valeur( $cle );
		$large  = 'textarea' === $champ['type'] || 'evenement' === $cle;
		$classe = $large ? 'etr-champ etr-champ--large' : 'etr-champ';

		echo '<p class="' . esc_attr( $classe ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $champ['label'] ) . ( $requis ? '' : ' <span class="etr-champ__option">' . esc_html__( 'facultatif', 'espace-traiteur' ) . '</span>' ) . '</label>';

		if ( 'textarea' === $champ['type'] ) {
			printf( '<textarea id="%1$s" name="etr[%2$s]" rows="3"%3$s>%4$s</textarea>', esc_attr( $id ), esc_attr( $cle ), $requis ? ' required' : '', esc_textarea( $valeur ) );
		} else {
			$extra = '';
			if ( 'number' === $champ['type'] ) {
				$extra = ' min="0" step="1" inputmode="numeric"';
			} elseif ( 'date' === $champ['type'] ) {
				$extra = ' min="' . esc_attr( current_time( 'Y-m-d' ) ) . '"';
			}
			printf(
				'<input type="%1$s" id="%2$s" name="etr[%3$s]" value="%4$s"%5$s%6$s>',
				esc_attr( $champ['type'] ),
				esc_attr( $id ),
				esc_attr( $cle ),
				esc_attr( $valeur ),
				$extra, // phpcs:ignore WordPress.Security.EscapeOutput -- construit ci-dessus.
				$requis ? ' required' : ''
			);
		}
		echo '</p>';
	}

	/**
	 * Catalogue groupé par famille puis sous-catégorie, avec champ quantité.
	 *
	 * @param int $numero Numéro de l'étape.
	 */
	private static function catalogue( $numero ) {
		$articles = etr_catalogue();

		// Après une erreur, les dates sont connues : on affiche directement les disponibilités.
		list( $debut, $fin ) = etr_periode( self::ancienne_valeur( 'date_evenement' ), self::ancienne_valeur( 'date_livraison' ), self::ancienne_valeur( 'date_reprise' ) );
		$reservees           = $debut ? etr_quantites_reservees( $debut, $fin, self::$demande_modifiee_id ) : null;

		echo '<fieldset class="etr-etape etr-catalogue"><legend class="etr-etape__titre"><span class="etr-etape__numero" aria-hidden="true">' . (int) $numero . '</span>' . esc_html__( 'Mobilier, art de la table et décoration', 'espace-traiteur' ) . '</legend>';

		if ( ! $articles ) {
			echo '<p class="etr-etape__aide">' . esc_html__( 'Le catalogue est vide pour le moment : décrivez vos besoins dans « Autres besoins ».', 'espace-traiteur' ) . '</p></fieldset>';
			return;
		}

		// Famille (1er niveau) => sous-catégorie => articles. etr_catalogue() est déjà trié.
		$familles = array();
		foreach ( $articles as $article ) {
			$niveaux = explode( ' › ', $article['categorie'], 2 );
			$famille = $niveaux[0];
			$sous    = isset( $niveaux[1] ) ? $niveaux[1] : '';
			$familles[ $famille ][ $sous ][] = $article;
		}

		echo '<p class="etr-dispo-info' . ( $debut ? ' etr-dispo-info--dates' : '' ) . '" aria-live="polite">' . self::icone( 'calendrier' ) . '<span class="etr-dispo-info__texte">' . esc_html( $debut ? self::texte_periode( $debut, $fin ) : __( 'Choisissez la date de l’événement pour voir les quantités disponibles.', 'espace-traiteur' ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// Outils de recherche et de filtre (activés par le script).
		echo '<div class="etr-outils" hidden>';
		echo '<label class="etr-recherche">' . self::icone( 'recherche' ) . '<span class="screen-reader-text">' . esc_html__( 'Rechercher un article', 'espace-traiteur' ) . '</span><input type="search" class="etr-recherche__champ" placeholder="' . esc_attr__( 'Rechercher un article ou une référence', 'espace-traiteur' ) . '" autocomplete="off"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( count( $familles ) > 1 ) {
			echo '<div class="etr-familles" role="group" aria-label="' . esc_attr__( 'Familles', 'espace-traiteur' ) . '">';
			echo '<button type="button" class="etr-familles__btn" data-famille="" aria-pressed="true">' . esc_html__( 'Tout', 'espace-traiteur' ) . '</button>';
			foreach ( array_keys( $familles ) as $index => $famille ) {
				echo '<button type="button" class="etr-familles__btn" data-famille="' . (int) $index . '" aria-pressed="false">' . esc_html( $famille ) . '</button>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '<p class="etr-catalogue__aucun" hidden>' . esc_html__( 'Aucun article ne correspond à votre recherche.', 'espace-traiteur' ) . '</p>';

		$index = 0;
		foreach ( $familles as $famille => $sous_categories ) {
			$total = 0;
			foreach ( $sous_categories as $liste ) {
				$total += count( $liste );
			}
			echo '<section class="etr-famille" data-famille="' . (int) $index++ . '">';
			echo '<h3 class="etr-famille__titre">' . esc_html( $famille ) . ' <span class="etr-compteur">' . (int) $total . '</span></h3>';
			foreach ( $sous_categories as $sous => $liste ) {
				echo '<div class="etr-sous">';
				if ( '' !== $sous ) {
					echo '<h4 class="etr-sous-categorie">' . esc_html( $sous ) . '</h4>';
				}
				echo '<ul class="etr-articles">';
				foreach ( $liste as $article ) {
					self::ligne_article( $article, $reservees );
				}
				echo '</ul></div>';
			}
			echo '</section>';
		}
		echo '</fieldset>';
	}

	/**
	 * Une ligne du catalogue.
	 *
	 * @param array      $article   Article normalisé.
	 * @param array|null $reservees Quantités réservées (null = dates inconnues).
	 */
	private static function ligne_article( $article, $reservees ) {
		$id      = 'etr-qte-' . $article['id'];
		$stock   = etr_stock_total( $article['id'] );
		$dispo   = etr_disponibilite( $article['id'], $reservees );
		$indispo = 0 === $dispo;
		$qte     = $indispo ? '' : self::ancienne_quantite( $article['id'] );

		if ( null !== $reservees || $indispo ) {
			$texte_dispo = etr_texte_disponibilite( $article['id'], $dispo );
		} elseif ( null !== $stock ) {
			/* translators: %s : stock total. */
			$texte_dispo = sprintf( __( 'Stock total : %s', 'espace-traiteur' ), number_format_i18n( $stock ) . ( $article['unite'] ? ' ' . $article['unite'] : '' ) );
		} else {
			$texte_dispo = '';
		}

		$recherche = remove_accents( strtolower( $article['titre'] . ' ' . $article['reference'] ) );
		echo '<li class="etr-article' . ( $indispo ? ' etr-article--indispo' : '' ) . ( '' !== $qte ? ' etr-article--choisi' : '' ) . '" data-article="' . (int) $article['id'] . '" data-titre="' . esc_attr( $article['titre'] ) . '" data-unite="' . esc_attr( $article['unite'] ) . '" data-recherche="' . esc_attr( $recherche ) . '">';
		echo $article['image'] ? wp_kses_post( $article['image'] ) : '<span class="etr-article__img etr-article__img--vide">' . self::icone( 'image' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="etr-article__infos"><label for="' . esc_attr( $id ) . '" class="etr-article__nom">' . esc_html( $article['titre'] ) . '</label>';
		echo '<p class="etr-article__meta">';
		if ( $article['reference'] ) {
			echo '<span class="etr-article__ref">' . esc_html( $article['reference'] ) . '</span>';
		}
		if ( $article['prix_html'] ) {
			echo '<span class="etr-article__prix">' . wp_kses_post( $article['prix_html'] ) . '</span>';
		}
		if ( $article['url'] ) {
			echo '<a href="' . esc_url( $article['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir la fiche', 'espace-traiteur' ) . '<span class="screen-reader-text"> ' . esc_html__( '(nouvel onglet)', 'espace-traiteur' ) . '</span></a>';
		}
		echo '</p>';
		if ( $article['description'] ) {
			echo '<p class="etr-article__desc">' . esc_html( $article['description'] ) . '</p>';
		}
		echo '</div>';
		echo '<div class="etr-article__action">';
		echo '<p class="etr-dispo">' . esc_html( $texte_dispo ) . '</p>';
		echo '<div class="etr-qte">';
		printf(
			'<input type="number" class="etr-article__qte" id="%1$s" name="quantites[%2$d]" min="0" step="1" inputmode="numeric"%3$s value="%4$s" placeholder="0" aria-label="%5$s">',
			esc_attr( $id ),
			(int) $article['id'],
			( null !== $dispo ? ' max="' . (int) $dispo . '"' : '' ) . ( $indispo ? ' disabled' : '' ), // phpcs:ignore WordPress.Security.EscapeOutput -- entiers.
			esc_attr( $qte ),
			/* translators: %s : article. */
			esc_attr( sprintf( __( 'Quantité pour %s', 'espace-traiteur' ), $article['titre'] ) )
		);
		echo '</div></div>';
		echo '</li>';
	}
}
