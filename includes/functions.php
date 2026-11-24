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
 * Prix unitaire indicatif d'un article (prix actuel du produit WooCommerce).
 *
 * @param int $article_id ID article.
 * @return float|null Prix, ou null si l'article n'a pas de prix.
 */
function etr_prix_unitaire( $article_id ) {
	$prix = null;
	if ( etr_source_woocommerce() ) {
		$produit = wc_get_product( absint( $article_id ) );
		if ( $produit && '' !== (string) $produit->get_price() ) {
			$prix = (float) wc_get_price_to_display( $produit );
		}
	}
	$prix = apply_filters( 'etr_prix_unitaire', $prix, (int) $article_id );
	return null === $prix || '' === $prix ? null : (float) $prix;
}

/**
 * Montant formaté (HTML sûr).
 *
 * @param float $montant Montant.
 * @return string
 */
function etr_format_prix( $montant ) {
	if ( function_exists( 'wc_price' ) ) {
		return wc_price( $montant );
	}
	return esc_html( number_format_i18n( $montant, 2 ) . "\xc2\xa0€" );
}

/**
 * Remise et frais de livraison saisis par l'équipe sur une demande.
 *
 * @param int $demande_id ID de la demande.
 * @return array{remise_pct:float,livraison:float|null}
 */
function etr_tarif( $demande_id ) {
	$remise    = get_post_meta( $demande_id, '_etr_remise_pct', true );
	$livraison = get_post_meta( $demande_id, '_etr_frais_livraison', true );
	return array(
		'remise_pct' => '' === $remise ? 0.0 : min( 100.0, max( 0.0, (float) $remise ) ),
		'livraison'  => '' === $livraison ? null : max( 0.0, (float) $livraison ),
	);
}

/**
 * Lit un montant ou un pourcentage saisi (« 10,5 », « 80 € »…).
 *
 * @param string $saisie Valeur saisie.
 * @return float|null Null si vide ou illisible.
 */
function etr_lire_nombre( $saisie ) {
	$saisie = str_replace( array( ',', ' ', "\xc2\xa0", '€', '%' ), array( '.', '', '', '', '' ), trim( (string) $saisie ) );
	if ( '' === $saisie || ! is_numeric( $saisie ) ) {
		return null;
	}
	return max( 0.0, round( (float) $saisie, 2 ) );
}

/**
 * Chiffrage indicatif d'une demande, ligne par ligne, avec remise et livraison.
 *
 * « total » est le prix final : sous-total − remise + livraison.
 *
 * @param int $demande_id ID de la demande.
 * @return array{lignes:array<int,array{unitaire:float|null,total:float|null}>,sous_total:float,remise_pct:float,remise:float,livraison:float|null,total:float,sans_prix:int}
 */
function etr_chiffrage( $demande_id ) {
	$chiffrage = array(
		'lignes'     => array(),
		'sous_total' => 0.0,
		'remise_pct' => 0.0,
		'remise'     => 0.0,
		'livraison'  => null,
		'total'      => 0.0,
		'sans_prix'  => 0,
	);
	foreach ( etr_get_lignes( $demande_id ) as $index => $ligne ) {
		$unitaire = etr_prix_unitaire( $ligne['article_id'] );
		$total    = null === $unitaire ? null : $unitaire * (int) $ligne['quantite'];
		$chiffrage['lignes'][ $index ] = array(
			'unitaire' => $unitaire,
			'total'    => $total,
		);
		if ( null === $total ) {
			++$chiffrage['sans_prix'];
		} else {
			$chiffrage['sous_total'] += $total;
		}
	}
	$tarif                   = etr_tarif( $demande_id );
	$chiffrage['remise_pct'] = $tarif['remise_pct'];
	$chiffrage['remise']     = round( $chiffrage['sous_total'] * $tarif['remise_pct'] / 100, 2 );
	$chiffrage['livraison']  = $tarif['livraison'];
	$chiffrage['total']      = $chiffrage['sous_total'] - $chiffrage['remise'] + (float) $tarif['livraison'];
	return $chiffrage;
}

/**
 * Lignes de détail du prix final (sous-total, remise, livraison), vides sans remise ni livraison.
 *
 * @param array $chiffrage Résultat de etr_chiffrage().
 * @return array<int,array{0:string,1:string}> Libellé, montant HTML.
 */
function etr_detail_chiffrage( $chiffrage ) {
	if ( ! $chiffrage['remise_pct'] && null === $chiffrage['livraison'] ) {
		return array();
	}
	$detail = array( array( __( 'Sous-total du matériel', 'espace-traiteur' ), etr_format_prix( $chiffrage['sous_total'] ) ) );
	if ( $chiffrage['remise_pct'] ) {
		/* translators: %s : pourcentage. */
		$detail[] = array( sprintf( __( 'Remise (%s %%)', 'espace-traiteur' ), number_format_i18n( $chiffrage['remise_pct'], fmod( $chiffrage['remise_pct'], 1 ) ? 2 : 0 ) ), '−&nbsp;' . etr_format_prix( $chiffrage['remise'] ) );
	}
	if ( null !== $chiffrage['livraison'] ) {
		$detail[] = array( __( 'Livraison et reprise', 'espace-traiteur' ), $chiffrage['livraison'] ? '+&nbsp;' . etr_format_prix( $chiffrage['livraison'] ) : esc_html__( 'Offertes', 'espace-traiteur' ) );
	}
	return $detail;
}

/**
 * Note sous le prix final.
 *
 * @param array $chiffrage Résultat de etr_chiffrage().
 * @return string Texte brut.
 */
function etr_note_chiffrage( $chiffrage ) {
	$note = null === $chiffrage['livraison']
		? __( 'Selon les tarifs actuels du site, hors livraison et reprise.', 'espace-traiteur' )
		: __( 'Selon les tarifs actuels du site.', 'espace-traiteur' );
	if ( $chiffrage['sans_prix'] ) {
		/* translators: %s : nombre d'articles. */
		$note .= ' ' . sprintf( _n( '%s article sans prix n’est pas compté.', '%s articles sans prix ne sont pas comptés.', $chiffrage['sans_prix'], 'espace-traiteur' ), number_format_i18n( $chiffrage['sans_prix'] ) );
	}
	return $note;
}

/**
 * Prix d'une ligne pour l'application (total et détail unitaire).
 *
 * @param array{unitaire:float|null,total:float|null} $prix Prix de la ligne (etr_chiffrage()).
 * @param int                                         $quantite Quantité.
 * @return string HTML.
 */
function etr_prix_ligne_html( $prix, $quantite ) {
	if ( null === $prix['total'] ) {
		return '<span class="etr-ligne__prix etr-ligne__prix--vide">' . esc_html__( 'Prix non renseigné', 'espace-traiteur' ) . '</span>';
	}
	return '<span class="etr-ligne__prix">' . etr_format_prix( $prix['total'] ) . '<small>' . esc_html( number_format_i18n( $quantite ) ) . ' × ' . etr_format_prix( $prix['unitaire'] ) . '</small></span>';
}

/**
 * Total indicatif d'une demande pour l'application.
 *
 * @param array $chiffrage Résultat de etr_chiffrage().
 * @return string HTML.
 */
function etr_total_indicatif_html( $chiffrage ) {
	$html = '<div class="etr-chiffrage">';
	$detail = etr_detail_chiffrage( $chiffrage );
	foreach ( $detail as $ligne ) {
		$html .= '<span class="etr-chiffrage__detail">' . esc_html( $ligne[0] ) . '</span><span class="etr-chiffrage__montant">' . $ligne[1] . '</span>';
	}
	if ( $detail ) {
		$html .= '<span class="etr-chiffrage__sep" aria-hidden="true"></span>';
	}
	$html .= '<span class="etr-chiffrage__libelle">' . esc_html__( 'Prix final indicatif', 'espace-traiteur' ) . '</span>';
	$html .= '<span class="etr-chiffrage__total">' . etr_format_prix( $chiffrage['total'] ) . '</span>';
	$html .= '<span class="etr-chiffrage__note">' . esc_html( etr_note_chiffrage( $chiffrage ) ) . '</span></div>';
	return $html;
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
	$html     .= '<th class="etr-num">' . esc_html__( 'Prix unitaire', 'espace-traiteur' ) . '</th>';
	$html     .= '<th class="etr-num">' . esc_html__( 'Prix de la ligne', 'espace-traiteur' ) . '</th>';
	$html     .= '</tr></thead><tbody>';
	$chiffrage = etr_chiffrage( $demande_id );
	foreach ( $lignes as $index => $ligne ) {
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
		$prix  = isset( $chiffrage['lignes'][ $index ] ) ? $chiffrage['lignes'][ $index ] : array( 'unitaire' => null, 'total' => null );
		$html .= '<td class="etr-num">' . ( null === $prix['unitaire'] ? '—' : etr_format_prix( $prix['unitaire'] ) ) . '</td>';
		$html .= '<td class="etr-num">' . ( null === $prix['total'] ? '—' : etr_format_prix( $prix['total'] ) ) . '</td>';
		$html .= '</tr>';
	}
	$colonnes = $avec_dispo ? 5 : 4;
	$html    .= '</tbody><tfoot>';
	foreach ( etr_detail_chiffrage( $chiffrage ) as $detail ) {
		$html .= '<tr><td colspan="' . $colonnes . '">' . esc_html( $detail[0] ) . '</td><td class="etr-num">' . $detail[1] . '</td></tr>';
	}
	$html .= '<tr><th colspan="' . $colonnes . '">' . esc_html__( 'Prix final indicatif', 'espace-traiteur' ) . '</th>';
	$html .= '<th class="etr-num"><strong>' . etr_format_prix( $chiffrage['total'] ) . '</strong></th></tr></tfoot></table>';
	$html .= '<p class="etr-muted">' . esc_html( etr_note_chiffrage( $chiffrage ) ) . '</p>';
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
 * Nom affiché d'un utilisateur, en texte brut (WordPress l'enregistre avec « &amp; »).
 *
 * @param WP_User|false|null $user Utilisateur.
 * @return string
 */
function etr_nom( $user ) {
	return $user instanceof WP_User ? wp_specialchars_decode( $user->display_name, ENT_QUOTES ) : '';
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

/**
 * Envoie un e-mail et, en cas d'échec, garde la raison donnée par WordPress
 * (par exemple « SMTP connect() failed ») pour l'afficher à la personne qui a lancé l'envoi.
 *
 * @param string|string[] $a       Destinataire(s).
 * @param string          $objet   Objet.
 * @param string          $corps   Message.
 * @param string[]        $entetes En-têtes.
 * @return bool
 */
function etr_envoyer_mail( $a, $objet, $corps, $entetes = array() ) {
	$cle = 'etr_erreur_mail_' . get_current_user_id();
	delete_transient( $cle );
	$noter = function ( $erreur ) use ( $cle ) {
		if ( is_wp_error( $erreur ) ) {
			set_transient( $cle, wp_strip_all_tags( $erreur->get_error_message() ), 10 * MINUTE_IN_SECONDS );
		}
	};
	add_action( 'wp_mail_failed', $noter );
	$envoye = wp_mail( $a, $objet, $corps, $entetes );
	remove_action( 'wp_mail_failed', $noter );
	return $envoye;
}

/**
 * Raison du dernier échec d'envoi lancé par l'utilisateur courant (lue une seule fois).
 *
 * @return string
 */
function etr_raison_echec_mail() {
	$cle    = 'etr_erreur_mail_' . get_current_user_id();
	$raison = (string) get_transient( $cle );
	delete_transient( $cle );
	return $raison;
}

/**
 * Adresse d'expédition utilisée par WordPress pour ses e-mails.
 *
 * @return string
 */
function etr_expediteur_mail() {
	$domaine = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
	if ( 0 === strpos( $domaine, 'www.' ) ) {
		$domaine = substr( $domaine, 4 );
	}
	return (string) apply_filters( 'wp_mail_from', 'wordpress@' . $domaine ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- filtre de WordPress.
}

/**
 * Nom du site pour les e-mails et l'application, sans le slogan :
 * « Maison Anamé | Location de Mobilier & Art de la table » devient « Maison Anamé ».
 *
 * @return string
 */
function etr_nom_site() {
	$nom   = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	$court = trim( (string) preg_split( '/\s+[|–—-]\s+/u', $nom, 2 )[0] );
	return (string) apply_filters( 'etr_nom_site', '' !== $court ? $court : $nom, $nom );
}

/**
 * Recharge un compte depuis la base de données (identifiant ou e-mail).
 *
 * Un cache d'objets persistant (LiteSpeed Cache, Redis, Memcached…) peut garder une
 * ancienne copie du compte : la clé d'un lien « choisir mon mot de passe » tout juste
 * envoyé, ou un mot de passe tout juste choisi, serait alors refusé.
 *
 * @param string $login Identifiant ou adresse e-mail.
 * @return int ID du compte (0 s'il n'existe pas).
 */
function etr_rafraichir_compte( $login ) {
	global $wpdb;
	$login = (string) $login;
	if ( '' === $login ) {
		return 0;
	}
	$colonne = is_email( $login ) ? 'user_email' : 'user_login';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lecture volontairement hors cache.
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE {$colonne} = %s LIMIT 1", $login ) );
	if ( $id ) {
		clean_user_cache( $id );
	}
	return $id;
}

/**
 * Vérifie la clé d'un lien « choisir mon mot de passe » à partir de la base de données.
 *
 * @param string $cle   Clé reçue dans le lien.
 * @param string $login Identifiant.
 * @return WP_User|WP_Error
 */
function etr_verifier_cle( $cle, $login ) {
	etr_rafraichir_compte( $login );
	return check_password_reset_key( $cle, $login );
}
