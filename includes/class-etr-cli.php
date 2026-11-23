<?php
/**
 * Commandes WP-CLI : wp espace-traiteur …
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gère l'espace traiteur en ligne de commande.
 */
class ETR_CLI {

	/**
	 * Met à jour la base de données après une mise à jour du plugin.
	 *
	 * Exécute les étapes de migration en attente, y compris les traitements par lots
	 * jusqu'à leur fin. Code de sortie non nul en cas d'échec (utile au déploiement).
	 *
	 * ## EXAMPLES
	 *
	 *     wp espace-traiteur migrer
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function migrer( $args, $assoc_args ) {
		if ( ! ETR_Migrations::a_faire() ) {
			WP_CLI::success( sprintf( 'Rien à faire : données déjà en version %s.', ETR_Migrations::version_bdd() ) );
			return;
		}

		foreach ( ETR_Migrations::etapes_en_attente() as $version => $etape ) {
			WP_CLI::log( sprintf( 'À exécuter : %s — %s', $version, $etape['description'] ) );
		}

		for ( $tour = 0; $tour < 1000; $tour++ ) {
			$resultat = ETR_Migrations::executer( 'wp-cli' );

			if ( true === $resultat ) {
				WP_CLI::success( sprintf( 'Base de données à jour (version %s).', ETR_Migrations::version_bdd() ) );
				return;
			}
			if ( is_wp_error( $resultat ) ) {
				WP_CLI::error( $resultat->get_error_message() );
			}
			if ( 'verrouille' === $resultat ) {
				WP_CLI::error( 'Une migration est déjà en cours (verrou posé). Réessayez dans quelques minutes, ou vérifiez avec « wp espace-traiteur statut ».' );
			}
			// 'a_suivre' : lot suivant immédiatement.
			WP_CLI::log( 'Lot traité, suite…' );
		}
		WP_CLI::error( 'Migration interrompue : trop de lots. Relancez la commande.' );
	}

	/**
	 * Affiche l'état : versions, étapes en attente, verrou, journal.
	 *
	 * ## EXAMPLES
	 *
	 *     wp espace-traiteur statut
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function statut( $args, $assoc_args ) {
		WP_CLI::log( sprintf( 'Version des fichiers : %s', ETR_VERSION ) );
		WP_CLI::log( sprintf( 'Version des données  : %s', ETR_Migrations::version_bdd() ) );

		if ( version_compare( ETR_Migrations::version_bdd(), ETR_VERSION, '>' ) ) {
			WP_CLI::warning( 'Les données sont plus récentes que les fichiers (retour à une ancienne version du plugin ?).' );
		}

		$attente = ETR_Migrations::etapes_en_attente();
		WP_CLI::log( $attente ? 'Étapes en attente   : ' . implode( ', ', array_keys( $attente ) ) : 'Étapes en attente   : aucune' );

		$verrou = ETR_Migrations::verrou();
		WP_CLI::log( 'Verrou              : ' . ( $verrou ? 'posé depuis ' . human_time_diff( $verrou ) : 'libre' ) );

		$echec = ETR_Migrations::echec();
		if ( $echec ) {
			WP_CLI::warning( sprintf( 'Dernier échec : étape %s — %s', $echec['version'], $echec['message'] ) );
		}

		$journal = array_reverse( ETR_Migrations::journal() );
		if ( $journal ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Journal (du plus récent au plus ancien) :' );
			$lignes = array();
			foreach ( array_slice( $journal, 0, 10 ) as $ligne ) {
				$lignes[] = array(
					'date'    => wp_date( 'Y-m-d H:i', $ligne['date'] ),
					'version' => $ligne['version'],
					'statut'  => $ligne['statut'],
					'source'  => $ligne['source'],
					'message' => $ligne['message'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $lignes, array( 'date', 'version', 'statut', 'source', 'message' ) );
		}
	}
}
