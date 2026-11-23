<?php
/**
 * Fonctions utilitaires.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Statuts possibles d'une demande.
 *
 * @return array<string,string> slug => libellé.
 */
function etr_statuts() {
	return apply_filters(
		'etr_statuts',
		array(
			'en_attente' => __( 'En attente', 'espace-traiteur' ),
			'validee'    => __( 'Validée', 'espace-traiteur' ),
			'refusee'    => __( 'Refusée', 'espace-traiteur' ),
			'livree'     => __( 'Livrée', 'espace-traiteur' ),
			'cloturee'   => __( 'Clôturée', 'espace-traiteur' ),
			'annulee'    => __( 'Annulée', 'espace-traiteur' ),
		)
	);
}

/**
 * Statut courant d'une demande.
 *
 * @param int $demande_id ID de la demande.
 * @return string
 */
function etr_get_statut( $demande_id ) {
	$statut = get_post_meta( $demande_id, '_etr_statut', true );
	return array_key_exists( $statut, etr_statuts() ) ? $statut : 'en_attente';
}

/**
 * Badge HTML d'un statut.
 *
 * @param string $statut Slug du statut.
 * @return string
 */
function etr_statut_badge( $statut ) {
	$statuts = etr_statuts();
	$label   = isset( $statuts[ $statut ] ) ? $statuts[ $statut ] : $statut;
	return '<span class="etr-statut etr-statut--' . esc_attr( $statut ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * Champs du formulaire de demande.
 *
 * @return array<string,array>
 */
function etr_champs_demande() {
	return apply_filters(
		'etr_champs_demande',
		array(
			'evenement'      => array(
				'label'  => __( 'Nom de l’événement', 'espace-traiteur' ),
				'type'   => 'text',
				'requis' => true,
			),
			'date_evenement' => array(
				'label'  => __( 'Date de l’événement', 'espace-traiteur' ),
				'type'   => 'date',
				'requis' => true,
			),
			'nb_invites'     => array(
				'label' => __( 'Nombre d’invités', 'espace-traiteur' ),
				'type'  => 'number',
			),
			'lieu'           => array(
				'label'  => __( 'Lieu / adresse de livraison', 'espace-traiteur' ),
				'type'   => 'textarea',
				'requis' => true,
			),
			'date_livraison' => array(
				'label' => __( 'Livraison souhaitée le', 'espace-traiteur' ),
				'type'  => 'date',
			),
			'date_reprise'   => array(
				'label' => __( 'Reprise souhaitée le', 'espace-traiteur' ),
				'type'  => 'date',
			),
			'contact'        => array(
				'label' => __( 'Contact sur place (nom, téléphone)', 'espace-traiteur' ),
				'type'  => 'text',
			),
			'autres_besoins' => array(
				'label' => __( 'Autres besoins (hors catalogue)', 'espace-traiteur' ),
				'type'  => 'textarea',
			),
			'commentaire'    => array(
				'label' => __( 'Commentaire', 'espace-traiteur' ),
				'type'  => 'textarea',
			),
		)
	);
}

/**
 * Valeur d'un champ de demande, prête à afficher (HTML échappé).
 *
 * @param int    $demande_id ID de la demande.
 * @param string $cle        Clé du champ.
 * @param string $type       Type du champ.
 * @return string
 */
function etr_valeur_affichee( $demande_id, $cle, $type ) {
	$valeur = (string) get_post_meta( $demande_id, '_etr_' . $cle, true );
	if ( '' === $valeur ) {
		return '—';
	}
	if ( 'date' === $type ) {
		return esc_html( etr_format_date( $valeur ) );
	}
	if ( 'textarea' === $type ) {
		return nl2br( esc_html( $valeur ) );
	}
	return esc_html( $valeur );
}

/**
 * Formate une date AAAA-MM-JJ selon les réglages du site.
 *
 * @param string $date Date.
 * @return string
 */
function etr_format_date( $date ) {
	$timestamp = $date ? strtotime( $date ) : false;
	return $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '—';
}

/**
 * Lignes (articles demandés) d'une demande.
 *
 * @param int $demande_id ID de la demande.
 * @return array<int,array{article_id:int,quantite:int,titre:string,unite:string}>
 */
function etr_get_lignes( $demande_id ) {
	$lignes = get_post_meta( $demande_id, '_etr_lignes', true );
	return is_array( $lignes ) ? $lignes : array();
}

/**
 * Vérifie une date au format AAAA-MM-JJ.
 *
 * @param string $date Date.
 * @return bool
 */
function etr_date_valide( $date ) {
	$d = is_string( $date ) ? DateTime::createFromFormat( 'Y-m-d', $date ) : false;
	return $d && $d->format( 'Y-m-d' ) === $date;
}

/**
 * Statuts pour lesquels le matériel est bloqué (réservé).
 *
 * @return string[]
 */
function etr_statuts_reservant() {
	return apply_filters( 'etr_statuts_reservant', array( 'en_attente', 'validee', 'livree' ) );
}

/**
 * Période pendant laquelle le matériel est immobilisé : de la livraison
 * (ou du jour J) jusqu'à la reprise (ou au jour J).
 *
 * @param string $date_evenement Date de l'événement.
 * @param string $date_livraison Date de livraison.
 * @param string $date_reprise   Date de reprise.
 * @return array{0:string,1:string} Début et fin (AAAA-MM-JJ), vides si inconnues.
 */
function etr_periode( $date_evenement, $date_livraison = '', $date_reprise = '' ) {
	$debuts = array_filter( array( $date_evenement, $date_livraison ), 'etr_date_valide' );
	$fins   = array_filter( array( $date_evenement, $date_reprise ), 'etr_date_valide' );
	if ( ! $debuts || ! $fins ) {
		return array( '', '' );
	}
	$debut = min( $debuts );
	$fin   = max( $fins );
	return array( $debut, max( $debut, $fin ) );
}

/**
 * Période d'une demande enregistrée.
 *
 * @param int $demande_id ID.
 * @return array{0:string,1:string}
 */
function etr_periode_demande( $demande_id ) {
	return etr_periode(
		(string) get_post_meta( $demande_id, '_etr_date_evenement', true ),
		(string) get_post_meta( $demande_id, '_etr_date_livraison', true ),
		(string) get_post_meta( $demande_id, '_etr_date_reprise', true )
	);
}

/**
 * Quantités déjà réservées par article sur une période.
 *
 * Toute demande active dont la période chevauche celle demandée est comptée
 * (calcul prudent : on ne surréserve jamais).
 *
 * @param string $debut   Début (AAAA-MM-JJ).
 * @param string $fin     Fin (AAAA-MM-JJ).
 * @param int    $exclure ID d'une demande à ignorer.
 * @return array<int,int> article_id => quantité réservée.
 */
function etr_quantites_reservees( $debut, $fin, $exclure = 0 ) {
	$ids = get_posts(
		array(
			'post_type'        => 'etr_demande',
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'post__not_in'     => $exclure ? array( (int) $exclure ) : array(),
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_etr_statut',
					'value'   => etr_statuts_reservant(),
					'compare' => 'IN',
				),
			),
		)
	);

	$reservees = array();
	foreach ( $ids as $id ) {
		list( $d, $f ) = etr_periode_demande( $id );
		if ( ! $d || $d > $fin || $f < $debut ) {
			continue;
		}
		foreach ( etr_get_lignes( $id ) as $ligne ) {
			$article_id               = (int) $ligne['article_id'];
			$reservees[ $article_id ] = ( isset( $reservees[ $article_id ] ) ? $reservees[ $article_id ] : 0 ) + (int) $ligne['quantite'];
		}
	}
	return $reservees;
}

/**
 * Quantité encore disponible d'un article.
 *
 * @param int        $article_id ID article.
 * @param array|null $reservees  Résultat de etr_quantites_reservees(), ou null si la période est inconnue.
 * @return int|null Quantité disponible, ou null si l'article est illimité.
 */
function etr_disponibilite( $article_id, $reservees = null ) {
	$stock = etr_stock_total( $article_id );
	if ( null === $stock ) {
		return null;
	}
	$deja = is_array( $reservees ) && isset( $reservees[ $article_id ] ) ? (int) $reservees[ $article_id ] : 0;
	return max( 0, $stock - $deja );
}

/**
 * Texte de disponibilité d'un article.
 *
 * @param int      $article_id ID article.
 * @param int|null $dispo      Quantité disponible (null = illimité).
 * @return string
 */
function etr_texte_disponibilite( $article_id, $dispo ) {
	if ( null === $dispo ) {
		return '';
	}
	if ( 0 === $dispo ) {
		return 0 === etr_stock_total( $article_id )
			? __( 'Indisponible', 'espace-traiteur' )
			: __( 'Indisponible sur ces dates', 'espace-traiteur' );
	}
	$unite = get_post_meta( $article_id, '_etr_unite', true );
	/* translators: %s : quantité disponible. */
	return sprintf( __( 'Disponible : %s', 'espace-traiteur' ), number_format_i18n( $dispo ) . ( $unite ? ' ' . $unite : '' ) );
}

/**
 * Tableau HTML des articles demandés.
 *
 * @param int  $demande_id ID de la demande.
 * @param bool $avec_dispo Ajoute la disponibilité sur la période (hors cette demande).
 * @return string
 */
function etr_lignes_html( $demande_id, $avec_dispo = false ) {
	$lignes = etr_get_lignes( $demande_id );
	if ( empty( $lignes ) ) {
		return '<p>' . esc_html__( 'Aucun article du catalogue.', 'espace-traiteur' ) . '</p>';
	}

	$reservees = null;
	if ( $avec_dispo ) {
		list( $debut, $fin ) = etr_periode_demande( $demande_id );
		$reservees           = $debut ? etr_quantites_reservees( $debut, $fin, $demande_id ) : array();
	}

	$html  = '<table class="etr-table widefat striped"><thead><tr>';
	$html .= '<th>' . esc_html__( 'Article', 'espace-traiteur' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Référence', 'espace-traiteur' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Quantité', 'espace-traiteur' ) . '</th>';
	if ( $avec_dispo ) {
		$html .= '<th>' . esc_html__( 'Disponible sur la période (hors cette demande)', 'espace-traiteur' ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $lignes as $ligne ) {
		$article   = etr_get_article( $ligne['article_id'] );
		$reference = $article ? $article['reference'] : '';
		$quantite  = number_format_i18n( $ligne['quantite'] ) . ( ! empty( $ligne['unite'] ) ? ' ' . $ligne['unite'] : '' );
		$html     .= '<tr><td>' . esc_html( $ligne['titre'] ) . '</td><td>' . esc_html( $reference ? $reference : '—' ) . '</td><td>' . esc_html( $quantite ) . '</td>';
		if ( $avec_dispo ) {
			$dispo = etr_disponibilite( $ligne['article_id'], $reservees );
			if ( null === $dispo ) {
				$html .= '<td>' . esc_html__( 'Illimité', 'espace-traiteur' ) . '</td>';
			} elseif ( $dispo < (int) $ligne['quantite'] ) {
				/* translators: %s : quantité disponible. */
				$html .= '<td class="etr-alerte">' . esc_html( sprintf( __( '%s — insuffisant', 'espace-traiteur' ), number_format_i18n( $dispo ) ) ) . '</td>';
			} else {
				$html .= '<td>' . esc_html( number_format_i18n( $dispo ) ) . '</td>';
			}
		}
		$html .= '</tr>';
	}
	$html .= '</tbody></table>';
	return $html;
}

/**
 * URL de la page « Espace traiteur ».
 *
 * @param array $args Paramètres à ajouter à l'URL.
 * @return string
 */
function etr_url( $args = array() ) {
	$page_id = (int) get_option( 'etr_page_id' );
	$url     = $page_id ? get_permalink( $page_id ) : '';
	if ( ! $url ) {
		$url = home_url( '/' );
	}
	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * L'utilisateur a-t-il accès à l'espace traiteur ?
 *
 * @param int $user_id ID utilisateur (courant par défaut).
 * @return bool
 */
function etr_est_traiteur( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return $user_id && user_can( $user_id, 'acceder_espace_traiteur' );
}

/**
 * L'utilisateur gère-t-il les demandes et le catalogue ?
 *
 * @param int $user_id ID utilisateur (courant par défaut).
 * @return bool
 */
function etr_est_gestionnaire( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return $user_id && user_can( $user_id, 'gerer_espace_traiteur' );
}
