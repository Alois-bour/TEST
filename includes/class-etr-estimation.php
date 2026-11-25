<?php
/**
 * Estimation du prix de la livraison (fonction activable « estimation_livraison »), visible
 * seulement par le bureau et l'administration, jamais par le traiteur.
 *
 * Distance par la route entre l'adresse de départ (réglable) et le lieu de livraison :
 * - adresses : Base Adresse Nationale (api-adresse.data.gouv.fr) ;
 * - itinéraire : Géoplateforme de l'IGN (data.geopf.fr), sinon OSRM, sinon vol d'oiseau × 1,3.
 * Coût : deux allers-retours (livraison puis reprise), gazole, usage du camion, temps du personnel
 * (route et manutention), autres frais ; prix conseillé = coût + marge, arrondi.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Estimation de la livraison.
 */
class ETR_Estimation {

	const OPTION        = 'etr_estimation';
	const OPTION_DEPART = 'etr_estimation_depart';
	const META          = '_etr_estimation_trajet';
	const DELAI         = 8; // Secondes par appel aux services.
	const RAYON         = 30; // km autour du dépôt pour le prix du gazole.
	const CARBURANTS    = 'https://data.economie.gouv.fr/api/explore/v2.1/catalog/datasets/prix-des-carburants-en-france-flux-instantane-v2/records';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
		add_action( 'etr_fonctions_reglages', array( __CLASS__, 'champs_reglages' ) );
		add_action( 'etr_gestion_tarif_estimation', array( __CLASS__, 'encart_gestion' ) );
		add_action( 'etr_gestion_fiche_cote', array( __CLASS__, 'formulaire_recalculer' ), 1 );
		add_action( 'etr_gestion_action', array( __CLASS__, 'action_recalculer' ) );
		add_action( 'add_meta_boxes_etr_demande', array( __CLASS__, 'metabox' ) );
		// Calcul à l'avance (la fiche s'ouvre sans attendre les services).
		add_action( 'etr_demande_creee', array( __CLASS__, 'planifier' ) );
		add_action( 'etr_demande_modifiee', array( __CLASS__, 'planifier' ) );
		add_action( 'etr_estimer_trajet', array( __CLASS__, 'trajet' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Réglages                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Paramètres par défaut : camion 20 m³ avec hayon, gazole.
	 *
	 * @return array
	 */
	public static function defauts() {
		return array(
			'depart'      => 'Frolois 54140',
			'conso'       => 14.0,  // L/100 km.
			'gazole_source' => 'marche', // marche : prix réel des stations autour du dépôt ; manuel : prix saisi.
			'gazole'      => 2.00,  // €/L : prix saisi, et prix de secours si le prix du marché est indisponible.
			'usage_km'    => 0.20,  // €/km : entretien, pneus, assurance, amortissement.
			'taux'        => 30.0,  // €/h : coût chargé d'une personne.
			'personnes'   => 1,
			'manutention' => 30,    // Minutes sur place, à chaque livraison et à chaque reprise.
			'fixes'       => 0.0,   // € : péages, parking…
			'marge'       => 20.0,  // %.
			'minimum'     => 0.0,   // € : prix conseillé minimal.
			'arrondi'     => 5,     // € : arrondi supérieur du prix conseillé.
		);
	}

	/**
	 * Paramètres enregistrés.
	 *
	 * @return array
	 */
	public static function parametres() {
		$p = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $p ) ? $p : array(), self::defauts() );
	}

	/**
	 * Déclare l'option (page Fonctions).
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
	}

	/**
	 * Nettoie la saisie ; l'adresse de départ changée est relocalisée.
	 *
	 * @param mixed $saisie Saisie.
	 * @return array
	 */
	public static function nettoyer( $saisie ) {
		$saisie = is_array( $saisie ) ? $saisie : array();
		$propre = array();
		foreach ( self::defauts() as $cle => $defaut ) {
			if ( 'gazole_source' === $cle ) {
				$propre[ $cle ] = isset( $saisie[ $cle ] ) && 'manuel' === $saisie[ $cle ] ? 'manuel' : 'marche';
				continue;
			}
			if ( 'depart' === $cle ) {
				$valeur = isset( $saisie[ $cle ] ) ? trim( sanitize_text_field( $saisie[ $cle ] ) ) : '';
				$propre[ $cle ] = '' === $valeur ? $defaut : $valeur;
				continue;
			}
			$valeur         = isset( $saisie[ $cle ] ) ? etr_lire_nombre( (string) $saisie[ $cle ] ) : null;
			$propre[ $cle ] = null === $valeur ? $defaut : ( is_int( $defaut ) ? (int) round( $valeur ) : (float) $valeur );
		}
		$propre['personnes'] = max( 1, $propre['personnes'] );
		$propre['arrondi']   = max( 1, $propre['arrondi'] );
		$avant               = self::parametres();
		if ( $avant['depart'] !== $propre['depart'] ) {
			delete_option( self::OPTION_DEPART ); // Nouvelle adresse : relocalisée au prochain calcul.
			delete_transient( 'etr_gazole_marche' ); // Et prix du gazole relevé autour du nouveau dépôt.
		}
		return $propre;
	}

	/**
	 * Champs sous la fonction, page Fonctions.
	 *
	 * @param string $cle Fonction affichée.
	 */
	public static function champs_reglages( $cle ) {
		if ( 'estimation_livraison' !== $cle ) {
			return;
		}
		$p      = self::parametres();
		$champs = array(
			'depart'      => array( __( 'Adresse de départ (dépôt)', 'espace-traiteur' ), '' ),
			'conso'       => array( __( 'Consommation du camion', 'espace-traiteur' ), 'L/100 km' ),
			'usage_km'    => array( __( 'Usage du camion (entretien, pneus, assurance, amortissement)', 'espace-traiteur' ), '€/km' ),
			'taux'        => array( __( 'Coût horaire d’une personne (charges comprises)', 'espace-traiteur' ), '€/h' ),
			'personnes'   => array( __( 'Personnes par livraison', 'espace-traiteur' ), '' ),
			'manutention' => array( __( 'Temps sur place (déchargement, rechargement au hayon)', 'espace-traiteur' ), __( 'min par passage', 'espace-traiteur' ) ),
			'fixes'       => array( __( 'Autres frais par demande (péages, stationnement)', 'espace-traiteur' ), '€' ),
			'marge'       => array( __( 'Marge', 'espace-traiteur' ), '%' ),
			'minimum'     => array( __( 'Prix minimum conseillé', 'espace-traiteur' ), '€' ),
			'arrondi'     => array( __( 'Arrondi du prix conseillé', 'espace-traiteur' ), '€' ),
		);
		echo '<fieldset class="etr-estimation-reglages" style="margin-top:8px">';
		foreach ( $champs as $nom => $champ ) {
			$valeur = $p[ $nom ];
			if ( 'depart' !== $nom ) {
				$valeur = str_replace( '.', ',', (string) $valeur );
			}
			echo '<p><label>' . esc_html( $champ[0] ) . '<br><input type="text" class="' . ( 'depart' === $nom ? 'regular-text' : 'small-text' ) . '" name="' . esc_attr( self::OPTION . '[' . $nom . ']' ) . '" value="' . esc_attr( (string) $valeur ) . '"' . ( 'depart' === $nom ? '' : ' inputmode="decimal"' ) . '> ' . esc_html( $champ[1] ) . '</label></p>';
		}
		// Prix du gazole : marché (stations autour du dépôt) ou saisi.
		$marche = self::prix_marche();
		echo '<p><strong>' . esc_html__( 'Prix du gazole', 'espace-traiteur' ) . '</strong><br>';
		echo '<label><input type="radio" name="' . esc_attr( self::OPTION . '[gazole_source]' ) . '" value="marche"' . checked( $p['gazole_source'], 'marche', false ) . '> ' . esc_html__( 'Prix réel du marché (moyenne des stations autour du dépôt, mise à jour toutes les 6 heures)', 'espace-traiteur' ) . '</label><br>';
		echo '<label><input type="radio" name="' . esc_attr( self::OPTION . '[gazole_source]' ) . '" value="manuel"' . checked( $p['gazole_source'], 'manuel', false ) . '> ' . esc_html__( 'Prix saisi :', 'espace-traiteur' ) . '</label> ';
		echo '<input type="text" class="small-text" inputmode="decimal" name="' . esc_attr( self::OPTION . '[gazole]' ) . '" value="' . esc_attr( str_replace( '.', ',', (string) $p['gazole'] ) ) . '"> €/L</p>';
		echo '<p class="description">' . esc_html( $marche ? self::texte_marche( $marche ) : __( 'Prix du marché indisponible pour le moment : le prix saisi est utilisé en attendant.', 'espace-traiteur' ) ) . '</p>';
		$depart = get_option( self::OPTION_DEPART );
		if ( is_array( $depart ) && ! empty( $depart['label'] ) ) {
			/* translators: %s : adresse trouvée. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Départ localisé : %s', 'espace-traiteur' ), $depart['label'] ) ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Valeurs par défaut : camion 20 m³ avec hayon au gazole. Le calcul compte deux allers-retours (livraison, puis reprise). Les adresses sont envoyées à la Base Adresse Nationale et au calcul d’itinéraire de l’IGN (services publics gratuits).', 'espace-traiteur' ) . '</p>';
		echo '</fieldset>';
	}

	/* ------------------------------------------------------------------ */
	/* Prix du gazole                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Prix moyen du gazole relevé dans les stations : autour du dépôt, sinon dans son département,
	 * sinon en France (données publiques « Prix des carburants », data.economie.gouv.fr).
	 * Gardé 6 heures ; après un échec, nouvel essai au bout d'une heure.
	 *
	 * @return array|null { prix, stations, maj, zone }
	 */
	public static function prix_marche() {
		$garde = get_transient( 'etr_gazole_marche' );
		if ( is_array( $garde ) ) {
			return empty( $garde['prix'] ) ? null : $garde;
		}
		$depart = self::depart();
		$p      = self::parametres();
		$zones  = array();
		if ( $depart ) {
			/* translators: 1 : rayon, 2 : dépôt. */
			$zones[] = array( sprintf( "within_distance(geom, geom'POINT(%F %F)', %dkm)", $depart['lon'], $depart['lat'], self::RAYON ), sprintf( __( 'à moins de %1$d km de %2$s', 'espace-traiteur' ), self::RAYON, $depart['label'] ) );
		}
		if ( preg_match( '/\b(\d{2})\d{3}\b/', $p['depart'], $cp ) ) {
			/* translators: %s : département. */
			$zones[] = array( sprintf( 'code_departement="%s"', $cp[1] ), sprintf( __( 'dans le département %s', 'espace-traiteur' ), $cp[1] ) );
		}
		$zones[] = array( '', __( 'en France', 'espace-traiteur' ) );
		foreach ( $zones as $zone ) {
			$ou   = 'gazole_prix is not null' . ( $zone[0] ? ' and ' . $zone[0] : '' );
			$json = self::json( self::CARBURANTS . '?select=' . rawurlencode( 'avg(gazole_prix) as prix, count(gazole_prix) as stations, max(gazole_maj) as maj' ) . '&where=' . rawurlencode( $ou ) );
			$r    = isset( $json['results'][0] ) ? $json['results'][0] : null;
			if ( $r && isset( $r['prix'] ) && (float) $r['prix'] > 0.5 && (float) $r['prix'] < 5 && (int) $r['stations'] >= 3 ) {
				$marche = array(
					'prix'     => round( (float) $r['prix'], 3 ),
					'stations' => (int) $r['stations'],
					'maj'      => isset( $r['maj'] ) ? (string) $r['maj'] : '',
					'zone'     => $zone[1],
				);
				set_transient( 'etr_gazole_marche', $marche, 6 * HOUR_IN_SECONDS );
				return $marche;
			}
		}
		set_transient( 'etr_gazole_marche', array( 'prix' => 0 ), HOUR_IN_SECONDS );
		return null;
	}

	/**
	 * Phrase sur le prix du marché.
	 *
	 * @param array $marche Résultat de prix_marche().
	 * @return string
	 */
	private static function texte_marche( $marche ) {
		$maj = $marche['maj'] ? strtotime( $marche['maj'] ) : 0;
		return sprintf(
			/* translators: 1 : prix, 2 : nombre de stations, 3 : zone, 4 : date. */
			__( 'Prix du marché : %1$s €/L, moyenne de %2$s stations %3$s%4$s.', 'espace-traiteur' ),
			number_format_i18n( $marche['prix'], 3 ),
			number_format_i18n( $marche['stations'] ),
			$marche['zone'],
			/* translators: %s : date et heure. */
			$maj ? ' ' . sprintf( __( '(relevé jusqu’au %s)', 'espace-traiteur' ), wp_date( 'j F à H:i', $maj ) ) : ''
		);
	}

	/**
	 * Prix du gazole retenu pour le calcul.
	 *
	 * @return array { prix, texte }
	 */
	public static function gazole() {
		$p = self::parametres();
		if ( 'marche' === $p['gazole_source'] ) {
			$marche = self::prix_marche();
			if ( $marche ) {
				return array( 'prix' => $marche['prix'], 'texte' => self::texte_marche( $marche ) );
			}
			/* translators: %s : prix. */
			return array( 'prix' => (float) $p['gazole'], 'texte' => sprintf( __( 'Prix du marché indisponible : prix saisi utilisé (%s €/L).', 'espace-traiteur' ), number_format_i18n( $p['gazole'], 2 ) ) );
		}
		/* translators: %s : prix. */
		return array( 'prix' => (float) $p['gazole'], 'texte' => sprintf( __( 'Prix du gazole saisi : %s €/L.', 'espace-traiteur' ), number_format_i18n( $p['gazole'], 2 ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Services : adresses et itinéraire                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Appel JSON.
	 *
	 * @param string $url Adresse.
	 * @return array|null
	 */
	private static function json( $url ) {
		$reponse = wp_remote_get(
			$url,
			array(
				'timeout'    => self::DELAI,
				'user-agent' => 'EspaceTraiteur/' . ETR_VERSION . ' (WordPress)',
			)
		);
		if ( is_wp_error( $reponse ) || 200 !== (int) wp_remote_retrieve_response_code( $reponse ) ) {
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $reponse ), true );
		return is_array( $json ) ? $json : null;
	}

	/**
	 * Localise une adresse (France) : le texte entier, puis chaque ligne en partant de la fin
	 * (« Château de la Roche / 12 route des Vignes, 69400 Villefranche »).
	 *
	 * @param string $adresse Adresse libre.
	 * @return array|null { lon, lat, label, score }
	 */
	public static function localiser( $adresse ) {
		$lignes   = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $adresse ) ) ) );
		$essais   = array_unique( array_filter( array_merge( array( implode( ' ', $lignes ) ), array_reverse( $lignes ) ) ) );
		$meilleur = null;
		foreach ( array_slice( $essais, 0, 4 ) as $texte ) {
			$json = self::json( 'https://api-adresse.data.gouv.fr/search/?limit=1&q=' . rawurlencode( mb_substr( $texte, 0, 200 ) ) );
			if ( empty( $json['features'][0]['geometry']['coordinates'] ) ) {
				continue;
			}
			$f        = $json['features'][0];
			$candidat = array(
				'lon'   => (float) $f['geometry']['coordinates'][0],
				'lat'   => (float) $f['geometry']['coordinates'][1],
				'label' => isset( $f['properties']['label'] ) ? sanitize_text_field( $f['properties']['label'] ) : $texte,
				'score' => isset( $f['properties']['score'] ) ? (float) $f['properties']['score'] : 0,
			);
			if ( ! $meilleur || $candidat['score'] > $meilleur['score'] ) {
				$meilleur = $candidat;
			}
			if ( $meilleur['score'] >= 0.6 ) {
				break;
			}
		}
		return $meilleur && $meilleur['score'] >= 0.3 ? $meilleur : null;
	}

	/**
	 * Départ localisé (gardé jusqu'au changement d'adresse).
	 *
	 * @return array|null
	 */
	public static function depart() {
		$p      = self::parametres();
		$depart = get_option( self::OPTION_DEPART );
		if ( is_array( $depart ) && isset( $depart['adresse'] ) && $depart['adresse'] === $p['depart'] ) {
			return $depart;
		}
		$lieu = self::localiser( $p['depart'] );
		if ( ! $lieu ) {
			return null;
		}
		$lieu['adresse'] = $p['depart'];
		update_option( self::OPTION_DEPART, $lieu, false );
		return $lieu;
	}

	/**
	 * Itinéraire en voiture : { km, minutes, source }.
	 *
	 * @param array $a Départ { lon, lat }.
	 * @param array $b Arrivée { lon, lat }.
	 * @return array
	 */
	public static function itineraire( $a, $b ) {
		$de   = $a['lon'] . ',' . $a['lat'];
		$vers = $b['lon'] . ',' . $b['lat'];
		$ign  = self::json( 'https://data.geopf.fr/navigation/itineraire?resource=bdtopo-osrm&profile=car&optimization=fastest&distanceUnit=kilometer&timeUnit=minute&getSteps=false&getBbox=false&start=' . $de . '&end=' . $vers );
		if ( isset( $ign['distance'], $ign['duration'] ) && $ign['distance'] > 0 ) {
			return array( 'km' => (float) $ign['distance'], 'minutes' => (float) $ign['duration'], 'source' => 'IGN' );
		}
		$osrm = self::json( 'https://router.project-osrm.org/route/v1/driving/' . $de . ';' . $vers . '?overview=false' );
		if ( isset( $osrm['routes'][0]['distance'] ) && $osrm['routes'][0]['distance'] > 0 ) {
			return array( 'km' => $osrm['routes'][0]['distance'] / 1000, 'minutes' => $osrm['routes'][0]['duration'] / 60, 'source' => 'OSRM' );
		}
		// Vol d'oiseau × 1,3, à 60 km/h de moyenne.
		$r    = 6371;
		$dlat = deg2rad( $b['lat'] - $a['lat'] );
		$dlon = deg2rad( $b['lon'] - $a['lon'] );
		$h    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $a['lat'] ) ) * cos( deg2rad( $b['lat'] ) ) * sin( $dlon / 2 ) ** 2;
		$km   = 2 * $r * asin( min( 1, sqrt( $h ) ) ) * 1.3;
		return array( 'km' => $km, 'minutes' => $km, 'source' => 'vol' );
	}

	/**
	 * Trajet d'une demande (gardé tant que le départ et le lieu ne changent pas).
	 *
	 * @param int  $id      Demande.
	 * @param bool $calculer Calculer s'il n'est pas connu (sinon null).
	 * @return array|WP_Error|null { km, minutes, source, arrivee, depart, le }
	 */
	public static function trajet( $id, $calculer = true ) {
		$lieu = trim( (string) get_post_meta( $id, '_etr_lieu', true ) );
		if ( '' === $lieu ) {
			return new WP_Error( 'etr_lieu', __( 'Pas de lieu de livraison sur la demande.', 'espace-traiteur' ) );
		}
		$p     = self::parametres();
		$cle   = md5( $p['depart'] . '|' . $lieu );
		$garde = get_post_meta( $id, self::META, true );
		// Gardé ; une approximation à vol d'oiseau (services en panne) est retentée le lendemain.
		if ( is_array( $garde ) && isset( $garde['cle'] ) && $garde['cle'] === $cle && ( 'vol' !== $garde['source'] || time() - (int) $garde['le'] < DAY_IN_SECONDS ) ) {
			return $garde;
		}
		if ( ! $calculer ) {
			return null;
		}
		// Échec récent : pas de nouvel essai avant 30 minutes (la fiche ne doit pas attendre à chaque ouverture).
		$echec = get_transient( 'etr_estimation_echec_' . $id );
		if ( is_array( $echec ) && $echec['cle'] === $cle ) {
			return new WP_Error( $echec['code'], $echec['message'] );
		}
		$depart  = self::depart();
		$arrivee = $depart ? self::localiser( $lieu ) : null;
		if ( ! $depart || ! $arrivee ) {
			$erreur = ! $depart
				? new WP_Error( 'etr_depart', __( 'Adresse de départ introuvable (ou services de cartographie injoignables) : vérifiez-la dans Espace traiteur › Fonctions.', 'espace-traiteur' ) )
				: new WP_Error( 'etr_arrivee', __( 'Lieu de livraison introuvable (ou services de cartographie injoignables). Précisez la commune ou le code postal.', 'espace-traiteur' ) );
			set_transient( 'etr_estimation_echec_' . $id, array( 'cle' => $cle, 'code' => $erreur->get_error_code(), 'message' => $erreur->get_error_message() ), 30 * MINUTE_IN_SECONDS );
			return $erreur;
		}
		$trajet = self::itineraire( $depart, $arrivee ) + array(
			'cle'     => $cle,
			'arrivee' => $arrivee['label'],
			'depart'  => $depart['label'],
			'le'      => time(),
		);
		update_post_meta( $id, self::META, $trajet );
		return $trajet;
	}

	/**
	 * Calcul du coût et du prix conseillé.
	 *
	 * @param array $trajet { km, minutes } (un aller).
	 * @param array $p      Paramètres (défaut : réglages).
	 * @return array
	 */
	public static function chiffrer( $trajet, $p = null, $camions = 1 ) {
		$p          = $p ? $p : self::parametres();
		$camions    = max( 1, (int) $camions );                        // Camions pleins (volume du matériel).
		$km         = 4 * (float) $trajet['km'] * $camions;            // Livraison et reprise : deux allers-retours par camion.
		$heures     = ( 4 * (float) $trajet['minutes'] + 2 * $p['manutention'] ) * $camions / 60;
		$carburant  = $km * $p['conso'] / 100 * $p['gazole'];
		$usage      = $km * $p['usage_km'];
		$personnel  = $heures * $p['personnes'] * $p['taux'];
		$cout       = $carburant + $usage + $personnel + $p['fixes'];
		$prix       = max( $p['minimum'], $cout * ( 1 + $p['marge'] / 100 ) );
		$prix       = ceil( $prix / $p['arrondi'] ) * $p['arrondi'];
		return array(
			'km'        => $km,
			'heures'    => $heures,
			'litres'    => $km * $p['conso'] / 100,
			'carburant' => $carburant,
			'usage'     => $usage,
			'personnel' => $personnel,
			'fixes'     => (float) $p['fixes'],
			'cout'      => $cout,
			'prix'      => (float) $prix,
			'camions'   => $camions,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Affichage (bureau seulement)                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Durée lisible.
	 *
	 * @param float $heures Heures.
	 * @return string
	 */
	private static function duree( $heures ) {
		$minutes = (int) round( $heures * 60 );
		return $minutes >= 60 ? sprintf( '%d h %02d', intdiv( $minutes, 60 ), $minutes % 60 ) : $minutes . ' min';
	}

	/**
	 * Encart d'estimation (HTML).
	 *
	 * @param int  $id     Demande.
	 * @param bool $bouton Bouton « Utiliser ce prix » (formulaire de tarif de l'application).
	 * @return string
	 */
	public static function html( $id, $bouton = false ) {
		$trajet = self::trajet( $id );
		$html   = '<div class="etr-estimation"><p class="etr-estimation__titre">' . esc_html__( 'Estimation de la livraison', 'espace-traiteur' ) . ' <span class="etr-estimation__prive">' . esc_html__( 'non visible par le traiteur', 'espace-traiteur' ) . '</span></p>';
		if ( is_wp_error( $trajet ) ) {
			$html .= '<p class="etr-muted">' . esc_html( $trajet->get_error_message() ) . '</p>';
			if ( $bouton ) {
				$html .= '<p class="etr-estimation__actions"><button type="submit" class="etr-btn etr-btn--discret" form="etr-estimation-recalculer">' . esc_html__( 'Réessayer', 'espace-traiteur' ) . '</button></p>';
			}
			return $html . '</div>';
		}
		$p           = self::parametres();
		$gazole      = self::gazole();
		$p['gazole'] = $gazole['prix'];
		$c           = self::chiffrer( $trajet, $p, (int) apply_filters( 'etr_estimation_camions', 1, $id ) );
		$prix   = function ( $m ) {
			return number_format_i18n( $m, 2 ) . ' €';
		};
		/* translators: 1 : distance, 2 : durée, 3 : lieu trouvé. */
		$html  .= '<p class="etr-estimation__trajet">' . esc_html( sprintf( __( '%1$s km et %2$s par trajet, jusqu’à « %3$s »', 'espace-traiteur' ), number_format_i18n( $trajet['km'], 1 ), self::duree( $trajet['minutes'] / 60 ), $trajet['arrivee'] ) ) . ( 'vol' === $trajet['source'] ? ' <em>' . esc_html__( '(approximation à vol d’oiseau : itinéraire indisponible)', 'espace-traiteur' ) . '</em>' : '' ) . '</p>';
		if ( $c['camions'] > 1 ) {
			/* translators: %s : nombre de camions. */
			$html .= '<p class="etr-estimation__trajet"><strong>' . esc_html( sprintf( __( 'Volume du matériel : %s camions pleins, chaque passage compte autant d’allers-retours.', 'espace-traiteur' ), number_format_i18n( $c['camions'] ) ) ) . '</strong></p>';
		}
		$html  .= '<dl class="etr-estimation__detail">';
		$lignes = array(
			/* translators: 1 : km, 2 : litres, 3 : prix au litre. */
			array( sprintf( __( 'Gazole (%1$s km, %2$s L à %3$s €)', 'espace-traiteur' ), number_format_i18n( $c['km'] ), number_format_i18n( $c['litres'], 1 ), number_format_i18n( $p['gazole'], 2 ) ), $prix( $c['carburant'] ) ),
			array( __( 'Usage du camion', 'espace-traiteur' ), $prix( $c['usage'] ) ),
			/* translators: 1 : durée, 2 : personnes. */
			array( sprintf( _n( 'Personnel (%1$s, %2$s personne)', 'Personnel (%1$s, %2$s personnes)', $p['personnes'], 'espace-traiteur' ), self::duree( $c['heures'] ), number_format_i18n( $p['personnes'] ) ), $prix( $c['personnel'] ) ),
		);
		if ( $c['fixes'] ) {
			$lignes[] = array( __( 'Autres frais', 'espace-traiteur' ), $prix( $c['fixes'] ) );
		}
		$lignes[] = array( __( 'Coût de revient', 'espace-traiteur' ), $prix( $c['cout'] ) );
		foreach ( $lignes as $ligne ) {
			$html .= '<div><dt>' . esc_html( $ligne[0] ) . '</dt><dd>' . esc_html( $ligne[1] ) . '</dd></div>';
		}
		/* translators: %s : marge. */
		$html .= '<div class="etr-estimation__prix"><dt>' . esc_html( sprintf( __( 'Prix conseillé (marge %s %%)', 'espace-traiteur' ), number_format_i18n( $p['marge'] ) ) ) . '</dt><dd>' . esc_html( $prix( $c['prix'] ) ) . '</dd></div></dl>';
		$html .= '<p class="etr-muted etr-estimation__note">' . esc_html( $gazole['texte'] ) . ' ' . esc_html__( 'Livraison puis reprise : deux allers-retours depuis le dépôt. Indication à ajuster (accès, étages, horaires, péages).', 'espace-traiteur' ) . '</p>';
		if ( $bouton ) {
			$html .= '<p class="etr-estimation__actions"><button type="button" class="etr-btn etr-btn--discret" data-remplir-livraison="' . esc_attr( str_replace( '.', ',', (string) $c['prix'] ) ) . '">' . esc_html__( 'Utiliser ce prix', 'espace-traiteur' ) . '</button>';
			$html .= '<button type="submit" class="etr-btn etr-btn--discret" form="etr-estimation-recalculer">' . esc_html__( 'Recalculer', 'espace-traiteur' ) . '</button></p>';
		}
		return $html . '</div>';
	}

	/**
	 * Dans le formulaire « Tarification » de la fiche (bureau).
	 *
	 * @param int $id Demande.
	 */
	public static function encart_gestion( $id ) {
		if ( ! ETR_Modules::actif( 'estimation_livraison' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		echo self::html( $id, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans html().
	}

	/**
	 * Formulaire « Recalculer » (hors du formulaire de tarif, relié par l'attribut form).
	 *
	 * @param int $id Demande.
	 */
	public static function formulaire_recalculer( $id ) {
		if ( ! ETR_Modules::actif( 'estimation_livraison' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		echo '<form id="etr-estimation-recalculer" method="post" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) ) ) . '" hidden>';
		wp_nonce_field( 'etr_estimation_' . $id, 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="estimation"><input type="hidden" name="demande_id" value="' . (int) $id . '"></form>';
	}

	/**
	 * Recalculer (bureau).
	 *
	 * @param string $action Action.
	 */
	public static function action_recalculer( $action ) {
		if ( 'estimation' !== $action || ! ETR_Modules::actif( 'estimation_livraison' ) ) {
			return;
		}
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		check_admin_referer( 'etr_estimation_' . $id, 'etr_nonce' );
		if ( ! $id || ! ETR_Gestion::demande_visible( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		delete_post_meta( $id, self::META );
		delete_transient( 'etr_estimation_echec_' . $id );
		delete_transient( 'etr_gazole_marche' );
		delete_option( self::OPTION_DEPART );
		self::trajet( $id );
		wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => 'fait' ) ) );
		exit;
	}

	/**
	 * Boîte dans l'administration WordPress (fiche d'une demande).
	 */
	public static function metabox() {
		if ( ! ETR_Modules::actif( 'estimation_livraison' ) || ! current_user_can( 'gerer_espace_traiteur' ) ) {
			return;
		}
		add_meta_box(
			'etr_mb_estimation',
			__( 'Estimation de la livraison', 'espace-traiteur' ),
			function ( $post ) {
				echo self::html( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans html().
			},
			'etr_demande',
			'side',
			'default'
		);
	}

	/**
	 * Calcul à l'avance, juste après la demande (tâche planifiée).
	 *
	 * @param int $id Demande.
	 */
	public static function planifier( $id ) {
		if ( ETR_Modules::actif( 'estimation_livraison' ) && ! wp_next_scheduled( 'etr_estimer_trajet', array( (int) $id ) ) ) {
			wp_schedule_single_event( time(), 'etr_estimer_trajet', array( (int) $id ) );
		}
	}
}
