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

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		if ( ETR_Access::traiteur_seulement( get_current_user_id() ) ) {
			wp_safe_redirect( etr_url() );
			exit;
		}
		if ( ! etr_est_gestionnaire() ) {
			wp_die( esc_html__( 'L’espace de gestion est réservé à l’équipe.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}

		global $wp_query;
		$wp_query->is_home = false;
		$wp_query->is_404  = false;
		status_header( 200 );

		$action = isset( $_REQUEST['etr_gestion_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['etr_gestion_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- vérifié par action.
		if ( 'decision' === $action ) {
			self::action_decision();
		} elseif ( 'creer_traiteur' === $action ) {
			self::action_creer_traiteur();
		} elseif ( 'inviter' === $action || 'basculer' === $action ) {
			self::action_compte( $action );
		}
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
	 * Création d'un traiteur : redirection si réussie, sinon formulaire réaffiché.
	 */
	private static function action_creer_traiteur() {
		check_admin_referer( 'etr_gestion_creer_traiteur', 'etr_nonce' );
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas le droit de créer des comptes.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		$donnees = array();
		foreach ( array( 'societe', 'contact', 'email', 'telephone', 'identifiant' ) as $cle ) {
			$donnees[ $cle ] = isset( $_POST[ $cle ] ) ? wp_unslash( $_POST[ $cle ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé par creer_compte().
		}
		$resultat = ETR_Traiteurs::creer_compte( $donnees );
		if ( is_wp_error( $resultat ) ) {
			self::$erreurs_traiteur = array( $resultat->get_error_code() );
			return;
		}
		wp_safe_redirect(
			self::url(
				array(
					'vue'     => 'traiteurs',
					'etr_msg' => $resultat['invitation'] ? 'cree' : 'cree_sans_mail',
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
	 * Styles.
	 */
	public static function assets() {
		if ( ! self::actif() ) {
			return;
		}
		wp_enqueue_style( 'espace-traiteur', ETR_URL . 'assets/css/espace-traiteur.css', array(), ETR_VERSION );
		wp_enqueue_style( 'espace-traiteur-gestion', ETR_URL . 'assets/css/gestion.css', array( 'espace-traiteur' ), ETR_VERSION );
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
		return $nom . ' – ' . __( 'Gestion', 'espace-traiteur' ) . ' – ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
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
	 * Toutes les demandes publiées (méta préchargées).
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
		return array(
			'tableau'   => array( __( 'Tableau de bord', 'espace-traiteur' ), 'accueil' ),
			'demandes'  => array( __( 'Demandes', 'espace-traiteur' ), 'demandes' ),
			'planning'  => array( __( 'Planning', 'espace-traiteur' ), 'calendrier' ),
			'traiteurs' => array( __( 'Traiteurs', 'espace-traiteur' ), 'invites' ),
			'catalogue' => array( __( 'Catalogue', 'espace-traiteur' ), 'catalogue' ),
		);
	}

	/**
	 * Vue demandée.
	 *
	 * @return string
	 */
	private static function vue_courante() {
		$vue = isset( $_GET['vue'] ) ? sanitize_key( wp_unslash( $_GET['vue'] ) ) : 'tableau'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( self::$erreurs_traiteur ) {
			return 'traiteurs';
		}
		if ( 'demande' === $vue ) {
			return isset( $_GET['id'] ) ? 'demande' : 'demandes'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		return array_key_exists( $vue, self::vues() ) ? $vue : 'tableau';
	}

	/**
	 * Affiche l'application.
	 */
	public static function rendu() {
		$vue   = self::vue_courante();
		$actif = 'demande' === $vue ? 'demandes' : $vue;

		echo '<div class="etr-app etr-gestion">';
		self::barre_laterale( $actif );
		echo '<main class="etr-app__principal" id="etr-contenu">';
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
			default:
				self::vue_tableau();
		}

		echo '</main></div>';
	}

	/**
	 * Icône.
	 *
	 * @param string $nom Nom.
	 * @return string
	 */
	private static function icone( $nom ) {
		return ETR_Frontend::icone( $nom );
	}

	/**
	 * Barre latérale.
	 *
	 * @param string $actif Vue active.
	 */
	private static function barre_laterale( $actif ) {
		$user      = wp_get_current_user();
		$logo_id   = ETR_Comptes::logo_id();
		$site      = get_bloginfo( 'name' );
		$a_traiter = count( self::filtrer_statut( 'en_attente' ) );

		echo '<header class="etr-app__barre">';
		echo '<a class="etr-app__logo" href="' . esc_url( self::url() ) . '">';
		if ( $logo_id ) {
			echo wp_get_attachment_image( $logo_id, 'medium', false, array( 'alt' => $site ) );
		} else {
			echo '<span class="etr-app__nom-site">' . esc_html( $site ) . '</span>';
		}
		echo '<span class="etr-gestion__marque">' . esc_html__( 'Gestion des traiteurs', 'espace-traiteur' ) . '</span>';
		echo '</a>';

		echo '<nav class="etr-app__nav" aria-label="' . esc_attr__( 'Gestion', 'espace-traiteur' ) . '"><ul>';
		foreach ( self::vues() as $cle => $vue ) {
			$badge = ( 'demandes' === $cle && $a_traiter ) ? '<span class="etr-gestion__pastille" title="' . esc_attr__( 'À traiter', 'espace-traiteur' ) . '">' . esc_html( number_format_i18n( $a_traiter ) ) . '</span>' : '';
			echo '<li><a href="' . esc_url( self::url( 'tableau' === $cle ? array() : array( 'vue' => $cle ) ) ) . '"' . ( $cle === $actif ? ' aria-current="page"' : '' ) . '>' . self::icone( $vue[1] ) . '<span>' . esc_html( $vue[0] ) . '</span>' . $badge . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique, badge échappé.
		}
		echo '</ul></nav>';

		echo '<div class="etr-app__compte">';
		echo '<p class="etr-app__societe">' . esc_html( etr_nom( $user ) ) . '<span>' . esc_html( $user->user_email ) . '</span></p>';
		echo '<ul class="etr-app__liens">';
		$liens = array(
			array( etr_url(), __( 'Voir l’espace traiteur', 'espace-traiteur' ), 'site' ),
			array( admin_url( 'edit.php?post_type=etr_demande&page=etr-reglages' ), __( 'Réglages', 'espace-traiteur' ), 'reglages' ),
			array( admin_url(), __( 'Administration WordPress', 'espace-traiteur' ), 'externe' ),
			array( wp_logout_url( self::url() ), __( 'Se déconnecter', 'espace-traiteur' ), 'sortie' ),
		);
		foreach ( $liens as $lien ) {
			echo '<li><a href="' . esc_url( $lien[0] ) . '">' . self::icone( $lien[2] ) . '<span>' . esc_html( $lien[1] ) . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></div>';
		echo '</header>';
	}

	/**
	 * En-tête de page.
	 *
	 * @param string $titre  Titre.
	 * @param string $sous   Sous-titre (texte brut).
	 * @param string $action HTML échappé (bouton, badge).
	 * @param string $retour HTML échappé (lien de retour).
	 */
	private static function entete( $titre, $sous = '', $action = '', $retour = '' ) {
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
			'enregistre' => __( 'Message enregistré. Le statut n’a pas changé : aucun e-mail n’a été envoyé.', 'espace-traiteur' ),
			'cree'       => __( 'Traiteur ajouté. Il a reçu un e-mail pour choisir son mot de passe.', 'espace-traiteur' ),
			'invite'     => __( 'Invitation renvoyée.', 'espace-traiteur' ),
			'desactive'  => __( 'Accès désactivé : le traiteur est déconnecté et ne peut plus se connecter.', 'espace-traiteur' ),
			'reactive'   => __( 'Accès réactivé.', 'espace-traiteur' ),
		);
		$alertes = array(
			'cree_sans_mail' => __( 'Traiteur ajouté, mais l’e-mail d’invitation n’est pas parti. Renvoyez l’invitation depuis la liste.', 'espace-traiteur' ),
			'mail_echec'     => __( 'L’e-mail n’est pas parti. Vérifiez la configuration des e-mails du site.', 'espace-traiteur' ),
		);
		if ( isset( $succes[ $msg ] ) ) {
			echo '<div class="etr-notice etr-notice--succes" role="status">' . self::icone( 'coche' ) . '<p>' . esc_html( $succes[ $msg ] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( isset( $alertes[ $msg ] ) ) {
			echo '<div class="etr-notice etr-notice--erreur" role="alert">' . self::icone( 'alerte' ) . '<p>' . esc_html( $alertes[ $msg ] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Lien vers une demande.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	private static function url_demande( $id ) {
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
		$html    = '<a class="etr-mvt etr-mvt--' . esc_attr( $mvt['type'] ) . ( $attente ? ' etr-mvt--attente' : '' ) . '" href="' . esc_url( self::url_demande( $mvt['id'] ) ) . '">';
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
		echo '<div class="etr-table-cadre"><table class="etr-table">';
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
			echo '<td>' . etr_statut_badge( etr_get_statut( $id ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Demandes                                                           */
	/* ------------------------------------------------------------------ */

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
		$base = array_filter(
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
		$comptes = array_fill_keys( array_keys( $statuts ), 0 );
		foreach ( $base as $id ) {
			$s = etr_get_statut( $id );
			if ( isset( $comptes[ $s ] ) ) {
				++$comptes[ $s ];
			}
		}
		$liste = $statut ? array_filter(
			$base,
			function ( $id ) use ( $statut ) {
				return etr_get_statut( $id ) === $statut;
			}
		) : $base;
		$liste = self::trier( array_values( $liste ) );
		$total = count( $liste );
		$pages = max( 1, (int) ceil( $total / self::PAR_PAGE ) );
		$page  = min( $page, $pages );

		/* translators: %s : nombre de demandes. */
		self::entete( __( 'Demandes', 'espace-traiteur' ), sprintf( _n( '%s demande', '%s demandes', count( self::demandes() ), 'espace-traiteur' ), number_format_i18n( count( self::demandes() ) ) ) );

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
		if ( ! $demande || 'etr_demande' !== $demande->post_type || 'publish' !== $demande->post_status ) {
			echo $retour; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Cette demande n’existe pas ou a été supprimée.', 'espace-traiteur' ) . '</p></div>';
			return;
		}

		$statut = etr_get_statut( $id );
		$auteur = get_userdata( (int) $demande->post_author );
		/* translators: 1 : numéro, 2 : traiteur, 3 : date d'envoi. */
		$sous = sprintf( __( 'Demande n° %1$d de %2$s, reçue le %3$s', 'espace-traiteur' ), $id, etr_nom( $auteur ), get_the_date( '', $demande ) );
		self::entete( get_the_title( $demande ), $sous, '<div class="etr-page-entete__statut">' . etr_statut_badge( $statut ) . '</div>', $retour );

		echo '<div class="etr-dossier">';
		echo '<div class="etr-dossier__principal">';
		self::panneau_materiel( $id );
		self::panneaux_infos( $id );
		echo '</div>';

		echo '<aside class="etr-dossier__cote">';
		self::panneau_decision( $id, $statut );
		self::panneau_traiteur( $auteur );
		if ( current_user_can( 'edit_post', $id ) ) {
			echo '<p class="etr-dossier__wp"><a class="etr-lien" href="' . esc_url( get_edit_post_link( $id, 'url' ) ) . '">' . esc_html__( 'Ouvrir dans l’administration WordPress', 'espace-traiteur' ) . '</a></p>';
		}
		echo '</aside>';
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
		$chiffrage = etr_chiffrage( $id );
		echo '<ul class="etr-lignes etr-lignes--prix">';
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
			echo etr_prix_ligne_html( $chiffrage['lignes'][ $index ], (int) $ligne['quantite'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans etr_prix_ligne_html().
			echo '</li>';
		}
		echo '</ul>';
		echo etr_total_indicatif_html( $chiffrage ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans etr_total_indicatif_html().
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
		echo '<form class="etr-panneau etr-decision" method="post" action="' . esc_url( self::url_demande( $id ) ) . '">';
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
				echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" role="cell">';
				echo '<time datetime="' . esc_attr( $date ) . '">' . esc_html( date_i18n( 'j', strtotime( $date ) ) ) . '</time>';
				if ( ! empty( $jours[ $date ] ) ) {
					echo '<ul>';
					foreach ( $jours[ $date ] as $mvt ) {
						echo '<li>' . self::mouvement_html( $mvt ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
					}
					echo '</ul>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
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
			echo '<div class="etr-table-cadre"><table class="etr-table etr-table--traiteurs">';
			echo '<thead><tr><th scope="col">' . esc_html__( 'Traiteur', 'espace-traiteur' ) . '</th><th scope="col">' . esc_html__( 'Coordonnées', 'espace-traiteur' ) . '</th><th scope="col">' . esc_html__( 'Demandes', 'espace-traiteur' ) . '</th><th scope="col">' . esc_html__( 'Accès', 'espace-traiteur' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'espace-traiteur' ) . '</span></th></tr></thead><tbody>';
			foreach ( $traiteurs as $user ) {
				$desactive = ETR_Comptes::est_desactive( $user->ID );
				$nb        = isset( $nombre[ $user->ID ] ) ? $nombre[ $user->ID ] : 0;
				$contact   = (string) get_user_meta( $user->ID, 'etr_contact', true );
				$tel       = (string) get_user_meta( $user->ID, 'etr_telephone', true );
				echo '<tr' . ( $desactive ? ' class="etr-table__passee"' : '' ) . '>';
				echo '<td><strong>' . esc_html( etr_nom( $user ) ) . '</strong><small>' . esc_html( $contact ? $contact : $user->user_login ) . '</small></td>';
				echo '<td><a class="etr-lien" href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a>' . ( $tel ? '<small>' . esc_html( $tel ) . '</small>' : '' ) . '</td>';
				echo '<td>';
				if ( $nb ) {
					echo '<a class="etr-lien" href="' . esc_url( self::url( array( 'vue' => 'demandes', 'traiteur' => $user->ID ) ) ) . '">' . esc_html( number_format_i18n( $nb ) ) . '</a>';
					/* translators: %s : date. */
					echo '<small>' . esc_html( sprintf( __( 'Dernière le %s', 'espace-traiteur' ), get_the_date( 'j M Y', $derniere[ $user->ID ] ) ) ) . '</small>';
				} else {
					echo '<span class="etr-muted">' . esc_html__( 'Aucune', 'espace-traiteur' ) . '</span>';
				}
				echo '</td>';
				echo '<td>' . ( $desactive ? '<span class="etr-statut etr-statut--refusee">' . esc_html__( 'Désactivé', 'espace-traiteur' ) . '</span>' : '<span class="etr-statut etr-statut--validee">' . esc_html__( 'Actif', 'espace-traiteur' ) . '</span>' ) . '</td>';
				echo '<td class="etr-table__actions">';
				if ( ! $desactive ) {
					self::bouton_compte( 'inviter', $user->ID, __( 'Renvoyer l’invitation', 'espace-traiteur' ) );
				}
				self::bouton_compte( 'basculer', $user->ID, $desactive ? __( 'Réactiver', 'espace-traiteur' ) : __( 'Désactiver', 'espace-traiteur' ), $desactive ? '' : __( 'Désactiver l’accès de ce traiteur ? Il sera déconnecté immédiatement.', 'espace-traiteur' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';

		if ( current_user_can( 'create_users' ) ) {
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
	 */
	private static function bouton_compte( $action, $user_id, $libelle, $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( self::url( array( 'vue' => 'traiteurs' ) ) ) . '"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : '' ) . '>';
		wp_nonce_field( 'etr_gestion_' . $action . '_' . $user_id, 'etr_nonce', false );
		echo '<input type="hidden" name="etr_gestion_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="user" value="' . (int) $user_id . '">';
		echo '<button type="submit" class="etr-btn-texte">' . esc_html( $libelle ) . '</button>';
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
		echo '<p class="etr-muted">' . esc_html__( 'Il reçoit un e-mail pour choisir son mot de passe, puis se connecte à l’espace traiteur.', 'espace-traiteur' ) . '</p>';
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
		echo '<button type="submit" class="etr-btn etr-btn--plein">' . esc_html__( 'Ajouter et envoyer l’invitation', 'espace-traiteur' ) . '</button>';
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
		if ( '' !== $q ) {
			$articles = array_filter(
				$articles,
				function ( $a ) use ( $q ) {
					return false !== stripos( remove_accents( $a['titre'] . ' ' . $a['reference'] . ' ' . $a['categorie'] ), remove_accents( $q ) );
				}
			);
		}
		$jour      = self::aujourdhui();
		$dehors    = etr_quantites_reservees( $jour, $jour );
		$woo       = etr_source_woocommerce();
		$gerer_url = $woo ? admin_url( 'edit.php?post_type=product' ) : admin_url( 'edit.php?post_type=etr_article' );
		$action    = '<a class="etr-btn etr-btn--discret" href="' . esc_url( $gerer_url ) . '">' . self::icone( 'externe' ) . '<span>' . esc_html( $woo ? __( 'Gérer les produits', 'espace-traiteur' ) : __( 'Gérer les articles', 'espace-traiteur' ) ) . '</span></a>';

		/* translators: %s : nombre d'articles. */
		self::entete( __( 'Catalogue', 'espace-traiteur' ), sprintf( _n( '%s article proposé aux traiteurs', '%s articles proposés aux traiteurs', $total, 'espace-traiteur' ), number_format_i18n( $total ) ), $action );

		echo '<div class="etr-barre-outils"><form class="etr-recherche-gestion" method="get" action="' . esc_url( self::url() ) . '" role="search">';
		if ( ! get_option( 'permalink_structure' ) ) {
			echo '<input type="hidden" name="etr_gestion" value="1">';
		}
		echo '<input type="hidden" name="vue" value="catalogue"><span class="etr-recherche-gestion__champ">' . self::icone( 'recherche' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<label class="screen-reader-text" for="etr-cat-q">' . esc_html__( 'Rechercher un article', 'espace-traiteur' ) . '</label>';
		echo '<input type="search" id="etr-cat-q" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Nom, référence, catégorie…', 'espace-traiteur' ) . '"></span>';
		echo '<button type="submit" class="screen-reader-text">' . esc_html__( 'Rechercher', 'espace-traiteur' ) . '</button></form></div>';

		if ( ! $articles ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html( '' !== $q ? __( 'Aucun article ne correspond à cette recherche.', 'espace-traiteur' ) : __( 'Le catalogue est vide. Ajoutez des articles depuis l’administration.', 'espace-traiteur' ) ) . '</p></div>';
			return;
		}

		echo '<div class="etr-table-cadre"><table class="etr-table etr-table--catalogue">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Article', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Stock', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Sorti aujourd’hui', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Disponible aujourd’hui', 'espace-traiteur' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Modifier', 'espace-traiteur' ) . '</span></th></tr></thead><tbody>';
		$categorie = null;
		foreach ( $articles as $article ) {
			if ( $article['categorie'] !== $categorie ) {
				$categorie = $article['categorie'];
				echo '<tr class="etr-table__groupe"><th scope="rowgroup" colspan="5">' . esc_html( $categorie ? $categorie : __( 'Sans catégorie', 'espace-traiteur' ) ) . '</th></tr>';
			}
			$stock = etr_stock_total( $article['id'] );
			$sorti = isset( $dehors[ $article['id'] ] ) ? (int) $dehors[ $article['id'] ] : 0;
			$dispo = etr_disponibilite( $article['id'], $dehors );
			echo '<tr>';
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
}
