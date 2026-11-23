<?php
/**
 * Catalogue : articles proposés aux traiteurs.
 *
 * Quand WooCommerce est actif, le catalogue est celui des produits du site
 * (mobilier, art de la table, textile, décoration…). Sinon, le plugin utilise
 * son propre catalogue (type de contenu « etr_article »).
 *
 * Chaque article est normalisé sous la forme :
 * array(
 *   'id'          => int,
 *   'titre'       => string,
 *   'categorie'   => string,  // « Parent › Enfant »
 *   'ordre'       => string,  // clé de tri des catégories
 *   'image'       => string,  // HTML
 *   'description' => string,  // texte brut court
 *   'reference'   => string,
 *   'unite'       => string,
 *   'url'         => string,  // fiche publique (vide si aucune)
 *   'prix_html'   => string,  // HTML (vide si non affiché)
 * )
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Le catalogue vient-il de WooCommerce ?
 *
 * @return bool
 */
function etr_source_woocommerce() {
	return (bool) apply_filters( 'etr_source_woocommerce', function_exists( 'wc_get_product' ) );
}

/**
 * Taxonomie des catégories du catalogue.
 *
 * @return string
 */
function etr_taxonomie_categories() {
	return etr_source_woocommerce() ? 'product_cat' : 'etr_categorie';
}

/**
 * Catégories retenues dans les réglages (vide = toutes).
 *
 * @return int[]
 */
function etr_categories_retenues() {
	$ids = get_option( 'etr_categories', array() );
	return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
}

/**
 * Libellé « Parent › Enfant » de la catégorie la plus précise d'un article.
 *
 * @param int $post_id ID.
 * @return array{0:string,1:string} Libellé et clé de tri.
 */
function etr_categorie_article( $post_id ) {
	$taxonomie = etr_taxonomie_categories();
	$termes    = get_the_terms( $post_id, $taxonomie );
	if ( ! $termes || is_wp_error( $termes ) ) {
		$divers = __( 'Divers', 'espace-traiteur' );
		return array( $divers, "\xff" . $divers );
	}

	$meilleur   = null;
	$profondeur = -1;
	foreach ( $termes as $terme ) {
		$par_defaut = 'uncategorized' === $terme->slug || (int) get_option( 'default_product_cat' ) === (int) $terme->term_id;
		if ( $par_defaut && count( $termes ) > 1 ) {
			continue;
		}
		$p = count( get_ancestors( $terme->term_id, $taxonomie, 'taxonomy' ) );
		if ( $p > $profondeur ) {
			$meilleur   = $terme;
			$profondeur = $p;
		}
	}
	if ( ! $meilleur ) {
		$meilleur = reset( $termes );
	}

	$noms = array();
	foreach ( array_reverse( get_ancestors( $meilleur->term_id, $taxonomie, 'taxonomy' ) ) as $ancetre_id ) {
		$ancetre = get_term( $ancetre_id, $taxonomie );
		if ( $ancetre && ! is_wp_error( $ancetre ) ) {
			$noms[] = $ancetre->name;
		}
	}
	$noms[] = $meilleur->name;
	$libelle = implode( ' › ', $noms );
	return array( $libelle, remove_accents( strtolower( $libelle ) ) );
}

/**
 * L'article appartient-il aux catégories retenues ?
 *
 * @param int $post_id ID.
 * @return bool
 */
function etr_dans_categories_retenues( $post_id ) {
	$retenues = etr_categories_retenues();
	if ( ! $retenues ) {
		return true;
	}
	$taxonomie = etr_taxonomie_categories();
	$termes    = wp_get_post_terms( $post_id, $taxonomie, array( 'fields' => 'ids' ) );
	if ( is_wp_error( $termes ) ) {
		return false;
	}
	foreach ( $termes as $terme_id ) {
		$lignee = array_merge( array( (int) $terme_id ), array_map( 'intval', get_ancestors( $terme_id, $taxonomie, 'taxonomy' ) ) );
		if ( array_intersect( $lignee, $retenues ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Article normalisé à partir d'un produit WooCommerce.
 *
 * @param WC_Product $produit Produit.
 * @return array|null
 */
function etr_article_depuis_produit( $produit ) {
	if ( ! $produit || 'publish' !== $produit->get_status() || $produit->is_type( 'variation' ) ) {
		return null;
	}
	if ( 'hidden' === $produit->get_catalog_visibility() || 'yes' === $produit->get_meta( '_etr_masquer' ) ) {
		return null;
	}
	if ( ! etr_dans_categories_retenues( $produit->get_id() ) ) {
		return null;
	}

	list( $categorie, $ordre ) = etr_categorie_article( $produit->get_id() );
	// La description longue décrit l'article ; la courte est souvent un encadré commun (« Livraison et reprise non incluses »).
	$description = $produit->get_description() ? $produit->get_description() : $produit->get_short_description();

	return array(
		'id'          => $produit->get_id(),
		'titre'       => wp_strip_all_tags( $produit->get_name() ),
		'categorie'   => $categorie,
		'ordre'       => $ordre,
		'image'       => $produit->get_image( 'thumbnail', array( 'class' => 'etr-article__img' ), false ),
		'description' => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $description ) ), 25 ),
		'reference'   => (string) $produit->get_sku(),
		'unite'       => (string) $produit->get_meta( '_etr_unite' ),
		'url'         => $produit->get_permalink(),
		'prix_html'   => get_option( 'etr_afficher_prix' ) ? $produit->get_price_html() : '',
	);
}

/**
 * Article normalisé à partir du catalogue interne.
 *
 * @param WP_Post $post Article.
 * @return array|null
 */
function etr_article_depuis_post( $post ) {
	if ( ! $post || 'etr_article' !== $post->post_type || 'publish' !== $post->post_status ) {
		return null;
	}
	if ( ! etr_dans_categories_retenues( $post->ID ) ) {
		return null;
	}
	list( $categorie, $ordre ) = etr_categorie_article( $post->ID );
	return array(
		'id'          => $post->ID,
		'titre'       => get_the_title( $post ),
		'categorie'   => $categorie,
		'ordre'       => $ordre,
		'image'       => has_post_thumbnail( $post ) ? get_the_post_thumbnail( $post, 'thumbnail', array( 'class' => 'etr-article__img' ) ) : '',
		'description' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 ),
		'reference'   => (string) get_post_meta( $post->ID, '_etr_reference', true ),
		'unite'       => (string) get_post_meta( $post->ID, '_etr_unite', true ),
		'url'         => '',
		'prix_html'   => '',
	);
}

/**
 * Un article du catalogue, s'il est proposé aux traiteurs.
 *
 * @param int $article_id ID.
 * @return array|null
 */
function etr_get_article( $article_id ) {
	$article_id = absint( $article_id );
	if ( ! $article_id ) {
		return null;
	}
	if ( etr_source_woocommerce() ) {
		return etr_article_depuis_produit( wc_get_product( $article_id ) );
	}
	return etr_article_depuis_post( get_post( $article_id ) );
}

/**
 * Tous les articles proposés aux traiteurs, triés par catégorie puis nom.
 *
 * @return array[]
 */
function etr_catalogue() {
	$articles = array();

	if ( etr_source_woocommerce() ) {
		$produits = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		foreach ( $produits as $produit ) {
			$article = etr_article_depuis_produit( $produit );
			if ( $article ) {
				$articles[] = $article;
			}
		}
	} else {
		$posts = get_posts(
			array(
				'post_type'   => 'etr_article',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$article = etr_article_depuis_post( $post );
			if ( $article ) {
				$articles[] = $article;
			}
		}
	}

	usort(
		$articles,
		function ( $a, $b ) {
			return strcmp( $a['ordre'], $b['ordre'] ) ?: strcasecmp( remove_accents( $a['titre'] ), remove_accents( $b['titre'] ) );
		}
	);

	return apply_filters( 'etr_catalogue', $articles );
}

/**
 * Quantité totale possédée d'un article (avant réservations).
 *
 * Ordre de priorité :
 * 1. « Quantité disponible (espace traiteur) » saisie sur l'article ;
 * 2. avec WooCommerce : rupture de stock → 0, stock géré → quantité en stock ;
 * 3. sinon : illimité (null).
 *
 * @param int $article_id ID.
 * @return int|null
 */
function etr_stock_total( $article_id ) {
	$propre = get_post_meta( $article_id, '_etr_stock', true );
	if ( '' !== $propre && null !== $propre ) {
		return max( 0, (int) $propre );
	}
	if ( etr_source_woocommerce() ) {
		$produit = wc_get_product( $article_id );
		if ( $produit ) {
			if ( ! $produit->is_in_stock() ) {
				return 0;
			}
			if ( $produit->managing_stock() ) {
				return max( 0, (int) $produit->get_stock_quantity() );
			}
		}
	}
	return null;
}
