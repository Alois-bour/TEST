<?php
/**
 * Devis, acompte, casse et relances (fonctions activables) :
 * - devis PDF envoyé par le bureau et accepté en ligne par le traiteur (« devis ») ;
 * - acompte et caution demandés, reçus, rendus (« acompte ») ;
 * - relevé de la casse et des manquants après la reprise (« casse ») ;
 * - relances automatiques (« relances »).
 *
 * Aucun paiement en ligne : les traiteurs n'ont pas accès au panier WooCommerce.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions « devis, acompte et casse ».
 */
class ETR_Devis {

	const META_DEVIS   = '_etr_devis';
	const META_ACOMPTE = '_etr_acompte';
	const META_RELEVE  = '_etr_releve_casse';
	const META_PRIX    = '_etr_prix_remplacement';
	const OPTION_JOUR  = 'etr_relances_jour';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'etr_gestion_fiche_cote', array( __CLASS__, 'panneau_devis' ), 20 );
		add_action( 'etr_gestion_fiche_cote', array( __CLASS__, 'panneau_acompte' ), 30 );
		add_action( 'etr_gestion_fiche', array( __CLASS__, 'panneau_casse' ), 5 );
		add_action( 'etr_gestion_action', array( __CLASS__, 'actions_bureau' ) );
		add_action( 'etr_traiteur_demande_fin', array( __CLASS__, 'encart_traiteur' ), 5 );
		add_action( 'etr_traiteur_action', array( __CLASS__, 'action_traiteur' ) );
		add_action( 'template_redirect', array( __CLASS__, 'telechargement' ), 5 );
		// Demande ou tarif modifiés : le devis envoyé n'est plus à jour.
		add_action( 'etr_demande_modifiee', array( __CLASS__, 'devis_perime' ) );
		add_action( 'etr_tarif_change', array( __CLASS__, 'devis_perime' ) );

		// Prix de remplacement sur les produits WooCommerce.
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'champ_produit' ), 20 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'enregistrer_produit' ) );

		// Relances : une fois par jour (passage horaire des rappels).
		add_action( 'etr_rappels_horaires', array( __CLASS__, 'relances' ), 20 );
	}

	/**
	 * Montant en texte (PDF, e-mails).
	 *
	 * @param float $montant Montant.
	 * @return string
	 */
	public static function prix( $montant ) {
		return number_format_i18n( (float) $montant, 2 ) . ' €';
	}

	/**
	 * Lecture d'un montant saisi (« 1 200,50 »).
	 *
	 * @param string $saisie Saisie.
	 * @return float|null
	 */
	private static function montant( $saisie ) {
		$saisie = trim( (string) $saisie );
		if ( '' === $saisie ) {
			return null;
		}
		$valeur = etr_lire_nombre( $saisie );
		return null === $valeur ? null : max( 0, round( (float) $valeur, 2 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Devis                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Devis d'une demande.
	 *
	 * @param int $id Demande.
	 * @return array { envoye, total, valable, accepte: { le, nom, ip } } ou vide.
	 */
	public static function devis( $id ) {
		$devis = get_post_meta( $id, self::META_DEVIS, true );
		return is_array( $devis ) ? $devis : array();
	}

	/**
	 * Le devis peut-il encore être accepté ?
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	private static function acceptable( $id ) {
		$devis = self::devis( $id );
		return $devis && empty( $devis['accepte'] ) && current_time( 'Y-m-d' ) <= $devis['valable'] && in_array( etr_get_statut( $id ), array( 'en_attente', 'validee' ), true );
	}

	/**
	 * Panneau « Devis » (bureau).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_devis( $id ) {
		if ( ! ETR_Modules::actif( 'devis' ) || ! etr_get_lignes( $id ) ) {
			return;
		}
		$devis = self::devis( $id );
		echo '<section class="etr-panneau etr-devis"><h2 class="etr-panneau__titre">' . esc_html__( 'Devis', 'espace-traiteur' ) . '</h2>';
		if ( $devis ) {
			/* translators: 1 : date, 2 : montant. */
			echo '<p>' . esc_html( sprintf( __( 'Envoyé le %1$s · %2$s', 'espace-traiteur' ), wp_date( 'j F Y', (int) $devis['envoye'] ), self::prix( $devis['total'] ) ) ) . '</p>';
			if ( ! empty( $devis['accepte'] ) ) {
				/* translators: 1 : nom, 2 : date et heure. */
				echo '<p class="etr-pastille-etat etr-pastille-etat--ok">' . esc_html( sprintf( __( 'Accepté par %1$s le %2$s', 'espace-traiteur' ), $devis['accepte']['nom'], wp_date( 'j F Y à H:i', (int) $devis['accepte']['le'] ) ) ) . '</p>';
				if ( ! empty( $devis['change_apres'] ) ) {
					echo '<p class="etr-notice etr-notice--erreur">' . esc_html__( 'Le tarif a changé après l’acceptation : le devis accepté reste celui envoyé. Renvoyez un devis si le nouveau montant doit être accepté.', 'espace-traiteur' ) . '</p>';
				}
			} elseif ( current_time( 'Y-m-d' ) > $devis['valable'] ) {
				echo '<p class="etr-pastille-etat">' . esc_html__( 'Expiré sans réponse', 'espace-traiteur' ) . '</p>';
			} else {
				/* translators: %s : date. */
				echo '<p class="etr-pastille-etat etr-pastille-etat--cours">' . esc_html( sprintf( __( 'En attente d’acceptation (valable jusqu’au %s)', 'espace-traiteur' ), etr_format_date( $devis['valable'] ) ) ) . '</p>';
			}
			echo '<p><a class="etr-lien" href="' . esc_url( self::url_pdf( $id, 'devis' ) ) . '" target="_blank">' . esc_html__( 'Voir le devis (PDF)', 'espace-traiteur' ) . '</a></p>';
		} else {
			echo '<p class="etr-muted">' . esc_html__( 'Prix du catalogue, remise et livraison de cette fiche. Le traiteur l’accepte dans son espace.', 'espace-traiteur' ) . '</p>';
		}
		if ( in_array( etr_get_statut( $id ), array( 'en_attente', 'validee' ), true ) ) {
			$sans_prix = etr_chiffrage( $id )['sans_prix'];
			if ( $sans_prix ) {
				/* translators: %s : nombre d'articles. */
				echo '<p class="etr-muted">' . esc_html( sprintf( _n( '%s article sans prix : il apparaîtra « à chiffrer » sur le devis.', '%s articles sans prix : ils apparaîtront « à chiffrer » sur le devis.', $sans_prix, 'espace-traiteur' ), number_format_i18n( $sans_prix ) ) ) . '</p>';
			}
			echo '<form method="post" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) ) . '"' . ( $devis ? ' data-confirmer="' . esc_attr__( 'Renvoyer le devis ? Le traiteur devra accepter la nouvelle version.', 'espace-traiteur' ) . '"' : '' ) . '>';
			wp_nonce_field( 'etr_devis_' . $id, 'etr_nonce' );
			echo '<input type="hidden" name="etr_gestion_action" value="devis"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
			echo '<button type="submit" class="etr-btn etr-btn--discret">' . esc_html( $devis ? __( 'Renvoyer le devis', 'espace-traiteur' ) : __( 'Envoyer le devis', 'espace-traiteur' ) ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * Envoie (ou renvoie) le devis.
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	public static function envoyer_devis( $id ) {
		$chiffrage = etr_chiffrage( $id );
		// Copie figée du devis : le PDF et l'acceptation portent sur ce qui a été envoyé,
		// même si les prix du catalogue changent ensuite.
		$lignes = array();
		foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
			$lignes[] = array(
				'titre'    => $ligne['titre'],
				'quantite' => (int) $ligne['quantite'],
				'unitaire' => $chiffrage['lignes'][ $index ]['unitaire'],
				'total'    => $chiffrage['lignes'][ $index ]['total'],
			);
		}
		update_post_meta(
			$id,
			self::META_DEVIS,
			array(
				'envoye'    => time(),
				'total'     => $chiffrage['total'],
				'valable'   => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS * (int) get_option( 'etr_devis_validite', 30 ) ),
				'accepte'   => array(),
				'lignes'    => $lignes,
				'chiffrage' => array_intersect_key( $chiffrage, array_flip( array( 'sous_total', 'remise_pct', 'remise', 'livraison', 'total' ) ) ),
			)
		);
		delete_post_meta( $id, '_etr_devis_relance' );
		ETR_Historique::ajouter( $id, 'devis', sprintf( /* translators: %s : montant. */ __( 'Devis envoyé au traiteur (%s)', 'espace-traiteur' ), self::prix( $chiffrage['total'] ) ) );
		$user = get_userdata( (int) get_post_field( 'post_author', $id ) );
		ETR_Push::notifier_traiteur( $user ? $user->ID : 0, __( 'Votre devis est prêt', 'espace-traiteur' ), get_the_title( $id ) . ' · ' . self::prix( $chiffrage['total'] ), $id );
		/* translators: 1 : montant, 2 : date. */
		$texte = sprintf( __( 'Votre devis pour « %1$s » est en pièce jointe (%2$s). Vous pouvez l’accepter en ligne dans votre espace :', 'espace-traiteur' ), get_the_title( $id ), self::prix( $chiffrage['total'] ) );
		return self::envoyer_pdf( $id, 'devis', __( 'Votre devis', 'espace-traiteur' ), $texte );
	}

	/**
	 * La demande ou son tarif ont changé : un devis pas encore accepté est retiré (à renvoyer) ;
	 * un devis déjà accepté est gardé tel quel, avec un avertissement au bureau.
	 *
	 * @param int $id Demande.
	 */
	public static function devis_perime( $id ) {
		$devis = self::devis( $id );
		if ( ! $devis ) {
			return;
		}
		if ( empty( $devis['accepte'] ) ) {
			delete_post_meta( $id, self::META_DEVIS );
			ETR_Historique::ajouter( $id, 'devis', __( 'Devis retiré : la demande ou son tarif ont changé, il est à renvoyer', 'espace-traiteur' ) );
			return;
		}
		$devis['change_apres'] = time();
		update_post_meta( $id, self::META_DEVIS, $devis );
	}

	/**
	 * Le traiteur accepte le devis.
	 *
	 * @param int    $id  Demande.
	 * @param string $nom Nom de la personne qui accepte.
	 */
	private static function accepter( $id, $nom ) {
		$devis            = self::devis( $id );
		$devis['accepte'] = array(
			'le'  => time(),
			'nom' => $nom,
			'ip'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'cgv' => (string) get_option( 'etr_cgv_url' ),
		);
		update_post_meta( $id, self::META_DEVIS, $devis );
		/* translators: 1 : nom, 2 : montant. */
		$texte = sprintf( __( 'Devis accepté en ligne par %1$s (%2$s), conditions générales acceptées', 'espace-traiteur' ), $nom, self::prix( $devis['total'] ) );
		ETR_Historique::ajouter( $id, 'devis', $texte );
		/* translators: %s : traiteur. */
		ETR_Push::notifier( 'bureau', sprintf( __( 'Devis accepté : %s', 'espace-traiteur' ), etr_nom( wp_get_current_user() ) ), get_the_title( $id ) . ' · ' . self::prix( $devis['total'] ), $id, '', 'devis' );
		$equipe = apply_filters( 'etr_email_equipe', is_email( get_option( 'etr_email_equipe' ) ) ? get_option( 'etr_email_equipe' ) : get_option( 'admin_email' ) );
		/* translators: 1 : site, 2 : numéro. */
		etr_envoyer_mail( $equipe, sprintf( __( '[%1$s] Devis accepté — demande #%2$d', 'espace-traiteur' ), etr_nom_site(), $id ), $texte . ".\n\n" . __( 'Voir la demande :', 'espace-traiteur' ) . ' ' . ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) . "\n" );
	}

	/* ------------------------------------------------------------------ */
	/* Acompte et caution                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Acompte et caution.
	 *
	 * @param int $id Demande.
	 * @return array { acompte, acompte_recu, caution, caution_recue, caution_rendue } (montants float|null, dates AAAA-MM-JJ ou '').
	 */
	public static function acompte( $id ) {
		$a = get_post_meta( $id, self::META_ACOMPTE, true );
		return wp_parse_args(
			is_array( $a ) ? $a : array(),
			array(
				'acompte'        => null,
				'acompte_recu'   => '',
				'caution'        => null,
				'caution_recue'  => '',
				'caution_rendue' => '',
			)
		);
	}

	/**
	 * Lignes lisibles (traiteur, devis).
	 *
	 * @param int $id Demande.
	 * @return string[]
	 */
	public static function textes_acompte( $id ) {
		$a      = self::acompte( $id );
		$textes = array();
		if ( null !== $a['acompte'] ) {
			/* translators: 1 : montant, 2 : état. */
			$textes[] = sprintf( __( 'Acompte : %1$s — %2$s', 'espace-traiteur' ), self::prix( $a['acompte'] ), $a['acompte_recu'] ? sprintf( /* translators: %s : date. */ __( 'reçu le %s', 'espace-traiteur' ), etr_format_date( $a['acompte_recu'] ) ) : __( 'à régler', 'espace-traiteur' ) );
		}
		if ( null !== $a['caution'] ) {
			if ( $a['caution_rendue'] ) {
				/* translators: %s : date. */
				$etat = sprintf( __( 'rendue le %s', 'espace-traiteur' ), etr_format_date( $a['caution_rendue'] ) );
			} elseif ( $a['caution_recue'] ) {
				/* translators: %s : date. */
				$etat = sprintf( __( 'reçue le %s', 'espace-traiteur' ), etr_format_date( $a['caution_recue'] ) );
			} else {
				$etat = __( 'à remettre', 'espace-traiteur' );
			}
			/* translators: 1 : montant, 2 : état. */
			$textes[] = sprintf( __( 'Caution : %1$s — %2$s', 'espace-traiteur' ), self::prix( $a['caution'] ), $etat );
		}
		return $textes;
	}

	/**
	 * Panneau « Acompte et caution » (bureau).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_acompte( $id ) {
		if ( ! ETR_Modules::actif( 'acompte' ) ) {
			return;
		}
		$a      = self::acompte( $id );
		$champs = array(
			'acompte'        => array( __( 'Acompte demandé (€)', 'espace-traiteur' ), 'text' ),
			'acompte_recu'   => array( __( 'Acompte reçu le', 'espace-traiteur' ), 'date' ),
			'caution'        => array( __( 'Caution demandée (€)', 'espace-traiteur' ), 'text' ),
			'caution_recue'  => array( __( 'Caution reçue le', 'espace-traiteur' ), 'date' ),
			'caution_rendue' => array( __( 'Caution rendue le', 'espace-traiteur' ), 'date' ),
		);
		echo '<form class="etr-panneau etr-acompte" method="post" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) ) . '"><h2 class="etr-panneau__titre">' . esc_html__( 'Acompte et caution', 'espace-traiteur' ) . '</h2>';
		wp_nonce_field( 'etr_acompte_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="acompte"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
		foreach ( $champs as $cle => $champ ) {
			$valeur = $a[ $cle ];
			if ( 'text' === $champ[1] && null !== $valeur ) {
				$valeur = number_format( (float) $valeur, 2, ',', '' ); // Sans séparateur de milliers : relu tel quel.
			}
			echo '<p class="etr-champ"><label for="etr-acompte-' . esc_attr( $cle ) . '">' . esc_html( $champ[0] ) . '</label><input type="' . esc_attr( $champ[1] ) . '" id="etr-acompte-' . esc_attr( $cle ) . '" name="etr_acompte[' . esc_attr( $cle ) . ']" value="' . esc_attr( (string) $valeur ) . '"' . ( 'text' === $champ[1] ? ' inputmode="decimal"' : '' ) . '></p>';
		}
		echo '<button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Enregistrer', 'espace-traiteur' ) . '</button></form>';
	}

	/* ------------------------------------------------------------------ */
	/* Casse et manquants                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Prix de remplacement d'un article (casse), ou null.
	 *
	 * @param int $article_id Article.
	 * @return float|null
	 */
	public static function prix_remplacement( $article_id ) {
		$prix = get_post_meta( $article_id, self::META_PRIX, true );
		return '' === $prix || null === $prix ? null : (float) $prix;
	}

	/**
	 * Lignes facturables après la reprise.
	 *
	 * @param int $id Demande.
	 * @return array { lignes: [ { titre, manquants, casses, unitaire, total } ], total, a_chiffrer }
	 */
	public static function releve( $id ) {
		$reprise = ETR_Operations::reprise( $id );
		$releve  = array(
			'lignes'     => array(),
			'total'      => 0.0,
			'a_chiffrer' => 0,
		);
		if ( ! $reprise || empty( $reprise['lignes'] ) ) {
			return $releve;
		}
		foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
			if ( ! isset( $reprise['lignes'][ $index ] ) ) {
				continue;
			}
			$manquants = max( 0, (int) $ligne['quantite'] - (int) $reprise['lignes'][ $index ]['rendu'] );
			$casses    = (int) $reprise['lignes'][ $index ]['casse'];
			if ( ! $manquants && ! $casses ) {
				continue;
			}
			$unitaire = self::prix_remplacement( (int) $ligne['article_id'] );
			$total    = null === $unitaire ? null : $unitaire * ( $manquants + $casses );
			$releve['lignes'][] = array(
				'titre'     => $ligne['titre'],
				'manquants' => $manquants,
				'casses'    => $casses,
				'unitaire'  => $unitaire,
				'total'     => $total,
			);
			if ( null === $total ) {
				++$releve['a_chiffrer'];
			} else {
				$releve['total'] += $total;
			}
		}
		return $releve;
	}

	/**
	 * Panneau « Casse et manquants à facturer » (bureau, après une reprise avec écarts).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_casse( $id ) {
		if ( ! ETR_Modules::actif( 'casse' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		$releve = self::releve( $id );
		if ( ! $releve['lignes'] ) {
			return;
		}
		$envoi = get_post_meta( $id, self::META_RELEVE, true );
		echo '<section class="etr-panneau etr-casse"><h2 class="etr-panneau__titre">' . esc_html__( 'Casse et manquants à facturer', 'espace-traiteur' ) . '</h2>';
		echo '<div class="etr-table-cadre"><table class="etr-table"><thead><tr><th scope="col">' . esc_html__( 'Article', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Manquants', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Cassés', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Montant', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
		foreach ( $releve['lignes'] as $ligne ) {
			echo '<tr><td>' . esc_html( $ligne['titre'] ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $ligne['manquants'] ) ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $ligne['casses'] ) ) . '</td><td class="etr-table__nombre">' . ( null === $ligne['total'] ? '<span class="etr-muted">' . esc_html__( 'à chiffrer', 'espace-traiteur' ) . '</span>' : esc_html( self::prix( $ligne['total'] ) ) ) . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><th scope="row" colspan="3">' . esc_html__( 'Total', 'espace-traiteur' ) . '</th><td class="etr-table__nombre"><strong>' . esc_html( self::prix( $releve['total'] ) ) . '</strong></td></tr></tfoot></table></div>';
		if ( $releve['a_chiffrer'] ) {
			echo '<p class="etr-muted">' . esc_html__( 'Lignes « à chiffrer » : renseignez le « Prix de remplacement » du produit (WooCommerce › Inventaire).', 'espace-traiteur' ) . '</p>';
		}
		if ( is_array( $envoi ) && ! empty( $envoi['envoye'] ) ) {
			/* translators: 1 : date, 2 : montant. */
			echo '<p class="etr-pastille-etat etr-pastille-etat--ok">' . esc_html( sprintf( __( 'Relevé envoyé le %1$s (%2$s)', 'espace-traiteur' ), wp_date( 'j F Y', (int) $envoi['envoye'] ), self::prix( $envoi['total'] ) ) ) . '</p>';
		}
		echo '<p><a class="etr-lien" href="' . esc_url( self::url_pdf( $id, 'releve' ) ) . '" target="_blank">' . esc_html__( 'Voir le relevé (PDF)', 'espace-traiteur' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) ) . '" data-confirmer="' . esc_attr__( 'Envoyer le relevé au traiteur par e-mail ?', 'espace-traiteur' ) . '">';
		wp_nonce_field( 'etr_releve_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="releve"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
		echo '<button type="submit" class="etr-btn etr-btn--discret">' . esc_html( is_array( $envoi ) && ! empty( $envoi['envoye'] ) ? __( 'Renvoyer le relevé', 'espace-traiteur' ) : __( 'Envoyer le relevé au traiteur', 'espace-traiteur' ) ) . '</button></form></section>';
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Actions du bureau dans l'application.
	 *
	 * @param string $action Action.
	 */
	public static function actions_bureau( $action ) {
		$modules = array(
			'devis'   => 'devis',
			'acompte' => 'acompte',
			'releve'  => 'casse',
		);
		if ( ! isset( $modules[ $action ] ) || ! ETR_Modules::actif( $modules[ $action ] ) ) {
			return;
		}
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		check_admin_referer( 'etr_' . $action . '_' . $id, 'etr_nonce' );
		if ( ! $id || ! ETR_Gestion::demande_visible( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$msg = 'fait';
		if ( 'devis' === $action && in_array( etr_get_statut( $id ), array( 'en_attente', 'validee' ), true ) ) {
			$msg = self::envoyer_devis( $id ) ? 'devis_envoye' : 'mail_echec';
		} elseif ( 'releve' === $action && self::releve( $id )['lignes'] ) {
			$releve = self::releve( $id );
			/* translators: 1 : événement, 2 : montant. */
			$texte = sprintf( __( 'Après la reprise du matériel de « %1$s », voici le relevé de la casse et des manquants (%2$s), en pièce jointe. Il reste disponible dans votre espace :', 'espace-traiteur' ), get_the_title( $id ), self::prix( $releve['total'] ) );
			if ( self::envoyer_pdf( $id, 'releve', __( 'Relevé de casse et de manquants', 'espace-traiteur' ), $texte ) ) {
				update_post_meta( $id, self::META_RELEVE, array( 'envoye' => time(), 'total' => $releve['total'] ) );
				ETR_Historique::ajouter( $id, 'casse', sprintf( /* translators: %s : montant. */ __( 'Relevé de casse et de manquants envoyé au traiteur (%s)', 'espace-traiteur' ), self::prix( $releve['total'] ) ) );
				$msg = 'releve_envoye';
			} else {
				$msg = 'mail_echec';
			}
		} elseif ( 'acompte' === $action ) {
			$saisie = isset( $_POST['etr_acompte'] ) && is_array( $_POST['etr_acompte'] ) ? wp_unslash( $_POST['etr_acompte'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé champ par champ.
			$propre = array();
			foreach ( array( 'acompte', 'caution' ) as $cle ) {
				$propre[ $cle ] = isset( $saisie[ $cle ] ) ? self::montant( sanitize_text_field( $saisie[ $cle ] ) ) : null;
			}
			foreach ( array( 'acompte_recu', 'caution_recue', 'caution_rendue' ) as $cle ) {
				$date           = isset( $saisie[ $cle ] ) ? sanitize_text_field( $saisie[ $cle ] ) : '';
				$propre[ $cle ] = etr_date_valide( $date ) ? $date : '';
			}
			// Un acompte ou une caution reçus en ligne pendant que la fiche était ouverte ne sont pas effacés.
			$base   = self::acompte( $id );
			$stripe = class_exists( 'ETR_Stripe' ) ? ETR_Stripe::paiements( $id ) : array();
			if ( '' === $propre['acompte_recu'] && isset( $stripe['acompte']['statut'] ) && 'paye' === $stripe['acompte']['statut'] ) {
				$propre['acompte_recu'] = $base['acompte_recu'];
			}
			if ( '' === $propre['caution_recue'] && isset( $stripe['caution']['statut'] ) && in_array( $stripe['caution']['statut'], array( 'bloquee', 'enregistree', 'debitee' ), true ) ) {
				$propre['caution_recue'] = $base['caution_recue'];
			}
			$avant = self::textes_acompte( $id );
			update_post_meta( $id, self::META_ACOMPTE, $propre );
			$apres = self::textes_acompte( $id );
			if ( $apres !== $avant ) {
				ETR_Historique::ajouter( $id, 'acompte', $apres ? implode( ' · ', $apres ) : __( 'Acompte et caution retirés', 'espace-traiteur' ) );
			}
		}
		wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => $msg ) ) );
		exit;
	}

	/**
	 * Encart de la demande côté traiteur : devis, acompte, relevé.
	 *
	 * @param int $id Demande.
	 */
	public static function encart_traiteur( $id ) {
		if ( ! etr_peut_voir_demande( $id ) ) {
			return;
		}
		$blocs = array();
		$devis = ETR_Modules::actif( 'devis' ) ? self::devis( $id ) : array();
		if ( $devis ) {
			$html = '<h2 class="etr-panneau__titre">' . esc_html__( 'Devis', 'espace-traiteur' ) . '</h2>';
			/* translators: 1 : montant, 2 : date. */
			$html .= '<p>' . esc_html( sprintf( __( 'Montant : %1$s · envoyé le %2$s', 'espace-traiteur' ), self::prix( $devis['total'] ), wp_date( 'j F Y', (int) $devis['envoye'] ) ) ) . ' — <a class="etr-lien" href="' . esc_url( self::url_pdf( $id, 'devis' ) ) . '">' . esc_html__( 'Télécharger (PDF)', 'espace-traiteur' ) . '</a></p>';
			if ( ! empty( $devis['accepte'] ) ) {
				/* translators: 1 : nom, 2 : date. */
				$html .= '<p class="etr-notice etr-notice--succes">' . esc_html( sprintf( __( 'Devis accepté par %1$s le %2$s. Merci !', 'espace-traiteur' ), $devis['accepte']['nom'], wp_date( 'j F Y à H:i', (int) $devis['accepte']['le'] ) ) ) . '</p>';
			} elseif ( self::acceptable( $id ) ) {
				$cgv   = (string) get_option( 'etr_cgv_url' );
				$html .= '<form method="post" class="etr-devis-accepter" action="' . esc_url( etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) ) . '">' . wp_nonce_field( 'etr_accepter_devis_' . $id, 'etr_nonce', true, false );
				$html .= '<input type="hidden" name="etr_action" value="accepter_devis"><input type="hidden" name="demande_id" value="' . (int) $id . '"><input type="hidden" name="devis_envoye" value="' . (int) $devis['envoye'] . '">';
				$html .= '<p class="etr-champ"><label for="etr-devis-nom">' . esc_html__( 'Votre nom', 'espace-traiteur' ) . '</label><input type="text" id="etr-devis-nom" name="etr_nom" required maxlength="100" value="' . esc_attr( etr_nom( wp_get_current_user() ) ) . '" autocomplete="name"></p>';
				$html .= '<p class="etr-case"><label><input type="checkbox" name="etr_cgv" value="1" required> ' . ( $cgv ? sprintf( /* translators: %s : lien. */ esc_html__( 'J’ai lu et j’accepte les %s.', 'espace-traiteur' ), '<a href="' . esc_url( $cgv ) . '" target="_blank" rel="noopener">' . esc_html__( 'conditions générales', 'espace-traiteur' ) . '</a>' ) : esc_html__( 'J’accepte ce devis et les conditions de location.', 'espace-traiteur' ) ) . '</label></p>';
				/* translators: %s : date. */
				$html .= '<button type="submit" class="etr-btn">' . esc_html__( 'Accepter le devis', 'espace-traiteur' ) . '</button> <span class="etr-muted">' . esc_html( sprintf( __( 'Valable jusqu’au %s.', 'espace-traiteur' ), etr_format_date( $devis['valable'] ) ) ) . '</span></form>';
			}
			$blocs[] = $html;
		}
		$acompte = ETR_Modules::actif( 'acompte' ) ? self::textes_acompte( $id ) : array();
		if ( $acompte ) {
			$blocs[] = '<h2 class="etr-panneau__titre">' . esc_html__( 'Acompte et caution', 'espace-traiteur' ) . '</h2><ul class="etr-liste-simple"><li>' . implode( '</li><li>', array_map( 'esc_html', $acompte ) ) . '</li></ul>';
		}
		$envoi = ETR_Modules::actif( 'casse' ) ? get_post_meta( $id, self::META_RELEVE, true ) : '';
		if ( is_array( $envoi ) && ! empty( $envoi['envoye'] ) ) {
			/* translators: %s : montant. */
			$blocs[] = '<h2 class="etr-panneau__titre">' . esc_html__( 'Casse et manquants', 'espace-traiteur' ) . '</h2><p>' . esc_html( sprintf( __( 'Montant : %s', 'espace-traiteur' ), self::prix( $envoi['total'] ) ) ) . ' — <a class="etr-lien" href="' . esc_url( self::url_pdf( $id, 'releve' ) ) . '">' . esc_html__( 'Télécharger le relevé (PDF)', 'espace-traiteur' ) . '</a></p>';
		}
		foreach ( $blocs as $bloc ) {
			echo '<section class="etr-panneau etr-encart-traiteur">' . $bloc . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- construit et échappé ci-dessus.
		}
	}

	/**
	 * Le traiteur accepte le devis.
	 *
	 * @param string $action Action du formulaire.
	 */
	public static function action_traiteur( $action ) {
		if ( 'accepter_devis' !== $action || ! ETR_Modules::actif( 'devis' ) ) {
			return;
		}
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		if ( ! isset( $_POST['etr_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['etr_nonce'] ) ), 'etr_accepter_devis_' . $id ) ) {
			wp_die( esc_html__( 'Lien expiré : rechargez la page.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		if ( ! $id || 'etr_demande' !== get_post_type( $id ) || ! etr_peut_voir_demande( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$nom = isset( $_POST['etr_nom'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['etr_nom'] ) ) ), 0, 100 ) : '';
		// Seulement la version affichée au traiteur (un devis renvoyé entre-temps doit être relu).
		$version = isset( $_POST['devis_envoye'] ) ? absint( $_POST['devis_envoye'] ) : 0;
		$devis   = self::devis( $id );
		if ( $devis && (int) $devis['envoye'] !== $version ) {
			wp_safe_redirect( etr_url( array( 'etr_vue' => 'demande', 'id' => $id, 'etr_msg' => 'devis_change' ) ) );
			exit;
		}
		if ( self::acceptable( $id ) && '' !== $nom && ! empty( $_POST['etr_cgv'] ) ) {
			self::accepter( $id, $nom );
		}
		wp_safe_redirect( etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* PDF                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Adresse d'un document : ?etr_document=devis|releve&id=ID (traiteur de la demande ou équipe).
	 *
	 * @param int    $id   Demande.
	 * @param string $type devis | releve.
	 * @return string
	 */
	public static function url_pdf( $id, $type ) {
		return add_query_arg(
			array(
				'etr_document' => $type,
				'id'           => (int) $id,
			),
			etr_url()
		);
	}

	/**
	 * Téléchargement d'un devis ou d'un relevé.
	 */
	public static function telechargement() {
		if ( ! isset( $_GET['etr_document'], $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$type    = sanitize_key( wp_unslash( $_GET['etr_document'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $type, array( 'devis', 'releve' ), true ) ) {
			return; // Autres documents (facture) : servis par leur module.
		}
		$id      = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$user_id = get_current_user_id();
		$equipe  = ETR_Equipe::acces() && ETR_Equipe::bureau() && ETR_Gestion::demande_visible( $id );
		$auteur  = etr_peut_voir_demande( $id, $user_id );
		$permis  = $user_id && 'etr_demande' === get_post_type( $id ) && 'publish' === get_post_status( $id ) && ( $auteur || $equipe );
		if ( 'devis' === $type ) {
			$permis = $permis && ETR_Modules::actif( 'devis' ) && ( $equipe || self::devis( $id ) );
		} elseif ( 'releve' === $type ) {
			$envoi  = get_post_meta( $id, self::META_RELEVE, true );
			$permis = $permis && ETR_Modules::actif( 'casse' ) && self::releve( $id )['lignes'] && ( $equipe || ( is_array( $envoi ) && ! empty( $envoi['envoye'] ) ) );
		} else {
			$permis = false;
		}
		if ( ! $permis ) {
			wp_die( esc_html__( 'Document introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$pdf = self::pdf( $id, $type );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . self::nom_fichier( $id, $type ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- document PDF.
		exit;
	}

	/**
	 * Nom du fichier.
	 *
	 * @param int    $id   Demande.
	 * @param string $type devis | releve.
	 * @return string
	 */
	private static function nom_fichier( $id, $type ) {
		return ( 'devis' === $type ? 'devis-' : 'releve-casse-' ) . (int) $id . '-' . sanitize_title( get_the_title( $id ) ) . '.pdf';
	}

	/**
	 * Envoie un document au traiteur (pièce jointe).
	 *
	 * @param int    $id    Demande.
	 * @param string $type  devis | releve.
	 * @param string $titre Objet.
	 * @param string $texte Texte (suivi du lien vers l'espace).
	 * @return bool
	 */
	private static function envoyer_pdf( $id, $type, $titre, $texte ) {
		$user = get_userdata( (int) get_post_field( 'post_author', $id ) );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$dossier = trailingslashit( get_temp_dir() ) . 'etr-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dossier ) ) {
			return false;
		}
		$fichier = $dossier . '/' . self::nom_fichier( $id, $type );
		file_put_contents( $fichier, self::pdf( $id, $type ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		/* translators: 1 : site, 2 : titre, 3 : événement. */
		$objet  = sprintf( __( '[%1$s] %2$s — %3$s', 'espace-traiteur' ), etr_nom_site(), $titre, get_the_title( $id ) );
		/* translators: %s : nom. */
		$corps  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n" . $texte . "\n" . etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) . "\n";
		$envoye = etr_envoyer_mail( $user->user_email, $objet, $corps, array(), array( $fichier ) );
		wp_delete_file( $fichier );
		@rmdir( $dossier ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		return $envoye;
	}

	/**
	 * Devis ou relevé (PDF).
	 *
	 * @param int    $id   Demande.
	 * @param string $type devis | releve.
	 * @return string
	 */
	public static function pdf( $id, $type ) {
		$devis   = 'devis' === $type;
		$auteur  = get_userdata( (int) get_post_field( 'post_author', $id ) );
		$m       = 42;
		$largeur = ETR_PDF::LARGEUR - 2 * $m;
		$encre   = '#1d1d1b';
		$gris    = '#6b655f';
		/* translators: %d : numéro. */
		$titre = $devis ? sprintf( __( 'Devis n° %d', 'espace-traiteur' ), $id ) : sprintf( __( 'Relevé de casse n° %d', 'espace-traiteur' ), $id );
		$pdf   = new ETR_PDF( $titre );

		// En-tête.
		$y       = $m;
		$logo_id = ETR_Comptes::logo_id();
		$logo    = $logo_id ? ETR_Bon_Livraison::jpeg( get_attached_file( $logo_id ), 400 ) : '';
		if ( $logo ) {
			$pdf->image( $logo, $m, $y, 110 );
		} else {
			$pdf->texte( $m, $y + 18, etr_nom_site(), 18, true, $encre );
		}
		$pdf->texte( $m + $largeur, $y + 14, $devis ? __( 'DEVIS', 'espace-traiteur' ) : __( 'CASSE ET MANQUANTS', 'espace-traiteur' ), 15, true, $encre, 'droite' );
		/* translators: %d : numéro. */
		$pdf->texte( $m + $largeur, $y + 30, sprintf( __( 'Demande n° %d', 'espace-traiteur' ), $id ), 10, false, $gris, 'droite' );
		$info = $devis ? self::devis( $id ) : array();
		/* translators: %s : date. */
		$pdf->texte( $m + $largeur, $y + 44, sprintf( __( 'Établi le %s', 'espace-traiteur' ), wp_date( 'j F Y', ! empty( $info['envoye'] ) ? (int) $info['envoye'] : time() ) ), 10, false, $gris, 'droite' );
		if ( ! empty( $info['valable'] ) ) {
			/* translators: %s : date. */
			$pdf->texte( $m + $largeur, $y + 58, sprintf( __( 'Valable jusqu’au %s', 'espace-traiteur' ), etr_format_date( $info['valable'] ) ), 10, false, $gris, 'droite' );
		}
		$y += 76;
		$pdf->trait( $m, $y, $m + $largeur, $y, $encre, 1 );
		$y += 24;

		// Traiteur et événement.
		$col = $largeur / 2 - 10;
		$pdf->texte( $m, $y, __( 'TRAITEUR', 'espace-traiteur' ), 8, true, $gris );
		$y_g = $pdf->paragraphe( $m, $y + 15, $col, etr_nom( $auteur ), 11, true );
		if ( $auteur ) {
			$y_g = $pdf->paragraphe( $m, $y_g, $col, trim( get_user_meta( $auteur->ID, 'etr_contact', true ) . "\n" . $auteur->user_email ), 9.5, false, '#3a3732' );
		}
		$x_d = $m + $largeur / 2 + 10;
		$pdf->texte( $x_d, $y, __( 'ÉVÉNEMENT', 'espace-traiteur' ), 8, true, $gris );
		$y_d = $pdf->paragraphe( $x_d, $y + 15, $col, get_the_title( $id ), 11, true );
		list( $livraison, $reprise ) = ETR_Operations::jours( $id );
		$details = array_filter(
			array(
				etr_date_valide( (string) get_post_meta( $id, '_etr_date_evenement', true ) ) ? etr_format_date( (string) get_post_meta( $id, '_etr_date_evenement', true ) ) : '',
				(string) get_post_meta( $id, '_etr_lieu', true ),
				/* translators: 1 : livraison, 2 : reprise. */
				$livraison ? sprintf( __( 'Livraison le %1$s, reprise le %2$s', 'espace-traiteur' ), etr_format_date( $livraison ), etr_format_date( $reprise ) ) : '',
			)
		);
		$y_d = $pdf->paragraphe( $x_d, $y_d, $col, implode( "\n", $details ), 9.5, false, '#3a3732' );
		$y   = max( $y_g, $y_d ) + 20;

		// Tableau.
		$colonnes = $devis
			? array( __( 'Article', 'espace-traiteur' ), __( 'Qté', 'espace-traiteur' ), __( 'Prix unitaire', 'espace-traiteur' ), __( 'Montant', 'espace-traiteur' ) )
			: array( __( 'Article', 'espace-traiteur' ), __( 'Manquants', 'espace-traiteur' ), __( 'Cassés', 'espace-traiteur' ), __( 'Montant', 'espace-traiteur' ) );
		$x_cols   = array( $m + 8, $m + $largeur - 230, $m + $largeur - 110, $m + $largeur - 8 );
		$pdf->rectangle( $m, $y, $largeur, 20, '#efebe4' );
		foreach ( $colonnes as $n => $libelle ) {
			$pdf->texte( $x_cols[ $n ], $y + 13.5, $libelle, 9, true, $encre, $n ? 'droite' : 'gauche' );
		}
		$y += 20;

		$lignes = array();
		$totaux = array();
		if ( $devis ) {
			// Le devis envoyé (copie figée) ; sinon, aperçu du bureau avec les prix du moment.
			if ( ! empty( $info['lignes'] ) && ! empty( $info['chiffrage'] ) ) {
				$chiffrage = $info['chiffrage'];
				$detail    = $info['lignes'];
			} else {
				$chiffrage = etr_chiffrage( $id );
				$detail    = array();
				foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
					$detail[] = array(
						'titre'    => $ligne['titre'],
						'quantite' => (int) $ligne['quantite'],
						'unitaire' => $chiffrage['lignes'][ $index ]['unitaire'],
						'total'    => $chiffrage['lignes'][ $index ]['total'],
					);
				}
			}
			foreach ( $detail as $ligne ) {
				$lignes[] = array( $ligne['titre'], number_format_i18n( (int) $ligne['quantite'] ), null === $ligne['unitaire'] ? '—' : self::prix( $ligne['unitaire'] ), null === $ligne['total'] ? __( 'à chiffrer', 'espace-traiteur' ) : self::prix( $ligne['total'] ) );
			}
			if ( $chiffrage['remise_pct'] || null !== $chiffrage['livraison'] ) {
				$totaux[] = array( __( 'Sous-total du matériel', 'espace-traiteur' ), self::prix( $chiffrage['sous_total'] ), false );
			}
			if ( $chiffrage['remise_pct'] ) {
				/* translators: %s : pourcentage. */
				$totaux[] = array( sprintf( __( 'Remise (%s %%)', 'espace-traiteur' ), number_format_i18n( $chiffrage['remise_pct'], fmod( $chiffrage['remise_pct'], 1 ) ? 2 : 0 ) ), '– ' . self::prix( $chiffrage['remise'] ), false );
			}
			if ( null !== $chiffrage['livraison'] ) {
				$totaux[] = array( __( 'Livraison et reprise', 'espace-traiteur' ), self::prix( $chiffrage['livraison'] ), false );
			}
			$totaux[] = array( __( 'Total', 'espace-traiteur' ), self::prix( $chiffrage['total'] ), true );
		} else {
			$releve = self::releve( $id );
			foreach ( $releve['lignes'] as $ligne ) {
				$lignes[] = array( $ligne['titre'], number_format_i18n( $ligne['manquants'] ), number_format_i18n( $ligne['casses'] ), null === $ligne['total'] ? __( 'à chiffrer', 'espace-traiteur' ) : self::prix( $ligne['total'] ) );
			}
			$totaux[] = array( __( 'Total', 'espace-traiteur' ), self::prix( $releve['total'] ), true );
		}
		foreach ( $lignes as $i => $ligne ) {
			if ( $y > ETR_PDF::HAUTEUR - 120 ) {
				$pdf->page();
				$y = $m;
			}
			$morceaux = ETR_PDF::couper( $ligne[0], 10, $largeur - 300 );
			$haut     = 8 + 13.5 * count( $morceaux );
			if ( $i % 2 ) {
				$pdf->rectangle( $m, $y, $largeur, $haut, '#faf9f6' );
			}
			foreach ( $morceaux as $n => $texte ) {
				$pdf->texte( $x_cols[0], $y + 14 + 13.5 * $n, $texte, 10 );
			}
			for ( $c = 1; $c < 4; $c++ ) {
				$pdf->texte( $x_cols[ $c ], $y + 14, $ligne[ $c ], 10, false, $encre, 'droite' );
			}
			$y += $haut;
			$pdf->trait( $m, $y, $m + $largeur, $y, '#e3ddd3', 0.5 );
		}
		$y += 10;
		foreach ( $totaux as $total ) {
			$y += 16;
			$pdf->texte( $x_cols[2], $y, $total[0], $total[2] ? 11 : 10, $total[2], $encre, 'droite' );
			$pdf->texte( $x_cols[3], $y, $total[1], $total[2] ? 11 : 10, $total[2], $encre, 'droite' );
		}
		$y += 30;

		// Mentions.
		$mentions = array();
		if ( $devis ) {
			if ( ETR_Modules::actif( 'acompte' ) ) {
				$mentions = array_merge( $mentions, self::textes_acompte( $id ) );
			}
			if ( ! empty( $info['accepte'] ) ) {
				/* translators: 1 : nom, 2 : date et heure. */
				$mentions[] = sprintf( __( 'Devis accepté en ligne par %1$s le %2$s.', 'espace-traiteur' ), $info['accepte']['nom'], wp_date( 'j F Y à H:i', (int) $info['accepte']['le'] ) );
			} else {
				$mentions[] = __( 'Pour accepter ce devis : votre espace traiteur, fiche de la demande, « Accepter le devis ».', 'espace-traiteur' );
			}
			$cgv = (string) get_option( 'etr_cgv_url' );
			if ( $cgv ) {
				/* translators: %s : adresse. */
				$mentions[] = sprintf( __( 'Conditions générales : %s', 'espace-traiteur' ), $cgv );
			}
		} else {
			$mentions[] = __( 'Montants calculés au prix de remplacement de chaque article, d’après la reprise enregistrée sur place.', 'espace-traiteur' );
		}
		foreach ( $mentions as $mention ) {
			$y = $pdf->paragraphe( $m, $y, $largeur, $mention, 9.5, false, '#3a3732' ) + 4;
		}
		/* translators: %s : site. */
		$pdf->texte( $m, ETR_PDF::HAUTEUR - 28, sprintf( __( '%s — document établi à partir de la demande enregistrée dans l’espace traiteur.', 'espace-traiteur' ), etr_nom_site() ), 7.5, false, '#a49d92' );
		return $pdf->sortie();
	}

	/* ------------------------------------------------------------------ */
	/* Prix de remplacement (produits WooCommerce)                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Champ « Prix de remplacement (casse) » dans l'onglet Inventaire.
	 */
	public static function champ_produit() {
		if ( ! ETR_Modules::actif( 'casse' ) || ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_PRIX,
				'label'       => __( 'Prix de remplacement (casse)', 'espace-traiteur' ),
				'description' => __( 'Espace traiteur : montant facturé par article cassé ou manquant après la reprise.', 'espace-traiteur' ),
				'desc_tip'    => true,
				'data_type'   => 'price',
			)
		);
	}

	/**
	 * Enregistre le prix de remplacement.
	 *
	 * @param WC_Product $produit Produit.
	 */
	public static function enregistrer_produit( $produit ) {
		if ( ! ETR_Modules::actif( 'casse' ) || ! isset( $_POST[ self::META_PRIX ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- formulaire produit WooCommerce (nonce vérifié par WordPress).
			return;
		}
		$prix = self::montant( sanitize_text_field( wp_unslash( $_POST[ self::META_PRIX ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( null === $prix ) {
			$produit->delete_meta_data( self::META_PRIX );
		} else {
			$produit->update_meta_data( self::META_PRIX, $prix );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Relances                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Relances du jour (à partir de 9 h, une fois par jour).
	 *
	 * @param bool $forcer Ignorer l'heure et le passage du jour (tests, WP-CLI).
	 */
	public static function relances( $forcer = false ) {
		if ( ! ETR_Modules::actif( 'relances' ) ) {
			return;
		}
		$maintenant = current_datetime();
		$jour       = $maintenant->format( 'Y-m-d' );
		if ( true !== $forcer && ( (int) $maintenant->format( 'G' ) < 9 || get_option( self::OPTION_JOUR ) === $jour ) ) {
			return;
		}
		update_option( self::OPTION_JOUR, $jour, false );

		$ids = get_posts(
			array(
				'post_type'     => 'etr_demande',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- demandes en cours.
					array(
						'key'     => '_etr_statut',
						'value'   => array( 'en_attente', 'validee', 'livree' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$dans_7 = $maintenant->modify( '+7 days' )->format( 'Y-m-d' );
		foreach ( $ids as $id ) {
			$statut = etr_get_statut( $id );

			// Devis sans réponse depuis 3 jours : un rappel au traiteur.
			$devis = self::devis( $id );
			if ( ETR_Modules::actif( 'devis' ) && self::acceptable( $id ) && time() - (int) $devis['envoye'] >= 3 * DAY_IN_SECONDS && ! get_post_meta( $id, '_etr_devis_relance', true ) ) {
				/* translators: 1 : événement, 2 : montant, 3 : date. */
				ETR_Traiteur_Direct::prevenir( $id, __( 'Votre devis attend votre accord', 'espace-traiteur' ), sprintf( __( 'Le devis de « %1$s » (%2$s) est valable jusqu’au %3$s. Vous pouvez l’accepter en ligne depuis votre espace.', 'espace-traiteur' ), get_the_title( $id ), self::prix( $devis['total'] ), etr_format_date( $devis['valable'] ) ) );
				update_post_meta( $id, '_etr_devis_relance', time() );
				ETR_Historique::ajouter( $id, 'relance', __( 'Relance envoyée au traiteur : devis pas encore accepté', 'espace-traiteur' ), 0 );
			}

			// Reprise en retard : le bureau est prévenu (une fois par date prévue).
			list( $livraison, $reprise ) = ETR_Operations::jours( $id );
			if ( 'livree' === $statut && $reprise && $reprise < $jour && ! ETR_Operations::reprise( $id ) && get_post_meta( $id, '_etr_relance_reprise', true ) !== $reprise ) {
				/* translators: %s : date. */
				ETR_Push::notifier( 'bureau', __( 'Reprise en retard', 'espace-traiteur' ), sprintf( __( '%1$s · prévue le %2$s', 'espace-traiteur' ), get_the_title( $id ), etr_format_date( $reprise ) ), $id, '', 'relance' );
				update_post_meta( $id, '_etr_relance_reprise', $reprise );
			}

			// Acompte non reçu à 7 jours de la livraison.
			$a = self::acompte( $id );
			if ( ETR_Modules::actif( 'acompte' ) && 'validee' === $statut && null !== $a['acompte'] && ! $a['acompte_recu'] && $livraison && $livraison <= $dans_7 && $livraison >= $jour && get_post_meta( $id, '_etr_relance_acompte', true ) !== $livraison ) {
				/* translators: 1 : événement, 2 : montant, 3 : date. */
				ETR_Push::notifier( 'bureau', __( 'Acompte non reçu', 'espace-traiteur' ), sprintf( __( '%1$s · %2$s · livraison le %3$s', 'espace-traiteur' ), get_the_title( $id ), self::prix( $a['acompte'] ), etr_format_date( $livraison ) ), $id, '', 'relance' );
				update_post_meta( $id, '_etr_relance_acompte', $livraison ); // Une fois par date de livraison.
			}
		}
	}
}
