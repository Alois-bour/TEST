<?php
/**
 * Fonctions activables : chaque fonction facultative s'active ou se désactive dans
 * Espace traiteur › Fonctions. Une fonction désactivée n'affiche rien, n'envoie rien
 * et refuse ses routes.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registre des fonctions activables.
 */
class ETR_Modules {

	const OPTION = 'etr_modules';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
	}

	/**
	 * Groupes de fonctions : groupe => { titre, fonctions : clé => { libellé, description } }.
	 *
	 * @return array
	 */
	public static function groupes() {
		return array(
			'materiel' => array(
				__( 'Matériel et planning', 'espace-traiteur' ),
				array(
					'deplacer_dates' => array( __( 'Déplacer une livraison ou une reprise', 'espace-traiteur' ), __( 'Planning : le bureau change la date d’une livraison ou d’une reprise (glisser sur ordinateur, « Changer la date » sur téléphone). Le stock est vérifié sur la nouvelle période et le traiteur est prévenu.', 'espace-traiteur' ) ),
					'reparation'     => array( __( 'Matériel en réparation', 'espace-traiteur' ), __( 'La casse constatée à la reprise sort du stock disponible jusqu’à ce que le bureau la remette en stock (écran Catalogue). Évite de louer du matériel cassé.', 'espace-traiteur' ) ),
				),
			),
			'stock' => array(
				__( 'Stock', 'espace-traiteur' ),
				array(
					'historique_casse' => array( __( 'Historique de casse par article', 'espace-traiteur' ), __( 'Statistiques : casse et manquants de chaque article sur 12 mois, taux de pertes, articles « à racheter ».', 'espace-traiteur' ) ),
					'inventaire'       => array( __( 'Inventaire', 'espace-traiteur' ), __( 'Onglet « Inventaire » (bureau) : on compte à l’entrepôt depuis le téléphone, l’écart avec le stock attendu (possédé − chez les traiteurs − en réparation) s’affiche, le stock est corrigé en un clic.', 'espace-traiteur' ) ),
					'alertes_stock'    => array( __( 'Alertes de stock bas', 'espace-traiteur' ), __( 'Articles dont la quantité libre passe sous un seuil dans les semaines à venir : encart dans le Catalogue et notification quotidienne au bureau.', 'espace-traiteur' ) ),
				),
			),
			'logistique' => array(
				__( 'Logistique', 'espace-traiteur' ),
				array(
					'volume'            => array( __( 'Volume du camion', 'espace-traiteur' ), __( 'Volume de chaque produit (fiche produit › Inventaire) ; sur chaque demande, volume total et part du camion, alerte quand il faut plusieurs trajets (compté aussi dans l’estimation de la livraison) ; volume à charger sur la tournée du jour.', 'espace-traiteur' ) ),
					'creneaux'          => array( __( 'Créneaux de livraison et de reprise', 'espace-traiteur' ), __( 'Le traiteur choisit un créneau (matin, après-midi…) ; nombre d’étapes limité par créneau et par jour si vous le souhaitez ; créneau affiché sur la fiche et la tournée.', 'espace-traiteur' ) ),
					'tournee_optimisee' => array( __( 'Tournée optimisée', 'espace-traiteur' ), __( 'Bouton « Optimiser l’ordre de passage » sur la tournée (bureau) : étapes rangées par créneau, puis au plus court depuis le dépôt (adresse de départ de l’estimation de la livraison).', 'espace-traiteur' ) ),
				),
			),
			'traiteur' => array(
				__( 'Le traiteur informé en direct', 'espace-traiteur' ),
				array(
					'push_traiteur' => array( __( 'Notifications sur l’iPhone du traiteur', 'espace-traiteur' ), __( 'Le traiteur qui a installé son espace peut recevoir les notifications : demande validée ou refusée, livreur en route, livré, repris, message de l’équipe, date déplacée.', 'espace-traiteur' ) ),
					'en_route'      => array( __( '« En route » avec heure d’arrivée', 'espace-traiteur' ), __( 'Sur la tournée, le livreur touche « En route » et choisit le temps de trajet : le traiteur reçoit l’heure d’arrivée estimée (e-mail et notification) et la voit dans son suivi.', 'espace-traiteur' ) ),
					'messages'      => array( __( 'Messages par demande', 'espace-traiteur' ), __( 'Fil de discussion entre le bureau et le traiteur sur chaque demande, à la place des échanges d’e-mails. Chaque message est signalé à l’autre partie.', 'espace-traiteur' ) ),
					'recommander'   => array( __( 'Commander à nouveau', 'espace-traiteur' ), __( 'Le traiteur reprend une ancienne demande comme modèle : événement, lieu et matériel préremplis, seules les dates sont à choisir.', 'espace-traiteur' ) ),
					'packs'            => array( __( 'Packs prêts à commander', 'espace-traiteur' ), __( 'Vous composez des packs (« Mariage 100 personnes », « Cocktail »…), en quantités fixes ou par invité ; le traiteur en ajoute un en un clic dans sa demande.', 'espace-traiteur' ) ),
					'suggestions'      => array( __( 'Quantités suggérées', 'espace-traiteur' ), __( 'Bouton « Suggérer les quantités pour mes invités » : quantité conseillée par invité de chaque produit × nombre d’invités (quantités vides seulement, dans la limite du disponible).', 'espace-traiteur' ) ),
					'calendrier_dispo' => array( __( 'Calendrier des disponibilités', 'espace-traiteur' ), __( 'Écran « Disponibilités » de l’espace traiteur : pour un article, quantité encore libre jour par jour.', 'espace-traiteur' ) ),
					'multi_comptes'    => array( __( 'Plusieurs comptes par société', 'espace-traiteur' ), __( 'Les collaborateurs d’un traiteur ont leur propre accès et voient les demandes de toute la société (écran « Mon équipe »).', 'espace-traiteur' ) ),
					'avis'             => array( __( 'Avis après l’événement', 'espace-traiteur' ), __( 'Après la clôture, le traiteur reçoit une invitation à noter la location (1 à 5) et à laisser un commentaire ; le bureau est prévenu ; note moyenne dans les statistiques.', 'espace-traiteur' ) ),
				),
			),
			'argent'   => array(
				__( 'Livraison, devis, acompte et casse', 'espace-traiteur' ),
				array(
					'estimation_livraison' => array( __( 'Estimation du prix de la livraison', 'espace-traiteur' ), __( 'Sur chaque demande, pour le bureau et l’administration seulement (jamais le traiteur) : distance par la route depuis le dépôt, gazole, usage du camion, temps du personnel, et prix conseillé à facturer. Bouton « Utiliser ce prix » dans la tarification.', 'espace-traiteur' ) ),
					'devis'        => array( __( 'Devis PDF et acceptation en ligne', 'espace-traiteur' ), __( 'Le bureau envoie un devis PDF (matériel, remise, livraison, total) ; le traiteur l’accepte dans son espace en cochant les conditions générales. Nom, date et heure de l’acceptation sont gardés.', 'espace-traiteur' ) ),
					'acompte'      => array( __( 'Acompte et caution', 'espace-traiteur' ), __( 'Le bureau indique l’acompte et la caution demandés, puis reçus (et la caution rendue). Le traiteur les voit sur sa demande et sur le devis. Pas de paiement en ligne : les traiteurs n’ont pas accès au panier.', 'espace-traiteur' ) ),
					'casse'        => array( __( 'Casse et manquants facturés', 'espace-traiteur' ), __( 'Après une reprise avec écarts, le montant est calculé avec le « Prix de remplacement » de chaque produit ; le bureau vérifie puis envoie le relevé PDF au traiteur.', 'espace-traiteur' ) ),
					'relances'     => array( __( 'Relances automatiques', 'espace-traiteur' ), __( 'Chaque jour : devis pas encore accepté après 3 jours (e-mail au traiteur, une fois), reprise en retard et acompte non reçu à 7 jours de la livraison (notification au bureau).', 'espace-traiteur' ) ),
				),
			),
			'pilotage' => array(
				__( 'Pilotage', 'espace-traiteur' ),
				array(
					'pilotage_terrain' => array( __( 'Statistiques du terrain', 'espace-traiteur' ), __( 'Onglet Statistiques : livraisons et reprises par livreur, taux de casse et de manquants, incidents par type, sur les 12 derniers mois.', 'espace-traiteur' ) ),
				),
			),
			'existant' => array(
				__( 'Fonctions déjà en place', 'espace-traiteur' ),
				array(
					'face_id'        => array( __( 'Connexion par Face ID', 'espace-traiteur' ), __( 'Clés d’accès de l’application de l’équipe. Désactivée : le bouton disparaît et les clés enregistrées sont refusées (elles restent gardées).', 'espace-traiteur' ) ),
					'mails_html'     => array( __( 'E-mails mis en page (HTML)', 'espace-traiteur' ), __( 'Tous les e-mails du plugin aux couleurs du site : logo, titre, informations en tableau, boutons. Une version texte est toujours jointe. Désactivée : e-mails en texte simple.', 'espace-traiteur' ) ),
					'bon_livraison'  => array( __( 'Bon de livraison PDF envoyé au traiteur', 'espace-traiteur' ), __( 'E-mail au traiteur avec le bon signé en pièce jointe à chaque livraison, et téléchargement du bon.', 'espace-traiteur' ) ),
				),
			),
		);
	}

	/**
	 * État par défaut de chaque fonction (sans traduction : lu très tôt, dès le chargement).
	 *
	 * @return array<string,bool>
	 */
	public static function defauts() {
		return array(
			'deplacer_dates'   => true,
			'volume'           => true,
			'creneaux'         => false,
			'tournee_optimisee' => true,
			'reparation'       => false,
			'historique_casse' => true,
			'inventaire'       => true,
			'alertes_stock'    => false,
			'push_traiteur'    => true,
			'en_route'         => true,
			'messages'         => true,
			'recommander'      => true,
			'packs'            => true,
			'suggestions'      => true,
			'calendrier_dispo' => true,
			'multi_comptes'    => false,
			'avis'             => false,
			'estimation_livraison' => true,
			'devis'            => false,
			'acompte'          => false,
			'casse'            => false,
			'relances'         => false,
			'pilotage_terrain' => true,
			'face_id'          => true,
			'bon_livraison'    => true,
			'mails_html'       => true,
		);
	}

	/**
	 * Toutes les fonctions : clé => { libellé, description }.
	 *
	 * @return array
	 */
	public static function liste() {
		$liste = array();
		foreach ( self::groupes() as $groupe ) {
			$liste += $groupe[1];
		}
		return $liste;
	}

	/**
	 * La fonction est-elle active ?
	 *
	 * @param string $cle Fonction.
	 * @return bool
	 */
	public static function actif( $cle ) {
		$defauts = self::defauts();
		if ( ! isset( $defauts[ $cle ] ) ) {
			return false;
		}
		$choix = get_option( self::OPTION, array() );
		$actif = is_array( $choix ) && isset( $choix[ $cle ] ) ? '1' === $choix[ $cle ] : $defauts[ $cle ];
		/** Forcer une fonction depuis le code : add_filter( 'etr_module_actif', … ). */
		return (bool) apply_filters( 'etr_module_actif', $actif, $cle );
	}

	/**
	 * Option et réglages liés (conditions générales).
	 */
	public static function reglages() {
		register_setting(
			'etr_modules',
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'nettoyer' ),
			)
		);
		register_setting(
			'etr_modules',
			'etr_cgv_url',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			)
		);
		register_setting(
			'etr_modules',
			'etr_devis_validite',
			array(
				'type'              => 'integer',
				'default'           => 30,
				'sanitize_callback' => function ( $valeur ) {
					return max( 1, min( 365, absint( $valeur ) ) );
				},
			)
		);
	}

	/**
	 * Garde seulement les fonctions connues (cases décochées = « 0 »).
	 *
	 * @param mixed $valeur Saisie.
	 * @return array
	 */
	public static function nettoyer( $valeur ) {
		$propre = array();
		foreach ( array_keys( self::defauts() ) as $cle ) {
			$propre[ $cle ] = is_array( $valeur ) && ! empty( $valeur[ $cle ] ) ? '1' : '0';
		}
		return $propre;
	}

	/**
	 * Sous-menu « Fonctions ».
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=etr_demande',
			__( 'Fonctions de l’espace traiteur', 'espace-traiteur' ),
			__( 'Fonctions', 'espace-traiteur' ),
			'gerer_espace_traiteur',
			'etr-fonctions',
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Page « Fonctions » : une case par fonction, groupées.
	 */
	public static function page() {
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Fonctions de l’espace traiteur', 'espace-traiteur' ) . '</h1>';
		echo '<p>' . esc_html__( 'Activez seulement ce que vous utilisez. Une fonction désactivée disparaît de l’application de l’équipe et de l’espace des traiteurs ; ses données sont gardées et réapparaissent si vous la réactivez.', 'espace-traiteur' ) . '</p>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( 'etr_modules' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::groupes() as $groupe ) {
			echo '<tr><th scope="row" colspan="2"><h2 style="margin:16px 0 0">' . esc_html( $groupe[0] ) . '</h2></th></tr>';
			foreach ( $groupe[1] as $cle => $fonction ) {
				echo '<tr><th scope="row">' . esc_html( $fonction[0] ) . '</th><td>';
				echo '<input type="hidden" name="' . esc_attr( self::OPTION . '[' . $cle . ']' ) . '" value="0">';
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION . '[' . $cle . ']' ) . '" value="1"' . checked( self::actif( $cle ), true, false ) . '> ' . esc_html__( 'Activée', 'espace-traiteur' ) . '</label>';
				echo '<p class="description">' . esc_html( $fonction[1] ) . '</p>';
				if ( 'devis' === $cle ) {
					echo '<p><label>' . esc_html__( 'Conditions générales (adresse de la page)', 'espace-traiteur' ) . '<br><input type="url" class="regular-text" name="etr_cgv_url" value="' . esc_attr( get_option( 'etr_cgv_url' ) ) . '" placeholder="https://"></label></p>';
					echo '<p><label>' . esc_html__( 'Validité du devis (jours)', 'espace-traiteur' ) . ' <input type="number" min="1" max="365" class="small-text" name="etr_devis_validite" value="' . esc_attr( (string) get_option( 'etr_devis_validite', 30 ) ) . '"></label></p>';
				}
				do_action( 'etr_fonctions_reglages', $cle ); // Réglages propres à une fonction (estimation de la livraison).
				if ( 'casse' === $cle ) {
					echo '<p class="description">' . esc_html__( 'Prix de remplacement : fiche produit WooCommerce › Inventaire › « Prix de remplacement (casse) ». Sans prix, la ligne est indiquée « à chiffrer ».', 'espace-traiteur' ) . '</p>';
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}
}
