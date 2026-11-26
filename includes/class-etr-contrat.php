<?php
/**
 * Contrat de location signé en ligne (fonction activable « contrat »).
 *
 * Après la validation (et l'acceptation du devis quand les devis sont actifs), le traiteur signe
 * au doigt, dans son espace, un contrat qui reprend le matériel, sa valeur de remplacement, les
 * dates, les montants et les conditions de location réglées dans l'administration. Le contrat
 * signé est figé (contenu, empreinte SHA-256, nom, date, adresse IP) ; le PDF est envoyé au
 * traiteur et au bureau. Si la demande change ensuite (matériel, dates, montants), le contrat
 * est à signer de nouveau.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contrat de location.
 */
class ETR_Contrat {

	const OPTION  = 'etr_contrat';
	const META    = '_etr_contrat';
	const ANCIENS = '_etr_contrats_anciens';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
		add_action( 'etr_fonctions_reglages', array( __CLASS__, 'champs_reglages' ) );
		add_action( 'etr_traiteur_paiement', array( __CLASS__, 'encart_traiteur' ), 3 );
		add_action( 'etr_traiteur_action', array( __CLASS__, 'action_signer' ) );
		add_action( 'etr_gestion_fiche_argent', array( __CLASS__, 'panneau' ), 25 );
		add_action( 'template_redirect', array( __CLASS__, 'telechargement' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ), 30 );
		add_filter( 'etr_traiteur_taches', array( __CLASS__, 'tache_traiteur' ), 5, 2 );
		add_filter( 'etr_gestion_taches', array( __CLASS__, 'tache_bureau' ), 10, 2 );
		add_filter( 'etr_stripe_peut_payer', array( __CLASS__, 'peut_payer' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Réglages                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Conditions de location proposées par défaut (modifiables dans Fonctions).
	 *
	 * @return string
	 */
	public static function conditions_par_defaut() {
		return implode(
			"\n\n",
			array(
				__( '1. Objet. Le loueur met à la disposition du locataire le matériel listé ci-dessus, pour la période allant de la livraison à la reprise.', 'espace-traiteur' ),
				__( '2. Garde du matériel. Dès la livraison et jusqu’à la reprise, le locataire a la garde du matériel et en est responsable. Il ne peut ni le sous-louer, ni le prêter, ni le déplacer hors du lieu indiqué sans l’accord du loueur.', 'espace-traiteur' ),
				__( '3. État du matériel. Le matériel est livré propre et en bon état ; toute réserve est signalée à la livraison. Il est rendu dans l’état où il a été livré, débarrassé des déchets et des restes alimentaires.', 'espace-traiteur' ),
				__( '4. Casse et manquants. Le matériel cassé, perdu ou manquant à la reprise est facturé à sa valeur de remplacement indiquée ci-dessus. La caution peut être retenue à hauteur de ce montant.', 'espace-traiteur' ),
				__( '5. Accès. Le locataire garantit l’accès au lieu aux dates et créneaux convenus. Une attente, un accès difficile ou une manutention imprévue peuvent être facturés.', 'espace-traiteur' ),
				__( '6. Paiement et annulation. L’acompte, le solde et la caution sont réglés selon les montants ci-dessus et les conditions générales du loueur, qui s’appliquent aussi en cas d’annulation.', 'espace-traiteur' ),
			)
		);
	}

	/**
	 * Paramètres.
	 *
	 * @return array { conditions, avant_paiement }
	 */
	public static function parametres() {
		$p = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $p ) ? $p : array(),
			array(
				'conditions'     => self::conditions_par_defaut(),
				'avant_paiement' => 1,
			)
		);
	}

	/**
	 * Option (page Fonctions).
	 */
	public static function reglages() {
		register_setting(
			'etr_modules',
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => function ( $saisie ) {
					$saisie     = is_array( $saisie ) ? $saisie : array();
					$conditions = isset( $saisie['conditions'] ) ? trim( str_replace( "\r\n", "\n", sanitize_textarea_field( $saisie['conditions'] ) ) ) : '';
					return array(
						'conditions'     => '' !== $conditions ? mb_substr( $conditions, 0, 20000 ) : self::conditions_par_defaut(),
						'avant_paiement' => empty( $saisie['avant_paiement'] ) ? 0 : 1,
					);
				},
			)
		);
	}

	/**
	 * Champs sous la fonction.
	 *
	 * @param string $cle Fonction.
	 */
	public static function champs_reglages( $cle ) {
		if ( 'contrat' !== $cle ) {
			return;
		}
		$p = self::parametres();
		echo '<p><label for="etr-contrat-conditions">' . esc_html__( 'Conditions de location (reprises dans chaque contrat)', 'espace-traiteur' ) . '</label><br><textarea class="large-text" rows="10" id="etr-contrat-conditions" name="' . esc_attr( self::OPTION ) . '[conditions]">' . esc_textarea( $p['conditions'] ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Un paragraphe par ligne vide. Laissez vide pour revenir au texte proposé. Un contrat déjà signé garde le texte du jour de sa signature.', 'espace-traiteur' ) . '</p>';
		echo '<p><input type="hidden" name="' . esc_attr( self::OPTION ) . '[avant_paiement]" value="0"><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[avant_paiement]" value="1"' . checked( $p['avant_paiement'], 1, false ) . '> ' . esc_html__( 'Contrat à signer avant le paiement en ligne de l’acompte et de la caution', 'espace-traiteur' ) . '</label></p>';
	}

	/* ------------------------------------------------------------------ */
	/* Contenu et état                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Contrat signé en cours (le dernier).
	 *
	 * @param int $id Demande.
	 * @return array
	 */
	public static function signe( $id ) {
		$c = get_post_meta( $id, self::META, true );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * Ce que dit le contrat aujourd'hui (figé dans le contrat signé).
	 *
	 * @param int $id Demande.
	 * @return array
	 */
	public static function contenu( $id ) {
		$auteur  = get_userdata( (int) get_post_field( 'post_author', $id ) );
		$societe = $auteur ? get_userdata( function_exists( 'etr_societe_id' ) ? etr_societe_id( $auteur->ID ) : $auteur->ID ) : false;
		$societe = $societe ? $societe : $auteur;
		$f       = class_exists( 'ETR_Facture' ) ? ETR_Facture::parametres() : array();
		list( $livraison, $reprise ) = ETR_Operations::jours( $id );

		$lignes = array();
		foreach ( etr_get_lignes( $id ) as $ligne ) {
			$lignes[] = array(
				'titre'    => (string) $ligne['titre'],
				'quantite' => (int) $ligne['quantite'],
				'unite'    => isset( $ligne['unite'] ) ? (string) $ligne['unite'] : '',
				'valeur'   => class_exists( 'ETR_Devis' ) ? ETR_Devis::prix_remplacement( (int) $ligne['article_id'] ) : null,
			);
		}
		$devis   = ETR_Modules::actif( 'devis' ) ? ETR_Devis::devis( $id ) : array();
		$total   = $devis && ! empty( $devis['accepte'] ) ? (float) $devis['total'] : (float) etr_chiffrage( $id )['total'];
		$acompte = ETR_Modules::actif( 'acompte' ) ? ETR_Devis::acompte( $id ) : array();

		return array(
			'bailleur'   => array(
				'nom'     => ! empty( $f['raison'] ) ? $f['raison'] : etr_nom_site(),
				'adresse' => isset( $f['adresse'] ) ? (string) $f['adresse'] : '',
				'siret'   => isset( $f['siret'] ) ? (string) $f['siret'] : '',
			),
			'locataire'  => array(
				'nom'       => etr_nom( $societe ),
				'email'     => $societe ? $societe->user_email : '',
				'telephone' => $societe ? (string) get_user_meta( $societe->ID, 'etr_telephone', true ) : '',
			),
			'evenement'  => get_the_title( $id ),
			'date'       => (string) get_post_meta( $id, '_etr_date_evenement', true ),
			'lieu'       => (string) get_post_meta( $id, '_etr_lieu', true ),
			'livraison'  => (string) $livraison,
			'reprise'    => (string) $reprise,
			'lignes'     => $lignes,
			'total'      => round( $total, 2 ),
			'acompte'    => isset( $acompte['acompte'] ) && null !== $acompte['acompte'] ? (float) $acompte['acompte'] : null,
			'caution'    => isset( $acompte['caution'] ) && null !== $acompte['caution'] ? (float) $acompte['caution'] : null,
			'conditions' => self::parametres()['conditions'],
			'cgv'        => (string) get_option( 'etr_cgv_url' ),
		);
	}

	/**
	 * Empreinte de ce qui engage les deux parties (matériel, dates, lieu, montants) : un
	 * changement de ces éléments demande une nouvelle signature, pas un changement de mise en page.
	 *
	 * @param array $contenu Contenu.
	 * @return string
	 */
	public static function empreinte( $contenu ) {
		$essentiel = array_intersect_key( $contenu, array_flip( array( 'lignes', 'date', 'lieu', 'livraison', 'reprise', 'total', 'acompte', 'caution' ) ) );
		return hash( 'sha256', (string) wp_json_encode( $essentiel ) );
	}

	/**
	 * État du contrat : a_signer (rien de signé), a_resigner (la demande a changé), signe, attente
	 * (pas encore le moment : demande en attente ou devis pas accepté), aucun (pas concerné).
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function etat( $id ) {
		if ( ! ETR_Modules::actif( 'contrat' ) ) {
			return 'aucun';
		}
		$statut = etr_get_statut( $id );
		$signe  = self::signe( $id );
		if ( in_array( $statut, array( 'refusee', 'annulee' ), true ) ) {
			return $signe ? 'signe' : 'aucun';
		}
		if ( in_array( $statut, array( 'livree', 'cloturee' ), true ) ) {
			return $signe ? 'signe' : 'aucun'; // Après la livraison, on ne fait plus signer.
		}
		if ( 'validee' !== $statut ) {
			return $signe ? 'signe' : 'attente';
		}
		if ( ETR_Modules::actif( 'devis' ) ) {
			$devis = ETR_Devis::devis( $id );
			if ( ! $devis || empty( $devis['accepte'] ) ) {
				return $signe ? 'a_resigner' : 'attente'; // Nouveau devis à accepter d'abord.
			}
		}
		if ( ! $signe ) {
			return 'a_signer';
		}
		return self::empreinte( self::contenu( $id ) ) === $signe['empreinte'] ? 'signe' : 'a_resigner';
	}

	/* ------------------------------------------------------------------ */
	/* Espace traiteur                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Tâche « Signer le contrat » (bloc « Actions de votre part »).
	 *
	 * @param array[] $t  Tâches.
	 * @param int     $id Demande.
	 * @return array[]
	 */
	public static function tache_traiteur( $t, $id ) {
		$etat = self::etat( $id );
		if ( in_array( $etat, array( 'a_signer', 'a_resigner' ), true ) ) {
			// En tête : le paiement en ligne peut l'attendre.
			array_unshift( $t, array( 'a_resigner' === $etat ? __( 'Signer de nouveau le contrat de location', 'espace-traiteur' ) : __( 'Signer le contrat de location', 'espace-traiteur' ), '#etr-contrat' ) );
		}
		return $t;
	}

	/**
	 * Tâche du bureau : contrat qui attend la signature du traiteur.
	 *
	 * @param array[] $t  Tâches.
	 * @param int     $id Demande.
	 * @return array[]
	 */
	public static function tache_bureau( $t, $id ) {
		$etat = self::etat( $id );
		if ( in_array( $etat, array( 'a_signer', 'a_resigner' ), true ) ) {
			$t[] = array(
				'cle'    => 'contrat',
				'onglet' => 'argent',
				'texte'  => 'a_resigner' === $etat ? __( 'Contrat à faire signer de nouveau (la demande a changé)', 'espace-traiteur' ) : __( 'Contrat pas encore signé par le traiteur', 'espace-traiteur' ),
				'urgent' => false,
			);
		}
		return $t;
	}

	/**
	 * Paiement en ligne de l'acompte et de la caution : après la signature (si réglé ainsi).
	 *
	 * @param bool   $peut Paiement possible.
	 * @param int    $id   Demande.
	 * @param string $type acompte | caution | solde.
	 * @return bool
	 */
	public static function peut_payer( $peut, $id, $type ) {
		if ( ! $peut || 'solde' === $type || ! self::parametres()['avant_paiement'] ) {
			return $peut;
		}
		return ! in_array( self::etat( $id ), array( 'a_signer', 'a_resigner' ), true );
	}

	/**
	 * Script de signature (fiche d'une demande de l'espace traiteur).
	 */
	public static function script() {
		if ( ! ETR_Modules::actif( 'contrat' ) || ! class_exists( 'ETR_Frontend' ) || ! ETR_Frontend::sur_page_espace() ) {
			return;
		}
		wp_enqueue_script( 'espace-traiteur-contrat', ETR_URL . 'assets/js/contrat.js', array(), ETR_VERSION, true );
	}

	/**
	 * Bloc « Contrat de location » (bloc Paiement de la fiche du traiteur).
	 *
	 * @param int $id Demande.
	 */
	public static function encart_traiteur( $id ) {
		$etat = self::etat( $id );
		if ( in_array( $etat, array( 'aucun', 'attente' ), true ) || ! etr_peut_voir_demande( $id ) ) {
			return;
		}
		$signe = self::signe( $id );
		echo '<section class="etr-panneau etr-encart-traiteur etr-contrat" id="etr-contrat"><h2 class="etr-panneau__titre">' . esc_html__( 'Contrat de location', 'espace-traiteur' ) . '</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification -- message après la signature.
		if ( isset( $_GET['etr_msg'] ) && 'contrat_signe' === sanitize_key( wp_unslash( $_GET['etr_msg'] ) ) ) {
			echo '<p class="etr-notice etr-notice--succes">' . esc_html__( 'Merci : le contrat est signé. Vous le recevez par e-mail.', 'espace-traiteur' ) . '</p>';
		}
		$erreur = get_transient( 'etr_contrat_erreur_' . get_current_user_id() );
		if ( $erreur ) {
			delete_transient( 'etr_contrat_erreur_' . get_current_user_id() );
			echo '<p class="etr-notice etr-notice--erreur" role="alert">' . esc_html( $erreur ) . '</p>';
		}
		if ( $signe ) {
			/* translators: 1 : nom, 2 : date et heure. */
			echo '<p>' . esc_html( sprintf( __( 'Signé par %1$s le %2$s.', 'espace-traiteur' ), $signe['nom'], wp_date( 'j F Y à H:i', (int) $signe['le'] ) ) ) . ' <a class="etr-lien" href="' . esc_url( self::url_pdf( $id ) ) . '">' . esc_html__( 'Télécharger (PDF)', 'espace-traiteur' ) . '</a></p>';
		}
		if ( 'signe' === $etat ) {
			echo '</section>';
			return;
		}
		if ( 'a_resigner' === $etat ) {
			echo '<p class="etr-notice">' . esc_html__( 'Votre demande a changé depuis la signature (matériel, dates ou montants) : merci de signer la nouvelle version.', 'espace-traiteur' ) . '</p>';
		}
		$c = self::contenu( $id );
		echo '<details class="etr-contrat__texte"><summary>' . esc_html__( 'Lire le contrat', 'espace-traiteur' ) . '</summary>';
		echo self::resume_html( $c ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans resume_html().
		echo '</details>';
		echo '<form method="post" class="etr-contrat__signer" action="' . esc_url( etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) ) . '">';
		wp_nonce_field( 'etr_contrat_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_action" value="signer_contrat"><input type="hidden" name="demande_id" value="' . (int) $id . '"><input type="hidden" name="empreinte" value="' . esc_attr( self::empreinte( $c ) ) . '"><input type="hidden" name="signature" value="" data-contrat-signature>';
		echo '<p class="etr-champ"><label for="etr-contrat-nom">' . esc_html__( 'Votre nom', 'espace-traiteur' ) . '</label><input type="text" id="etr-contrat-nom" name="nom" required maxlength="100" autocomplete="name" value="' . esc_attr( etr_nom( wp_get_current_user() ) ) . '"></p>';
		echo '<div class="etr-champ etr-contrat__zone" data-contrat-zone><span class="etr-contrat__libelle" id="etr-contrat-signature-libelle">' . esc_html__( 'Votre signature', 'espace-traiteur' ) . '</span><canvas class="etr-contrat__toile" aria-labelledby="etr-contrat-signature-libelle" role="img"></canvas><button type="button" class="etr-lien" data-contrat-effacer>' . esc_html__( 'Effacer', 'espace-traiteur' ) . '</button><span class="etr-contrat__aide" data-contrat-aide>' . esc_html__( 'Signez avec le doigt ou la souris dans le cadre.', 'espace-traiteur' ) . '</span></div>';
		echo '<p class="etr-case"><label><input type="checkbox" name="accord" value="1" required> ' . esc_html__( 'J’ai lu le contrat et j’accepte les conditions de location.', 'espace-traiteur' ) . '</label></p>';
		echo '<button type="submit" class="etr-btn">' . esc_html__( 'Signer le contrat', 'espace-traiteur' ) . '</button>';
		echo '</form></section>';
	}

	/**
	 * Résumé HTML du contrat (avant signature).
	 *
	 * @param array $c Contenu.
	 * @return string
	 */
	private static function resume_html( $c ) {
		$html  = '<div class="etr-contrat__corps">';
		/* translators: 1 : loueur, 2 : locataire. */
		$html .= '<p>' . esc_html( sprintf( __( 'Entre %1$s (le loueur) et %2$s (le locataire).', 'espace-traiteur' ), $c['bailleur']['nom'], $c['locataire']['nom'] ) ) . '</p>';
		$html .= '<p>' . esc_html( self::periode_texte( $c ) ) . '</p>';
		$html .= '<ul class="etr-liste-simple">';
		foreach ( $c['lignes'] as $l ) {
			$html .= '<li>' . esc_html( number_format_i18n( $l['quantite'] ) . ' × ' . $l['titre'] . ( null !== $l['valeur'] ? ' — ' . sprintf( /* translators: %s : montant. */ __( 'valeur de remplacement %s l’unité', 'espace-traiteur' ), ETR_Devis::prix( $l['valeur'] ) ) : '' ) ) . '</li>';
		}
		$html .= '</ul><p>' . esc_html( self::montants_texte( $c ) ) . '</p>';
		foreach ( preg_split( "/\n\s*\n/", (string) $c['conditions'] ) as $para ) {
			$html .= '<p>' . nl2br( esc_html( trim( $para ) ) ) . '</p>';
		}
		if ( $c['cgv'] ) {
			$html .= '<p><a class="etr-lien" href="' . esc_url( $c['cgv'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Conditions générales', 'espace-traiteur' ) . '</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * Événement, lieu et période en une phrase.
	 *
	 * @param array $c Contenu.
	 * @return string
	 */
	private static function periode_texte( $c ) {
		/* translators: 1 : événement, 2 : date, 3 : lieu, 4 : livraison, 5 : reprise. */
		return sprintf( __( 'Événement « %1$s » le %2$s, %3$s. Livraison le %4$s, reprise le %5$s.', 'espace-traiteur' ), $c['evenement'], etr_format_date( $c['date'] ), preg_replace( '/\s+/', ' ', $c['lieu'] ), etr_format_date( $c['livraison'] ), etr_format_date( $c['reprise'] ) );
	}

	/**
	 * Montants en une phrase.
	 *
	 * @param array $c Contenu.
	 * @return string
	 */
	private static function montants_texte( $c ) {
		/* translators: %s : montant. */
		$t = array( sprintf( __( 'Montant de la location : %s TTC', 'espace-traiteur' ), ETR_Devis::prix( $c['total'] ) ) );
		if ( null !== $c['acompte'] && $c['acompte'] > 0 ) {
			/* translators: %s : montant. */
			$t[] = sprintf( __( 'acompte : %s', 'espace-traiteur' ), ETR_Devis::prix( $c['acompte'] ) );
		}
		if ( null !== $c['caution'] && $c['caution'] > 0 ) {
			/* translators: %s : montant. */
			$t[] = sprintf( __( 'caution : %s', 'espace-traiteur' ), ETR_Devis::prix( $c['caution'] ) );
		}
		return implode( ' · ', $t ) . '.';
	}

	/**
	 * Le traiteur signe.
	 *
	 * @param string $action Action.
	 */
	public static function action_signer( $action ) {
		if ( 'signer_contrat' !== $action ) {
			return;
		}
		$id    = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		$nonce = isset( $_POST['etr_nonce'] ) ? sanitize_key( wp_unslash( $_POST['etr_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'etr_contrat_' . $id ) || ! etr_peut_voir_demande( $id ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		$retour = etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) );
		$erreur = function ( $message ) use ( $retour ) {
			set_transient( 'etr_contrat_erreur_' . get_current_user_id(), $message, 120 );
			wp_safe_redirect( add_query_arg( 'etr_msg', 'contrat_erreur', $retour ) . '#etr-contrat' );
			exit;
		};
		$etat = self::etat( $id );
		if ( ! in_array( $etat, array( 'a_signer', 'a_resigner' ), true ) ) {
			wp_safe_redirect( $retour . '#etr-contrat' ); // Déjà signé, ou plus le moment.
			exit;
		}
		$nom = isset( $_POST['nom'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['nom'] ) ), 0, 100 ) : '';
		if ( '' === $nom || empty( $_POST['accord'] ) ) {
			$erreur( __( 'Indiquez votre nom et cochez l’acceptation des conditions.', 'espace-traiteur' ) );
		}
		$contenu = self::contenu( $id );
		$attendu = isset( $_POST['empreinte'] ) ? sanitize_key( wp_unslash( $_POST['empreinte'] ) ) : '';
		if ( ! hash_equals( self::empreinte( $contenu ), $attendu ) ) {
			// La demande a changé pendant la lecture : on ne fait pas signer un texte que la personne n'a pas vu.
			$erreur( __( 'Le contrat a changé pendant votre lecture (matériel, dates ou montants). Relisez-le, puis signez de nouveau.', 'espace-traiteur' ) );
		}
		$fichier = ETR_Fichiers::enregistrer_signature( isset( $_POST['signature'] ) ? wp_unslash( $_POST['signature'] ) : '', $id, 'contrat' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- image vérifiée par enregistrer_signature().
		if ( is_wp_error( $fichier ) ) {
			$erreur( __( 'Signez dans le cadre avant d’envoyer.', 'espace-traiteur' ) );
		}

		$ancien = self::signe( $id );
		if ( $ancien ) {
			$anciens   = get_post_meta( $id, self::ANCIENS, true );
			$anciens   = is_array( $anciens ) ? $anciens : array();
			$anciens[] = $ancien; // Les versions précédentes restent gardées (preuve).
			update_post_meta( $id, self::ANCIENS, array_slice( $anciens, -10 ) );
		}
		$contrat = array(
			'le'         => time(),
			'par'        => get_current_user_id(),
			'nom'        => $nom,
			'signature'  => $fichier,
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'navigateur' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
			'contenu'    => $contenu,
			'empreinte'  => self::empreinte( $contenu ),
			'document'   => hash( 'sha256', (string) wp_json_encode( $contenu ) ),
			'version'    => ( $ancien ? (int) $ancien['version'] : 0 ) + 1,
		);
		update_post_meta( $id, self::META, $contrat );
		/* translators: %s : nom. */
		ETR_Historique::ajouter( $id, 'contrat', sprintf( __( 'Contrat de location signé par %s', 'espace-traiteur' ), $nom ) );
		/* translators: 1 : traiteur, 2 : événement. */
		ETR_Push::notifier( 'bureau', sprintf( __( 'Contrat signé : %s', 'espace-traiteur' ), etr_nom( wp_get_current_user() ) ), get_the_title( $id ), $id, '', 'contrat' );
		self::envoyer( $id );
		wp_safe_redirect( add_query_arg( 'etr_msg', 'contrat_signe', $retour ) . '#etr-contrat' );
		exit;
	}

	/**
	 * Envoie le contrat signé (PDF joint) au traiteur et au bureau.
	 *
	 * @param int $id Demande.
	 */
	private static function envoyer( $id ) {
		$c = self::signe( $id );
		if ( ! $c ) {
			return;
		}
		$fichier = trailingslashit( get_temp_dir() ) . self::nom_fichier( $id );
		file_put_contents( $fichier, self::pdf( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$titre = sprintf( /* translators: %s : événement. */ __( 'Contrat de location signé — %s', 'espace-traiteur' ), get_the_title( $id ) );
		$texte = sprintf( /* translators: 1 : nom, 2 : date. */ __( 'Le contrat de location a été signé par %1$s le %2$s. Vous le trouverez en pièce jointe.', 'espace-traiteur' ), $c['nom'], wp_date( 'j F Y à H:i', (int) $c['le'] ) );
		$user  = get_userdata( (int) $c['par'] );
		if ( $user && is_email( $user->user_email ) ) {
			/* translators: 1 : site, 2 : numéro, 3 : titre. */
			etr_envoyer_mail( $user->user_email, sprintf( __( '[%1$s] Demande #%2$d : %3$s', 'espace-traiteur' ), etr_nom_site(), $id, $titre ), sprintf( /* translators: %s : nom. */ __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n" . $texte . "\n\n" . etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ), array(), array( $fichier ) );
		}
		$equipe = apply_filters( 'etr_email_equipe', is_email( get_option( 'etr_email_equipe' ) ) ? get_option( 'etr_email_equipe' ) : get_option( 'admin_email' ) );
		if ( is_email( $equipe ) ) {
			/* translators: 1 : site, 2 : numéro, 3 : titre. */
			etr_envoyer_mail( $equipe, sprintf( __( '[%1$s] Demande #%2$d : %3$s', 'espace-traiteur' ), etr_nom_site(), $id, $titre ), $texte . "\n\n" . ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'onglet' => 'argent' ) ), array(), array( $fichier ) );
		}
		wp_delete_file( $fichier );
	}

	/* ------------------------------------------------------------------ */
	/* Bureau                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Panneau « Contrat de location » (onglet Argent de la fiche).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau( $id ) {
		$etat = self::etat( $id );
		if ( 'aucun' === $etat && ! self::signe( $id ) ) {
			return;
		}
		$signe  = self::signe( $id );
		$textes = array(
			'attente'    => __( 'À signer par le traiteur une fois la demande validée (et le devis accepté).', 'espace-traiteur' ),
			'a_signer'   => __( 'Pas encore signé : le traiteur le voit dans « Actions de votre part ».', 'espace-traiteur' ),
			'a_resigner' => __( 'La demande a changé depuis la signature : le traiteur doit signer la nouvelle version.', 'espace-traiteur' ),
			'signe'      => '',
			'aucun'      => '',
		);
		echo '<section class="etr-panneau etr-contrat"><h2 class="etr-panneau__titre">' . esc_html__( 'Contrat de location', 'espace-traiteur' ) . '</h2>';
		if ( $signe ) {
			/* translators: 1 : nom, 2 : date et heure, 3 : version. */
			echo '<p>' . esc_html( sprintf( __( 'Signé par %1$s le %2$s (version %3$s).', 'espace-traiteur' ), $signe['nom'], wp_date( 'j F Y à H:i', (int) $signe['le'] ), number_format_i18n( (int) $signe['version'] ) ) ) . '</p>';
			echo '<p><a class="etr-lien" target="_blank" href="' . esc_url( self::url_pdf( $id ) ) . '">' . esc_html__( 'Voir le contrat signé (PDF)', 'espace-traiteur' ) . '</a></p>';
		}
		if ( $textes[ $etat ] ) {
			echo '<p class="' . ( 'a_resigner' === $etat ? 'etr-notice' : 'etr-muted' ) . '">' . esc_html( $textes[ $etat ] ) . '</p>';
		}
		echo '</section>';
	}

	/* ------------------------------------------------------------------ */
	/* PDF                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Adresse du PDF.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function url_pdf( $id ) {
		return add_query_arg( array( 'etr_document' => 'contrat', 'id' => (int) $id ), etr_url() );
	}

	/**
	 * Nom du fichier.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	private static function nom_fichier( $id ) {
		return 'contrat-location-' . (int) $id . '-' . sanitize_title( get_the_title( $id ) ) . '.pdf';
	}

	/**
	 * Téléchargement : le traiteur de la demande ou l'équipe (bureau).
	 */
	public static function telechargement() {
		if ( ! isset( $_GET['etr_document'], $_GET['id'] ) || 'contrat' !== sanitize_key( wp_unslash( $_GET['etr_document'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id     = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$equipe = ETR_Equipe::acces() && ETR_Equipe::bureau() && ETR_Gestion::demande_visible( $id );
		$permis = is_user_logged_in() && 'etr_demande' === get_post_type( $id ) && ( etr_peut_voir_demande( $id ) || $equipe ) && self::signe( $id );
		if ( ! $permis ) {
			wp_die( esc_html__( 'Document introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$pdf = self::pdf( $id );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . self::nom_fichier( $id ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- document PDF.
		exit;
	}

	/**
	 * Le contrat signé (PDF), d'après le contenu figé à la signature.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function pdf( $id ) {
		$s       = self::signe( $id );
		$c       = $s['contenu'];
		$m       = 42;
		$largeur = ETR_PDF::LARGEUR - 2 * $m;
		$encre   = '#1d1d1b';
		$gris    = '#6b655f';
		/* translators: %d : numéro de demande. */
		$pdf  = new ETR_PDF( sprintf( __( 'Contrat de location — demande n° %d', 'espace-traiteur' ), $id ) );
		$bas  = ETR_PDF::HAUTEUR - 60;
		$place = function ( $y, $hauteur ) use ( $pdf, $bas, $m ) {
			if ( $y + $hauteur > $bas ) {
				$pdf->page();
				return $m;
			}
			return $y;
		};

		// En-tête.
		$y       = $m;
		$logo_id = ETR_Comptes::logo_id();
		$logo    = $logo_id ? ETR_Bon_Livraison::jpeg( get_attached_file( $logo_id ), 400 ) : '';
		if ( $logo ) {
			$pdf->image( $logo, $m, $y, 110 );
		} else {
			$pdf->texte( $m, $y + 18, $c['bailleur']['nom'], 18, true, $encre );
		}
		$pdf->texte( $m + $largeur, $y + 14, __( 'CONTRAT DE LOCATION', 'espace-traiteur' ), 15, true, $encre, 'droite' );
		/* translators: 1 : numéro, 2 : version. */
		$pdf->texte( $m + $largeur, $y + 30, sprintf( __( 'Demande n° %1$d · version %2$s', 'espace-traiteur' ), $id, number_format_i18n( (int) $s['version'] ) ), 10, false, $gris, 'droite' );
		$y += 70;
		$pdf->trait( $m, $y, $m + $largeur, $y, $encre, 1 );
		$y += 24;

		// Parties.
		$col = $largeur / 2 - 10;
		$pdf->texte( $m, $y, __( 'LE LOUEUR', 'espace-traiteur' ), 8, true, $gris );
		$y_g = $pdf->paragraphe( $m, $y + 15, $col, $c['bailleur']['nom'], 11, true );
		$y_g = $pdf->paragraphe( $m, $y_g, $col, trim( $c['bailleur']['adresse'] . ( $c['bailleur']['siret'] ? "\n" . sprintf( /* translators: %s : SIRET. */ __( 'SIRET %s', 'espace-traiteur' ), $c['bailleur']['siret'] ) : '' ) ), 9.5, false, '#3a3732' );
		$x_d = $m + $largeur / 2 + 10;
		$pdf->texte( $x_d, $y, __( 'LE LOCATAIRE', 'espace-traiteur' ), 8, true, $gris );
		$y_d = $pdf->paragraphe( $x_d, $y + 15, $col, $c['locataire']['nom'], 11, true );
		$y_d = $pdf->paragraphe( $x_d, $y_d, $col, trim( $c['locataire']['email'] . "\n" . $c['locataire']['telephone'] ), 9.5, false, '#3a3732' );
		$y   = max( $y_g, $y_d ) + 18;

		// Événement et période.
		$pdf->texte( $m, $y, __( 'ÉVÉNEMENT ET PÉRIODE DE LOCATION', 'espace-traiteur' ), 8, true, $gris );
		$y = $pdf->paragraphe( $m, $y + 15, $largeur, self::periode_texte( $c ), 10 ) + 14;

		// Matériel.
		$y = $place( $y, 60 );
		$pdf->texte( $m, $y, __( 'MATÉRIEL LOUÉ', 'espace-traiteur' ), 8, true, $gris );
		$y += 10;
		$pdf->rectangle( $m, $y, $largeur, 20, '#efebe4' );
		$pdf->texte( $m + 8, $y + 13.5, __( 'Article', 'espace-traiteur' ), 9, true );
		$pdf->texte( $m + $largeur - 130, $y + 13.5, __( 'Quantité', 'espace-traiteur' ), 9, true, $encre, 'droite' );
		$pdf->texte( $m + $largeur - 8, $y + 13.5, __( 'Valeur de remplacement', 'espace-traiteur' ), 9, true, $encre, 'droite' );
		$y += 20;
		foreach ( $c['lignes'] as $i => $l ) {
			$lignes = ETR_PDF::couper( $l['titre'], 10, $largeur - 250 );
			$haut   = 8 + 13.5 * count( $lignes );
			$y      = $place( $y, $haut );
			if ( $i % 2 ) {
				$pdf->rectangle( $m, $y, $largeur, $haut, '#faf9f6' );
			}
			foreach ( $lignes as $n => $texte ) {
				$pdf->texte( $m + 8, $y + 14 + 13.5 * $n, $texte, 10 );
			}
			$pdf->texte( $m + $largeur - 130, $y + 14, number_format_i18n( $l['quantite'] ) . ( $l['unite'] ? ' ' . $l['unite'] : '' ), 10, false, $encre, 'droite' );
			$pdf->texte( $m + $largeur - 8, $y + 14, null !== $l['valeur'] ? sprintf( /* translators: %s : montant. */ __( '%s l’unité', 'espace-traiteur' ), html_entity_decode( ETR_Devis::prix( $l['valeur'] ) ) ) : '—', 10, false, $encre, 'droite' );
			$y += $haut;
			$pdf->trait( $m, $y, $m + $largeur, $y, '#e3ddd3', 0.5 );
		}
		$y = $pdf->paragraphe( $m, $place( $y + 16, 30 ), $largeur, html_entity_decode( self::montants_texte( $c ) ), 10, true ) + 14;

		// Conditions.
		$y = $place( $y, 40 );
		$pdf->texte( $m, $y, __( 'CONDITIONS DE LOCATION', 'espace-traiteur' ), 8, true, $gris );
		$y += 15;
		foreach ( preg_split( "/\n\s*\n/", (string) $c['conditions'] ) as $para ) {
			$para = trim( preg_replace( '/\s*\n\s*/', ' ', $para ) );
			if ( '' === $para ) {
				continue;
			}
			$y = $place( $y, count( ETR_PDF::couper( $para, 9.5, $largeur ) ) * 9.5 * 1.35 );
			$y = $pdf->paragraphe( $m, $y, $largeur, $para, 9.5, false, '#3a3732' ) + 6;
		}
		if ( $c['cgv'] ) {
			/* translators: %s : adresse. */
			$y = $pdf->paragraphe( $m, $place( $y, 20 ), $largeur, sprintf( __( 'Conditions générales : %s', 'espace-traiteur' ), $c['cgv'] ), 9, false, $gris ) + 6;
		}

		// Signature.
		$y = $place( $y + 14, 170 );
		$pdf->texte( $m, $y, __( 'SIGNATURE DU LOCATAIRE', 'espace-traiteur' ), 8, true, $gris );
		/* translators: 1 : nom, 2 : date et heure. */
		$y         = $pdf->paragraphe( $m, $y + 16, $largeur, sprintf( __( 'Lu et accepté par %1$s le %2$s, depuis son espace traiteur.', 'espace-traiteur' ), $s['nom'], wp_date( 'j F Y à H:i:s', (int) $s['le'] ) ), 10 );
		$chemin    = ETR_Fichiers::chemin( $s['signature'] );
		$signature = $chemin ? ETR_Bon_Livraison::jpeg( $chemin, 700 ) : '';
		if ( $signature ) {
			$h = $pdf->image( $signature, $m, $y + 4, 190 );
			$pdf->trait( $m, $y + 8 + $h, $m + 190, $y + 8 + $h, '#d9d3c8' );
			$pdf->texte( $m, $y + 22 + $h, $s['nom'], 9, false, $gris );
			$y += $h + 36;
		}
		/* translators: 1 : adresse IP, 2 : empreinte. */
		$pdf->paragraphe( $m, $place( $y, 30 ), $largeur, sprintf( __( 'Signature électronique — adresse IP %1$s — empreinte SHA-256 du contrat : %2$s', 'espace-traiteur' ), $s['ip'] ? $s['ip'] : '—', $s['document'] ), 7.5, false, $gris );

		return $pdf->sortie();
	}
}
