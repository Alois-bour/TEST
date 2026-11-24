<?php
/**
 * Activation, rôles et mise à jour.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installation du plugin.
 */
class ETR_Installer {

	/**
	 * Activation : rôles, page, catégories par défaut.
	 */
	public static function activate() {
		self::roles();

		ETR_Post_Types::register();

		$page_id = (int) get_option( 'etr_page_id' );
		if ( ! $page_id || ! get_post( $page_id ) ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Espace traiteur', 'espace-traiteur' ),
					'post_name'    => 'espace-traiteur',
					'post_content' => '<!-- wp:shortcode -->[espace_traiteur]<!-- /wp:shortcode -->',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( 'etr_page_id', $page_id );
			}
		}

		$categories = etr_source_woocommerce() ? array() : array(
			__( 'Mobilier', 'espace-traiteur' ),
			__( 'Vaisselle', 'espace-traiteur' ),
			__( 'Verrerie', 'espace-traiteur' ),
			__( 'Couverts', 'espace-traiteur' ),
			__( 'Linge de table', 'espace-traiteur' ),
			__( 'Matériel de cuisine', 'espace-traiteur' ),
		);
		foreach ( $categories as $categorie ) {
			if ( ! term_exists( $categorie, 'etr_categorie' ) ) {
				wp_insert_term( $categorie, 'etr_categorie' );
			}
		}

		if ( false === get_option( 'etr_version' ) ) {
			// Première installation : tout vient d'être créé, aucune migration à rejouer.
			update_option( 'etr_version', ETR_VERSION );
		} else {
			// Réactivation après une mise à jour des fichiers : on rattrape les migrations.
			ETR_Migrations::executer( 'activation' );
		}
		ETR_Gestion::regle_reecriture();
		flush_rewrite_rules();
	}

	/**
	 * Désactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'etr_rappels_horaires' );
		flush_rewrite_rules();
	}

	/**
	 * Rôle « Traiteur » et capacités des administrateurs.
	 */
	public static function roles() {
		// Le rôle n'a que l'accès à l'espace traiteur : pas même la lecture de l'administration.
		$role = get_role( 'traiteur' );
		if ( ! $role ) {
			$role = add_role( 'traiteur', __( 'Traiteur', 'espace-traiteur' ), array( 'acceder_espace_traiteur' => true ) );
		}
		if ( $role ) {
			$role->add_cap( 'acceder_espace_traiteur' );
			$role->remove_cap( 'read' );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'acceder_espace_traiteur' );
			$admin->add_cap( 'gerer_espace_traiteur' );
		}
	}

}
