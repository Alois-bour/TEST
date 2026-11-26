<?php
/**
 * Le traiteur informé en direct (fonctions activables) :
 * - notifications sur l'iPhone du traiteur (« push_traiteur ») ;
 * - « En route » avec heure d'arrivée estimée (« en_route ») ;
 * - messages par demande entre le bureau et le traiteur (« messages ») ;
 * - commander à nouveau à partir d'une ancienne demande (« recommander »).
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions « traiteur en direct ».
 */
class ETR_Traiteur_Direct {

	const META_MESSAGES = '_etr_messages';
	const META_EN_ROUTE = '_etr_en_route';
	const MAX_MESSAGE   = 2000;

	/**
	 * Hooks.
	 */
	public static function init() {
		// Notifications sur l'iPhone du traiteur.
		add_action( 'etr_traiteur_haut', array( __CLASS__, 'carte_push' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script_push' ) );
		add_action( 'wp_ajax_etr_traiteur_push', array( __CLASS__, 'ajax_push' ) );
		add_action( 'etr_statut_change', array( __CLASS__, 'push_statut' ), 20, 3 );
		add_action( 'etr_livree', array( __CLASS__, 'push_livree' ), 20 );

		// En route.
		add_action( 'etr_tournee_actions', array( __CLASS__, 'bouton_en_route' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'etr_traiteur_demande_apres_suivi', array( __CLASS__, 'suivi_en_route' ), 4 );

		// Messages.
		add_action( 'etr_gestion_fiche_echanges', array( __CLASS__, 'panneau_messages_equipe' ) );
		add_action( 'etr_gestion_action', array( __CLASS__, 'action_equipe' ) );
		add_action( 'etr_traiteur_demande_fin', array( __CLASS__, 'panneau_messages_traiteur' ), 20 );
		add_action( 'etr_traiteur_action', array( __CLASS__, 'action_traiteur' ) );

		// Commander à nouveau.
		add_action( 'etr_traiteur_demande_fin', array( __CLASS__, 'bouton_recommander' ), 30 );
		add_filter( 'etr_modele_demande', array( __CLASS__, 'modele' ) );
	}

	/**
	 * Traiteur d'une demande.
	 *
	 * @param int $id Demande.
	 * @return WP_User|false
	 */
	private static function traiteur( $id ) {
		return get_userdata( (int) get_post_field( 'post_author', $id ) );
	}

	/**
	 * Prévient le traiteur d'une demande : e-mail et notification sur l'iPhone.
	 *
	 * @param int    $id    Demande.
	 * @param string $titre Titre court (notification, objet de l'e-mail).
	 * @param string $texte Texte.
	 * @param bool   $mail  Envoyer aussi un e-mail.
	 */
	public static function prevenir( $id, $titre, $texte, $mail = true ) {
		$user = self::traiteur( $id );
		if ( ! $user ) {
			return;
		}
		ETR_Push::notifier_traiteur( $user->ID, $titre, $texte, $id );
		if ( $mail && is_email( $user->user_email ) ) {
			/* translators: 1 : site, 2 : numéro, 3 : titre. */
			$objet  = sprintf( __( '[%1$s] Demande #%2$d : %3$s', 'espace-traiteur' ), etr_nom_site(), $id, $titre );
			/* translators: %s : nom. */
			$corps  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n" . $texte . "\n\n";
			$corps .= __( 'Voir la demande :', 'espace-traiteur' ) . ' ' . etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) . "\n";
			etr_envoyer_mail( $user->user_email, $objet, $corps );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Notifications sur l'iPhone du traiteur                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Notifications du traiteur possibles : fonction active, serveur capable, et service worker
	 * avec sa propre portée. Sans permaliens, l'espace traiteur et l'application de l'équipe
	 * vivent tous deux à « / » : un service worker remplacerait l'autre.
	 *
	 * @return bool
	 */
	private static function push_possible() {
		return ETR_Modules::actif( 'push_traiteur' ) && ETR_Push::disponible() && get_option( 'permalink_structure' )
			&& 0 !== strpos( ETR_PWA::perimetre_traiteur(), trailingslashit( ETR_PWA::perimetre() ) ) && '/' !== ETR_PWA::perimetre_traiteur();
	}

	/**
	 * Carte « Recevoir les notifications » (affichée par traiteur-push.js si l'appareil le permet).
	 */
	public static function carte_push() {
		if ( ! self::push_possible() ) {
			return;
		}
		echo '<aside class="etr-installer" id="etr-push-traiteur" hidden><span class="etr-installer__icone" aria-hidden="true">' . ETR_Frontend::icone( 'message' ) . '</span><p><strong>' . esc_html__( 'Être prévenu sur cet iPhone', 'espace-traiteur' ) . '</strong><span data-push-etat>' . esc_html__( 'Demande validée, livreur en route, livraison, messages de notre équipe.', 'espace-traiteur' ) . '</span></p><button type="button" class="etr-btn" data-push-activer>' . esc_html__( 'Activer', 'espace-traiteur' ) . '</button><button type="button" class="etr-installer__fermer" aria-label="' . esc_attr__( 'Fermer', 'espace-traiteur' ) . '">×</button></aside>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
	}

	/**
	 * Script d'abonnement (page de l'espace, traiteur connecté).
	 */
	public static function script_push() {
		if ( ! self::push_possible() || ! etr_est_traiteur() || ! ETR_Frontend::sur_page_espace() ) {
			return;
		}
		wp_enqueue_script( 'espace-traiteur-push', ETR_URL . 'assets/js/traiteur-push.js', array(), ETR_VERSION, true );
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		wp_localize_script(
			'espace-traiteur-push',
			'etrTraiteurPush',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'etr_traiteur_push' ),
				'cle'    => ETR_Push::cle_publique(),
				'sw'     => ETR_PWA::url( 'sw-traiteur.js' ),
				'portee' => ETR_PWA::perimetre_traiteur(),
				'ios'    => (bool) preg_match( '/iPhone|iPad|iPod/i', $ua ),
			)
		);
	}

	/**
	 * Enregistre ou retire l'appareil du traiteur connecté.
	 */
	public static function ajax_push() {
		check_ajax_referer( 'etr_traiteur_push', 'nonce' );
		if ( ! ETR_Modules::actif( 'push_traiteur' ) || ! etr_est_traiteur() ) {
			wp_send_json_error( array( 'message' => __( 'Notifications indisponibles.', 'espace-traiteur' ) ), 403 );
		}
		$endpoint = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		if ( isset( $_POST['quoi'] ) && 'retirer' === $_POST['quoi'] ) {
			ETR_Push::retirer_appareil( get_current_user_id(), $endpoint );
			wp_send_json_success();
		}
		$p256dh = isset( $_POST['p256dh'] ) ? sanitize_text_field( wp_unslash( $_POST['p256dh'] ) ) : '';
		$auth   = isset( $_POST['auth'] ) ? sanitize_text_field( wp_unslash( $_POST['auth'] ) ) : '';
		$deja   = wp_list_pluck( ETR_Push::appareils( get_current_user_id() ), 'endpoint' );
		if ( in_array( $endpoint, $deja, true ) ) {
			wp_send_json_success();
		}
		$ok = ETR_Push::ajouter_appareil( get_current_user_id(), $endpoint, $p256dh, $auth, 'iPhone' );
		if ( is_wp_error( $ok ) ) {
			wp_send_json_error( array( 'message' => $ok->get_error_message() ), 400 );
		}
		wp_send_json_success();
	}

	/**
	 * Changement de statut : notification au traiteur (l'e-mail part déjà, ETR_Notifications).
	 *
	 * @param int    $id      Demande.
	 * @param string $ancien  Ancien statut.
	 * @param string $nouveau Nouveau statut.
	 */
	public static function push_statut( $id, $ancien, $nouveau ) {
		$textes = array(
			'validee'  => __( 'Votre demande est validée', 'espace-traiteur' ),
			'refusee'  => __( 'Votre demande n’a pas pu être acceptée', 'espace-traiteur' ),
			'cloturee' => __( 'Matériel repris, demande clôturée', 'espace-traiteur' ),
		);
		$user = self::traiteur( $id );
		if ( $user && isset( $textes[ $nouveau ] ) ) {
			ETR_Push::notifier_traiteur( $user->ID, $textes[ $nouveau ], get_the_title( $id ), $id );
		}
	}

	/**
	 * Livraison enregistrée.
	 *
	 * @param int $id Demande.
	 */
	public static function push_livree( $id ) {
		$user      = self::traiteur( $id );
		$livraison = ETR_Operations::livraison( $id );
		if ( $user ) {
			/* translators: %s : personne qui a signé. */
			ETR_Push::notifier_traiteur( $user->ID, __( 'Matériel livré', 'espace-traiteur' ), get_the_title( $id ) . ( $livraison ? ' · ' . sprintf( __( 'réceptionné par %s', 'espace-traiteur' ), $livraison['signataire'] ) : '' ), $id );
		}
	}

	/* ------------------------------------------------------------------ */
	/* En route                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Routes : POST terrain/{id}/en-route (livreurs).
	 */
	public static function routes() {
		if ( ! ETR_Modules::actif( 'en_route' ) ) {
			return;
		}
		register_rest_route(
			ETR_Push::API,
			'/terrain/(?P<id>\d+)/en-route',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'api_en_route' ),
				'permission_callback' => function () {
					return ETR_Operations::autorise( 'livreur' );
				},
			)
		);
	}

	/**
	 * Départ vers le traiteur : { etape, minutes }.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_en_route( $requete ) {
		$id      = (int) $requete['id'];
		$etape   = 'reprise' === $requete->get_param( 'etape' ) ? 'reprise' : 'livraison';
		$minutes = max( 5, min( 240, (int) $requete->get_param( 'minutes' ) ) );
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			return new WP_Error( 'etr_demande', __( 'Demande introuvable.', 'espace-traiteur' ), array( 'status' => 404 ) );
		}
		$permis = 'livraison' === $etape ? array( 'validee' ) : array( 'livree' );
		if ( ! in_array( etr_get_statut( $id ), $permis, true ) ) {
			return new WP_Error( 'etr_statut', __( 'Cette étape est déjà faite.', 'espace-traiteur' ), array( 'status' => 409 ) );
		}
		$arrivee = time() + $minutes * MINUTE_IN_SECONDS;
		$routes  = get_post_meta( $id, self::META_EN_ROUTE, true );
		$routes  = is_array( $routes ) ? $routes : array();
		$routes[ $etape ] = array(
			'le'      => time(),
			'arrivee' => $arrivee,
			'par'     => get_current_user_id(),
		);
		update_post_meta( $id, self::META_EN_ROUTE, $routes );
		$heure = wp_date( 'H:i', $arrivee );
		/* translators: %s : heure. */
		ETR_Historique::ajouter( $id, 'en_route', sprintf( 'livraison' === $etape ? __( 'Livreur en route, arrivée prévue vers %s', 'espace-traiteur' ) : __( 'Livreur en route pour la reprise, arrivée prévue vers %s', 'espace-traiteur' ), $heure ) );
		self::prevenir(
			$id,
			'livraison' === $etape ? __( 'Votre livreur est en route', 'espace-traiteur' ) : __( 'Notre livreur arrive pour la reprise', 'espace-traiteur' ),
			/* translators: 1 : événement, 2 : heure. */
			sprintf( __( '%1$s : arrivée prévue vers %2$s.', 'espace-traiteur' ), get_the_title( $id ), $heure )
		);
		return rest_ensure_response(
			array(
				'arrivee' => $heure,
				/* translators: %s : heure. */
				'message' => sprintf( __( 'Traiteur prévenu : arrivée vers %s.', 'espace-traiteur' ), $heure ),
			)
		);
	}

	/**
	 * Départ annoncé pour une étape (pas encore faite, annoncé il y a moins de 8 h).
	 *
	 * @param int    $id    Demande.
	 * @param string $etape livraison | reprise.
	 * @return array|null
	 */
	public static function en_route( $id, $etape ) {
		$routes = get_post_meta( $id, self::META_EN_ROUTE, true );
		if ( ! is_array( $routes ) || empty( $routes[ $etape ]['le'] ) || time() - (int) $routes[ $etape ]['le'] > 8 * HOUR_IN_SECONDS ) {
			return null;
		}
		$faite = 'livraison' === $etape ? ETR_Operations::livraison( $id ) : ETR_Operations::reprise( $id );
		return $faite ? null : $routes[ $etape ];
	}

	/**
	 * Bouton « En route » sur une étape de la tournée (livreurs).
	 *
	 * @param int    $id    Demande.
	 * @param string $etape livraison | reprise.
	 */
	public static function bouton_en_route( $id, $etape ) {
		if ( ! ETR_Modules::actif( 'en_route' ) || ! ETR_Equipe::livreur() ) {
			return;
		}
		$deja = self::en_route( $id, $etape );
		/* translators: %s : heure. */
		$texte = $deja ? sprintf( __( 'En route · arrivée %s', 'espace-traiteur' ), wp_date( 'H:i', (int) $deja['arrivee'] ) ) : __( 'En route', 'espace-traiteur' );
		echo '<button type="button" class="etr-btn etr-btn--discret" data-en-route="' . (int) $id . '" data-etape="' . esc_attr( $etape ) . '">' . ETR_Frontend::icone( 'camion' ) . '<span>' . esc_html( $texte ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
	}

	/**
	 * Suivi du traiteur : « Livreur en route, arrivée vers… ».
	 *
	 * @param int $id Demande.
	 */
	public static function suivi_en_route( $id ) {
		if ( ! ETR_Modules::actif( 'en_route' ) ) {
			return;
		}
		foreach ( array( 'livraison', 'reprise' ) as $etape ) {
			$route = self::en_route( $id, $etape );
			if ( $route ) {
				echo '<p class="etr-notice etr-notice--info etr-en-route">' . ETR_Frontend::icone( 'lieu' ) . '<span>' . esc_html( // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
					sprintf(
						/* translators: %s : heure. */
						'livraison' === $etape ? __( 'Votre livreur est en route : arrivée prévue vers %s.', 'espace-traiteur' ) : __( 'Notre livreur arrive pour la reprise vers %s.', 'espace-traiteur' ),
						wp_date( 'H:i', (int) $route['arrivee'] )
					)
				) . '</span></p>';
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Messages                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Messages d'une demande.
	 *
	 * @param int $id Demande.
	 * @return array[] { le, par, equipe (bool), texte }
	 */
	public static function messages( $id ) {
		$messages = get_post_meta( $id, self::META_MESSAGES, true );
		return is_array( $messages ) ? $messages : array();
	}

	/**
	 * Ajoute un message.
	 *
	 * @param int    $id     Demande.
	 * @param string $texte  Texte.
	 * @param bool   $equipe Écrit par l'équipe.
	 */
	private static function ajouter_message( $id, $texte, $equipe ) {
		$messages   = self::messages( $id );
		$messages[] = array(
			'le'     => time(),
			'par'    => get_current_user_id(),
			'equipe' => (bool) $equipe,
			'texte'  => $texte,
		);
		update_post_meta( $id, self::META_MESSAGES, array_slice( $messages, -200 ) );
	}

	/**
	 * Fil de messages (HTML).
	 *
	 * @param int  $id          Demande.
	 * @param bool $cote_equipe Affiché à l'équipe.
	 * @return string
	 */
	private static function fil( $id, $cote_equipe ) {
		$messages = self::messages( $id );
		if ( ! $messages ) {
			return '<p class="etr-muted">' . esc_html__( 'Aucun message pour l’instant.', 'espace-traiteur' ) . '</p>';
		}
		$html = '<ol class="etr-messages">';
		foreach ( $messages as $message ) {
			$moi    = $cote_equipe === ! empty( $message['equipe'] );
			$auteur = ! empty( $message['equipe'] )
				? ( $cote_equipe ? etr_nom( get_userdata( (int) $message['par'] ) ) : __( 'Notre équipe', 'espace-traiteur' ) )
				: etr_nom( get_userdata( (int) $message['par'] ) );
			$html  .= '<li class="etr-message' . ( $moi ? ' etr-message--moi' : '' ) . '"><p>' . nl2br( esc_html( $message['texte'] ) ) . '</p><small>' . esc_html( $auteur . ' · ' . wp_date( 'j M à H:i', (int) $message['le'] ) ) . '</small></li>';
		}
		return $html . '</ol>';
	}

	/**
	 * Panneau « Messages avec le traiteur » de la fiche (bureau).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_messages_equipe( $id ) {
		if ( ! ETR_Modules::actif( 'messages' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		echo '<section class="etr-panneau" id="etr-messages"><h2 class="etr-panneau__titre">' . esc_html__( 'Messages avec le traiteur', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( self::messages( $id ) ) ) ) . '</span></h2>';
		echo self::fil( $id, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans fil().
		echo '<form method="post" class="etr-message-form" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) ) . '#etr-messages">';
		wp_nonce_field( 'etr_message_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="message"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
		echo '<label class="screen-reader-text" for="etr-message-texte">' . esc_html__( 'Message', 'espace-traiteur' ) . '</label><textarea id="etr-message-texte" name="etr_message" rows="2" maxlength="' . (int) self::MAX_MESSAGE . '" required placeholder="' . esc_attr__( 'Écrire au traiteur…', 'espace-traiteur' ) . '"></textarea>';
		echo '<button type="submit" class="etr-btn">' . esc_html__( 'Envoyer', 'espace-traiteur' ) . '</button></form></section>';
	}

	/**
	 * Message envoyé par le bureau (action de l'application, réservée au bureau).
	 *
	 * @param string $action Action.
	 */
	public static function action_equipe( $action ) {
		if ( 'message' !== $action || ! ETR_Modules::actif( 'messages' ) ) {
			return;
		}
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		check_admin_referer( 'etr_message_' . $id, 'etr_nonce' );
		if ( ! $id || ! ETR_Gestion::demande_visible( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$texte = isset( $_POST['etr_message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['etr_message'] ) ) ) : '';
		if ( '' !== $texte ) {
			$texte = mb_substr( $texte, 0, self::MAX_MESSAGE );
			self::ajouter_message( $id, $texte, true );
			self::prevenir( $id, __( 'Nouveau message de notre équipe', 'espace-traiteur' ), $texte );
		}
		wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => 'message_envoye' ) ) . '#etr-messages' );
		exit;
	}

	/**
	 * Panneau « Messages » de la demande, côté traiteur.
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_messages_traiteur( $id ) {
		if ( ! ETR_Modules::actif( 'messages' ) || ! etr_peut_voir_demande( $id ) ) {
			return;
		}
		echo '<section class="etr-panneau etr-messages-panneau" id="etr-messages"><h2 class="etr-panneau__titre">' . esc_html__( 'Messages avec notre équipe', 'espace-traiteur' ) . '</h2>';
		echo self::fil( $id, false ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans fil().
		echo '<form method="post" class="etr-message-form" action="' . esc_url( etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) ) . '#etr-messages">';
		wp_nonce_field( 'etr_message_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_action" value="message_demande"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
		echo '<label class="screen-reader-text" for="etr-message-texte">' . esc_html__( 'Message', 'espace-traiteur' ) . '</label><textarea id="etr-message-texte" name="etr_message" rows="2" maxlength="' . (int) self::MAX_MESSAGE . '" required placeholder="' . esc_attr__( 'Une question, une précision ?', 'espace-traiteur' ) . '"></textarea>';
		echo '<button type="submit" class="etr-btn">' . esc_html__( 'Envoyer', 'espace-traiteur' ) . '</button></form></section>';
	}

	/**
	 * Message envoyé par le traiteur.
	 *
	 * @param string $action Action du formulaire.
	 */
	public static function action_traiteur( $action ) {
		if ( 'message_demande' !== $action || ! ETR_Modules::actif( 'messages' ) ) {
			return;
		}
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		if ( ! isset( $_POST['etr_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['etr_nonce'] ) ), 'etr_message_' . $id ) ) {
			wp_die( esc_html__( 'Lien expiré : rechargez la page.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		if ( ! $id || 'etr_demande' !== get_post_type( $id ) || ! etr_peut_voir_demande( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$texte = isset( $_POST['etr_message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['etr_message'] ) ) ) : '';
		if ( '' !== $texte ) {
			$texte = mb_substr( $texte, 0, self::MAX_MESSAGE );
			self::ajouter_message( $id, $texte, false );
			$nom = etr_nom( wp_get_current_user() );
			/* translators: %s : traiteur. */
			ETR_Push::notifier( 'bureau', sprintf( __( 'Message de %s', 'espace-traiteur' ), $nom ), get_the_title( $id ) . ' · ' . $texte, $id, ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) . '#etr-messages', 'message' );
			$equipe = apply_filters( 'etr_email_equipe', is_email( get_option( 'etr_email_equipe' ) ) ? get_option( 'etr_email_equipe' ) : get_option( 'admin_email' ) );
			/* translators: 1 : site, 2 : numéro, 3 : traiteur. */
			$objet = sprintf( __( '[%1$s] Demande #%2$d : message de %3$s', 'espace-traiteur' ), etr_nom_site(), $id, $nom );
			etr_envoyer_mail( $equipe, $objet, $texte . "\n\n" . __( 'Répondre :', 'espace-traiteur' ) . ' ' . ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) . "\n" );
		}
		wp_safe_redirect( etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) . '#etr-messages' );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Commander à nouveau                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Bouton sous le détail d'une demande.
	 *
	 * @param int $id Demande.
	 */
	public static function bouton_recommander( $id ) {
		if ( ! ETR_Modules::actif( 'recommander' ) || ! etr_peut_voir_demande( $id ) || ! etr_get_lignes( $id ) ) {
			return;
		}
		echo '<p class="etr-recommander"><a class="etr-btn etr-btn--secondaire" href="' . esc_url( etr_url( array( 'etr_vue' => 'nouvelle', 'depuis' => $id ) ) ) . '">' . ETR_Frontend::icone( 'plus' ) . '<span>' . esc_html__( 'Commander à nouveau', 'espace-traiteur' ) . '</span></a> <span class="etr-muted">' . esc_html__( 'Même événement et même matériel ; vous choisissez les nouvelles dates.', 'espace-traiteur' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
	}

	/**
	 * Demande servant de modèle au formulaire (seulement une demande du traiteur connecté).
	 *
	 * @param int $modele Modèle.
	 * @return int
	 */
	public static function modele( $modele ) {
		if ( ! ETR_Modules::actif( 'recommander' ) || empty( $_GET['depuis'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- lecture seule.
			return $modele;
		}
		$id = absint( $_GET['depuis'] ); // phpcs:ignore WordPress.Security.NonceVerification
		return etr_peut_voir_demande( $id ) ? $id : $modele;
	}
}
