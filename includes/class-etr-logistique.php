<?php
/**
 * Logistique (fonctions activables) :
 * - volume du matériel et capacité du camion (« volume ») ;
 * - créneaux de livraison et de reprise, avec capacité par créneau (« creneaux ») ;
 * - ordre de tournée optimisé (« tournee_optimisee »).
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logistique.
 */
class ETR_Logistique {

	const OPTION      = 'etr_logistique';
	const META_VOLUME = '_etr_volume';      // m³ par unité (produit).
	const META_COORD  = '_etr_coordonnees'; // Lieu de livraison localisé (demande).

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
		add_action( 'etr_fonctions_reglages', array( __CLASS__, 'champs_reglages' ) );

		// Volume.
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'champ_produit' ), 25 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'enregistrer_produit' ) );
		add_action( 'etr_gestion_fiche_cote', array( __CLASS__, 'panneau_volume' ), 8 );
		add_filter( 'etr_estimation_camions', array( __CLASS__, 'camions_estimation' ), 10, 2 );

		// Créneaux.
		add_filter( 'etr_champs_demande', array( __CLASS__, 'champs_creneaux' ) );
		add_filter( 'etr_demande_validation', array( __CLASS__, 'valider_creneaux' ), 10, 3 );

		// Tournée : volume du jour, créneau sur chaque étape, optimisation.
		add_action( 'etr_tournee_haut', array( __CLASS__, 'haut_tournee' ), 10, 3 );
		add_action( 'etr_tournee_carte_infos', array( __CLASS__, 'infos_carte' ), 10, 2 );
		add_action( 'etr_gestion_action', array( __CLASS__, 'action_optimiser' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Réglages                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Paramètres par défaut.
	 *
	 * @return array
	 */
	public static function defauts() {
		return array(
			'capacite'    => 20.0,  // m³ (camion 20 m³ avec hayon).
			'remplissage' => 85.0,  // % du volume réellement utilisable (calage, hayon).
			'creneaux'    => "Matin (8 h – 12 h)\nAprès-midi (13 h 30 – 18 h)",
			'par_creneau' => 0,     // Étapes au plus par créneau et par jour (0 = sans limite).
		);
	}

	/**
	 * Paramètres.
	 *
	 * @return array
	 */
	public static function parametres() {
		$p = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $p ) ? $p : array(), self::defauts() );
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
					$avant  = self::parametres();
					$saisie = is_array( $saisie ) ? $saisie : array();
					$propre = $avant;
					foreach ( array( 'capacite', 'remplissage' ) as $cle ) {
						if ( isset( $saisie[ $cle ] ) ) {
							$valeur         = etr_lire_nombre( (string) $saisie[ $cle ] );
							$propre[ $cle ] = $valeur ? (float) $valeur : self::defauts()[ $cle ];
						}
					}
					$propre['remplissage'] = min( 100, max( 10, $propre['remplissage'] ) );
					if ( isset( $saisie['creneaux'] ) ) {
						$lignes             = array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $saisie['creneaux'] ) ) ) );
						$propre['creneaux'] = implode( "\n", array_slice( array_unique( $lignes ), 0, 8 ) );
					}
					if ( isset( $saisie['par_creneau'] ) ) {
						$propre['par_creneau'] = absint( $saisie['par_creneau'] );
					}
					return $propre;
				},
			)
		);
	}

	/**
	 * Champs sous les fonctions, page Fonctions.
	 *
	 * @param string $cle Fonction.
	 */
	public static function champs_reglages( $cle ) {
		$p   = self::parametres();
		$nom = function ( $champ ) {
			return esc_attr( self::OPTION . '[' . $champ . ']' );
		};
		if ( 'volume' === $cle ) {
			echo '<p><label>' . esc_html__( 'Volume du camion', 'espace-traiteur' ) . ' <input type="text" inputmode="decimal" class="small-text" name="' . $nom( 'capacite' ) . '" value="' . esc_attr( str_replace( '.', ',', (string) $p['capacite'] ) ) . '"> m³</label> &nbsp; '; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans $nom.
			echo '<label>' . esc_html__( 'Remplissage utile', 'espace-traiteur' ) . ' <input type="text" inputmode="decimal" class="small-text" name="' . $nom( 'remplissage' ) . '" value="' . esc_attr( str_replace( '.', ',', (string) $p['remplissage'] ) ) . '"> %</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p class="description">' . esc_html__( 'Volume de chaque produit : fiche produit WooCommerce › Inventaire › « Volume par unité (m³) ». Exemple : table ronde 1,80 m pliée ≈ 0,25 m³, carton de 24 verres ≈ 0,03 m³.', 'espace-traiteur' ) . '</p>';
		} elseif ( 'creneaux' === $cle ) {
			echo '<p><label>' . esc_html__( 'Créneaux proposés (un par ligne)', 'espace-traiteur' ) . '<br><textarea class="large-text" rows="3" name="' . $nom( 'creneaux' ) . '">' . esc_textarea( $p['creneaux'] ) . '</textarea></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><label>' . esc_html__( 'Livraisons et reprises au plus par créneau et par jour', 'espace-traiteur' ) . ' <input type="number" min="0" class="small-text" name="' . $nom( 'par_creneau' ) . '" value="' . esc_attr( (string) $p['par_creneau'] ) . '"></label> <span class="description">' . esc_html__( '0 = sans limite', 'espace-traiteur' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ------------------------------------------------------------------ */
	/* Volume                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Volume par unité d'un article (m³), ou null.
	 *
	 * @param int $article_id Article.
	 * @return float|null
	 */
	public static function volume_article( $article_id ) {
		$v = get_post_meta( $article_id, self::META_VOLUME, true );
		return '' === $v || null === $v ? null : (float) $v;
	}

	/**
	 * Volume d'une demande.
	 *
	 * @param int $id Demande.
	 * @return array { m3, sans_volume (articles), camions, taux (% d'un camion utile) }
	 */
	public static function volume( $id ) {
		$p      = self::parametres();
		$total  = 0.0;
		$sans   = array();
		foreach ( etr_get_lignes( $id ) as $ligne ) {
			$v = self::volume_article( (int) $ligne['article_id'] );
			if ( null === $v ) {
				$sans[] = $ligne['titre'];
			} else {
				$total += $v * (int) $ligne['quantite'];
			}
		}
		$utile = max( 0.1, $p['capacite'] * $p['remplissage'] / 100 );
		return array(
			'm3'          => $total,
			'sans_volume' => $sans,
			'camions'     => max( 1, (int) ceil( $total / $utile - 1e-9 ) ),
			'taux'        => 100 * $total / $utile,
		);
	}

	/**
	 * Champ « Volume par unité » (onglet Inventaire des produits).
	 */
	public static function champ_produit() {
		if ( ! ETR_Modules::actif( 'volume' ) || ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_VOLUME,
				'label'       => __( 'Volume par unité (m³)', 'espace-traiteur' ),
				'description' => __( 'Espace traiteur : place occupée dans le camion (rangé, plié ou en caisse).', 'espace-traiteur' ),
				'desc_tip'    => true,
				'data_type'   => 'decimal',
			)
		);
	}

	/**
	 * Enregistre le volume.
	 *
	 * @param WC_Product $produit Produit.
	 */
	public static function enregistrer_produit( $produit ) {
		if ( ! ETR_Modules::actif( 'volume' ) || ! isset( $_POST[ self::META_VOLUME ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- formulaire produit WooCommerce.
			return;
		}
		$v = etr_lire_nombre( sanitize_text_field( wp_unslash( $_POST[ self::META_VOLUME ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( null === $v ) {
			$produit->delete_meta_data( self::META_VOLUME );
		} else {
			$produit->update_meta_data( self::META_VOLUME, round( $v, 4 ) );
		}
	}

	/**
	 * Panneau « Volume » de la fiche (bureau).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau_volume( $id ) {
		if ( ! ETR_Modules::actif( 'volume' ) || ! etr_get_lignes( $id ) ) {
			return;
		}
		$v    = self::volume( $id );
		$p    = self::parametres();
		$taux = min( 100, $v['taux'] );
		echo '<section class="etr-panneau etr-volume"><h2 class="etr-panneau__titre">' . esc_html__( 'Volume du matériel', 'espace-traiteur' ) . '</h2>';
		/* translators: 1 : volume, 2 : pourcentage, 3 : capacité. */
		echo '<p>' . esc_html( sprintf( __( '%1$s m³, soit %2$s %% d’un camion de %3$s m³', 'espace-traiteur' ), number_format_i18n( $v['m3'], 2 ), number_format_i18n( $v['taux'] ), number_format_i18n( $p['capacite'] ) ) ) . '</p>';
		echo '<div class="etr-volume__jauge" aria-hidden="true"><span style="width:' . esc_attr( number_format( $taux, 1, '.', '' ) ) . '%"' . ( $v['camions'] > 1 ? ' class="est-plein"' : '' ) . '></span></div>';
		if ( $v['camions'] > 1 ) {
			/* translators: %s : nombre de trajets. */
			echo '<p class="etr-notice etr-notice--erreur">' . esc_html( sprintf( __( 'Ne tient pas en un camion : %s trajets à prévoir à la livraison et à la reprise.', 'espace-traiteur' ), number_format_i18n( $v['camions'] ) ) ) . '</p>';
		}
		if ( $v['sans_volume'] ) {
			/* translators: %s : articles. */
			echo '<p class="etr-muted">' . esc_html( sprintf( _n( 'Sans volume renseigné : %s.', 'Sans volume renseigné : %s.', count( $v['sans_volume'] ), 'espace-traiteur' ), implode( ', ', array_slice( $v['sans_volume'], 0, 5 ) ) . ( count( $v['sans_volume'] ) > 5 ? '…' : '' ) ) ) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * Estimation de la livraison : camions pleins nécessaires.
	 *
	 * @param int $camions Camions.
	 * @param int $id      Demande.
	 * @return int
	 */
	public static function camions_estimation( $camions, $id ) {
		return ETR_Modules::actif( 'volume' ) ? self::volume( $id )['camions'] : $camions;
	}

	/* ------------------------------------------------------------------ */
	/* Créneaux                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Créneaux proposés.
	 *
	 * @return string[]
	 */
	public static function creneaux() {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) self::parametres()['creneaux'] ) ) ) );
	}

	/**
	 * Champs « Créneau de livraison / de reprise » du formulaire.
	 *
	 * @param array $champs Champs.
	 * @return array
	 */
	public static function champs_creneaux( $champs ) {
		if ( ! ETR_Modules::actif( 'creneaux' ) || ! self::creneaux() ) {
			return $champs;
		}
		$nouveaux = array();
		foreach ( $champs as $cle => $champ ) {
			$nouveaux[ $cle ] = $champ;
			if ( 'date_livraison' === $cle || 'date_reprise' === $cle ) {
				$nouveaux[ 'date_livraison' === $cle ? 'creneau_livraison' : 'creneau_reprise' ] = array(
					'label'   => 'date_livraison' === $cle ? __( 'Créneau de livraison', 'espace-traiteur' ) : __( 'Créneau de reprise', 'espace-traiteur' ),
					'type'    => 'select',
					'options' => self::creneaux(),
				);
			}
		}
		return $nouveaux;
	}

	/**
	 * Étapes déjà prévues un jour sur un créneau (hors une demande).
	 *
	 * @param string $jour    AAAA-MM-JJ.
	 * @param string $creneau Créneau.
	 * @param int    $exclure Demande ignorée.
	 * @return int
	 */
	public static function charge( $jour, $creneau, $exclure = 0 ) {
		$n = 0;
		foreach ( ETR_Operations::tournee( $jour ) as $type => $ids ) {
			$etape = 'livraisons' === $type ? 'livraison' : 'reprise';
			foreach ( $ids as $id ) {
				if ( (int) $id !== (int) $exclure && get_post_meta( $id, '_etr_creneau_' . $etape, true ) === $creneau ) {
					++$n;
				}
			}
		}
		return $n;
	}

	/**
	 * Formulaire du traiteur : créneau complet ce jour-là ?
	 *
	 * @param string[] $erreurs Erreurs.
	 * @param array    $donnees Saisie.
	 * @param int      $id      Demande modifiée (0 : nouvelle).
	 * @return string[]
	 */
	public static function valider_creneaux( $erreurs, $donnees, $id = 0 ) {
		$max = (int) self::parametres()['par_creneau'];
		if ( ! ETR_Modules::actif( 'creneaux' ) || $max < 1 ) {
			return $erreurs;
		}
		foreach ( array( 'livraison', 'reprise' ) as $etape ) {
			$creneau = isset( $donnees[ 'creneau_' . $etape ] ) ? $donnees[ 'creneau_' . $etape ] : '';
			$jour    = ! empty( $donnees[ 'date_' . $etape ] ) ? $donnees[ 'date_' . $etape ] : ( isset( $donnees['date_evenement'] ) ? $donnees['date_evenement'] : '' );
			if ( '' !== $creneau && etr_date_valide( $jour ) && self::charge( $jour, $creneau, $id ) >= $max ) {
				/* translators: 1 : créneau, 2 : date. */
				$erreurs[] = sprintf( __( 'Le créneau « %1$s » du %2$s est complet : choisissez-en un autre ou laissez « Indifférent ».', 'espace-traiteur' ), $creneau, etr_format_date( $jour ) );
			}
		}
		return $erreurs;
	}

	/* ------------------------------------------------------------------ */
	/* Tournée                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * En tête de la tournée : volume à charger, optimisation de l'ordre (bureau).
	 *
	 * @param string $jour    Jour.
	 * @param array  $tournee { livraisons, reprises }.
	 * @param bool   $bureau  Bureau.
	 */
	public static function haut_tournee( $jour, $tournee, $bureau ) {
		$p = self::parametres();
		if ( ETR_Modules::actif( 'volume' ) && $tournee['livraisons'] ) {
			$m3 = 0.0;
			foreach ( $tournee['livraisons'] as $id ) {
				$m3 += self::volume( $id )['m3'];
			}
			$utile   = $p['capacite'] * $p['remplissage'] / 100;
			$camions = max( 1, (int) ceil( $m3 / max( 0.1, $utile ) - 1e-9 ) );
			/* translators: 1 : volume, 2 : camions. */
			echo '<p class="etr-tournee__volume' . ( $camions > 1 ? ' est-plein' : '' ) . '">' . esc_html( sprintf( _n( 'À charger : %1$s m³ (%2$s camion)', 'À charger : %1$s m³ (%2$s camions)', $camions, 'espace-traiteur' ), number_format_i18n( $m3, 1 ), number_format_i18n( $camions ) ) ) . '</p>';
		}
		if ( $bureau && ETR_Modules::actif( 'tournee_optimisee' ) && count( $tournee['livraisons'] ) + count( $tournee['reprises'] ) > 1 ) {
			echo '<form method="post" class="etr-tournee__optimiser" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'tournee', 'jour' => $jour ) ) ) . '" data-confirmer="' . esc_attr__( 'Recalculer l’ordre de passage pour faire le moins de kilomètres (en respectant les créneaux) ?', 'espace-traiteur' ) . '">';
			wp_nonce_field( 'etr_optimiser_' . $jour, 'etr_nonce' );
			echo '<input type="hidden" name="etr_gestion_action" value="optimiser"><input type="hidden" name="jour" value="' . esc_attr( $jour ) . '">';
			echo '<button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Optimiser l’ordre de passage', 'espace-traiteur' ) . '</button></form>';
		}
	}

	/**
	 * Sur chaque étape : créneau et volume.
	 *
	 * @param int    $id    Demande.
	 * @param string $etape livraison | reprise.
	 */
	public static function infos_carte( $id, $etape ) {
		$infos   = array();
		$creneau = ETR_Modules::actif( 'creneaux' ) ? (string) get_post_meta( $id, '_etr_creneau_' . $etape, true ) : '';
		if ( '' !== $creneau ) {
			$infos[] = $creneau;
		}
		if ( ETR_Modules::actif( 'volume' ) ) {
			$v = self::volume( $id );
			if ( $v['m3'] > 0 ) {
				/* translators: %s : volume. */
				$infos[] = sprintf( __( '%s m³', 'espace-traiteur' ), number_format_i18n( $v['m3'], 1 ) );
			}
		}
		if ( $infos ) {
			echo '<p class="etr-arret__ligne etr-arret__creneau">' . ETR_Frontend::icone( 'calendrier' ) . '<span>' . esc_html( implode( ' · ', $infos ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
		}
	}

	/**
	 * Coordonnées du lieu d'une demande (gardées tant que le lieu ne change pas).
	 *
	 * @param int $id Demande.
	 * @return array|null { lon, lat }
	 */
	public static function coordonnees( $id ) {
		$lieu = trim( (string) get_post_meta( $id, '_etr_lieu', true ) );
		if ( '' === $lieu ) {
			return null;
		}
		$garde = get_post_meta( $id, self::META_COORD, true );
		if ( is_array( $garde ) && isset( $garde['cle'] ) && md5( $lieu ) === $garde['cle'] ) {
			return $garde;
		}
		$point = ETR_Estimation::localiser( $lieu );
		if ( ! $point ) {
			return null;
		}
		$point['cle'] = md5( $lieu );
		update_post_meta( $id, self::META_COORD, $point );
		return $point;
	}

	/**
	 * Distance à vol d'oiseau (km).
	 *
	 * @param array $a Point.
	 * @param array $b Point.
	 * @return float
	 */
	public static function distance( $a, $b ) {
		$dlat = deg2rad( $b['lat'] - $a['lat'] );
		$dlon = deg2rad( $b['lon'] - $a['lon'] );
		$h    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $a['lat'] ) ) * cos( deg2rad( $b['lat'] ) ) * sin( $dlon / 2 ) ** 2;
		return 12742 * asin( min( 1, sqrt( $h ) ) );
	}

	/**
	 * Ordre court : plus proche voisin depuis le point de départ, puis améliorations 2-opt.
	 *
	 * @param array      $depart Point de départ { lon, lat } (dépôt, ou dernier arrêt du créneau précédent).
	 * @param array      $points clé => { lon, lat }.
	 * @param array|null $fin    Point d'arrivée (retour au dépôt) ; null : parcours ouvert (la suite part du dernier arrêt).
	 * @return array Clés dans l'ordre.
	 */
	public static function ordre_court( $depart, $points, $fin = null ) {
		$restants = $points;
		$ordre    = array();
		$courant  = $depart;
		while ( $restants ) {
			$meilleur = null;
			foreach ( $restants as $cle => $point ) {
				$d = self::distance( $courant, $point );
				if ( null === $meilleur || $d < $meilleur[1] ) {
					$meilleur = array( $cle, $d );
				}
			}
			$ordre[] = $meilleur[0];
			$courant = $restants[ $meilleur[0] ];
			unset( $restants[ $meilleur[0] ] );
		}
		// 2-opt : inverse un morceau du parcours tant que cela le raccourcit. Avant le premier arrêt :
		// le point de départ ; après le dernier : l'arrivée (ou rien, parcours ouvert).
		$n     = count( $ordre );
		$point = function ( $ordre, $i ) use ( $points, $depart, $fin, $n ) {
			if ( $i < 0 ) {
				return $depart;
			}
			return $i >= $n ? $fin : $points[ $ordre[ $i ] ];
		};
		$d = function ( $a, $b ) {
			return null === $a || null === $b ? 0.0 : self::distance( $a, $b );
		};
		$ameliore   = true;
		$iterations = 0;
		while ( $ameliore && $iterations++ < 50 ) {
			$ameliore = false;
			for ( $i = 0; $i < $n - 1; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$avant = $d( $point( $ordre, $i - 1 ), $point( $ordre, $i ) ) + $d( $point( $ordre, $j ), $point( $ordre, $j + 1 ) );
					$apres = $d( $point( $ordre, $i - 1 ), $point( $ordre, $j ) ) + $d( $point( $ordre, $i ), $point( $ordre, $j + 1 ) );
					if ( $apres + 1e-9 < $avant ) {
						$ordre    = array_merge( array_slice( $ordre, 0, $i ), array_reverse( array_slice( $ordre, $i, $j - $i + 1 ) ), array_slice( $ordre, $j + 1 ) );
						$ameliore = true;
					}
				}
			}
		}
		return $ordre;
	}

	/**
	 * Optimise l'ordre des livraisons puis des reprises d'un jour : par créneau (dans l'ordre des
	 * créneaux), puis au plus court depuis le dépôt. Les lieux introuvables passent en dernier.
	 *
	 * @param string $jour Jour.
	 * @return int Étapes ordonnées.
	 */
	public static function optimiser( $jour ) {
		$depart = ETR_Estimation::depart();
		if ( ! $depart ) {
			return 0;
		}
		$creneaux = array_flip( self::creneaux() );
		$total    = 0;
		foreach ( ETR_Operations::tournee( $jour ) as $type => $ids ) {
			$etape   = 'livraisons' === $type ? 'livraison' : 'reprise';
			$groupes = array();
			foreach ( $ids as $id ) {
				$c   = ETR_Modules::actif( 'creneaux' ) ? (string) get_post_meta( $id, '_etr_creneau_' . $etape, true ) : '';
				$rang = isset( $creneaux[ $c ] ) ? $creneaux[ $c ] : count( $creneaux ); // Sans créneau : après.
				$groupes[ $rang ][] = $id;
			}
			ksort( $groupes );
			$ordre   = array();
			$courant = $depart;
			$rangs   = array_keys( $groupes );
			foreach ( $groupes as $rang => $groupe ) {
				$dernier = end( $rangs ) === $rang; // Le dernier créneau revient au dépôt ; les autres continuent.
				$points = array();
				$sans   = array();
				foreach ( $groupe as $id ) {
					$c = self::coordonnees( $id );
					if ( $c ) {
						$points[ $id ] = $c;
					} else {
						$sans[] = $id;
					}
				}
				$suite = $points ? self::ordre_court( $courant, $points, $dernier ? $depart : null ) : array();
				if ( $suite ) {
					$courant = $points[ end( $suite ) ];
				}
				$ordre = array_merge( $ordre, $suite, $sans );
			}
			ETR_Operations::ordonner( $etape, $ordre );
			$total += count( $ordre );
		}
		return $total;
	}

	/**
	 * Action « Optimiser l'ordre de passage » (bureau).
	 *
	 * @param string $action Action.
	 */
	public static function action_optimiser( $action ) {
		if ( 'optimiser' !== $action || ! ETR_Modules::actif( 'tournee_optimisee' ) ) {
			return;
		}
		$jour = isset( $_POST['jour'] ) ? sanitize_text_field( wp_unslash( $_POST['jour'] ) ) : '';
		check_admin_referer( 'etr_optimiser_' . $jour, 'etr_nonce' );
		if ( ! etr_date_valide( $jour ) ) {
			wp_die( esc_html__( 'Jour invalide.', 'espace-traiteur' ), '', array( 'response' => 400 ) );
		}
		$n = self::optimiser( $jour );
		wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'tournee', 'jour' => $jour, 'etr_msg' => $n ? 'ordre_optimise' : 'ordre_impossible' ) ) );
		exit;
	}
}
