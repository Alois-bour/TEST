<?php
/**
 * Équipe interne : rôles Bureau, Préparateur et Livreur, et ce que chacun peut faire
 * dans l'espace de gestion (l'application de l'équipe).
 *
 * - Bureau : tout l'espace de gestion (demandes, décisions, prix, traiteurs, équipe…).
 * - Préparateur : planning, demandes validées (sans prix), catalogue, bons.
 * - Livreur : planning, demandes validées et livrées (sans prix), bons.
 *
 * Préparateurs et livreurs n'ont pas accès à l'administration WordPress.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rôles et droits de l'équipe.
 */
class ETR_Equipe {

	/**
	 * Droit d'entrer dans l'espace de gestion.
	 */
	const CAP_ACCES = 'acceder_gestion';

	/**
	 * Droit de préparer le matériel.
	 */
	const CAP_PREPARER = 'preparer_commandes';

	/**
	 * Droit de livrer et reprendre le matériel.
	 */
	const CAP_LIVRER = 'livrer_commandes';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'auth_redirect', array( __CLASS__, 'bloquer_admin' ) );
		add_action( 'admin_init', array( __CLASS__, 'bloquer_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'barre_admin' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'redirection_connexion' ), 20, 3 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'filtrer_api' ), 10, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'mots_de_passe_application' ), 10, 2 );
	}

	/**
	 * Rôles de l'équipe : slug => { libellé, droits }.
	 *
	 * @return array
	 */
	public static function roles() {
		return array(
			'etr_bureau'      => array(
				__( 'Équipe – Bureau', 'espace-traiteur' ),
				array(
					'read'                  => true,
					self::CAP_ACCES         => true,
					self::CAP_PREPARER      => true,
					self::CAP_LIVRER        => true,
					'gerer_espace_traiteur' => true,
				),
			),
			'etr_preparateur' => array(
				__( 'Équipe – Préparateur', 'espace-traiteur' ),
				array(
					self::CAP_ACCES    => true,
					self::CAP_PREPARER => true,
				),
			),
			'etr_livreur'     => array(
				__( 'Équipe – Livreur', 'espace-traiteur' ),
				array(
					self::CAP_ACCES  => true,
					self::CAP_LIVRER => true,
				),
			),
		);
	}

	/**
	 * Crée ou met à jour les rôles ; les administrateurs reçoivent tous les droits.
	 */
	public static function installer_roles() {
		foreach ( self::roles() as $slug => $role ) {
			$objet = get_role( $slug );
			if ( ! $objet ) {
				$objet = add_role( $slug, $role[0], array() );
			}
			if ( $objet ) {
				foreach ( $role[1] as $cap => $accorde ) {
					$objet->add_cap( $cap, $accorde );
				}
			}
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( self::CAP_ACCES, self::CAP_PREPARER, self::CAP_LIVRER ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Supprime les rôles (désinstallation).
	 */
	public static function supprimer_roles() {
		foreach ( array_keys( self::roles() ) as $slug ) {
			remove_role( $slug );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Droits                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Peut entrer dans l'espace de gestion.
	 *
	 * @param int $user_id Utilisateur (courant par défaut).
	 * @return bool
	 */
	public static function acces( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return $user_id && ( user_can( $user_id, self::CAP_ACCES ) || etr_est_gestionnaire( $user_id ) );
	}

	/**
	 * Peut tout gérer (bureau, administrateur).
	 *
	 * @param int $user_id Utilisateur (courant par défaut).
	 * @return bool
	 */
	public static function bureau( $user_id = 0 ) {
		return etr_est_gestionnaire( $user_id );
	}

	/**
	 * Peut préparer le matériel.
	 *
	 * @param int $user_id Utilisateur (courant par défaut).
	 * @return bool
	 */
	public static function preparateur( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return $user_id && ( user_can( $user_id, self::CAP_PREPARER ) || etr_est_gestionnaire( $user_id ) );
	}

	/**
	 * Peut livrer et reprendre le matériel.
	 *
	 * @param int $user_id Utilisateur (courant par défaut).
	 * @return bool
	 */
	public static function livreur( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return $user_id && ( user_can( $user_id, self::CAP_LIVRER ) || etr_est_gestionnaire( $user_id ) );
	}

	/**
	 * Membre de l'équipe « terrain » (préparateur ou livreur, sans droits de bureau ni d'édition).
	 *
	 * @param int $user_id Utilisateur.
	 * @return bool
	 */
	public static function terrain( $user_id ) {
		return $user_id
			&& user_can( $user_id, self::CAP_ACCES )
			&& ! etr_est_gestionnaire( $user_id )
			&& ! user_can( $user_id, 'edit_posts' );
	}

	/**
	 * Libellé du rôle d'équipe d'un utilisateur.
	 *
	 * @param WP_User $user Utilisateur.
	 * @return string
	 */
	public static function libelle_role( $user ) {
		$roles = self::roles();
		foreach ( (array) $user->roles as $slug ) {
			if ( isset( $roles[ $slug ] ) ) {
				return $roles[ $slug ][0];
			}
		}
		return in_array( 'administrator', (array) $user->roles, true ) ? __( 'Administrateur', 'espace-traiteur' ) : '';
	}

	/**
	 * Libellé court d'un rôle d'équipe.
	 *
	 * @param string $slug Rôle.
	 * @return string
	 */
	public static function libelle_role_slug( $slug ) {
		$courts = array(
			'etr_bureau'      => __( 'Bureau', 'espace-traiteur' ),
			'etr_preparateur' => __( 'Préparateur', 'espace-traiteur' ),
			'etr_livreur'     => __( 'Livreur', 'espace-traiteur' ),
		);
		return isset( $courts[ $slug ] ) ? $courts[ $slug ] : '';
	}

	/**
	 * Ce que fait chaque rôle (aide du formulaire).
	 *
	 * @return array<string,string>
	 */
	public static function descriptions() {
		return array(
			'etr_bureau'      => __( 'Tout l’espace de gestion : demandes, décisions, prix, traiteurs, équipe.', 'espace-traiteur' ),
			'etr_preparateur' => __( 'Planning, demandes validées, catalogue et bons de préparation.', 'espace-traiteur' ),
			'etr_livreur'     => __( 'Planning, livraisons et reprises, bons.', 'espace-traiteur' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Membres                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Rôle d'équipe actuel d'un utilisateur (slug), ou chaîne vide.
	 *
	 * @param WP_User $user Utilisateur.
	 * @return string
	 */
	public static function role_equipe( $user ) {
		foreach ( (array) $user->roles as $slug ) {
			if ( isset( self::roles()[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Accès retiré ? (le rôle est mis de côté pour pouvoir le rendre).
	 *
	 * @param int $user_id Utilisateur.
	 * @return bool
	 */
	public static function acces_retire( $user_id ) {
		return '' !== self::role_retire( $user_id );
	}

	/**
	 * Rôle mis de côté lors du retrait de l'accès.
	 *
	 * @param int $user_id Utilisateur.
	 * @return string
	 */
	public static function role_retire( $user_id ) {
		$role = (string) get_user_meta( $user_id, 'etr_role_retire', true );
		return isset( self::roles()[ $role ] ) ? $role : '';
	}

	/**
	 * Membres de l'équipe : administrateurs, rôles d'équipe, et personnes dont l'accès est retiré.
	 *
	 * @return WP_User[]
	 */
	public static function membres() {
		$actifs  = get_users(
			array(
				'role__in' => array_merge( array( 'administrator' ), array_keys( self::roles() ) ),
				'orderby'  => 'display_name',
			)
		);
		$retires = get_users(
			array(
				'meta_key' => 'etr_role_retire', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'  => 'display_name',
			)
		);
		$membres = array();
		foreach ( array_merge( $actifs, $retires ) as $user ) {
			$membres[ $user->ID ] = $user;
		}
		uasort(
			$membres,
			function ( $a, $b ) {
				return strcasecmp( remove_accents( etr_nom( $a ) ), remove_accents( etr_nom( $b ) ) );
			}
		);
		return array_values( $membres );
	}

	/**
	 * Le bureau peut-il modifier ce compte ? Pas les administrateurs, pas soi-même.
	 *
	 * @param int $user_id Utilisateur.
	 * @return bool
	 */
	public static function modifiable( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || (int) $user_id === get_current_user_id() || in_array( 'administrator', (array) $user->roles, true ) ) {
			return false;
		}
		return '' !== self::role_equipe( $user ) || self::acces_retire( $user_id );
	}

	/**
	 * Crée un membre de l'équipe (aucun droit d'administration WordPress).
	 *
	 * Codes d'erreur : nom, email, email_existe, role, creation.
	 *
	 * @param array $donnees nom, email, telephone, role (bruts).
	 * @param bool  $inviter Envoyer l'invitation tout de suite.
	 * @return array|WP_Error { user_id, invitation : true envoyée, false échec, null non demandée }
	 */
	public static function creer_membre( $donnees, $inviter = false ) {
		$nom       = sanitize_text_field( isset( $donnees['nom'] ) ? $donnees['nom'] : '' );
		$email     = sanitize_email( isset( $donnees['email'] ) ? $donnees['email'] : '' );
		$telephone = sanitize_text_field( isset( $donnees['telephone'] ) ? $donnees['telephone'] : '' );
		$role      = sanitize_key( isset( $donnees['role'] ) ? $donnees['role'] : '' );

		if ( '' === $nom ) {
			return new WP_Error( 'nom' );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email' );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'email_existe' );
		}
		if ( ! isset( self::roles()[ $role ] ) ) {
			return new WP_Error( 'role' );
		}

		$base  = sanitize_user( strtolower( remove_accents( strstr( $email, '@', true ) ) ), true );
		$base  = $base ? $base : 'equipe';
		$login = $base;
		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = $base . $i;
		}
		$noms    = explode( ' ', $nom, 2 );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $nom,
				'nickname'     => $nom,
				'first_name'   => $noms[0],
				'last_name'    => isset( $noms[1] ) ? $noms[1] : '',
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'creation' );
		}
		update_user_meta( $user_id, 'etr_telephone', $telephone );

		return array(
			'user_id'    => $user_id,
			'invitation' => $inviter ? self::envoyer_invitation( get_userdata( $user_id ) ) : null,
		);
	}

	/**
	 * Invitation d'un membre : lien pour choisir son mot de passe, puis adresse de l'application.
	 *
	 * @param WP_User $user Membre.
	 * @return bool
	 */
	public static function envoyer_invitation( $user ) {
		$cle = get_password_reset_key( $user );
		if ( is_wp_error( $cle ) ) {
			return false;
		}
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$lien = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $cle ) . '&login=' . rawurlencode( $user->user_login ), 'login' );

		/* translators: %s : nom du site. */
		$objet = sprintf( __( '[%s] Votre accès à l’application de l’équipe', 'espace-traiteur' ), $site );
		/* translators: %s : prénom et nom. */
		$corps  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n";
		/* translators: 1 : nom du site, 2 : rôle. */
		$corps .= sprintf( __( 'Un accès à l’application de gestion %1$s vous a été ouvert (rôle : %2$s).', 'espace-traiteur' ), $site, self::libelle_role_slug( self::role_equipe( $user ) ) ) . "\n\n";
		/* translators: %s : identifiant. */
		$corps .= sprintf( __( 'Votre identifiant : %s', 'espace-traiteur' ), $user->user_login ) . "\n\n";
		$corps .= __( 'Choisissez votre mot de passe (lien valable 24 heures) :', 'espace-traiteur' ) . "\n" . $lien . "\n\n";
		$corps .= __( 'Ensuite, ouvrez l’application sur votre iPhone avec Safari, puis touchez « Partager » et « Sur l’écran d’accueil » pour l’installer :', 'espace-traiteur' ) . "\n" . ETR_Gestion::url() . "\n";

		$envoye = wp_mail( $user->user_email, $objet, $corps );
		if ( $envoye ) {
			update_user_meta( $user->ID, 'etr_invitation_envoyee', time() );
		}
		return $envoye;
	}

	/**
	 * Modifie un membre : rôle, invitation, retrait ou retour de l'accès.
	 *
	 * @param int    $user_id Membre.
	 * @param string $op      role|inviter|retirer|rendre.
	 * @param string $role    Nouveau rôle (op « role »).
	 * @return string|WP_Error Code du message à afficher.
	 */
	public static function modifier_membre( $user_id, $op, $role = '' ) {
		if ( ! self::modifiable( $user_id ) ) {
			return new WP_Error( 'interdit', __( 'Ce compte ne peut pas être modifié ici.', 'espace-traiteur' ) );
		}
		$user = get_userdata( $user_id );

		switch ( $op ) {
			case 'role':
				if ( ! isset( self::roles()[ $role ] ) || self::acces_retire( $user_id ) ) {
					return new WP_Error( 'role', __( 'Rôle inconnu.', 'espace-traiteur' ) );
				}
				$user->set_role( $role );
				return 'role';

			case 'inviter':
				return self::envoyer_invitation( $user ) ? 'invite' : 'mail_echec';

			case 'retirer':
				$actuel = self::role_equipe( $user );
				if ( '' === $actuel ) {
					return 'retire';
				}
				update_user_meta( $user_id, 'etr_role_retire', $actuel );
				$user->set_role( '' );
				WP_Session_Tokens::get_instance( $user_id )->destroy_all(); // Déconnexion immédiate.
				return 'retire';

			case 'rendre':
				$ancien = self::role_retire( $user_id );
				if ( '' !== $ancien ) {
					$user->set_role( $ancien );
					delete_user_meta( $user_id, 'etr_role_retire' );
				}
				return 'rendu';
		}
		return new WP_Error( 'op', __( 'Action inconnue.', 'espace-traiteur' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Préparateurs et livreurs : hors de l'administration WordPress      */
	/* ------------------------------------------------------------------ */

	/**
	 * Renvoie vers l'espace de gestion un préparateur ou un livreur qui ouvre wp-admin.
	 *
	 * @param int $user_id Utilisateur authentifié (hook auth_redirect), sinon utilisateur courant.
	 */
	public static function bloquer_admin( $user_id = 0 ) {
		$user_id = is_numeric( $user_id ) && $user_id ? (int) $user_id : get_current_user_id();
		if ( wp_doing_ajax() || ! self::terrain( $user_id ) ) {
			return;
		}
		wp_safe_redirect( ETR_Gestion::url() );
		exit;
	}

	/**
	 * Pas de barre d'administration pour le terrain.
	 *
	 * @param bool $afficher Valeur par défaut.
	 * @return bool
	 */
	public static function barre_admin( $afficher ) {
		return ( is_user_logged_in() && self::terrain( get_current_user_id() ) ) ? false : $afficher;
	}

	/**
	 * API REST : un préparateur ou un livreur connecté n'a accès qu'aux routes publiques
	 * utiles à l'affichage du site (Store API WooCommerce, oEmbed), comme les traiteurs.
	 *
	 * @param mixed           $resultat Résultat (null pour continuer).
	 * @param WP_REST_Server  $serveur  Serveur.
	 * @param WP_REST_Request $requete  Requête.
	 * @return mixed
	 */
	public static function filtrer_api( $resultat, $serveur, $requete ) {
		if ( null !== $resultat || ! is_user_logged_in() || ! self::terrain( get_current_user_id() ) ) {
			return $resultat;
		}
		$route = $requete->get_route();
		foreach ( (array) apply_filters( 'etr_routes_api_equipe', array( '/wc/store/', '/oembed/' ) ) as $prefixe ) {
			if ( 0 === strpos( $route, $prefixe ) ) {
				return $resultat;
			}
		}
		return new WP_Error( 'etr_api_interdite', __( 'Accès réservé.', 'espace-traiteur' ), array( 'status' => 403 ) );
	}

	/**
	 * Pas de mots de passe d'application pour le terrain.
	 *
	 * @param bool    $disponible Valeur par défaut.
	 * @param WP_User $user       Utilisateur.
	 * @return bool
	 */
	public static function mots_de_passe_application( $disponible, $user ) {
		return ( $user instanceof WP_User && self::terrain( $user->ID ) ) ? false : $disponible;
	}

	/**
	 * Après connexion par wp-login.php, le terrain arrive dans l'application.
	 *
	 * @param string           $redirect_to URL prévue.
	 * @param string           $requested   URL demandée.
	 * @param WP_User|WP_Error $user        Utilisateur.
	 * @return string
	 */
	public static function redirection_connexion( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && self::terrain( $user->ID ) ) {
			return ETR_Gestion::url();
		}
		return $redirect_to;
	}
}
