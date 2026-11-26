<?php
/**
 * Espace de gestion autonome (plein écran) pour l'équipe : tableau de bord,
 * demandes, planning, traiteurs et catalogue, hors de l'administration WordPress.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Espace de gestion, à l'adresse /gestion-traiteur/.
 */
class ETR_Gestion {

	const PAR_PAGE = 25;

	/**
	 * Erreurs du formulaire d'ajout de traiteur (réaffiché sans redirection).
	 *
	 * @var array
	 */
	private static $erreurs_traiteur = array();

	/**
	 * Erreurs du formulaire d'ajout d'un membre de l'équipe.
	 *
	 * @var array
	 */
	private static $erreurs_membre = array();

	/**
	 * Cache des demandes.
	 *
	 * @var int[]|null
	 */
	private static $demandes = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'regle_reecriture' ) );
		add_filter( 'query_vars', array( __CLASS__, 'variables' ) );
		add_action( 'template_redirect', array( __CLASS__, 'controleur' ), 1 );
		add_filter( 'template_include', array( __CLASS__, 'gabarit' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'classe_body' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'barre_admin' ), 20 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'titre_document' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'noeud_barre_admin' ), 80 );
	}

	/**
	 * Identifiant d'URL de l'espace de gestion.
	 *
	 * @return string
	 */
	public static function slug() {
		return trim( (string) apply_filters( 'etr_gestion_slug', 'gestion-traiteur' ), '/' );
	}

	/**
	 * Règle de réécriture /gestion-traiteur/.
	 */
	public static function regle_reecriture() {
		add_rewrite_rule( '^' . preg_quote( self::slug(), '/' ) . '/?$', 'index.php?etr_gestion=1', 'top' );
	}

	/**
	 * Variable de requête.
	 *
	 * @param string[] $vars Variables.
	 * @return string[]
	 */
	public static function variables( $vars ) {
		$vars[] = 'etr_gestion';
		return $vars;
	}

	/**
	 * URL de l'espace de gestion.
	 *
	 * @param array $args Paramètres.
	 * @return string
	 */
	public static function url( $args = array() ) {
		$url = get_option( 'permalink_structure' )
			? home_url( user_trailingslashit( self::slug() ) )
			: add_query_arg( 'etr_gestion', '1', home_url( '/' ) );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Sommes-nous dans l'espace de gestion ?
	 *
	 * @return bool
	 */
	public static function actif() {
		return (bool) get_query_var( 'etr_gestion' );
	}

	/* ------------------------------------------------------------------ */
	/* Accès, actions, gabarit                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Contrôle d'accès puis traitement des actions (redirection après envoi).
	 */
	public static function controleur() {
		if ( ! self::actif() ) {
			return;
		}
		nocache_headers();

		// Jeton frais pour renvoyer les saisies faites sans réseau (la page en cache a un jeton ancien).
		if ( isset( $_GET['etr_jeton'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- lecture seule.
			if ( ! is_user_logged_in() || ! ETR_Equipe::acces() ) {
				wp_send_json_error( array( 'message' => __( 'Reconnectez-vous pour envoyer les saisies en attente.', 'espace-traiteur' ) ), 401 );
			}
			wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		}

		// Connexion, mot de passe oublié, choix du mot de passe : dans l'application même
		// (une app installée sur iPhone a ses propres cookies), voir ETR_Equipe_Acces.
		if ( ETR_Equipe_Acces::preparer() ) {
			self::page_trouvee();
			header( 'X-ETR-Page: connexion' ); // Le service worker efface alors les pages gardées.
			return;
		}
		if ( ETR_Access::traiteur_seulement( get_current_user_id() ) ) {
			wp_safe_redirect( etr_url() );
			exit;
		}
		if ( ! ETR_Equipe::acces() ) {
			wp_die( esc_html__( 'L’espace de gestion est réservé à l’équipe.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}

		self::page_trouvee();
		// Personne de l'équipe : les pages autonomes (bons, étiquettes) ne touchent pas aux pages
		// gardées sur le téléphone ; les vraies pages de l'application remplacent cet en-tête plus bas.
		header( 'X-ETR-Page: document' );

		$action = isset( $_REQUEST['etr_gestion_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['etr_gestion_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- vérifié par action.
		if ( $action && ! ETR_Equipe::bureau() ) {
			wp_die( esc_html__( 'Cette action est réservée au bureau.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		if ( 'decision' === $action ) {
			self::action_decision();
		} elseif ( 'tarif' === $action ) {
			self::action_tarif();
		} elseif ( 'creer_traiteur' === $action ) {
			self::action_creer_traiteur();
		} elseif ( 'inviter' === $action || 'basculer' === $action ) {
			self::action_compte( $action );
		} elseif ( 'export' === $action ) {
			self::action_export();
		} elseif ( 'creer_membre' === $action ) {
			self::action_creer_membre();
		} elseif ( 'membre' === $action ) {
			self::action_membre();
		} elseif ( $action ) {
			/** Actions du bureau ajoutées par les fonctions activables (ETR_Modules). */
			do_action( 'etr_gestion_action', $action );
		}

		// Photos et signatures (fichiers privés), étiquettes QR (page autonome).
		// phpcs:disable WordPress.Security.NonceVerification -- simples lectures, droits vérifiés.
		$vue_demandee = isset( $_GET['vue'] ) ? sanitize_key( wp_unslash( $_GET['vue'] ) ) : '';
		if ( 'fichier' === $vue_demandee ) {
			ETR_Fichiers::servir( isset( $_GET['f'] ) ? sanitize_file_name( wp_unslash( $_GET['f'] ) ) : '' );
		}
		if ( 'bon-livraison' === $vue_demandee && ETR_Modules::actif( 'bon_livraison' ) ) {
			$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			if ( ! self::demande_visible( $id ) || ! ETR_Operations::livraison( $id ) ) {
				wp_die( esc_html__( 'Bon de livraison introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
			}
			ETR_Bon_Livraison::servir( $id );
		}
		if ( 'etiquettes' === $vue_demandee ) {
			ETR_Terrain::etiquettes( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		}
		// phpcs:enable

		// Bons imprimables : page autonome, hors application.
		if ( isset( $_GET['vue'], $_GET['id'] ) && 'bon' === $_GET['vue'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! self::demande_visible( $id ) ) {
				wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
			}
			ETR_Bons::afficher( $id, isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', self::url_demande( $id ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		// Seulement les vraies pages de l'application (pas les exports, PDF, photos, étiquettes,
		// qui partent plus haut) : gardables pour le hors connexion par le service worker.
		header( 'X-ETR-Page: app' );
	}

	/**
	 * L'espace de gestion est une vraie page (ni accueil, ni « introuvable »).
	 */
	private static function page_trouvee() {
		global $wp_query;
		$wp_query->is_home = false;
		$wp_query->is_404  = false;
		status_header( 200 );
	}

	/**
	 * Enregistre la décision sur une demande.
	 */
	private static function action_decision() {
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		check_admin_referer( 'etr_gestion_decision_' . $id, 'etr_nonce' );
		if ( ! $id || 'etr_demande' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		$statut  = isset( $_POST['etr_statut'] ) ? sanitize_key( wp_unslash( $_POST['etr_statut'] ) ) : '';
		$reponse = isset( $_POST['etr_reponse'] ) ? sanitize_textarea_field( wp_unslash( $_POST['etr_reponse'] ) ) : '';
		$change  = ETR_Admin::appliquer_decision( $id, $statut, $reponse );

		wp_safe_redirect(
			self::url(
				array(
					'vue'     => 'demande',
					'id'      => $id,
					'etr_msg' => $change ? 'statut' : 'enregistre',
				)
			)
		);
		exit;
	}

	/**
	 * Enregistre la remise et les frais de livraison d'une demande.
	 */
	private static function action_tarif() {
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		check_admin_referer( 'etr_gestion_tarif_' . $id, 'etr_nonce' );
		if ( ! $id || 'etr_demande' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		$remise    = isset( $_POST['etr_remise_pct'] ) ? sanitize_text_field( wp_unslash( $_POST['etr_remise_pct'] ) ) : '';
		$livraison = isset( $_POST['etr_frais_livraison'] ) ? sanitize_text_field( wp_unslash( $_POST['etr_frais_livraison'] ) ) : '';
		ETR_Admin::appliquer_tarif( $id, $remise, $livraison );

		wp_safe_redirect(
			self::url(
				array(
					'vue'     => 'demande',
					'id'      => $id,
					'etr_msg' => 'tarif',
				)
			)
		);
		exit;
	}

	/**
	 * Adresse d'un export CSV.
	 *
	 * @param string $type demandes|articles|planning.
	 * @param array  $args Filtres.
	 * @return string
	 */
	private static function url_export( $type, $args = array() ) {
		return wp_nonce_url(
			self::url(
				array_merge(
					$args,
					array(
						'etr_gestion_action' => 'export',
						'type'               => $type,
					)
				)
			),
			'etr_gestion_export'
		);
	}

	/**
	 * Export CSV (séparateur « ; », UTF-8 avec BOM : s'ouvre directement dans Excel).
	 */
	private static function action_export() {
		check_admin_referer( 'etr_gestion_export' );
		// phpcs:disable WordPress.Security.NonceVerification -- vérifié ci-dessus.
		$type     = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$statut   = isset( $_GET['statut'] ) ? sanitize_key( wp_unslash( $_GET['statut'] ) ) : '';
		$traiteur = isset( $_GET['traiteur'] ) ? absint( $_GET['traiteur'] ) : 0;
		$q        = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$mois     = isset( $_GET['mois'] ) ? sanitize_text_field( wp_unslash( $_GET['mois'] ) ) : '';
		// phpcs:enable
		if ( ! array_key_exists( $statut, etr_statuts() ) ) {
			$statut = '';
		}
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $mois ) ) {
			$mois = current_time( 'Y-m' );
		}

		if ( 'planning' === $type ) {
			$nom    = 'planning-' . $mois;
			$lignes = self::export_planning( $mois );
		} elseif ( 'articles' === $type ) {
			$nom    = 'demandes-articles-' . current_time( 'Y-m-d' );
			$lignes = self::export_articles( self::filtrer_demandes( $statut, $traiteur, $q )[1] );
		} else {
			$nom    = 'demandes-' . current_time( 'Y-m-d' );
			$lignes = self::export_demandes( self::filtrer_demandes( $statut, $traiteur, $q )[1] );
		}
		$lignes = apply_filters( 'etr_export_csv', $lignes, $type );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $nom ) . '.csv"' );
		$sortie = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $sortie, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( $lignes as $ligne ) {
			fputcsv( $sortie, array_map( array( __CLASS__, 'cellule_csv' ), $ligne ), ';', '"', '\\' );
		}
		fclose( $sortie ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Cellule CSV : nombres au format français, formules neutralisées.
	 *
	 * @param mixed $valeur Valeur.
	 * @return string
	 */
	private static function cellule_csv( $valeur ) {
		if ( is_float( $valeur ) ) {
			return number_format( $valeur, 2, ',', '' );
		}
		$valeur = (string) $valeur;
		// Évite l'exécution de formules à l'ouverture dans un tableur.
		if ( '' !== $valeur && in_array( $valeur[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $valeur ) ) {
			$valeur = "'" . $valeur;
		}
		return $valeur;
	}

	/**
	 * Valeur brute d'un champ de demande.
	 *
	 * @param int    $id  Demande.
	 * @param string $cle Champ.
	 * @return string
	 */
	private static function meta( $id, $cle ) {
		return (string) get_post_meta( $id, '_etr_' . $cle, true );
	}

	/**
	 * Export : une ligne par demande.
	 *
	 * @param int[] $ids Demandes.
	 * @return array[]
	 */
	private static function export_demandes( $ids ) {
		$statuts = etr_statuts();
		$lignes  = array(
			array( 'N°', 'Reçue le', 'Traiteur', 'E-mail du traiteur', 'Événement', 'Date de l’événement', 'Invités', 'Lieu', 'Contact sur place', 'Livraison', 'Reprise', 'Statut', 'Articles', 'Quantité totale', 'Sous-total', 'Remise (%)', 'Remise', 'Livraison et reprise', 'Total indicatif', 'Hors catalogue', 'Message au traiteur', 'Lien' ),
		);
		foreach ( $ids as $id ) {
			$auteur    = get_userdata( (int) get_post_field( 'post_author', $id ) );
			$chiffrage = etr_chiffrage( $id );
			$quantite  = 0;
			foreach ( etr_get_lignes( $id ) as $ligne ) {
				$quantite += (int) $ligne['quantite'];
			}
			$statut   = etr_get_statut( $id );
			$lignes[] = array(
				$id,
				get_the_date( 'Y-m-d', $id ),
				$auteur ? etr_nom( $auteur ) : '',
				$auteur ? $auteur->user_email : '',
				wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ),
				self::meta( $id, 'date_evenement' ),
				self::meta( $id, 'nb_invites' ),
				self::meta( $id, 'lieu' ),
				self::meta( $id, 'contact' ),
				self::meta( $id, 'date_livraison' ),
				self::meta( $id, 'date_reprise' ),
				isset( $statuts[ $statut ] ) ? $statuts[ $statut ] : $statut,
				count( etr_get_lignes( $id ) ),
				$quantite,
				(float) $chiffrage['sous_total'],
				$chiffrage['remise_pct'] ? (float) $chiffrage['remise_pct'] : '',
				$chiffrage['remise_pct'] ? (float) $chiffrage['remise'] : '',
				null === $chiffrage['livraison'] ? '' : (float) $chiffrage['livraison'],
				(float) $chiffrage['total'],
				self::meta( $id, 'autres_besoins' ),
				self::meta( $id, 'reponse' ),
				self::url_demande( $id ),
			);
		}
		return $lignes;
	}

	/**
	 * Export : une ligne par article demandé (pour Odoo ou la comptabilité).
	 *
	 * @param int[] $ids Demandes.
	 * @return array[]
	 */
	private static function export_articles( $ids ) {
		$statuts = etr_statuts();
		$lignes  = array(
			array( 'N°', 'Traiteur', 'Événement', 'Date de l’événement', 'Livraison', 'Reprise', 'Statut', 'Article', 'Référence', 'Quantité', 'Unité', 'Prix unitaire', 'Prix de la ligne' ),
		);
		foreach ( $ids as $id ) {
			$statut    = etr_get_statut( $id );
			$chiffrage = etr_chiffrage( $id );
			foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
				$article  = etr_get_article( $ligne['article_id'] );
				$prix     = $chiffrage['lignes'][ $index ];
				$lignes[] = array(
					$id,
					self::traiteur( $id ),
					wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ),
					self::meta( $id, 'date_evenement' ),
					self::meta( $id, 'date_livraison' ),
					self::meta( $id, 'date_reprise' ),
					isset( $statuts[ $statut ] ) ? $statuts[ $statut ] : $statut,
					$ligne['titre'],
					$article ? $article['reference'] : '',
					(int) $ligne['quantite'],
					isset( $ligne['unite'] ) ? $ligne['unite'] : '',
					null === $prix['unitaire'] ? '' : (float) $prix['unitaire'],
					null === $prix['total'] ? '' : (float) $prix['total'],
				);
			}
		}
		return $lignes;
	}

	/**
	 * Export : mouvements du mois (livraisons, événements, reprises).
	 *
	 * @param string $mois AAAA-MM.
	 * @return array[]
	 */
	private static function export_planning( $mois ) {
		$statuts = etr_statuts();
		$types   = self::types_mouvement();
		$jours   = self::mouvements( $mois . '-01', gmdate( 'Y-m-t', strtotime( $mois . '-01' ) ) );
		ksort( $jours );
		$lignes = array(
			array( 'Date', 'Mouvement', 'N°', 'Événement', 'Traiteur', 'Statut', 'Lieu', 'Contact sur place', 'Articles', 'Quantité totale' ),
		);
		foreach ( $jours as $date => $mouvements ) {
			foreach ( $mouvements as $mvt ) {
				$id       = $mvt['id'];
				$statut   = etr_get_statut( $id );
				$quantite = 0;
				foreach ( etr_get_lignes( $id ) as $ligne ) {
					$quantite += (int) $ligne['quantite'];
				}
				$lignes[] = array(
					$date,
					isset( $types[ $mvt['type'] ] ) ? $types[ $mvt['type'] ] : $mvt['type'],
					$id,
					wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ),
					self::traiteur( $id ),
					isset( $statuts[ $statut ] ) ? $statuts[ $statut ] : $statut,
					self::meta( $id, 'lieu' ),
					self::meta( $id, 'contact' ),
					count( etr_get_lignes( $id ) ),
					$quantite,
				);
			}
		}
		return $lignes;
	}

	/**
	 * Création d'un traiteur : redirection si réussie, sinon formulaire réaffiché.
	 */
	private static function action_creer_traiteur() {
		check_admin_referer( 'etr_gestion_creer_traiteur', 'etr_nonce' );
		if ( ! ETR_Equipe::bureau() ) {
			wp_die( esc_html__( 'Vous n’avez pas le droit de créer des comptes.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		$donnees = array();
		foreach ( array( 'societe', 'contact', 'email', 'telephone', 'identifiant' ) as $cle ) {
			$donnees[ $cle ] = isset( $_POST[ $cle ] ) ? wp_unslash( $_POST[ $cle ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé par creer_compte().
		}
		$resultat = ETR_Traiteurs::creer_compte( $donnees, ! empty( $_POST['envoyer_invitation'] ) );
		if ( is_wp_error( $resultat ) ) {
			self::$erreurs_traiteur = array( $resultat->get_error_code() );
			return;
		}
		wp_safe_redirect(
			self::url(
				array(
					'vue'     => 'traiteurs',
					'etr_msg' => ETR_Traiteurs::code_creation( $resultat ),
				)
			)
		);
		exit;
	}

	/**
	 * Ajout d'un membre de l'équipe : redirection si réussi, sinon formulaire réaffiché.
	 */
	private static function action_creer_membre() {
		check_admin_referer( 'etr_gestion_creer_membre', 'etr_nonce' );
		$donnees = array();
		foreach ( array( 'nom', 'email', 'telephone' ) as $cle ) {
			$donnees[ $cle ] = isset( $_POST[ $cle ] ) ? wp_unslash( $_POST[ $cle ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé par creer_membre().
		}
		$donnees['roles'] = isset( $_POST['roles'] ) ? (array) wp_unslash( $_POST['roles'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- liste blanche dans creer_membre().
		$resultat         = ETR_Equipe::creer_membre( $donnees, ! empty( $_POST['envoyer_invitation'] ) );
		if ( is_wp_error( $resultat ) ) {
			self::$erreurs_membre = array( $resultat->get_error_code() );
			return;
		}
		wp_safe_redirect(
			self::url(
				array(
					'vue'     => 'equipe',
					'etr_msg' => ! empty( $resultat['existant'] ) ? 'membre_traiteur' : 'membre_' . ETR_Traiteurs::code_creation( $resultat ),
				)
			)
		);
		exit;
	}

	/**
	 * Action sur un membre de l'équipe : changer de rôle, inviter, retirer ou rendre l'accès.
	 */
	private static function action_membre() {
		$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'etr_gestion_membre_' . $user_id, 'etr_nonce' );
		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$roles = isset( $_POST['roles'] ) ? (array) wp_unslash( $_POST['roles'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- liste blanche dans modifier_membre().
		$msg   = ETR_Equipe::modifier_membre( $user_id, $op, $roles );
		if ( is_wp_error( $msg ) ) {
			wp_die( esc_html( $msg->get_error_message() ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect(
			self::url(
				array(
					'vue'     => 'equipe',
					'etr_msg' => $msg,
				)
			)
		);
		exit;
	}

	/**
	 * Renvoi d'invitation ou (dés)activation d'un accès.
	 *
	 * @param string $action inviter|basculer.
	 */
	private static function action_compte( $action ) {
		$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'etr_gestion_' . $action . '_' . $user_id, 'etr_nonce' );
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'traiteur', (array) $user->roles, true ) ) {
			wp_die( esc_html__( 'Traiteur introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		if ( 'inviter' === $action ) {
			$msg = ETR_Traiteurs::envoyer_invitation( $user ) ? 'invite' : 'mail_echec';
		} else {
			$msg = ETR_Traiteurs::basculer_acces( $user_id );
		}
		wp_safe_redirect(
			self::url(
				array(
					'vue'     => 'traiteurs',
					'etr_msg' => $msg,
				)
			)
		);
		exit;
	}

	/**
	 * Gabarit plein écran.
	 *
	 * @param string $gabarit Gabarit prévu.
	 * @return string
	 */
	public static function gabarit( $gabarit ) {
		return self::actif() ? ETR_DIR . 'templates/gestion.php' : $gabarit;
	}

	/**
	 * Styles et scripts.
	 */
	public static function assets() {
		if ( ! self::actif() ) {
			return;
		}
		wp_enqueue_style( 'espace-traiteur', ETR_URL . 'assets/css/espace-traiteur.css', array(), ETR_VERSION );
		wp_enqueue_style( 'espace-traiteur-gestion', ETR_URL . 'assets/css/gestion.css', array( 'espace-traiteur' ), ETR_VERSION );
		wp_enqueue_script( 'espace-traiteur-gestion', ETR_URL . 'assets/js/gestion.js', array(), ETR_VERSION, true );
		wp_enqueue_script( 'espace-traiteur-app', ETR_URL . 'assets/js/app.js', array(), ETR_VERSION, true );
		wp_localize_script( 'espace-traiteur-app', 'etrApp', ETR_PWA::config_script() );
		if ( ETR_Cles_Acces::disponible() && ( ETR_Equipe_Acces::ecran() || is_user_logged_in() ) ) {
			wp_enqueue_script( 'espace-traiteur-cles', ETR_URL . 'assets/js/cles-acces.js', array(), ETR_VERSION, true );
			wp_localize_script(
				'espace-traiteur-cles',
				'etrCles',
				array(
					'api'   => esc_url_raw( rest_url( ETR_Push::API . ETR_Cles_Acces::ROUTE ) ),
					'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				)
			);
		}
		if ( ETR_Equipe_Acces::ecran() ) {
			wp_enqueue_script( 'espace-traiteur-connexion', ETR_URL . 'assets/js/connexion.js', array(), ETR_VERSION, true );
		} elseif ( is_user_logged_in() ) {
			// Boîte d'envoi hors connexion d'abord : elle reçoit la configuration, terrain.js s'en sert.
			wp_enqueue_script( 'espace-traiteur-hors-ligne', ETR_URL . 'assets/js/hors-ligne.js', array(), ETR_VERSION, true );
			wp_enqueue_script( 'espace-traiteur-terrain', ETR_URL . 'assets/js/terrain.js', array( 'espace-traiteur-hors-ligne' ), ETR_VERSION, true );
			if ( ETR_Modules::actif( 'en_route' ) || ETR_Modules::actif( 'deplacer_dates' ) ) {
				wp_enqueue_script( 'espace-traiteur-modules', ETR_URL . 'assets/js/modules.js', array( 'espace-traiteur-hors-ligne', 'espace-traiteur-app' ), ETR_VERSION, true );
			}
			wp_localize_script(
				'espace-traiteur-hors-ligne',
				'etrTerrain',
				array(
					'api'       => esc_url_raw( rest_url( ETR_Push::API . '/terrain/' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'jsqr'      => ETR_URL . 'assets/vendor/jsqr.min.js?ver=' . ETR_VERSION,
					'maxPhotos' => ETR_Operations::MAX_PHOTOS,
					'hote'      => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
					'gestion'   => self::url(),
					'utilisateur' => get_current_user_id(), // Saisies hors connexion rejouées pour la même personne.
					'apiNotifs' => ETR_Push::disponible() ? esc_url_raw( rest_url( ETR_Push::API . '/notifications/' ) ) : '',
				)
			);
		}
		if ( ! ETR_Equipe_Acces::ecran() && is_user_logged_in() && ETR_Push::disponible() ) {
			wp_enqueue_script( 'espace-traiteur-notifications', ETR_URL . 'assets/js/notifications.js', array( 'espace-traiteur-app' ), ETR_VERSION, true );
			wp_localize_script(
				'espace-traiteur-notifications',
				'etrNotifs',
				array(
					'cle'   => ETR_Push::cle_publique(),
					'api'   => esc_url_raw( rest_url( ETR_Push::API . '/notifications/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'utilisateur' => get_current_user_id(),
				)
			);
		}
	}

	/**
	 * Classes sur <body>.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function classe_body( $classes ) {
		if ( self::actif() ) {
			$classes[] = 'etr-plein-ecran';
			$classes[] = 'etr-page-gestion';
		}
		return $classes;
	}

	/**
	 * Pas de barre d'administration dans l'espace de gestion.
	 *
	 * @param bool $afficher Valeur par défaut.
	 * @return bool
	 */
	public static function barre_admin( $afficher ) {
		return self::actif() ? false : $afficher;
	}

	/**
	 * Titre de l'onglet.
	 *
	 * @param string $titre Titre.
	 * @return string
	 */
	public static function titre_document( $titre ) {
		if ( ! self::actif() ) {
			return $titre;
		}
		$vues = self::vues();
		$vue  = self::vue_courante();
		$nom  = isset( $vues[ $vue ] ) ? $vues[ $vue ][0] : __( 'Demande', 'espace-traiteur' );
		if ( ETR_Equipe_Acces::ecran() ) {
			$nom = ETR_Equipe_Acces::titre();
		}
		return $nom . ' – ' . __( 'Gestion', 'espace-traiteur' ) . ' – ' . etr_nom_site();
	}

	/**
	 * Lien dans le menu « Espace traiteur » de l'administration.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=etr_demande',
			__( 'Espace de gestion', 'espace-traiteur' ),
			__( 'Espace de gestion', 'espace-traiteur' ),
			'gerer_espace_traiteur',
			self::url(),
			'',
			0
		);
	}

	/**
	 * Lien dans la barre d'administration.
	 *
	 * @param WP_Admin_Bar $barre Barre.
	 */
	public static function noeud_barre_admin( $barre ) {
		if ( ! etr_est_gestionnaire() ) {
			return;
		}
		$barre->add_node(
			array(
				'id'    => 'etr-gestion',
				'title' => __( 'Gestion traiteurs', 'espace-traiteur' ),
				'href'  => self::url(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Données                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Statuts visibles par le préparateur et le livreur (le bureau voit tout).
	 *
	 * @return string[]
	 */
	private static function statuts_terrain() {
		return array( 'validee', 'livree', 'cloturee' );
	}

	/**
	 * La demande est-elle visible par l'utilisateur courant ?
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	public static function demande_visible( $id ) {
		return $id && 'etr_demande' === get_post_type( $id ) && 'publish' === get_post_status( $id )
			&& ( ETR_Equipe::bureau() || in_array( etr_get_statut( $id ), self::statuts_terrain(), true ) );
	}

	/**
	 * Demandes publiées visibles par l'utilisateur courant (méta préchargées).
	 * Préparateurs et livreurs ne voient que les demandes validées, livrées ou clôturées :
	 * planning, liste, fiche et bons en découlent.
	 *
	 * @return int[]
	 */
	private static function demandes() {
		if ( null === self::$demandes ) {
			self::$demandes = get_posts(
				array(
					'post_type'              => 'etr_demande',
					'post_status'            => 'publish',
					'numberposts'            => -1,
					'fields'                 => 'ids',
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			if ( self::$demandes ) {
				update_meta_cache( 'post', self::$demandes );
				_prime_post_caches( self::$demandes, false, false );
			}
			if ( ! ETR_Equipe::bureau() ) {
				self::$demandes = array_values( array_filter( self::$demandes, array( __CLASS__, 'demande_visible' ) ) );
			}
		}
		return self::$demandes;
	}

	/**
	 * Date d'une demande (méta).
	 *
	 * @param int    $id  ID.
	 * @param string $cle evenement|livraison|reprise.
	 * @return string
	 */
	private static function date( $id, $cle ) {
		$date = (string) get_post_meta( $id, '_etr_date_' . $cle, true );
		return etr_date_valide( $date ) ? $date : '';
	}

	/**
	 * Aujourd'hui (fuseau du site).
	 *
	 * @return string
	 */
	private static function aujourdhui() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Tri : à venir d'abord (de la plus proche à la plus lointaine), puis passées (de la plus récente à la plus ancienne).
	 *
	 * @param int[] $ids Demandes.
	 * @return int[]
	 */
	private static function trier( $ids ) {
		$jour = self::aujourdhui();
		usort(
			$ids,
			function ( $a, $b ) use ( $jour ) {
				$da = self::date( $a, 'evenement' );
				$db = self::date( $b, 'evenement' );
				$pa = ( ! $da || $da < $jour ) ? 1 : 0;
				$pb = ( ! $db || $db < $jour ) ? 1 : 0;
				if ( $pa !== $pb ) {
					return $pa - $pb;
				}
				return $pa ? strcmp( $db, $da ) : strcmp( $da, $db );
			}
		);
		return $ids;
	}

	/**
	 * Mouvements (livraisons, événements, reprises) entre deux dates, par jour.
	 *
	 * @param string $debut Début AAAA-MM-JJ.
	 * @param string $fin   Fin AAAA-MM-JJ.
	 * @return array<string,array[]> date => liste de { type, id }.
	 */
	private static function mouvements( $debut, $fin ) {
		$jours   = array();
		$actifs  = array( 'en_attente', 'validee', 'livree' );
		$ordre   = array(
			'livraison' => 0,
			'evenement' => 1,
			'reprise'   => 2,
		);
		foreach ( self::demandes() as $id ) {
			if ( ! in_array( etr_get_statut( $id ), $actifs, true ) ) {
				continue;
			}
			foreach ( $ordre as $type => $rang ) {
				$date = self::date( $id, $type );
				if ( $date && $date >= $debut && $date <= $fin ) {
					$jours[ $date ][] = array(
						'type' => $type,
						'id'   => $id,
					);
				}
			}
		}
		foreach ( $jours as &$liste ) {
			usort(
				$liste,
				function ( $a, $b ) use ( $ordre ) {
					return $ordre[ $a['type'] ] - $ordre[ $b['type'] ];
				}
			);
		}
		return $jours;
	}

	/**
	 * Articles d'une demande en quantité insuffisante sur sa période (hors cette demande).
	 *
	 * @param int $id Demande.
	 * @return array<int,int> article_id => quantité manquante.
	 */
	private static function manques( $id ) {
		$lignes = etr_get_lignes( $id );
		if ( ! $lignes ) {
			return array();
		}
		list( $debut, $fin ) = etr_periode_demande( $id );
		$reservees           = $debut ? etr_quantites_reservees( $debut, $fin, $id ) : array();
		$manques             = array();
		foreach ( $lignes as $ligne ) {
			$dispo = etr_disponibilite( (int) $ligne['article_id'], $reservees );
			if ( null !== $dispo && (int) $ligne['quantite'] > $dispo ) {
				$manques[ (int) $ligne['article_id'] ] = (int) $ligne['quantite'] - $dispo;
			}
		}
		return $manques;
	}

	/* ------------------------------------------------------------------ */
	/* Mise en page                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Vues de navigation : clé => { libellé, icône }.
	 *
	 * @return array
	 */
	private static function vues() {
		$toutes = array(
			'tableau'      => array( __( 'Tableau de bord', 'espace-traiteur' ), 'accueil' ),
			'demandes'     => array( __( 'Demandes', 'espace-traiteur' ), 'demandes' ),
			'planning'     => array( __( 'Planning', 'espace-traiteur' ), 'calendrier' ),
			'traiteurs'    => array( __( 'Traiteurs', 'espace-traiteur' ), 'invites' ),
			'catalogue'    => array( __( 'Catalogue', 'espace-traiteur' ), 'catalogue' ),
			'statistiques' => array( __( 'Statistiques', 'espace-traiteur' ), 'statistiques' ),
			'equipe'       => array( __( 'Équipe', 'espace-traiteur' ), 'equipe' ),
			'tournee'      => array( __( 'Tournée', 'espace-traiteur' ), 'camion' ),
			'scanner'      => array( __( 'Scanner', 'espace-traiteur' ), 'scan' ),
		);
		if ( ETR_Equipe::bureau() ) {
			$ordre = array( 'tableau', 'demandes', 'planning', 'traiteurs', 'tournee', 'scanner', 'catalogue', 'statistiques', 'equipe' );
			/** Écrans ajoutés par les fonctions activables (inventaire, factures…), après les autres. */
			return (array) apply_filters( 'etr_gestion_vues', array_merge( array_flip( $ordre ), $toutes ) );
		}
		// Livreur : la tournée d'abord ; préparateur : le planning. Sans prix ni gestion.
		$cles = ETR_Equipe::livreur() ? array( 'tournee', 'planning', 'demandes', 'scanner' ) : array( 'planning', 'demandes', 'scanner' );
		if ( ETR_Equipe::preparateur() ) {
			$cles[] = 'catalogue';
		}
		$vues = array();
		foreach ( $cles as $cle ) {
			$vues[ $cle ] = $toutes[ $cle ];
		}
		return $vues;
	}

	/**
	 * Vue demandée.
	 *
	 * @return string
	 */
	private static function vue_courante() {
		$vues   = self::vues();
		$defaut = isset( $vues['tableau'] ) ? 'tableau' : (string) key( $vues );
		$vue    = isset( $_GET['vue'] ) ? sanitize_key( wp_unslash( $_GET['vue'] ) ) : $defaut; // phpcs:ignore WordPress.Security.NonceVerification
		if ( self::$erreurs_traiteur ) {
			return 'traiteurs';
		}
		if ( self::$erreurs_membre ) {
			return 'equipe';
		}
		if ( 'demande' === $vue ) {
			return isset( $_GET['id'] ) ? 'demande' : 'demandes'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( 'notifications' === $vue ) {
			return 'notifications'; // Pour toute l'équipe, depuis la cloche (pas d'onglet).
		}
		// Écrans du terrain (hors onglets) : droits vérifiés ici, puis par l'API à l'enregistrement.
		$terrain = array(
			'preparer'  => ETR_Equipe::preparateur(),
			'livrer'    => ETR_Equipe::livreur(),
			'reprendre' => ETR_Equipe::livreur(),
			'colis'     => ETR_Equipe::preparateur() || ETR_Equipe::livreur(),
			'incident'  => true,
			'recherche' => true,
			'face-id'   => ETR_Cles_Acces::disponible(),
		);
		if ( ! empty( $terrain[ $vue ] ) ) {
			return $vue;
		}
		return array_key_exists( $vue, $vues ) ? $vue : $defaut;
	}

	/**
	 * Affiche l'application.
	 */
	public static function rendu() {
		if ( ETR_Equipe_Acces::ecran() ) {
			ETR_Equipe_Acces::afficher();
			return;
		}
		$vue    = self::vue_courante();
		$parent = array(
			'demande'   => 'demandes',
			'preparer'  => 'demandes',
			'livrer'    => 'tournee',
			'reprendre' => 'tournee',
			'colis'     => 'scanner',
			'incident'  => 'demandes',
			'recherche' => '',
			'face-id'   => '',
		);
		$actif  = isset( $parent[ $vue ] ) ? $parent[ $vue ] : $vue;

		echo '<div class="etr-app etr-gestion">';
		self::barre_laterale( $actif );
		echo '<main class="etr-app__principal" id="etr-contenu" data-vue="' . esc_attr( $vue ) . '">';
		self::aide_installation();
		if ( 'notifications' !== $vue ) {
			self::invitation_notifications();
		}
		if ( 'face-id' !== $vue && ETR_Cles_Acces::disponible() && ! ETR_Cles_Acces::cles( get_current_user_id() ) ) {
			echo '<aside class="etr-installer etr-notifs-invite" id="etr-cle-invite" hidden><span class="etr-notifs-invite__icone" aria-hidden="true">' . self::icone( 'visage' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><strong>' . esc_html__( 'Se connecter avec Face ID', 'espace-traiteur' ) . '</strong><span>' . esc_html__( 'Plus besoin de taper le mot de passe sur ce téléphone.', 'espace-traiteur' ) . '</span></p>';
			echo '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'face-id' ) ) ) . '">' . esc_html__( 'Activer', 'espace-traiteur' ) . '</a>';
			echo '<button type="button" class="etr-installer__fermer" aria-label="' . esc_attr__( 'Plus tard', 'espace-traiteur' ) . '">×</button></aside>';
		}
		self::messages();

		switch ( $vue ) {
			case 'demandes':
				self::vue_demandes();
				break;
			case 'demande':
				self::vue_demande( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				break;
			case 'planning':
				self::vue_planning();
				break;
			case 'traiteurs':
				self::vue_traiteurs();
				break;
			case 'catalogue':
				self::vue_catalogue();
				break;
			case 'statistiques':
				self::vue_statistiques();
				break;
			case 'equipe':
				self::vue_equipe();
				break;
			case 'notifications':
				self::vue_notifications();
				break;
			case 'tournee':
				ETR_Terrain::vue_tournee();
				break;
			case 'scanner':
				ETR_Terrain::vue_scanner();
				break;
			case 'colis':
				ETR_Terrain::vue_colis();
				break;
			case 'preparer':
				ETR_Terrain::vue_preparer( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
				break;
			case 'livrer':
				ETR_Terrain::vue_livrer( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
				break;
			case 'reprendre':
				ETR_Terrain::vue_reprendre( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
				break;
			case 'incident':
				ETR_Terrain::vue_incident( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
				break;
			case 'recherche':
				self::vue_recherche();
				break;
			case 'face-id':
				self::vue_face_id();
				break;
			default:
				if ( has_action( 'etr_gestion_vue_' . $vue ) ) {
					do_action( 'etr_gestion_vue_' . $vue ); // Écrans des fonctions activables.
				} else {
					self::vue_tableau();
				}
		}

		echo '</main></div>';
	}

	/**
	 * Invitation à activer les notifications (affichée par notifications.js dans l'application
	 * installée, tant que la personne n'a ni accepté ni refusé).
	 */
	private static function invitation_notifications() {
		if ( ! ETR_Push::disponible() ) {
			return;
		}
		echo '<aside class="etr-installer etr-notifs-invite" id="etr-notifs-invite" hidden>';
		echo '<span class="etr-notifs-invite__icone" aria-hidden="true">' . self::icone( 'cloche' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
		echo '<p><strong>' . esc_html__( 'Recevoir les alertes sur ce téléphone', 'espace-traiteur' ) . '</strong>';
		echo '<span>' . esc_html( self::texte_alertes() ) . '</span></p>';
		echo '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'notifications' ) ) ) . '">' . esc_html__( 'Activer', 'espace-traiteur' ) . '</a>';
		echo '<button type="button" class="etr-installer__fermer" aria-label="' . esc_attr__( 'Plus tard', 'espace-traiteur' ) . '">×</button>';
		echo '</aside>';
	}

	/**
	 * Ce que la personne recevra, selon ses rôles.
	 *
	 * @return string[]
	 */
	private static function alertes_recues() {
		$alertes = array();
		$roles   = ETR_Equipe::roles_equipe( wp_get_current_user() );
		if ( ETR_Equipe::bureau() ) {
			$alertes[] = __( 'Chaque nouvelle demande d’un traiteur, et chaque demande qu’un traiteur annule.', 'espace-traiteur' );
		}
		if ( array_intersect( array( 'etr_preparateur', 'etr_livreur' ), $roles ) ) {
			$alertes[] = __( 'Chaque commande validée par le bureau (à préparer, à livrer), et chaque commande validée puis annulée.', 'espace-traiteur' );
		}
		return $alertes;
	}

	/**
	 * Résumé d'une ligne des alertes reçues.
	 *
	 * @return string
	 */
	private static function texte_alertes() {
		$roles = ETR_Equipe::roles_equipe( wp_get_current_user() );
		$bureau = ETR_Equipe::bureau();
		$terrain = (bool) array_intersect( array( 'etr_preparateur', 'etr_livreur' ), $roles );
		if ( $bureau && $terrain ) {
			return __( 'Nouvelles demandes et commandes à préparer, même application fermée.', 'espace-traiteur' );
		}
		return $bureau
			? __( 'Nouvelles demandes des traiteurs, même application fermée.', 'espace-traiteur' )
			: __( 'Commandes validées à préparer et à livrer, même application fermée.', 'espace-traiteur' );
	}

	/**
	 * Page « Face ID » : activer sur cet appareil, voir et retirer ses appareils.
	 */
	private static function vue_face_id() {
		self::entete( __( 'Connexion par Face ID', 'espace-traiteur' ), __( 'Se connecter d’un regard, sans taper le mot de passe.', 'espace-traiteur' ) );
		$cles = ETR_Cles_Acces::cles( get_current_user_id() );
		echo '<section class="etr-panneau etr-notifs" data-etr-cles>';
		echo '<p class="etr-notifs__etat" data-cle-etat role="status">' . esc_html__( 'Vérification de cet appareil…', 'espace-traiteur' ) . '</p>';
		echo '<div class="etr-notifs__actions"><button type="button" class="etr-btn" data-cle-activer hidden>' . self::icone( 'visage' ) . '<span>' . esc_html__( 'Activer Face ID sur cet appareil', 'espace-traiteur' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="etr-muted">' . esc_html__( 'Votre iPhone crée une clé d’accès pour ce site, gardée dans votre trousseau et protégée par Face ID. Le site n’en garde que la partie publique : aucune donnée de votre visage n’est transmise. Le mot de passe reste valable.', 'espace-traiteur' ) . '</p>';
		echo '</section>';

		echo '<section class="etr-panneau etr-notifs"><h2 class="etr-panneau__titre">' . esc_html__( 'Appareils enregistrés', 'espace-traiteur' ) . '</h2>';
		if ( ! $cles ) {
			echo '<p class="etr-muted">' . esc_html__( 'Aucun pour l’instant.', 'espace-traiteur' ) . '</p>';
		} else {
			echo '<ul class="etr-envois__liste">';
			foreach ( $cles as $cle ) {
				/* translators: 1 : date d'ajout, 2 : dernière utilisation. */
				$quand = sprintf( __( 'Ajouté le %1$s · %2$s', 'espace-traiteur' ), wp_date( 'j M Y', $cle['cree'] ), $cle['utilise'] ? sprintf( /* translators: %s : date. */ __( 'utilisé le %s', 'espace-traiteur' ), wp_date( 'j M Y, H:i', $cle['utilise'] ) ) : __( 'jamais utilisé', 'espace-traiteur' ) );
				echo '<li><strong>' . esc_html( $cle['nom'] ? $cle['nom'] : __( 'Appareil', 'espace-traiteur' ) ) . '</strong><small>' . esc_html( $quand ) . '</small><button type="button" class="etr-lien" data-cle-retirer="' . esc_attr( $cle['id'] ) . '">' . esc_html__( 'Retirer', 'espace-traiteur' ) . '</button></li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}

	/**
	 * Page « Notifications » : activer, tester ou désactiver sur cet appareil.
	 */
	private static function vue_notifications() {
		self::entete( __( 'Notifications', 'espace-traiteur' ), __( 'Des alertes sur ce téléphone, même quand l’application est fermée.', 'espace-traiteur' ) );

		echo '<section class="etr-panneau etr-notifs" id="etr-notifs">';
		if ( ! ETR_Push::disponible() ) {
			echo '<p class="etr-notifs__etat etr-notifs__etat--erreur">' . esc_html__( 'Le serveur du site ne peut pas envoyer de notifications : l’extension PHP OpenSSL est absente ou trop ancienne. Demandez à l’hébergeur de l’activer.', 'espace-traiteur' ) . '</p>';
			echo '</section>';
			return;
		}
		echo '<p class="etr-notifs__etat" id="etr-notifs-etat" role="status">' . esc_html__( 'Vérification de cet appareil…', 'espace-traiteur' ) . '</p>';
		echo '<noscript><p class="etr-notifs__etat etr-notifs__etat--erreur">' . esc_html__( 'Activez JavaScript pour gérer les notifications.', 'espace-traiteur' ) . '</p></noscript>';
		echo '<div class="etr-notifs__actions">';
		echo '<button type="button" class="etr-btn" id="etr-notifs-activer" hidden>' . self::icone( 'cloche' ) . '<span>' . esc_html__( 'Activer sur cet appareil', 'espace-traiteur' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
		echo '<button type="button" class="etr-btn etr-btn--discret" id="etr-notifs-test" hidden>' . esc_html__( 'Envoyer une notification de test', 'espace-traiteur' ) . '</button>';
		echo '<button type="button" class="etr-btn etr-btn--discret" id="etr-notifs-desactiver" hidden>' . esc_html__( 'Désactiver sur cet appareil', 'espace-traiteur' ) . '</button>';
		echo '</div>';
		echo '</section>';

		$alertes = self::alertes_recues();
		echo '<section class="etr-panneau etr-notifs"><h2 class="etr-panneau__titre">' . esc_html__( 'Ce que vous recevez', 'espace-traiteur' ) . '</h2>';
		$types = ETR_Push::types_pour( get_current_user_id() );
		if ( $types ) {
			// Un interrupteur par type d'alerte (enregistré tout de suite, notifications.js).
			echo '<ul class="etr-interrupteurs" data-etr-preferences>';
			foreach ( $types as $type => $config ) {
				echo '<li><label><span>' . esc_html( $config['libelle'] ) . '</span><input type="checkbox" role="switch" value="' . esc_attr( $type ) . '"' . checked( $config['actif'], true, false ) . '></label></li>';
			}
			echo '</ul>';
		} elseif ( $alertes ) {
			echo '<ul class="etr-notifs__liste">';
			foreach ( $alertes as $alerte ) {
				echo '<li>' . esc_html( $alerte ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="etr-muted">' . esc_html__( 'Aucune alerte pour vos rôles actuels.', 'espace-traiteur' ) . '</p>';
		}
		echo '<p class="etr-muted">' . esc_html__( 'Vous ne recevez jamais d’alerte pour vos propres actions. Toucher une alerte ouvre la demande.', 'espace-traiteur' ) . '</p>';

		$appareils = ETR_Push::appareils( get_current_user_id() );
		if ( $appareils ) {
			echo '<h3 class="etr-notifs__sous-titre">' . esc_html__( 'Vos appareils', 'espace-traiteur' ) . '</h3><ul class="etr-notifs__liste">';
			foreach ( $appareils as $appareil ) {
				/* translators: 1 : appareil, 2 : date. */
				echo '<li>' . esc_html( sprintf( __( '%1$s, depuis le %2$s', 'espace-traiteur' ), $appareil['nom'] ? $appareil['nom'] : __( 'Appareil', 'espace-traiteur' ), wp_date( 'j M Y', (int) $appareil['depuis'] ) ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}

	/**
	 * Aide à l'installation sur iPhone (affichée par app.js dans Safari, si l'app n'est pas installée).
	 */
	private static function aide_installation() {
		echo '<aside class="etr-installer" id="etr-installer" hidden>';
		echo '<span class="etr-installer__icone" aria-hidden="true"><img src="' . esc_url( ETR_PWA::icone( 180 ) ) . '" alt="" width="44" height="44"></span>';
		echo '<p><strong>' . esc_html__( 'Installer l’application sur l’iPhone', 'espace-traiteur' ) . '</strong>';
		/* translators: Les guillemets désignent les boutons de Safari. */
		echo '<span>' . esc_html__( 'Dans Safari, touchez « Partager » (carré avec une flèche), puis « Sur l’écran d’accueil ».', 'espace-traiteur' ) . '</span></p>';
		echo '<button type="button" class="etr-installer__fermer" aria-label="' . esc_attr__( 'Fermer', 'espace-traiteur' ) . '">×</button>';
		echo '</aside>';
	}

	/**
	 * Icône.
	 *
	 * @param string $nom Nom.
	 * @return string
	 */
	public static function icone( $nom ) {
		return ETR_Frontend::icone( $nom );
	}

	/**
	 * Nombre d'onglets visibles dans l'application installée sur téléphone (les autres vont dans « Plus »).
	 */
	const ONGLETS_TELEPHONE = 4;

	/**
	 * Pastilles des onglets : demandes à traiter (bureau), étapes de la tournée du jour (livreurs).
	 *
	 * @return int[] Vue => nombre.
	 */
	private static function pastilles() {
		$pastilles = array();
		if ( ETR_Equipe::bureau() ) {
			$pastilles['demandes'] = count( self::filtrer_statut( 'en_attente' ) );
		}
		if ( ETR_Equipe::livreur() && ! ETR_Equipe::bureau() ) {
			$tournee               = ETR_Operations::tournee_de( current_datetime()->format( 'Y-m-d' ), get_current_user_id() );
			$pastilles['tournee'] = count( $tournee['livraisons'] ) + count( $tournee['reprises'] );
		}
		return array_filter( $pastilles );
	}

	/**
	 * Nombre affiché sur l'icône de l'application (iPhone) : même règle que les pastilles.
	 *
	 * @return int
	 */
	public static function pastille_app() {
		return (int) array_sum( self::pastilles() );
	}

	/**
	 * Liens du compte (notifications, réglages, déconnexion…).
	 *
	 * @return array[] { url, libellé, icône }
	 */
	private static function liens_compte() {
		$liens = array(
			array( self::url( array( 'vue' => 'recherche' ) ), __( 'Rechercher', 'espace-traiteur' ), 'recherche' ),
			array( self::url( array( 'vue' => 'notifications' ) ), __( 'Notifications', 'espace-traiteur' ), 'cloche' ),
		);
		if ( ETR_Cles_Acces::disponible() ) {
			$liens[] = array( self::url( array( 'vue' => 'face-id' ) ), __( 'Connexion par Face ID', 'espace-traiteur' ), 'visage' );
		}
		if ( ETR_Equipe::bureau() ) {
			$liens[] = array( etr_url(), __( 'Voir l’espace traiteur', 'espace-traiteur' ), 'site' );
			$liens[] = array( admin_url( 'edit.php?post_type=etr_demande&page=etr-reglages' ), __( 'Réglages', 'espace-traiteur' ), 'reglages' );
			$liens[] = array( admin_url(), __( 'Administration WordPress', 'espace-traiteur' ), 'externe' );
		}
		$liens[] = array( wp_logout_url( self::url() ), __( 'Se déconnecter', 'espace-traiteur' ), 'sortie' );
		return $liens;
	}

	/**
	 * Barre latérale (ordinateur) ; sur téléphone : en-tête compact et barre d'onglets en bas,
	 * avec un onglet « Plus » pour le reste (comme les applications iPhone).
	 *
	 * @param string $actif Vue active.
	 */
	private static function barre_laterale( $actif ) {
		$user      = wp_get_current_user();
		$logo_id   = ETR_Comptes::logo_id();
		$site      = get_bloginfo( 'name' );
		$pastilles = self::pastilles();
		$vues      = self::vues();
		$cles      = array_keys( $vues );
		$dans_plus = ! in_array( $actif, array_slice( $cles, 0, self::ONGLETS_TELEPHONE ), true );

		echo '<header class="etr-app__barre">';
		echo '<a class="etr-app__logo" href="' . esc_url( self::url() ) . '">';
		if ( $logo_id ) {
			echo wp_get_attachment_image( $logo_id, 'medium', false, array( 'alt' => $site ) );
		} else {
			echo '<span class="etr-app__nom-site">' . esc_html( $site ) . '</span>';
		}
		echo '<span class="etr-gestion__marque">' . esc_html__( 'Gestion des traiteurs', 'espace-traiteur' ) . '</span>';
		echo '</a>';
		// Titre compact : affiché dans l'en-tête quand le grand titre de la page a défilé (app.js).
		echo '<p class="etr-app__titre-compact" aria-hidden="true"></p>';
		echo '<a class="etr-app__chercher" href="' . esc_url( self::url( array( 'vue' => 'recherche' ) ) ) . '" aria-label="' . esc_attr__( 'Rechercher', 'espace-traiteur' ) . '">' . self::icone( 'recherche' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// Libellés courts de la barre d'onglets (application installée sur téléphone).
		$courts = array(
			'tableau'      => __( 'Accueil', 'espace-traiteur' ),
			'statistiques' => __( 'Stats', 'espace-traiteur' ),
		);
		echo '<nav class="etr-app__nav" aria-label="' . esc_attr__( 'Gestion', 'espace-traiteur' ) . '"><ul>';
		foreach ( $cles as $rang => $cle ) {
			$vue   = $vues[ $cle ];
			$badge = isset( $pastilles[ $cle ] ) ? '<span class="etr-gestion__pastille">' . esc_html( number_format_i18n( $pastilles[ $cle ] ) ) . '</span>' : '';
			$court = isset( $courts[ $cle ] ) ? $courts[ $cle ] : $vue[0];
			echo '<li' . ( $rang >= self::ONGLETS_TELEPHONE ? ' class="etr-app__onglet-secondaire"' : '' ) . '><a href="' . esc_url( self::url( 'tableau' === $cle ? array() : array( 'vue' => $cle ) ) ) . '"' . ( $cle === $actif ? ' aria-current="page"' : '' ) . '>' . self::icone( $vue[1] ) . '<span class="etr-app__nav-libelle">' . esc_html( $vue[0] ) . '</span><span class="etr-app__nav-court" aria-hidden="true">' . esc_html( $court ) . '</span>' . $badge . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique, badge échappé.
		}
		// Onglet « Plus » (application installée sur téléphone seulement).
		echo '<li class="etr-app__onglet-plus"><button type="button" data-ouvrir-feuille="etr-plus"' . ( $dans_plus ? ' aria-current="page"' : '' ) . '>' . self::icone( 'plus-onglet' ) . '<span class="etr-app__nav-court">' . esc_html__( 'Plus', 'espace-traiteur' ) . '</span></button></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</ul></nav>';

		echo '<div class="etr-app__compte">';
		$role = ETR_Equipe::libelle_role( $user );
		echo '<p class="etr-app__societe">' . esc_html( etr_nom( $user ) ) . '<span>' . esc_html( $role ? $role : $user->user_email ) . '</span></p>';
		echo '<ul class="etr-app__liens">';
		foreach ( self::liens_compte() as $lien ) {
			echo '<li><a href="' . esc_url( $lien[0] ) . '">' . self::icone( $lien[2] ) . '<span>' . esc_html( $lien[1] ) . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></div>';
		echo '</header>';

		// Feuille « Plus » : les autres écrans et le compte.
		echo '<dialog class="etr-feuille" id="etr-plus" aria-labelledby="etr-plus-titre"><div class="etr-feuille__poignee" aria-hidden="true"></div>';
		echo '<div class="etr-feuille__tete"><p class="etr-feuille__titre" id="etr-plus-titre">' . esc_html( etr_nom( $user ) ) . '<small>' . esc_html( $role ? $role : $user->user_email ) . '</small></p><button type="button" class="etr-feuille__fermer" data-fermer-feuille aria-label="' . esc_attr__( 'Fermer', 'espace-traiteur' ) . '">×</button></div>';
		$secondaires = array_slice( $cles, self::ONGLETS_TELEPHONE );
		// Écrans classés : le terrain, puis la gestion (les écrans ajoutés vont en gestion), puis le compte.
		$terrain = array( 'tournee', 'scanner', 'planning', 'demandes' );
		$groupes = array(
			__( 'Terrain', 'espace-traiteur' ) => array_values( array_intersect( $secondaires, $terrain ) ),
			__( 'Gestion', 'espace-traiteur' ) => array_values( array_diff( $secondaires, $terrain ) ),
		);
		foreach ( $groupes as $titre => $liste ) {
			if ( ! $liste ) {
				continue;
			}
			echo '<p class="etr-feuille__groupe">' . esc_html( $titre ) . '</p><ul class="etr-feuille__liste">';
			foreach ( $liste as $cle ) {
				$badge = isset( $pastilles[ $cle ] ) ? '<span class="etr-gestion__pastille">' . esc_html( number_format_i18n( $pastilles[ $cle ] ) ) . '</span>' : '';
				echo '<li><a href="' . esc_url( self::url( array( 'vue' => $cle ) ) ) . '"' . ( $cle === $actif ? ' aria-current="page"' : '' ) . '>' . self::icone( $vues[ $cle ][1] ) . '<span>' . esc_html( $vues[ $cle ][0] ) . '</span>' . $badge . self::icone( 'suite' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul>';
		}
		echo '<p class="etr-feuille__groupe">' . esc_html__( 'Compte', 'espace-traiteur' ) . '</p><ul class="etr-feuille__liste">';
		foreach ( self::liens_compte() as $lien ) {
			echo '<li><a href="' . esc_url( $lien[0] ) . '"' . ( 'sortie' === $lien[2] ? ' class="est-danger"' : '' ) . '>' . self::icone( $lien[2] ) . '<span>' . esc_html( $lien[1] ) . '</span>' . ( 'sortie' === $lien[2] ? '' : self::icone( 'suite' ) ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></dialog>';
	}

	/**
	 * En-tête de page.
	 *
	 * @param string $titre  Titre.
	 * @param string $sous   Sous-titre (texte brut).
	 * @param string $action HTML échappé (bouton, badge).
	 * @param string $retour HTML échappé (lien de retour).
	 */
	public static function entete( $titre, $sous = '', $action = '', $retour = '' ) {
		echo '<div class="etr-page-entete">';
		echo '<div class="etr-page-entete__texte">';
		echo $retour; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé par l'appelant.
		echo '<h1 class="etr-page-entete__titre">' . esc_html( $titre ) . '</h1>';
		if ( $sous ) {
			echo '<p class="etr-page-entete__sous">' . esc_html( $sous ) . '</p>';
		}
		echo '</div>';
		echo $action; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé par l'appelant.
		echo '</div>';
	}

	/**
	 * Messages après une action.
	 */
	private static function messages() {
		$msg    = isset( $_GET['etr_msg'] ) ? sanitize_key( wp_unslash( $_GET['etr_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$succes = array(
			'statut'     => __( 'Statut enregistré. Le traiteur a été prévenu par e-mail.', 'espace-traiteur' ),
			'livree'     => __( 'Livraison enregistrée : la demande est « Livrée », le bureau et le traiteur sont prévenus.', 'espace-traiteur' ),
			'reprise'    => __( 'Reprise enregistrée : tout est revenu, la demande est clôturée.', 'espace-traiteur' ),
			'reprise_ecarts' => __( 'Reprise enregistrée avec des écarts : le bureau est prévenu.', 'espace-traiteur' ),
			'incident'   => __( 'Incident envoyé : le bureau est prévenu.', 'espace-traiteur' ),
			'enregistre' => __( 'Message enregistré. Le statut n’a pas changé : aucun e-mail n’a été envoyé.', 'espace-traiteur' ),
			'tarif'      => __( 'Remise et frais de livraison enregistrés.', 'espace-traiteur' ),
			'cree'       => __( 'Traiteur ajouté. Il a reçu un e-mail pour choisir son mot de passe.', 'espace-traiteur' ),
			'cree_sans_invitation' => __( 'Traiteur ajouté, sans invitation. Cliquez sur « Envoyer l’invitation » dans la liste quand vous êtes prêt.', 'espace-traiteur' ),
			'invite'     => __( 'Invitation envoyée.', 'espace-traiteur' ),
			'role'       => __( 'Rôles enregistrés.', 'espace-traiteur' ),
			'membre_cree' => __( 'Personne ajoutée. Elle a reçu un e-mail pour choisir son mot de passe et installer l’application.', 'espace-traiteur' ),
			'membre_traiteur' => __( 'Ce compte traiteur fait maintenant partie de l’équipe : il se connecte à l’application avec son mot de passe habituel, et garde son espace traiteur.', 'espace-traiteur' ),
			'membre_cree_sans_invitation' => __( 'Personne ajoutée, sans invitation. Cliquez sur « Envoyer l’invitation » dans la liste quand vous êtes prêt.', 'espace-traiteur' ),
			'retire'     => __( 'Accès retiré : la personne est déconnectée et ne peut plus entrer dans l’application.', 'espace-traiteur' ),
			'rendu'      => __( 'Accès rendu.', 'espace-traiteur' ),
			'desactive'  => __( 'Accès désactivé : le traiteur est déconnecté et ne peut plus se connecter.', 'espace-traiteur' ),
			'reactive'   => __( 'Accès réactivé.', 'espace-traiteur' ),
			'fait'       => __( 'Enregistré.', 'espace-traiteur' ),
			'ordre_optimise' => __( 'Ordre de passage optimisé.', 'espace-traiteur' ),
			'facture_envoyee' => __( 'Facture envoyée au traiteur.', 'espace-traiteur' ),
			'message_envoye' => __( 'Message envoyé : le traiteur est prévenu.', 'espace-traiteur' ),
			'dates'      => __( 'Dates enregistrées : le traiteur est prévenu.', 'espace-traiteur' ),
			'devis_envoye' => __( 'Devis envoyé au traiteur.', 'espace-traiteur' ),
			'releve_envoye' => __( 'Relevé de casse et de manquants envoyé au traiteur.', 'espace-traiteur' ),
		);
		$alertes = array(
			'cree_sans_mail' => __( 'Traiteur ajouté, mais l’e-mail d’invitation n’est pas parti. Renvoyez l’invitation depuis la liste.', 'espace-traiteur' ),
			'mail_echec'     => __( 'L’e-mail n’est pas parti. Vérifiez la configuration des e-mails du site.', 'espace-traiteur' ),
			'membre_cree_sans_mail' => __( 'Personne ajoutée, mais l’e-mail d’invitation n’est pas parti. Vérifiez la configuration des e-mails du site, puis renvoyez l’invitation depuis la liste.', 'espace-traiteur' ),
			'roles_vides'    => __( 'Une personne doit garder au moins un rôle. Pour lui fermer l’application, utilisez « Retirer l’accès ».', 'espace-traiteur' ),
			'dates_erreur'   => __( 'Dates non enregistrées.', 'espace-traiteur' ),
			'stripe_erreur'  => __( 'Opération Stripe non effectuée.', 'espace-traiteur' ),
			'ordre_impossible' => __( 'Optimisation impossible : adresse de départ introuvable (Espace traiteur › Fonctions › Estimation de la livraison).', 'espace-traiteur' ),
		);
		if ( isset( $succes[ $msg ] ) ) {
			echo '<div class="etr-notice etr-notice--succes" role="status">' . self::icone( 'coche' ) . '<p>' . esc_html( $succes[ $msg ] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( isset( $alertes[ $msg ] ) ) {
			$raison = in_array( $msg, array( 'cree_sans_mail', 'mail_echec', 'membre_cree_sans_mail' ), true ) ? etr_raison_echec_mail() : '';
			if ( 'dates_erreur' === $msg || 'stripe_erreur' === $msg ) {
				$raison = (string) get_transient( 'etr_erreur_dates_' . get_current_user_id() );
				delete_transient( 'etr_erreur_dates_' . get_current_user_id() );
			}
			/* translators: %s : message d'erreur technique. */
			$raison = $raison ? '<br><small>' . esc_html( sprintf( __( 'Raison : %s', 'espace-traiteur' ), $raison ) ) . '</small>' : '';
			echo '<div class="etr-notice etr-notice--erreur" role="alert">' . self::icone( 'alerte' ) . '<p>' . esc_html( $alertes[ $msg ] ) . $raison . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Lien vers une demande.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function url_demande( $id ) {
		return self::url(
			array(
				'vue' => 'demande',
				'id'  => $id,
			)
		);
	}

	/**
	 * Nom du traiteur d'une demande.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	private static function traiteur( $id ) {
		return etr_nom( get_userdata( (int) get_post_field( 'post_author', $id ) ) );
	}

	/**
	 * Libellés des mouvements logistiques.
	 *
	 * @return array
	 */
	private static function types_mouvement() {
		return array(
			'livraison' => __( 'Livraison', 'espace-traiteur' ),
			'evenement' => __( 'Événement', 'espace-traiteur' ),
			'reprise'   => __( 'Reprise', 'espace-traiteur' ),
		);
	}

	/**
	 * Un mouvement (lien compact).
	 *
	 * @param array $mvt { type, id }.
	 * @return string
	 */
	private static function mouvement_html( $mvt ) {
		$types   = self::types_mouvement();
		$attente = 'en_attente' === etr_get_statut( $mvt['id'] );
		$attrs   = (string) apply_filters( 'etr_planning_mouvement_attributs', '', $mvt );
		$html    = '<a class="etr-mvt etr-mvt--' . esc_attr( $mvt['type'] ) . ( $attente ? ' etr-mvt--attente' : '' ) . '" href="' . esc_url( self::url_demande( $mvt['id'] ) ) . '"' . $attrs . '>';
		$html   .= '<span class="etr-mvt__type">' . esc_html( $types[ $mvt['type'] ] ) . ( $attente ? ' <em>' . esc_html__( 'à confirmer', 'espace-traiteur' ) . '</em>' : '' ) . '</span>';
		$html   .= '<span class="etr-mvt__titre">' . esc_html( self::traiteur( $mvt['id'] ) ) . '</span>';
		$html   .= '<span class="etr-mvt__sous">' . esc_html( get_the_title( $mvt['id'] ) ) . '</span>';
		$html   .= '</a>';
		return $html;
	}

	/**
	 * Demandes d'un statut.
	 *
	 * @param string $statut Statut.
	 * @return int[]
	 */
	private static function filtrer_statut( $statut ) {
		return array_values(
			array_filter(
				self::demandes(),
				function ( $id ) use ( $statut ) {
					return etr_get_statut( $id ) === $statut;
				}
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Tableau de bord                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Pilotage basé sur les demandes et les disponibilités actuelles.
	 */
	private static function vue_statistiques() {
		$stats = ETR_Statistiques::calculer( self::demandes(), self::aujourdhui() );
		self::entete(
			__( 'Statistiques', 'espace-traiteur' ),
			__( 'Demandes validées, livrées ou clôturées, regroupées par date d’événement.', 'espace-traiteur' )
		);
		$total       = array_sum( array_column( $stats['mois'], 'total' ) );
		$nb_demandes = array_sum( array_column( $stats['mois'], 'demandes' ) );
		$incomplets  = array_sum( array_column( $stats['mois'], 'incomplets' ) );

		echo '<div class="etr-stats">';
		echo '<section class="etr-stats__apercu" aria-labelledby="etr-stats-apercu">';
		echo '<div class="etr-stats__apercu-intro"><h2 id="etr-stats-apercu">' . esc_html__( 'Vue d’ensemble', 'espace-traiteur' ) . '</h2>';
		echo '<p>' . esc_html__( 'Toutes les demandes retenues, passées et à venir.', 'espace-traiteur' ) . '</p></div>';
		echo '<dl class="etr-stats__indicateurs">';
		echo '<div><dt>' . esc_html__( 'Total indicatif', 'espace-traiteur' ) . '</dt><dd>' . ( $stats['mois'] ? wp_kses_post( etr_format_prix( $total ) ) : '—' ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Demandes retenues', 'espace-traiteur' ) . '</dt><dd>' . esc_html( number_format_i18n( $nb_demandes ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Chiffrages à compléter', 'espace-traiteur' ) . '</dt><dd>' . esc_html( number_format_i18n( $incomplets ) ) . '</dd></div>';
		echo '</dl></section>';
		echo '<p class="etr-stats__note">' . esc_html__( 'Montants indicatifs calculés avec les prix actuels, les remises et les frais renseignés : ce ne sont pas des encaissements. Les besoins hors catalogue et les prix manquants ne sont pas chiffrés.', 'espace-traiteur' ) . '</p>';

		echo '<section class="etr-stats__section" aria-labelledby="etr-stats-mois"><div class="etr-stats__entete"><div><h2 id="etr-stats-mois" class="etr-section-titre">' . esc_html__( 'Chiffre d’affaires indicatif par mois', 'espace-traiteur' ) . '</h2>';
		echo '<p>' . esc_html__( 'Répartition selon le mois de l’événement.', 'espace-traiteur' ) . '</p></div></div>';
		if ( ! $stats['mois'] ) {
			echo '<p class="etr-stats__vide">' . esc_html__( 'Aucune demande validée, livrée ou clôturée à afficher.', 'espace-traiteur' ) . '</p>';
		} else {
			echo '<div class="etr-table-cadre" tabindex="0" role="region" aria-labelledby="etr-stats-mois"><table class="etr-table etr-stats__table etr-stats__table--mois"><thead><tr><th scope="col">' . esc_html__( 'Mois', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Demandes', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Total indicatif', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Chiffrages à compléter', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
			foreach ( $stats['mois'] as $mois => $ligne ) {
				$libelle_mois = date_i18n( 'F Y', strtotime( $mois . '-01' ) );
				$etat = $ligne['incomplets']
					? sprintf( __( '%s à compléter', 'espace-traiteur' ), number_format_i18n( $ligne['incomplets'] ) )
					: __( '0 — complet', 'espace-traiteur' );
				echo '<tr><td data-label="' . esc_attr__( 'Mois', 'espace-traiteur' ) . '"><time datetime="' . esc_attr( $mois ) . '">' . esc_html( $libelle_mois ) . '</time></td><td data-label="' . esc_attr__( 'Demandes', 'espace-traiteur' ) . '" class="etr-table__nombre">' . esc_html( number_format_i18n( $ligne['demandes'] ) ) . '</td><td data-label="' . esc_attr__( 'Total indicatif', 'espace-traiteur' ) . '" class="etr-table__nombre etr-stats__montant">' . wp_kses_post( etr_format_prix( $ligne['total'] ) ) . '</td><td data-label="' . esc_attr__( 'Chiffrages à compléter', 'espace-traiteur' ) . '" class="etr-table__nombre"><span class="etr-stats__etat' . ( $ligne['incomplets'] ? ' etr-stats__etat--attention' : '' ) . '">' . esc_html( $etat ) . '</span></td></tr>';
			}
			echo '</tbody></table></div>';
			echo '<p class="etr-stats__note">' . esc_html__( 'À compléter : prix ou frais de livraison manquants, ou besoins hors catalogue.', 'espace-traiteur' ) . '</p>';
		}
		echo '</section>';

		echo '<div class="etr-stats__classements"><div class="etr-stats__colonne">';
		echo '<section class="etr-stats__section" aria-labelledby="etr-stats-traiteurs"><div class="etr-stats__entete"><div><h2 id="etr-stats-traiteurs" class="etr-section-titre">' . esc_html__( 'Meilleurs traiteurs', 'espace-traiteur' ) . '</h2><p>' . esc_html__( 'Classés par total indicatif.', 'espace-traiteur' ) . '</p></div></div>';
		if ( ! $stats['traiteurs'] ) {
			echo '<p class="etr-stats__vide">' . esc_html__( 'Aucun traiteur à afficher.', 'espace-traiteur' ) . '</p>';
		} else {
			echo '<div class="etr-table-cadre" tabindex="0" role="region" aria-labelledby="etr-stats-traiteurs"><table class="etr-table etr-stats__table"><thead><tr><th scope="col">' . esc_html__( 'Traiteur', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Demandes', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Total indicatif', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $stats['traiteurs'], 0, 10, true ) as $id => $ligne ) {
				$user = get_userdata( $id );
				$nom  = $user ? etr_nom( $user ) : sprintf( __( 'Compte supprimé #%d', 'espace-traiteur' ), $id );
				echo '<tr><td>' . esc_html( $nom ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $ligne['demandes'] ) ) . '</td><td class="etr-table__nombre etr-stats__montant">' . wp_kses_post( etr_format_prix( $ligne['total'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		echo '<section class="etr-stats__section" aria-labelledby="etr-stats-stock"><div class="etr-stats__entete"><div><h2 id="etr-stats-stock" class="etr-section-titre">' . esc_html__( 'Articles complets sur les réservations actives', 'espace-traiteur' ) . '</h2>';
		echo '<p>' . esc_html__( 'Demandes actives dont la période atteint ou dépasse le stock actuel.', 'espace-traiteur' ) . '</p></div></div>';
		if ( ! $stats['ruptures'] ) {
			echo '<p class="etr-stats__vide">' . esc_html__( 'Aucun article à stock suivi n’est complet sur les périodes actives.', 'espace-traiteur' ) . '</p>';
		} else {
			echo '<div class="etr-table-cadre" tabindex="0" role="region" aria-labelledby="etr-stats-stock"><table class="etr-table etr-stats__table"><thead><tr><th scope="col">' . esc_html__( 'Article', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Demandes concernées', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $stats['ruptures'], 0, 10, true ) as $ligne ) {
				echo '<tr><td>' . esc_html( $ligne['titre'] ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $ligne['demandes'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '<p class="etr-stats__note">' . esc_html__( 'Les stocks non suivis sont exclus. Les chevauchements sont calculés prudemment ; ce n’est pas un historique des ruptures.', 'espace-traiteur' ) . '</p>';
		echo '</section></div>';

		echo '<section class="etr-stats__section" aria-labelledby="etr-stats-articles"><div class="etr-stats__entete"><div><h2 id="etr-stats-articles" class="etr-section-titre">' . esc_html__( 'Articles les plus demandés', 'espace-traiteur' ) . '</h2><p>' . esc_html__( 'Quantités cumulées sur les demandes retenues.', 'espace-traiteur' ) . '</p></div></div>';
		if ( ! $stats['articles'] ) {
			echo '<p class="etr-stats__vide">' . esc_html__( 'Aucun article à afficher.', 'espace-traiteur' ) . '</p>';
		} else {
			$maximum = max( array_column( $stats['articles'], 'quantite' ) );
			echo '<div class="etr-table-cadre" tabindex="0" role="region" aria-labelledby="etr-stats-articles"><table class="etr-table etr-stats__table"><thead><tr><th scope="col">' . esc_html__( 'Article', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Quantité', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $stats['articles'], 0, 10, true ) as $ligne ) {
				$largeur = $maximum > 0 ? max( 0, min( 100, 100 * $ligne['quantite'] / $maximum ) ) : 0;
				echo '<tr><td><span class="etr-stats__article">' . esc_html( $ligne['titre'] ) . '</span><span class="etr-stats__barre" aria-hidden="true"><span style="width:' . esc_attr( number_format( $largeur, 2, '.', '' ) ) . '%"></span></span></td><td class="etr-table__nombre etr-stats__montant">' . esc_html( number_format_i18n( $ligne['quantite'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section></div></div>';
		do_action( 'etr_gestion_statistiques' ); // Fonctions activables : pilotage du terrain.
	}

	/**
	 * Tableau de bord : chiffres du moment, semaine logistique, demandes à traiter.
	 */
	private static function vue_tableau() {
		$jour      = self::aujourdhui();
		$fin       = gmdate( 'Y-m-d', strtotime( $jour . ' +6 days' ) );
		$a_traiter = self::trier( self::filtrer_statut( 'en_attente' ) );
		$a_venir   = array_filter(
			self::filtrer_statut( 'validee' ),
			function ( $id ) use ( $jour ) {
				return self::date( $id, 'evenement' ) >= $jour;
			}
		);
		$semaine   = self::mouvements( $jour, $fin );
		$compte    = array(
			'livraison' => 0,
			'reprise'   => 0,
		);
		foreach ( $semaine as $liste ) {
			foreach ( $liste as $mvt ) {
				if ( isset( $compte[ $mvt['type'] ] ) ) {
					++$compte[ $mvt['type'] ];
				}
			}
		}

		$prenom = wp_get_current_user()->first_name ? wp_get_current_user()->first_name : etr_nom( wp_get_current_user() );
		/* translators: %s : prénom. */
		self::entete( sprintf( __( 'Bonjour %s', 'espace-traiteur' ), $prenom ), ucfirst( date_i18n( 'l j F Y' ) ) );

		$chiffres = array(
			array( count( $a_traiter ), __( 'demandes à traiter', 'espace-traiteur' ), self::url( array( 'vue' => 'demandes', 'statut' => 'en_attente' ) ), (bool) $a_traiter ),
			array( count( $a_venir ), __( 'événements validés à venir', 'espace-traiteur' ), self::url( array( 'vue' => 'demandes', 'statut' => 'validee' ) ), false ),
			array( $compte['livraison'], __( 'livraisons dans les 7 jours', 'espace-traiteur' ), self::url( array( 'vue' => 'planning' ) ), false ),
			array( $compte['reprise'], __( 'reprises dans les 7 jours', 'espace-traiteur' ), self::url( array( 'vue' => 'planning' ) ), false ),
		);
		echo '<ul class="etr-chiffres">';
		foreach ( $chiffres as $c ) {
			echo '<li' . ( $c[3] ? ' class="etr-chiffres--accent"' : '' ) . '><a href="' . esc_url( $c[2] ) . '"><strong>' . esc_html( number_format_i18n( $c[0] ) ) . '</strong><span>' . esc_html( $c[1] ) . '</span></a></li>';
		}
		echo '</ul>';

		// Ce que le bureau doit faire : devis, acomptes, factures, incidents, messages…
		ETR_Fiche::a_faire(
			array_values(
				array_filter(
					self::demandes(),
					function ( $id ) {
						return in_array( etr_get_statut( $id ), array( 'en_attente', 'validee', 'livree', 'cloturee' ), true );
					}
				)
			)
		);

		// Semaine logistique.
		echo '<section class="etr-semaine" aria-labelledby="etr-semaine-titre">';
		echo '<div class="etr-section-entete"><h2 id="etr-semaine-titre" class="etr-section-titre">' . esc_html__( 'Les 7 prochains jours', 'espace-traiteur' ) . '</h2>';
		echo '<a class="etr-lien" href="' . esc_url( self::url( array( 'vue' => 'planning' ) ) ) . '">' . esc_html__( 'Voir le planning du mois', 'espace-traiteur' ) . '</a></div>';
		echo '<ol class="etr-semaine__jours">';
		for ( $i = 0; $i < 7; $i++ ) {
			$date = gmdate( 'Y-m-d', strtotime( $jour . ' +' . $i . ' days' ) );
			$ts   = strtotime( $date );
			echo '<li class="etr-semaine__jour' . ( 0 === $i ? ' etr-semaine__jour--aujourdhui' : '' ) . ( in_array( (int) gmdate( 'N', $ts ), array( 6, 7 ), true ) ? ' etr-semaine__jour--we' : '' ) . '">';
			echo '<p class="etr-semaine__date"><span>' . esc_html( 0 === $i ? __( 'Aujourd’hui', 'espace-traiteur' ) : ucfirst( date_i18n( 'l', $ts ) ) ) . '</span><time datetime="' . esc_attr( $date ) . '">' . esc_html( date_i18n( 'j', $ts ) ) . '</time></p>';
			if ( empty( $semaine[ $date ] ) ) {
				echo '<p class="etr-semaine__rien">' . esc_html__( 'Rien de prévu', 'espace-traiteur' ) . '</p>';
			} else {
				echo '<ul>';
				foreach ( $semaine[ $date ] as $mvt ) {
					echo '<li>' . self::mouvement_html( $mvt ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé.
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ol></section>';

		// Demandes à traiter.
		echo '<section class="etr-file" aria-labelledby="etr-file-titre">';
		echo '<div class="etr-section-entete"><h2 id="etr-file-titre" class="etr-section-titre">' . esc_html__( 'À traiter', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $a_traiter ) ) ) . '</span></h2>';
		if ( count( $a_traiter ) > 8 ) {
			echo '<a class="etr-lien" href="' . esc_url( self::url( array( 'vue' => 'demandes', 'statut' => 'en_attente' ) ) ) . '">' . esc_html__( 'Toutes les demandes à traiter', 'espace-traiteur' ) . '</a>';
		}
		echo '</div>';
		if ( ! $a_traiter ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Aucune demande en attente. Les nouvelles demandes des traiteurs apparaîtront ici.', 'espace-traiteur' ) . '</p></div>';
		} else {
			self::table_demandes( array_slice( $a_traiter, 0, 8 ), true );
		}
		echo '</section>';
	}

	/**
	 * Tableau de demandes.
	 *
	 * @param int[] $ids         Demandes.
	 * @param bool  $avec_manque Signale le matériel insuffisant.
	 */
	private static function table_demandes( $ids, $avec_manque = false ) {
		echo '<div class="etr-table-cadre etr-table-cadre--demandes"><table class="etr-table etr-table--demandes">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Événement', 'espace-traiteur' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Traiteur', 'espace-traiteur' ) . '</th>';
		echo '<th scope="col" class="etr-table__logistique">' . esc_html__( 'Livraison et reprise', 'espace-traiteur' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Matériel', 'espace-traiteur' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Statut', 'espace-traiteur' ) . '</th>';
		echo '</tr></thead><tbody>';
		$jour = self::aujourdhui();
		foreach ( $ids as $id ) {
			$date   = self::date( $id, 'evenement' );
			$lieu   = ETR_Frontend::lieu_court( $id );
			$manque = $avec_manque ? self::manques( $id ) : array();
			echo '<tr' . ( $date && $date < $jour ? ' class="etr-table__passee"' : '' ) . '>';
			$actions = self::actions_glisser( $id );
			echo '<td><a class="etr-table__principal" href="' . esc_url( self::url_demande( $id ) ) . '">' . ETR_Frontend::bloc_date( $date ) . '<span><strong>' . esc_html( get_the_title( $id ) ) . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput -- bloc échappé.
			echo '<small>' . esc_html( $lieu ? $lieu : __( 'Lieu non précisé', 'espace-traiteur' ) ) . '</small></span></a></td>';
			echo '<td>' . esc_html( self::traiteur( $id ) ) . '<small>' . esc_html(
				/* translators: %s : durée (« 2 jours »). */
				sprintf( __( 'Reçue il y a %s', 'espace-traiteur' ), human_time_diff( get_post_time( 'U', true, $id ), time() ) )
			) . '</small></td>';
			$livraison = self::date( $id, 'livraison' );
			$reprise   = self::date( $id, 'reprise' );
			echo '<td class="etr-table__logistique">' . esc_html( $livraison ? date_i18n( 'j M', strtotime( $livraison ) ) : '–' ) . '<span class="etr-table__fleche" aria-hidden="true"></span>' . esc_html( $reprise ? date_i18n( 'j M', strtotime( $reprise ) ) : '–' ) . '</td>';
			echo '<td>' . esc_html( ETR_Frontend::texte_articles( $id ) );
			if ( $manque ) {
				/* translators: %s : nombre d'articles. */
				echo '<small class="etr-manque">' . self::icone( 'alerte' ) . esc_html( sprintf( _n( '%s article insuffisant', '%s articles insuffisants', count( $manque ), 'espace-traiteur' ), number_format_i18n( count( $manque ) ) ) ) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</td>';
			echo '<td class="etr-table__statut">' . etr_statut_badge( etr_get_statut( $id ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $actions ) {
				echo '<td class="etr-glisser__actions" aria-hidden="true">' . $actions . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans actions_glisser().
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Actions révélées en glissant une demande vers la gauche (téléphone) : appeler, itinéraire.
	 * Ce sont des raccourcis : les mêmes informations sont sur la fiche.
	 *
	 * @param int $id Demande.
	 * @return string HTML (vide si aucune action).
	 */
	private static function actions_glisser( $id ) {
		$html      = '';
		$telephone = (string) get_user_meta( (int) get_post_field( 'post_author', $id ), 'etr_telephone', true );
		$numero    = preg_replace( '/[^\d+]/', '', $telephone );
		$lieu      = trim( (string) get_post_meta( $id, '_etr_lieu', true ) );
		if ( strlen( $numero ) >= 6 ) {
			$html .= '<a class="etr-glisser__action etr-glisser__action--appel" href="tel:' . esc_attr( $numero ) . '" tabindex="-1">' . self::icone( 'telephone' ) . '<span>' . esc_html__( 'Appeler', 'espace-traiteur' ) . '</span></a>';
		}
		if ( '' !== $lieu ) {
			$html .= '<a class="etr-glisser__action etr-glisser__action--route" href="' . esc_url( ETR_Terrain::url_itineraire( $lieu ) ) . '" target="_blank" rel="noopener" tabindex="-1">' . self::icone( 'lieu' ) . '<span>' . esc_html__( 'Itinéraire', 'espace-traiteur' ) . '</span></a>';
		}
		return $html;
	}

	/* ------------------------------------------------------------------ */
	/* Demandes                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Demandes filtrées (traiteur, recherche), puis par statut, triées.
	 *
	 * @param string $statut   Statut ('' = tous).
	 * @param int    $traiteur Traiteur (0 = tous).
	 * @param string $q        Recherche.
	 * @return array{0:int[],1:int[]} Demandes sans filtre de statut, liste finale triée.
	 */
	private static function filtrer_demandes( $statut, $traiteur, $q ) {
		$base  = array_filter(
			self::demandes(),
			function ( $id ) use ( $traiteur, $q ) {
				if ( $traiteur && (int) get_post_field( 'post_author', $id ) !== $traiteur ) {
					return false;
				}
				if ( '' === $q ) {
					return true;
				}
				$texte = implode( ' ', array( $id, get_the_title( $id ), self::traiteur( $id ), get_post_meta( $id, '_etr_lieu', true ), get_post_meta( $id, '_etr_contact', true ) ) );
				return false !== stripos( remove_accents( $texte ), remove_accents( $q ) );
			}
		);
		$liste = $statut ? array_filter(
			$base,
			function ( $id ) use ( $statut ) {
				return etr_get_statut( $id ) === $statut;
			}
		) : $base;
		return array( $base, self::trier( array_values( $liste ) ) );
	}

	/**
	 * Recherche globale : demandes (n°, événement, traiteur, lieu, contact) et, pour le bureau, traiteurs.
	 */
	private static function vue_recherche() {
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		self::entete( __( 'Rechercher', 'espace-traiteur' ), __( 'Une demande (n°, événement, traiteur, lieu, contact) ou un traiteur.', 'espace-traiteur' ) );

		echo '<form class="etr-recherche-globale" method="get" action="' . esc_url( self::url() ) . '" role="search">';
		if ( ! get_option( 'permalink_structure' ) ) {
			echo '<input type="hidden" name="etr_gestion" value="1">';
		}
		echo '<input type="hidden" name="vue" value="recherche">';
		echo '<span class="etr-recherche-gestion__champ">' . self::icone( 'recherche' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<label class="screen-reader-text" for="etr-recherche-q">' . esc_html__( 'Rechercher', 'espace-traiteur' ) . '</label>';
		echo '<input type="search" id="etr-recherche-q" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'N°, traiteur, événement, lieu…', 'espace-traiteur' ) . '" enterkeyhint="search" autocomplete="off"' . ( '' === $q ? ' autofocus' : '' ) . '></span>';
		echo '<button type="submit" class="etr-btn">' . esc_html__( 'Rechercher', 'espace-traiteur' ) . '</button>';
		echo '</form>';
		if ( ETR_Equipe::preparateur() || ETR_Equipe::livreur() ) {
			echo '<p class="etr-recherche-globale__raccourci"><a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'scanner' ) ) ) . '">' . self::icone( 'scan' ) . '<span>' . esc_html__( 'Scanner une étiquette', 'espace-traiteur' ) . '</span></a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( '' === $q ) {
			return;
		}

		list( , $demandes ) = self::filtrer_demandes( '', 0, $q );
		$traiteurs          = array();
		if ( ETR_Equipe::bureau() ) {
			$traiteurs = get_users(
				array(
					'role'           => 'traiteur',
					'search'         => '*' . $q . '*',
					'search_columns' => array( 'display_name', 'user_email', 'user_login', 'user_nicename' ),
					'number'         => 20,
				)
			);
		}
		if ( ! $demandes && ! $traiteurs ) {
			/* translators: %s : recherche. */
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html( sprintf( __( 'Rien ne correspond à « %s ».', 'espace-traiteur' ), $q ) ) . '</p></div>';
			return;
		}
		if ( $demandes ) {
			/* translators: %s : nombre. */
			echo '<h2 class="etr-section-titre">' . esc_html__( 'Demandes', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $demandes ) ) ) . '</span></h2>';
			self::table_demandes( array_slice( $demandes, 0, 30 ) );
		}
		if ( $traiteurs ) {
			echo '<h2 class="etr-section-titre">' . esc_html__( 'Traiteurs', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $traiteurs ) ) ) . '</span></h2><ul class="etr-feuille__liste etr-recherche-globale__traiteurs">';
			foreach ( $traiteurs as $user ) {
				$tel = (string) get_user_meta( $user->ID, 'etr_telephone', true );
				echo '<li><a href="' . esc_url( self::url( array( 'vue' => 'demandes', 'traiteur' => $user->ID ) ) ) . '">' . self::icone( 'invites' ) . '<span>' . esc_html( etr_nom( $user ) ) . '<small>' . esc_html( $user->user_email . ( $tel ? ' · ' . $tel : '' ) ) . '</small></span>' . self::icone( 'suite' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul>';
		}
	}

	/**
	 * Liste filtrable des demandes.
	 */
	private static function vue_demandes() {
		// phpcs:disable WordPress.Security.NonceVerification -- filtres de lecture.
		$statut   = isset( $_GET['statut'] ) ? sanitize_key( wp_unslash( $_GET['statut'] ) ) : '';
		$traiteur = isset( $_GET['traiteur'] ) ? absint( $_GET['traiteur'] ) : 0;
		$q        = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$page     = isset( $_GET['p'] ) ? max( 1, absint( $_GET['p'] ) ) : 1;
		// phpcs:enable
		$statuts = etr_statuts();
		if ( ! isset( $statuts[ $statut ] ) ) {
			$statut = '';
		}

		// Filtres traiteur + recherche, puis comptes par statut.
		list( $base, $liste ) = self::filtrer_demandes( $statut, $traiteur, $q );
		$comptes              = array_fill_keys( array_keys( $statuts ), 0 );
		foreach ( $base as $id ) {
			$s = etr_get_statut( $id );
			if ( isset( $comptes[ $s ] ) ) {
				++$comptes[ $s ];
			}
		}
		$total = count( $liste );
		$pages = max( 1, (int) ceil( $total / self::PAR_PAGE ) );
		$page  = min( $page, $pages );

		$export = array_filter(
			array(
				'statut'   => $statut,
				'traiteur' => $traiteur,
				'q'        => $q,
			)
		);
		// Exports regroupés dans un petit menu : la liste des demandes remonte sur téléphone.
		$action = ! ETR_Equipe::bureau() ? '' : '<details class="etr-menu-export"><summary class="etr-btn etr-btn--discret">' . self::icone( 'telecharger' ) . '<span>' . esc_html__( 'Exporter', 'espace-traiteur' ) . '</span></summary><ul>'
			. '<li><a href="' . esc_url( self::url_export( 'demandes', $export ) ) . '">' . esc_html__( 'Les demandes (CSV)', 'espace-traiteur' ) . '<small>' . esc_html__( 'une ligne par demande, filtres appliqués', 'espace-traiteur' ) . '</small></a></li>'
			. '<li><a href="' . esc_url( self::url_export( 'articles', $export ) ) . '">' . esc_html__( 'Le détail des articles (CSV)', 'espace-traiteur' ) . '<small>' . esc_html__( 'une ligne par article demandé', 'espace-traiteur' ) . '</small></a></li>'
			. '</ul></details>';
		/* translators: %s : nombre de demandes. */
		self::entete( __( 'Demandes', 'espace-traiteur' ), sprintf( _n( '%s demande', '%s demandes', count( self::demandes() ), 'espace-traiteur' ), number_format_i18n( count( self::demandes() ) ) ), $action );

		$filtres = array_filter(
			array(
				'vue'      => 'demandes',
				'traiteur' => $traiteur,
				'q'        => $q,
			)
		);

		echo '<div class="etr-barre-outils">';
		echo '<nav class="etr-filtres" aria-label="' . esc_attr__( 'Filtrer par statut', 'espace-traiteur' ) . '"><ul>';
		echo '<li><a href="' . esc_url( self::url( $filtres ) ) . '" aria-current="' . ( '' === $statut ? 'true' : 'false' ) . '">' . esc_html__( 'Toutes', 'espace-traiteur' ) . ' <span>' . esc_html( number_format_i18n( count( $base ) ) ) . '</span></a></li>';
		foreach ( $statuts as $cle => $libelle ) {
			if ( ! $comptes[ $cle ] && $cle !== $statut ) {
				continue;
			}
			echo '<li><a href="' . esc_url( self::url( array_merge( $filtres, array( 'statut' => $cle ) ) ) ) . '" aria-current="' . ( $cle === $statut ? 'true' : 'false' ) . '">' . esc_html( $libelle ) . ' <span>' . esc_html( number_format_i18n( $comptes[ $cle ] ) ) . '</span></a></li>';
		}
		echo '</ul></nav>';

		echo '<form class="etr-recherche-gestion" method="get" action="' . esc_url( self::url() ) . '" role="search">';
		if ( ! get_option( 'permalink_structure' ) ) {
			echo '<input type="hidden" name="etr_gestion" value="1">';
		}
		echo '<input type="hidden" name="vue" value="demandes">';
		if ( $statut ) {
			echo '<input type="hidden" name="statut" value="' . esc_attr( $statut ) . '">';
		}
		echo '<label class="screen-reader-text" for="etr-filtre-traiteur">' . esc_html__( 'Traiteur', 'espace-traiteur' ) . '</label>';
		echo '<select id="etr-filtre-traiteur" name="traiteur" onchange="this.form.submit()"><option value="">' . esc_html__( 'Tous les traiteurs', 'espace-traiteur' ) . '</option>';
		foreach ( get_users( array( 'role' => 'traiteur', 'orderby' => 'display_name' ) ) as $user ) {
			echo '<option value="' . (int) $user->ID . '"' . selected( $traiteur, $user->ID, false ) . '>' . esc_html( etr_nom( $user ) ) . '</option>';
		}
		echo '</select>';
		echo '<span class="etr-recherche-gestion__champ">' . self::icone( 'recherche' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<label class="screen-reader-text" for="etr-filtre-q">' . esc_html__( 'Rechercher', 'espace-traiteur' ) . '</label>';
		echo '<input type="search" id="etr-filtre-q" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Événement, lieu, n°…', 'espace-traiteur' ) . '"></span>';
		echo '<button type="submit" class="screen-reader-text">' . esc_html__( 'Rechercher', 'espace-traiteur' ) . '</button>';
		echo '</form>';
		echo '</div>';

		if ( ! $liste ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Aucune demande ne correspond à ces filtres.', 'espace-traiteur' ) . '</p>';
			if ( $statut || $traiteur || $q ) {
				echo '<a class="etr-lien" href="' . esc_url( self::url( array( 'vue' => 'demandes' ) ) ) . '">' . esc_html__( 'Effacer les filtres', 'espace-traiteur' ) . '</a>';
			}
			echo '</div>';
			return;
		}

		self::table_demandes( array_slice( $liste, ( $page - 1 ) * self::PAR_PAGE, self::PAR_PAGE ), 'en_attente' === $statut );

		if ( $pages > 1 ) {
			$args = array_merge( $filtres, $statut ? array( 'statut' => $statut ) : array() );
			echo '<nav class="etr-pagination" aria-label="' . esc_attr__( 'Pages', 'espace-traiteur' ) . '">';
			if ( $page > 1 ) {
				echo '<a href="' . esc_url( self::url( array_merge( $args, array( 'p' => $page - 1 ) ) ) ) . '">' . self::icone( 'retour' ) . '<span>' . esc_html__( 'Précédentes', 'espace-traiteur' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			/* translators: 1 : page courante, 2 : nombre de pages. */
			echo '<span>' . esc_html( sprintf( __( 'Page %1$s sur %2$s', 'espace-traiteur' ), number_format_i18n( $page ), number_format_i18n( $pages ) ) ) . '</span>';
			if ( $page < $pages ) {
				echo '<a href="' . esc_url( self::url( array_merge( $args, array( 'p' => $page + 1 ) ) ) ) . '"><span>' . esc_html__( 'Suivantes', 'espace-traiteur' ) . '</span>' . self::icone( 'suite' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</nav>';
		}
	}

	/**
	 * Détail d'une demande et décision.
	 *
	 * @param int $id Demande.
	 */
	private static function vue_demande( $id ) {
		$demande = get_post( $id );
		$retour  = '<a class="etr-retour" href="' . esc_url( self::url( array( 'vue' => 'demandes' ) ) ) . '">' . self::icone( 'retour' ) . '<span>' . esc_html__( 'Demandes', 'espace-traiteur' ) . '</span></a>';
		if ( ! $demande || ! self::demande_visible( $id ) ) {
			echo $retour; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Cette demande n’existe pas ou a été supprimée.', 'espace-traiteur' ) . '</p></div>';
			return;
		}

		$statut = etr_get_statut( $id );
		$auteur = get_userdata( (int) $demande->post_author );
		/* translators: 1 : numéro, 2 : traiteur, 3 : date d'envoi. */
		$sous = sprintf( __( 'Demande n° %1$d de %2$s, reçue le %3$s', 'espace-traiteur' ), $id, etr_nom( $auteur ), get_the_date( '', $demande ) );
		self::entete( get_the_title( $demande ), $sous, '<div class="etr-page-entete__statut">' . etr_statut_badge( $statut ) . '</div>', $retour );

		// Fiche en onglets : Résumé, Matériel, Échanges, Argent (bureau), Historique.
		$onglets = ETR_Fiche::onglets( $id );
		$actif   = ETR_Fiche::onglet_actif( $onglets );
		ETR_Fiche::barre( $id, $onglets, $actif );

		ETR_Fiche::ouvrir( 'resume', $actif, 'etr-dossier' );
		echo '<div class="etr-dossier__principal">';
		ETR_Fiche::prochaine_etape( $id );
		ETR_Terrain::panneau_suivi( $id ); // Les actions du terrain (préparer, livrer, reprendre).
		self::panneaux_infos( $id );
		do_action( 'etr_gestion_fiche', $id ); // Extensions (ancien emplacement).
		echo '</div><aside class="etr-dossier__cote">';
		if ( ETR_Equipe::bureau() ) {
			self::panneau_decision( $id, $statut );
		}
		/** Panneaux du résumé (dates de livraison et de reprise…). */
		do_action( 'etr_gestion_fiche_resume', $id );
		if ( ETR_Equipe::bureau() ) {
			do_action( 'etr_gestion_fiche_cote', $id ); // Extensions (ancien emplacement).
		}
		self::panneau_traiteur( $auteur );
		if ( current_user_can( 'edit_post', $id ) ) {
			echo '<p class="etr-dossier__wp"><a class="etr-lien" href="' . esc_url( get_edit_post_link( $id, 'url' ) ) . '">' . esc_html__( 'Ouvrir dans l’administration WordPress', 'espace-traiteur' ) . '</a></p>';
		}
		echo '</aside></div>';

		ETR_Fiche::ouvrir( 'materiel', $actif );
		self::panneau_materiel( $id );
		ETR_Terrain::panneaux_comptes_rendus( $id );
		/** Panneaux du matériel (volume…). */
		do_action( 'etr_gestion_fiche_materiel', $id );
		self::panneau_documents( $id );
		echo '</div>';

		ETR_Fiche::ouvrir( 'echanges', $actif );
		/** Échanges (messages avec le traiteur, avis…). */
		do_action( 'etr_gestion_fiche_echanges', $id );
		ETR_Terrain::panneau_incidents( $id );
		ETR_Terrain::panneau_notes( $id );
		echo '</div>';

		if ( isset( $onglets['argent'] ) ) {
			ETR_Fiche::ouvrir( 'argent', $actif );
			ETR_Fiche::resume_argent( $id );
			self::panneau_tarif( $id );
			/** Argent (devis, acompte, paiements en ligne, facture…). */
			do_action( 'etr_gestion_fiche_argent', $id );
			echo '</div>';
		}

		ETR_Fiche::ouvrir( 'historique', $actif );
		echo '<section class="etr-panneau"><h2 class="etr-panneau__titre">' . esc_html__( 'Historique', 'espace-traiteur' ) . '</h2>' . ETR_Historique::html( $id, 12 ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans ETR_Historique::html().
		echo '</div>';
	}

	/**
	 * Matériel demandé, avec disponibilité sur la période.
	 *
	 * @param int $id Demande.
	 */
	private static function panneau_materiel( $id ) {
		$lignes = etr_get_lignes( $id );
		echo '<section class="etr-panneau"><h2 class="etr-panneau__titre">' . esc_html__( 'Matériel demandé', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $lignes ) ) ) . '</span></h2>';
		if ( ! $lignes ) {
			echo '<p class="etr-muted">' . esc_html__( 'Aucun article du catalogue : voir les besoins hors catalogue ci-dessous.', 'espace-traiteur' ) . '</p></section>';
			return;
		}
		list( $debut, $fin ) = etr_periode_demande( $id );
		$reservees           = $debut ? etr_quantites_reservees( $debut, $fin, $id ) : array();
		if ( $debut ) {
			/* translators: 1 : début, 2 : fin. */
			echo '<p class="etr-muted etr-materiel__periode">' . esc_html( $debut === $fin ? sprintf( __( 'Disponibilités calculées pour le %s, hors cette demande.', 'espace-traiteur' ), etr_format_date( $debut ) ) : sprintf( __( 'Disponibilités calculées du %1$s au %2$s, hors cette demande.', 'espace-traiteur' ), etr_format_date( $debut ), etr_format_date( $fin ) ) ) . '</p>';
		}
		$manques   = 0;
		$avec_prix = ETR_Equipe::bureau(); // Prix réservés au bureau.
		$chiffrage = $avec_prix ? etr_chiffrage( $id ) : array();
		echo '<ul class="etr-lignes' . ( $avec_prix ? ' etr-lignes--prix' : '' ) . '">';
		foreach ( $lignes as $index => $ligne ) {
			$article = etr_get_article( $ligne['article_id'] );
			$dispo   = etr_disponibilite( (int) $ligne['article_id'], $reservees );
			$court   = null !== $dispo && (int) $ligne['quantite'] > $dispo;
			$manques += $court ? 1 : 0;
			echo '<li class="etr-ligne etr-ligne--gestion' . ( $court ? ' etr-ligne--manque' : '' ) . '">';
			echo ( $article && $article['image'] ) ? wp_kses_post( $article['image'] ) : '<span class="etr-article__img etr-article__img--vide">' . self::icone( 'image' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<span class="etr-ligne__nom">' . esc_html( $ligne['titre'] );
			if ( null === $dispo ) {
				echo '<span class="etr-muted">' . esc_html__( 'Stock non suivi', 'espace-traiteur' ) . '</span>';
			} elseif ( $court ) {
				/* translators: 1 : disponible, 2 : manquant. */
				echo '<span class="etr-manque">' . self::icone( 'alerte' ) . esc_html( sprintf( __( '%1$s disponibles, il en manque %2$s', 'espace-traiteur' ), number_format_i18n( $dispo ), number_format_i18n( (int) $ligne['quantite'] - $dispo ) ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				/* translators: %s : quantité disponible. */
				echo '<span class="etr-muted">' . esc_html( sprintf( __( '%s disponibles', 'espace-traiteur' ), number_format_i18n( $dispo ) ) ) . '</span>';
			}
			echo '</span>';
			echo '<span class="etr-ligne__qte">' . esc_html( number_format_i18n( $ligne['quantite'] ) ) . ( ! empty( $ligne['unite'] ) ? '<small>' . esc_html( $ligne['unite'] ) . '</small>' : '' ) . '</span>';
			if ( $avec_prix ) {
				echo etr_prix_ligne_html( $chiffrage['lignes'][ $index ], (int) $ligne['quantite'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans etr_prix_ligne_html().
			}
			echo '</li>';
		}
		echo '</ul>';
		if ( $avec_prix ) {
			echo etr_total_indicatif_html( $chiffrage ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans etr_total_indicatif_html().
		}
		if ( $manques ) {
			echo '<p class="etr-materiel__bilan etr-materiel__bilan--manque">' . self::icone( 'alerte' ) . esc_html(
				/* translators: %s : nombre d'articles. */
				sprintf( _n( '%s article n’est pas disponible en quantité suffisante sur ces dates.', '%s articles ne sont pas disponibles en quantité suffisante sur ces dates.', $manques, 'espace-traiteur' ), number_format_i18n( $manques ) )
			) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( $debut ) {
			echo '<p class="etr-materiel__bilan">' . self::icone( 'coche' ) . esc_html__( 'Tout le matériel est disponible sur ces dates.', 'espace-traiteur' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</section>';
	}

	/**
	 * Informations de l'événement.
	 *
	 * @param int $id Demande.
	 */
	private static function panneaux_infos( $id ) {
		$champs  = etr_champs_demande();
		$groupes = array(
			__( 'Événement', 'espace-traiteur' )            => array( 'date_evenement', 'nb_invites', 'lieu', 'contact' ),
			__( 'Livraison et reprise', 'espace-traiteur' ) => array( 'date_livraison', 'date_reprise' ),
		);
		$deja    = array_merge( array( 'evenement' ), ...array_values( $groupes ) );
		$autres  = array_filter(
			array_diff( array_keys( $champs ), $deja ),
			function ( $cle ) use ( $id ) {
				return '' !== (string) get_post_meta( $id, '_etr_' . $cle, true );
			}
		);
		if ( $autres ) {
			$groupes[ __( 'Compléments', 'espace-traiteur' ) ] = $autres;
		}
		echo '<div class="etr-dossier__infos">';
		foreach ( $groupes as $titre => $cles ) {
			echo '<section class="etr-panneau"><h2 class="etr-panneau__titre">' . esc_html( $titre ) . '</h2><dl class="etr-details' . ( count( $cles ) > 2 && __( 'Compléments', 'espace-traiteur' ) === $titre ? ' etr-details--empile' : '' ) . '">';
			foreach ( $cles as $cle ) {
				if ( isset( $champs[ $cle ] ) ) {
					echo '<div><dt>' . esc_html( $champs[ $cle ]['label'] ) . '</dt><dd>' . etr_valeur_affichee( $id, $cle, $champs[ $cle ]['type'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
			echo '</dl></section>';
		}
		echo '</div>';
	}

	/**
	 * Formulaire de décision : statut et message au traiteur.
	 *
	 * @param int    $id     Demande.
	 * @param string $statut Statut actuel.
	 */
	private static function panneau_decision( $id, $statut ) {
		$aides = array(
			'en_attente' => __( 'Reçue, pas encore étudiée.', 'espace-traiteur' ),
			'validee'    => __( 'Le matériel est réservé.', 'espace-traiteur' ),
			'refusee'    => __( 'Le matériel est libéré.', 'espace-traiteur' ),
			'livree'     => __( 'Le matériel est chez le traiteur.', 'espace-traiteur' ),
			'cloturee'   => __( 'Matériel repris, dossier terminé.', 'espace-traiteur' ),
			'annulee'    => __( 'Annulée, le matériel est libéré.', 'espace-traiteur' ),
		);
		// Sur téléphone, ce panneau s'ouvre en feuille depuis la barre d'action du bas (app.js).
		echo '<form class="etr-panneau etr-decision" method="post" action="' . esc_url( self::url_demande( $id ) ) . '" data-feuille="' . esc_attr__( 'Changer le statut', 'espace-traiteur' ) . '" data-feuille-principale>';
		wp_nonce_field( 'etr_gestion_decision_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="decision"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
		echo '<fieldset><legend class="etr-panneau__titre">' . esc_html__( 'Statut', 'espace-traiteur' ) . '</legend>';
		echo '<div class="etr-decision__choix">';
		foreach ( etr_statuts() as $cle => $libelle ) {
			echo '<label class="etr-decision__option etr-decision__option--' . esc_attr( $cle ) . '"><input type="radio" name="etr_statut" value="' . esc_attr( $cle ) . '"' . checked( $statut, $cle, false ) . '>';
			echo '<span class="etr-decision__puce" aria-hidden="true"></span><span><strong>' . esc_html( $libelle ) . '</strong><small>' . esc_html( isset( $aides[ $cle ] ) ? $aides[ $cle ] : '' ) . '</small></span></label>';
		}
		echo '</div></fieldset>';
		echo '<div class="etr-champ"><label for="etr-reponse">' . esc_html__( 'Message au traiteur', 'espace-traiteur' ) . ' <span class="etr-champ__option">' . esc_html__( '(facultatif)', 'espace-traiteur' ) . '</span></label>';
		echo '<textarea id="etr-reponse" name="etr_reponse" rows="4" placeholder="' . esc_attr__( 'Précisions sur la livraison, article remplacé…', 'espace-traiteur' ) . '">' . esc_textarea( (string) get_post_meta( $id, '_etr_reponse', true ) ) . '</textarea></div>';
		echo '<p class="etr-muted">' . esc_html__( 'Le traiteur voit ce message dans son espace. S’il change de statut, il reçoit aussi un e-mail.', 'espace-traiteur' ) . '</p>';
		echo '<button type="submit" class="etr-btn etr-btn--plein">' . esc_html__( 'Enregistrer', 'espace-traiteur' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Remise et frais de livraison.
	 *
	 * @param int $id Demande.
	 */
	private static function panneau_tarif( $id ) {
		$tarif = etr_tarif( $id );
		echo '<form class="etr-panneau etr-tarif" method="post" action="' . esc_url( self::url_demande( $id ) ) . '" data-feuille="' . esc_attr__( 'Remise et livraison', 'espace-traiteur' ) . '">';
		wp_nonce_field( 'etr_gestion_tarif_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="tarif"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
		echo '<h2 class="etr-panneau__titre">' . esc_html__( 'Tarification', 'espace-traiteur' ) . '</h2>';
		echo '<div class="etr-tarif__champs">';
		echo '<p class="etr-champ"><label for="etr-remise">' . esc_html__( 'Remise', 'espace-traiteur' ) . '</label><span class="etr-saisie-unite"><input type="text" inputmode="decimal" id="etr-remise" name="etr_remise_pct" value="' . esc_attr( $tarif['remise_pct'] ? str_replace( '.', ',', (string) $tarif['remise_pct'] ) : '' ) . '" placeholder="0"><span aria-hidden="true">%</span></span></p>';
		echo '<p class="etr-champ"><label for="etr-livraison">' . esc_html__( 'Livraison et reprise', 'espace-traiteur' ) . '</label><span class="etr-saisie-unite"><input type="text" inputmode="decimal" id="etr-livraison" name="etr_frais_livraison" value="' . esc_attr( null === $tarif['livraison'] ? '' : str_replace( '.', ',', (string) $tarif['livraison'] ) ) . '" placeholder="—"><span aria-hidden="true">€</span></span></p>';
		echo '</div>';
		do_action( 'etr_gestion_tarif_estimation', $id ); // Estimation du prix de la livraison (bureau seulement).
		echo '<p class="etr-muted">' . esc_html__( 'Pris en compte dans le prix final indicatif, visible aussi par le traiteur si les prix sont affichés. Livraison vide = non chiffrée, 0 = offerte.', 'espace-traiteur' ) . '</p>';
		echo '<button type="submit" class="etr-btn">' . esc_html__( 'Mettre à jour le prix', 'espace-traiteur' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Bons imprimables.
	 *
	 * @param int $id Demande.
	 */
	private static function panneau_documents( $id ) {
		echo '<section class="etr-panneau etr-documents"><h2 class="etr-panneau__titre">' . esc_html__( 'Documents à imprimer', 'espace-traiteur' ) . '</h2><ul>';
		$aides = array(
			'preparation' => __( 'Liste à cocher pour l’entrepôt', 'espace-traiteur' ),
			'retour'      => __( 'Casse et manquants à la reprise', 'espace-traiteur' ),
		);
		foreach ( ETR_Bons::types() as $type => $libelle ) {
			$url = self::url(
				array(
					'vue'  => 'bon',
					'id'   => $id,
					'type' => $type,
				)
			);
			echo '<li><a class="etr-documents__lien" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $libelle ) . '</strong><small>' . esc_html( $aides[ $type ] ) . '</small><span class="screen-reader-text"> ' . esc_html__( '(nouvel onglet)', 'espace-traiteur' ) . '</span></a></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Coordonnées du traiteur.
	 *
	 * @param WP_User|false $user Traiteur.
	 */
	private static function panneau_traiteur( $user ) {
		if ( ! $user ) {
			return;
		}
		$tel     = (string) get_user_meta( $user->ID, 'etr_telephone', true );
		$contact = (string) get_user_meta( $user->ID, 'etr_contact', true );
		echo '<section class="etr-panneau etr-fiche"><h2 class="etr-panneau__titre">' . esc_html( etr_nom( $user ) ) . '</h2>';
		if ( $contact ) {
			echo '<p class="etr-muted">' . esc_html( $contact ) . '</p>';
		}
		echo '<ul class="etr-fiche__coord">';
		echo '<li>' . self::icone( 'courriel' ) . '<a class="etr-lien" href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $tel ) {
			echo '<li>' . self::icone( 'telephone' ) . '<a class="etr-lien" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $tel ) ) . '">' . esc_html( $tel ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
		$nb = count_user_posts( $user->ID, 'etr_demande' );
		/* translators: %s : nombre de demandes. */
		echo '<a class="etr-lien" href="' . esc_url( self::url( array( 'vue' => 'demandes', 'traiteur' => $user->ID ) ) ) . '">' . esc_html( sprintf( _n( 'Voir sa demande', 'Voir ses %s demandes', $nb, 'espace-traiteur' ), number_format_i18n( $nb ) ) ) . '</a>';
		echo '</section>';
	}

	/* ------------------------------------------------------------------ */
	/* Planning                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Calendrier mensuel des livraisons, événements et reprises.
	 */
	private static function vue_planning() {
		$mois = isset( $_GET['mois'] ) ? sanitize_text_field( wp_unslash( $_GET['mois'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $mois ) ) {
			$mois = current_time( 'Y-m' );
		}
		$premier   = $mois . '-01';
		$ts        = strtotime( $premier );
		$nb_jours  = (int) gmdate( 't', $ts );
		$decalage  = (int) gmdate( 'N', $ts ) - 1;
		$debut     = gmdate( 'Y-m-d', strtotime( $premier . ' -' . $decalage . ' days' ) );
		$cases     = (int) ( ceil( ( $decalage + $nb_jours ) / 7 ) * 7 );
		$fin       = gmdate( 'Y-m-d', strtotime( $debut . ' +' . ( $cases - 1 ) . ' days' ) );
		$jours     = self::mouvements( $debut, $fin );
		$jour      = self::aujourdhui();
		$precedent = gmdate( 'Y-m', strtotime( $premier . ' -1 month' ) );
		$suivant   = gmdate( 'Y-m', strtotime( $premier . ' +1 month' ) );

		$nav  = '<div class="etr-mois-nav">';
		$nav .= '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'planning' ) ) ) . '">' . esc_html__( 'Ce mois-ci', 'espace-traiteur' ) . '</a>';
		$nav .= '<a class="etr-btn etr-btn--icone" href="' . esc_url( self::url( array( 'vue' => 'planning', 'mois' => $precedent ) ) ) . '" aria-label="' . esc_attr__( 'Mois précédent', 'espace-traiteur' ) . '">' . self::icone( 'retour' ) . '</a>';
		$nav .= '<a class="etr-btn etr-btn--icone" href="' . esc_url( self::url( array( 'vue' => 'planning', 'mois' => $suivant ) ) ) . '" aria-label="' . esc_attr__( 'Mois suivant', 'espace-traiteur' ) . '">' . self::icone( 'suite' ) . '</a>';
		if ( ETR_Equipe::bureau() ) {
			$nav .= '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url_export( 'planning', array( 'mois' => $mois ) ) ) . '">' . self::icone( 'telecharger' ) . '<span>' . esc_html__( 'Exporter', 'espace-traiteur' ) . '</span></a>';
		}
		$nav .= '</div>';
		self::entete( ucfirst( date_i18n( 'F Y', $ts ) ), __( 'Livraisons, événements et reprises des demandes en cours.', 'espace-traiteur' ), $nav );

		echo '<ul class="etr-legende">';
		foreach ( self::types_mouvement() as $type => $libelle ) {
			echo '<li class="etr-legende--' . esc_attr( $type ) . '">' . esc_html( $libelle ) . '</li>';
		}
		echo '<li class="etr-legende--attente">' . esc_html__( 'En attente de validation', 'espace-traiteur' ) . '</li>';
		echo '</ul>';

		echo '<div class="etr-calendrier" role="table" aria-label="' . esc_attr( date_i18n( 'F Y', $ts ) ) . '">';
		echo '<div class="etr-calendrier__semaine etr-calendrier__entete" role="row">';
		for ( $i = 0; $i < 7; $i++ ) {
			$nom = date_i18n( 'D', strtotime( '2024-01-01 +' . $i . ' days' ) );
			echo '<span role="columnheader">' . esc_html( $nom ) . '</span>';
		}
		echo '</div>';
		for ( $semaine = 0; $semaine < $cases / 7; $semaine++ ) {
			echo '<div class="etr-calendrier__semaine" role="row">';
			for ( $i = 0; $i < 7; $i++ ) {
				$date    = gmdate( 'Y-m-d', strtotime( $debut . ' +' . ( $semaine * 7 + $i ) . ' days' ) );
				$classes = array( 'etr-calendrier__jour' );
				if ( substr( $date, 0, 7 ) !== $mois ) {
					$classes[] = 'etr-calendrier__jour--hors';
				}
				if ( $date === $jour ) {
					$classes[] = 'etr-calendrier__jour--aujourdhui';
				}
				if ( empty( $jours[ $date ] ) ) {
					$classes[] = 'etr-calendrier__jour--vide'; // Masqué dans la liste du téléphone.
				}
				echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" role="cell" data-jour="' . esc_attr( $date ) . '">';
				// Jour de la semaine : affiché seulement dans la liste du téléphone.
				echo '<time datetime="' . esc_attr( $date ) . '"><span class="etr-calendrier__jsem">' . esc_html( date_i18n( 'D', strtotime( $date ) ) ) . '</span>' . esc_html( date_i18n( 'j', strtotime( $date ) ) ) . '</time>';
				if ( ! empty( $jours[ $date ] ) ) {
					echo '<ul>';
					foreach ( $jours[ $date ] as $mvt ) {
						echo '<li>' . self::mouvement_html( $mvt ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
					}
					echo '</ul>';
				} elseif ( $date === $jour ) {
					echo '<p class="etr-calendrier__rien-jour">' . esc_html__( 'Rien de prévu aujourd’hui', 'espace-traiteur' ) . '</p>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
		$dans_le_mois = array_filter(
			$jours,
			function ( $liste, $d ) use ( $mois ) {
				return $liste && substr( (string) $d, 0, 7 ) === $mois;
			},
			ARRAY_FILTER_USE_BOTH
		);
		if ( ! $dans_le_mois ) {
			echo '<p class="etr-calendrier__rien">' . esc_html__( 'Rien de prévu ce mois-ci.', 'espace-traiteur' ) . '</p>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Traiteurs                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Comptes traiteurs et ajout.
	 */
	private static function vue_traiteurs() {
		$traiteurs = get_users(
			array(
				'role'    => 'traiteur',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		$derniere  = array();
		$nombre    = array();
		foreach ( self::demandes() as $id ) {
			$auteur            = (int) get_post_field( 'post_author', $id );
			$nombre[ $auteur ] = ( isset( $nombre[ $auteur ] ) ? $nombre[ $auteur ] : 0 ) + 1;
			if ( ! isset( $derniere[ $auteur ] ) ) {
				$derniere[ $auteur ] = $id; // Demandes triées de la plus récente à la plus ancienne.
			}
		}

		/* translators: %s : nombre de traiteurs. */
		self::entete( __( 'Traiteurs', 'espace-traiteur' ), sprintf( _n( '%s traiteur partenaire', '%s traiteurs partenaires', count( $traiteurs ), 'espace-traiteur' ), number_format_i18n( count( $traiteurs ) ) ) );

		echo '<div class="etr-traiteurs-gestion">';
		echo '<div>';
		if ( ! $traiteurs ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Aucun traiteur pour le moment. Ajoutez le premier avec le formulaire.', 'espace-traiteur' ) . '</p></div>';
		} else {
			echo '<div class="etr-table-cadre" tabindex="0" role="region" aria-label="' . esc_attr__( 'Liste des traiteurs', 'espace-traiteur' ) . '"><table class="etr-table etr-table--traiteurs">';
			echo '<thead><tr><th scope="col">' . esc_html__( 'Traiteur', 'espace-traiteur' ) . '</th><th scope="col">' . esc_html__( 'Coordonnées', 'espace-traiteur' ) . '</th><th scope="col">' . esc_html__( 'Demandes', 'espace-traiteur' ) . '</th><th scope="col">' . esc_html__( 'Accès', 'espace-traiteur' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'espace-traiteur' ) . '</span></th></tr></thead><tbody>';
			foreach ( $traiteurs as $user ) {
				$desactive = ETR_Comptes::est_desactive( $user->ID );
				$nb        = isset( $nombre[ $user->ID ] ) ? $nombre[ $user->ID ] : 0;
				$contact   = (string) get_user_meta( $user->ID, 'etr_contact', true );
				$tel       = (string) get_user_meta( $user->ID, 'etr_telephone', true );
				echo '<tr' . ( $desactive ? ' class="etr-table__passee"' : '' ) . '>';
				echo '<td><strong>' . esc_html( etr_nom( $user ) ) . '</strong><small>' . esc_html( $contact ? $contact : $user->user_login ) . '</small></td>';
				echo '<td><a class="etr-lien" href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a>' . ( $tel ? '<small>' . esc_html( $tel ) . '</small>' : '' ) . '</td>';
				echo '<td data-label="' . esc_attr__( 'Demandes', 'espace-traiteur' ) . '">';
				if ( $nb ) {
					echo '<a class="etr-lien" href="' . esc_url( self::url( array( 'vue' => 'demandes', 'traiteur' => $user->ID ) ) ) . '">' . esc_html( number_format_i18n( $nb ) ) . '</a>';
					/* translators: %s : date. */
					echo '<small>' . esc_html( sprintf( __( 'Dernière le %s', 'espace-traiteur' ), get_the_date( 'j M Y', $derniere[ $user->ID ] ) ) ) . '</small>';
				} else {
					echo '<span class="etr-muted">' . esc_html__( 'Aucune', 'espace-traiteur' ) . '</span>';
				}
				echo '</td>';
				$invite_le = ETR_Traiteurs::date_invitation( $user->ID );
				echo '<td data-label="' . esc_attr__( 'Accès', 'espace-traiteur' ) . '">' . ETR_Traiteurs::badge_acces( $desactive, $invite_le ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans badge_acces().
				echo '<td class="etr-table__actions">';
				if ( ! $desactive ) {
					self::bouton_compte( 'inviter', $user->ID, $invite_le ? __( 'Renvoyer l’invitation', 'espace-traiteur' ) : __( 'Envoyer l’invitation', 'espace-traiteur' ), '', ! $invite_le );
				}
				self::bouton_compte( 'basculer', $user->ID, $desactive ? __( 'Réactiver', 'espace-traiteur' ) : __( 'Désactiver', 'espace-traiteur' ), $desactive ? '' : __( 'Désactiver l’accès de ce traiteur ? Il sera déconnecté immédiatement.', 'espace-traiteur' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';

		if ( ETR_Equipe::bureau() ) {
			self::formulaire_traiteur();
		}
		echo '</div>';
	}

	/**
	 * Petit formulaire d'action sur un compte.
	 *
	 * @param string $action   inviter|basculer.
	 * @param int    $user_id  Traiteur.
	 * @param string $libelle  Libellé.
	 * @param string $confirm  Confirmation (facultative).
	 * @param bool   $avant    Mettre l'action en avant (bouton plein).
	 */
	private static function bouton_compte( $action, $user_id, $libelle, $confirm = '', $avant = false ) {
		echo '<form method="post" action="' . esc_url( self::url( array( 'vue' => 'traiteurs' ) ) ) . '"' . ( $confirm ? ' data-confirmer="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		wp_nonce_field( 'etr_gestion_' . $action . '_' . $user_id, 'etr_nonce', false );
		echo '<input type="hidden" name="etr_gestion_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="user" value="' . (int) $user_id . '">';
		echo '<button type="submit" class="' . ( $avant ? 'etr-btn etr-btn--plein etr-btn--petit' : 'etr-btn-texte' ) . '">' . esc_html( $libelle ) . '</button>';
		echo '</form>';
	}

	/**
	 * Formulaire d'ajout d'un traiteur.
	 */
	private static function formulaire_traiteur() {
		$erreurs = array(
			'societe'            => array( 'societe', __( 'Indiquez le nom de la société.', 'espace-traiteur' ) ),
			'email'              => array( 'email', __( 'Cette adresse e-mail n’est pas valide.', 'espace-traiteur' ) ),
			'email_existe'       => array( 'email', __( 'Un compte existe déjà avec cette adresse e-mail.', 'espace-traiteur' ) ),
			'identifiant_existe' => array( 'identifiant', __( 'Cet identifiant est déjà pris. Choisissez-en un autre ou laissez vide.', 'espace-traiteur' ) ),
			'creation'           => array( '', __( 'Le compte n’a pas pu être créé. Réessayez.', 'espace-traiteur' ) ),
		);
		$code    = self::$erreurs_traiteur ? self::$erreurs_traiteur[0] : '';
		$erreur  = isset( $erreurs[ $code ] ) ? $erreurs[ $code ] : array( '', '' );
		$champs  = array(
			'societe'     => array( __( 'Société', 'espace-traiteur' ), 'text', true, 'organization' ),
			'contact'     => array( __( 'Personne à contacter', 'espace-traiteur' ), 'text', false, 'name' ),
			'email'       => array( __( 'E-mail', 'espace-traiteur' ), 'email', true, 'email' ),
			'telephone'   => array( __( 'Téléphone', 'espace-traiteur' ), 'tel', false, 'tel' ),
			'identifiant' => array( __( 'Identifiant', 'espace-traiteur' ), 'text', false, 'off' ),
		);

		echo '<form class="etr-panneau etr-ajout" method="post" action="' . esc_url( self::url( array( 'vue' => 'traiteurs' ) ) ) . '" id="ajouter">';
		echo '<h2 class="etr-panneau__titre">' . esc_html__( 'Ajouter un traiteur', 'espace-traiteur' ) . '</h2>';
		echo '<p class="etr-muted">' . esc_html__( 'Le compte est créé tout de suite. L’invitation (e-mail pour choisir son mot de passe) part maintenant ou plus tard, depuis la liste.', 'espace-traiteur' ) . '</p>';
		if ( $erreur[1] && '' === $erreur[0] ) {
			echo '<div class="etr-notice etr-notice--erreur" role="alert"><p>' . esc_html( $erreur[1] ) . '</p></div>';
		}
		wp_nonce_field( 'etr_gestion_creer_traiteur', 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="creer_traiteur">';
		foreach ( $champs as $cle => $champ ) {
			$valeur = ( $code && isset( $_POST[ $cle ] ) ) ? sanitize_text_field( wp_unslash( $_POST[ $cle ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- formulaire réaffiché après vérification.
			$fautif = $erreur[0] === $cle;
			echo '<div class="etr-champ' . ( $fautif ? ' etr-champ--erreur' : '' ) . '"><label for="etr-t-' . esc_attr( $cle ) . '">' . esc_html( $champ[0] ) . ( $champ[2] ? '' : ' <span class="etr-champ__option">' . esc_html__( '(facultatif)', 'espace-traiteur' ) . '</span>' ) . '</label>';
			echo '<input type="' . esc_attr( $champ[1] ) . '" id="etr-t-' . esc_attr( $cle ) . '" name="' . esc_attr( $cle ) . '" value="' . esc_attr( $valeur ) . '" autocomplete="' . esc_attr( $champ[3] ) . '"' . ( $champ[2] ? ' required' : '' ) . ( $fautif ? ' aria-invalid="true" aria-describedby="etr-t-' . esc_attr( $cle ) . '-erreur"' : '' ) . '>';
			if ( $fautif ) {
				echo '<p class="etr-champ__erreur" id="etr-t-' . esc_attr( $cle ) . '-erreur">' . esc_html( $erreur[1] ) . '</p>';
			} elseif ( 'identifiant' === $cle ) {
				echo '<p class="etr-champ__aide">' . esc_html__( 'Laissez vide pour le déduire de l’e-mail.', 'espace-traiteur' ) . '</p>';
			}
			echo '</div>';
		}
		$cocher = $code && ! empty( $_POST['envoyer_invitation'] ); // phpcs:ignore WordPress.Security.NonceVerification -- formulaire réaffiché après vérification.
		echo '<label class="etr-case"><input type="checkbox" name="envoyer_invitation" value="1"' . checked( $cocher, true, false ) . '><span>' . esc_html__( 'Envoyer l’invitation maintenant', 'espace-traiteur' ) . '<small>' . esc_html__( 'Sinon, vous l’enverrez plus tard depuis la liste.', 'espace-traiteur' ) . '</small></span></label>';
		echo '<button type="submit" class="etr-btn etr-btn--plein">' . esc_html__( 'Ajouter le traiteur', 'espace-traiteur' ) . '</button>';
		echo '</form>';
	}

	/* ------------------------------------------------------------------ */
	/* Équipe                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Membres de l'équipe : bureau, préparateurs, livreurs.
	 */
	private static function vue_equipe() {
		$membres = ETR_Equipe::membres();
		/* translators: %s : nombre de personnes. */
		self::entete( __( 'Équipe', 'espace-traiteur' ), sprintf( _n( '%s personne', '%s personnes', count( $membres ), 'espace-traiteur' ), number_format_i18n( count( $membres ) ) ) );

		echo '<div class="etr-traiteurs-gestion"><div>';
		echo '<p class="etr-muted etr-equipe__intro">' . esc_html__( 'Le bureau gère tout. Les préparateurs et les livreurs voient le planning, les demandes validées (sans prix) et les bons, sans accès à l’administration WordPress.', 'espace-traiteur' ) . '</p>';
		echo '<div class="etr-table-cadre" tabindex="0" role="region" aria-label="' . esc_attr__( 'Membres de l’équipe', 'espace-traiteur' ) . '"><table class="etr-table etr-table--traiteurs">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Personne', 'espace-traiteur' ) . '</th><th scope="col">' . esc_html__( 'Rôles', 'espace-traiteur' ) . '</th><th scope="col">' . esc_html__( 'Accès', 'espace-traiteur' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'espace-traiteur' ) . '</span></th></tr></thead><tbody>';
		$moi = get_current_user_id();
		foreach ( $membres as $user ) {
			$modifiable = ETR_Equipe::modifiable( $user->ID );
			$retire     = ETR_Equipe::acces_retire( $user->ID );
			$tel        = (string) get_user_meta( $user->ID, 'etr_telephone', true );
			$invite_le  = ETR_Traiteurs::date_invitation( $user->ID );
			echo '<tr' . ( $retire ? ' class="etr-table__passee"' : '' ) . '>';
			echo '<td><strong>' . esc_html( etr_nom( $user ) ) . ( $user->ID === $moi ? ' <small class="etr-muted">(' . esc_html__( 'vous', 'espace-traiteur' ) . ')</small>' : '' ) . '</strong>';
			echo '<small><a class="etr-lien" href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a>' . ( $tel ? ' · ' . esc_html( $tel ) : '' ) . '</small></td>';
			echo '<td data-label="' . esc_attr__( 'Rôles', 'espace-traiteur' ) . '">';
			if ( $modifiable && ! $retire ) {
				self::formulaire_roles( $user->ID, ETR_Equipe::roles_equipe( $user ) );
			} else {
				$libelle = $retire ? ETR_Equipe::libelle_roles( ETR_Equipe::roles_retires( $user->ID ) ) : ETR_Equipe::libelle_role( $user );
				echo esc_html( $libelle ? $libelle : '—' );
			}
			echo '</td>';
			if ( $retire ) {
				$acces = '<span class="etr-statut etr-statut--refusee">' . esc_html__( 'Retiré', 'espace-traiteur' ) . '</span>';
			} elseif ( ! $modifiable && ! $invite_le ) {
				$acces = '<span class="etr-statut etr-statut--validee">' . esc_html__( 'Actif', 'espace-traiteur' ) . '</span>'; // Administrateur, ou soi-même.
			} else {
				$acces = ETR_Traiteurs::badge_acces( false, $invite_le );
			}
			echo '<td data-label="' . esc_attr__( 'Accès', 'espace-traiteur' ) . '">' . $acces . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé ci-dessus et dans badge_acces().
			echo '<td class="etr-table__actions">';
			if ( $modifiable ) {
				if ( $retire ) {
					self::formulaire_membre( $user->ID, 'rendre', __( 'Rendre l’accès', 'espace-traiteur' ) );
				} else {
					self::formulaire_membre( $user->ID, 'inviter', $invite_le ? __( 'Renvoyer l’invitation', 'espace-traiteur' ) : __( 'Envoyer l’invitation', 'espace-traiteur' ), '', ! $invite_le );
					self::formulaire_membre( $user->ID, 'retirer', __( 'Retirer l’accès', 'espace-traiteur' ), __( 'Retirer l’accès de cette personne ? Elle sera déconnectée immédiatement.', 'espace-traiteur' ) );
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></div>';
		self::formulaire_ajout_membre();
		echo '</div>';
	}

	/**
	 * Petit formulaire d'action sur un membre.
	 *
	 * @param int    $user_id Membre.
	 * @param string $op      inviter|retirer|rendre.
	 * @param string $libelle Libellé du bouton.
	 * @param string $confirm Confirmation (facultative).
	 * @param bool   $avant   Bouton mis en avant.
	 */
	private static function formulaire_membre( $user_id, $op, $libelle, $confirm = '', $avant = false ) {
		echo '<form method="post" action="' . esc_url( self::url( array( 'vue' => 'equipe' ) ) ) . '"' . ( $confirm ? ' data-confirmer="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		wp_nonce_field( 'etr_gestion_membre_' . $user_id, 'etr_nonce', false );
		echo '<input type="hidden" name="etr_gestion_action" value="membre"><input type="hidden" name="user" value="' . (int) $user_id . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<button type="submit" class="' . ( $avant ? 'etr-btn etr-btn--plein etr-btn--petit' : 'etr-btn-texte' ) . '">' . esc_html( $libelle ) . '</button>';
		echo '</form>';
	}

	/**
	 * Rôles d'un membre : une case par rôle, enregistrée dès qu'on la coche ou la décoche.
	 *
	 * @param int      $user_id Membre.
	 * @param string[] $roles   Rôles actuels.
	 */
	private static function formulaire_roles( $user_id, $roles ) {
		echo '<form method="post" action="' . esc_url( self::url( array( 'vue' => 'equipe' ) ) ) . '" class="etr-equipe__roles-liste">';
		wp_nonce_field( 'etr_gestion_membre_' . $user_id, 'etr_nonce', false );
		echo '<input type="hidden" name="etr_gestion_action" value="membre"><input type="hidden" name="user" value="' . (int) $user_id . '"><input type="hidden" name="op" value="roles">';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Rôles', 'espace-traiteur' ) . '</legend>';
		foreach ( array_keys( ETR_Equipe::roles() ) as $slug ) {
			echo '<label class="etr-case etr-case--compacte"><input type="checkbox" name="roles[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $roles, true ), true, false ) . ' onchange="this.form.submit()"><span>' . esc_html( ETR_Equipe::libelle_role_slug( $slug ) ) . '</span></label>';
		}
		echo '</fieldset><noscript><button type="submit" class="etr-btn-texte">' . esc_html__( 'Enregistrer', 'espace-traiteur' ) . '</button></noscript>';
		echo '</form>';
	}

	/**
	 * Formulaire d'ajout d'un membre.
	 */
	private static function formulaire_ajout_membre() {
		$erreurs = array(
			'nom'          => array( 'nom', __( 'Indiquez le nom.', 'espace-traiteur' ) ),
			'email'        => array( 'email', __( 'Cette adresse e-mail n’est pas valide.', 'espace-traiteur' ) ),
			'email_existe' => array( 'email', __( 'Cette adresse est déjà celle d’un membre de l’équipe ou d’un administrateur.', 'espace-traiteur' ) ),
			'role'         => array( 'roles', __( 'Choisissez au moins un rôle.', 'espace-traiteur' ) ),
			'creation'     => array( '', __( 'Le compte n’a pas pu être créé. Réessayez.', 'espace-traiteur' ) ),
		);
		$code   = self::$erreurs_membre ? self::$erreurs_membre[0] : '';
		$erreur = isset( $erreurs[ $code ] ) ? $erreurs[ $code ] : array( '', '' );
		// phpcs:disable WordPress.Security.NonceVerification -- formulaire réaffiché après vérification.
		$valeur = function ( $cle ) use ( $code ) {
			return ( $code && isset( $_POST[ $cle ] ) ) ? sanitize_text_field( wp_unslash( $_POST[ $cle ] ) ) : '';
		};
		// phpcs:enable

		echo '<form class="etr-panneau etr-ajout" method="post" action="' . esc_url( self::url( array( 'vue' => 'equipe' ) ) ) . '" id="ajouter">';
		echo '<h2 class="etr-panneau__titre">' . esc_html__( 'Ajouter une personne', 'espace-traiteur' ) . '</h2>';
		echo '<p class="etr-muted">' . esc_html__( 'Elle reçoit un e-mail pour choisir son mot de passe, puis se connecte à l’application.', 'espace-traiteur' ) . '</p>';
		if ( $erreur[1] && '' === $erreur[0] ) {
			echo '<div class="etr-notice etr-notice--erreur" role="alert"><p>' . esc_html( $erreur[1] ) . '</p></div>';
		}
		wp_nonce_field( 'etr_gestion_creer_membre', 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="creer_membre">';
		$champs = array(
			'nom'       => array( __( 'Prénom et nom', 'espace-traiteur' ), 'text', true, 'name' ),
			'email'     => array( __( 'E-mail', 'espace-traiteur' ), 'email', true, 'email' ),
			'telephone' => array( __( 'Téléphone', 'espace-traiteur' ), 'tel', false, 'tel' ),
		);
		foreach ( $champs as $cle => $champ ) {
			$fautif = $erreur[0] === $cle;
			echo '<div class="etr-champ' . ( $fautif ? ' etr-champ--erreur' : '' ) . '"><label for="etr-m-' . esc_attr( $cle ) . '">' . esc_html( $champ[0] ) . ( $champ[2] ? '' : ' <span class="etr-champ__option">' . esc_html__( '(facultatif)', 'espace-traiteur' ) . '</span>' ) . '</label>';
			echo '<input type="' . esc_attr( $champ[1] ) . '" id="etr-m-' . esc_attr( $cle ) . '" name="' . esc_attr( $cle ) . '" value="' . esc_attr( $valeur( $cle ) ) . '" autocomplete="' . esc_attr( $champ[3] ) . '"' . ( $champ[2] ? ' required' : '' ) . ( $fautif ? ' aria-invalid="true" aria-describedby="etr-m-' . esc_attr( $cle ) . '-erreur"' : '' ) . '>';
			if ( $fautif ) {
				echo '<p class="etr-champ__erreur" id="etr-m-' . esc_attr( $cle ) . '-erreur">' . esc_html( $erreur[1] ) . '</p>';
			}
			echo '</div>';
		}
		// phpcs:disable WordPress.Security.NonceVerification -- formulaire réaffiché après vérification.
		$coches = $code ? ETR_Equipe::normaliser_roles( isset( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array() ) : array( 'etr_preparateur' );
		$inviter = $code ? ! empty( $_POST['envoyer_invitation'] ) : true; // Invitation envoyée à la création, sauf si on décoche.
		// phpcs:enable
		$fautif = 'roles' === $erreur[0];
		echo '<fieldset class="etr-champ etr-equipe__roles' . ( $fautif ? ' etr-champ--erreur' : '' ) . '"><legend>' . esc_html__( 'Rôles', 'espace-traiteur' ) . ' <span class="etr-champ__option">' . esc_html__( '(un ou plusieurs)', 'espace-traiteur' ) . '</span></legend>';
		if ( $fautif ) {
			echo '<p class="etr-champ__erreur">' . esc_html( $erreur[1] ) . '</p>';
		}
		$aides = ETR_Equipe::descriptions();
		foreach ( array_keys( ETR_Equipe::roles() ) as $slug ) {
			echo '<label class="etr-case"><input type="checkbox" name="roles[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $coches, true ), true, false ) . '><span>' . esc_html( ETR_Equipe::libelle_role_slug( $slug ) ) . '<small>' . esc_html( $aides[ $slug ] ) . '</small></span></label>';
		}
		echo '</fieldset>';
		echo '<label class="etr-case"><input type="checkbox" name="envoyer_invitation" value="1"' . checked( $inviter, true, false ) . '><span>' . esc_html__( 'Envoyer l’invitation maintenant', 'espace-traiteur' ) . '<small>' . esc_html__( 'Décochez pour l’envoyer plus tard depuis la liste.', 'espace-traiteur' ) . '</small></span></label>';
		echo '<button type="submit" class="etr-btn etr-btn--plein">' . esc_html__( 'Ajouter', 'espace-traiteur' ) . '</button>';
		echo '</form>';
	}

	/* ------------------------------------------------------------------ */
	/* Catalogue                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Catalogue : stock et sorties du jour.
	 */
	private static function vue_catalogue() {
		$q        = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$articles = etr_catalogue();
		$total    = count( $articles );
		$cle      = self::cle_recherche( $q );
		$jour      = self::aujourdhui();
		$dehors    = etr_quantites_reservees( $jour, $jour );
		$woo       = etr_source_woocommerce();
		$gerer_url = $woo ? admin_url( 'edit.php?post_type=product' ) : admin_url( 'edit.php?post_type=etr_article' );
		$action    = ! ETR_Equipe::bureau() ? '' : '<a class="etr-btn etr-btn--discret" href="' . esc_url( $gerer_url ) . '">' . self::icone( 'externe' ) . '<span>' . esc_html( $woo ? __( 'Gérer les produits', 'espace-traiteur' ) : __( 'Gérer les articles', 'espace-traiteur' ) ) . '</span></a>';

		/* translators: %s : nombre d'articles. */
		self::entete( __( 'Catalogue', 'espace-traiteur' ), sprintf( _n( '%s article proposé aux traiteurs', '%s articles proposés aux traiteurs', $total, 'espace-traiteur' ), number_format_i18n( $total ) ), $action );
		do_action( 'etr_gestion_catalogue' ); // Fonctions activables : matériel en réparation.

		echo '<div class="etr-barre-outils"><form class="etr-recherche-gestion" method="get" action="' . esc_url( self::url() ) . '" role="search">';
		if ( ! get_option( 'permalink_structure' ) ) {
			echo '<input type="hidden" name="etr_gestion" value="1">';
		}
		echo '<input type="hidden" name="vue" value="catalogue"><span class="etr-recherche-gestion__champ">' . self::icone( 'recherche' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<label class="screen-reader-text" for="etr-cat-q">' . esc_html__( 'Rechercher un article', 'espace-traiteur' ) . '</label>';
		echo '<input type="search" id="etr-cat-q" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Nom, référence, catégorie…', 'espace-traiteur' ) . '"></span>';
		echo '<button type="submit" class="screen-reader-text">' . esc_html__( 'Rechercher', 'espace-traiteur' ) . '</button></form></div>';

		if ( ! $articles ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Le catalogue est vide. Ajoutez des articles depuis l’administration.', 'espace-traiteur' ) . '</p></div>';
			return;
		}

		// Toutes les lignes sont rendues ; la recherche masque celles qui ne correspondent pas (filtrage instantané en JS).
		$lignes = array();
		foreach ( $articles as $article ) {
			$texte    = self::cle_recherche( $article['titre'] . ' ' . $article['reference'] . ' ' . $article['categorie'] );
			$lignes[] = array( $article, $texte, self::correspond( $texte, $cle ) );
		}
		$visibles = count( array_filter( array_column( $lignes, 2 ) ) );

		echo '<p class="etr-vide etr-vide--compact" id="etr-cat-vide"' . ( $visibles ? ' hidden' : '' ) . '>' . esc_html__( 'Aucun article ne correspond à cette recherche.', 'espace-traiteur' ) . '</p>';
		echo '<div class="etr-table-cadre" id="etr-cat-table"' . ( $visibles ? '' : ' hidden' ) . '><table class="etr-table etr-table--catalogue">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Article', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Stock', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Sorti aujourd’hui', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Disponible aujourd’hui', 'espace-traiteur' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Modifier', 'espace-traiteur' ) . '</span></th></tr></thead><tbody>';
		$categorie = null;
		$groupe    = 0;
		foreach ( $lignes as $ligne ) {
			list( $article, $texte, $visible ) = $ligne;
			if ( $article['categorie'] !== $categorie ) {
				$categorie = $article['categorie'];
				++$groupe;
				$groupe_visible = (bool) array_filter(
					$lignes,
					function ( $l ) use ( $categorie ) {
						return $l[2] && $l[0]['categorie'] === $categorie;
					}
				);
				echo '<tr class="etr-table__groupe" data-groupe="' . (int) $groupe . '"' . ( $groupe_visible ? '' : ' hidden' ) . '><th scope="rowgroup" colspan="5">' . esc_html( $categorie ? $categorie : __( 'Sans catégorie', 'espace-traiteur' ) ) . '</th></tr>';
			}
			$stock = etr_stock_total( $article['id'] );
			$sorti = isset( $dehors[ $article['id'] ] ) ? (int) $dehors[ $article['id'] ] : 0;
			$dispo = etr_disponibilite( $article['id'], $dehors );
			echo '<tr class="etr-cat-ligne" data-groupe="' . (int) $groupe . '" data-recherche="' . esc_attr( $texte ) . '"' . ( $visible ? '' : ' hidden' ) . '>';
			echo '<td><span class="etr-table__article">' . ( $article['image'] ? wp_kses_post( $article['image'] ) : '<span class="etr-article__img etr-article__img--vide">' . self::icone( 'image' ) . '</span>' ) . '<span><strong>' . esc_html( $article['titre'] ) . '</strong>' . ( $article['reference'] ? '<small>' . esc_html( $article['reference'] ) . '</small>' : '' ) . '</span></span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="etr-table__nombre">' . ( null === $stock ? '<span class="etr-muted">' . esc_html__( 'Non suivi', 'espace-traiteur' ) . '</span>' : esc_html( number_format_i18n( $stock ) ) ) . '</td>';
			echo '<td class="etr-table__nombre">' . ( $sorti ? esc_html( number_format_i18n( $sorti ) ) : '<span class="etr-muted">–</span>' ) . '</td>';
			echo '<td class="etr-table__nombre">' . ( null === $dispo ? '<span class="etr-muted">–</span>' : '<span class="' . ( 0 === $dispo ? 'etr-manque' : '' ) . '">' . esc_html( number_format_i18n( $dispo ) ) . '</span>' ) . '</td>';
			$lien = get_edit_post_link( $article['id'], 'url' );
			echo '<td class="etr-table__actions">' . ( $lien ? '<a class="etr-btn-texte" href="' . esc_url( $lien ) . '">' . esc_html__( 'Modifier', 'espace-traiteur' ) . '</a>' : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Tous les mots de la recherche figurent-ils dans le texte ?
	 *
	 * @param string $texte Texte normalisé.
	 * @param string $cle   Recherche normalisée.
	 * @return bool
	 */
	private static function correspond( $texte, $cle ) {
		foreach ( array_filter( explode( ' ', $cle ), 'strlen' ) as $mot ) {
			if ( false === strpos( $texte, $mot ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Texte normalisé pour la recherche (minuscules, sans accents, espaces simples).
	 * Doit rester identique à la normalisation de assets/js/gestion.js.
	 *
	 * @param string $texte Texte.
	 * @return string
	 */
	private static function cle_recherche( $texte ) {
		$texte = strtolower( remove_accents( wp_strip_all_tags( (string) $texte ) ) );
		return trim( preg_replace( '/\s+/', ' ', $texte ) );
	}
}
