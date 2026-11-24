<?php
/**
 * Calculs du pilotage à partir des demandes existantes.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

class ETR_Statistiques {
	/**
	 * @param int[]  $ids  Demandes publiées.
	 * @param string $jour Date locale AAAA-MM-JJ.
	 * @return array Résultats agrégés.
	 */
	public static function calculer( $ids, $jour ) {
		$resultat = array( 'mois' => array(), 'traiteurs' => array(), 'articles' => array(), 'ruptures' => array() );
		$reservations = array();
		$statuts_ca   = array( 'validee', 'livree', 'cloturee' );
		$statuts_stock = etr_statuts_reservant();

		foreach ( $ids as $id ) {
			$statut = etr_get_statut( $id );
			$lignes = etr_get_lignes( $id );
			$date   = (string) get_post_meta( $id, '_etr_date_evenement', true );
			if ( in_array( $statut, $statuts_ca, true ) && etr_date_valide( $date ) ) {
				$mois      = substr( $date, 0, 7 );
				$chiffrage = etr_chiffrage( $id );
				if ( ! isset( $resultat['mois'][ $mois ] ) ) {
					$resultat['mois'][ $mois ] = array( 'total' => 0.0, 'demandes' => 0, 'incomplets' => 0 );
				}
				$resultat['mois'][ $mois ]['total'] += $chiffrage['total'];
				++$resultat['mois'][ $mois ]['demandes'];
				if ( $chiffrage['sans_prix'] || null === $chiffrage['livraison'] || '' !== (string) get_post_meta( $id, '_etr_autres_besoins', true ) ) {
					++$resultat['mois'][ $mois ]['incomplets'];
				}
				$auteur = (int) get_post_field( 'post_author', $id );
				if ( ! isset( $resultat['traiteurs'][ $auteur ] ) ) {
					$resultat['traiteurs'][ $auteur ] = array( 'total' => 0.0, 'demandes' => 0 );
				}
				$resultat['traiteurs'][ $auteur ]['total'] += $chiffrage['total'];
				++$resultat['traiteurs'][ $auteur ]['demandes'];
				foreach ( $lignes as $ligne ) {
					$article_id = (int) $ligne['article_id'];
					if ( ! isset( $resultat['articles'][ $article_id ] ) ) {
						$resultat['articles'][ $article_id ] = array( 'titre' => $ligne['titre'], 'quantite' => 0 );
					}
					$resultat['articles'][ $article_id ]['quantite'] += (int) $ligne['quantite'];
				}
			}

			if ( ! in_array( $statut, $statuts_stock, true ) ) {
				continue;
			}
			list( $debut, $fin ) = etr_periode_demande( $id );
			if ( ! $debut || $fin < $jour ) {
				continue;
			}
			foreach ( $lignes as $ligne ) {
				$article_id = (int) $ligne['article_id'];
				if ( ! isset( $reservations[ $article_id ][ $id ] ) ) {
					$reservations[ $article_id ][ $id ] = array( 'debut' => $debut, 'fin' => $fin, 'quantite' => 0, 'titre' => $ligne['titre'] );
				}
				$reservations[ $article_id ][ $id ]['quantite'] += (int) $ligne['quantite'];
			}
		}

		foreach ( $reservations as $article_id => $periodes ) {
			$stock = etr_stock_total( $article_id );
			if ( null === $stock ) {
				continue;
			}
			foreach ( $periodes as $periode ) {
				$total = 0;
				foreach ( $periodes as $autre ) {
					if ( $autre['debut'] <= $periode['fin'] && $autre['fin'] >= $periode['debut'] ) {
						$total += $autre['quantite'];
					}
				}
				if ( $total >= $stock ) {
					if ( ! isset( $resultat['ruptures'][ $article_id ] ) ) {
						$resultat['ruptures'][ $article_id ] = array( 'titre' => $periode['titre'], 'demandes' => 0 );
					}
					++$resultat['ruptures'][ $article_id ]['demandes'];
				}
			}
		}
		krsort( $resultat['mois'] );
		uasort( $resultat['traiteurs'], function ( $a, $b ) { return $b['total'] <=> $a['total']; } );
		uasort( $resultat['articles'], function ( $a, $b ) { return $b['quantite'] <=> $a['quantite']; } );
		uasort( $resultat['ruptures'], function ( $a, $b ) { return $b['demandes'] <=> $a['demandes']; } );
		return $resultat;
	}
}
