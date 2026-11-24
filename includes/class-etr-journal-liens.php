<?php
/**
 * Journal des liens « choisir mon mot de passe » : création des clés, modifications,
 * connexions et liens refusés, avec l'heure et l'extension à l'origine.
 *
 * Sert à comprendre pourquoi un lien tout juste reçu est refusé (clé remplacée par une
 * autre extension, envoi en double…). Les clés elles-mêmes ne sont jamais enregistrées :
 * seulement une empreinte courte, qui permet de reconnaître la même clé.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Journal des liens.
 */
class ETR_Journal_Liens {

	const OPTION = 'etr_journal_liens';
	const MAX    = 40;

	/**
	 * Empreintes des clés créées pendant cette requête (pour ne pas signaler deux fois la même).
	 *
	 * @var string[]
	 */
	private static $creees = array();

	/**
	 * Comptes qui se connectent avec une clé en attente (WordPress l'efface à la connexion).
	 *
	 * @var bool[]
	 */
	private static $avec_cle = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'retrieve_password_key', array( __CLASS__, 'cle_creee' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'compte_modifie' ), 10, 2 );
		add_filter( 'authenticate', array( __CLASS__, 'avant_connexion' ), 999 );
		add_action( 'wp_login', array( __CLASS__, 'connexion' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'mot_de_passe_choisi' ) );
	}

	/**
	 * Empreinte courte d'une clé (la clé ne peut pas en être déduite).
	 *
	 * @param string $cle Clé.
	 * @return string
	 */
	public static function empreinte( $cle ) {
		return substr( hash_hmac( 'sha256', (string) $cle, wp_salt( 'auth' ) ), 0, 8 );
	}

	/**
	 * Ajoute une ligne au journal.
	 *
	 * @param string $login     Identifiant.
	 * @param string $evenement Événement.
	 * @param string $detail    Détail.
	 */
	public static function noter( $login, $evenement, $detail = '' ) {
		$journal   = get_option( self::OPTION, array() );
		$journal   = is_array( $journal ) ? $journal : array();
		$journal[] = array(
			't' => time(),
			'l' => (string) $login,
			'e' => (string) $evenement,
			'd' => (string) $detail,
			's' => self::source(),
			'r' => self::requete(),
		);
		update_option( self::OPTION, array_slice( $journal, - self::MAX ), false );
	}

	/**
	 * Journal, du plus récent au plus ancien.
	 *
	 * @return array
	 */
	public static function lignes() {
		$journal = get_option( self::OPTION, array() );
		return is_array( $journal ) ? array_reverse( $journal ) : array();
	}

	/**
	 * Vide le journal.
	 */
	public static function vider() {
		delete_option( self::OPTION );
	}

	/* ------------------------------------------------------------------ */
	/* Événements                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Une clé est créée (invitation, mot de passe oublié, test, autre extension).
	 *
	 * @param string $login Identifiant.
	 * @param string $cle   Clé (non enregistrée).
	 */
	public static function cle_creee( $login, $cle ) {
		$empreinte       = self::empreinte( $cle );
		self::$creees[]  = $empreinte;
		self::noter( $login, 'cle_creee', $empreinte );
	}

	/**
	 * La clé enregistrée change sans passer par la création d'une clé (effacée ou remplacée).
	 *
	 * @param int     $user_id Compte.
	 * @param WP_User $avant   Compte avant la modification.
	 */
	public static function compte_modifie( $user_id, $avant ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- valeur réellement enregistrée, hors cache.
		$apres = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $user_id ) );
		$avant = $avant instanceof WP_User ? (string) $avant->user_activation_key : '';
		if ( $apres === $avant || ( '' !== $apres && self::$creees ) ) {
			return; // Inchangée, ou nouvelle clé déjà notée par cle_creee().
		}
		$user = get_userdata( $user_id );
		self::noter( $user ? $user->user_login : '#' . $user_id, '' === $apres ? 'cle_effacee' : 'cle_remplacee' );
	}

	/**
	 * Repère, avant la connexion, un compte qui a une clé en attente.
	 *
	 * @param WP_User|WP_Error|null $user Résultat de l'authentification.
	 * @return WP_User|WP_Error|null
	 */
	public static function avant_connexion( $user ) {
		if ( $user instanceof WP_User && '' !== (string) $user->user_activation_key ) {
			self::$avec_cle[ $user->ID ] = true;
		}
		return $user;
	}

	/**
	 * Connexion réussie d'un compte qui avait une clé en attente : WordPress l'a effacée.
	 *
	 * @param string  $login Identifiant.
	 * @param WP_User $user  Compte.
	 */
	public static function connexion( $login, $user ) {
		if ( $user instanceof WP_User && ! empty( self::$avec_cle[ $user->ID ] ) ) {
			self::noter( $login, 'connexion' );
		}
	}

	/**
	 * Mot de passe choisi avec un lien.
	 *
	 * @param WP_User $user Compte.
	 */
	public static function mot_de_passe_choisi( $user ) {
		self::noter( $user->user_login, 'mot_de_passe' );
	}

	/**
	 * Lien ouvert : accepté ou refusé (avec la raison, et la clé qui l'a remplacé si on la connaît).
	 *
	 * @param string           $login    Identifiant.
	 * @param string           $cle      Clé du lien.
	 * @param WP_User|WP_Error $resultat Résultat de la vérification.
	 */
	public static function lien_ouvert( $login, $cle, $resultat ) {
		if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return; // Formulaire envoyé : déjà noté à l'ouverture du lien.
		}
		$empreinte = self::empreinte( $cle );
		if ( ! is_wp_error( $resultat ) ) {
			self::noter( $login, 'lien_accepte', $empreinte );
			return;
		}
		self::noter( $login, 'lien_refuse', $empreinte . ' · ' . $resultat->get_error_code() );
	}

	/* ------------------------------------------------------------------ */
	/* Contexte                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Extensions et fonctions à l'origine de l'événement (pile d'appels).
	 *
	 * @return string
	 */
	private static function source() {
		$sources = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- diagnostic.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $cadre ) {
			if ( ! empty( $cadre['class'] ) && 0 === strpos( $cadre['class'], 'ETR_' ) && 'ETR_Journal_Liens' !== $cadre['class'] ) {
				$sources[] = $cadre['class'] . '::' . $cadre['function'];
				break; // Fonction du plugin la plus proche : suffisant.
			}
			if ( ! empty( $cadre['file'] ) && preg_match( '#/(plugins|mu-plugins|themes)/([^/]+)#', wp_normalize_path( $cadre['file'] ), $m ) && 0 !== strpos( wp_normalize_path( $cadre['file'] ), wp_normalize_path( ETR_DIR ) ) ) {
				$sources[] = $m[1] . '/' . $m[2];
			}
		}
		return implode( ', ', array_unique( $sources ) );
	}

	/**
	 * Requête en cours, sans paramètres (les liens contiennent la clé).
	 *
	 * @return string
	 */
	private static function requete() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'WP-CLI';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		$methode = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$chemin  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$vue     = isset( $_GET['vue'] ) ? sanitize_key( wp_unslash( $_GET['vue'] ) ) : ( isset( $_GET['etr_vue'] ) ? sanitize_key( wp_unslash( $_GET['etr_vue'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? self::agent( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		return trim( strtoupper( $methode ) . ' ' . $chemin . ( $vue ? ' vue=' . $vue : '' ) . ( $action ? ' action=' . $action : '' ) . ( $agent ? ' · ' . $agent : '' ) );
	}

	/**
	 * Navigateur ou robot, en court.
	 *
	 * @param string $agent User-Agent.
	 * @return string
	 */
	private static function agent( $agent ) {
		$agent = preg_replace( '#^Mozilla/5\.0 #', '', $agent );
		return strlen( $agent ) > 90 ? substr( $agent, 0, 90 ) . '…' : $agent;
	}
}
