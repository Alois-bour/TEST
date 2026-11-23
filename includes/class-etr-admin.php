<?php
/**
 * Administration : traitement des demandes et gestion du catalogue.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écrans d'administration.
 */
class ETR_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'enregistrer_reglages' ) );

		// Champs « Espace traiteur » sur les produits WooCommerce.
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'champs_produit' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'enregistrer_produit' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_etr_demande', array( __CLASS__, 'metaboxes_demande' ) );
		add_action( 'add_meta_boxes_etr_article', array( __CLASS__, 'metaboxes_article' ) );
		add_action( 'save_post_etr_demande', array( __CLASS__, 'enregistrer_demande' ) );
		add_action( 'save_post_etr_article', array( __CLASS__, 'enregistrer_article' ) );

		add_filter( 'manage_etr_demande_posts_columns', array( __CLASS__, 'colonnes_demande' ) );
		add_action( 'manage_etr_demande_posts_custom_column', array( __CLASS__, 'colonne_demande' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filtre_statut' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'appliquer_filtre_statut' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'actions_ligne' ), 10, 2 );

		add_filter( 'manage_etr_article_posts_columns', array( __CLASS__, 'colonnes_article' ) );
		add_action( 'manage_etr_article_posts_custom_column', array( __CLASS__, 'colonne_article' ), 10, 2 );
	}

	/**
	 * Sous-menu « Catégories » (la taxonomie n'apparaît pas seule quand le CPT est en sous-menu).
	 */
	public static function menu() {
		if ( ! etr_source_woocommerce() ) {
			add_submenu_page(
				'edit.php?post_type=etr_demande',
				__( 'Catégories', 'espace-traiteur' ),
				__( 'Catégories', 'espace-traiteur' ),
				'gerer_espace_traiteur',
				'edit-tags.php?taxonomy=etr_categorie&post_type=etr_article'
			);
		}
		add_submenu_page(
			'edit.php?post_type=etr_demande',
			__( 'Réglages de l’espace traiteur', 'espace-traiteur' ),
			__( 'Réglages', 'espace-traiteur' ),
			'gerer_espace_traiteur',
			'etr-reglages',
			array( __CLASS__, 'page_reglages' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Réglages                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Déclare les options.
	 */
	public static function enregistrer_reglages() {
		register_setting(
			'etr_reglages',
			'etr_categories',
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => function ( $valeur ) {
					return is_array( $valeur ) ? array_values( array_filter( array_map( 'absint', $valeur ) ) ) : array();
				},
			)
		);
		register_setting(
			'etr_reglages',
			'etr_afficher_prix',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => function ( $valeur ) {
					return $valeur ? '1' : '';
				},
			)
		);
		register_setting(
			'etr_reglages',
			'etr_connexion_plein_ecran',
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => function ( $valeur ) {
					return $valeur ? '1' : '0';
				},
			)
		);
		foreach ( array( 'etr_logo', 'etr_image_connexion' ) as $option ) {
			register_setting(
				'etr_reglages',
				$option,
				array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				)
			);
		}
		register_setting(
			'etr_reglages',
			'etr_email_equipe',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_email',
			)
		);
	}

	/**
	 * Page « Réglages ».
	 */
	public static function page_reglages() {
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			return;
		}
		$taxonomie = etr_taxonomie_categories();
		$retenues  = etr_categories_retenues();
		$termes    = get_terms(
			array(
				'taxonomy'   => $taxonomie,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$termes    = is_wp_error( $termes ) ? array() : $termes;

		echo '<div class="wrap"><h1>' . esc_html__( 'Réglages de l’espace traiteur', 'espace-traiteur' ) . '</h1>';
		self::recapitulatif();
		echo '<form method="post" action="options.php">';
		settings_fields( 'etr_reglages' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Catalogue', 'espace-traiteur' ) . '</th><td>';
		if ( etr_source_woocommerce() ) {
			echo '<p>' . esc_html__( 'Les articles proposés aux traiteurs sont les produits WooCommerce publiés du site.', 'espace-traiteur' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Sur chaque produit (onglet « Inventaire »), vous pouvez indiquer la quantité possédée, l’unité, ou masquer le produit de l’espace traiteur.', 'espace-traiteur' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'WooCommerce n’est pas actif : le catalogue interne du plugin est utilisé (menu « Catalogue »).', 'espace-traiteur' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Catégories proposées', 'espace-traiteur' ) . '</th><td><fieldset>';
		echo '<p class="description">' . esc_html__( 'Aucune case cochée = toutes les catégories. Cocher une catégorie inclut ses sous-catégories.', 'espace-traiteur' ) . '</p>';
		self::cases_categories( $termes, 0, $retenues, 0 );
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Prix', 'espace-traiteur' ) . '</th><td><label><input type="checkbox" name="etr_afficher_prix" value="1"' . checked( get_option( 'etr_afficher_prix' ), '1', false ) . '> ' . esc_html__( 'Afficher les prix du site dans l’espace traiteur', 'espace-traiteur' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="etr_email_equipe">' . esc_html__( 'E-mail de l’équipe', 'espace-traiteur' ) . '</label></th><td>';
		echo '<input type="email" class="regular-text" id="etr_email_equipe" name="etr_email_equipe" value="' . esc_attr( get_option( 'etr_email_equipe' ) ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Reçoit les nouvelles demandes et les annulations. Vide = e-mail d’administration du site.', 'espace-traiteur' ) . '</p></td></tr>';

		echo '<tr><th scope="row" colspan="2"><h2 style="margin:16px 0 0">' . esc_html__( 'Page de connexion', 'espace-traiteur' ) . '</h2></th></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Affichage', 'espace-traiteur' ) . '</th><td><input type="hidden" name="etr_connexion_plein_ecran" value="0"><label><input type="checkbox" name="etr_connexion_plein_ecran" value="1"' . checked( get_option( 'etr_connexion_plein_ecran', '1' ), '1', false ) . '> ' . esc_html__( 'Plein écran (sans l’en-tête ni le pied de page du site)', 'espace-traiteur' ) . '</label></td></tr>';
		self::champ_media( 'etr_logo', __( 'Logo', 'espace-traiteur' ), __( 'Affiché au-dessus du formulaire. Sans choix : le logo du site est utilisé automatiquement.', 'espace-traiteur' ), ETR_Comptes::logo_id( true ) );
		self::champ_media( 'etr_image_connexion', __( 'Photo d’ambiance', 'espace-traiteur' ), __( 'Grande photo à côté du formulaire (portrait ou carré, 1200 px de large conseillé). Sans choix : une photo d’ambiance du site est utilisée automatiquement.', 'espace-traiteur' ), ETR_Comptes::photo_id( true ) );

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
		self::mises_a_jour();
		echo '</div>';
	}

	/**
	 * Section « Mises à jour » : versions et journal des migrations.
	 */
	private static function mises_a_jour() {
		// phpcs:ignore WordPress.Security.NonceVerification -- simple message après redirection.
		$etat = isset( $_GET['etr_migration'] ) ? sanitize_key( wp_unslash( $_GET['etr_migration'] ) ) : '';
		$messages = array(
			'ok'         => array( 'success', __( 'Base de données à jour.', 'espace-traiteur' ) ),
			'erreur'     => array( 'error', __( 'La migration a de nouveau échoué : voir le journal ci-dessous.', 'espace-traiteur' ) ),
			'a_suivre'   => array( 'info', __( 'Traitement par lots en cours : il se poursuit en arrière-plan.', 'espace-traiteur' ) ),
			'verrouille' => array( 'warning', __( 'Une migration est déjà en cours. Réessayez dans quelques minutes.', 'espace-traiteur' ) ),
		);

		echo '<hr><h2 id="etr-mises-a-jour">' . esc_html__( 'Mises à jour du plugin', 'espace-traiteur' ) . '</h2>';
		if ( isset( $messages[ $etat ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $etat ][0] ) . ' inline"><p>' . esc_html( $messages[ $etat ][1] ) . '</p></div>';
		}

		$attente = ETR_Migrations::etapes_en_attente();
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Version des fichiers', 'espace-traiteur' ) . '</th><td><code>' . esc_html( ETR_VERSION ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Version des données', 'espace-traiteur' ) . '</th><td><code>' . esc_html( ETR_Migrations::version_bdd() ) . '</code> ';
		echo ETR_Migrations::a_faire()
			? '<span class="etr-statut etr-statut--en_attente">' . esc_html__( 'Migration en attente', 'espace-traiteur' ) . '</span>'
			: '<span class="etr-statut etr-statut--validee">' . esc_html__( 'À jour', 'espace-traiteur' ) . '</span>';
		echo '</td></tr>';
		if ( $attente ) {
			echo '<tr><th scope="row">' . esc_html__( 'Étapes en attente', 'espace-traiteur' ) . '</th><td><ul style="margin:0">';
			foreach ( $attente as $version => $etape ) {
				echo '<li><code>' . esc_html( $version ) . '</code> ' . esc_html( $etape['description'] ) . '</li>';
			}
			echo '</ul>';
			$relancer = wp_nonce_url( admin_url( 'admin-post.php?action=etr_relancer_migration' ), 'etr_relancer_migration' );
			echo '<p><a class="button" href="' . esc_url( $relancer ) . '">' . esc_html__( 'Lancer la migration maintenant', 'espace-traiteur' ) . '</a></p></td></tr>';
		}
		echo '</tbody></table>';

		$journal = array_reverse( ETR_Migrations::journal() );
		if ( $journal ) {
			$statuts = array(
				'ok'       => array( 'validee', __( 'Réussie', 'espace-traiteur' ) ),
				'erreur'   => array( 'refusee', __( 'Échec', 'espace-traiteur' ) ),
				'en_cours' => array( 'en_attente', __( 'En cours', 'espace-traiteur' ) ),
			);
			echo '<h3>' . esc_html__( 'Journal des migrations', 'espace-traiteur' ) . '</h3>';
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Date', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Étape', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Résultat', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Lancée par', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Détail', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $journal, 0, 10 ) as $ligne ) {
				$statut = isset( $statuts[ $ligne['statut'] ] ) ? $statuts[ $ligne['statut'] ] : array( 'cloturee', $ligne['statut'] );
				echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $ligne['date'] ) ) . '</td><td><code>' . esc_html( $ligne['version'] ) . '</code></td><td><span class="etr-statut etr-statut--' . esc_attr( $statut[0] ) . '">' . esc_html( $statut[1] ) . '</span></td><td>' . esc_html( $ligne['source'] ) . '</td><td>' . esc_html( $ligne['message'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Champ « image de la médiathèque ».
	 *
	 * @param string $option Nom de l'option (ID de pièce jointe).
	 * @param string $label  Libellé.
	 * @param string $aide   Aide.
	 * @param int    $defaut Image utilisée si rien n'est choisi.
	 */
	private static function champ_media( $option, $label, $aide, $defaut = 0 ) {
		$id      = (int) get_option( $option );
		$style   = array( 'style' => 'max-width:240px;max-height:160px;width:auto;height:auto;display:block' );
		$apercu  = $id ? wp_get_attachment_image( $id, 'medium', false, $style ) : '';
		if ( ! $id ) {
			$apercu = $defaut
				? wp_get_attachment_image( $defaut, 'medium', false, $style ) . '<p class="description">' . esc_html__( 'Image utilisée par défaut (trouvée sur le site).', 'espace-traiteur' ) . '</p>'
				: '<p class="description">' . esc_html__( 'Aucune image trouvée sur le site.', 'espace-traiteur' ) . '</p>';
		}
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><div class="etr-media" data-titre="' . esc_attr( $label ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $option ) . '" value="' . (int) $id . '" class="etr-media__id">';
		echo '<div class="etr-media__apercu" style="margin-bottom:8px">' . $apercu . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML de wp_get_attachment_image().
		echo '<button type="button" class="button etr-media__choisir">' . esc_html__( 'Choisir une image', 'espace-traiteur' ) . '</button> ';
		echo '<button type="button" class="button-link etr-media__retirer"' . ( $id ? '' : ' hidden' ) . '>' . esc_html__( 'Retirer', 'espace-traiteur' ) . '</button>';
		echo '<p class="description">' . esc_html( $aide ) . '</p>';
		echo '</div></td></tr>';
	}

	/**
	 * Récapitulatif du catalogue proposé aux traiteurs.
	 */
	private static function recapitulatif() {
		$recap = etr_recapitulatif_catalogue();

		echo '<div class="etr-recap">';
		echo '<h2>' . esc_html__( 'Récapitulatif du catalogue', 'espace-traiteur' ) . '</h2>';
		echo '<p class="etr-recap__chiffres">';
		printf(
			/* translators: 1 : produits publiés, 2 : produits proposés, 3 : produits écartés. */
			esc_html__( '%1$s publiés · %2$s proposés aux traiteurs · %3$s écartés', 'espace-traiteur' ),
			'<strong>' . esc_html( number_format_i18n( $recap['publies'] ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( $recap['proposes'] ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( count( $recap['ecartes'] ) ) ) . '</strong>'
		);
		echo '</p>';

		if ( $recap['ecartes'] ) {
			echo '<h3>' . esc_html__( 'Produits écartés', 'espace-traiteur' ) . '</h3>';
			echo '<table class="widefat striped etr-recap__table"><thead><tr><th>' . esc_html__( 'Produit', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Raison', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
			foreach ( $recap['ecartes'] as $ligne ) {
				echo '<tr><td>' . self::lien_produit( $ligne ) . '</td><td>' . esc_html( $ligne['raison'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans lien_produit().
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Aucun produit écarté : tous les produits publiés sont proposés.', 'espace-traiteur' ) . '</p>';
		}

		if ( $recap['illimites'] ) {
			echo '<details class="etr-recap__illimites"><summary>';
			$nombre = count( $recap['illimites'] );
			printf(
				/* translators: %s : nombre de produits. */
				esc_html( _n( '%s produit proposé sans quantité possédée (réservable sans limite)', '%s produits proposés sans quantité possédée (réservables sans limite)', $nombre, 'espace-traiteur' ) ),
				esc_html( number_format_i18n( $nombre ) )
			);
			echo '</summary><p class="description">' . esc_html__( 'Renseignez la « Quantité possédée » (onglet Inventaire du produit) pour que les réservations soient limitées.', 'espace-traiteur' ) . '</p><ul>';
			foreach ( $recap['illimites'] as $ligne ) {
				echo '<li>' . self::lien_produit( $ligne ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans lien_produit().
			}
			echo '</ul></details>';
		}
		echo '</div><hr>';
	}

	/**
	 * Lien vers la fiche d'édition d'un produit.
	 *
	 * @param array $ligne Ligne du récapitulatif.
	 * @return string
	 */
	private static function lien_produit( $ligne ) {
		return $ligne['lien']
			? '<a href="' . esc_url( $ligne['lien'] ) . '">' . esc_html( $ligne['titre'] ) . '</a>'
			: esc_html( $ligne['titre'] );
	}

	/**
	 * Cases à cocher des catégories, en arbre.
	 *
	 * @param WP_Term[] $termes   Tous les termes.
	 * @param int       $parent   Parent courant.
	 * @param int[]     $retenues Termes cochés.
	 * @param int       $niveau   Profondeur.
	 */
	private static function cases_categories( $termes, $parent, $retenues, $niveau ) {
		foreach ( $termes as $terme ) {
			if ( (int) $terme->parent !== (int) $parent ) {
				continue;
			}
			printf(
				'<label style="display:block;padding-left:%1$dpx"><input type="checkbox" name="etr_categories[]" value="%2$d"%3$s> %4$s <span class="description">(%5$d)</span></label>',
				(int) $niveau * 20,
				(int) $terme->term_id,
				checked( in_array( (int) $terme->term_id, $retenues, true ), true, false ),
				esc_html( $terme->name ),
				(int) $terme->count
			);
			self::cases_categories( $termes, $terme->term_id, $retenues, $niveau + 1 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Produits WooCommerce                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Champs de l'onglet « Inventaire » d'un produit.
	 */
	public static function champs_produit() {
		if ( ! current_user_can( 'gerer_espace_traiteur' ) || ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		echo '<div class="options_group etr-produit">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Espace traiteur', 'espace-traiteur' ) . '</strong></p>';
		wp_nonce_field( 'etr_produit', 'etr_produit_nonce' );
		woocommerce_wp_text_input(
			array(
				'id'                => '_etr_stock',
				'label'             => __( 'Quantité possédée', 'espace-traiteur' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => __( 'Nombre total de pièces que les traiteurs peuvent réserver (les réservations sur les mêmes dates sont déduites). Vide : stock WooCommerce s’il est géré, sinon illimité.', 'espace-traiteur' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_etr_unite',
				'label'       => __( 'Unité', 'espace-traiteur' ),
				'placeholder' => __( 'pièce(s), lot(s) de 10…', 'espace-traiteur' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_etr_masquer',
				'label'       => __( 'Masquer', 'espace-traiteur' ),
				'description' => __( 'Ne pas proposer ce produit dans l’espace traiteur', 'espace-traiteur' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Enregistre les champs « Espace traiteur » d'un produit.
	 *
	 * @param WC_Product $produit Produit.
	 */
	public static function enregistrer_produit( $produit ) {
		if ( ! isset( $_POST['etr_produit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_produit_nonce'] ) ), 'etr_produit' ) ) {
			return;
		}
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			return;
		}
		$stock = isset( $_POST['_etr_stock'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['_etr_stock'] ) ) ) : '';
		$produit->update_meta_data( '_etr_stock', '' === $stock ? '' : (string) absint( $stock ) );
		$produit->update_meta_data( '_etr_unite', isset( $_POST['_etr_unite'] ) ? sanitize_text_field( wp_unslash( $_POST['_etr_unite'] ) ) : '' );
		$produit->update_meta_data( '_etr_masquer', isset( $_POST['_etr_masquer'] ) ? 'yes' : 'no' );
	}

	/**
	 * Feuille de style sur les écrans du plugin.
	 */
	public static function assets() {
		$ecran = get_current_screen();
		if ( $ecran && 'etr_demande_page_etr-reglages' === $ecran->id ) {
			wp_enqueue_media();
			wp_enqueue_script( 'espace-traiteur-reglages', ETR_URL . 'assets/js/admin-reglages.js', array( 'jquery' ), ETR_VERSION, true );
		}
		if ( $ecran && in_array( $ecran->post_type, array( 'etr_demande', 'etr_article' ), true ) ) {
			wp_enqueue_style( 'espace-traiteur', ETR_URL . 'assets/css/espace-traiteur.css', array(), ETR_VERSION );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Demandes                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Metaboxes de la demande.
	 */
	public static function metaboxes_demande() {
		add_meta_box( 'etr_mb_details', __( 'Détails de la demande', 'espace-traiteur' ), array( __CLASS__, 'mb_details' ), 'etr_demande', 'normal', 'high' );
		add_meta_box( 'etr_mb_lignes', __( 'Articles demandés', 'espace-traiteur' ), array( __CLASS__, 'mb_lignes' ), 'etr_demande', 'normal', 'default' );
		add_meta_box( 'etr_mb_statut', __( 'Statut et réponse', 'espace-traiteur' ), array( __CLASS__, 'mb_statut' ), 'etr_demande', 'side', 'high' );
	}

	/**
	 * Metabox : détails.
	 *
	 * @param WP_Post $post Demande.
	 */
	public static function mb_details( $post ) {
		$user = get_userdata( $post->post_author );
		echo '<table class="form-table etr-details"><tbody>';
		echo '<tr><th>' . esc_html__( 'Référence', 'espace-traiteur' ) . '</th><td>#' . (int) $post->ID . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Traiteur', 'espace-traiteur' ) . '</th><td>';
		if ( $user ) {
			printf(
				'<a href="%1$s">%2$s</a> — <a href="mailto:%3$s">%3$s</a>',
				esc_url( get_edit_user_link( $user->ID ) ),
				esc_html( etr_nom( $user ) ),
				esc_html( $user->user_email )
			);
		} else {
			echo '—';
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Envoyée le', 'espace-traiteur' ) . '</th><td>' . esc_html( get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) ) . '</td></tr>';
		foreach ( etr_champs_demande() as $cle => $champ ) {
			echo '<tr><th>' . esc_html( $champ['label'] ) . '</th><td>' . etr_valeur_affichee( $post->ID, $cle, $champ['type'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé par etr_valeur_affichee().
		}
		echo '</tbody></table>';
	}

	/**
	 * Metabox : lignes.
	 *
	 * @param WP_Post $post Demande.
	 */
	public static function mb_lignes( $post ) {
		echo etr_lignes_html( $post->ID, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans etr_lignes_html().
	}

	/**
	 * Metabox : statut + réponse au traiteur.
	 *
	 * @param WP_Post $post Demande.
	 */
	public static function mb_statut( $post ) {
		wp_nonce_field( 'etr_enregistrer_demande', 'etr_admin_nonce' );
		$statut = etr_get_statut( $post->ID );
		echo '<p><label for="etr_statut"><strong>' . esc_html__( 'Statut', 'espace-traiteur' ) . '</strong></label><br>';
		echo '<select name="etr_statut" id="etr_statut" class="widefat">';
		foreach ( etr_statuts() as $slug => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $statut, $slug, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		echo '<p><label for="etr_reponse"><strong>' . esc_html__( 'Message au traiteur', 'espace-traiteur' ) . '</strong></label>';
		echo '<textarea name="etr_reponse" id="etr_reponse" rows="5" class="widefat">' . esc_textarea( get_post_meta( $post->ID, '_etr_reponse', true ) ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Visible dans l’espace du traiteur. Un e-mail lui est envoyé à chaque changement de statut.', 'espace-traiteur' ) . '</p>';
	}

	/**
	 * Enregistre statut et réponse.
	 *
	 * @param int $post_id ID de la demande.
	 */
	public static function enregistrer_demande( $post_id ) {
		if ( ! isset( $_POST['etr_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_admin_nonce'] ) ), 'etr_enregistrer_demande' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'gerer_espace_traiteur' ) ) {
			return;
		}

		$ancien  = etr_get_statut( $post_id );
		$nouveau = isset( $_POST['etr_statut'] ) ? sanitize_key( wp_unslash( $_POST['etr_statut'] ) ) : $ancien;
		if ( ! array_key_exists( $nouveau, etr_statuts() ) ) {
			$nouveau = $ancien;
		}

		$reponse = isset( $_POST['etr_reponse'] ) ? sanitize_textarea_field( wp_unslash( $_POST['etr_reponse'] ) ) : '';
		update_post_meta( $post_id, '_etr_reponse', $reponse );
		update_post_meta( $post_id, '_etr_statut', $nouveau );

		if ( $ancien !== $nouveau ) {
			do_action( 'etr_statut_change', $post_id, $ancien, $nouveau );
		}
	}

	/**
	 * Colonnes de la liste des demandes.
	 *
	 * @param array $colonnes Colonnes.
	 * @return array
	 */
	public static function colonnes_demande( $colonnes ) {
		return array(
			'cb'                 => $colonnes['cb'],
			'title'              => __( 'Événement', 'espace-traiteur' ),
			'etr_traiteur'       => __( 'Traiteur', 'espace-traiteur' ),
			'etr_date_evenement' => __( 'Date de l’événement', 'espace-traiteur' ),
			'etr_articles'       => __( 'Articles', 'espace-traiteur' ),
			'etr_statut'         => __( 'Statut', 'espace-traiteur' ),
			'date'               => __( 'Envoyée le', 'espace-traiteur' ),
		);
	}

	/**
	 * Contenu des colonnes.
	 *
	 * @param string $colonne Colonne.
	 * @param int    $post_id ID.
	 */
	public static function colonne_demande( $colonne, $post_id ) {
		switch ( $colonne ) {
			case 'etr_traiteur':
				$user = get_userdata( (int) get_post_field( 'post_author', $post_id ) );
				echo $user ? esc_html( etr_nom( $user ) ) : '—';
				break;
			case 'etr_date_evenement':
				echo esc_html( etr_format_date( get_post_meta( $post_id, '_etr_date_evenement', true ) ) );
				break;
			case 'etr_articles':
				$total = 0;
				foreach ( etr_get_lignes( $post_id ) as $ligne ) {
					$total += (int) $ligne['quantite'];
				}
				echo esc_html( number_format_i18n( $total ) );
				break;
			case 'etr_statut':
				echo etr_statut_badge( etr_get_statut( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans etr_statut_badge().
				break;
		}
	}

	/**
	 * Menu déroulant de filtre par statut.
	 *
	 * @param string $post_type Type de contenu affiché.
	 */
	public static function filtre_statut( $post_type ) {
		if ( 'etr_demande' !== $post_type ) {
			return;
		}
		$courant = isset( $_GET['etr_statut'] ) ? sanitize_key( wp_unslash( $_GET['etr_statut'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="etr_statut"><option value="">' . esc_html__( 'Tous les statuts', 'espace-traiteur' ) . '</option>';
		foreach ( etr_statuts() as $slug => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $courant, $slug, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Applique le filtre par statut.
	 *
	 * @param WP_Query $query Requête.
	 */
	public static function appliquer_filtre_statut( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'etr_demande' !== $query->get( 'post_type' ) ) {
			return;
		}
		$statut = isset( $_GET['etr_statut'] ) ? sanitize_key( wp_unslash( $_GET['etr_statut'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $statut && array_key_exists( $statut, etr_statuts() ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => '_etr_statut',
						'value' => $statut,
					),
				)
			);
		}
	}

	/**
	 * Retire la « Modification rapide » des demandes (le statut se gère dans l'écran de la demande).
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Contenu.
	 * @return array
	 */
	public static function actions_ligne( $actions, $post ) {
		if ( 'etr_demande' === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/* ------------------------------------------------------------------ */
	/* Catalogue                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Metabox des articles.
	 */
	public static function metaboxes_article() {
		add_meta_box( 'etr_mb_article', __( 'Informations article', 'espace-traiteur' ), array( __CLASS__, 'mb_article' ), 'etr_article', 'side', 'high' );
	}

	/**
	 * Metabox : référence, stock, unité.
	 *
	 * @param WP_Post $post Article.
	 */
	public static function mb_article( $post ) {
		wp_nonce_field( 'etr_enregistrer_article', 'etr_article_nonce' );
		$champs = array(
			'reference' => array( __( 'Référence', 'espace-traiteur' ), 'text', '' ),
			'stock'     => array( __( 'Quantité disponible', 'espace-traiteur' ), 'number', __( 'Vide = pas de limite.', 'espace-traiteur' ) ),
			'unite'     => array( __( 'Unité', 'espace-traiteur' ), 'text', __( 'Ex. : pièce(s), lot(s) de 10…', 'espace-traiteur' ) ),
		);
		foreach ( $champs as $cle => $champ ) {
			printf(
				'<p><label for="etr_%1$s"><strong>%2$s</strong></label><br><input type="%3$s" id="etr_%1$s" name="etr_article[%1$s]" value="%4$s" class="widefat"%5$s>%6$s</p>',
				esc_attr( $cle ),
				esc_html( $champ[0] ),
				esc_attr( $champ[1] ),
				esc_attr( get_post_meta( $post->ID, '_etr_' . $cle, true ) ),
				'number' === $champ[1] ? ' min="0" step="1"' : '',
				$champ[2] ? '<span class="description">' . esc_html( $champ[2] ) . '</span>' : ''
			);
		}
	}

	/**
	 * Enregistre les informations article.
	 *
	 * @param int $post_id ID de l'article.
	 */
	public static function enregistrer_article( $post_id ) {
		if ( ! isset( $_POST['etr_article_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_article_nonce'] ) ), 'etr_enregistrer_article' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'gerer_espace_traiteur' ) ) {
			return;
		}
		$donnees = isset( $_POST['etr_article'] ) && is_array( $_POST['etr_article'] ) ? wp_unslash( $_POST['etr_article'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- assaini ci-dessous.

		update_post_meta( $post_id, '_etr_reference', sanitize_text_field( isset( $donnees['reference'] ) ? $donnees['reference'] : '' ) );
		update_post_meta( $post_id, '_etr_unite', sanitize_text_field( isset( $donnees['unite'] ) ? $donnees['unite'] : '' ) );

		$stock = isset( $donnees['stock'] ) ? trim( (string) $donnees['stock'] ) : '';
		update_post_meta( $post_id, '_etr_stock', '' === $stock ? '' : (string) absint( $stock ) );
	}

	/**
	 * Colonnes du catalogue.
	 *
	 * @param array $colonnes Colonnes.
	 * @return array
	 */
	public static function colonnes_article( $colonnes ) {
		$nouvelles = array();
		foreach ( $colonnes as $cle => $label ) {
			$nouvelles[ $cle ] = $label;
			if ( 'title' === $cle ) {
				$nouvelles['etr_reference'] = __( 'Référence', 'espace-traiteur' );
				$nouvelles['etr_stock']     = __( 'Disponible', 'espace-traiteur' );
			}
		}
		return $nouvelles;
	}

	/**
	 * Contenu des colonnes du catalogue.
	 *
	 * @param string $colonne Colonne.
	 * @param int    $post_id ID.
	 */
	public static function colonne_article( $colonne, $post_id ) {
		if ( 'etr_reference' === $colonne ) {
			$ref = get_post_meta( $post_id, '_etr_reference', true );
			echo esc_html( $ref ? $ref : '—' );
		} elseif ( 'etr_stock' === $colonne ) {
			$stock = get_post_meta( $post_id, '_etr_stock', true );
			echo '' === $stock ? esc_html__( 'Illimité', 'espace-traiteur' ) : esc_html( number_format_i18n( (int) $stock ) . ' ' . get_post_meta( $post_id, '_etr_unite', true ) );
		}
	}
}
