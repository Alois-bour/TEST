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
		add_submenu_page(
			'edit.php?post_type=etr_demande',
			__( 'Catégories', 'espace-traiteur' ),
			__( 'Catégories', 'espace-traiteur' ),
			'gerer_espace_traiteur',
			'edit-tags.php?taxonomy=etr_categorie&post_type=etr_article'
		);
	}

	/**
	 * Feuille de style sur les écrans du plugin.
	 */
	public static function assets() {
		$ecran = get_current_screen();
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
				esc_html( $user->display_name ),
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
				echo $user ? esc_html( $user->display_name ) : '—';
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
