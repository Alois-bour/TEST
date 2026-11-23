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
 * Tableau HTML des articles demandés.
 *
 * @param int $demande_id ID de la demande.
 * @return string
 */
function etr_lignes_html( $demande_id ) {
	$lignes = etr_get_lignes( $demande_id );
	if ( empty( $lignes ) ) {
		return '<p>' . esc_html__( 'Aucun article du catalogue.', 'espace-traiteur' ) . '</p>';
	}

	$html  = '<table class="etr-table widefat striped"><thead><tr>';
	$html .= '<th>' . esc_html__( 'Article', 'espace-traiteur' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Référence', 'espace-traiteur' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Quantité', 'espace-traiteur' ) . '</th>';
	$html .= '</tr></thead><tbody>';
	foreach ( $lignes as $ligne ) {
		$reference = get_post_meta( $ligne['article_id'], '_etr_reference', true );
		$quantite  = number_format_i18n( $ligne['quantite'] ) . ( ! empty( $ligne['unite'] ) ? ' ' . $ligne['unite'] : '' );
		$html     .= '<tr><td>' . esc_html( $ligne['titre'] ) . '</td><td>' . esc_html( $reference ? $reference : '—' ) . '</td><td>' . esc_html( $quantite ) . '</td></tr>';
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
