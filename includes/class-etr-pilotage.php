<?php
/**
 * Statistiques du terrain (fonction activable « pilotage_terrain ») : livraisons et reprises
 * par livreur, taux de casse et de manquants, incidents par type, sur 12 mois.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pilotage du terrain.
 */
class ETR_Pilotage {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'etr_gestion_statistiques', array( __CLASS__, 'afficher' ) );
	}

	/**
	 * Calcul sur les demandes publiées des 12 derniers mois (livraison ou reprise enregistrée).
	 *
	 * @return array { livreurs: [ user_id => { livraisons, reprises } ], sortis, casses, manquants, reprises, incidents: [ type => n ] }
	 */
	public static function calculer() {
		$depuis = time() - YEAR_IN_SECONDS;
		$ids    = get_posts(
			array(
				'post_type'     => 'etr_demande',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => ETR_Operations::META_LIVRAISON, 'compare' => 'EXISTS' ),
					array( 'key' => ETR_Operations::META_REPRISE, 'compare' => 'EXISTS' ),
					array( 'key' => ETR_Operations::META_INCIDENTS, 'compare' => 'EXISTS' ),
				),
			)
		);
		$r = array(
			'livreurs'  => array(),
			'sortis'    => 0,
			'casses'    => 0,
			'manquants' => 0,
			'reprises'  => 0,
			'incidents' => array(),
		);
		$compter = function ( $user_id, $cle ) use ( &$r ) {
			$user_id = (int) $user_id;
			if ( ! isset( $r['livreurs'][ $user_id ] ) ) {
				$r['livreurs'][ $user_id ] = array( 'livraisons' => 0, 'reprises' => 0 );
			}
			++$r['livreurs'][ $user_id ][ $cle ];
		};
		foreach ( $ids as $id ) {
			$livraison = ETR_Operations::livraison( $id );
			if ( $livraison && (int) $livraison['le'] >= $depuis ) {
				$compter( $livraison['par'], 'livraisons' );
			}
			$reprise = ETR_Operations::reprise( $id );
			if ( $reprise && (int) $reprise['le'] >= $depuis ) {
				$compter( $reprise['par'], 'reprises' );
				++$r['reprises'];
				$r['casses']    += (int) $reprise['casses'];
				$r['manquants'] += (int) $reprise['manquants'];
				foreach ( etr_get_lignes( $id ) as $ligne ) {
					$r['sortis'] += (int) $ligne['quantite'];
				}
			}
			foreach ( ETR_Operations::incidents( $id ) as $incident ) {
				if ( (int) $incident['le'] >= $depuis ) {
					$r['incidents'][ $incident['type'] ] = ( isset( $r['incidents'][ $incident['type'] ] ) ? $r['incidents'][ $incident['type'] ] : 0 ) + 1;
				}
			}
		}
		uasort(
			$r['livreurs'],
			function ( $a, $b ) {
				return ( $b['livraisons'] + $b['reprises'] ) <=> ( $a['livraisons'] + $a['reprises'] );
			}
		);
		arsort( $r['incidents'] );
		return $r;
	}

	/**
	 * Section de l'onglet Statistiques (bureau).
	 */
	public static function afficher() {
		if ( ! ETR_Modules::actif( 'pilotage_terrain' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		$r     = self::calculer();
		$taux  = function ( $n ) use ( $r ) {
			return $r['sortis'] ? number_format_i18n( 100 * $n / $r['sortis'], 1 ) . ' %' : '–';
		};
		$types = ETR_Operations::types_incident();
		echo '<div class="etr-stats etr-stats--terrain"><section class="etr-stats__section" aria-labelledby="etr-stats-terrain"><div class="etr-stats__entete"><div><h2 id="etr-stats-terrain" class="etr-section-titre">' . esc_html__( 'Terrain, 12 derniers mois', 'espace-traiteur' ) . '</h2><p>' . esc_html__( 'Livraisons et reprises enregistrées dans l’application, casse et manquants constatés aux reprises, incidents signalés.', 'espace-traiteur' ) . '</p></div></div>';
		echo '<dl class="etr-stats__indicateurs">';
		$indicateurs = array(
			array( number_format_i18n( $r['reprises'] ), __( 'reprises enregistrées', 'espace-traiteur' ) ),
			array( $taux( $r['casses'] ), __( 'du matériel repris est cassé', 'espace-traiteur' ) ),
			array( $taux( $r['manquants'] ), __( 'du matériel repris manque', 'espace-traiteur' ) ),
			array( number_format_i18n( array_sum( $r['incidents'] ) ), __( 'incidents signalés', 'espace-traiteur' ) ),
		);
		foreach ( $indicateurs as $ind ) {
			echo '<div><dt>' . esc_html( $ind[1] ) . '</dt><dd>' . esc_html( $ind[0] ) . '</dd></div>';
		}
		echo '</dl>';
		if ( $r['livreurs'] ) {
			echo '<div class="etr-table-cadre" tabindex="0" role="region" aria-label="' . esc_attr__( 'Activité par livreur', 'espace-traiteur' ) . '"><table class="etr-table etr-stats__table"><thead><tr><th scope="col">' . esc_html__( 'Livreur', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Livraisons', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Reprises', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
			foreach ( $r['livreurs'] as $user_id => $n ) {
				$user = get_userdata( $user_id );
				echo '<tr><td>' . esc_html( $user ? etr_nom( $user ) : __( 'Compte supprimé', 'espace-traiteur' ) ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $n['livraisons'] ) ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $n['reprises'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		if ( $r['incidents'] ) {
			echo '<ul class="etr-liste-simple etr-stats__incidents">';
			foreach ( $r['incidents'] as $type => $n ) {
				echo '<li>' . esc_html( ( isset( $types[ $type ] ) ? $types[ $type ] : $type ) . ' : ' . number_format_i18n( $n ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</section></div>';
	}
}
