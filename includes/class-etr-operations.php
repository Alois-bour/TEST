<?php
/**
 * Opérations du terrain : préparation, colis et scans, livraison signée, reprise.
 *
 * Cycle d'une commande validée :
 * 1. préparation : les quantités sont cochées sur le téléphone, le nombre de colis est saisi,
 *    puis la commande est marquée « prête » (les livreurs sont prévenus) ;
 * 2. étiquettes QR (une par colis), scannées au chargement (« sortie ») ;
 * 3. livraison : signature du client, photos → statut « Livrée » ;
 * 4. reprise : quantités rendues et casse, photos, colis scannés au retour → statut « Clôturée »
 *    s'il ne manque rien, sinon la demande reste « Livrée » et le bureau est prévenu des écarts.
 *
 * Les droits sont vérifiés ici (API) : préparation par les préparateurs, livraison et
 * reprise par les livreurs (le bureau peut tout faire), uniquement sur les demandes visibles.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Opérations du terrain.
 */
class ETR_Operations {

	const META_PREPA     = '_etr_preparation';
	const META_COLIS     = '_etr_colis';
	const META_LIVRAISON = '_etr_livraison';
	const META_REPRISE   = '_etr_reprise';
	const META_NOTES     = '_etr_notes';
	const META_INCIDENTS = '_etr_incidents';
	const MAX_PHOTOS     = 8;
	const MAX_COLIS      = 200;
	const HEURE_RAPPEL   = 17;
	const VERROU_DUREE   = 30;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'etr_routes_api_equipe', array( __CLASS__, 'routes_autorisees' ) );
		add_action( 'etr_rappels_horaires', array( __CLASS__, 'rappels_veille' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Verrou par demande                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Exécute une opération seule sur une demande : si deux téléphones enregistrent en même temps
	 * (scans, livraison renvoyée), le second attend que le premier ait fini, puis relit l'état.
	 *
	 * @param int      $id        Demande.
	 * @param callable $operation Opération (retourne une réponse ou un WP_Error).
	 * @return mixed
	 */
	private static function sous_verrou( $id, $operation ) {
		global $wpdb;
		$nom  = 'etr_verrou_' . (int) $id;
		$fin  = microtime( true ) + 8;
		$pris = self::prendre_verrou( $nom );
		while ( ! $pris && microtime( true ) < $fin ) {
			usleep( 150000 );
			$pris = self::prendre_verrou( $nom );
		}
		if ( ! $pris ) {
			return new WP_Error( 'etr_occupe', __( 'Un autre enregistrement est en cours sur cette commande. Réessayez dans un instant.', 'espace-traiteur' ), array( 'status' => 409 ) );
		}
		try {
			wp_cache_delete( $id, 'post_meta' ); // État relu depuis la base, même avec un cache d'objets.
			return call_user_func( $operation );
		} finally {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $nom, 'option_value' => $pris ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Prend le verrou (insertion atomique d'une ligne d'option) ; un verrou abandonné expire.
	 *
	 * @param string $nom Nom du verrou.
	 * @return string|false Jeton du verrou, ou false s'il est déjà pris.
	 */
	private static function prendre_verrou( $nom ) {
		global $wpdb;
		$jeton = time() . '-' . wp_generate_password( 10, false );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- verrou : l'API des options n'est pas atomique.
		if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $nom, $jeton ) ) ) {
			return $jeton;
		}
		$actuel = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $nom ) );
		if ( '' !== $actuel && (int) $actuel < time() - self::VERROU_DUREE ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $nom, 'option_value' => $actuel ) ); // Requête interrompue : verrou libéré.
		}
		// phpcs:enable
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* État d'une demande                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Préparation : quantités prêtes par ligne, colis, statut.
	 *
	 * @param int $id Demande.
	 * @return array { lignes: int[], colis, prete, par, le, total, prepare, complete }
	 */
	public static function preparation( $id ) {
		$brut   = get_post_meta( $id, self::META_PREPA, true );
		$brut   = is_array( $brut ) ? $brut : array();
		$faites = array();
		$total  = 0;
		foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
			$demande          = max( 0, (int) $ligne['quantite'] );
			$total           += $demande;
			$faites[ $index ] = isset( $brut['lignes'][ $index ] ) ? min( $demande, max( 0, (int) $brut['lignes'][ $index ] ) ) : 0;
		}
		$prepare = array_sum( $faites );
		return array(
			'lignes'   => $faites,
			'colis'    => isset( $brut['colis'] ) ? max( 0, (int) $brut['colis'] ) : 0,
			'prete'    => ! empty( $brut['prete'] ),
			'par'      => isset( $brut['par'] ) ? (int) $brut['par'] : 0,
			'le'       => isset( $brut['le'] ) ? (int) $brut['le'] : 0,
			'total'    => $total,
			'prepare'  => $prepare,
			'complete' => $prepare >= $total,
		);
	}

	/**
	 * Enregistre la préparation (uniquement les champs connus).
	 *
	 * @param int   $id     Demande.
	 * @param array $prepa  État (voir preparation()).
	 */
	private static function enregistrer_preparation( $id, $prepa ) {
		update_post_meta(
			$id,
			self::META_PREPA,
			array(
				'lignes' => array_map( 'intval', $prepa['lignes'] ),
				'colis'  => (int) $prepa['colis'],
				'prete'  => (bool) $prepa['prete'],
				'par'    => (int) $prepa['par'],
				'le'     => (int) $prepa['le'],
			)
		);
	}

	/**
	 * Scans des colis : numéro => { sortie, sortie_par, retour, retour_par }.
	 *
	 * @param int $id Demande.
	 * @return array
	 */
	public static function scans( $id ) {
		$scans = get_post_meta( $id, self::META_COLIS, true );
		return is_array( $scans ) ? $scans : array();
	}

	/**
	 * Nombre de colis chargés et revenus.
	 *
	 * @param int $id Demande.
	 * @return array { colis, sortis, revenus }
	 */
	public static function bilan_colis( $id ) {
		$colis   = self::preparation( $id )['colis'];
		$sortis  = 0;
		$revenus = 0;
		foreach ( self::scans( $id ) as $numero => $scan ) {
			if ( $numero >= 1 && $numero <= $colis ) {
				$sortis  += empty( $scan['sortie'] ) ? 0 : 1;
				$revenus += empty( $scan['retour'] ) ? 0 : 1;
			}
		}
		return array(
			'colis'   => $colis,
			'sortis'  => $sortis,
			'revenus' => $revenus,
		);
	}

	/**
	 * Compte rendu de livraison.
	 *
	 * @param int $id Demande.
	 * @return array Vide si pas encore livrée par l'application.
	 */
	public static function livraison( $id ) {
		$livraison = get_post_meta( $id, self::META_LIVRAISON, true );
		return is_array( $livraison ) ? $livraison : array();
	}

	/**
	 * Compte rendu de reprise.
	 *
	 * @param int $id Demande.
	 * @return array Vide si pas encore reprise par l'application.
	 */
	public static function reprise( $id ) {
		$reprise = get_post_meta( $id, self::META_REPRISE, true );
		return is_array( $reprise ) ? $reprise : array();
	}

	/**
	 * Jours de livraison et de reprise (comme le planning : début et fin de la période).
	 *
	 * @param int $id Demande.
	 * @return array{0:string,1:string}
	 */
	public static function jours( $id ) {
		return etr_periode_demande( $id );
	}

	/**
	 * Livraisons et reprises d'un jour.
	 *
	 * @param string $jour AAAA-MM-JJ.
	 * @return array { livraisons: int[], reprises: int[] }
	 */
	public static function tournee( $jour ) {
		$ids = get_posts(
			array(
				'post_type'     => 'etr_demande',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- quelques dizaines de demandes actives.
					array(
						'key'     => '_etr_statut',
						'value'   => array( 'validee', 'livree' ),
						'compare' => 'IN',
					),
				),
			)
		);
		update_meta_cache( 'post', $ids );
		$tournee = array(
			'livraisons' => array(),
			'reprises'   => array(),
		);
		foreach ( $ids as $id ) {
			list( $livraison, $reprise ) = self::jours( $id );
			$statut                     = etr_get_statut( $id );
			if ( 'validee' === $statut && $livraison === $jour ) {
				$tournee['livraisons'][] = $id;
			} elseif ( 'livree' === $statut && $reprise === $jour ) {
				$tournee['reprises'][] = $id;
			}
		}
		return $tournee;
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Quantité préparée d'une ligne, ou nombre de colis.
	 *
	 * @param int      $id       Demande.
	 * @param int|null $index    Ligne (null : seulement les colis).
	 * @param int|null $quantite Quantité préparée.
	 * @param int|null $colis    Nombre de colis.
	 * @return array État de la préparation.
	 */
	public static function preparer( $id, $index, $quantite, $colis ) {
		$prepa  = self::preparation( $id );
		$lignes = etr_get_lignes( $id );
		$baisse = false;
		if ( null !== $index && isset( $lignes[ $index ] ) && null !== $quantite ) {
			$nouvelle                  = min( (int) $lignes[ $index ]['quantite'], max( 0, (int) $quantite ) );
			$baisse                    = $nouvelle < $prepa['lignes'][ $index ];
			$prepa['lignes'][ $index ] = $nouvelle;
		}
		if ( null !== $colis ) {
			$prepa['colis'] = min( self::MAX_COLIS, max( 0, (int) $colis ) );
		}
		$prepa['prepare']  = array_sum( $prepa['lignes'] );
		$prepa['complete'] = $prepa['prepare'] >= $prepa['total'];
		// Une commande prête (même incomplète, après confirmation) ne se rouvre que si l'on retire du matériel.
		if ( $prepa['prete'] && $baisse ) {
			$prepa['prete'] = false;
			ETR_Historique::ajouter( $id, 'preparation', __( 'Préparation rouverte : une quantité a été retirée', 'espace-traiteur' ) );
		}
		self::enregistrer_preparation( $id, $prepa );
		return $prepa;
	}

	/**
	 * Marque la commande prête (ou la rouvre).
	 *
	 * @param int  $id    Demande.
	 * @param bool $prete Prête ?
	 * @return array État de la préparation.
	 */
	public static function marquer_prete( $id, $prete ) {
		$prepa = self::preparation( $id );
		if ( $prete === $prepa['prete'] ) {
			return $prepa;
		}
		$prepa['prete'] = $prete;
		$prepa['par']   = get_current_user_id();
		$prepa['le']    = time();
		self::enregistrer_preparation( $id, $prepa );

		if ( ! $prete ) {
			ETR_Historique::ajouter( $id, 'preparation', __( 'Préparation rouverte', 'espace-traiteur' ) );
			return $prepa;
		}
		$manque = $prepa['total'] - $prepa['prepare'];
		$texte  = $manque
			/* translators: 1 : colis, 2 : articles manquants. */
			? sprintf( __( 'Commande prête (%1$s colis), incomplète : %2$s article(s) manquant(s)', 'espace-traiteur' ), number_format_i18n( $prepa['colis'] ), number_format_i18n( $manque ) )
			/* translators: %s : colis. */
			: sprintf( __( 'Commande prête (%s colis)', 'espace-traiteur' ), number_format_i18n( $prepa['colis'] ) );
		ETR_Historique::ajouter( $id, 'preparation', $texte );

		list( $jour ) = self::jours( $id );
		ETR_Push::notifier(
			'livreur',
			/* translators: %s : traiteur. */
			sprintf( __( 'Commande prête : %s', 'espace-traiteur' ), self::traiteur( $id ) ),
			/* translators: 1 : événement, 2 : date, 3 : colis. */
			sprintf( __( '%1$s · livraison le %2$s · %3$s colis', 'espace-traiteur' ), get_the_title( $id ), $jour ? etr_format_date( $jour ) : '—', number_format_i18n( $prepa['colis'] ) ) . ( $manque ? ' · ' . __( 'incomplète', 'espace-traiteur' ) : '' ),
			$id,
			'',
			'prete'
		);
		return $prepa;
	}

	/**
	 * Scan d'un colis au chargement (sortie) ou au retour.
	 *
	 * @param int    $id     Demande.
	 * @param int    $numero Colis (1 à N).
	 * @param string $mode   sortie | retour.
	 * @return array|WP_Error { deja, bilan }
	 */
	public static function scanner( $id, $numero, $mode ) {
		$statut = etr_get_statut( $id );
		$bilan  = self::bilan_colis( $id );
		if ( $numero < 1 || $numero > $bilan['colis'] ) {
			return new WP_Error( 'etr_colis', __( 'Ce colis ne fait pas partie de la commande (étiquette ancienne ?).', 'espace-traiteur' ), array( 'status' => 409 ) );
		}
		if ( 'sortie' === $mode && 'validee' !== $statut ) {
			return new WP_Error( 'etr_colis', __( 'Cette commande n’est pas à livrer (déjà livrée ou annulée).', 'espace-traiteur' ), array( 'status' => 409 ) );
		}
		if ( 'retour' === $mode && ! in_array( $statut, array( 'livree', 'cloturee' ), true ) ) {
			return new WP_Error( 'etr_colis', __( 'Cette commande n’a pas encore été livrée.', 'espace-traiteur' ), array( 'status' => 409 ) );
		}
		$scans = self::scans( $id );
		$deja  = ! empty( $scans[ $numero ][ $mode ] );
		if ( ! $deja ) {
			$scans[ $numero ]                  = isset( $scans[ $numero ] ) ? $scans[ $numero ] : array();
			$scans[ $numero ][ $mode ]         = time();
			$scans[ $numero ][ $mode . '_par' ] = get_current_user_id();
			update_post_meta( $id, self::META_COLIS, $scans );
			$bilan = self::bilan_colis( $id );
			$tous  = 'sortie' === $mode ? $bilan['sortis'] : $bilan['revenus'];
			if ( $tous === $bilan['colis'] ) {
				ETR_Historique::ajouter(
					$id,
					'colis',
					'sortie' === $mode
						/* translators: %s : nombre de colis. */
						? sprintf( __( 'Les %s colis sont chargés', 'espace-traiteur' ), number_format_i18n( $bilan['colis'] ) )
						/* translators: %s : nombre de colis. */
						: sprintf( __( 'Les %s colis sont revenus à l’entrepôt', 'espace-traiteur' ), number_format_i18n( $bilan['colis'] ) )
				);
			}
		}
		return array(
			'deja'  => $deja,
			'bilan' => $bilan,
		);
	}

	/**
	 * Livraison signée.
	 *
	 * @param int    $id         Demande.
	 * @param string $signataire Nom de la personne qui réceptionne.
	 * @param string $signature  Signature (data URL PNG).
	 * @param string $note       Remarque.
	 * @param array  $photos     Entrées de $_FILES.
	 * @param string $jeton      Jeton de l'envoi (un renvoi du même formulaire n'enregistre rien de plus).
	 * @return true|WP_Error
	 */
	public static function livrer( $id, $signataire, $signature, $note, $photos, $jeton = '' ) {
		if ( '' === $signataire ) {
			return new WP_Error( 'etr_livraison', __( 'Indiquez le nom de la personne qui réceptionne.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}
		$fichiers = self::enregistrer_fichiers( $id, 'livraison', $signature, $photos, true );
		if ( is_wp_error( $fichiers ) ) {
			return $fichiers;
		}
		update_post_meta(
			$id,
			self::META_LIVRAISON,
			array(
				'le'         => time(),
				'par'        => get_current_user_id(),
				'signataire' => $signataire,
				'signature'  => $fichiers['signature'],
				'photos'     => $fichiers['photos'],
				'note'       => $note,
				'jeton'      => $jeton,
			)
		);
		/* translators: %s : personne qui a signé. */
		ETR_Historique::ajouter( $id, 'livraison', sprintf( __( 'Livrée, réceptionnée et signée par %s', 'espace-traiteur' ), $signataire ) . ( $note ? ' — ' . $note : '' ) );
		etr_changer_statut( $id, 'livree' );
		ETR_Push::notifier(
			'bureau',
			/* translators: %s : traiteur. */
			sprintf( __( 'Livrée : %s', 'espace-traiteur' ), self::traiteur( $id ) ),
			/* translators: 1 : événement, 2 : signataire. */
			sprintf( __( '%1$s · signée par %2$s', 'espace-traiteur' ), get_the_title( $id ), $signataire ) . ( $fichiers['photos'] ? ' · ' . sprintf( _n( '%s photo', '%s photos', count( $fichiers['photos'] ), 'espace-traiteur' ), number_format_i18n( count( $fichiers['photos'] ) ) ) : '' ),
			$id,
			'',
			'livree'
		);
		return true;
	}

	/**
	 * Reprise du matériel : quantités rendues, casse, photos.
	 *
	 * @param int    $id         Demande.
	 * @param array  $saisie     index => { rendu, casse }.
	 * @param string $note       Remarque.
	 * @param array  $photos     Entrées de $_FILES.
	 * @param string $signataire Nom (facultatif).
	 * @param string $signature  Signature (facultative).
	 * @param string $jeton      Jeton de l'envoi (un renvoi du même formulaire n'enregistre rien de plus).
	 * @return array|WP_Error { manquants, casses, ecarts, cloturee }
	 */
	public static function reprendre( $id, $saisie, $note, $photos, $signataire, $signature, $jeton = '' ) {
		$lignes    = array();
		$manquants = 0;
		$casses    = 0;
		foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
			$demande = max( 0, (int) $ligne['quantite'] );
			$rendu   = isset( $saisie[ $index ]['rendu'] ) ? min( $demande, max( 0, (int) $saisie[ $index ]['rendu'] ) ) : $demande;
			$casse   = isset( $saisie[ $index ]['casse'] ) ? min( $rendu, max( 0, (int) $saisie[ $index ]['casse'] ) ) : 0;
			$lignes[ $index ] = array(
				'rendu' => $rendu,
				'casse' => $casse,
			);
			$manquants += $demande - $rendu;
			$casses    += $casse;
		}
		$fichiers = self::enregistrer_fichiers( $id, 'reprise', $signature, $photos, false );
		if ( is_wp_error( $fichiers ) ) {
			return $fichiers;
		}
		// Une reprise refaite (écarts retrouvés) remplace la précédente : ses fichiers sont supprimés.
		$ancienne = self::reprise( $id );
		foreach ( array_merge( isset( $ancienne['photos'] ) ? (array) $ancienne['photos'] : array(), empty( $ancienne['signature'] ) ? array() : array( $ancienne['signature'] ) ) as $nom ) {
			ETR_Fichiers::supprimer( $nom );
		}
		update_post_meta(
			$id,
			self::META_REPRISE,
			array(
				'le'         => time(),
				'par'        => get_current_user_id(),
				'lignes'     => $lignes,
				'manquants'  => $manquants,
				'casses'     => $casses,
				'signataire' => $signataire,
				'signature'  => $fichiers['signature'],
				'photos'     => $fichiers['photos'],
				'note'       => $note,
				'jeton'      => $jeton,
			)
		);

		$ecarts = self::texte_ecarts( $manquants, $casses );
		ETR_Historique::ajouter( $id, 'reprise', ( $ecarts ? sprintf( /* translators: %s : écarts. */ __( 'Matériel repris : %s', 'espace-traiteur' ), $ecarts ) : __( 'Matériel repris, complet et en bon état', 'espace-traiteur' ) ) . ( $note ? ' — ' . $note : '' ) );
		$cloturee = ! $ecarts && etr_changer_statut( $id, 'cloturee' );
		ETR_Push::notifier(
			'bureau',
			$ecarts
				/* translators: %s : traiteur. */
				? sprintf( __( 'Reprise avec écarts : %s', 'espace-traiteur' ), self::traiteur( $id ) )
				/* translators: %s : traiteur. */
				: sprintf( __( 'Matériel repris : %s', 'espace-traiteur' ), self::traiteur( $id ) ),
			get_the_title( $id ) . ' · ' . ( $ecarts ? $ecarts : ( $cloturee ? __( 'complet, demande clôturée', 'espace-traiteur' ) : __( 'complet', 'espace-traiteur' ) ) ),
			$id,
			'',
			'reprise'
		);
		return array(
			'manquants' => $manquants,
			'casses'    => $casses,
			'ecarts'    => '' !== $ecarts,
			'cloturee'  => $cloturee,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Notes internes et incidents                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Types d'incidents.
	 *
	 * @return string[]
	 */
	public static function types_incident() {
		return array(
			'casse'    => __( 'Casse', 'espace-traiteur' ),
			'retard'   => __( 'Retard', 'espace-traiteur' ),
			'acces'    => __( 'Accès impossible', 'espace-traiteur' ),
			'manquant' => __( 'Matériel manquant', 'espace-traiteur' ),
			'autre'    => __( 'Autre', 'espace-traiteur' ),
		);
	}

	/**
	 * Notes internes de l'équipe (jamais montrées au traiteur).
	 *
	 * @param int $id Demande.
	 * @return array[] { le, par, texte, jeton }
	 */
	public static function notes( $id ) {
		$notes = get_post_meta( $id, self::META_NOTES, true );
		return is_array( $notes ) ? $notes : array();
	}

	/**
	 * Ajoute une note (un renvoi du même jeton n'ajoute rien).
	 *
	 * @param int    $id    Demande.
	 * @param string $texte Note.
	 * @param string $jeton Jeton de l'envoi.
	 * @return array|WP_Error Note.
	 */
	public static function ajouter_note( $id, $texte, $jeton ) {
		if ( '' === trim( $texte ) ) {
			return new WP_Error( 'etr_note', __( 'La note est vide.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}
		$notes = self::notes( $id );
		foreach ( $notes as $note ) {
			if ( $jeton && isset( $note['jeton'] ) && $note['jeton'] === $jeton ) {
				return $note;
			}
		}
		$note    = array(
			'le'    => time(),
			'par'   => get_current_user_id(),
			'texte' => $texte,
			'jeton' => $jeton,
		);
		$notes[] = $note;
		update_post_meta( $id, self::META_NOTES, $notes );
		return $note;
	}

	/**
	 * Incidents d'une demande.
	 *
	 * @param int $id Demande.
	 * @return array[] { le, par, type, texte, photos, resolu, resolu_par, jeton }
	 */
	public static function incidents( $id ) {
		$incidents = get_post_meta( $id, self::META_INCIDENTS, true );
		return is_array( $incidents ) ? $incidents : array();
	}

	/**
	 * Nombre d'incidents non résolus.
	 *
	 * @param int $id Demande.
	 * @return int
	 */
	public static function incidents_ouverts( $id ) {
		return count(
			array_filter(
				self::incidents( $id ),
				function ( $incident ) {
					return empty( $incident['resolu'] );
				}
			)
		);
	}

	/**
	 * Signale un incident (photos facultatives) ; le bureau est prévenu.
	 *
	 * @param int    $id     Demande.
	 * @param string $type   Type (types_incident()).
	 * @param string $texte  Description.
	 * @param array  $photos Entrées de $_FILES.
	 * @param string $jeton  Jeton de l'envoi.
	 * @return true|WP_Error
	 */
	public static function signaler( $id, $type, $texte, $photos, $jeton ) {
		$types = self::types_incident();
		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'etr_incident', __( 'Choisissez le type d’incident.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( $texte ) ) {
			return new WP_Error( 'etr_incident', __( 'Décrivez l’incident en quelques mots.', 'espace-traiteur' ), array( 'status' => 400 ) );
		}
		$incidents = self::incidents( $id );
		foreach ( $incidents as $incident ) {
			if ( $jeton && isset( $incident['jeton'] ) && $incident['jeton'] === $jeton ) {
				return true; // Déjà reçu (renvoi après coupure).
			}
		}
		$fichiers = self::enregistrer_fichiers( $id, 'incident', '', $photos, false );
		if ( is_wp_error( $fichiers ) ) {
			return $fichiers;
		}
		$incidents[] = array(
			'le'         => time(),
			'par'        => get_current_user_id(),
			'type'       => $type,
			'texte'      => $texte,
			'photos'     => $fichiers['photos'],
			'resolu'     => 0,
			'resolu_par' => 0,
			'jeton'      => $jeton,
		);
		update_post_meta( $id, self::META_INCIDENTS, $incidents );
		/* translators: 1 : type, 2 : description. */
		ETR_Historique::ajouter( $id, 'incident', sprintf( __( 'Incident signalé (%1$s) : %2$s', 'espace-traiteur' ), $types[ $type ], $texte ) );
		ETR_Push::notifier(
			'bureau',
			/* translators: 1 : type, 2 : traiteur. */
			sprintf( __( 'Incident : %1$s — %2$s', 'espace-traiteur' ), $types[ $type ], self::traiteur( $id ) ),
			get_the_title( $id ) . ' · ' . $texte,
			$id,
			'',
			'incident'
		);
		return true;
	}

	/**
	 * Marque un incident résolu (bureau).
	 *
	 * @param int $id Demande.
	 * @param int $n  Rang de l'incident.
	 * @return true|WP_Error
	 */
	public static function resoudre( $id, $n ) {
		$incidents = self::incidents( $id );
		if ( ! isset( $incidents[ $n ] ) ) {
			return new WP_Error( 'etr_incident', __( 'Incident introuvable.', 'espace-traiteur' ), array( 'status' => 404 ) );
		}
		if ( empty( $incidents[ $n ]['resolu'] ) ) {
			$incidents[ $n ]['resolu']     = time();
			$incidents[ $n ]['resolu_par'] = get_current_user_id();
			update_post_meta( $id, self::META_INCIDENTS, $incidents );
			$types = self::types_incident();
			/* translators: %s : type. */
			ETR_Historique::ajouter( $id, 'incident', sprintf( __( 'Incident résolu (%s)', 'espace-traiteur' ), isset( $types[ $incidents[ $n ]['type'] ] ) ? $types[ $incidents[ $n ]['type'] ] : '' ) );
		}
		return true;
	}

	/**
	 * « 2 manquants, 1 cassé ».
	 *
	 * @param int $manquants Manquants.
	 * @param int $casses    Cassés.
	 * @return string Vide si aucun écart.
	 */
	public static function texte_ecarts( $manquants, $casses ) {
		$parties = array();
		if ( $manquants ) {
			/* translators: %s : nombre. */
			$parties[] = sprintf( _n( '%s manquant', '%s manquants', $manquants, 'espace-traiteur' ), number_format_i18n( $manquants ) );
		}
		if ( $casses ) {
			/* translators: %s : nombre. */
			$parties[] = sprintf( _n( '%s cassé', '%s cassés', $casses, 'espace-traiteur' ), number_format_i18n( $casses ) );
		}
		return implode( ', ', $parties );
	}

	/**
	 * Enregistre la signature et les photos ; tout ou rien.
	 *
	 * @param int    $id        Demande.
	 * @param string $type      livraison | reprise.
	 * @param string $signature Data URL (ou '').
	 * @param array  $photos    Entrées de $_FILES.
	 * @param bool   $requise   Signature obligatoire.
	 * @return array|WP_Error { signature, photos }
	 */
	private static function enregistrer_fichiers( $id, $type, $signature, $photos, $requise ) {
		if ( count( $photos ) > self::MAX_PHOTOS ) {
			/* translators: %s : nombre maximum. */
			return new WP_Error( 'etr_photos', sprintf( __( '%s photos au plus.', 'espace-traiteur' ), number_format_i18n( self::MAX_PHOTOS ) ), array( 'status' => 400 ) );
		}
		$noms = array();
		$sig  = '';
		if ( '' !== $signature || $requise ) {
			$sig = ETR_Fichiers::enregistrer_signature( $signature, $id, $type );
			if ( is_wp_error( $sig ) ) {
				$sig->add_data( array( 'status' => 400 ) );
				return $sig;
			}
		}
		foreach ( $photos as $photo ) {
			$nom = ETR_Fichiers::enregistrer_envoi( $photo, $id, $type );
			if ( is_wp_error( $nom ) ) {
				foreach ( array_merge( $noms, $sig ? array( $sig ) : array() ) as $deja ) {
					ETR_Fichiers::supprimer( $deja );
				}
				$nom->add_data( array( 'status' => 400 ) );
				return $nom;
			}
			$noms[] = $nom;
		}
		return array(
			'signature' => $sig,
			'photos'    => $noms,
		);
	}

	/**
	 * Nom du traiteur.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function traiteur( $id ) {
		return etr_nom( get_userdata( (int) get_post_field( 'post_author', $id ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rappels de la veille                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Vers 17 h : tournée du lendemain aux livreurs, commandes à finir aux préparateurs.
	 */
	public static function rappels_veille() {
		$maintenant = current_datetime();
		$jour       = $maintenant->format( 'Y-m-d' );
		if ( (int) $maintenant->format( 'G' ) < self::HEURE_RAPPEL || get_option( 'etr_rappel_veille' ) === $jour ) {
			return;
		}
		update_option( 'etr_rappel_veille', $jour, false );
		$demain  = $maintenant->modify( '+1 day' )->format( 'Y-m-d' );
		$tournee = self::tournee( $demain );
		$url     = ETR_Gestion::url(
			array(
				'vue'  => 'tournee',
				'jour' => $demain,
			)
		);

		$nb_l = count( $tournee['livraisons'] );
		$nb_r = count( $tournee['reprises'] );
		if ( $nb_l || $nb_r ) {
			$parties = array();
			if ( $nb_l ) {
				/* translators: %s : nombre. */
				$parties[] = sprintf( _n( '%s livraison', '%s livraisons', $nb_l, 'espace-traiteur' ), number_format_i18n( $nb_l ) );
			}
			if ( $nb_r ) {
				/* translators: %s : nombre. */
				$parties[] = sprintf( _n( '%s reprise', '%s reprises', $nb_r, 'espace-traiteur' ), number_format_i18n( $nb_r ) );
			}
			$noms = array_map( array( __CLASS__, 'traiteur' ), array_merge( $tournee['livraisons'], $tournee['reprises'] ) );
			/* translators: %s : « 2 livraisons, 1 reprise ». */
			ETR_Push::notifier( 'livreur', sprintf( __( 'Demain : %s', 'espace-traiteur' ), implode( ', ', $parties ) ), implode( ' · ', array_unique( $noms ) ), 0, $url, 'rappel' );
		}

		$a_finir = array_values(
			array_filter(
				$tournee['livraisons'],
				function ( $id ) {
					return ! ETR_Operations::preparation( $id )['prete'];
				}
			)
		);
		if ( $a_finir ) {
			ETR_Push::notifier(
				'preparateur',
				/* translators: %s : nombre. */
				sprintf( _n( 'À préparer pour demain : %s commande', 'À préparer pour demain : %s commandes', count( $a_finir ), 'espace-traiteur' ), number_format_i18n( count( $a_finir ) ) ),
				implode( ' · ', array_map( array( __CLASS__, 'traiteur' ), $a_finir ) ),
				1 === count( $a_finir ) ? $a_finir[0] : 0,
				1 === count( $a_finir ) ? '' : $url,
				'rappel'
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* API                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Le terrain a accès à ces routes.
	 *
	 * @param string[] $routes Préfixes.
	 * @return string[]
	 */
	public static function routes_autorisees( $routes ) {
		$routes[] = '/' . ETR_Push::API . '/terrain/';
		return $routes;
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		$base = '/terrain/(?P<id>\d+)';
		$def  = array(
			$base . '/preparation'       => array( 'api_preparer', 'preparateur' ),
			$base . '/preparation/prete' => array( 'api_prete', 'preparateur' ),
			$base . '/livraison'         => array( 'api_livrer', 'livreur' ),
			$base . '/reprise'           => array( 'api_reprendre', 'livreur' ),
			$base . '/note'              => array( 'api_note', 'equipe' ),
			$base . '/incident'          => array( 'api_incident', 'equipe' ),
			$base . '/incident/(?P<n>\d+)/resolu' => array( 'api_resoudre', 'bureau' ),
			'/terrain/scan'              => array( 'api_scanner', 'terrain' ),
		);
		foreach ( $def as $route => $config ) {
			$droit = $config[1];
			register_rest_route(
				ETR_Push::API,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $config[0] ),
					'permission_callback' => function () use ( $droit ) {
						return ETR_Operations::autorise( $droit );
					},
				)
			);
		}
	}

	/**
	 * Droit d'agir (le bureau peut tout faire).
	 *
	 * @param string $droit preparateur | livreur | terrain | equipe | bureau.
	 * @return bool
	 */
	public static function autorise( $droit ) {
		if ( ! is_user_logged_in() || ! ETR_Equipe::acces() ) {
			return false;
		}
		if ( 'preparateur' === $droit ) {
			return ETR_Equipe::preparateur();
		}
		if ( 'livreur' === $droit ) {
			return ETR_Equipe::livreur();
		}
		if ( 'equipe' === $droit ) {
			return true; // Toute l'équipe (accès déjà vérifié).
		}
		if ( 'bureau' === $droit ) {
			return ETR_Equipe::bureau();
		}
		return ETR_Equipe::preparateur() || ETR_Equipe::livreur();
	}

	/**
	 * Demande visible et au bon statut.
	 *
	 * @param int      $id      Demande.
	 * @param string[] $statuts Statuts permis.
	 * @return true|WP_Error
	 */
	private static function verifier( $id, $statuts ) {
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			return new WP_Error( 'etr_demande', __( 'Demande introuvable.', 'espace-traiteur' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( etr_get_statut( $id ), $statuts, true ) ) {
			/* translators: %s : statut. */
			return new WP_Error( 'etr_statut', sprintf( __( 'Impossible : la demande est « %s ». Rechargez la page.', 'espace-traiteur' ), etr_statuts()[ etr_get_statut( $id ) ] ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * POST preparation : { index, quantite } et/ou { colis }.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_preparer( $requete ) {
		$id       = (int) $requete['id'];
		$index    = $requete->get_param( 'index' );
		$quantite = $requete->get_param( 'quantite' );
		$colis    = $requete->get_param( 'colis' );
		return self::sous_verrou(
			$id,
			function () use ( $id, $index, $quantite, $colis ) {
				$ok = self::verifier( $id, array( 'validee' ) );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				return rest_ensure_response(
					self::preparer(
						$id,
						null === $index ? null : (int) $index,
						null === $quantite ? null : (int) $quantite,
						null === $colis ? null : (int) $colis
					)
				);
			}
		);
	}

	/**
	 * POST preparation/prete : { prete }.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_prete( $requete ) {
		$id    = (int) $requete['id'];
		$prete = rest_sanitize_boolean( $requete->get_param( 'prete' ) );
		return self::sous_verrou(
			$id,
			function () use ( $id, $prete ) {
				$ok = self::verifier( $id, array( 'validee' ) );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				if ( $prete && self::preparation( $id )['colis'] < 1 ) {
					return new WP_Error( 'etr_colis', __( 'Indiquez le nombre de colis avant de marquer la commande prête.', 'espace-traiteur' ), array( 'status' => 400 ) );
				}
				return rest_ensure_response( self::marquer_prete( $id, $prete ) );
			}
		);
	}

	/**
	 * POST scan : { d, c, mode }.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_scanner( $requete ) {
		$id   = (int) $requete->get_param( 'd' );
		$mode = 'retour' === $requete->get_param( 'mode' ) ? 'retour' : 'sortie';
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			return new WP_Error( 'etr_demande', __( 'Étiquette inconnue : la demande n’existe pas ou ne vous est pas visible.', 'espace-traiteur' ), array( 'status' => 404 ) );
		}
		$numero   = (int) $requete->get_param( 'c' );
		$resultat = self::sous_verrou(
			$id,
			function () use ( $id, $numero, $mode ) {
				return self::scanner( $id, $numero, $mode );
			}
		);
		if ( is_wp_error( $resultat ) ) {
			return $resultat;
		}
		list( $livraison ) = self::jours( $id );
		return rest_ensure_response(
			array_merge(
				$resultat,
				array(
					'demande'   => $id,
					'numero'    => (int) $requete->get_param( 'c' ),
					'mode'      => $mode,
					'titre'     => get_the_title( $id ),
					'traiteur'  => self::traiteur( $id ),
					'livraison' => $livraison ? etr_format_date( $livraison ) : '',
					'url'       => ETR_Gestion::url(
						array(
							'vue' => 'demande',
							'id'  => $id,
						)
					),
				)
			)
		);
	}

	/**
	 * POST livraison (multipart) : signataire, signature, note, photos[].
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_livrer( $requete ) {
		$id    = (int) $requete['id'];
		$jeton = self::jeton( $requete );
		$url   = ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => 'livree' ) );
		return self::sous_verrou(
			$id,
			function () use ( $id, $jeton, $url, $requete ) {
				// Même formulaire renvoyé (réponse perdue en route) : déjà enregistré, rien de plus.
				$deja = self::livraison( $id );
				if ( $jeton && isset( $deja['jeton'] ) && $deja['jeton'] === $jeton ) {
					return rest_ensure_response( array( 'url' => $url ) );
				}
				$ok = self::verifier( $id, array( 'validee' ) );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$resultat = self::livrer(
					$id,
					sanitize_text_field( (string) $requete->get_param( 'signataire' ) ),
					(string) $requete->get_param( 'signature' ),
					sanitize_textarea_field( (string) $requete->get_param( 'note' ) ),
					self::photos( $requete ),
					$jeton
				);
				return is_wp_error( $resultat ) ? $resultat : rest_ensure_response( array( 'url' => $url ) );
			}
		);
	}

	/**
	 * POST reprise (multipart) : lignes[i][rendu|casse], note, photos[], signataire, signature.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_reprendre( $requete ) {
		$id    = (int) $requete['id'];
		$jeton = self::jeton( $requete );
		return self::sous_verrou(
			$id,
			function () use ( $id, $jeton, $requete ) {
				$deja = self::reprise( $id );
				if ( $jeton && isset( $deja['jeton'] ) && $deja['jeton'] === $jeton ) {
					$resultat = array(
						'manquants' => (int) $deja['manquants'],
						'casses'    => (int) $deja['casses'],
						'ecarts'    => (bool) ( $deja['manquants'] || $deja['casses'] ),
						'cloturee'  => 'cloturee' === etr_get_statut( $id ),
					);
				} else {
					$ok = self::verifier( $id, array( 'livree' ) );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
					$resultat = self::reprendre(
						$id,
						(array) $requete->get_param( 'lignes' ),
						sanitize_textarea_field( (string) $requete->get_param( 'note' ) ),
						self::photos( $requete ),
						sanitize_text_field( (string) $requete->get_param( 'signataire' ) ),
						(string) $requete->get_param( 'signature' ),
						$jeton
					);
					if ( is_wp_error( $resultat ) ) {
						return $resultat;
					}
				}
				$resultat['url'] = ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => $resultat['ecarts'] ? 'reprise_ecarts' : 'reprise' ) );
				return rest_ensure_response( $resultat );
			}
		);
	}

	/**
	 * POST note : { texte, jeton }.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_note( $requete ) {
		$id    = (int) $requete['id'];
		$texte = sanitize_textarea_field( (string) $requete->get_param( 'texte' ) );
		$jeton = self::jeton( $requete );
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			return new WP_Error( 'etr_demande', __( 'Demande introuvable.', 'espace-traiteur' ), array( 'status' => 404 ) );
		}
		return self::sous_verrou(
			$id,
			function () use ( $id, $texte, $jeton ) {
				$note = self::ajouter_note( $id, $texte, $jeton );
				if ( is_wp_error( $note ) ) {
					return $note;
				}
				return rest_ensure_response(
					array(
						'auteur' => etr_nom( get_userdata( $note['par'] ) ),
						'le'     => wp_date( 'j M, H:i', $note['le'] ),
						'texte'  => $note['texte'],
					)
				);
			}
		);
	}

	/**
	 * POST incident (multipart) : type, texte, photos[], jeton.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_incident( $requete ) {
		$id    = (int) $requete['id'];
		$jeton = self::jeton( $requete );
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			return new WP_Error( 'etr_demande', __( 'Demande introuvable.', 'espace-traiteur' ), array( 'status' => 404 ) );
		}
		return self::sous_verrou(
			$id,
			function () use ( $id, $jeton, $requete ) {
				$resultat = self::signaler(
					$id,
					sanitize_key( (string) $requete->get_param( 'type' ) ),
					sanitize_textarea_field( (string) $requete->get_param( 'texte' ) ),
					self::photos( $requete ),
					$jeton
				);
				return is_wp_error( $resultat ) ? $resultat : rest_ensure_response( array( 'url' => ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => 'incident' ) ) ) );
			}
		);
	}

	/**
	 * POST incident/{n}/resolu (bureau).
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_resoudre( $requete ) {
		$id = (int) $requete['id'];
		$n  = (int) $requete['n'];
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			return new WP_Error( 'etr_demande', __( 'Demande introuvable.', 'espace-traiteur' ), array( 'status' => 404 ) );
		}
		return self::sous_verrou(
			$id,
			function () use ( $id, $n ) {
				$resultat = self::resoudre( $id, $n );
				return is_wp_error( $resultat ) ? $resultat : rest_ensure_response( array( 'ok' => true ) );
			}
		);
	}

	/**
	 * Jeton d'envoi d'un formulaire (créé à l'ouverture de l'écran).
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return string
	 */
	private static function jeton( $requete ) {
		return substr( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $requete->get_param( 'jeton' ) ), 0, 64 );
	}

	/**
	 * Photos envoyées (champ « photos[] »), une entrée par photo.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return array[]
	 */
	private static function photos( $requete ) {
		$fichiers = $requete->get_file_params();
		if ( empty( $fichiers['photos']['name'] ) ) {
			return array();
		}
		$envoi  = $fichiers['photos'];
		$photos = array();
		foreach ( (array) $envoi['name'] as $i => $nom ) {
			$valeur = function ( $cle ) use ( $envoi, $i ) {
				return is_array( $envoi[ $cle ] ) ? $envoi[ $cle ][ $i ] : $envoi[ $cle ];
			};
			if ( UPLOAD_ERR_NO_FILE === (int) $valeur( 'error' ) ) {
				continue;
			}
			$photos[] = array(
				'name'     => $nom,
				'tmp_name' => $valeur( 'tmp_name' ),
				'error'    => (int) $valeur( 'error' ),
				'size'     => (int) $valeur( 'size' ),
			);
		}
		return $photos;
	}
}
