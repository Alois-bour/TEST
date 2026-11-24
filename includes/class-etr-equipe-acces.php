<?php
/**
 * Accès de l'équipe à l'application : connexion, mot de passe oublié et choix du mot de passe,
 * tous dans l'application (jamais sur les écrans de WordPress).
 *
 * - Une application installée sur iPhone a ses propres cookies : tout doit se passer dans son périmètre.
 * - Un lien d'invitation ouvert alors qu'une autre personne est connectée (par exemple un
 *   administrateur sur le même téléphone) ne mélange jamais les comptes : l'application le dit
 *   et propose de se déconnecter avant de continuer.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écrans d'accès de l'application de l'équipe.
 */
class ETR_Equipe_Acces {

	/**
	 * Écran à afficher : connexion | oubli | mot-de-passe | autre-session ('' = application).
	 *
	 * @var string
	 */
	private static $ecran = '';

	/**
	 * Personne visée par le lien « choisir mon mot de passe » (si le lien est valable).
	 *
	 * @var WP_User|null
	 */
	private static $cible = null;

	/**
	 * Erreur à afficher.
	 *
	 * @var string
	 */
	private static $erreur = '';

	/**
	 * Confirmation à afficher.
	 *
	 * @var string
	 */
	private static $succes = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'email_reinitialisation' ), 20, 4 );
	}

	/**
	 * Lien « choisir mon mot de passe » dans l'application.
	 *
	 * @param string $cle   Clé de réinitialisation.
	 * @param string $login Identifiant.
	 * @return string
	 */
	public static function url_mot_de_passe( $cle, $login ) {
		return ETR_Gestion::url(
			array(
				'vue'   => 'mot-de-passe',
				'key'   => rawurlencode( $cle ),
				'login' => rawurlencode( $login ),
			)
		);
	}

	/**
	 * Écran d'accès en cours ('' si c'est l'application elle-même).
	 *
	 * @return string
	 */
	public static function ecran() {
		return self::$ecran;
	}

	/**
	 * Titre de l'onglet pour l'écran en cours.
	 *
	 * @return string
	 */
	public static function titre() {
		$titres = array(
			'connexion'     => __( 'Connexion', 'espace-traiteur' ),
			'oubli'         => __( 'Mot de passe oublié', 'espace-traiteur' ),
			'mot-de-passe'  => __( 'Choisir votre mot de passe', 'espace-traiteur' ),
			'autre-session' => __( 'Choisir votre mot de passe', 'espace-traiteur' ),
		);
		return isset( $titres[ self::$ecran ] ) ? $titres[ self::$ecran ] : '';
	}

	/**
	 * Personne autorisée à utiliser ces écrans : équipe (ou administrateur), jamais un traiteur.
	 *
	 * @param WP_User $user Utilisateur.
	 * @return bool
	 */
	private static function autorise( $user ) {
		return $user instanceof WP_User && ETR_Equipe::acces( $user->ID ) && ! ETR_Access::traiteur_seulement( $user->ID );
	}

	/* ------------------------------------------------------------------ */
	/* Aiguillage (appelé par ETR_Gestion::controleur)                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Traite les formulaires d'accès et choisit l'écran.
	 *
	 * @return bool Vrai si un écran d'accès doit s'afficher à la place de l'application.
	 */
	public static function preparer() {
		// phpcs:disable WordPress.Security.NonceVerification -- formulaires publics (vérifications ci-dessous).
		$vue    = isset( $_GET['vue'] ) ? sanitize_key( wp_unslash( $_GET['vue'] ) ) : '';
		$action = isset( $_POST['etr_gestion_action'] ) ? sanitize_key( wp_unslash( $_POST['etr_gestion_action'] ) ) : '';
		// phpcs:enable

		if ( 'mot-de-passe' === $vue ) {
			self::preparer_mot_de_passe( $action );
			return true;
		}
		if ( is_user_logged_in() ) {
			return false;
		}
		if ( 'mot-de-passe-oublie' === $vue ) {
			if ( 'mot_de_passe_oublie' === $action ) {
				self::action_oubli();
			}
			self::$ecran = 'oubli';
			return true;
		}
		if ( 'connexion' === $action ) {
			self::action_connexion();
		}
		self::$ecran = 'connexion';
		return true;
	}

	/**
	 * Lien « choisir mon mot de passe » : clé vérifiée, autre session signalée, formulaire traité.
	 *
	 * @param string $action Action envoyée.
	 */
	private static function preparer_mot_de_passe( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- la clé de réinitialisation sert de jeton.
		$login = isset( $_REQUEST['login'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['login'] ) ) : '';
		$cle   = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
		// phpcs:enable

		$user        = check_password_reset_key( $cle, $login );
		self::$cible = ( ! is_wp_error( $user ) && self::autorise( $user ) ) ? $user : null;

		// Quelqu'un d'autre est connecté sur cet appareil : on ne mélange pas les comptes.
		if ( self::$cible && is_user_logged_in() && get_current_user_id() !== self::$cible->ID ) {
			if ( 'deconnexion_lien' === $action && check_admin_referer( 'etr_deconnexion_lien', 'etr_nonce' ) ) {
				wp_logout();
				wp_safe_redirect( self::url_mot_de_passe( $cle, $login ) );
				exit;
			}
			self::$ecran = 'autre-session';
			return;
		}

		if ( self::$cible && 'nouveau_mot_de_passe' === $action ) {
			self::action_nouveau_mot_de_passe();
		}
		self::$ecran = 'mot-de-passe';
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Connexion d'un membre de l'équipe.
	 */
	private static function action_connexion() {
		// phpcs:disable WordPress.Security.NonceVerification -- formulaire de connexion public, comme wp-login.php.
		$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$mdp   = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- mot de passe : jamais modifié.
		$garde = ! empty( $_POST['rememberme'] );
		// phpcs:enable
		$cle = 'equipe|' . $login;

		if ( '' === $login || '' === $mdp ) {
			self::$erreur = __( 'Saisissez votre identifiant et votre mot de passe.', 'espace-traiteur' );
			return;
		}
		if ( ETR_Comptes::est_bloque( $cle ) ) {
			self::$erreur = __( 'Trop de tentatives de connexion. Réessayez dans 15 minutes.', 'espace-traiteur' );
			return;
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $mdp,
				'remember'      => $garde,
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			ETR_Comptes::compter_echec( $cle );
			self::$erreur = 'etr_hors_espace' === $user->get_error_code()
				? __( 'Les traiteurs se connectent depuis l’espace traiteur, pas depuis l’application de l’équipe.', 'espace-traiteur' )
				: __( 'Identifiant ou mot de passe incorrect.', 'espace-traiteur' );
			return;
		}
		if ( ! ETR_Equipe::acces( $user->ID ) ) {
			wp_logout();
			self::$erreur = __( 'Cette application est réservée à l’équipe. Si votre accès a été retiré, contactez le bureau.', 'espace-traiteur' );
			return;
		}
		delete_transient( ETR_Comptes::cle_echecs( $cle ) );
		wp_safe_redirect( ETR_Gestion::url() );
		exit;
	}

	/**
	 * Demande d'un nouveau lien (même message que le compte existe ou non).
	 */
	private static function action_oubli() {
		$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $login ) {
			self::$erreur = __( 'Saisissez votre identifiant ou votre adresse e-mail.', 'espace-traiteur' );
			return;
		}
		$cle = 'oubli-equipe|' . $login;
		if ( ETR_Comptes::est_bloque( $cle ) ) {
			self::$erreur = __( 'Trop de demandes. Réessayez dans 15 minutes.', 'espace-traiteur' );
			return;
		}
		ETR_Comptes::compter_echec( $cle ); // Limite les e-mails envoyés et les essais au hasard.

		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( $user && self::autorise( $user ) ) {
			retrieve_password( $user->user_login );
		}
		self::$succes = __( 'Si un compte de l’équipe correspond, un e-mail vient de vous être envoyé avec un lien pour choisir un nouveau mot de passe.', 'espace-traiteur' );
	}

	/**
	 * Enregistre le nouveau mot de passe puis renvoie vers la connexion, identifiant prérempli.
	 */
	private static function action_nouveau_mot_de_passe() {
		// phpcs:disable WordPress.Security.NonceVerification -- la clé de réinitialisation sert de jeton.
		$mdp1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- mot de passe : jamais modifié.
		$mdp2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		if ( strlen( $mdp1 ) < 8 ) {
			self::$erreur = __( 'Le mot de passe doit contenir au moins 8 caractères.', 'espace-traiteur' );
			return;
		}
		if ( $mdp1 !== $mdp2 ) {
			self::$erreur = __( 'Les deux mots de passe ne sont pas identiques.', 'espace-traiteur' );
			return;
		}
		$login = self::$cible->user_login;
		reset_password( self::$cible, $mdp1 );
		delete_transient( ETR_Comptes::cle_echecs( 'equipe|' . $login ) );
		wp_safe_redirect(
			ETR_Gestion::url(
				array(
					'etr_msg' => 'mot-de-passe',
					'log'     => rawurlencode( $login ),
				)
			)
		);
		exit;
	}

	/**
	 * L'e-mail « mot de passe oublié » d'un membre de l'équipe mène à l'application
	 * (les administrateurs gardent le lien habituel de WordPress).
	 *
	 * @param array   $defaults   Destinataire, objet, message, en-têtes.
	 * @param string  $key        Clé de réinitialisation.
	 * @param string  $user_login Identifiant.
	 * @param WP_User $user_data  Utilisateur.
	 * @return array
	 */
	public static function email_reinitialisation( $defaults, $key, $user_login, $user_data ) {
		if ( ! $user_data instanceof WP_User || ! ETR_Equipe::roles_equipe( $user_data ) || in_array( 'administrator', (array) $user_data->roles, true ) ) {
			return $defaults;
		}
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		/* translators: %s : nom du site. */
		$defaults['subject'] = sprintf( __( '[%s] Nouveau mot de passe pour l’application de l’équipe', 'espace-traiteur' ), $site );
		/* translators: %s : prénom et nom. */
		$defaults['message']  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user_data ) ) . "\n\n";
		$defaults['message'] .= __( 'Vous avez demandé un nouveau mot de passe pour l’application de l’équipe.', 'espace-traiteur' ) . "\n";
		/* translators: %s : identifiant. */
		$defaults['message'] .= sprintf( __( 'Votre identifiant : %s', 'espace-traiteur' ), $user_login ) . "\n\n";
		$defaults['message'] .= __( 'Choisissez votre nouveau mot de passe (lien valable 24 heures) :', 'espace-traiteur' ) . "\n" . self::url_mot_de_passe( $key, $user_login ) . "\n\n";
		$defaults['message'] .= __( 'Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe actuel reste valable.', 'espace-traiteur' ) . "\n";
		return $defaults;
	}

	/* ------------------------------------------------------------------ */
	/* Affichage                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Affiche l'écran d'accès en cours.
	 */
	public static function afficher() {
		$logo_id = ETR_Comptes::logo_id();
		$site    = get_bloginfo( 'name' );
		echo '<div class="etr-auth etr-auth--app"><div class="etr-auth__panneau"><div class="etr-auth__contenu">';
		echo '<span class="etr-auth__logo">' . ( $logo_id ? wp_get_attachment_image( $logo_id, 'medium', false, array( 'alt' => $site ) ) : '<span class="etr-auth__nom-site">' . esc_html( $site ) . '</span>' ) . '</span>';
		echo '<p class="etr-auth__surtitre">' . esc_html__( 'Application de l’équipe', 'espace-traiteur' ) . '</p>';

		switch ( self::$ecran ) {
			case 'oubli':
				self::ecran_oubli();
				break;
			case 'mot-de-passe':
				self::ecran_mot_de_passe();
				break;
			case 'autre-session':
				self::ecran_autre_session();
				break;
			default:
				self::ecran_connexion();
		}
		echo '</div></div></div>';
	}

	/**
	 * Titre, introduction et messages d'un écran.
	 *
	 * @param string $titre Titre.
	 * @param string $intro Introduction.
	 */
	private static function entete( $titre, $intro ) {
		echo '<h1 class="etr-auth__titre">' . esc_html( $titre ) . '</h1>';
		if ( $intro ) {
			echo '<p class="etr-auth__intro">' . esc_html( $intro ) . '</p>';
		}
		if ( self::$succes ) {
			echo '<div class="etr-auth__message etr-auth__message--succes" role="status"><p>' . esc_html( self::$succes ) . '</p></div>';
		}
		if ( self::$erreur ) {
			echo '<div class="etr-auth__message etr-auth__message--erreur" role="alert"><p>' . esc_html( self::$erreur ) . '</p></div>';
		}
	}

	/**
	 * Bouton d'envoi.
	 *
	 * @param string $libelle Libellé.
	 * @param string $id      ID HTML (facultatif).
	 */
	private static function bouton( $libelle, $id = '' ) {
		echo '<button type="submit"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' class="etr-auth__bouton" data-chargement="' . esc_attr__( 'Un instant…', 'espace-traiteur' ) . '"><span>' . esc_html( $libelle ) . '</span></button>';
	}

	/**
	 * Champ mot de passe avec bouton « Afficher ».
	 *
	 * @param string $id           ID HTML.
	 * @param string $nom          Nom du champ envoyé au serveur.
	 * @param string $libelle      Libellé.
	 * @param string $autocomplete Valeur autocomplete.
	 * @param string $extra        Attributs fixes supplémentaires.
	 */
	private static function champ_mot_de_passe( $id, $nom, $libelle, $autocomplete, $extra = '' ) {
		echo '<div class="etr-auth__champ"><label for="' . esc_attr( $id ) . '">' . esc_html( $libelle ) . '</label><div class="etr-auth__saisie etr-auth__saisie--mdp">';
		echo '<input type="password" name="' . esc_attr( $nom ) . '" id="' . esc_attr( $id ) . '" autocomplete="' . esc_attr( $autocomplete ) . '" required' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- attributs fixes écrits dans ce fichier.
		echo '<button type="button" class="etr-auth__oeil" aria-controls="' . esc_attr( $id ) . '" aria-pressed="false" data-afficher="' . esc_attr__( 'Afficher', 'espace-traiteur' ) . '" data-masquer="' . esc_attr__( 'Masquer', 'espace-traiteur' ) . '">' . esc_html__( 'Afficher', 'espace-traiteur' ) . '</button></div></div>';
	}

	/**
	 * Écran de connexion.
	 */
	private static function ecran_connexion() {
		// phpcs:disable WordPress.Security.NonceVerification -- préremplissage et message après redirection.
		$login = isset( $_REQUEST['log'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['log'] ) ) : '';
		if ( isset( $_GET['etr_msg'] ) && 'mot-de-passe' === $_GET['etr_msg'] && ! self::$erreur ) {
			self::$succes = __( 'Votre mot de passe est enregistré. Connectez-vous.', 'espace-traiteur' );
		}
		// phpcs:enable
		self::entete( __( 'Bonjour', 'espace-traiteur' ), __( 'Connectez-vous pour voir le planning, les demandes et les livraisons.', 'espace-traiteur' ) );
		echo '<form method="post" action="' . esc_url( ETR_Gestion::url() ) . '" class="etr-auth__form" novalidate>';
		echo '<input type="hidden" name="etr_gestion_action" value="connexion">';
		echo '<div class="etr-auth__champ"><label for="user_login">' . esc_html__( 'Identifiant ou e-mail', 'espace-traiteur' ) . '</label><div class="etr-auth__saisie"><input type="text" name="log" id="user_login" value="' . esc_attr( $login ) . '" autocomplete="username" autocapitalize="none" spellcheck="false" required' . ( $login ? '' : ' autofocus' ) . '></div></div>';
		self::champ_mot_de_passe( 'user_pass', 'pwd', __( 'Mot de passe', 'espace-traiteur' ), 'current-password', $login ? ' autofocus' : '' );
		echo '<div class="etr-auth__ligne"><label class="etr-auth__case"><input type="checkbox" name="rememberme" value="1" checked> <span>' . esc_html__( 'Rester connecté', 'espace-traiteur' ) . '</span></label>';
		echo '<a href="' . esc_url( ETR_Gestion::url( array( 'vue' => 'mot-de-passe-oublie' ) ) ) . '">' . esc_html__( 'Mot de passe oublié ?', 'espace-traiteur' ) . '</a></div>';
		self::bouton( __( 'Se connecter', 'espace-traiteur' ), 'wp-submit' );
		echo '</form>';
	}

	/**
	 * Écran « mot de passe oublié ».
	 */
	private static function ecran_oubli() {
		self::entete( __( 'Mot de passe oublié', 'espace-traiteur' ), self::$succes ? '' : __( 'Indiquez votre identifiant ou votre adresse e-mail : vous recevrez un lien pour choisir un nouveau mot de passe.', 'espace-traiteur' ) );
		if ( ! self::$succes ) {
			echo '<form method="post" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'mot-de-passe-oublie' ) ) ) . '" class="etr-auth__form" novalidate>';
			echo '<input type="hidden" name="etr_gestion_action" value="mot_de_passe_oublie">';
			echo '<div class="etr-auth__champ"><label for="etr-oubli">' . esc_html__( 'Identifiant ou e-mail', 'espace-traiteur' ) . '</label><div class="etr-auth__saisie"><input type="text" name="user_login" id="etr-oubli" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></div></div>';
			self::bouton( __( 'Recevoir le lien', 'espace-traiteur' ) );
			echo '</form>';
		}
		echo '<p class="etr-auth__retour"><a href="' . esc_url( ETR_Gestion::url() ) . '">' . esc_html__( '← Retour à la connexion', 'espace-traiteur' ) . '</a></p>';
	}

	/**
	 * Écran « choisir votre mot de passe » (ou lien plus valable).
	 */
	private static function ecran_mot_de_passe() {
		if ( ! self::$cible ) {
			self::entete( __( 'Lien expiré', 'espace-traiteur' ), __( 'Ce lien n’est plus valable : il ne sert qu’une fois, expire après 24 heures, et une invitation plus récente l’annule. Demandez un nouveau lien, il arrive en quelques instants.', 'espace-traiteur' ) );
			echo '<a class="etr-auth__bouton" href="' . esc_url( ETR_Gestion::url( array( 'vue' => 'mot-de-passe-oublie' ) ) ) . '"><span>' . esc_html__( 'Recevoir un nouveau lien', 'espace-traiteur' ) . '</span></a>';
			echo '<p class="etr-auth__retour"><a href="' . esc_url( ETR_Gestion::url() ) . '">' . esc_html__( '← Retour à la connexion', 'espace-traiteur' ) . '</a></p>';
			return;
		}
		$login = self::$cible->user_login;
		/* translators: 1 : prénom et nom, 2 : identifiant. */
		self::entete( __( 'Choisissez votre mot de passe', 'espace-traiteur' ), sprintf( __( '%1$s — identifiant : %2$s. Au moins 8 caractères.', 'espace-traiteur' ), etr_nom( self::$cible ), $login ) );
		// phpcs:disable WordPress.Security.NonceVerification
		$cle = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
		// phpcs:enable
		echo '<form method="post" action="' . esc_url( self::url_mot_de_passe( $cle, $login ) ) . '" class="etr-auth__form" novalidate>';
		echo '<input type="hidden" name="etr_gestion_action" value="nouveau_mot_de_passe">';
		// Aide les gestionnaires de mots de passe (trousseau iCloud) à associer le mot de passe au bon identifiant.
		echo '<input type="text" name="etr_identifiant" value="' . esc_attr( $login ) . '" autocomplete="username" class="etr-auth__cache" tabindex="-1" aria-hidden="true" readonly>';
		self::champ_mot_de_passe( 'pass1', 'pass1', __( 'Nouveau mot de passe', 'espace-traiteur' ), 'new-password', ' minlength="8" autofocus' );
		self::champ_mot_de_passe( 'pass2', 'pass2', __( 'Confirmer le mot de passe', 'espace-traiteur' ), 'new-password', ' minlength="8"' );
		self::bouton( __( 'Enregistrer mon mot de passe', 'espace-traiteur' ) );
		echo '</form>';
	}

	/**
	 * Une autre personne est connectée sur cet appareil : on le dit, et on propose de se déconnecter.
	 */
	private static function ecran_autre_session() {
		$moi = wp_get_current_user();
		/* translators: 1 : personne visée par le lien, 2 : personne connectée. */
		self::entete( __( 'Une autre personne est connectée', 'espace-traiteur' ), sprintf( __( 'Ce lien permet à %1$s de choisir son mot de passe, mais cet appareil est connecté au compte de %2$s. Déconnectez ce compte pour continuer.', 'espace-traiteur' ), etr_nom( self::$cible ), etr_nom( $moi ) ) );
		// phpcs:disable WordPress.Security.NonceVerification
		$cle = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
		// phpcs:enable
		echo '<form method="post" action="' . esc_url( self::url_mot_de_passe( $cle, self::$cible->user_login ) ) . '" class="etr-auth__form">';
		wp_nonce_field( 'etr_deconnexion_lien', 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="deconnexion_lien">';
		/* translators: %s : personne connectée. */
		self::bouton( sprintf( __( 'Déconnecter %s et continuer', 'espace-traiteur' ), etr_nom( $moi ) ) );
		echo '</form>';
		echo '<p class="etr-auth__retour"><a href="' . esc_url( ETR_Gestion::url() ) . '">' . esc_html__( 'Annuler et rester connecté', 'espace-traiteur' ) . '</a></p>';
	}
}
