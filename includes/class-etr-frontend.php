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
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'espace_traiteur', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'traiter_formulaires' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_etr_disponibilites', array( __CLASS__, 'ajax_disponibilites' ) );
	}

	/**
	 * Styles et script sur la page contenant le shortcode.
	 */
	public static function assets() {
		if ( ! is_singular() || ! has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'espace_traiteur' ) ) {
			return;
		}
		wp_enqueue_style( 'espace-traiteur', ETR_URL . 'assets/css/espace-traiteur.css', array(), ETR_VERSION );

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
		list( $debut, $fin ) = etr_periode( $dates['date_evenement'], $dates['date_livraison'], $dates['date_reprise'] );
		if ( ! $debut ) {
			wp_send_json_error( array( 'message' => __( 'Choisissez la date de l’événement pour voir les quantités disponibles.', 'espace-traiteur' ) ) );
		}

		wp_send_json_success(
			array(
				'periode'  => self::texte_periode( $debut, $fin ),
				'articles' => self::disponibilites_catalogue( etr_quantites_reservees( $debut, $fin ) ),
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
		} elseif ( 'annuler_demande' === $action ) {
			self::annuler_demande();
		}
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

		// Articles du catalogue, contrôlés selon la disponibilité sur la période.
		list( $debut, $fin ) = etr_periode( $donnees['date_evenement'], $donnees['date_livraison'], $donnees['date_reprise'] );
		$reservees           = $debut ? etr_quantites_reservees( $debut, $fin ) : array();

		$quantites = isset( $_POST['quantites'] ) && is_array( $_POST['quantites'] ) ? wp_unslash( $_POST['quantites'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- converti en entiers ci-dessous.
		$lignes    = array();
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
			update_post_meta( $demande_id, '_etr_' . $cle, $valeur );
		}
		update_post_meta( $demande_id, '_etr_lignes', $lignes );
		update_post_meta( $demande_id, '_etr_statut', 'en_attente' );

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
			return '';
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
		echo '<div class="etr-espace">';

		if ( ! is_user_logged_in() ) {
			self::vue_connexion();
		} elseif ( ! etr_est_traiteur() ) {
			echo '<p class="etr-notice etr-notice--erreur">' . esc_html__( 'Cet espace est réservé aux traiteurs partenaires.', 'espace-traiteur' ) . '</p>';
		} else {
			$vue = isset( $_GET['etr_vue'] ) ? sanitize_key( wp_unslash( $_GET['etr_vue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( self::$erreurs ) {
				$vue = 'nouvelle';
			}

			self::entete();
			self::messages();

			if ( 'nouvelle' === $vue ) {
				self::vue_formulaire();
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
	 * Formulaire de connexion.
	 */
	private static function vue_connexion() {
		echo '<div class="etr-connexion">';
		echo '<h2>' . esc_html__( 'Connexion à votre espace traiteur', 'espace-traiteur' ) . '</h2>';
		wp_login_form(
			array(
				'redirect'       => etr_url(),
				'label_username' => __( 'Identifiant ou e-mail', 'espace-traiteur' ),
				'label_log_in'   => __( 'Se connecter', 'espace-traiteur' ),
			)
		);
		echo '<p><a href="' . esc_url( wp_lostpassword_url( etr_url() ) ) . '">' . esc_html__( 'Mot de passe oublié ?', 'espace-traiteur' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * En-tête de l'espace.
	 */
	private static function entete() {
		$user = wp_get_current_user();
		echo '<div class="etr-entete">';
		/* translators: %s : nom du traiteur. */
		echo '<p class="etr-bonjour">' . esc_html( sprintf( __( 'Bonjour %s', 'espace-traiteur' ), $user->display_name ) ) . '</p>';
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
		$demandes = get_posts(
			array(
				'post_type'      => 'etr_demande',
				'post_status'    => 'publish',
				'author'         => get_current_user_id(),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		echo '<h2>' . esc_html__( 'Mes demandes', 'espace-traiteur' ) . '</h2>';

		if ( ! $demandes ) {
			echo '<p>' . esc_html__( 'Vous n’avez encore fait aucune demande.', 'espace-traiteur' ) . '</p>';
			return;
		}

		echo '<div class="etr-table-wrap"><table class="etr-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Réf.', 'espace-traiteur' ) . '</th>';
		echo '<th>' . esc_html__( 'Événement', 'espace-traiteur' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'espace-traiteur' ) . '</th>';
		echo '<th>' . esc_html__( 'Statut', 'espace-traiteur' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		foreach ( $demandes as $demande ) {
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
	private static function vue_formulaire() {
		echo '<h2>' . esc_html__( 'Nouvelle demande', 'espace-traiteur' ) . '</h2>';
		echo '<form method="post" class="etr-form" action="' . esc_url( etr_url( array( 'etr_vue' => 'nouvelle' ) ) ) . '">';
		wp_nonce_field( 'etr_nouvelle_demande', 'etr_nonce' );
		echo '<input type="hidden" name="etr_action" value="nouvelle_demande">';

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
		$reservees           = $debut ? etr_quantites_reservees( $debut, $fin ) : null;

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
