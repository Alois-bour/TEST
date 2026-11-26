<?php
/**
 * Fiche d'une demande dans l'application de l'équipe : onglets, prochaine étape, argent en un
 * coup d'œil, et liste « À faire » de l'accueil du bureau.
 *
 * Les panneaux des fonctions activables se placent dans un onglet avec les actions
 * « etr_gestion_fiche_{onglet} » (resume, materiel, echanges, argent).
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fiche en onglets et tâches du bureau.
 */
class ETR_Fiche {

	/**
	 * Onglets de la fiche (l'argent est réservé au bureau).
	 *
	 * @param int $id Demande.
	 * @return array[] Clé => { libelle, pastille }.
	 */
	public static function onglets( $id ) {
		$taches   = ETR_Equipe::bureau() ? self::taches( $id ) : array();
		$par      = array();
		foreach ( $taches as $t ) {
			$par[ $t['onglet'] ] = ( isset( $par[ $t['onglet'] ] ) ? $par[ $t['onglet'] ] : 0 ) + 1;
		}
		$echanges = ETR_Operations::incidents_ouverts( $id ) + ( self::message_sans_reponse( $id ) ? 1 : 0 );
		$onglets  = array(
			'resume'     => array( __( 'Résumé', 'espace-traiteur' ), 0 ),
			'materiel'   => array( __( 'Matériel', 'espace-traiteur' ), isset( $par['materiel'] ) ? $par['materiel'] : 0 ),
			'echanges'   => array( __( 'Échanges', 'espace-traiteur' ), $echanges ),
			'argent'     => array( __( 'Argent', 'espace-traiteur' ), isset( $par['argent'] ) ? $par['argent'] : 0 ),
			'historique' => array( __( 'Historique', 'espace-traiteur' ), 0 ),
		);
		if ( ! ETR_Equipe::bureau() ) {
			unset( $onglets['argent'] );
		}
		/**
		 * Onglets de la fiche d'une demande.
		 *
		 * @param array $onglets Clé => { libellé, pastille }.
		 * @param int   $id      Demande.
		 */
		return (array) apply_filters( 'etr_gestion_fiche_onglets', $onglets, $id );
	}

	/**
	 * Onglet demandé dans l'adresse (?onglet=), Résumé par défaut.
	 *
	 * @param array $onglets Onglets.
	 * @return string
	 */
	public static function onglet_actif( $onglets ) {
		$demande = isset( $_GET['onglet'] ) ? sanitize_key( wp_unslash( $_GET['onglet'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- simple affichage.
		return isset( $onglets[ $demande ] ) ? $demande : 'resume';
	}

	/**
	 * Barre d'onglets (de vrais liens : elle marche aussi sans JavaScript).
	 *
	 * @param int    $id      Demande.
	 * @param array  $onglets Onglets.
	 * @param string $actif   Onglet affiché.
	 */
	public static function barre( $id, $onglets, $actif ) {
		echo '<nav class="etr-fiche-onglets" aria-label="' . esc_attr__( 'Parties de la fiche', 'espace-traiteur' ) . '" data-fiche="' . (int) $id . '"><ul role="tablist">';
		foreach ( $onglets as $cle => $o ) {
			$url = ETR_Gestion::url( array_merge( array( 'vue' => 'demande', 'id' => $id ), 'resume' === $cle ? array() : array( 'onglet' => $cle ) ) );
			echo '<li role="presentation"><a role="tab" id="etr-onglet-' . esc_attr( $cle ) . '" href="' . esc_url( $url ) . '" data-onglet="' . esc_attr( $cle ) . '" aria-controls="etr-partie-' . esc_attr( $cle ) . '" aria-selected="' . ( $cle === $actif ? 'true' : 'false' ) . '">' . esc_html( $o[0] );
			if ( $o[1] ) {
				/* translators: %s : nombre. */
				echo ' <span class="etr-fiche-onglets__pastille" aria-label="' . esc_attr( sprintf( _n( '%s point à voir', '%s points à voir', $o[1], 'espace-traiteur' ), number_format_i18n( $o[1] ) ) ) . '">' . esc_html( number_format_i18n( $o[1] ) ) . '</span>';
			}
			echo '</a></li>';
		}
		echo '</ul></nav>';
	}

	/**
	 * Ouvre une partie de la fiche.
	 *
	 * @param string $cle   Onglet.
	 * @param string $actif Onglet affiché.
	 * @param string $classe Classe en plus.
	 */
	public static function ouvrir( $cle, $actif, $classe = '' ) {
		echo '<div class="etr-fiche-partie' . ( $classe ? ' ' . esc_attr( $classe ) : '' ) . '" role="tabpanel" id="etr-partie-' . esc_attr( $cle ) . '" aria-labelledby="etr-onglet-' . esc_attr( $cle ) . '" data-partie="' . esc_attr( $cle ) . '"' . ( $cle === $actif ? '' : ' hidden' ) . '>';
	}

	/* ------------------------------------------------------------------ */
	/* Tâches                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Le dernier message vient du traiteur (pas encore de réponse de l'équipe) ?
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	public static function message_sans_reponse( $id ) {
		if ( ! ETR_Modules::actif( 'messages' ) || ! class_exists( 'ETR_Traiteur_Direct' ) ) {
			return false;
		}
		$messages = ETR_Traiteur_Direct::messages( $id );
		$dernier  = end( $messages );
		return $dernier && empty( $dernier['equipe'] );
	}

	/**
	 * Ce que le bureau doit faire sur une demande, dans l'ordre du dossier.
	 *
	 * @param int $id Demande.
	 * @return array[] { cle, onglet, texte, urgent }
	 */
	public static function taches( $id ) {
		$statut = etr_get_statut( $id );
		$t      = array();
		$ajout  = function ( $cle, $onglet, $texte, $urgent = false ) use ( &$t ) {
			$t[] = array(
				'cle'    => $cle,
				'onglet' => $onglet,
				'texte'  => $texte,
				'urgent' => $urgent,
			);
		};
		if ( in_array( $statut, array( 'refusee', 'annulee' ), true ) ) {
			return array();
		}
		if ( 'en_attente' === $statut ) {
			$ajout( 'etudier', 'resume', __( 'Demande à étudier : valider ou refuser', 'espace-traiteur' ), true );
		}
		if ( ETR_Modules::actif( 'devis' ) && in_array( $statut, array( 'en_attente', 'validee' ), true ) && ! ETR_Devis::devis( $id ) ) {
			$ajout( 'devis', 'argent', __( 'Devis à envoyer', 'espace-traiteur' ) );
		}
		if ( ETR_Modules::actif( 'acompte' ) && in_array( $statut, array( 'validee', 'livree' ), true ) ) {
			$a = ETR_Devis::acompte( $id );
			if ( (float) $a['acompte'] > 0 && ! $a['acompte_recu'] ) {
				/* translators: %s : montant. */
				$ajout( 'acompte', 'argent', sprintf( __( 'Acompte de %s à recevoir', 'espace-traiteur' ), ETR_Devis::prix( $a['acompte'] ) ) );
			}
			if ( 'validee' === $statut && (float) $a['caution'] > 0 && ! $a['caution_recue'] ) {
				/* translators: %s : montant. */
				$ajout( 'caution', 'argent', sprintf( __( 'Caution de %s à recevoir', 'espace-traiteur' ), ETR_Devis::prix( $a['caution'] ) ) );
			}
		}
		$reprise = ETR_Operations::reprise( $id );
		if ( 'livree' === $statut && $reprise && ( ! empty( $reprise['manquants'] ) || ! empty( $reprise['casses'] ) ) ) {
			$ajout( 'ecarts', 'materiel', __( 'Écarts à la reprise : casse ou manquants à régler', 'espace-traiteur' ), true );
		}
		if ( ETR_Modules::actif( 'facture' ) && class_exists( 'ETR_Facture' ) ) {
			$f = ETR_Facture::facture( $id );
			if ( ! $f && ETR_Facture::etablissable( $id ) ) {
				$ajout( 'facture', 'argent', __( 'Facture à établir', 'espace-traiteur' ) );
			} elseif ( $f && empty( $f['payee'] ) ) {
				$reste  = ETR_Facture::reglements( $id, $f )['reste'];
				$retard = ! empty( $f['echeance'] ) && $f['echeance'] < current_time( 'Y-m-d' );
				/* translators: 1 : numéro, 2 : montant. */
				$texte = sprintf( __( 'Facture %1$s : %2$s à encaisser', 'espace-traiteur' ), $f['numero'], ETR_Devis::prix( $reste ) );
				$ajout( 'encaisser', 'argent', $retard ? $texte . ' · ' . __( 'échéance dépassée', 'espace-traiteur' ) : $texte, $retard );
			}
		}
		if ( ETR_Modules::actif( 'stripe' ) && class_exists( 'ETR_Stripe' ) ) {
			$s = ETR_Stripe::paiements( $id );
			if ( ! empty( $s['doublons'] ) ) {
				$ajout( 'doublon', 'argent', __( 'Paiement en double à rembourser (Stripe)', 'espace-traiteur' ), true );
			}
			if ( ! empty( $s['caution']['debit_attente']['montant'] ) && in_array( $s['caution']['statut'] ?? '', array( 'bloquee', 'enregistree' ), true ) ) {
				$ajout( 'debit', 'argent', __( 'Débit de caution sans réponse de Stripe : à relancer', 'espace-traiteur' ), true );
			}
			if ( 'cloturee' === $statut && in_array( $s['caution']['statut'] ?? '', array( 'bloquee', 'enregistree' ), true ) ) {
				$ajout( 'liberer', 'argent', __( 'Caution à libérer', 'espace-traiteur' ) );
			}
		}
		if ( ETR_Operations::incidents_ouverts( $id ) ) {
			$ajout( 'incident', 'echanges', __( 'Incident à résoudre', 'espace-traiteur' ), true );
		}
		if ( self::message_sans_reponse( $id ) ) {
			$ajout( 'message', 'echanges', __( 'Message du traiteur sans réponse', 'espace-traiteur' ) );
		}
		/**
		 * Tâches du bureau sur une demande.
		 *
		 * @param array[] $t  { cle, onglet, texte, urgent }.
		 * @param int     $id Demande.
		 */
		return (array) apply_filters( 'etr_gestion_taches', $t, $id );
	}

	/**
	 * Carte « Prochaine étape » du bureau (en tête du résumé).
	 *
	 * @param int $id Demande.
	 */
	public static function prochaine_etape( $id ) {
		if ( ! ETR_Equipe::bureau() ) {
			return;
		}
		// Valider et régler les écarts : dans le parcours ; ici, le reste (devis, acompte, incidents…).
		$taches = array_values(
			array_filter(
				self::taches( $id ),
				function ( $t ) {
					return ! in_array( $t['cle'], array( 'etudier', 'ecarts' ), true );
				}
			)
		);
		if ( ! $taches ) {
			return;
		}
		echo '<section class="etr-aussi" aria-labelledby="etr-aussi-' . (int) $id . '"><h2 class="etr-aussi__titre" id="etr-aussi-' . (int) $id . '">' . esc_html__( 'Aussi à faire', 'espace-traiteur' ) . '</h2><ul>';
		foreach ( $taches as $t ) {
			$url = ETR_Gestion::url( array_merge( array( 'vue' => 'demande', 'id' => $id ), 'resume' === $t['onglet'] ? array() : array( 'onglet' => $t['onglet'] ) ) );
			echo '<li' . ( $t['urgent'] ? ' class="est-urgent"' : '' ) . '><a href="' . esc_url( $url ) . '" data-onglet="' . esc_attr( $t['onglet'] ) . '"><span>' . esc_html( $t['texte'] ) . '</span>' . ETR_Gestion::icone( 'suite' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
		}
		echo '</ul></section>';
	}

	/* ------------------------------------------------------------------ */
	/* Argent en un coup d'œil                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Frise devis → acompte → caution → facture.
	 *
	 * @param int $id Demande.
	 */
	public static function resume_argent( $id ) {
		$etapes = array();
		$total  = etr_chiffrage( $id )['total'];
		if ( ETR_Modules::actif( 'devis' ) ) {
			$d = ETR_Devis::devis( $id );
			if ( ! $d ) {
				$etapes[] = array( __( 'Devis', 'espace-traiteur' ), __( 'à envoyer', 'espace-traiteur' ), 'a-faire', '' );
			} else {
				$montant  = isset( $d['chiffrage']['total'] ) ? (float) $d['chiffrage']['total'] : (float) ( $d['montant'] ?? $total );
				$etapes[] = array( __( 'Devis', 'espace-traiteur' ), ! empty( $d['accepte'] ) ? __( 'accepté', 'espace-traiteur' ) : __( 'envoyé', 'espace-traiteur' ), ! empty( $d['accepte'] ) ? 'fait' : 'en-cours', ETR_Devis::prix( $montant ) );
			}
		}
		if ( ETR_Modules::actif( 'contrat' ) && class_exists( 'ETR_Contrat' ) ) {
			$etat     = ETR_Contrat::etat( $id );
			$noms     = array(
				'signe'      => array( __( 'signé', 'espace-traiteur' ), 'fait' ),
				'a_signer'   => array( __( 'à signer', 'espace-traiteur' ), 'a-faire' ),
				'a_resigner' => array( __( 'à signer de nouveau', 'espace-traiteur' ), 'a-faire' ),
				'attente'    => array( __( 'après validation', 'espace-traiteur' ), 'plus-tard' ),
			);
			if ( isset( $noms[ $etat ] ) ) {
				$etapes[] = array( __( 'Contrat', 'espace-traiteur' ), $noms[ $etat ][0], $noms[ $etat ][1], '' );
			}
		}
		if ( ETR_Modules::actif( 'acompte' ) ) {
			$a = ETR_Devis::acompte( $id );
			if ( (float) $a['acompte'] > 0 ) {
				$etapes[] = array( __( 'Acompte', 'espace-traiteur' ), $a['acompte_recu'] ? sprintf( /* translators: %s : date. */ __( 'reçu le %s', 'espace-traiteur' ), etr_format_date( $a['acompte_recu'] ) ) : __( 'à recevoir', 'espace-traiteur' ), $a['acompte_recu'] ? 'fait' : 'a-faire', ETR_Devis::prix( $a['acompte'] ) );
			}
			if ( (float) $a['caution'] > 0 ) {
				$etat = $a['caution_rendue'] ? array( __( 'rendue', 'espace-traiteur' ), 'fait' ) : ( $a['caution_recue'] ? array( __( 'reçue', 'espace-traiteur' ), 'en-cours' ) : array( __( 'à recevoir', 'espace-traiteur' ), 'a-faire' ) );
				$s    = class_exists( 'ETR_Stripe' ) && ETR_Modules::actif( 'stripe' ) ? ( ETR_Stripe::paiements( $id )['caution'] ?? array() ) : array();
				$noms = array(
					'bloquee'     => array( __( 'empreinte bloquée', 'espace-traiteur' ), 'en-cours' ),
					'enregistree' => array( __( 'carte enregistrée', 'espace-traiteur' ), 'en-cours' ),
					'debitee'     => array( sprintf( /* translators: %s : montant. */ __( '%s débités', 'espace-traiteur' ), ETR_Devis::prix( $s['debite'] ?? 0 ) ), 'fait' ),
					'liberee'     => array( __( 'libérée', 'espace-traiteur' ), 'fait' ),
				);
				if ( isset( $s['statut'], $noms[ $s['statut'] ] ) ) {
					$etat = $noms[ $s['statut'] ];
				}
				$etapes[] = array( __( 'Caution', 'espace-traiteur' ), $etat[0], $etat[1], ETR_Devis::prix( $a['caution'] ) );
			}
		}
		if ( ETR_Modules::actif( 'facture' ) && class_exists( 'ETR_Facture' ) ) {
			$f = ETR_Facture::facture( $id );
			if ( ! $f ) {
				$etapes[] = array( __( 'Facture', 'espace-traiteur' ), ETR_Facture::etablissable( $id ) ? __( 'à établir', 'espace-traiteur' ) : __( 'après la reprise', 'espace-traiteur' ), ETR_Facture::etablissable( $id ) ? 'a-faire' : 'plus-tard', '' );
			} elseif ( ! empty( $f['payee'] ) ) {
				$etapes[] = array( __( 'Facture', 'espace-traiteur' ), sprintf( /* translators: %s : date. */ __( 'payée le %s', 'espace-traiteur' ), etr_format_date( $f['payee'] ) ), 'fait', ETR_Devis::prix( $f['ttc'] ) );
			} else {
				$etapes[] = array( __( 'Facture', 'espace-traiteur' ), sprintf( /* translators: %s : montant. */ __( 'reste %s', 'espace-traiteur' ), ETR_Devis::prix( ETR_Facture::reglements( $id, $f )['reste'] ) ), 'a-faire', ETR_Devis::prix( $f['ttc'] ) );
			}
		}
		echo '<section class="etr-panneau etr-argent"><div class="etr-argent__tete"><h2 class="etr-panneau__titre">' . esc_html__( 'En un coup d’œil', 'espace-traiteur' ) . '</h2>';
		echo '<p class="etr-argent__total"><span>' . esc_html__( 'Montant indicatif', 'espace-traiteur' ) . '</span><strong>' . esc_html( ETR_Devis::prix( $total ) ) . '</strong></p></div>';
		if ( $etapes ) {
			echo '<ol class="etr-argent__frise">';
			foreach ( $etapes as $e ) {
				echo '<li class="etr-argent__etape etr-argent__etape--' . esc_attr( $e[2] ) . '"><span class="etr-argent__puce" aria-hidden="true">' . ( 'fait' === $e[2] ? ETR_Gestion::icone( 'coche' ) : '' ) . '</span><span class="etr-argent__nom">' . esc_html( $e[0] ) . '</span><span class="etr-argent__etat">' . esc_html( $e[1] ) . '</span>' . ( $e[3] ? '<span class="etr-argent__montant">' . esc_html( $e[3] ) . '</span>' : '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
			}
			echo '</ol>';
		} else {
			echo '<p class="etr-muted">' . esc_html__( 'Devis, acompte, caution et facture s’activent dans Espace traiteur › Fonctions.', 'espace-traiteur' ) . '</p>';
		}
		echo '</section>';
	}

	/* ------------------------------------------------------------------ */
	/* Accueil du bureau                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Liste « À faire » de l'accueil : les tâches de toutes les demandes en cours, groupées.
	 *
	 * @param int[] $ids Demandes à examiner.
	 */
	public static function a_faire( $ids ) {
		$groupes = array();
		foreach ( $ids as $id ) {
			foreach ( self::taches( $id ) as $t ) {
				if ( 'etudier' === $t['cle'] ) {
					continue; // Déjà listées plus bas (« À traiter »).
				}
				$groupes[ $t['cle'] ][] = array( $id, $t );
			}
		}
		echo '<section class="etr-a-faire" aria-labelledby="etr-a-faire-titre"><div class="etr-section-entete"><h2 id="etr-a-faire-titre" class="etr-section-titre">' . esc_html__( 'À faire', 'espace-traiteur' );
		$n = array_sum( array_map( 'count', $groupes ) );
		if ( $n ) {
			echo ' <span class="etr-compteur">' . esc_html( number_format_i18n( $n ) ) . '</span>';
		}
		echo '</h2></div>';
		if ( ! $groupes ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Rien en attente : devis, acomptes, factures, incidents et messages sont à jour.', 'espace-traiteur' ) . '</p></div></section>';
			return;
		}
		// Urgent d'abord, puis l'ordre du dossier.
		uasort(
			$groupes,
			function ( $a, $b ) {
				return (int) $b[0][1]['urgent'] - (int) $a[0][1]['urgent'];
			}
		);
		echo '<ul class="etr-a-faire__liste">';
		foreach ( $groupes as $liste ) {
			foreach ( array_slice( $liste, 0, 6 ) as $e ) {
				list( $id, $t ) = $e;
				$url            = ETR_Gestion::url( array_merge( array( 'vue' => 'demande', 'id' => $id ), 'resume' === $t['onglet'] ? array() : array( 'onglet' => $t['onglet'] ) ) );
				echo '<li class="etr-a-faire__ligne' . ( $t['urgent'] ? ' etr-a-faire__ligne--urgent' : '' ) . '"><a href="' . esc_url( $url ) . '"><span class="etr-a-faire__texte">' . esc_html( $t['texte'] ) . '<small>' . esc_html( get_the_title( $id ) . ' · ' . etr_nom( get_userdata( (int) get_post_field( 'post_author', $id ) ) ) ) . '</small></span>' . ETR_Gestion::icone( 'suite' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
			}
			if ( count( $liste ) > 6 ) {
				/* translators: %s : nombre. */
				echo '<li class="etr-a-faire__plus">' . esc_html( sprintf( __( 'et %s autres du même type', 'espace-traiteur' ), number_format_i18n( count( $liste ) - 6 ) ) ) . '</li>';
			}
		}
		echo '</ul></section>';
	}

	/* ------------------------------------------------------------------ */
	/* Espace traiteur                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Ce que le traiteur a à faire sur sa demande (liens vers le bon bloc de la page).
	 *
	 * @param int $id Demande.
	 * @return array[] { texte, ancre }
	 */
	public static function taches_traiteur( $id ) {
		$t = array();
		if ( ! etr_peut_voir_demande( $id ) ) {
			return $t;
		}
		if ( ETR_Modules::actif( 'devis' ) && ETR_Devis::devis( $id ) && ETR_Devis::acceptable( $id ) ) {
			$t[] = array( __( 'Accepter le devis', 'espace-traiteur' ), '#etr-devis-accepter' );
		}
		if ( class_exists( 'ETR_Stripe' ) && ETR_Stripe::pret() ) {
			$acompte = ETR_Stripe::a_payer( $id, 'acompte' );
			if ( $acompte > 0 ) {
				/* translators: %s : montant. */
				$t[] = array( sprintf( __( 'Payer l’acompte de %s', 'espace-traiteur' ), ETR_Devis::prix( $acompte ) ), '#etr-paiement' );
			}
			$caution = ETR_Stripe::a_payer( $id, 'caution' );
			$mode    = ETR_Stripe::parametres()['caution'];
			if ( $caution > 0 && ! ( 'empreinte' === $mode && ETR_Stripe::empreinte_a_partir_du( $id ) ) ) {
				/* translators: %s : montant. */
				$t[] = array( sprintf( 'empreinte' === $mode ? __( 'Déposer l’empreinte bancaire de %s (caution)', 'espace-traiteur' ) : __( 'Enregistrer votre carte pour la caution de %s', 'espace-traiteur' ), ETR_Devis::prix( $caution ) ), '#etr-paiement' );
			}
			$solde = ETR_Stripe::a_payer( $id, 'solde' );
			if ( $solde > 0 ) {
				/* translators: %s : montant. */
				$t[] = array( sprintf( __( 'Régler la facture : %s', 'espace-traiteur' ), ETR_Devis::prix( $solde ) ), '#etr-facture-traiteur' );
			}
		}
		if ( ETR_Modules::actif( 'avis' ) && 'cloturee' === etr_get_statut( $id ) && class_exists( 'ETR_Traiteur_Plus' ) && ! is_array( get_post_meta( $id, ETR_Traiteur_Plus::META_AVIS, true ) ) ) {
			$t[] = array( __( 'Donner votre avis sur la prestation', 'espace-traiteur' ), '#etr-avis' );
		}
		/**
		 * Tâches du traiteur sur une demande.
		 *
		 * @param array[] $t  { texte, ancre }.
		 * @param int     $id Demande.
		 */
		return (array) apply_filters( 'etr_traiteur_taches', $t, $id );
	}

	/**
	 * Carte « À faire » en haut de la fiche du traiteur.
	 *
	 * @param int $id Demande.
	 */
	public static function actions_traiteur( $id ) {
		$t = self::taches_traiteur( $id );
		if ( ! $t ) {
			return;
		}
		echo '<section class="etr-a-faire-traiteur" aria-labelledby="etr-a-faire-traiteur-titre"><h2 id="etr-a-faire-traiteur-titre" class="etr-a-faire-traiteur__titre">' . esc_html( _n( 'Une action de votre part', 'Actions de votre part', count( $t ), 'espace-traiteur' ) ) . '</h2><ul>';
		foreach ( $t as $i => $tache ) {
			echo '<li><a class="etr-btn' . ( $i ? ' etr-btn--secondaire' : '' ) . '" href="' . esc_attr( $tache[1] ) . '">' . esc_html( $tache[0] ) . '</a></li>';
		}
		echo '</ul></section>';
	}
}
