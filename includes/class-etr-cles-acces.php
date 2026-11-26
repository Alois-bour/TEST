<?php
/**
 * Connexion par Face ID / Touch ID (clés d'accès, norme WebAuthn), pour l'application de l'équipe.
 *
 * - Activation : depuis l'application (écran « Face ID »), l'iPhone crée une clé d'accès pour le
 *   site ; seule la clé publique est enregistrée sur le compte. La clé privée ne quitte jamais
 *   l'appareil (trousseau iCloud) et ne sert qu'après Face ID.
 * - Connexion : sur l'écran de connexion, « Se connecter avec Face ID » ; l'iPhone propose la
 *   clé du site, Face ID la débloque, la signature est vérifiée ici.
 *
 * Vérifications : défi à usage unique (5 minutes), origine et identifiant du site, présence et
 * vérification de l'utilisateur (Face ID), signature (ES256 ou RS256), compteur anti-clonage,
 * accès à l'application. Aucune dépendance : décodage CBOR et clés COSE faits ici, OpenSSL.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clés d'accès.
 */
class ETR_Cles_Acces {

	const META      = 'etr_cles_acces';
	const MAX_CLES  = 10;
	const MAX_IP    = 30; // Essais de connexion par adresse IP et par quart d'heure.
	const DUREE     = 300;
	const ROUTE     = '/cles/';
	const ES256     = -7;
	const RS256     = -257;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'etr_routes_api_equipe', array( __CLASS__, 'route_autorisee' ) );
	}

	/**
	 * Le serveur sait-il vérifier les clés d'accès ?
	 *
	 * @return bool
	 */
	public static function disponible() {
		return function_exists( 'openssl_verify' ) && function_exists( 'random_bytes' ) && ETR_Modules::actif( 'face_id' );
	}

	/**
	 * Identifiant du site pour les clés (nom de domaine, sans port).
	 *
	 * @return string
	 */
	public static function rp_id() {
		return (string) apply_filters( 'etr_cles_rp_id', wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Origines acceptées (adresse du site, et de l'administration si elle diffère).
	 *
	 * @return string[]
	 */
	private static function origines() {
		$origines = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$p          = wp_parse_url( $url );
			$origines[] = $p['scheme'] . '://' . $p['host'] . ( empty( $p['port'] ) ? '' : ':' . $p['port'] );
		}
		return array_unique( $origines );
	}

	/**
	 * Clés d'une personne.
	 *
	 * @param int $user_id Compte.
	 * @return array[]
	 */
	public static function cles( $user_id ) {
		$cles = get_user_meta( $user_id, self::META, true );
		return is_array( $cles ) ? $cles : array();
	}

	/* ------------------------------------------------------------------ */
	/* API                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Le terrain a accès à ces routes.
	 *
	 * @param string[] $routes Préfixes.
	 * @return string[]
	 */
	public static function route_autorisee( $routes ) {
		$routes[] = '/' . ETR_Push::API . self::ROUTE;
		return $routes;
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		if ( ! self::disponible() ) {
			return; // Fonction désactivée (Espace traiteur › Fonctions) : clés refusées.
		}
		$connecte = function () {
			return is_user_logged_in() && ETR_Equipe::acces();
		};
		$def = array(
			'inscription/options' => array( 'POST', 'api_options_inscription', $connecte ),
			'inscription'         => array( 'POST', 'api_inscription', $connecte ),
			'connexion/options'   => array( 'POST', 'api_options_connexion', '__return_true' ),
			'connexion'           => array( 'POST', 'api_connexion', '__return_true' ),
			'retirer'             => array( 'POST', 'api_retirer', $connecte ),
		);
		foreach ( $def as $route => $config ) {
			register_rest_route(
				ETR_Push::API,
				self::ROUTE . $route,
				array(
					'methods'             => $config[0],
					'callback'            => array( __CLASS__, $config[1] ),
					'permission_callback' => $config[2],
				)
			);
		}
	}

	/**
	 * Défi à usage unique, gardé 5 minutes.
	 *
	 * @param array $contexte Données liées (type, compte).
	 * @return array { jeton, defi }
	 */
	private static function nouveau_defi( $contexte ) {
		$jeton = bin2hex( random_bytes( 16 ) );
		$defi  = random_bytes( 32 );
		set_transient( 'etr_wa_' . $jeton, array_merge( $contexte, array( 'defi' => ETR_Push::b64( $defi ) ) ), self::DUREE );
		return array(
			'jeton' => $jeton,
			'defi'  => ETR_Push::b64( $defi ),
		);
	}

	/**
	 * Reprend (et consomme) un défi.
	 *
	 * @param string $jeton Jeton.
	 * @param string $type  inscription | connexion.
	 * @return array|null
	 */
	private static function prendre_defi( $jeton, $type ) {
		$jeton = preg_replace( '/[^a-f0-9]/', '', (string) $jeton );
		$defi  = $jeton ? get_transient( 'etr_wa_' . $jeton ) : false;
		delete_transient( 'etr_wa_' . $jeton );
		return is_array( $defi ) && isset( $defi['type'] ) && $type === $defi['type'] ? $defi : null;
	}

	/**
	 * POST inscription/options : options de création de clé pour la personne connectée.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_options_inscription() {
		$user = wp_get_current_user();
		$defi = self::nouveau_defi(
			array(
				'type' => 'inscription',
				'user' => $user->ID,
			)
		);
		return rest_ensure_response(
			array(
				'jeton'     => $defi['jeton'],
				'publicKey' => array(
					'challenge'              => $defi['defi'],
					'rp'                     => array(
						'id'   => self::rp_id(),
						'name' => etr_nom_site(),
					),
					'user'                   => array(
						'id'          => ETR_Push::b64( 'etr-' . $user->ID ),
						'name'        => $user->user_login,
						'displayName' => etr_nom( $user ),
					),
					'pubKeyCredParams'       => array(
						array( 'type' => 'public-key', 'alg' => self::ES256 ),
						array( 'type' => 'public-key', 'alg' => self::RS256 ),
					),
					'authenticatorSelection' => array(
						'authenticatorAttachment' => 'platform',
						'residentKey'             => 'required',
						'requireResidentKey'      => true,
						'userVerification'        => 'required',
					),
					'excludeCredentials'     => array_map(
						function ( $cle ) {
							return array( 'type' => 'public-key', 'id' => $cle['id'] );
						},
						self::cles( $user->ID )
					),
					'attestation'            => 'none',
					'timeout'                => 120000,
				),
			)
		);
	}

	/**
	 * POST inscription : enregistre la clé publique créée par l'appareil.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_inscription( $requete ) {
		$user = wp_get_current_user();
		$defi = self::prendre_defi( $requete->get_param( 'jeton' ), 'inscription' );
		if ( ! $defi || (int) $defi['user'] !== $user->ID ) {
			return self::erreur( __( 'Demande expirée : réessayez.', 'espace-traiteur' ) );
		}
		$client = self::client( (string) $requete->get_param( 'clientDataJSON' ), 'webauthn.create', $defi['defi'] );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$attestation = self::cbor( ETR_Push::d64( (string) $requete->get_param( 'attestationObject' ) ) );
		if ( ! is_array( $attestation ) || empty( $attestation['authData'] ) ) {
			return self::erreur( __( 'Réponse de l’appareil illisible.', 'espace-traiteur' ) );
		}
		$donnees = self::donnees_appareil( $attestation['authData'], true );
		if ( is_wp_error( $donnees ) ) {
			return $donnees;
		}
		$cles = self::cles( $user->ID );
		foreach ( $cles as $cle ) {
			if ( hash_equals( $cle['id'], $donnees['id'] ) ) {
				return self::erreur( __( 'Cet appareil est déjà enregistré.', 'espace-traiteur' ) );
			}
		}
		$cles[] = array(
			'id'       => $donnees['id'],
			'cle'      => $donnees['pem'],
			'alg'      => $donnees['alg'],
			'compteur' => $donnees['compteur'],
			'nom'      => sanitize_text_field( (string) $requete->get_param( 'nom' ) ),
			'cree'     => time(),
			'utilise'  => 0,
		);
		update_user_meta( $user->ID, self::META, array_slice( $cles, - self::MAX_CLES ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST connexion/options : défi de connexion (aucune information sur les comptes).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_options_connexion() {
		if ( self::ip_bloquee() ) {
			return self::erreur( __( 'Trop de tentatives. Réessayez dans 15 minutes.', 'espace-traiteur' ), 429 );
		}
		$defi = self::nouveau_defi( array( 'type' => 'connexion' ) );
		return rest_ensure_response(
			array(
				'jeton'     => $defi['jeton'],
				'publicKey' => array(
					'challenge'        => $defi['defi'],
					'rpId'             => self::rp_id(),
					'allowCredentials' => array(),
					'userVerification' => 'required',
					'timeout'          => 120000,
				),
			)
		);
	}

	/**
	 * POST connexion : vérifie la signature de l'appareil, puis connecte la personne.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_connexion( $requete ) {
		// Blocage par compte, seulement quand la clé présentée est bien une clé enregistrée de ce
		// compte (son identifiant est secret) : un inconnu ne peut pas bloquer le Face ID d'une
		// personne en envoyant son numéro. Les autres échecs comptent par adresse IP (plafond large),
		// sans bloquer toute l'équipe derrière la même connexion (Wi-Fi du dépôt).
		$compte     = self::compte_de_cle( $requete );
		$cle_echecs = $compte ? 'cle|' . $compte : '';
		// Chaque essai est compté avant la vérification, de façon atomique : des requêtes envoyées
		// en même temps ne passent pas toutes sous le plafond (retiré ensuite en cas de réussite).
		if ( self::compter_ip( 1 ) > self::MAX_IP || ( $cle_echecs && ETR_Comptes::est_bloque( $cle_echecs ) ) ) {
			return self::erreur( __( 'Trop de tentatives. Réessayez dans 15 minutes.', 'espace-traiteur' ), 429 );
		}
		$resultat = self::verifier_connexion( $requete );
		if ( is_wp_error( $resultat ) ) {
			if ( 'etr_cle_expiree' !== $resultat->get_error_code() ) { // Une page restée ouverte n'est pas une tentative.
				if ( $cle_echecs ) {
					ETR_Comptes::compter_echec( $cle_echecs );
				}
			} else {
				self::compter_ip( -1 ); // Demande expirée : pas une tentative.
			}
			return $resultat;
		}
		self::compter_ip( -1 ); // Réussite : ne compte pas dans le plafond de l'adresse IP.
		delete_transient( ETR_Comptes::cle_echecs( 'cle|' . $resultat->ID ) ); // Réussite : compteur d'échecs remis à zéro.
		wp_set_auth_cookie( $resultat->ID, true, is_ssl() );
		do_action( 'wp_login', $resultat->user_login, $resultat );
		return rest_ensure_response( array( 'url' => ETR_Gestion::url() ) );
	}

	/**
	 * Compte dont la clé présentée est une clé enregistrée (0 sinon), avant toute vérification.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return int
	 */
	private static function compte_de_cle( $requete ) {
		$poignee = ETR_Push::d64( (string) $requete->get_param( 'userHandle' ) );
		$user_id = preg_match( '/^etr-(\d+)$/', $poignee, $m ) ? (int) $m[1] : 0;
		$id      = (string) $requete->get_param( 'id' );
		if ( ! $user_id || '' === $id ) {
			return 0;
		}
		foreach ( self::cles( $user_id ) as $cle ) {
			if ( hash_equals( (string) $cle['id'], $id ) ) {
				return $user_id;
			}
		}
		return 0;
	}

	/**
	 * Vérification complète d'une connexion.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_User|WP_Error
	 */
	private static function verifier_connexion( $requete ) {
		$refus = __( 'Connexion par Face ID refusée. Connectez-vous avec votre mot de passe.', 'espace-traiteur' );
		$defi  = self::prendre_defi( $requete->get_param( 'jeton' ), 'connexion' );
		if ( ! $defi ) {
			return new WP_Error( 'etr_cle_expiree', __( 'Demande expirée : réessayez.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}
		$id        = (string) $requete->get_param( 'id' );
		$poignee   = ETR_Push::d64( (string) $requete->get_param( 'userHandle' ) );
		$user_id   = preg_match( '/^etr-(\d+)$/', $poignee, $m ) ? (int) $m[1] : 0;
		$user      = $user_id ? get_userdata( $user_id ) : false;
		$cles      = $user ? self::cles( $user->ID ) : array();
		$rang      = null;
		foreach ( $cles as $i => $cle ) {
			if ( hash_equals( $cle['id'], $id ) ) {
				$rang = $i;
			}
		}
		if ( null === $rang || ! ETR_Equipe::acces( $user->ID ) || ETR_Equipe::acces_retire( $user->ID ) || ETR_Comptes::est_desactive( $user->ID ) ) {
			return self::erreur( __( 'Cette clé n’est pas (ou plus) reconnue. Connectez-vous avec votre mot de passe, puis réactivez Face ID.', 'espace-traiteur' ) );
		}
		$client_json = (string) $requete->get_param( 'clientDataJSON' );
		$client      = self::client( $client_json, 'webauthn.get', $defi['defi'] );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$auth_data = ETR_Push::d64( (string) $requete->get_param( 'authenticatorData' ) );
		$donnees   = self::donnees_appareil( $auth_data, false );
		if ( is_wp_error( $donnees ) ) {
			return $donnees;
		}
		$signature = ETR_Push::d64( (string) $requete->get_param( 'signature' ) );
		$signe     = $auth_data . hash( 'sha256', ETR_Push::d64( $client_json ), true );
		if ( 1 !== openssl_verify( $signe, $signature, $cles[ $rang ]['cle'], OPENSSL_ALGO_SHA256 ) ) {
			return self::erreur( $refus );
		}
		// Compteur : s'il est tenu par l'appareil, il doit avancer (sinon clé clonée).
		if ( ( $donnees['compteur'] || $cles[ $rang ]['compteur'] ) && $donnees['compteur'] <= $cles[ $rang ]['compteur'] ) {
			return self::erreur( $refus );
		}
		$cles[ $rang ]['compteur'] = $donnees['compteur'];
		$cles[ $rang ]['utilise']  = time();
		update_user_meta( $user->ID, self::META, $cles );
		return $user;
	}

	/**
	 * POST retirer : { id } retire une clé de la personne connectée.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response
	 */
	public static function api_retirer( $requete ) {
		$id   = (string) $requete->get_param( 'id' );
		$cles = array_values(
			array_filter(
				self::cles( get_current_user_id() ),
				function ( $cle ) use ( $id ) {
					return ! hash_equals( $cle['id'], $id );
				}
			)
		);
		if ( $cles ) {
			update_user_meta( get_current_user_id(), self::META, $cles );
		} else {
			delete_user_meta( get_current_user_id(), self::META );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Vérifications                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Trop d'échecs depuis cette adresse IP, tous comptes confondus (plafond large).
	 *
	 * @return bool
	 */
	private static function ip_bloquee() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::nom_ip() ) ) >= self::MAX_IP; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Compteur de l'adresse IP pour la fenêtre de 15 minutes en cours (fenêtre fixe : un essai
	 * de plus ne prolonge pas le blocage).
	 *
	 * @return string
	 */
	private static function nom_ip() {
		return 'etr_echecs_cle_ip_' . md5( self::ip() ) . '_' . (int) floor( time() / ETR_Comptes::DUREE_BLOCAGE );
	}

	/**
	 * Avance (ou recule) le compteur de l'adresse IP, de façon atomique (SQL).
	 *
	 * @param int $pas +1 ou -1.
	 * @return int Nouvelle valeur.
	 */
	private static function compter_ip( $pas ) {
		global $wpdb;
		$nom = self::nom_ip();
		if ( $pas > 0 && $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')", $nom ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// Nouvelle fenêtre : les compteurs des fenêtres passées sont supprimés.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $wpdb->esc_like( 'etr_echecs_cle_ip_' ) . '%', '%' . $wpdb->esc_like( '_' . (int) floor( time() / ETR_Comptes::DUREE_BLOCAGE ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 1;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = %s" . ( $pas < 0 ? ' AND option_value > 0' : '' ), (int) $pas, $nom ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $nom ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Erreur d'API.
	 *
	 * @param string $message Message.
	 * @param int    $statut  Code HTTP.
	 * @return WP_Error
	 */
	private static function erreur( $message, $statut = 400 ) {
		return new WP_Error( 'etr_cle', $message, array( 'status' => $statut ) );
	}

	/**
	 * Adresse IP (limitation des essais).
	 *
	 * @return string
	 */
	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Données du navigateur (clientDataJSON) : type, défi, origine.
	 *
	 * @param string $b64  clientDataJSON (base64url).
	 * @param string $type webauthn.create | webauthn.get.
	 * @param string $defi Défi attendu (base64url).
	 * @return array|WP_Error
	 */
	private static function client( $b64, $type, $defi ) {
		$client = json_decode( ETR_Push::d64( $b64 ), true );
		if ( ! is_array( $client ) || ( isset( $client['type'] ) ? $client['type'] : '' ) !== $type ) {
			return self::erreur( __( 'Réponse de l’appareil illisible.', 'espace-traiteur' ) );
		}
		if ( ! isset( $client['challenge'] ) || ! hash_equals( $defi, rtrim( (string) $client['challenge'], '=' ) ) ) {
			return self::erreur( __( 'Demande expirée : réessayez.', 'espace-traiteur' ) );
		}
		if ( ! in_array( isset( $client['origin'] ) ? $client['origin'] : '', self::origines(), true ) ) {
			return self::erreur( __( 'Adresse du site inattendue.', 'espace-traiteur' ) );
		}
		return $client;
	}

	/**
	 * Données de l'appareil (authenticatorData) : site, présence et vérification (Face ID), compteur,
	 * et à l'inscription l'identifiant et la clé publique.
	 *
	 * @param string $auth_data   Octets.
	 * @param bool   $inscription Contient la clé (inscription).
	 * @return array|WP_Error { compteur, id?, pem?, alg? }
	 */
	private static function donnees_appareil( $auth_data, $inscription ) {
		if ( strlen( $auth_data ) < 37 || ! hash_equals( hash( 'sha256', self::rp_id(), true ), substr( $auth_data, 0, 32 ) ) ) {
			return self::erreur( __( 'Clé créée pour un autre site.', 'espace-traiteur' ) );
		}
		$drapeaux = ord( $auth_data[32] );
		if ( ! ( $drapeaux & 0x01 ) || ! ( $drapeaux & 0x04 ) ) {
			return self::erreur( __( 'Face ID n’a pas confirmé votre identité.', 'espace-traiteur' ) );
		}
		$resultat = array( 'compteur' => unpack( 'N', substr( $auth_data, 33, 4 ) )[1] );
		if ( ! $inscription ) {
			return $resultat;
		}
		if ( ! ( $drapeaux & 0x40 ) || strlen( $auth_data ) < 55 ) {
			return self::erreur( __( 'Réponse de l’appareil incomplète.', 'espace-traiteur' ) );
		}
		$longueur = unpack( 'n', substr( $auth_data, 53, 2 ) )[1];
		$id       = substr( $auth_data, 55, $longueur );
		$cose     = self::cbor( substr( $auth_data, 55 + $longueur ) );
		$cle      = is_array( $cose ) ? self::pem_cose( $cose ) : null;
		if ( ! $cle ) {
			return self::erreur( __( 'Type de clé non pris en charge.', 'espace-traiteur' ) );
		}
		return array_merge( $resultat, $cle, array( 'id' => ETR_Push::b64( $id ) ) );
	}

	/**
	 * Clé COSE → PEM (EC P-256 ou RSA).
	 *
	 * @param array $cose Clé COSE.
	 * @return array|null { pem, alg }
	 */
	private static function pem_cose( $cose ) {
		$type = isset( $cose[1] ) ? $cose[1] : 0;
		$alg  = isset( $cose[3] ) ? $cose[3] : 0;
		if ( 2 === $type && self::ES256 === $alg && isset( $cose[-1], $cose[-2], $cose[-3] ) && 1 === $cose[-1] && 32 === strlen( $cose[-2] ) && 32 === strlen( $cose[-3] ) ) {
			$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . "\x04" . $cose[-2] . $cose[-3];
		} elseif ( 3 === $type && self::RS256 === $alg && isset( $cose[-1], $cose[-2] ) ) {
			$cle = self::der( 0x30, self::der_entier( $cose[-1] ) . self::der_entier( $cose[-2] ) );
			$der = self::der( 0x30, self::der( 0x30, hex2bin( '06092a864886f70d0101010500' ) ) . self::der( 0x03, "\0" . $cle ) );
		} else {
			return null;
		}
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return openssl_pkey_get_public( $pem ) ? array( 'pem' => $pem, 'alg' => $alg ) : null;
	}

	/**
	 * Élément DER (étiquette, longueur, contenu).
	 *
	 * @param int    $etiquette Étiquette.
	 * @param string $contenu   Contenu.
	 * @return string
	 */
	private static function der( $etiquette, $contenu ) {
		$n = strlen( $contenu );
		if ( $n < 128 ) {
			$longueur = chr( $n );
		} else {
			$octets   = ltrim( pack( 'N', $n ), "\0" );
			$longueur = chr( 0x80 | strlen( $octets ) ) . $octets;
		}
		return chr( $etiquette ) . $longueur . $contenu;
	}

	/**
	 * Entier DER positif.
	 *
	 * @param string $octets Octets (grand-boutiste).
	 * @return string
	 */
	private static function der_entier( $octets ) {
		$octets = ltrim( $octets, "\0" );
		if ( '' === $octets || ord( $octets[0] ) > 0x7f ) {
			$octets = "\0" . $octets;
		}
		return self::der( 0x02, $octets );
	}

	/* ------------------------------------------------------------------ */
	/* Décodage CBOR (le sous-ensemble utilisé par WebAuthn)              */
	/* ------------------------------------------------------------------ */

	/**
	 * Décode un élément CBOR au début des octets.
	 *
	 * @param string $octets Octets.
	 * @return mixed Valeur, ou null si illisible.
	 */
	public static function cbor( $octets ) {
		$pos = 0;
		try {
			return self::cbor_element( (string) $octets, $pos, 0 );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Élément CBOR à la position donnée (avance la position).
	 *
	 * @param string $o         Octets.
	 * @param int    $pos       Position.
	 * @param int    $profondeur Profondeur (limitée).
	 * @return mixed
	 * @throws Exception Données invalides.
	 */
	private static function cbor_element( $o, &$pos, $profondeur ) {
		if ( $profondeur > 16 || $pos >= strlen( $o ) ) {
			throw new Exception( 'cbor' );
		}
		$tete   = ord( $o[ $pos++ ] );
		$majeur = $tete >> 5;
		$info   = $tete & 0x1f;
		if ( 7 === $majeur ) {
			$simples = array( 20 => false, 21 => true, 22 => null );
			if ( array_key_exists( $info, $simples ) ) {
				return $simples[ $info ];
			}
			throw new Exception( 'cbor' );
		}
		$valeur = self::cbor_longueur( $o, $pos, $info );
		switch ( $majeur ) {
			case 0:
				return $valeur;
			case 1:
				return -1 - $valeur;
			case 2:
			case 3:
				if ( $pos + $valeur > strlen( $o ) ) {
					throw new Exception( 'cbor' );
				}
				$texte = substr( $o, $pos, $valeur );
				$pos  += $valeur;
				return $texte;
			case 4:
				$liste = array();
				for ( $i = 0; $i < $valeur; $i++ ) {
					$liste[] = self::cbor_element( $o, $pos, $profondeur + 1 );
				}
				return $liste;
			case 5:
				$table = array();
				for ( $i = 0; $i < $valeur; $i++ ) {
					$cle           = self::cbor_element( $o, $pos, $profondeur + 1 );
					$table[ $cle ] = self::cbor_element( $o, $pos, $profondeur + 1 );
				}
				return $table;
		}
		throw new Exception( 'cbor' );
	}

	/**
	 * Longueur ou valeur entière d'un élément CBOR.
	 *
	 * @param string $o    Octets.
	 * @param int    $pos  Position.
	 * @param int    $info Information additionnelle.
	 * @return int
	 * @throws Exception Données invalides.
	 */
	private static function cbor_longueur( $o, &$pos, $info ) {
		if ( $info < 24 ) {
			return $info;
		}
		$tailles = array( 24 => 1, 25 => 2, 26 => 4, 27 => 8 );
		if ( ! isset( $tailles[ $info ] ) || $pos + $tailles[ $info ] > strlen( $o ) ) {
			throw new Exception( 'cbor' );
		}
		$valeur = 0;
		for ( $i = 0; $i < $tailles[ $info ]; $i++ ) {
			$valeur = ( $valeur << 8 ) | ord( $o[ $pos++ ] );
		}
		return $valeur;
	}
}
