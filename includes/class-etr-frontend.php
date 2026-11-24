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
	 * Demande actuellement modifiée dans le formulaire.
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
	 * Connexion en plein écran : page autonome, sans l'en-tête ni le pied de page du thème.
	 *
	 * @param string $gabarit Gabarit prévu par le thème.
	 * @return string
	 */
	public static function gabarit_plein_ecran( $gabarit ) {
		if ( is_user_logged_in() || ! self::sur_page_espace() || ! get_option( 'etr_connexion_plein_ecran', '1' ) ) {
			return $gabarit;
		}
		return ETR_DIR . 'templates/connexion.php';
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
		if ( ! is_user_logged_in() && self::sur_page_espace() ) {
			$classes[] = 'etr-page-connexion';
			if ( get_option( 'etr_connexion_plein_ecran', '1' ) ) {
				$classes[] = 'etr-plein-ecran';
			}
		}
		return $classes;
	}

	/**
	 * Thème Hello Elementor : pas de titre de page au-dessus de l'écran de connexion.
	 *
	 * @param bool $afficher Valeur par défaut.
	 * @return bool
	 */
	public static function titre_theme( $afficher ) {
		return ( ! is_user_logged_in() && self::sur_page_espace() ) ? false : $afficher;
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
		if ( $demande_id && ( ! etr_est_gestionnaire() ) ) {
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

		$saisie  = isset( $_POST['etr'] ) && is_array( $_POST['etr'] ) ? wp_unslash( $_POST['etr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- assaini champ par champ.
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
					if ( (int) $ligne['article_id'] === (int) $article_id && (int) $ligne['quantite'] > 0 ) {
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
	 * Shortcode [espace_traiteur].
	 *
	 * @return string
	 */
	public static function shortcode() {
		ob_start();
		echo '<div class="etr-espace' . ( is_user_logged_in() ? '' : ' etr-espace--auth' ) . '">';

		if ( ! is_user_logged_in() ) {
			ETR_Comptes::vue();
		} elseif ( ! etr_est_traiteur() ) {
			echo '<p class="etr-notice etr-notice--erreur">' . esc_html__( 'Cet espace est réservé aux traiteurs partenaires.', 'espace-traiteur' ) . '</p>';
		} else {
			$vue = isset( $_GET['etr_vue'] ) ? sanitize_key( wp_unslash( $_GET['etr_vue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( self::$erreurs ) {
				if ( ! empty( $_POST['demande_id'] ) ) {
					$vue       = 'modifier';
					$_GET['id'] = absint( $_POST['demande_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
				} else {
					$vue = 'nouvelle';
				}
			}

			self::entete();
			self::messages();

			if ( 'nouvelle' === $vue ) {
				self::vue_formulaire();
			} elseif ( 'modifier' === $vue && isset( $_GET['id'] ) ) {
				self::vue_formulaire( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			} elseif ( 'demande' === $vue && isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				self::vue_demande( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			} else {
				self::vue_tableau_de_bord();
			}
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * En-tête de l'espace.
	 */
	private static function entete() {
		$user = wp_get_current_user();
		echo '<div class="etr-entete">';
		/* translators: %s : nom du traiteur. */
		echo '<p class="etr-bonjour">' . esc_html( sprintf( __( 'Bonjour %s', 'espace-traiteur' ), etr_nom( $user ) ) ) . '</p>';
		echo '<nav class="etr-nav">';
		echo '<a class="etr-btn etr-btn--secondaire" href="' . esc_url( etr_url() ) . '">' . esc_html__( 'Mes demandes', 'espace-traiteur' ) . '</a>';
		echo '<a class="etr-btn" href="' . esc_url( etr_url( array( 'etr_vue' => 'nouvelle' ) ) ) . '">' . esc_html__( 'Nouvelle demande', 'espace-traiteur' ) . '</a>';
		echo '<a class="etr-lien" href="' . esc_url( wp_logout_url( etr_url() ) ) . '">' . esc_html__( 'Déconnexion', 'espace-traiteur' ) . '</a>';
		echo '</nav></div>';
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
			echo '<p class="etr-notice etr-notice--succes">' . esc_html( $textes[ $msg ] ) . '</p>';
		}
		if ( self::$erreurs ) {
			echo '<div class="etr-notice etr-notice--erreur"><ul>';
			foreach ( self::$erreurs as $erreur ) {
				echo '<li>' . esc_html( $erreur ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * Liste des demandes du traiteur connecté.
	 */
	private static function vue_tableau_de_bord() {
		$page = isset( $_GET['etr_page'] ) ? max( 1, absint( $_GET['etr_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$args = array(
			'post_type'      => 'etr_demande',
			'post_status'    => 'publish',
			'author'         => get_current_user_id(),
			'posts_per_page' => 20,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$demandes = new WP_Query( $args );

		// Page au-delà de la dernière : WordPress ne calcule pas le nombre de pages quand
		// la page demandée est vide, on revient donc sur la dernière page existante.
		if ( ! $demandes->posts && $page > 1 ) {
			$total         = (int) count_user_posts( get_current_user_id(), 'etr_demande' );
			$page          = max( 1, (int) ceil( $total / $args['posts_per_page'] ) );
			$args['paged'] = $page;
			$demandes      = new WP_Query( $args );
		}

		echo '<h2>' . esc_html__( 'Mes demandes', 'espace-traiteur' ) . '</h2>';

		if ( ! $demandes->posts ) {
			echo '<p>' . esc_html__( 'Vous n’avez encore fait aucune demande.', 'espace-traiteur' ) . '</p>';
			return;
		}

		echo '<div class="etr-table-wrap"><table class="etr-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Réf.', 'espace-traiteur' ) . '</th>';
		echo '<th>' . esc_html__( 'Événement', 'espace-traiteur' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'espace-traiteur' ) . '</th>';
		echo '<th>' . esc_html__( 'Statut', 'espace-traiteur' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		foreach ( $demandes->posts as $demande ) {
			$url = etr_url(
				array(
					'etr_vue' => 'demande',
					'id'      => $demande->ID,
				)
			);
			echo '<tr>';
			echo '<td>#' . (int) $demande->ID . '</td>';
			echo '<td>' . esc_html( get_the_title( $demande ) ) . '</td>';
			echo '<td>' . esc_html( etr_format_date( get_post_meta( $demande->ID, '_etr_date_evenement', true ) ) ) . '</td>';
			echo '<td>' . etr_statut_badge( etr_get_statut( $demande->ID ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td><a href="' . esc_url( $url ) . '">' . esc_html__( 'Voir', 'espace-traiteur' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		if ( $demandes->max_num_pages > 1 ) {
			$grand = 999999999;
			$liens = paginate_links(
				array(
					'base'      => str_replace( $grand, '%#%', esc_url( etr_url( array( 'etr_page' => $grand ) ) ) ),
					'format'    => '',
					'current'   => $page,
					'total'     => $demandes->max_num_pages,
					'type'      => 'list',
					'prev_text' => __( '« Précédent', 'espace-traiteur' ),
					'next_text' => __( 'Suivant »', 'espace-traiteur' ),
				)
			);
			if ( $liens ) {
				echo '<nav class="etr-pagination" aria-label="' . esc_attr__( 'Navigation des demandes', 'espace-traiteur' ) . '">' . wp_kses_post( $liens ) . '</nav>';
			}
		}
	}

	/**
	 * Détail d'une demande.
	 *
	 * @param int $demande_id ID.
	 */
	private static function vue_demande( $demande_id ) {
		$demande = get_post( $demande_id );
		if ( ! $demande || 'etr_demande' !== $demande->post_type || ( (int) $demande->post_author !== get_current_user_id() && ! etr_est_gestionnaire() ) ) {
			echo '<p class="etr-notice etr-notice--erreur">' . esc_html__( 'Demande introuvable.', 'espace-traiteur' ) . '</p>';
			return;
		}

		$statut = etr_get_statut( $demande_id );
		/* translators: 1 : numéro, 2 : nom de l'événement. */
		echo '<h2>' . esc_html( sprintf( __( 'Demande #%1$d — %2$s', 'espace-traiteur' ), $demande_id, get_the_title( $demande ) ) ) . '</h2>';
		echo '<p>' . etr_statut_badge( $statut ) . ' <span class="etr-muted">' . esc_html( sprintf( /* translators: %s : date. */ __( 'envoyée le %s', 'espace-traiteur' ), get_the_date( '', $demande ) ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$reponse = get_post_meta( $demande_id, '_etr_reponse', true );
		if ( $reponse ) {
			echo '<div class="etr-reponse"><strong>' . esc_html__( 'Message de notre équipe', 'espace-traiteur' ) . '</strong><p>' . nl2br( esc_html( $reponse ) ) . '</p></div>';
		}

		echo '<dl class="etr-details">';
		foreach ( etr_champs_demande() as $cle => $champ ) {
			echo '<dt>' . esc_html( $champ['label'] ) . '</dt><dd>' . etr_valeur_affichee( $demande_id, $cle, $champ['type'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</dl>';

		echo '<h3>' . esc_html__( 'Articles demandés', 'espace-traiteur' ) . '</h3>';
		echo '<div class="etr-table-wrap">' . etr_lignes_html( $demande_id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( 'en_attente' === $statut && (int) $demande->post_author === get_current_user_id() ) {
			echo '<p><a class="etr-btn etr-btn--secondaire" href="' . esc_url( etr_url( array( 'etr_vue' => 'modifier', 'id' => $demande_id ) ) ) . '">' . esc_html__( 'Modifier la demande', 'espace-traiteur' ) . '</a></p>';
			echo '<form method="post" class="etr-annuler" onsubmit="return confirm(\'' . esc_js( __( 'Annuler cette demande ?', 'espace-traiteur' ) ) . '\');">';
			wp_nonce_field( 'etr_annuler_' . $demande_id, 'etr_nonce' );
			echo '<input type="hidden" name="etr_action" value="annuler_demande">';
			echo '<input type="hidden" name="demande_id" value="' . (int) $demande_id . '">';
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
		echo '<h2>' . esc_html( $demande ? __( 'Modifier la demande', 'espace-traiteur' ) : __( 'Nouvelle demande', 'espace-traiteur' ) ) . '</h2>';
		echo '<form method="post" class="etr-form" action="' . esc_url( etr_url( array( 'etr_vue' => 'nouvelle' ) ) ) . '">';
		if ( $demande ) {
			wp_nonce_field( 'etr_modifier_' . $demande_id, 'etr_nonce' );
			echo '<input type="hidden" name="etr_action" value="modifier_demande">';
			echo '<input type="hidden" name="demande_id" value="' . (int) $demande_id . '">';
		} else {
			wp_nonce_field( 'etr_nouvelle_demande', 'etr_nonce' );
			echo '<input type="hidden" name="etr_action" value="nouvelle_demande">';
		}

		$champs   = etr_champs_demande();
		$sections = array(
			__( 'Votre événement', 'espace-traiteur' ) => array( 'evenement', 'date_evenement', 'nb_invites', 'lieu', 'date_livraison', 'date_reprise', 'contact' ),
		);

		foreach ( $sections as $titre => $cles ) {
			echo '<fieldset class="etr-fieldset"><legend>' . esc_html( $titre ) . '</legend><div class="etr-grille">';
			foreach ( $cles as $cle ) {
				if ( isset( $champs[ $cle ] ) ) {
					self::champ( $cle, $champs[ $cle ] );
				}
			}
			echo '</div></fieldset>';
		}

		self::catalogue();

		echo '<fieldset class="etr-fieldset"><legend>' . esc_html__( 'Compléments', 'espace-traiteur' ) . '</legend><div class="etr-grille">';
		foreach ( $champs as $cle => $champ ) {
			if ( ! in_array( $cle, $sections[ __( 'Votre événement', 'espace-traiteur' ) ], true ) ) {
				self::champ( $cle, $champ );
			}
		}
		echo '</div></fieldset>';

		echo '<p><button type="submit" class="etr-btn">' . esc_html__( 'Envoyer la demande', 'espace-traiteur' ) . '</button></p>';
		echo '</form>';
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
		$classe = 'textarea' === $champ['type'] ? 'etr-champ etr-champ--large' : 'etr-champ';

		echo '<p class="' . esc_attr( $classe ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $champ['label'] ) . ( $requis ? ' <abbr title="' . esc_attr__( 'obligatoire', 'espace-traiteur' ) . '">*</abbr>' : '' ) . '</label>';

		if ( 'textarea' === $champ['type'] ) {
			printf( '<textarea id="%1$s" name="etr[%2$s]" rows="3"%3$s>%4$s</textarea>', esc_attr( $id ), esc_attr( $cle ), $requis ? ' required' : '', esc_textarea( $valeur ) );
		} else {
			$extra = '';
			if ( 'number' === $champ['type'] ) {
				$extra = ' min="0" step="1"';
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
	 */
	private static function catalogue() {
		$articles = etr_catalogue();

		// Après une erreur, les dates sont connues : on affiche directement les disponibilités.
		list( $debut, $fin ) = etr_periode( self::ancienne_valeur( 'date_evenement' ), self::ancienne_valeur( 'date_livraison' ), self::ancienne_valeur( 'date_reprise' ) );
		$reservees           = $debut ? etr_quantites_reservees( $debut, $fin, self::$demande_modifiee_id ) : null;

		echo '<fieldset class="etr-fieldset etr-catalogue"><legend>' . esc_html__( 'Mobilier, art de la table et décoration', 'espace-traiteur' ) . '</legend>';

		if ( ! $articles ) {
			echo '<p>' . esc_html__( 'Le catalogue est vide pour le moment : décrivez vos besoins dans « Autres besoins ».', 'espace-traiteur' ) . '</p></fieldset>';
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

		echo '<p class="etr-dispo-info" aria-live="polite">' . esc_html( $debut ? self::texte_periode( $debut, $fin ) : __( 'Choisissez la date de l’événement pour voir les quantités disponibles.', 'espace-traiteur' ) ) . '</p>';

		foreach ( $familles as $famille => $sous_categories ) {
			$total  = 0;
			$ouvert = false;
			foreach ( $sous_categories as $liste ) {
				$total += count( $liste );
				foreach ( $liste as $article ) {
					if ( '' !== self::ancienne_quantite( $article['id'] ) ) {
						$ouvert = true;
					}
				}
			}
			echo '<details class="etr-categorie"' . ( $ouvert ? ' open' : '' ) . '><summary>' . esc_html( $famille ) . ' <span class="etr-muted">(' . (int) $total . ')</span></summary>';
			foreach ( $sous_categories as $sous => $liste ) {
				if ( '' !== $sous ) {
					echo '<h4 class="etr-sous-categorie">' . esc_html( $sous ) . '</h4>';
				}
				echo '<ul class="etr-articles">';
				foreach ( $liste as $article ) {
					self::ligne_article( $article, $reservees );
				}
				echo '</ul>';
			}
			echo '</details>';
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

		if ( null !== $reservees || $indispo ) {
			$texte_dispo = etr_texte_disponibilite( $article['id'], $dispo );
		} elseif ( null !== $stock ) {
			/* translators: %s : stock total. */
			$texte_dispo = sprintf( __( 'Stock total : %s', 'espace-traiteur' ), number_format_i18n( $stock ) . ( $article['unite'] ? ' ' . $article['unite'] : '' ) );
		} else {
			$texte_dispo = '';
		}

		echo '<li class="etr-article' . ( $indispo ? ' etr-article--indispo' : '' ) . '" data-article="' . (int) $article['id'] . '">';
		echo wp_kses_post( $article['image'] );
		echo '<div class="etr-article__infos"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $article['titre'] ) . '</strong></label>';
		if ( $article['reference'] ) {
			echo ' <span class="etr-muted">' . esc_html( $article['reference'] ) . '</span>';
		}
		if ( $article['description'] ) {
			echo '<p class="etr-article__desc">' . esc_html( $article['description'] ) . '</p>';
		}
		if ( $article['prix_html'] || $article['url'] ) {
			echo '<p class="etr-article__meta">';
			if ( $article['prix_html'] ) {
				echo '<span class="etr-article__prix">' . wp_kses_post( $article['prix_html'] ) . '</span> ';
			}
			if ( $article['url'] ) {
				echo '<a href="' . esc_url( $article['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir la fiche', 'espace-traiteur' ) . '</a>';
			}
			echo '</p>';
		}
		echo '<p class="etr-muted etr-dispo">' . esc_html( $texte_dispo ) . '</p>';
		echo '</div>';
		printf(
			'<input type="number" class="etr-article__qte" id="%1$s" name="quantites[%2$d]" min="0" step="1"%3$s value="%4$s" placeholder="0" aria-label="%5$s">',
			esc_attr( $id ),
			(int) $article['id'],
			( null !== $dispo ? ' max="' . (int) $dispo . '"' : '' ) . ( $indispo ? ' disabled' : '' ), // phpcs:ignore WordPress.Security.EscapeOutput -- entiers.
			esc_attr( $indispo ? '' : self::ancienne_quantite( $article['id'] ) ),
			/* translators: %s : article. */
			esc_attr( sprintf( __( 'Quantité pour %s', 'espace-traiteur' ), $article['titre'] ) )
		);
		echo '</li>';
	}
}
