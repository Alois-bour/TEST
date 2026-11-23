<?php
/**
 * Accès : les traiteurs restent sur leur espace, hors de l'administration.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contrôle d'accès.
 */
class ETR_Access {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'bloquer_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'barre_admin' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'redirection_connexion' ), 10, 3 );
	}

	/**
	 * Utilisateur « traiteur » uniquement (sans droits d'édition du site).
	 *
	 * @param int $user_id ID utilisateur.
	 * @return bool
	 */
	public static function traiteur_seulement( $user_id ) {
		return etr_est_traiteur( $user_id )
			&& ! etr_est_gestionnaire( $user_id )
			&& ! user_can( $user_id, 'edit_posts' );
	}

	/**
	 * Redirige les traiteurs vers leur espace s'ils ouvrent wp-admin.
	 */
	public static function bloquer_admin() {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		if ( self::traiteur_seulement( get_current_user_id() ) ) {
			wp_safe_redirect( etr_url() );
			exit;
		}
	}

	/**
	 * Masque la barre d'admin pour les traiteurs.
	 *
	 * @param bool $afficher Valeur par défaut.
	 * @return bool
	 */
	public static function barre_admin( $afficher ) {
		if ( is_user_logged_in() && self::traiteur_seulement( get_current_user_id() ) ) {
			return false;
		}
		return $afficher;
	}

	/**
	 * Après connexion, envoie les traiteurs sur leur espace.
	 *
	 * @param string           $redirect_to URL prévue.
	 * @param string           $requested   URL demandée.
	 * @param WP_User|WP_Error $user        Utilisateur.
	 * @return string
	 */
	public static function redirection_connexion( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && self::traiteur_seulement( $user->ID ) ) {
			return etr_url();
		}
		return $redirect_to;
	}
}
