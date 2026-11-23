<?php
/**
 * Types de contenus : demandes, articles du catalogue, catégories.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enregistrement des types de contenus.
 */
class ETR_Post_Types {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Capacités d'un type de contenu, toutes réservées aux gestionnaires.
	 *
	 * @param bool $creation_autorisee Création possible depuis l'admin.
	 * @return array
	 */
	private static function caps( $creation_autorisee = true ) {
		$cap  = 'gerer_espace_traiteur';
		$caps = array(
			'edit_post'              => $cap,
			'read_post'              => $cap,
			'delete_post'            => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'delete_posts'           => $cap,
			'delete_others_posts'    => $cap,
			'delete_published_posts' => $cap,
			'delete_private_posts'   => $cap,
			'edit_published_posts'   => $cap,
			'edit_private_posts'     => $cap,
			'create_posts'           => $creation_autorisee ? $cap : 'do_not_allow',
		);
		return $caps;
	}

	/**
	 * Enregistre les CPT et la taxonomie.
	 */
	public static function register() {
		register_post_type(
			'etr_demande',
			array(
				'labels'        => array(
					'name'               => __( 'Demandes traiteurs', 'espace-traiteur' ),
					'singular_name'      => __( 'Demande', 'espace-traiteur' ),
					'menu_name'          => __( 'Espace traiteur', 'espace-traiteur' ),
					'all_items'          => __( 'Demandes', 'espace-traiteur' ),
					'edit_item'          => __( 'Traiter la demande', 'espace-traiteur' ),
					'search_items'       => __( 'Rechercher une demande', 'espace-traiteur' ),
					'not_found'          => __( 'Aucune demande.', 'espace-traiteur' ),
					'not_found_in_trash' => __( 'Aucune demande dans la corbeille.', 'espace-traiteur' ),
				),
				'public'        => false,
				'show_ui'       => true,
				'show_in_menu'  => true,
				'menu_position' => 26,
				'menu_icon'     => 'dashicons-food',
				'supports'      => array( 'title' ),
				'capabilities'  => self::caps( false ),
				'map_meta_cap'  => false,
				'rewrite'       => false,
			)
		);

		register_post_type(
			'etr_article',
			array(
				'labels'       => array(
					'name'          => __( 'Catalogue', 'espace-traiteur' ),
					'singular_name' => __( 'Article', 'espace-traiteur' ),
					'all_items'     => __( 'Catalogue', 'espace-traiteur' ),
					'add_new'       => __( 'Ajouter un article', 'espace-traiteur' ),
					'add_new_item'  => __( 'Ajouter un article', 'espace-traiteur' ),
					'edit_item'     => __( 'Modifier l’article', 'espace-traiteur' ),
					'search_items'  => __( 'Rechercher un article', 'espace-traiteur' ),
					'not_found'     => __( 'Aucun article.', 'espace-traiteur' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=etr_demande',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'capabilities' => self::caps(),
				'map_meta_cap' => false,
				'rewrite'      => false,
			)
		);

		register_taxonomy(
			'etr_categorie',
			'etr_article',
			array(
				'labels'            => array(
					'name'          => __( 'Catégories', 'espace-traiteur' ),
					'singular_name' => __( 'Catégorie', 'espace-traiteur' ),
					'add_new_item'  => __( 'Ajouter une catégorie', 'espace-traiteur' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'rewrite'           => false,
				'capabilities'      => array(
					'manage_terms' => 'gerer_espace_traiteur',
					'edit_terms'   => 'gerer_espace_traiteur',
					'delete_terms' => 'gerer_espace_traiteur',
					'assign_terms' => 'gerer_espace_traiteur',
				),
			)
		);
	}
}
