<?php
/**
 * Migrations de la base de données après une mise à jour du plugin.
 *
 * Principe : la version des données est enregistrée dans l'option « etr_version ».
 * Quand les fichiers du plugin sont plus récents (ETR_VERSION), les étapes manquantes
 * sont exécutées une seule fois, dans l'ordre, au premier chargement de WordPress
 * (ou par la commande « wp espace-traiteur migrer » lancée au déploiement).
 *
 * Règles pour écrire une étape :
 * - elle doit pouvoir être relancée sans dégât (si elle échoue au milieu, elle sera rejouée) ;
 * - elle renvoie true, un WP_Error en cas d'échec, ou 'a_suivre' si elle traite par lots
 *   et n'a pas fini (elle sera rappelée jusqu'à renvoyer true) ;
 * - de préférence « additive » : ajouter avant de supprimer, pour garder un retour arrière possible.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exécution des migrations.
 */
class ETR_Migrations {

	const OPTION_VERSION  = 'etr_version';
	const OPTION_JOURNAL  = 'etr_journal_migrations';
	const OPTION_VERROU   = 'etr_migration_verrou';
	const OPTION_ECHEC    = 'etr_migration_echec';
	const DUREE_VERROU    = 600;
	const DELAI_REPRISE   = 900;
	const EVENEMENT_SUITE = 'etr_migration_suite';
	const TAILLE_JOURNAL  = 30;

	/**
	 * Hooks.
	 */
	public static function init() {
		// Après l'enregistrement des types de contenus (init, priorité 10).
		add_action( 'init', array( __CLASS__, 'peut_etre_migrer' ), 20 );
		add_action( self::EVENEMENT_SUITE, array( __CLASS__, 'executer_cron' ) );
		add_action( 'admin_notices', array( __CLASS__, 'avis_echec' ) );
		add_action( 'admin_post_etr_relancer_migration', array( __CLASS__, 'relancer' ) );
	}

	/**
	 * Liste des étapes, par version (ordre croissant).
	 *
	 * Pour ajouter une migration : ajouter une entrée « 'x.y.z' => array( description, callback ) »
	 * et passer ETR_VERSION à x.y.z (ou plus).
	 *
	 * @return array<string,array{description:string,callback:callable}>
	 */
	public static function etapes() {
		$etapes = array(
			'1.2.0' => array(
				'description' => __( 'Rôle Traiteur : accès limité à l’espace traiteur (retrait du droit « read »).', 'espace-traiteur' ),
				'callback'    => array( 'ETR_Installer', 'roles' ),
			),
		);
		return (array) apply_filters( 'etr_migrations', $etapes );
	}

	/**
	 * Version des données en base.
	 *
	 * @return string
	 */
	public static function version_bdd() {
		return (string) get_option( self::OPTION_VERSION, '0' );
	}

	/**
	 * Faut-il migrer ?
	 *
	 * @return bool
	 */
	public static function a_faire() {
		return version_compare( self::version_bdd(), ETR_VERSION, '<' );
	}

	/**
	 * Étapes restant à exécuter, dans l'ordre.
	 *
	 * @return array
	 */
	public static function etapes_en_attente() {
		$version = self::version_bdd();
		$etapes  = array();
		foreach ( self::etapes() as $cible => $etape ) {
			if ( version_compare( $cible, $version, '>' ) && version_compare( $cible, ETR_VERSION, '<=' ) ) {
				$etapes[ $cible ] = $etape;
			}
		}
		uksort( $etapes, 'version_compare' );
		return $etapes;
	}

	/**
	 * Migration automatique au chargement, si nécessaire.
	 */
	public static function peut_etre_migrer() {
		if ( ! self::a_faire() || wp_installing() ) {
			return;
		}
		// Après un échec, on n'insiste pas à chaque page : nouvel essai après 15 minutes.
		$echec = get_option( self::OPTION_ECHEC );
		if ( is_array( $echec ) && ( time() - (int) $echec['date'] ) < self::DELAI_REPRISE ) {
			return;
		}
		self::executer( 'automatique' );
	}

	/**
	 * Reprise d'une migration par lots (WP-Cron).
	 */
	public static function executer_cron() {
		if ( self::a_faire() ) {
			self::executer( 'tâche planifiée' );
		}
	}

	/**
	 * Exécute les étapes en attente.
	 *
	 * @param string $source Origine (automatique, wp-cli, activation…), pour le journal.
	 * @return true|WP_Error|string true si tout est fait, WP_Error en cas d'échec,
	 *                             'a_suivre' si un traitement par lots continue, 'verrouille' si une autre migration tourne.
	 */
	public static function executer( $source ) {
		if ( ! self::prendre_verrou() ) {
			return 'verrouille';
		}

		$cible = '';
		try {
			foreach ( self::etapes_en_attente() as $cible => $etape ) {
				$resultat = call_user_func( $etape['callback'] );

				if ( is_wp_error( $resultat ) ) {
					throw new RuntimeException( $resultat->get_error_message() );
				}
				if ( 'a_suivre' === $resultat ) {
					self::journaliser( $cible, __( 'Traitement par lots en cours…', 'espace-traiteur' ), 'en_cours', $source );
					if ( ! wp_next_scheduled( self::EVENEMENT_SUITE ) ) {
						wp_schedule_single_event( time() + 30, self::EVENEMENT_SUITE );
					}
					return 'a_suivre';
				}

				update_option( self::OPTION_VERSION, $cible );
				self::journaliser( $cible, $etape['description'], 'ok', $source );
			}

			if ( self::a_faire() ) {
				update_option( self::OPTION_VERSION, ETR_VERSION );
				/* translators: %s : version. */
				self::journaliser( ETR_VERSION, sprintf( __( 'Données à jour (version %s).', 'espace-traiteur' ), ETR_VERSION ), 'ok', $source );
			}
			delete_option( self::OPTION_ECHEC );
			wp_clear_scheduled_hook( self::EVENEMENT_SUITE );
			return true;
		} catch ( Throwable $e ) {
			$message = $e->getMessage();
			self::journaliser( $cible, $message, 'erreur', $source );
			update_option(
				self::OPTION_ECHEC,
				array(
					'version' => $cible,
					'message' => $message,
					'date'    => time(),
				),
				false
			);
			/* translators: 1 : version, 2 : message d'erreur. */
			return new WP_Error( 'etr_migration', sprintf( __( 'Échec de la migration %1$s : %2$s', 'espace-traiteur' ), $cible, $message ) );
		} finally {
			self::liberer_verrou();
		}
	}

	/**
	 * Prend le verrou (une seule migration à la fois).
	 *
	 * add_option() échoue si l'option existe déjà : c'est ce qui rend la prise du verrou atomique.
	 *
	 * @return bool
	 */
	private static function prendre_verrou() {
		if ( add_option( self::OPTION_VERROU, time(), '', 'no' ) ) {
			return true;
		}
		// Verrou abandonné (processus interrompu) : on le reprend après 10 minutes.
		$depuis = (int) get_option( self::OPTION_VERROU );
		if ( $depuis && ( time() - $depuis ) > self::DUREE_VERROU ) {
			delete_option( self::OPTION_VERROU );
			return (bool) add_option( self::OPTION_VERROU, time(), '', 'no' );
		}
		return false;
	}

	/**
	 * Libère le verrou.
	 */
	private static function liberer_verrou() {
		delete_option( self::OPTION_VERROU );
	}

	/**
	 * Verrou en place ?
	 *
	 * @return int Horodatage de prise du verrou, 0 si libre.
	 */
	public static function verrou() {
		return (int) get_option( self::OPTION_VERROU );
	}

	/**
	 * Ajoute une ligne au journal (les 30 dernières sont gardées).
	 *
	 * @param string $version Étape.
	 * @param string $message Message.
	 * @param string $statut  ok, erreur ou en_cours.
	 * @param string $source  Origine.
	 */
	private static function journaliser( $version, $message, $statut, $source ) {
		$journal   = self::journal();
		$journal[] = array(
			'date'    => time(),
			'version' => (string) $version,
			'message' => (string) $message,
			'statut'  => $statut,
			'source'  => (string) $source,
		);
		update_option( self::OPTION_JOURNAL, array_slice( $journal, -self::TAILLE_JOURNAL ), false );
	}

	/**
	 * Journal des migrations (du plus ancien au plus récent).
	 *
	 * @return array[]
	 */
	public static function journal() {
		$journal = get_option( self::OPTION_JOURNAL, array() );
		return is_array( $journal ) ? $journal : array();
	}

	/**
	 * Dernier échec, s'il y en a un.
	 *
	 * @return array|null
	 */
	public static function echec() {
		$echec = get_option( self::OPTION_ECHEC );
		return is_array( $echec ) ? $echec : null;
	}

	/**
	 * Avertit l'équipe dans l'administration si une migration a échoué.
	 */
	public static function avis_echec() {
		$echec = self::echec();
		if ( ! $echec || ! current_user_can( 'gerer_espace_traiteur' ) ) {
			return;
		}
		$relancer = wp_nonce_url( admin_url( 'admin-post.php?action=etr_relancer_migration' ), 'etr_relancer_migration' );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Espace traiteur : la mise à jour de la base de données a échoué.', 'espace-traiteur' ) . '</strong> ';
		/* translators: 1 : version, 2 : message. */
		echo esc_html( sprintf( __( 'Étape %1$s : %2$s', 'espace-traiteur' ), $echec['version'], $echec['message'] ) );
		echo ' <a href="' . esc_url( $relancer ) . '">' . esc_html__( 'Relancer maintenant', 'espace-traiteur' ) . '</a></p></div>';
	}

	/**
	 * Relance manuelle depuis l'administration.
	 */
	public static function relancer() {
		check_admin_referer( 'etr_relancer_migration' );
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ) );
		}
		$resultat = self::executer( 'relance manuelle' );
		$etat     = true === $resultat ? 'ok' : ( is_wp_error( $resultat ) ? 'erreur' : $resultat );
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'etr_demande', 'page' => 'etr-reglages', 'etr_migration' => $etat ), admin_url( 'edit.php' ) ) . '#etr-mises-a-jour' );
		exit;
	}
}
