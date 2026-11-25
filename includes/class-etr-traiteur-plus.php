<?php
/**
 * Espace traiteur, fonctions activables :
 * - packs prêts à commander (« packs ») ;
 * - quantités suggérées selon le nombre d'invités (« suggestions ») ;
 * - calendrier des disponibilités (« calendrier_dispo ») ;
 * - plusieurs comptes par société (« multi_comptes ») ;
 * - avis après l'événement (« avis »).
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions de l'espace traiteur.
 */
class ETR_Traiteur_Plus {

	const OPTION        = 'etr_traiteur_plus';
	const META_PACK     = '_etr_pack_lignes';
	const META_RATIO    = '_etr_par_invite';
	const META_AVIS     = '_etr_avis';
	const META_CLOTURE  = '_etr_cloturee_le';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
		add_action( 'etr_fonctions_reglages', array( __CLASS__, 'champs_reglages' ) );

		// Packs.
		add_action( 'init', array( __CLASS__, 'type_pack' ) );
		add_action( 'add_meta_boxes_etr_pack', array( __CLASS__, 'metabox_pack' ) );
		add_action( 'save_post_etr_pack', array( __CLASS__, 'enregistrer_pack' ) );

		// Packs et suggestions dans le formulaire.
		add_action( 'etr_formulaire_catalogue_haut', array( __CLASS__, 'outils_formulaire' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ), 20 );
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'champ_ratio' ), 26 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'enregistrer_ratio' ) );

		// Calendrier des disponibilités, société.
		add_filter( 'etr_traiteur_menu', array( __CLASS__, 'menu' ) );
		add_action( 'etr_traiteur_vue_disponibilites', array( __CLASS__, 'vue_disponibilites' ) );
		add_action( 'etr_traiteur_vue_societe', array( __CLASS__, 'vue_societe' ) );
		add_action( 'etr_traiteur_action', array( __CLASS__, 'actions_traiteur' ) );

		// Avis.
		add_action( 'etr_statut_change', array( __CLASS__, 'noter_cloture' ), 10, 3 );
		add_action( 'etr_rappels_horaires', array( __CLASS__, 'demander_avis' ), 30 );
		add_action( 'etr_traiteur_demande_fin', array( __CLASS__, 'formulaire_avis' ), 2 );
		add_action( 'etr_gestion_fiche', array( __CLASS__, 'panneau_avis' ), 1 );
		add_action( 'etr_gestion_statistiques', array( __CLASS__, 'statistiques_avis' ), 20 );
	}

	/* ------------------------------------------------------------------ */
	/* Réglages                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Paramètres par défaut.
	 *
	 * @return array
	 */
	public static function defauts() {
		return array(
			'avis_delai'          => 1,  // Jours après la clôture.
			'societe_invitation'  => 1,  // Le traiteur ajoute lui-même ses collaborateurs.
			'societe_max'         => 5,  // Collaborateurs au plus par société.
			'calendrier_mois'     => 3,  // Mois consultables.
		);
	}

	/**
	 * Paramètres.
	 *
	 * @return array
	 */
	public static function parametres() {
		$p = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $p ) ? $p : array(), self::defauts() );
	}

	/**
	 * Option (page Fonctions).
	 */
	public static function reglages() {
		register_setting(
			'etr_modules',
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => function ( $saisie ) {
					$propre = self::parametres();
					$saisie = is_array( $saisie ) ? $saisie : array();
					foreach ( array( 'avis_delai' => array( 0, 30 ), 'societe_max' => array( 1, 50 ), 'calendrier_mois' => array( 1, 12 ) ) as $cle => $bornes ) {
						if ( isset( $saisie[ $cle ] ) ) {
							$propre[ $cle ] = min( $bornes[1], max( $bornes[0], absint( $saisie[ $cle ] ) ) );
						}
					}
					if ( isset( $saisie['societe_max'] ) ) {
						$propre['societe_invitation'] = empty( $saisie['societe_invitation'] ) ? 0 : 1;
					}
					return $propre;
				},
			)
		);
	}

	/**
	 * Champs sous les fonctions.
	 *
	 * @param string $cle Fonction.
	 */
	public static function champs_reglages( $cle ) {
		$p   = self::parametres();
		$nom = function ( $champ ) {
			return esc_attr( self::OPTION . '[' . $champ . ']' );
		};
		if ( 'packs' === $cle ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=etr_pack' ) ) . '">' . esc_html__( 'Gérer les packs', 'espace-traiteur' ) . '</a></p>';
		} elseif ( 'suggestions' === $cle ) {
			echo '<p class="description">' . esc_html__( 'Ratio de chaque produit : fiche produit WooCommerce › Inventaire › « Quantité conseillée par invité » (ex. 1,2 verre à vin, 2 assiettes, 0,1 table de 10).', 'espace-traiteur' ) . '</p>';
		} elseif ( 'calendrier_dispo' === $cle ) {
			echo '<p><label>' . esc_html__( 'Mois consultables', 'espace-traiteur' ) . ' <input type="number" min="1" max="12" class="small-text" name="' . $nom( 'calendrier_mois' ) . '" value="' . esc_attr( (string) $p['calendrier_mois'] ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'multi_comptes' === $cle ) {
			echo '<p><input type="hidden" name="' . $nom( 'societe_invitation' ) . '" value="0"><label><input type="checkbox" name="' . $nom( 'societe_invitation' ) . '" value="1"' . checked( $p['societe_invitation'], 1, false ) . '> ' . esc_html__( 'Le traiteur ajoute lui-même ses collaborateurs (écran « Mon équipe »)', 'espace-traiteur' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><label>' . esc_html__( 'Collaborateurs au plus par société', 'espace-traiteur' ) . ' <input type="number" min="1" max="50" class="small-text" name="' . $nom( 'societe_max' ) . '" value="' . esc_attr( (string) $p['societe_max'] ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'avis' === $cle ) {
			echo '<p><label>' . esc_html__( 'Demander l’avis', 'espace-traiteur' ) . ' <input type="number" min="0" max="30" class="small-text" name="' . $nom( 'avis_delai' ) . '" value="' . esc_attr( (string) $p['avis_delai'] ) . '"> ' . esc_html__( 'jour(s) après la clôture de la demande', 'espace-traiteur' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ------------------------------------------------------------------ */
	/* Packs                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Type « Pack » (administration).
	 */
	public static function type_pack() {
		$cap = 'gerer_espace_traiteur';
		register_post_type(
			'etr_pack',
			array(
				'labels'       => array(
					'name'          => __( 'Packs', 'espace-traiteur' ),
					'singular_name' => __( 'Pack', 'espace-traiteur' ),
					'all_items'     => __( 'Packs', 'espace-traiteur' ),
					'add_new'       => __( 'Ajouter un pack', 'espace-traiteur' ),
					'add_new_item'  => __( 'Ajouter un pack', 'espace-traiteur' ),
					'edit_item'     => __( 'Modifier le pack', 'espace-traiteur' ),
					'not_found'     => __( 'Aucun pack.', 'espace-traiteur' ),
				),
				'public'       => false,
				'show_ui'      => ETR_Modules::actif( 'packs' ),
				'show_in_menu' => 'edit.php?post_type=etr_demande',
				'supports'     => array( 'title', 'excerpt' ),
				'capabilities' => array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_published_posts', 'edit_private_posts', 'create_posts' ), $cap ),
				'map_meta_cap' => false,
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Lignes d'un pack : article => { q, invite (quantité par invité) }.
	 *
	 * @param int $pack Pack.
	 * @return array
	 */
	public static function lignes_pack( $pack ) {
		$l = get_post_meta( $pack, self::META_PACK, true );
		return is_array( $l ) ? $l : array();
	}

	/**
	 * Boîte « Contenu du pack ».
	 */
	public static function metabox_pack() {
		add_meta_box(
			'etr_pack_contenu',
			__( 'Contenu du pack', 'espace-traiteur' ),
			function ( $post ) {
				wp_nonce_field( 'etr_pack', 'etr_pack_nonce' );
				$lignes = self::lignes_pack( $post->ID );
				echo '<p class="description">' . esc_html__( 'Quantité fixe, ou quantité par invité (cochez « par invité » : 1,2 verre par invité donne 120 verres pour 100 invités). Laissez vide pour ne pas inclure l’article.', 'espace-traiteur' ) . '</p>';
				echo '<p><input type="search" class="regular-text" placeholder="' . esc_attr__( 'Filtrer les articles…', 'espace-traiteur' ) . '" oninput="var q=this.value.toLowerCase();document.querySelectorAll(\'.etr-pack-ligne\').forEach(function(l){l.style.display=l.dataset.r.indexOf(q)>-1?\'\':\'none\';});"></p>';
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Article', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Quantité', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Par invité', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
				foreach ( etr_catalogue() as $article ) {
					$l = isset( $lignes[ $article['id'] ] ) ? $lignes[ $article['id'] ] : array( 'q' => '', 'invite' => false );
					echo '<tr class="etr-pack-ligne" data-r="' . esc_attr( mb_strtolower( $article['titre'] . ' ' . $article['reference'] ) ) . '"><td>' . esc_html( $article['titre'] ) . '</td>';
					echo '<td><input type="text" inputmode="decimal" class="small-text" name="etr_pack[' . (int) $article['id'] . '][q]" value="' . esc_attr( '' === $l['q'] ? '' : str_replace( '.', ',', (string) $l['q'] ) ) . '"></td>';
					echo '<td><input type="checkbox" name="etr_pack[' . (int) $article['id'] . '][invite]" value="1"' . checked( ! empty( $l['invite'] ), true, false ) . '></td></tr>';
				}
				echo '</tbody></table>';
			},
			'etr_pack',
			'normal',
			'high'
		);
	}

	/**
	 * Enregistre le contenu du pack.
	 *
	 * @param int $post_id Pack.
	 */
	public static function enregistrer_pack( $post_id ) {
		if ( ! isset( $_POST['etr_pack_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['etr_pack_nonce'] ) ), 'etr_pack' ) || ! current_user_can( 'gerer_espace_traiteur' ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$saisie = isset( $_POST['etr_pack'] ) && is_array( $_POST['etr_pack'] ) ? wp_unslash( $_POST['etr_pack'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé ci-dessous.
		$lignes = array();
		foreach ( $saisie as $article => $l ) {
			$q = isset( $l['q'] ) ? etr_lire_nombre( sanitize_text_field( $l['q'] ) ) : null;
			if ( $q && etr_get_article( (int) $article ) ) {
				$lignes[ (int) $article ] = array(
					'q'      => (float) $q,
					'invite' => ! empty( $l['invite'] ),
				);
			}
		}
		update_post_meta( $post_id, self::META_PACK, $lignes );
	}

	/**
	 * Packs publiés (pour le formulaire).
	 *
	 * @return array[] { id, titre, description, lignes }
	 */
	public static function packs() {
		$packs = array();
		foreach ( get_posts( array( 'post_type' => 'etr_pack', 'post_status' => 'publish', 'numberposts' => 50, 'orderby' => 'title', 'order' => 'ASC' ) ) as $pack ) {
			$lignes = self::lignes_pack( $pack->ID );
			if ( $lignes ) {
				$packs[] = array(
					'id'          => $pack->ID,
					'titre'       => $pack->post_title,
					'description' => $pack->post_excerpt,
					'lignes'      => $lignes,
				);
			}
		}
		return $packs;
	}

	/* ------------------------------------------------------------------ */
	/* Quantités suggérées                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Champ « Quantité conseillée par invité ».
	 */
	public static function champ_ratio() {
		if ( ! ETR_Modules::actif( 'suggestions' ) || ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_RATIO,
				'label'       => __( 'Quantité conseillée par invité', 'espace-traiteur' ),
				'description' => __( 'Espace traiteur : quantité suggérée = invités × ce nombre (arrondi au-dessus).', 'espace-traiteur' ),
				'desc_tip'    => true,
				'data_type'   => 'decimal',
			)
		);
	}

	/**
	 * Enregistre le ratio.
	 *
	 * @param WC_Product $produit Produit.
	 */
	public static function enregistrer_ratio( $produit ) {
		if ( ! ETR_Modules::actif( 'suggestions' ) || ! isset( $_POST[ self::META_RATIO ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$v = etr_lire_nombre( sanitize_text_field( wp_unslash( $_POST[ self::META_RATIO ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $v ) {
			$produit->update_meta_data( self::META_RATIO, $v );
		} else {
			$produit->delete_meta_data( self::META_RATIO );
		}
	}

	/**
	 * Ratios : article => quantité par invité.
	 *
	 * @return array<int,float>
	 */
	public static function ratios() {
		$ratios = array();
		foreach ( etr_catalogue() as $article ) {
			$r = (float) get_post_meta( $article['id'], self::META_RATIO, true );
			if ( $r > 0 ) {
				$ratios[ $article['id'] ] = $r;
			}
		}
		return $ratios;
	}

	/**
	 * En tête du catalogue : choisir un pack, suggérer les quantités.
	 */
	public static function outils_formulaire() {
		$packs  = ETR_Modules::actif( 'packs' ) ? self::packs() : array();
		$ratios = ETR_Modules::actif( 'suggestions' ) ? self::ratios() : array();
		if ( ! $packs && ! $ratios ) {
			return;
		}
		echo '<div class="etr-aide-quantites" id="etr-aide-quantites">';
		if ( $packs ) {
			echo '<div class="etr-aide-quantites__packs"><label for="etr-pack">' . esc_html__( 'Partir d’un pack', 'espace-traiteur' ) . '</label><span><select id="etr-pack"><option value="">' . esc_html__( '— Choisir un pack —', 'espace-traiteur' ) . '</option>';
			foreach ( $packs as $pack ) {
				echo '<option value="' . (int) $pack['id'] . '">' . esc_html( $pack['titre'] ) . '</option>';
			}
			echo '</select><button type="button" class="etr-btn etr-btn--secondaire" data-appliquer-pack>' . esc_html__( 'Ajouter au panier', 'espace-traiteur' ) . '</button></span><small data-pack-description></small></div>';
		}
		if ( $ratios ) {
			echo '<p class="etr-aide-quantites__suggestion"><button type="button" class="etr-btn etr-btn--secondaire" data-suggerer>' . esc_html__( 'Suggérer les quantités pour mes invités', 'espace-traiteur' ) . '</button> <small>' . esc_html__( 'D’après le nombre d’invités indiqué plus haut ; seules les quantités vides sont remplies.', 'espace-traiteur' ) . '</small></p>';
		}
		echo '<p class="etr-aide-quantites__message" role="status"></p></div>';
	}

	/**
	 * Script du formulaire (packs, suggestions).
	 */
	public static function script() {
		if ( ! etr_est_traiteur() || ! class_exists( 'ETR_Frontend' ) || ! ETR_Frontend::sur_page_espace() || ( ! ETR_Modules::actif( 'packs' ) && ! ETR_Modules::actif( 'suggestions' ) ) ) {
			return;
		}
		wp_enqueue_script( 'espace-traiteur-plus', ETR_URL . 'assets/js/traiteur-plus.js', array(), ETR_VERSION, true );
		wp_localize_script(
			'espace-traiteur-plus',
			'etrQuantites',
			array(
				'packs'  => ETR_Modules::actif( 'packs' ) ? self::packs() : array(),
				'ratios' => ETR_Modules::actif( 'suggestions' ) ? self::ratios() : (object) array(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Menu de l'espace : disponibilités, équipe                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Liens du menu.
	 *
	 * @param array $liens Liens.
	 * @return array
	 */
	public static function menu( $liens ) {
		if ( ETR_Modules::actif( 'calendrier_dispo' ) ) {
			$liens['disponibilites'] = array( etr_url( array( 'etr_vue' => 'disponibilites' ) ), __( 'Disponibilités', 'espace-traiteur' ), 'calendrier' );
		}
		if ( self::peut_gerer_societe() ) {
			$liens['societe'] = array( etr_url( array( 'etr_vue' => 'societe' ) ), __( 'Mon équipe', 'espace-traiteur' ), 'invites' );
		}
		return $liens;
	}

	/* ------------------------------------------------------------------ */
	/* Calendrier des disponibilités                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Réservations des demandes actives : { debut, fin, lignes article => quantité }.
	 *
	 * @return array[]
	 */
	private static function reservations() {
		$ids = get_posts(
			array(
				'post_type'     => 'etr_demande',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_etr_statut',
						'value'   => etr_statuts_reservant(),
						'compare' => 'IN',
					),
				),
			)
		);
		$r = array();
		foreach ( $ids as $id ) {
			list( $d, $f ) = etr_periode_demande( $id );
			if ( ! $d ) {
				continue;
			}
			$lignes = array();
			foreach ( etr_get_lignes( $id ) as $ligne ) {
				$lignes[ (int) $ligne['article_id'] ] = ( isset( $lignes[ (int) $ligne['article_id'] ] ) ? $lignes[ (int) $ligne['article_id'] ] : 0 ) + (int) $ligne['quantite'];
			}
			$r[] = array( 'debut' => $d, 'fin' => $f, 'lignes' => $lignes );
		}
		return $r;
	}

	/**
	 * Disponibilité d'un article jour par jour.
	 *
	 * @param int    $article Article.
	 * @param string $debut   AAAA-MM-JJ.
	 * @param int    $jours   Nombre de jours.
	 * @return array<string,int|null> date => quantité disponible (null : illimité).
	 */
	public static function dispo_jours( $article, $debut, $jours ) {
		$stock = etr_stock_total( $article );
		$res   = self::reservations();
		$out   = array();
		for ( $i = 0; $i < $jours; $i++ ) {
			$jour = gmdate( 'Y-m-d', strtotime( $debut . ' +' . $i . ' days' ) );
			if ( null === $stock ) {
				$out[ $jour ] = null;
				continue;
			}
			$pris = 0;
			foreach ( $res as $r ) {
				if ( $r['debut'] <= $jour && $r['fin'] >= $jour && isset( $r['lignes'][ $article ] ) ) {
					$pris += $r['lignes'][ $article ];
				}
			}
			$out[ $jour ] = max( 0, $stock - $pris );
		}
		return $out;
	}

	/**
	 * Écran « Disponibilités » : un article, mois par mois.
	 */
	public static function vue_disponibilites() {
		if ( ! ETR_Modules::actif( 'calendrier_dispo' ) ) {
			return;
		}
		$p        = self::parametres();
		$articles = array_filter(
			etr_catalogue(),
			function ( $a ) {
				return null !== etr_stock_total( $a['id'] );
			}
		);
		// phpcs:disable WordPress.Security.NonceVerification -- lecture seule.
		$choisi = isset( $_GET['article'] ) ? absint( $_GET['article'] ) : 0;
		$decal  = isset( $_GET['mois'] ) ? min( $p['calendrier_mois'] - 1, max( 0, absint( $_GET['mois'] ) ) ) : 0;
		// phpcs:enable
		echo '<header class="etr-page-entete"><div><h1 class="etr-page-entete__titre">' . esc_html__( 'Disponibilités', 'espace-traiteur' ) . '</h1><p class="etr-page-entete__sous">' . esc_html__( 'Choisissez un article : chaque jour indique la quantité encore libre.', 'espace-traiteur' ) . '</p></div></header>';
		echo '<form method="get" class="etr-dispo-calendrier__choix" action="' . esc_url( etr_url() ) . '"><input type="hidden" name="etr_vue" value="disponibilites">';
		foreach ( array( 'page_id', 'p' ) as $garde ) {
			if ( isset( $_GET[ $garde ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				echo '<input type="hidden" name="' . esc_attr( $garde ) . '" value="' . esc_attr( absint( $_GET[ $garde ] ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
			}
		}
		echo '<label class="screen-reader-text" for="etr-dispo-article">' . esc_html__( 'Article', 'espace-traiteur' ) . '</label><select id="etr-dispo-article" name="article" onchange="this.form.submit()"><option value="">' . esc_html__( '— Choisir un article —', 'espace-traiteur' ) . '</option>';
		foreach ( $articles as $a ) {
			echo '<option value="' . (int) $a['id'] . '"' . selected( $choisi, $a['id'], false ) . '>' . esc_html( $a['titre'] ) . '</option>';
		}
		echo '</select><noscript><button type="submit" class="etr-btn">' . esc_html__( 'Voir', 'espace-traiteur' ) . '</button></noscript></form>';
		if ( ! $choisi || ! isset( $articles[ array_search( $choisi, array_column( $articles, 'id' ), true ) ] ) ) {
			echo '<p class="etr-muted">' . esc_html__( 'Seuls les articles en quantité limitée sont listés ; les autres sont toujours disponibles.', 'espace-traiteur' ) . '</p>';
			return;
		}
		$premier = gmdate( 'Y-m-01', strtotime( current_time( 'Y-m-01' ) . ' +' . $decal . ' months' ) );
		$nb      = (int) gmdate( 't', strtotime( $premier ) );
		$jours   = self::dispo_jours( $choisi, $premier, $nb );
		$stock   = etr_stock_total( $choisi );
		$auj     = current_time( 'Y-m-d' );
		$nav     = '<nav class="etr-dispo-calendrier__nav">';
		if ( $decal > 0 ) {
			$nav .= '<a class="etr-btn etr-btn--discret" href="' . esc_url( etr_url( array( 'etr_vue' => 'disponibilites', 'article' => $choisi, 'mois' => $decal - 1 ) ) ) . '">‹</a>';
		}
		$nav .= '<strong>' . esc_html( ucfirst( date_i18n( 'F Y', strtotime( $premier ) ) ) ) . '</strong>';
		if ( $decal < $p['calendrier_mois'] - 1 ) {
			$nav .= '<a class="etr-btn etr-btn--discret" href="' . esc_url( etr_url( array( 'etr_vue' => 'disponibilites', 'article' => $choisi, 'mois' => $decal + 1 ) ) ) . '">›</a>';
		}
		echo $nav . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput -- construit et échappé ci-dessus.
		echo '<div class="etr-dispo-calendrier" role="table">';
		for ( $i = 0; $i < 7; $i++ ) {
			echo '<span class="etr-dispo-calendrier__entete" role="columnheader">' . esc_html( date_i18n( 'D', strtotime( '2024-01-01 +' . $i . ' days' ) ) ) . '</span>';
		}
		$decalage = (int) gmdate( 'N', strtotime( $premier ) ) - 1;
		for ( $i = 0; $i < $decalage; $i++ ) {
			echo '<span class="etr-dispo-calendrier__vide"></span>';
		}
		foreach ( $jours as $jour => $dispo ) {
			$passe  = $jour < $auj;
			$classe = $passe ? 'passe' : ( 0 === $dispo ? 'complet' : ( $dispo < $stock / 2 ? 'limite' : 'libre' ) );
			echo '<span class="etr-dispo-calendrier__jour est-' . esc_attr( $classe ) . '" role="cell"><b>' . esc_html( (string) (int) substr( $jour, 8 ) ) . '</b>' . ( $passe ? '' : '<small>' . esc_html( number_format_i18n( (int) $dispo ) ) . '</small>' ) . '</span>';
		}
		echo '</div>';
		/* translators: %s : stock. */
		echo '<p class="etr-muted etr-dispo-calendrier__legende"><span class="est-libre">' . esc_html__( 'Disponible', 'espace-traiteur' ) . '</span> <span class="est-limite">' . esc_html__( 'Moins de la moitié', 'espace-traiteur' ) . '</span> <span class="est-complet">' . esc_html__( 'Complet', 'espace-traiteur' ) . '</span> · ' . esc_html( sprintf( __( 'Stock total : %s', 'espace-traiteur' ), number_format_i18n( (int) $stock ) ) ) . '</p>';
		echo '<p><a class="etr-btn" href="' . esc_url( etr_url( array( 'etr_vue' => 'nouvelle' ) ) ) . '">' . esc_html__( 'Faire une demande', 'espace-traiteur' ) . '</a></p>';
	}

	/* ------------------------------------------------------------------ */
	/* Plusieurs comptes par société                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Le traiteur connecté peut-il gérer les comptes de sa société ?
	 *
	 * @return bool
	 */
	public static function peut_gerer_societe() {
		return ETR_Modules::actif( 'multi_comptes' ) && self::parametres()['societe_invitation'] && etr_est_traiteur() && etr_societe_id() === get_current_user_id();
	}

	/**
	 * Collaborateurs d'une société.
	 *
	 * @param int $principal Compte principal.
	 * @return WP_User[]
	 */
	public static function collaborateurs( $principal ) {
		return get_users(
			array(
				'meta_key'   => 'etr_societe', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (int) $principal, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/**
	 * Écran « Mon équipe » : collaborateurs, ajout, retrait.
	 */
	public static function vue_societe() {
		if ( ! self::peut_gerer_societe() ) {
			return;
		}
		$moi    = get_current_user_id();
		$membres = self::collaborateurs( $moi );
		$max    = (int) self::parametres()['societe_max'];
		// phpcs:ignore WordPress.Security.NonceVerification -- message après redirection.
		$msg    = isset( $_GET['etr_msg'] ) ? sanitize_key( wp_unslash( $_GET['etr_msg'] ) ) : '';
		$textes = array(
			'collaborateur_ajoute'  => array( 'succes', __( 'Collaborateur ajouté : il a reçu un e-mail pour choisir son mot de passe.', 'espace-traiteur' ) ),
			'collaborateur_retire'  => array( 'succes', __( 'Accès retiré.', 'espace-traiteur' ) ),
			'collaborateur_email'   => array( 'erreur', __( 'Adresse e-mail invalide ou déjà utilisée.', 'espace-traiteur' ) ),
			'collaborateur_max'     => array( 'erreur', __( 'Nombre maximal de collaborateurs atteint.', 'espace-traiteur' ) ),
		);
		echo '<header class="etr-page-entete"><div><h1 class="etr-page-entete__titre">' . esc_html__( 'Mon équipe', 'espace-traiteur' ) . '</h1><p class="etr-page-entete__sous">' . esc_html__( 'Vos collaborateurs ont leur propre accès : ils voient et font les demandes de votre société.', 'espace-traiteur' ) . '</p></div></header>';
		if ( isset( $textes[ $msg ] ) ) {
			echo '<p class="etr-notice etr-notice--' . esc_attr( $textes[ $msg ][0] ) . '">' . esc_html( $textes[ $msg ][1] ) . '</p>';
		}
		echo '<section class="etr-panneau"><ul class="etr-societe">';
		echo '<li><strong>' . esc_html( etr_nom( wp_get_current_user() ) ) . '</strong> <span class="etr-muted">' . esc_html( wp_get_current_user()->user_email ) . ' · ' . esc_html__( 'compte principal', 'espace-traiteur' ) . '</span></li>';
		foreach ( $membres as $membre ) {
			$contact = (string) get_user_meta( $membre->ID, 'etr_contact', true );
			echo '<li><span><strong>' . esc_html( $contact ? $contact : etr_nom( $membre ) ) . '</strong> <span class="etr-muted">' . esc_html( $membre->user_email ) . '</span></span>';
			echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Retirer l’accès de ce collaborateur ?', 'espace-traiteur' ) ) . '\');">' . wp_nonce_field( 'etr_retirer_collaborateur_' . $membre->ID, 'etr_nonce', true, false ) . '<input type="hidden" name="etr_action" value="retirer_collaborateur"><input type="hidden" name="collaborateur" value="' . (int) $membre->ID . '"><button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Retirer', 'espace-traiteur' ) . '</button></form></li>';
		}
		echo '</ul></section>';
		if ( count( $membres ) < $max ) {
			echo '<form method="post" class="etr-panneau etr-societe__ajout"><h2 class="etr-panneau__titre">' . esc_html__( 'Ajouter un collaborateur', 'espace-traiteur' ) . '</h2>';
			wp_nonce_field( 'etr_ajouter_collaborateur', 'etr_nonce' );
			echo '<input type="hidden" name="etr_action" value="ajouter_collaborateur"><div class="etr-grille">';
			echo '<p class="etr-champ"><label for="etr-collab-nom">' . esc_html__( 'Prénom et nom', 'espace-traiteur' ) . '</label><input type="text" id="etr-collab-nom" name="nom" required maxlength="100"></p>';
			echo '<p class="etr-champ"><label for="etr-collab-email">' . esc_html__( 'E-mail', 'espace-traiteur' ) . '</label><input type="email" id="etr-collab-email" name="email" required></p></div>';
			echo '<p><button type="submit" class="etr-btn">' . esc_html__( 'Envoyer l’invitation', 'espace-traiteur' ) . '</button></p></form>';
		}
	}

	/**
	 * Formulaires de l'espace : collaborateurs, avis.
	 *
	 * @param string $action Action.
	 */
	public static function actions_traiteur( $action ) {
		$nonce = isset( $_POST['etr_nonce'] ) ? sanitize_key( wp_unslash( $_POST['etr_nonce'] ) ) : '';
		if ( 'ajouter_collaborateur' === $action ) {
			if ( ! self::peut_gerer_societe() || ! wp_verify_nonce( $nonce, 'etr_ajouter_collaborateur' ) ) {
				wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
			}
			$moi = get_current_user_id();
			if ( count( self::collaborateurs( $moi ) ) >= (int) self::parametres()['societe_max'] ) {
				self::retour( 'societe', 'collaborateur_max' );
			}
			$r = ETR_Traiteurs::creer_compte(
				array(
					'societe' => etr_nom( wp_get_current_user() ),
					'contact' => isset( $_POST['nom'] ) ? sanitize_text_field( wp_unslash( $_POST['nom'] ) ) : '',
					'email'   => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				),
				false
			);
			if ( is_wp_error( $r ) ) {
				self::retour( 'societe', 'collaborateur_email' );
			}
			update_user_meta( $r['user_id'], 'etr_societe', $moi );
			ETR_Traiteurs::envoyer_invitation( get_userdata( $r['user_id'] ) );
			self::retour( 'societe', 'collaborateur_ajoute' );
		}
		if ( 'retirer_collaborateur' === $action ) {
			$membre = isset( $_POST['collaborateur'] ) ? absint( $_POST['collaborateur'] ) : 0;
			if ( ! self::peut_gerer_societe() || ! wp_verify_nonce( $nonce, 'etr_retirer_collaborateur_' . $membre ) || (int) get_user_meta( $membre, 'etr_societe', true ) !== get_current_user_id() ) {
				wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
			}
			delete_user_meta( $membre, 'etr_societe' );
			if ( ! ETR_Comptes::est_desactive( $membre ) ) {
				ETR_Traiteurs::basculer_acces( $membre ); // Désactivé et déconnecté ; ses demandes restent à la société.
			}
			self::retour( 'societe', 'collaborateur_retire' );
		}
		if ( 'donner_avis' === $action ) {
			$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
			if ( ! ETR_Modules::actif( 'avis' ) || ! wp_verify_nonce( $nonce, 'etr_avis_' . $id ) || ! etr_peut_voir_demande( $id ) || 'cloturee' !== etr_get_statut( $id ) ) {
				wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
			}
			$note  = isset( $_POST['note'] ) ? min( 5, max( 1, absint( $_POST['note'] ) ) ) : 0;
			$texte = isset( $_POST['commentaire'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['commentaire'] ) ), 0, 2000 ) : '';
			if ( $note && ! get_post_meta( $id, self::META_AVIS, true ) ) {
				update_post_meta( $id, self::META_AVIS, array( 'note' => $note, 'texte' => $texte, 'le' => time(), 'par' => get_current_user_id() ) );
				ETR_Historique::ajouter( $id, 'avis', sprintf( /* translators: %s : note. */ __( 'Avis du traiteur : %s/5', 'espace-traiteur' ), $note ) );
				/* translators: 1 : note, 2 : traiteur. */
				ETR_Push::notifier( 'bureau', sprintf( __( 'Avis %1$s/5 : %2$s', 'espace-traiteur' ), $note, etr_nom( wp_get_current_user() ) ), get_the_title( $id ) . ( $texte ? ' · ' . $texte : '' ), $id, '', 'avis' );
			}
			wp_safe_redirect( etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) . '#etr-avis' );
			exit;
		}
	}

	/**
	 * Redirection avec message.
	 *
	 * @param string $vue Écran.
	 * @param string $msg Message.
	 */
	private static function retour( $vue, $msg ) {
		wp_safe_redirect( etr_url( array( 'etr_vue' => $vue, 'etr_msg' => $msg ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Avis                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Date de clôture.
	 *
	 * @param int    $id      Demande.
	 * @param string $ancien  Ancien statut.
	 * @param string $nouveau Nouveau statut.
	 */
	public static function noter_cloture( $id, $ancien, $nouveau ) {
		if ( 'cloturee' === $nouveau ) {
			update_post_meta( $id, self::META_CLOTURE, time() );
		}
	}

	/**
	 * Demande d'avis par e-mail (une fois), quelques jours après la clôture.
	 */
	public static function demander_avis() {
		if ( ! ETR_Modules::actif( 'avis' ) ) {
			return;
		}
		$limite = time() - DAY_IN_SECONDS * (int) self::parametres()['avis_delai'];
		$ids    = get_posts(
			array(
				'post_type'     => 'etr_demande',
				'post_status'   => 'publish',
				'numberposts'   => 50,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_etr_statut', 'value' => 'cloturee' ),
					array( 'key' => self::META_CLOTURE, 'value' => $limite, 'compare' => '<=', 'type' => 'NUMERIC' ),
					array( 'key' => self::META_CLOTURE, 'value' => time() - 30 * DAY_IN_SECONDS, 'compare' => '>=', 'type' => 'NUMERIC' ),
					array( 'key' => '_etr_avis_demande', 'compare' => 'NOT EXISTS' ),
					array( 'key' => self::META_AVIS, 'compare' => 'NOT EXISTS' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_etr_avis_demande', time() );
			/* translators: %s : événement. */
			ETR_Traiteur_Direct::prevenir( $id, __( 'Votre avis nous intéresse', 'espace-traiteur' ), sprintf( __( 'Merci pour votre confiance pour « %s ». Comment s’est passée la location ? Donnez-nous votre avis en une minute, depuis votre espace (note et commentaire).', 'espace-traiteur' ), get_the_title( $id ) ) );
		}
	}

	/**
	 * Formulaire d'avis (demande clôturée), ou avis donné.
	 *
	 * @param int $id Demande.
	 */
	public static function formulaire_avis( $id ) {
		if ( ! ETR_Modules::actif( 'avis' ) || 'cloturee' !== etr_get_statut( $id ) || ! etr_peut_voir_demande( $id ) ) {
			return;
		}
		$avis = get_post_meta( $id, self::META_AVIS, true );
		echo '<section class="etr-panneau etr-avis" id="etr-avis"><h2 class="etr-panneau__titre">' . esc_html__( 'Votre avis', 'espace-traiteur' ) . '</h2>';
		if ( is_array( $avis ) ) {
			echo '<p class="etr-avis__etoiles" aria-label="' . esc_attr( $avis['note'] . '/5' ) . '">' . esc_html( str_repeat( '★', (int) $avis['note'] ) . str_repeat( '☆', 5 - (int) $avis['note'] ) ) . '</p>';
			echo $avis['texte'] ? '<p>' . nl2br( esc_html( $avis['texte'] ) ) . '</p>' : '';
			echo '<p class="etr-muted">' . esc_html__( 'Merci pour votre retour !', 'espace-traiteur' ) . '</p></section>';
			return;
		}
		echo '<form method="post">' . wp_nonce_field( 'etr_avis_' . $id, 'etr_nonce', true, false ) . '<input type="hidden" name="etr_action" value="donner_avis"><input type="hidden" name="demande_id" value="' . (int) $id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- champ de sécurité WordPress.
		echo '<fieldset class="etr-avis__notes"><legend>' . esc_html__( 'Votre note', 'espace-traiteur' ) . '</legend>';
		for ( $n = 5; $n >= 1; $n-- ) {
			echo '<input type="radio" id="etr-note-' . (int) $n . '" name="note" value="' . (int) $n . '" required><label for="etr-note-' . (int) $n . '" title="' . esc_attr( $n . '/5' ) . '">★</label>';
		}
		echo '</fieldset>';
		echo '<p class="etr-champ"><label for="etr-avis-texte">' . esc_html__( 'Un commentaire ?', 'espace-traiteur' ) . ' <span class="etr-champ__option">' . esc_html__( 'facultatif', 'espace-traiteur' ) . '</span></label><textarea id="etr-avis-texte" name="commentaire" rows="3" maxlength="2000"></textarea></p>';
		echo '<button type="submit" class="etr-btn">' . esc_html__( 'Envoyer mon avis', 'espace-traiteur' ) . '</button></form></section>';
	}

	/**
	 * Avis sur la fiche (équipe).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_avis( $id ) {
		$avis = ETR_Modules::actif( 'avis' ) ? get_post_meta( $id, self::META_AVIS, true ) : '';
		if ( ! is_array( $avis ) ) {
			return;
		}
		echo '<section class="etr-panneau etr-avis"><h2 class="etr-panneau__titre">' . esc_html__( 'Avis du traiteur', 'espace-traiteur' ) . '</h2><p class="etr-avis__etoiles">' . esc_html( str_repeat( '★', (int) $avis['note'] ) . str_repeat( '☆', 5 - (int) $avis['note'] ) ) . '</p>';
		echo $avis['texte'] ? '<p>' . nl2br( esc_html( $avis['texte'] ) ) . '</p>' : '';
		echo '<p class="etr-muted">' . esc_html( wp_date( 'j F Y', (int) $avis['le'] ) ) . '</p></section>';
	}

	/**
	 * Statistiques : note moyenne et derniers avis.
	 */
	public static function statistiques_avis() {
		if ( ! ETR_Modules::actif( 'avis' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		$ids = get_posts( array( 'post_type' => 'etr_demande', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids', 'meta_key' => self::META_AVIS, 'no_found_rows' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! $ids ) {
			return;
		}
		$notes = array();
		$liste = array();
		foreach ( $ids as $id ) {
			$a = get_post_meta( $id, self::META_AVIS, true );
			if ( is_array( $a ) ) {
				$notes[] = (int) $a['note'];
				$liste[] = array( $a['le'], $id, $a );
			}
		}
		rsort( $liste );
		echo '<div class="etr-stats etr-stats--terrain"><section class="etr-stats__section"><div class="etr-stats__entete"><div><h2 class="etr-section-titre">' . esc_html__( 'Avis des traiteurs', 'espace-traiteur' ) . '</h2>';
		/* translators: 1 : moyenne, 2 : nombre d'avis. */
		echo '<p>' . esc_html( sprintf( _n( 'Note moyenne %1$s/5 sur %2$s avis.', 'Note moyenne %1$s/5 sur %2$s avis.', count( $notes ), 'espace-traiteur' ), number_format_i18n( array_sum( $notes ) / count( $notes ), 1 ), number_format_i18n( count( $notes ) ) ) ) . '</p></div></div><ul class="etr-liste-simple">';
		foreach ( array_slice( $liste, 0, 5 ) as $l ) {
			echo '<li><strong>' . esc_html( str_repeat( '★', (int) $l[2]['note'] ) ) . '</strong> ' . esc_html( ETR_Operations::traiteur( $l[1] ) . ' — ' . get_the_title( $l[1] ) . ( $l[2]['texte'] ? ' : « ' . wp_trim_words( $l[2]['texte'], 20 ) . ' »' : '' ) ) . '</li>';
		}
		echo '</ul></section></div>';
	}
}
