<?php
/**
 * Notifications sur téléphone (Web Push) pour l'équipe.
 *
 * Chaque appareil où une personne active les notifications (iPhone avec l'application
 * installée sur l'écran d'accueil, iOS 16.4 ou plus ; ordinateur avec Chrome, Edge, Firefox
 * ou Safari) est enregistré sur son compte. Les messages sont chiffrés pour l'appareil
 * (RFC 8291) et signés par le site (VAPID, RFC 8292), sans dépendance externe : seule
 * l'extension OpenSSL de PHP est utilisée.
 *
 * Qui reçoit quoi :
 * - bureau : nouvelle demande, demande annulée par un traiteur ;
 * - préparateurs et livreurs : demande validée (à préparer, à livrer), puis annulée ou refusée.
 * La personne qui fait l'action n'est jamais notifiée de sa propre action.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notifications Web Push.
 */
class ETR_Push {

	const OPTION_CLES   = 'etr_push_vapid';
	const META          = 'etr_push_appareils';
	const API           = 'etr/v1';
	const MAX_APPAREILS = 10;
	const META_SANS     = 'etr_push_sans'; // Types de notifications désactivés par la personne.

	/**
	 * Services de notification acceptés (pas d'envoi vers une adresse quelconque).
	 */
	const SERVICES = array( 'push.apple.com', 'fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com' );

	/**
	 * Messages à envoyer en fin de requête : [ user_id, message ].
	 *
	 * @var array
	 */
	private static $file = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'etr_routes_api_equipe', array( __CLASS__, 'route_autorisee' ) );
		add_action( 'etr_demande_creee', array( __CLASS__, 'nouvelle_demande' ) );
		add_action( 'etr_demande_annulee', array( __CLASS__, 'demande_annulee' ) );
		add_action( 'etr_statut_change', array( __CLASS__, 'statut_change' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'vider_file' ), 100 );
	}

	/**
	 * Types de notifications : type => { groupe destinataire, libellé }.
	 *
	 * @return array[]
	 */
	public static function types() {
		$types = array(
			'nouvelle'         => array( 'bureau', __( 'Nouvelle demande d’un traiteur', 'espace-traiteur' ) ),
			'annulee_traiteur' => array( 'bureau', __( 'Demande annulée par un traiteur', 'espace-traiteur' ) ),
			'livree'           => array( 'bureau', __( 'Commande livrée et signée', 'espace-traiteur' ) ),
			'reprise'          => array( 'bureau', __( 'Matériel repris (avec ou sans écarts)', 'espace-traiteur' ) ),
			'incident'         => array( 'bureau', __( 'Incident signalé par le terrain', 'espace-traiteur' ) ),
			'validee'          => array( 'terrain', __( 'Commande validée par le bureau', 'espace-traiteur' ) ),
			'annulee'          => array( 'terrain', __( 'Commande validée puis annulée', 'espace-traiteur' ) ),
			'prete'            => array( 'livreur', __( 'Commande prête à livrer', 'espace-traiteur' ) ),
			'affectation'      => array( 'livreur', __( 'Livraison ou reprise qui vous est confiée', 'espace-traiteur' ) ),
			'rappel'           => array( 'terrain', __( 'Rappels de la veille (vers 17 h)', 'espace-traiteur' ) ),
		);
		// Fonctions activables (Espace traiteur › Fonctions).
		if ( ETR_Modules::actif( 'messages' ) ) {
			$types['message'] = array( 'bureau', __( 'Message d’un traiteur', 'espace-traiteur' ) );
		}
		if ( ETR_Modules::actif( 'devis' ) ) {
			$types['devis'] = array( 'bureau', __( 'Devis accepté par un traiteur', 'espace-traiteur' ) );
		}
		if ( ETR_Modules::actif( 'relances' ) ) {
			$types['relance'] = array( 'bureau', __( 'Relances : reprise en retard, acompte non reçu', 'espace-traiteur' ) );
		}
		return $types;
	}

	/**
	 * Types que la personne peut recevoir (selon ses rôles), avec son choix.
	 *
	 * @param int $user_id Compte.
	 * @return array[] type => { libellé, actif }
	 */
	public static function types_pour( $user_id ) {
		$sans  = self::types_desactives( $user_id );
		$types = array();
		foreach ( self::types() as $type => $config ) {
			if ( self::concerne( $user_id, $config[0] ) ) {
				$types[ $type ] = array(
					'libelle' => $config[1],
					'actif'   => ! in_array( $type, $sans, true ),
				);
			}
		}
		return $types;
	}

	/**
	 * Types désactivés par la personne.
	 *
	 * @param int $user_id Compte.
	 * @return string[]
	 */
	public static function types_desactives( $user_id ) {
		$sans = get_user_meta( $user_id, self::META_SANS, true );
		return is_array( $sans ) ? $sans : array();
	}

	/**
	 * Le serveur sait-il chiffrer et signer les notifications ?
	 *
	 * @return bool
	 */
	public static function disponible() {
		return function_exists( 'openssl_pkey_derive' ) && function_exists( 'openssl_pkey_new' ) && in_array( 'aes-128-gcm', openssl_get_cipher_methods(), true );
	}

	/* ------------------------------------------------------------------ */
	/* Clés du site (VAPID)                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Clés VAPID du site, créées au premier besoin.
	 *
	 * @return array|null { publique : clé publique brute en base64url, privee : PEM }
	 */
	public static function cles() {
		$cles = get_option( self::OPTION_CLES );
		if ( is_array( $cles ) && ! empty( $cles['publique'] ) && ! empty( $cles['privee'] ) ) {
			return $cles;
		}
		if ( ! self::disponible() ) {
			return null;
		}
		$paire = self::nouvelle_paire();
		if ( ! $paire ) {
			return null;
		}
		$cles = array(
			'publique' => self::b64( $paire['publique'] ),
			'privee'   => $paire['privee'],
		);
		update_option( self::OPTION_CLES, $cles, false );
		return $cles;
	}

	/**
	 * Clé publique du site (pour l'abonnement côté navigateur).
	 *
	 * @return string
	 */
	public static function cle_publique() {
		$cles = self::cles();
		return $cles ? $cles['publique'] : '';
	}

	/* ------------------------------------------------------------------ */
	/* Appareils                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Appareils d'une personne.
	 *
	 * @param int $user_id Compte.
	 * @return array[]
	 */
	public static function appareils( $user_id ) {
		$appareils = get_user_meta( $user_id, self::META, true );
		return is_array( $appareils ) ? $appareils : array();
	}

	/**
	 * Enregistre un appareil (un appareil n'appartient qu'à une personne à la fois).
	 *
	 * @param int    $user_id  Compte.
	 * @param string $endpoint Adresse du service de notification.
	 * @param string $p256dh   Clé publique de l'appareil (base64url).
	 * @param string $auth     Secret de l'appareil (base64url).
	 * @param string $nom      Appareil (ex. « iPhone »).
	 * @return true|WP_Error
	 */
	public static function ajouter_appareil( $user_id, $endpoint, $p256dh, $auth, $nom ) {
		if ( ! self::endpoint_valide( $endpoint ) ) {
			return new WP_Error( 'etr_push_endpoint', __( 'Service de notification non reconnu.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}
		$cle = self::d64( $p256dh );
		$sec = self::d64( $auth );
		if ( 65 !== strlen( $cle ) || "\x04" !== $cle[0] || 16 !== strlen( $sec ) ) {
			return new WP_Error( 'etr_push_cles', __( 'Clés de l’appareil invalides.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}

		self::retirer_partout( $endpoint );
		$appareils   = self::appareils( $user_id );
		$appareils[] = array(
			'endpoint' => $endpoint,
			'p256dh'   => $p256dh,
			'auth'     => $auth,
			'nom'      => sanitize_text_field( $nom ),
			'depuis'   => time(),
		);
		update_user_meta( $user_id, self::META, array_slice( $appareils, - self::MAX_APPAREILS ) );
		return true;
	}

	/**
	 * Retire un appareil d'une personne.
	 *
	 * @param int    $user_id  Compte.
	 * @param string $endpoint Adresse.
	 */
	public static function retirer_appareil( $user_id, $endpoint ) {
		$appareils = self::appareils( $user_id );
		$restants  = array_values(
			array_filter(
				$appareils,
				function ( $appareil ) use ( $endpoint ) {
					return $appareil['endpoint'] !== $endpoint;
				}
			)
		);
		if ( count( $restants ) === count( $appareils ) ) {
			return;
		}
		if ( $restants ) {
			update_user_meta( $user_id, self::META, $restants );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * Retire un appareil de tous les comptes (changement de personne sur un téléphone).
	 *
	 * @param string $endpoint Adresse.
	 */
	private static function retirer_partout( $endpoint ) {
		foreach ( self::comptes_avec_appareil() as $user_id ) {
			self::retirer_appareil( $user_id, $endpoint );
		}
	}

	/**
	 * Comptes qui ont au moins un appareil.
	 *
	 * @return int[]
	 */
	private static function comptes_avec_appareil() {
		return array_map(
			'intval',
			get_users(
				array(
					'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- quelques comptes d'équipe.
					'fields'   => 'ID',
				)
			)
		);
	}

	/**
	 * Adresse d'un service de notification connu, en HTTPS.
	 *
	 * @param string $endpoint Adresse.
	 * @return bool
	 */
	private static function endpoint_valide( $endpoint ) {
		$parties = wp_parse_url( (string) $endpoint );
		if ( empty( $parties['scheme'] ) || 'https' !== $parties['scheme'] || empty( $parties['host'] ) || strlen( $endpoint ) > 1000 ) {
			return false;
		}
		$hote = strtolower( $parties['host'] );
		foreach ( (array) apply_filters( 'etr_push_services', self::SERVICES ) as $service ) {
			if ( $hote === $service || substr( $hote, - strlen( '.' . $service ) ) === '.' . $service ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* API de l'application                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Routes : enregistrer / retirer cet appareil, envoyer une notification de test.
	 */
	public static function routes() {
		$droits = array( __CLASS__, 'droits' );
		register_rest_route(
			self::API,
			'/notifications/appareil',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'api_ajouter' ),
					'permission_callback' => $droits,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'api_retirer' ),
					'permission_callback' => $droits,
				),
			)
		);
		register_rest_route(
			self::API,
			'/notifications/preferences',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'api_preferences' ),
				'permission_callback' => $droits,
			)
		);
		register_rest_route(
			self::API,
			'/notifications/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'api_test' ),
				'permission_callback' => $droits,
			)
		);
	}

	/**
	 * Membre de l'équipe connecté (le jeton « wp_rest » est vérifié par WordPress).
	 *
	 * @return bool
	 */
	public static function droits() {
		return is_user_logged_in() && ETR_Equipe::acces();
	}

	/**
	 * Le terrain (préparateurs, livreurs) n'a accès qu'à quelques routes de l'API : on ajoute celles-ci.
	 *
	 * @param string[] $routes Préfixes autorisés.
	 * @return string[]
	 */
	public static function route_autorisee( $routes ) {
		$routes[] = '/' . self::API . '/notifications/';
		return $routes;
	}

	/**
	 * POST : enregistre cet appareil.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_ajouter( $requete ) {
		$cles     = (array) $requete->get_param( 'keys' );
		$resultat = self::ajouter_appareil(
			get_current_user_id(),
			(string) $requete->get_param( 'endpoint' ),
			isset( $cles['p256dh'] ) ? (string) $cles['p256dh'] : '',
			isset( $cles['auth'] ) ? (string) $cles['auth'] : '',
			(string) $requete->get_param( 'appareil' )
		);
		if ( is_wp_error( $resultat ) ) {
			return $resultat;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * DELETE : retire cet appareil.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response
	 */
	public static function api_retirer( $requete ) {
		self::retirer_appareil( get_current_user_id(), (string) $requete->get_param( 'endpoint' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST : types de notifications désactivés ({ sans: [type, …] }).
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response
	 */
	public static function api_preferences( $requete ) {
		$sans = array_values( array_intersect( array_keys( self::types() ), array_map( 'sanitize_key', (array) $requete->get_param( 'sans' ) ) ) );
		if ( $sans ) {
			update_user_meta( get_current_user_id(), self::META_SANS, $sans );
		} else {
			delete_user_meta( get_current_user_id(), self::META_SANS );
		}
		return rest_ensure_response( array( 'sans' => $sans ) );
	}

	/**
	 * POST : notification de test sur cet appareil.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_test( $requete ) {
		$endpoint = (string) $requete->get_param( 'endpoint' );
		foreach ( self::appareils( get_current_user_id() ) as $appareil ) {
			if ( $appareil['endpoint'] !== $endpoint ) {
				continue;
			}
			$resultat = self::envoyer(
				$appareil,
				array(
					'title' => __( 'Notifications activées', 'espace-traiteur' ),
					'body'  => __( 'Cet appareil recevra les alertes de l’application de l’équipe.', 'espace-traiteur' ),
					'url'   => ETR_Gestion::url(),
					'tag'   => 'etr-test',
				)
			);
			if ( true === $resultat ) {
				return rest_ensure_response( array( 'ok' => true ) );
			}
			if ( 'expire' === $resultat ) {
				self::retirer_appareil( get_current_user_id(), $endpoint );
			}
			/* translators: %s : raison technique. */
			return new WP_Error( 'etr_push_envoi', sprintf( __( 'La notification n’est pas partie (%s).', 'espace-traiteur' ), $resultat ), array( 'status' => 502 ) );
		}
		return new WP_Error( 'etr_push_inconnu', __( 'Cet appareil n’est pas enregistré. Réactivez les notifications.', 'espace-traiteur' ), array( 'status' => 404 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Événements                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Nouvelle demande d'un traiteur → bureau.
	 *
	 * @param int $demande_id Demande.
	 */
	public static function nouvelle_demande( $demande_id ) {
		/* translators: %s : traiteur. */
		$titre = sprintf( __( 'Nouvelle demande de %s', 'espace-traiteur' ), self::traiteur( $demande_id ) );
		self::notifier( 'bureau', $titre, self::resume( $demande_id ), $demande_id, '', 'nouvelle' );
	}

	/**
	 * Demande annulée par le traiteur → bureau.
	 *
	 * @param int $demande_id Demande.
	 */
	public static function demande_annulee( $demande_id ) {
		/* translators: %s : traiteur. */
		$titre = sprintf( __( 'Demande annulée par %s', 'espace-traiteur' ), self::traiteur( $demande_id ) );
		self::notifier( 'bureau', $titre, self::resume( $demande_id ), $demande_id, '', 'annulee_traiteur' );
	}

	/**
	 * Changement de statut par le bureau → préparateurs et livreurs.
	 *
	 * @param int    $demande_id Demande.
	 * @param string $ancien     Ancien statut.
	 * @param string $nouveau    Nouveau statut.
	 */
	public static function statut_change( $demande_id, $ancien, $nouveau ) {
		if ( 'validee' === $nouveau ) {
			/* translators: %s : traiteur. */
			self::notifier( 'terrain', sprintf( __( 'Commande validée : %s', 'espace-traiteur' ), self::traiteur( $demande_id ) ), self::resume( $demande_id, true ), $demande_id, '', 'validee' );
		} elseif ( in_array( $ancien, array( 'validee', 'livree' ), true ) && in_array( $nouveau, array( 'annulee', 'refusee' ), true ) ) {
			/* translators: %s : traiteur. */
			self::notifier( 'terrain', sprintf( __( 'Commande annulée : %s', 'espace-traiteur' ), self::traiteur( $demande_id ) ), self::resume( $demande_id, true ), $demande_id, '', 'annulee' );
		}
	}

	/**
	 * Met une notification en file pour un groupe (envoi en fin de requête).
	 *
	 * @param string $groupe     bureau | terrain (préparateurs et livreurs) | preparateur | livreur.
	 * @param string $titre      Titre.
	 * @param string $texte      Texte.
	 * @param int    $demande_id Demande ouverte au toucher.
	 * @param string $url        Page ouverte au toucher (sinon la demande, ou l'accueil).
	 * @param string $type       Type (voir types()) : la personne peut l'avoir désactivé.
	 * @param int[]  $seulement  Seulement ces personnes du groupe (vide : tout le groupe).
	 */
	public static function notifier( $groupe, $titre, $texte, $demande_id = 0, $url = '', $type = '', $seulement = array() ) {
		if ( '' === $url ) {
			$url = $demande_id ? ETR_Gestion::url( array( 'vue' => 'demande', 'id' => (int) $demande_id ) ) : ETR_Gestion::url();
		}
		$message = array(
			'title' => wp_strip_all_tags( $titre ),
			'body'  => wp_strip_all_tags( $texte ),
			'url'   => $url,
			'tag'   => $demande_id ? 'etr-demande-' . (int) $demande_id : 'etr',
		);
		$moi = get_current_user_id();
		foreach ( self::comptes_avec_appareil() as $user_id ) {
			if ( $user_id === $moi || ! self::concerne( $user_id, $groupe ) ) {
				continue;
			}
			if ( $seulement && ! in_array( $user_id, array_map( 'intval', $seulement ), true ) ) {
				continue;
			}
			if ( $type && in_array( $type, self::types_desactives( $user_id ), true ) ) {
				continue; // Désactivé par la personne (page Notifications).
			}
			// Pastille sur l'icône de l'application, mise à jour même application fermée.
			$message['badge'] = self::pastille( $user_id );
			/** Permet d'écarter un destinataire ou d'adapter le message. */
			$personnel = apply_filters( 'etr_push_message', $message, $user_id, $groupe, $demande_id );
			if ( $personnel ) {
				self::$file[] = array( $user_id, $personnel );
			}
		}
	}

	/**
	 * Notification à un traiteur (espace traiteur installé, fonction « push_traiteur »).
	 *
	 * @param int    $user_id    Traiteur.
	 * @param string $titre      Titre.
	 * @param string $texte      Texte.
	 * @param int    $demande_id Demande ouverte au toucher.
	 */
	public static function notifier_traiteur( $user_id, $titre, $texte, $demande_id = 0 ) {
		if ( ! ETR_Modules::actif( 'push_traiteur' ) || ! etr_est_traiteur( $user_id ) || ! self::appareils( $user_id ) ) {
			return;
		}
		$message = array(
			'title' => wp_strip_all_tags( $titre ),
			'body'  => wp_strip_all_tags( $texte ),
			'url'   => $demande_id ? etr_url( array( 'etr_vue' => 'demande', 'id' => (int) $demande_id ) ) : etr_url(),
			'tag'   => $demande_id ? 'etr-demande-' . (int) $demande_id : 'etr',
		);
		/** Même filtre que pour l'équipe (groupe « traiteur »). */
		$message = apply_filters( 'etr_push_message', $message, (int) $user_id, 'traiteur', $demande_id );
		if ( $message ) {
			self::$file[] = array( (int) $user_id, $message );
		}
	}

	/**
	 * Nombre sur l'icône de l'application : demandes à traiter (bureau), étapes de la tournée
	 * du jour (livreurs). Même règle que les pastilles des onglets.
	 *
	 * @param int $user_id Compte.
	 * @return int
	 */
	private static function pastille( $user_id ) {
		if ( ETR_Equipe::bureau( $user_id ) ) {
			return count(
				get_posts(
					array(
						'post_type'     => 'etr_demande',
						'post_status'   => 'publish',
						'numberposts'   => -1,
						'fields'        => 'ids',
						'no_found_rows' => true,
						'meta_key'      => '_etr_statut', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value'    => 'en_attente', // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				)
			);
		}
		$user = get_userdata( $user_id );
		if ( $user && in_array( 'etr_livreur', ETR_Equipe::roles_equipe( $user ), true ) ) {
			$tournee = ETR_Operations::tournee_de( current_datetime()->format( 'Y-m-d' ), $user_id );
			return count( $tournee['livraisons'] ) + count( $tournee['reprises'] );
		}
		return 0;
	}

	/**
	 * La personne fait-elle partie du groupe ?
	 *
	 * @param int    $user_id Compte.
	 * @param string $groupe  bureau | terrain | preparateur | livreur.
	 * @return bool
	 */
	private static function concerne( $user_id, $groupe ) {
		if ( ! ETR_Equipe::acces( $user_id ) || ETR_Equipe::acces_retire( $user_id ) ) {
			return false;
		}
		if ( 'bureau' === $groupe ) {
			return ETR_Equipe::bureau( $user_id );
		}
		// Rôles réellement donnés (le bureau a aussi les droits du terrain, sans en recevoir les alertes).
		$user     = get_userdata( $user_id );
		$attendus = array(
			'terrain'     => array( 'etr_preparateur', 'etr_livreur' ),
			'preparateur' => array( 'etr_preparateur' ),
			'livreur'     => array( 'etr_livreur' ),
		);
		return $user && isset( $attendus[ $groupe ] ) && array_intersect( $attendus[ $groupe ], ETR_Equipe::roles_equipe( $user ) );
	}

	/**
	 * Envoie la file (après le traitement de la page : l'action reste rapide).
	 */
	public static function vider_file() {
		if ( ! self::$file || ! self::cles() ) {
			return;
		}
		$file       = self::$file;
		self::$file = array();
		// La page est déjà envoyée au navigateur : l'envoi des notifications ne la ralentit pas.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		foreach ( $file as $element ) {
			list( $user_id, $message ) = $element;
			foreach ( self::appareils( $user_id ) as $appareil ) {
				if ( 'expire' === self::envoyer( $appareil, $message ) ) {
					self::retirer_appareil( $user_id, $appareil['endpoint'] ); // Notifications désactivées ou app supprimée.
				}
			}
		}
	}

	/**
	 * Nom du traiteur d'une demande.
	 *
	 * @param int $demande_id Demande.
	 * @return string
	 */
	private static function traiteur( $demande_id ) {
		return etr_nom( get_userdata( (int) get_post_field( 'post_author', $demande_id ) ) );
	}

	/**
	 * Résumé court : événement, date, nombre d'articles.
	 *
	 * @param int  $demande_id Demande.
	 * @param bool $livraison  Mettre en avant la date de livraison.
	 * @return string
	 */
	private static function resume( $demande_id, $livraison = false ) {
		$parties   = array( get_the_title( $demande_id ) );
		$evenement = (string) get_post_meta( $demande_id, '_etr_date_evenement', true );
		$livree_le = (string) get_post_meta( $demande_id, '_etr_date_livraison', true );
		if ( $livraison && $livree_le ) {
			/* translators: %s : date. */
			$parties[] = sprintf( __( 'livraison le %s', 'espace-traiteur' ), etr_format_date( $livree_le ) );
		} elseif ( $evenement ) {
			$parties[] = etr_format_date( $evenement );
		}
		$articles = array_sum( wp_list_pluck( etr_get_lignes( $demande_id ), 'quantite' ) );
		if ( $articles ) {
			/* translators: %s : nombre d'articles. */
			$parties[] = sprintf( _n( '%s article', '%s articles', $articles, 'espace-traiteur' ), number_format_i18n( $articles ) );
		}
		return implode( ' · ', array_filter( $parties ) );
	}

	/* ------------------------------------------------------------------ */
	/* Envoi                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Envoie un message chiffré à un appareil.
	 *
	 * @param array $appareil Appareil.
	 * @param array $message  { title, body, url, tag }.
	 * @return true|string True, « expire » (appareil à retirer) ou raison de l'échec.
	 */
	public static function envoyer( $appareil, $message ) {
		$cles = self::cles();
		if ( ! $cles ) {
			return 'openssl';
		}
		$corps = self::chiffrer( wp_json_encode( $message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $appareil['p256dh'], $appareil['auth'] );
		$jeton = self::jeton( $appareil['endpoint'], $cles );
		if ( null === $corps || null === $jeton ) {
			return 'chiffrement';
		}
		$reponse = wp_remote_post(
			$appareil['endpoint'],
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type'     => 'application/octet-stream',
					'Content-Encoding' => 'aes128gcm',
					'TTL'              => (string) DAY_IN_SECONDS,
					'Urgency'          => 'high',
					'Topic'            => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $message['tag'] ), 0, 32 ),
					'Authorization'    => 'vapid t=' . $jeton . ', k=' . $cles['publique'],
				),
				'body'    => $corps,
			)
		);
		if ( is_wp_error( $reponse ) ) {
			return $reponse->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $reponse );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		if ( 404 === $code || 410 === $code ) {
			return 'expire';
		}
		return 'HTTP ' . $code . ' ' . substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $reponse ) ), 0, 120 );
	}

	/**
	 * Chiffre un message pour un appareil (RFC 8291, « aes128gcm »).
	 *
	 * @param string $texte  Message.
	 * @param string $p256dh Clé publique de l'appareil (base64url, 65 octets).
	 * @param string $auth   Secret de l'appareil (base64url, 16 octets).
	 * @return string|null Corps de la requête.
	 */
	public static function chiffrer( $texte, $p256dh, $auth ) {
		$cle_appareil = self::d64( $p256dh );
		$secret       = self::d64( $auth );
		$paire        = self::nouvelle_paire();
		$pub_appareil = openssl_pkey_get_public( self::pem_publique( $cle_appareil ) );
		if ( ! $paire || ! $pub_appareil ) {
			return null;
		}
		$partage = openssl_pkey_derive( $pub_appareil, openssl_pkey_get_private( $paire['privee'] ), 32 );
		if ( false === $partage ) {
			return null;
		}

		// Clé d'entrée (RFC 8291 §3.3), puis clé de contenu et nonce (RFC 8188 §2.2).
		$prk_cle = hash_hmac( 'sha256', $partage, $secret, true );
		$ikm     = hash_hmac( 'sha256', "WebPush: info\0" . $cle_appareil . $paire['publique'] . "\x01", $prk_cle, true );
		$sel     = random_bytes( 16 );
		$prk     = hash_hmac( 'sha256', $ikm, $sel, true );
		$cek     = substr( hash_hmac( 'sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true ), 0, 16 );
		$nonce   = substr( hash_hmac( 'sha256', "Content-Encoding: nonce\0\x01", $prk, true ), 0, 12 );

		$etiquette = '';
		$chiffre   = openssl_encrypt( $texte . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiquette, '', 16 );
		if ( false === $chiffre ) {
			return null;
		}
		// En-tête : sel, taille d'enregistrement (4096), longueur et clé publique de l'envoi.
		return $sel . pack( 'N', 4096 ) . chr( 65 ) . $paire['publique'] . $chiffre . $etiquette;
	}

	/**
	 * Jeton VAPID (JWT ES256) pour le service de l'appareil.
	 *
	 * @param string $endpoint Adresse du service.
	 * @param array  $cles     Clés du site.
	 * @return string|null
	 */
	public static function jeton( $endpoint, $cles ) {
		$parties = wp_parse_url( $endpoint );
		$entete  = self::b64( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$contenu = self::b64(
			wp_json_encode(
				array(
					'aud' => $parties['scheme'] . '://' . $parties['host'],
					'exp' => time() + 12 * HOUR_IN_SECONDS,
					'sub' => self::sujet(),
				),
				JSON_UNESCAPED_SLASHES
			)
		);
		$signature = '';
		if ( ! openssl_sign( $entete . '.' . $contenu, $signature, $cles['privee'], OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}
		$brute = self::signature_brute( $signature );
		return null === $brute ? null : $entete . '.' . $contenu . '.' . self::b64( $brute );
	}

	/**
	 * Contact du site pour les services de notification (adresse https du site, sinon e-mail).
	 *
	 * @return string
	 */
	private static function sujet() {
		$site = home_url( '/' );
		return (string) apply_filters( 'etr_push_sujet', 0 === strpos( $site, 'https://' ) ? untrailingslashit( $site ) : 'mailto:' . get_option( 'admin_email' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Outils cryptographiques                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Nouvelle paire de clés P-256.
	 *
	 * @return array|null { publique : 65 octets (04 || x || y), privee : PEM }
	 */
	private static function nouvelle_paire() {
		$cle = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);
		if ( ! $cle ) {
			return null;
		}
		$details = openssl_pkey_get_details( $cle );
		$privee  = '';
		if ( empty( $details['ec']['x'] ) || ! openssl_pkey_export( $cle, $privee ) ) {
			return null;
		}
		return array(
			'publique' => "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT ),
			'privee'   => $privee,
		);
	}

	/**
	 * Clé publique P-256 brute (65 octets) → PEM.
	 *
	 * @param string $brute Clé.
	 * @return string
	 */
	private static function pem_publique( $brute ) {
		$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $brute;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format PEM.
	}

	/**
	 * Signature ECDSA DER → r || s (64 octets), format attendu par JWT.
	 *
	 * @param string $der Signature DER.
	 * @return string|null
	 */
	private static function signature_brute( $der ) {
		$pos = 2; // SEQUENCE, longueur.
		if ( strlen( $der ) < 8 || "\x30" !== $der[0] ) {
			return null;
		}
		$brute = '';
		for ( $i = 0; $i < 2; $i++ ) {
			if ( "\x02" !== $der[ $pos ] ) {
				return null;
			}
			$longueur = ord( $der[ $pos + 1 ] );
			$entier   = substr( $der, $pos + 2, $longueur );
			$pos     += 2 + $longueur;
			$brute   .= str_pad( ltrim( $entier, "\0" ), 32, "\0", STR_PAD_LEFT );
		}
		return 64 === strlen( $brute ) ? $brute : null;
	}

	/**
	 * Base64url sans remplissage.
	 *
	 * @param string $donnees Octets.
	 * @return string
	 */
	public static function b64( $donnees ) {
		return rtrim( strtr( base64_encode( $donnees ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Base64url → octets.
	 *
	 * @param string $texte Texte.
	 * @return string
	 */
	public static function d64( $texte ) {
		$texte = strtr( (string) $texte, '-_', '+/' );
		return (string) base64_decode( $texte . str_repeat( '=', ( 4 - strlen( $texte ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
