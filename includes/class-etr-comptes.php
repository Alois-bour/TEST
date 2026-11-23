<?php
/**
 * Comptes traiteurs : connexion, mot de passe oublié et choix du mot de passe,
 * uniquement depuis la page « Espace traiteur ».
 *
 * Les traiteurs sont des utilisateurs WordPress (pour profiter de la sécurité
 * éprouvée de WordPress : chiffrement des mots de passe, sessions), mais ils ne
 * peuvent se connecter que par l'espace traiteur et n'ont accès à rien d'autre.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authentification des traiteurs.
 */
class ETR_Comptes {

	/**
	 * Nombre d'échecs de connexion tolérés avant blocage.
	 */
	const MAX_ECHECS = 5;

	/**
	 * Durée du blocage après trop d'échecs (secondes).
	 */
	const DUREE_BLOCAGE = 900;

	/**
	 * Vrai pendant une connexion lancée depuis l'espace traiteur.
	 *
	 * @var bool
	 */
	private static $via_espace = false;

	/**
	 * Erreurs à afficher.
	 *
	 * @var string[]
	 */
	private static $erreurs = array();

	/**
	 * Message de confirmation à afficher.
	 *
	 * @var string
	 */
	private static $succes = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'filtrer_authentification' ), 100, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'mots_de_passe_application' ), 10, 2 );
		add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'email_reinitialisation' ), 10, 4 );
		add_action( 'template_redirect', array( __CLASS__, 'traiter_formulaires' ), 5 );
		add_action( 'init', array( __CLASS__, 'deconnecter_si_desactive' ) );
		add_action( 'login_init', array( __CLASS__, 'page_connexion_wordpress' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Règles d'accès                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Compte traiteur désactivé ?
	 *
	 * @param int $user_id ID utilisateur.
	 * @return bool
	 */
	public static function est_desactive( $user_id ) {
		return '1' === get_user_meta( $user_id, '_etr_desactive', true );
	}

	/**
	 * Retrouve un utilisateur par identifiant ou e-mail.
	 *
	 * @param string $login Identifiant ou e-mail.
	 * @return WP_User|false
	 */
	private static function trouver_utilisateur( $login ) {
		$login = trim( (string) $login );
		if ( '' === $login ) {
			return false;
		}
		return is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
	}

	/**
	 * Erreur affichée aux traiteurs qui tentent de se connecter ailleurs que dans leur espace.
	 *
	 * @return WP_Error
	 */
	private static function erreur_hors_espace() {
		return new WP_Error(
			'etr_hors_espace',
			sprintf(
				/* translators: %s : URL de l'espace traiteur. */
				__( 'Les comptes traiteurs se connectent uniquement depuis <a href="%s">l’espace traiteur</a>.', 'espace-traiteur' ),
				esc_url( etr_url() )
			)
		);
	}

	/**
	 * Refuse toute connexion d'un traiteur en dehors de l'espace traiteur
	 * (wp-login.php, « Mon compte » WooCommerce, XML-RPC, API…).
	 *
	 * Le même message est renvoyé que le mot de passe soit juste ou faux,
	 * pour ne pas offrir de moyen de tester des mots de passe hors de l'espace
	 * (où les tentatives sont limitées).
	 *
	 * @param WP_User|WP_Error|null $user     Résultat de l'authentification.
	 * @param string                $username Identifiant saisi.
	 * @param string                $password Mot de passe saisi.
	 * @return WP_User|WP_Error|null
	 */
	public static function filtrer_authentification( $user, $username, $password ) {
		if ( ! $user instanceof WP_User ) {
			if ( ! self::$via_espace && $username ) {
				$cible = self::trouver_utilisateur( $username );
				if ( $cible && ETR_Access::traiteur_seulement( $cible->ID ) ) {
					return self::erreur_hors_espace();
				}
			}
			return $user;
		}

		if ( ! ETR_Access::traiteur_seulement( $user->ID ) ) {
			return $user;
		}
		if ( ! self::$via_espace ) {
			return self::erreur_hors_espace();
		}
		if ( self::est_desactive( $user->ID ) ) {
			return new WP_Error( 'etr_desactive', __( 'Votre accès à l’espace traiteur a été désactivé. Contactez-nous pour le réactiver.', 'espace-traiteur' ) );
		}
		return $user;
	}

	/**
	 * Pas de mots de passe d'application pour les traiteurs.
	 *
	 * @param bool    $disponible Valeur par défaut.
	 * @param WP_User $user       Utilisateur.
	 * @return bool
	 */
	public static function mots_de_passe_application( $disponible, $user ) {
		return ( $user instanceof WP_User && ETR_Access::traiteur_seulement( $user->ID ) ) ? false : $disponible;
	}

	/**
	 * Déconnecte un traiteur dont l'accès vient d'être désactivé.
	 */
	public static function deconnecter_si_desactive() {
		$user_id = get_current_user_id();
		if ( $user_id && ETR_Access::traiteur_seulement( $user_id ) && self::est_desactive( $user_id ) ) {
			wp_logout();
		}
	}

	/**
	 * Un traiteur connecté qui ouvre wp-login.php est renvoyé vers son espace.
	 */
	public static function page_connexion_wordpress() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $action, array( 'login', '' ), true ) && is_user_logged_in() && ETR_Access::traiteur_seulement( get_current_user_id() ) ) {
			wp_safe_redirect( etr_url() );
			exit;
		}
	}

	/**
	 * L'e-mail « mot de passe oublié » d'un traiteur renvoie vers son espace,
	 * jamais vers les écrans WordPress (vaut aussi quand la demande part de wp-login.php).
	 *
	 * @param array   $defaults   Destinataire, objet, message, en-têtes.
	 * @param string  $key        Clé de réinitialisation.
	 * @param string  $user_login Identifiant.
	 * @param WP_User $user_data  Utilisateur.
	 * @return array
	 */
	public static function email_reinitialisation( $defaults, $key, $user_login, $user_data ) {
		if ( ! $user_data instanceof WP_User || ! ETR_Access::traiteur_seulement( $user_data->ID ) ) {
			return $defaults;
		}
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		/* translators: %s : nom du site. */
		$defaults['subject'] = sprintf( __( '[%s] Nouveau mot de passe pour votre espace traiteur', 'espace-traiteur' ), $site );
		/* translators: %s : nom du traiteur. */
		$defaults['message']  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user_data ) ) . "\n\n";
		$defaults['message'] .= __( 'Vous avez demandé un nouveau mot de passe pour votre espace traiteur.', 'espace-traiteur' ) . "\n";
		/* translators: %s : identifiant. */
		$defaults['message'] .= sprintf( __( 'Votre identifiant : %s', 'espace-traiteur' ), $user_login ) . "\n\n";
		$defaults['message'] .= __( 'Choisissez votre nouveau mot de passe (lien valable 24 heures) :', 'espace-traiteur' ) . "\n";
		$defaults['message'] .= self::url_mot_de_passe( $key, $user_login ) . "\n\n";
		$defaults['message'] .= __( 'Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe actuel reste valable.', 'espace-traiteur' ) . "\n";
		return $defaults;
	}

	/**
	 * Lien « choisir mon mot de passe » de l'espace traiteur.
	 *
	 * @param string $key   Clé de réinitialisation.
	 * @param string $login Identifiant.
	 * @return string
	 */
	public static function url_mot_de_passe( $key, $login ) {
		return etr_url(
			array(
				'etr_vue' => 'mot-de-passe',
				'key'     => rawurlencode( $key ),
				'login'   => rawurlencode( $login ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Limitation des tentatives                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Clé de comptage des échecs : adresse IP + identifiant saisi.
	 *
	 * Compter par identifiant évite qu'un visiteur qui se trompe bloque tous les
	 * traiteurs quand le site est derrière un proxy ou un CDN (même IP pour tous).
	 *
	 * @param string $login Identifiant ou e-mail saisi.
	 * @return string
	 */
	private static function cle_echecs( $login ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'etr_echecs_' . md5( $ip . '|' . strtolower( trim( $login ) ) );
	}

	/**
	 * Cet identifiant est-il temporairement bloqué pour ce visiteur ?
	 *
	 * @param string $login Identifiant saisi.
	 * @return bool
	 */
	private static function est_bloque( $login ) {
		return (int) get_transient( self::cle_echecs( $login ) ) >= self::MAX_ECHECS;
	}

	/**
	 * Compte un échec.
	 *
	 * @param string $login Identifiant saisi.
	 */
	private static function compter_echec( $login ) {
		set_transient( self::cle_echecs( $login ), (int) get_transient( self::cle_echecs( $login ) ) + 1, self::DUREE_BLOCAGE );
	}

	/* ------------------------------------------------------------------ */
	/* Formulaires                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Traite les formulaires de connexion et de mot de passe.
	 */
	public static function traiter_formulaires() {
		if ( empty( $_POST['etr_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['etr_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'connexion' === $action ) {
			self::connexion();
		} elseif ( 'mot_de_passe_oublie' === $action ) {
			self::mot_de_passe_oublie();
		} elseif ( 'nouveau_mot_de_passe' === $action ) {
			self::nouveau_mot_de_passe();
		}
	}

	/**
	 * Connexion depuis l'espace traiteur.
	 */
	private static function connexion() {
		// phpcs:disable WordPress.Security.NonceVerification -- formulaire de connexion public, comme wp-login.php.
		$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$mdp   = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- mot de passe : jamais modifié.
		$garde = ! empty( $_POST['rememberme'] );
		// phpcs:enable

		if ( '' === $login || '' === $mdp ) {
			self::$erreurs[] = __( 'Saisissez votre identifiant et votre mot de passe.', 'espace-traiteur' );
			return;
		}
		if ( self::est_bloque( $login ) ) {
			self::$erreurs[] = __( 'Trop de tentatives de connexion. Réessayez dans 15 minutes.', 'espace-traiteur' );
			return;
		}

		self::$via_espace = true;
		$user             = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $mdp,
				'remember'      => $garde,
			),
			is_ssl()
		);
		self::$via_espace = false;

		if ( is_wp_error( $user ) ) {
			self::compter_echec( $login );
			self::$erreurs[] = 'etr_desactive' === $user->get_error_code()
				? $user->get_error_message()
				: __( 'Identifiant ou mot de passe incorrect.', 'espace-traiteur' );
			return;
		}

		if ( ! etr_est_traiteur( $user->ID ) ) {
			wp_logout();
			self::$erreurs[] = __( 'Cet espace est réservé aux traiteurs partenaires.', 'espace-traiteur' );
			return;
		}

		delete_transient( self::cle_echecs( $login ) );
		wp_safe_redirect( etr_url() );
		exit;
	}

	/**
	 * Demande de nouveau mot de passe.
	 */
	private static function mot_de_passe_oublie() {
		$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $login ) {
			self::$erreurs[] = __( 'Saisissez votre identifiant ou votre adresse e-mail.', 'espace-traiteur' );
			return;
		}
		$cle_oubli = 'oubli|' . $login;
		if ( self::est_bloque( $cle_oubli ) ) {
			self::$erreurs[] = __( 'Trop de demandes. Réessayez dans 15 minutes.', 'espace-traiteur' );
			return;
		}
		// Limite le nombre d'e-mails envoyés et les essais d'identifiants au hasard.
		self::compter_echec( $cle_oubli );

		$user = self::trouver_utilisateur( $login );
		if ( $user && ETR_Access::traiteur_seulement( $user->ID ) && ! self::est_desactive( $user->ID ) ) {
			retrieve_password( $user->user_login );
		}

		// Même message dans tous les cas : on ne révèle pas quels comptes existent.
		self::$succes = __( 'Si un compte traiteur correspond, un e-mail vient de vous être envoyé avec un lien pour choisir un nouveau mot de passe.', 'espace-traiteur' );
	}

	/**
	 * Choix d'un nouveau mot de passe (lien reçu par e-mail).
	 */
	private static function nouveau_mot_de_passe() {
		// phpcs:disable WordPress.Security.NonceVerification -- la clé de réinitialisation sert de jeton.
		$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
		$cle   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$mdp1  = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$mdp2  = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable

		$user = check_password_reset_key( $cle, $login );
		if ( is_wp_error( $user ) || ! ETR_Access::traiteur_seulement( $user->ID ) ) {
			self::$erreurs[] = __( 'Ce lien n’est plus valable. Demandez un nouveau lien ci-dessous.', 'espace-traiteur' );
			return;
		}
		if ( strlen( $mdp1 ) < 8 ) {
			self::$erreurs[] = __( 'Le mot de passe doit contenir au moins 8 caractères.', 'espace-traiteur' );
			return;
		}
		if ( $mdp1 !== $mdp2 ) {
			self::$erreurs[] = __( 'Les deux mots de passe ne sont pas identiques.', 'espace-traiteur' );
			return;
		}

		reset_password( $user, $mdp1 );
		delete_transient( self::cle_echecs( $user->user_login ) );
		delete_transient( self::cle_echecs( $user->user_email ) );
		wp_safe_redirect( etr_url( array( 'etr_msg' => 'mot-de-passe' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Affichage (visiteur non connecté)                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Vue de l'espace pour un visiteur non connecté.
	 */
	public static function vue() {
		$vue = isset( $_GET['etr_vue'] ) ? sanitize_key( wp_unslash( $_GET['etr_vue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="etr-connexion">';
		self::messages();
		if ( 'mot-de-passe-oublie' === $vue ) {
			self::vue_oubli();
		} elseif ( 'mot-de-passe' === $vue ) {
			self::vue_nouveau_mot_de_passe();
		} else {
			self::vue_connexion();
		}
		echo '</div>';
	}

	/**
	 * Messages.
	 */
	private static function messages() {
		$msg = isset( $_GET['etr_msg'] ) ? sanitize_key( wp_unslash( $_GET['etr_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'mot-de-passe' === $msg && ! self::$erreurs ) {
			self::$succes = __( 'Votre mot de passe est enregistré. Vous pouvez maintenant vous connecter.', 'espace-traiteur' );
		}
		if ( self::$succes ) {
			echo '<p class="etr-notice etr-notice--succes">' . esc_html( self::$succes ) . '</p>';
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
	 * Formulaire de connexion.
	 */
	private static function vue_connexion() {
		$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<h2>' . esc_html__( 'Connexion à votre espace traiteur', 'espace-traiteur' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( etr_url() ) . '" class="etr-form-connexion">';
		echo '<input type="hidden" name="etr_action" value="connexion">';
		echo '<p><label for="user_login">' . esc_html__( 'Identifiant ou e-mail', 'espace-traiteur' ) . '</label><input type="text" name="log" id="user_login" value="' . esc_attr( $login ) . '" autocomplete="username" required></p>';
		echo '<p><label for="user_pass">' . esc_html__( 'Mot de passe', 'espace-traiteur' ) . '</label><input type="password" name="pwd" id="user_pass" autocomplete="current-password" required></p>';
		echo '<p class="etr-se-souvenir"><label><input type="checkbox" name="rememberme" value="1"> ' . esc_html__( 'Rester connecté', 'espace-traiteur' ) . '</label></p>';
		echo '<p><button type="submit" id="wp-submit" class="etr-btn">' . esc_html__( 'Se connecter', 'espace-traiteur' ) . '</button></p>';
		echo '</form>';
		echo '<p><a href="' . esc_url( etr_url( array( 'etr_vue' => 'mot-de-passe-oublie' ) ) ) . '">' . esc_html__( 'Mot de passe oublié ?', 'espace-traiteur' ) . '</a></p>';
	}

	/**
	 * Formulaire « mot de passe oublié ».
	 */
	private static function vue_oubli() {
		echo '<h2>' . esc_html__( 'Mot de passe oublié', 'espace-traiteur' ) . '</h2>';
		if ( ! self::$succes ) {
			echo '<p>' . esc_html__( 'Saisissez votre identifiant ou votre adresse e-mail : vous recevrez un lien pour choisir un nouveau mot de passe.', 'espace-traiteur' ) . '</p>';
			echo '<form method="post" action="' . esc_url( etr_url( array( 'etr_vue' => 'mot-de-passe-oublie' ) ) ) . '" class="etr-form-connexion">';
			echo '<input type="hidden" name="etr_action" value="mot_de_passe_oublie">';
			echo '<p><label for="etr-oubli-login">' . esc_html__( 'Identifiant ou e-mail', 'espace-traiteur' ) . '</label><input type="text" name="user_login" id="etr-oubli-login" autocomplete="username" required></p>';
			echo '<p><button type="submit" class="etr-btn">' . esc_html__( 'Recevoir le lien', 'espace-traiteur' ) . '</button></p>';
			echo '</form>';
		}
		echo '<p><a href="' . esc_url( etr_url() ) . '">' . esc_html__( '← Retour à la connexion', 'espace-traiteur' ) . '</a></p>';
	}

	/**
	 * Formulaire « choisir mon mot de passe ».
	 */
	private static function vue_nouveau_mot_de_passe() {
		// phpcs:disable WordPress.Security.NonceVerification
		$login = isset( $_REQUEST['login'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['login'] ) ) : '';
		$cle   = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
		// phpcs:enable

		echo '<h2>' . esc_html__( 'Choisir votre mot de passe', 'espace-traiteur' ) . '</h2>';

		$user = check_password_reset_key( $cle, $login );
		if ( is_wp_error( $user ) || ! ETR_Access::traiteur_seulement( $user->ID ) ) {
			if ( ! self::$erreurs ) {
				echo '<p class="etr-notice etr-notice--erreur">' . esc_html__( 'Ce lien n’est plus valable (il expire après 24 heures ou une fois utilisé).', 'espace-traiteur' ) . '</p>';
			}
			echo '<p><a class="etr-btn" href="' . esc_url( etr_url( array( 'etr_vue' => 'mot-de-passe-oublie' ) ) ) . '">' . esc_html__( 'Demander un nouveau lien', 'espace-traiteur' ) . '</a></p>';
			return;
		}

		/* translators: %s : identifiant. */
		echo '<p>' . esc_html( sprintf( __( 'Identifiant : %s', 'espace-traiteur' ), $user->user_login ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( self::url_mot_de_passe( $cle, $login ) ) . '" class="etr-form-connexion">';
		echo '<input type="hidden" name="etr_action" value="nouveau_mot_de_passe">';
		echo '<input type="hidden" name="login" value="' . esc_attr( $login ) . '">';
		echo '<input type="hidden" name="key" value="' . esc_attr( $cle ) . '">';
		echo '<p><label for="etr-pass1">' . esc_html__( 'Nouveau mot de passe (8 caractères minimum)', 'espace-traiteur' ) . '</label><input type="password" name="pass1" id="etr-pass1" minlength="8" autocomplete="new-password" required></p>';
		echo '<p><label for="etr-pass2">' . esc_html__( 'Confirmer le mot de passe', 'espace-traiteur' ) . '</label><input type="password" name="pass2" id="etr-pass2" minlength="8" autocomplete="new-password" required></p>';
		echo '<p><button type="submit" class="etr-btn">' . esc_html__( 'Enregistrer mon mot de passe', 'espace-traiteur' ) . '</button></p>';
		echo '</form>';
	}
}
