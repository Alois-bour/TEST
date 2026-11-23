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

		// Images par défaut : nouvelle recherche après un changement.
		foreach ( array( 'update_option_etr_logo', 'update_option_etr_image_connexion', 'add_attachment', 'delete_attachment', 'update_option_elementor_active_kit' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'oublier_defauts' ) );
		}
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
	 * Vue de l'espace pour un visiteur non connecté : écran de connexion en deux panneaux.
	 */
	public static function vue() {
		$vue = isset( $_GET['etr_vue'] ) ? sanitize_key( wp_unslash( $_GET['etr_vue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="etr-auth">';
		self::panneau_visuel();
		echo '<div class="etr-auth__panneau">';
		echo '<a class="etr-auth__retour-site" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( '← Retour au site', 'espace-traiteur' ) . '</a>';
		echo '<div class="etr-auth__contenu">';
		self::logo();

		if ( 'mot-de-passe-oublie' === $vue ) {
			self::vue_oubli();
		} elseif ( 'mot-de-passe' === $vue ) {
			self::vue_nouveau_mot_de_passe();
		} else {
			self::vue_connexion();
		}

		$contact = get_option( 'etr_email_equipe' );
		$contact = is_email( $contact ) ? $contact : get_option( 'admin_email' );
		echo '<p class="etr-auth__pied">' . esc_html__( 'Vous êtes traiteur et souhaitez un accès ?', 'espace-traiteur' ) . ' <a href="mailto:' . esc_attr( antispambot( $contact ) ) . '">' . esc_html__( 'Contactez-nous', 'espace-traiteur' ) . '</a></p>';
		echo '</div></div></div>';
	}

	/**
	 * Panneau de gauche : photo d'ambiance et accroche.
	 */
	private static function panneau_visuel() {
		$image_id = self::photo_id();
		$classe   = 'etr-auth__visuel' . ( $image_id ? '' : ' etr-auth__visuel--sans-image' );

		echo '<div class="' . esc_attr( $classe ) . '" aria-hidden="true">';
		if ( $image_id ) {
			echo wp_get_attachment_image(
				$image_id,
				'large',
				false,
				array(
					'class'   => 'etr-auth__image',
					'alt'     => '',
					'loading' => 'eager',
					'sizes'   => '(max-width: 860px) 100vw, 50vw',
				)
			);
		}
		echo '<div class="etr-auth__accroche">';
		echo '<p class="etr-auth__surtitre">' . esc_html__( 'Espace professionnel', 'espace-traiteur' ) . '</p>';
		echo '<p class="etr-auth__slogan">' . esc_html( apply_filters( 'etr_slogan_connexion', __( 'Mobilier, art de la table et décoration pour sublimer vos réceptions.', 'espace-traiteur' ) ) ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * Logo de la page de connexion : réglage, sinon logo du site détecté automatiquement.
	 *
	 * @param bool $defaut_seulement Ignorer le réglage (aperçu du choix par défaut).
	 * @return int ID de pièce jointe, 0 si aucun.
	 */
	public static function logo_id( $defaut_seulement = false ) {
		$id = $defaut_seulement ? 0 : (int) get_option( 'etr_logo' );
		if ( $id && wp_attachment_is_image( $id ) ) {
			return $id;
		}
		return self::defaut(
			'logo',
			function () {
				// 1. Logo du thème (Apparence › Personnaliser › Identité du site).
				$id = (int) get_theme_mod( 'custom_logo' );
				if ( $id ) {
					return $id;
				}
				// 2. Logo des réglages du site Elementor.
				$kit = (int) get_option( 'elementor_active_kit' );
				if ( $kit ) {
					$reglages = get_post_meta( $kit, '_elementor_page_settings', true );
					if ( ! empty( $reglages['site_logo']['id'] ) ) {
						return (int) $reglages['site_logo']['id'];
					}
				}
				// 3. Fichier du logo dans la médiathèque.
				return self::chercher_image( (array) apply_filters( 'etr_logos_par_defaut', array( 'maison-aname-logo1', 'maison-aname-logo', 'logo' ) ) );
			}
		);
	}

	/**
	 * Photo d'ambiance : réglage, sinon une photo du site détectée automatiquement.
	 *
	 * @param bool $defaut_seulement Ignorer le réglage (aperçu du choix par défaut).
	 * @return int ID de pièce jointe, 0 si aucune.
	 */
	public static function photo_id( $defaut_seulement = false ) {
		$id = $defaut_seulement ? 0 : (int) get_option( 'etr_image_connexion' );
		if ( $id && wp_attachment_is_image( $id ) ) {
			return $id;
		}
		return self::defaut(
			'photo',
			function () {
				// 1. Photos d'ambiance de la page d'accueil de maisonaname.com.
				$id = self::chercher_image(
					(array) apply_filters(
						'etr_photos_par_defaut',
						array( 'location-art-de-la-table-mariage-nancy', 'location-vaisselle-mariage', 'location-mobilier-mariage-decoration-maison-anna-nancy' )
					)
				);
				if ( $id ) {
					return $id;
				}
				// 2. Image d'une catégorie de produits WooCommerce.
				foreach ( array( 'art-de-la-table', 'mobilier', 'decoration' ) as $slug ) {
					$terme = get_term_by( 'slug', $slug, 'product_cat' );
					$id    = $terme ? (int) get_term_meta( $terme->term_id, 'thumbnail_id', true ) : 0;
					if ( $id ) {
						return $id;
					}
				}
				// 3. Image mise en avant de la page d'accueil.
				$accueil = (int) get_option( 'page_on_front' );
				return $accueil ? (int) get_post_thumbnail_id( $accueil ) : 0;
			}
		);
	}

	/**
	 * Image par défaut, mémorisée 12 h pour ne pas refaire la recherche à chaque visite.
	 *
	 * @param string   $type      « logo » ou « photo ».
	 * @param callable $recherche Recherche à lancer si rien n'est mémorisé.
	 * @return int
	 */
	private static function defaut( $type, $recherche ) {
		$cle = 'etr_' . $type . '_defaut';
		$id  = get_transient( $cle );
		if ( false === $id || ( $id && ! wp_attachment_is_image( (int) $id ) ) ) {
			$id = (int) call_user_func( $recherche );
			$id = ( $id && wp_attachment_is_image( $id ) ) ? $id : 0;
			set_transient( $cle, (string) $id, 12 * HOUR_IN_SECONDS );
		}
		return (int) $id;
	}

	/**
	 * Oublie les images par défaut mémorisées (après un changement de réglages ou de médiathèque).
	 */
	public static function oublier_defauts() {
		delete_transient( 'etr_logo_defaut' );
		delete_transient( 'etr_photo_defaut' );
	}

	/**
	 * Cherche une image de la médiathèque d'après le nom de son fichier (sans extension).
	 *
	 * @param string[] $noms Noms de fichiers, par ordre de préférence.
	 * @return int
	 */
	private static function chercher_image( $noms ) {
		foreach ( $noms as $nom ) {
			$nom = sanitize_file_name( $nom );
			if ( '' === $nom ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image',
					'numberposts'    => 1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						'relation' => 'OR',
						array(
							'key'     => '_wp_attached_file',
							'value'   => '/' . $nom . '.',
							'compare' => 'LIKE',
						),
						array(
							'key'     => '_wp_attached_file',
							'value'   => '/' . $nom . '-scaled.',
							'compare' => 'LIKE',
						),
					),
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		return 0;
	}

	/**
	 * Logo (réglage, sinon logo du site détecté, sinon nom du site).
	 */
	private static function logo() {
		$logo_id = self::logo_id();
		$site    = get_bloginfo( 'name' );

		echo '<a class="etr-auth__logo" href="' . esc_url( home_url( '/' ) ) . '">';
		if ( $logo_id ) {
			echo wp_get_attachment_image( $logo_id, 'medium', false, array( 'alt' => $site ) );
		} else {
			echo '<span class="etr-auth__nom-site">' . esc_html( $site ) . '</span>';
		}
		echo '</a>';
	}

	/**
	 * En-tête d'un écran : surtitre, titre, introduction.
	 *
	 * @param string $titre Titre.
	 * @param string $intro Introduction.
	 */
	private static function entete( $titre, $intro ) {
		echo '<p class="etr-auth__surtitre">' . esc_html__( 'Espace traiteur', 'espace-traiteur' ) . '</p>';
		echo '<h1 class="etr-auth__titre">' . esc_html( $titre ) . '</h1>';
		if ( $intro ) {
			echo '<p class="etr-auth__intro">' . esc_html( $intro ) . '</p>';
		}
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
			echo '<div class="etr-auth__message etr-auth__message--succes" role="status">' . esc_html( self::$succes ) . '</div>';
		}
		if ( self::$erreurs ) {
			echo '<div class="etr-auth__message etr-auth__message--erreur" role="alert">';
			foreach ( self::$erreurs as $erreur ) {
				echo '<p>' . esc_html( $erreur ) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Un champ de formulaire.
	 *
	 * @param array $args id, name, label, type, value, autocomplete, extra.
	 */
	private static function champ( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'         => 'text',
				'value'        => '',
				'autocomplete' => '',
				'extra'        => '',
			)
		);
		$mdp  = 'password' === $args['type'];

		echo '<div class="etr-auth__champ">';
		echo '<label for="' . esc_attr( $args['id'] ) . '">' . esc_html( $args['label'] ) . '</label>';
		echo '<div class="etr-auth__saisie' . ( $mdp ? ' etr-auth__saisie--mdp' : '' ) . '">';
		printf(
			'<input type="%1$s" name="%2$s" id="%3$s" value="%4$s" autocomplete="%5$s" required%6$s>',
			esc_attr( $args['type'] ),
			esc_attr( $args['name'] ),
			esc_attr( $args['id'] ),
			esc_attr( $args['value'] ),
			esc_attr( $args['autocomplete'] ),
			$args['extra'] // phpcs:ignore WordPress.Security.EscapeOutput -- attributs fixes écrits dans ce fichier.
		);
		if ( $mdp ) {
			echo '<button type="button" class="etr-auth__oeil" aria-controls="' . esc_attr( $args['id'] ) . '" aria-pressed="false" data-afficher="' . esc_attr__( 'Afficher', 'espace-traiteur' ) . '" data-masquer="' . esc_attr__( 'Masquer', 'espace-traiteur' ) . '">' . esc_html__( 'Afficher', 'espace-traiteur' ) . '</button>';
		}
		echo '</div></div>';
	}

	/**
	 * Bouton d'envoi.
	 *
	 * @param string $libelle Libellé.
	 * @param string $id      ID HTML.
	 */
	private static function bouton( $libelle, $id = '' ) {
		echo '<button type="submit"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' class="etr-auth__bouton" data-chargement="' . esc_attr__( 'Un instant…', 'espace-traiteur' ) . '"><span>' . esc_html( $libelle ) . '</span></button>';
	}

	/**
	 * Formulaire de connexion.
	 */
	private static function vue_connexion() {
		$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		self::entete( __( 'Bienvenue', 'espace-traiteur' ), __( 'Connectez-vous pour préparer vos demandes de mobilier et d’art de la table, et suivre leur traitement.', 'espace-traiteur' ) );
		self::messages();

		echo '<form method="post" action="' . esc_url( etr_url() ) . '" class="etr-auth__form" novalidate>';
		echo '<input type="hidden" name="etr_action" value="connexion">';
		self::champ(
			array(
				'id'           => 'user_login',
				'name'         => 'log',
				'label'        => __( 'Identifiant ou e-mail', 'espace-traiteur' ),
				'value'        => $login,
				'autocomplete' => 'username',
				'extra'        => ' autocapitalize="none" spellcheck="false"' . ( $login ? '' : ' autofocus' ),
			)
		);
		self::champ(
			array(
				'id'           => 'user_pass',
				'name'         => 'pwd',
				'label'        => __( 'Mot de passe', 'espace-traiteur' ),
				'type'         => 'password',
				'autocomplete' => 'current-password',
				'extra'        => $login ? ' autofocus' : '',
			)
		);
		echo '<div class="etr-auth__ligne">';
		echo '<label class="etr-auth__case"><input type="checkbox" name="rememberme" value="1"> <span>' . esc_html__( 'Rester connecté', 'espace-traiteur' ) . '</span></label>';
		echo '<a href="' . esc_url( etr_url( array( 'etr_vue' => 'mot-de-passe-oublie' ) ) ) . '">' . esc_html__( 'Mot de passe oublié ?', 'espace-traiteur' ) . '</a>';
		echo '</div>';
		self::bouton( __( 'Se connecter', 'espace-traiteur' ), 'wp-submit' );
		echo '</form>';
	}

	/**
	 * Formulaire « mot de passe oublié ».
	 */
	private static function vue_oubli() {
		self::entete( __( 'Mot de passe oublié', 'espace-traiteur' ), self::$succes ? '' : __( 'Indiquez votre identifiant ou votre adresse e-mail : nous vous envoyons un lien pour choisir un nouveau mot de passe.', 'espace-traiteur' ) );
		self::messages();

		if ( ! self::$succes ) {
			echo '<form method="post" action="' . esc_url( etr_url( array( 'etr_vue' => 'mot-de-passe-oublie' ) ) ) . '" class="etr-auth__form" novalidate>';
			echo '<input type="hidden" name="etr_action" value="mot_de_passe_oublie">';
			self::champ(
				array(
					'id'           => 'etr-oubli-login',
					'name'         => 'user_login',
					'label'        => __( 'Identifiant ou e-mail', 'espace-traiteur' ),
					'autocomplete' => 'username',
					'extra'        => ' autocapitalize="none" spellcheck="false" autofocus',
				)
			);
			self::bouton( __( 'Recevoir le lien', 'espace-traiteur' ) );
			echo '</form>';
		}
		echo '<p class="etr-auth__retour"><a href="' . esc_url( etr_url() ) . '">' . esc_html__( '← Retour à la connexion', 'espace-traiteur' ) . '</a></p>';
	}

	/**
	 * Formulaire « choisir mon mot de passe ».
	 */
	private static function vue_nouveau_mot_de_passe() {
		// phpcs:disable WordPress.Security.NonceVerification
		$login = isset( $_REQUEST['login'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['login'] ) ) : '';
		$cle   = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
		// phpcs:enable

		$user = check_password_reset_key( $cle, $login );
		if ( is_wp_error( $user ) || ! ETR_Access::traiteur_seulement( $user->ID ) ) {
			self::entete( __( 'Lien expiré', 'espace-traiteur' ), __( 'Ce lien n’est plus valable : il expire après 24 heures ou une fois utilisé. Demandez-en un nouveau, il arrive en quelques instants.', 'espace-traiteur' ) );
			self::messages();
			echo '<a class="etr-auth__bouton" href="' . esc_url( etr_url( array( 'etr_vue' => 'mot-de-passe-oublie' ) ) ) . '"><span>' . esc_html__( 'Demander un nouveau lien', 'espace-traiteur' ) . '</span></a>';
			echo '<p class="etr-auth__retour"><a href="' . esc_url( etr_url() ) . '">' . esc_html__( '← Retour à la connexion', 'espace-traiteur' ) . '</a></p>';
			return;
		}

		/* translators: %s : identifiant. */
		self::entete( __( 'Choisissez votre mot de passe', 'espace-traiteur' ), sprintf( __( 'Identifiant : %s. Choisissez un mot de passe d’au moins 8 caractères.', 'espace-traiteur' ), $user->user_login ) );
		self::messages();

		echo '<form method="post" action="' . esc_url( self::url_mot_de_passe( $cle, $login ) ) . '" class="etr-auth__form" novalidate>';
		echo '<input type="hidden" name="etr_action" value="nouveau_mot_de_passe">';
		echo '<input type="hidden" name="login" value="' . esc_attr( $login ) . '">';
		echo '<input type="hidden" name="key" value="' . esc_attr( $cle ) . '">';
		// Aide les gestionnaires de mots de passe à associer le mot de passe au bon identifiant.
		echo '<input type="text" name="etr_identifiant" value="' . esc_attr( $user->user_login ) . '" autocomplete="username" class="etr-auth__cache" tabindex="-1" aria-hidden="true" readonly>';
		self::champ(
			array(
				'id'           => 'etr-pass1',
				'name'         => 'pass1',
				'label'        => __( 'Nouveau mot de passe', 'espace-traiteur' ),
				'type'         => 'password',
				'autocomplete' => 'new-password',
				'extra'        => ' minlength="8" autofocus',
			)
		);
		self::champ(
			array(
				'id'           => 'etr-pass2',
				'name'         => 'pass2',
				'label'        => __( 'Confirmer le mot de passe', 'espace-traiteur' ),
				'type'         => 'password',
				'autocomplete' => 'new-password',
				'extra'        => ' minlength="8"',
			)
		);
		self::bouton( __( 'Enregistrer mon mot de passe', 'espace-traiteur' ) );
		echo '</form>';
	}
}
