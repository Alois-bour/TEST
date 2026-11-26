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
		add_action( 'auth_redirect', array( __CLASS__, 'bloquer_admin_tot' ) );
		add_action( 'admin_init', array( __CLASS__, 'bloquer_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'barre_admin' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'redirection_connexion' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'bloquer_pages_woocommerce' ) );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'achat_impossible' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'filtrer_api' ), 10, 3 );
	}

	/**
	 * Le visiteur courant est-il un traiteur connecté ?
	 *
	 * @return bool
	 */
	private static function traiteur_connecte() {
		return is_user_logged_in() && self::traiteur_seulement( get_current_user_id() );
	}

	/**
	 * Pas de « Mon compte », panier ni paiement WooCommerce pour les traiteurs.
	 */
	public static function bloquer_pages_woocommerce() {
		if ( ! self::traiteur_connecte() || ! function_exists( 'is_account_page' ) ) {
			return;
		}
		if ( is_account_page() || is_cart() || is_checkout() ) {
			wp_safe_redirect( etr_url() );
			exit;
		}
	}

	/**
	 * Les traiteurs ne passent pas commande sur la boutique.
	 *
	 * @param bool $achetable Valeur par défaut.
	 * @return bool
	 */
	public static function achat_impossible( $achetable ) {
		return self::traiteur_connecte() ? false : $achetable;
	}

	/**
	 * API REST : un traiteur connecté n'a accès qu'aux routes publiques utiles
	 * à l'affichage du site (Store API WooCommerce, oEmbed).
	 *
	 * @param mixed           $resultat Résultat (null pour continuer).
	 * @param WP_REST_Server  $serveur  Serveur.
	 * @param WP_REST_Request $requete  Requête.
	 * @return mixed
	 */
	public static function filtrer_api( $resultat, $serveur, $requete ) {
		if ( null !== $resultat || ! self::traiteur_connecte() ) {
			return $resultat;
		}
		$route     = $requete->get_route();
		$autorisee = (array) apply_filters( 'etr_routes_api_traiteurs', array( '/wc/store/', '/oembed/' ) );
		foreach ( $autorisee as $prefixe ) {
			if ( 0 === strpos( $route, $prefixe ) ) {
				return $resultat;
			}
		}
		return new WP_Error( 'etr_api_interdite', __( 'Accès réservé.', 'espace-traiteur' ), array( 'status' => 403 ) );
	}

	/**
	 * Utilisateur « traiteur » uniquement (sans droits d'édition du site).
	 *
	 * @param int $user_id ID utilisateur.
	 * @return bool
	 */
	public static function traiteur_seulement( $user_id ) {
		// Un traiteur qui fait aussi partie de l'équipe (préparateur, livreur…) garde l'accès à l'application.
		return etr_est_traiteur( $user_id )
			&& ! etr_est_gestionnaire( $user_id )
			&& ! user_can( $user_id, 'edit_posts' )
			&& ! ( class_exists( 'ETR_Equipe' ) && ETR_Equipe::acces( $user_id ) );
	}

	/**
	 * Redirige un traiteur dès la vérification de connexion de wp-admin, avant
	 * que WordPress n'affiche son message « accès refusé » (le rôle n'a pas le droit « read »).
	 *
	 * @param int $user_id Utilisateur authentifié.
	 */
	public static function bloquer_admin_tot( $user_id ) {
		if ( $user_id && ! wp_doing_ajax() && self::traiteur_seulement( $user_id ) ) {
			wp_safe_redirect( etr_url() );
			exit;
		}
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
