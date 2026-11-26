<?php
/**
 * Parcours d'une demande dans l'application de l'équipe : sept étapes (reçue, validée, préparée,
 * chargée, livrée, reprise, clôturée), l'étape en cours, et ce que la personne connectée doit
 * faire maintenant selon son rôle (bureau, préparateur, livreur) — ou qui l'on attend.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parcours d'une demande.
 */
class ETR_Parcours {

	/**
	 * Étapes calculées pendant la requête.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'oublier' ), 10, 2 );
		}
	}

	/**
	 * Libellés des étapes, dans l'ordre.
	 *
	 * @return string[]
	 */
	public static function libelles() {
		return array(
			'recue'    => __( 'Reçue', 'espace-traiteur' ),
			'validee'  => __( 'Validée', 'espace-traiteur' ),
			'preparee' => __( 'Préparée', 'espace-traiteur' ),
			'chargee'  => __( 'Chargée', 'espace-traiteur' ),
			'livree'   => __( 'Livrée', 'espace-traiteur' ),
			'reprise'  => __( 'Reprise', 'espace-traiteur' ),
			'cloturee' => __( 'Clôturée', 'espace-traiteur' ),
		);
	}

	/**
	 * Étapes d'une demande : clé => { libelle, fait, detail }.
	 *
	 * @param int $id Demande.
	 * @return array
	 */
	public static function etapes( $id ) {
		$id = (int) $id;
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		$statut    = etr_get_statut( $id );
		$apres     = in_array( $statut, array( 'livree', 'cloturee' ), true );
		$prepa     = ETR_Operations::preparation( $id );
		$bilan     = ETR_Operations::bilan_colis( $id );
		$livraison = ETR_Operations::livraison( $id );
		$reprise   = ETR_Operations::reprise( $id );
		list( $jour_liv, $jour_rep ) = ETR_Operations::jours( $id );
		$l = self::libelles();

		$e = array();
		/* translators: %s : date. */
		$e['recue'] = array( $l['recue'], true, sprintf( __( 'Le %s', 'espace-traiteur' ), get_the_date( 'j M', $id ) ) );
		$e['validee'] = array( $l['validee'], in_array( $statut, array( 'validee', 'livree', 'cloturee' ), true ), 'en_attente' === $statut ? __( 'À étudier par le bureau', 'espace-traiteur' ) : '' );
		$e['preparee'] = array(
			$l['preparee'],
			$prepa['prete'] || $apres,
			$prepa['prete']
				/* translators: %s : date. */
				? sprintf( __( 'Prête le %s', 'espace-traiteur' ), wp_date( 'j M, H:i', (int) $prepa['le'] ) )
				/* translators: 1 : préparés, 2 : total. */
				: sprintf( __( '%1$s / %2$s articles', 'espace-traiteur' ), number_format_i18n( $prepa['prepare'] ), number_format_i18n( $prepa['total'] ) ),
		);
		$e['chargee'] = array(
			$l['chargee'],
			// Rien à scanner (prête sans colis) : le chargement ne bloque pas.
			(bool) $livraison || $apres || ( $bilan['colis'] && $bilan['sortis'] >= $bilan['colis'] ) || ( $prepa['prete'] && ! $bilan['colis'] ),
			$bilan['colis']
				/* translators: 1 : chargés, 2 : colis. */
				? sprintf( __( '%1$s / %2$s colis scannés', 'espace-traiteur' ), number_format_i18n( $bilan['sortis'] ), number_format_i18n( $bilan['colis'] ) )
				: __( 'Colis comptés à la préparation', 'espace-traiteur' ),
		);
		$e['livree'] = array(
			$l['livree'],
			(bool) $livraison || $apres,
			$livraison
				/* translators: 1 : date, 2 : personne. */
				? sprintf( __( 'Le %1$s, signée par %2$s', 'espace-traiteur' ), wp_date( 'j M, H:i', (int) $livraison['le'] ), $livraison['signataire'] )
				/* translators: %s : date. */
				: ( $jour_liv ? sprintf( __( 'Prévue le %s', 'espace-traiteur' ), etr_format_date( $jour_liv ) ) : '' ),
		);
		$ecarts       = $reprise ? ETR_Operations::texte_ecarts( (int) $reprise['manquants'], (int) $reprise['casses'] ) : '';
		$e['reprise'] = array(
			$l['reprise'],
			(bool) $reprise || 'cloturee' === $statut,
			$reprise
				/* translators: 1 : date, 2 : écarts ou « complète ». */
				? sprintf( __( 'Le %1$s · %2$s', 'espace-traiteur' ), wp_date( 'j M', (int) $reprise['le'] ), $ecarts ? $ecarts : __( 'complète', 'espace-traiteur' ) )
				/* translators: %s : date. */
				: ( $jour_rep ? sprintf( __( 'Prévue le %s', 'espace-traiteur' ), etr_format_date( $jour_rep ) ) : '' ),
		);
		$e['cloturee'] = array( $l['cloturee'], 'cloturee' === $statut, 'cloturee' === $statut ? __( 'Dossier terminé', 'espace-traiteur' ) : '' );

		self::$cache[ $id ] = $e;
		return $e;
	}

	/**
	 * Une demande change : ses étapes sont recalculées.
	 *
	 * @param int $meta_id Métadonnée.
	 * @param int $post_id Demande.
	 */
	public static function oublier( $meta_id, $post_id = 0 ) {
		unset( self::$cache[ (int) $post_id ] );
	}

	/**
	 * Étape en cours (la première pas encore faite), '' si tout est fait ou la demande close.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function courante( $id ) {
		if ( in_array( etr_get_statut( $id ), array( 'refusee', 'annulee' ), true ) ) {
			return '';
		}
		foreach ( self::etapes( $id ) as $cle => $e ) {
			if ( ! $e[1] ) {
				return $cle;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Ce que la personne connectée doit faire                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Action du moment : { a_moi, sur, titre, texte, boutons[] (html) }.
	 *
	 * @param int $id Demande.
	 * @return array
	 */
	public static function action( $id ) {
		$statut  = etr_get_statut( $id );
		$etape   = self::courante( $id );
		$bureau  = ETR_Equipe::bureau();
		$prep    = ETR_Equipe::preparateur();
		$livreur = ETR_Equipe::livreur();
		$prepa   = ETR_Operations::preparation( $id );
		$bilan   = ETR_Operations::bilan_colis( $id );
		$reprise = ETR_Operations::reprise( $id );
		list( $jour_liv, $jour_rep ) = ETR_Operations::jours( $id );
		$creneau = (string) get_post_meta( $id, '_etr_creneau_livraison', true );
		$quand   = $jour_liv ? self::quand( $jour_liv ) . ( $creneau ? ' · ' . $creneau : '' ) : '';
		$a       = array(
			'a_moi'   => false,
			'sur'     => '',
			'titre'   => '',
			'texte'   => '',
			'boutons' => array(),
		);
		$attente = function ( $qui, $titre, $texte = '' ) use ( &$a ) {
			/* translators: %s : rôle attendu. */
			$a['sur']   = sprintf( __( 'En attente : %s', 'espace-traiteur' ), $qui );
			$a['titre'] = $titre;
			$a['texte'] = $texte;
		};
		$moi = function ( $titre, $texte = '' ) use ( &$a ) {
			$a['a_moi'] = true;
			$a['sur']   = __( 'À vous de jouer', 'espace-traiteur' );
			$a['titre'] = $titre;
			$a['texte'] = $texte;
		};

		if ( 'refusee' === $statut || 'annulee' === $statut ) {
			$a['sur']   = __( 'Terminé', 'espace-traiteur' );
			$a['titre'] = 'refusee' === $statut ? __( 'Demande refusée', 'espace-traiteur' ) : __( 'Demande annulée', 'espace-traiteur' );
			$a['texte'] = __( 'Le matériel est libéré.', 'espace-traiteur' );
			return $a;
		}
		switch ( $etape ) {
			case 'validee':
				if ( $bureau ) {
					$moi( __( 'Valider ou refuser la demande', 'espace-traiteur' ), __( 'Vérifiez le matériel et les dates (onglet Matériel), puis décidez. Le traiteur est prévenu par e-mail.', 'espace-traiteur' ) );
					$a['boutons'][] = self::bouton_decision( $id, 'validee', __( 'Valider la demande', 'espace-traiteur' ), true );
					$a['boutons'][] = self::bouton_refus( $id );
				} else {
					$attente( __( 'le bureau', 'espace-traiteur' ), __( 'Demande pas encore validée', 'espace-traiteur' ) );
				}
				break;
			case 'preparee':
				/* translators: 1 : préparés, 2 : total, 3 : date de livraison. */
				$texte = sprintf( __( '%1$s / %2$s articles prêts · livraison %3$s', 'espace-traiteur' ), number_format_i18n( $prepa['prepare'] ), number_format_i18n( $prepa['total'] ), $quand );
				if ( $prep ) {
					$moi( $prepa['prepare'] ? __( 'Continuer la préparation', 'espace-traiteur' ) : __( 'Préparer la commande', 'espace-traiteur' ), $texte );
					$a['boutons'][] = self::lien( self::url( array( 'vue' => 'preparer', 'id' => $id ) ), $prepa['prepare'] ? __( 'Continuer la préparation', 'espace-traiteur' ) : __( 'Commencer la préparation', 'espace-traiteur' ), true, 'coche' );
				} else {
					$attente( __( 'la préparation', 'espace-traiteur' ), __( 'Commande pas encore prête', 'espace-traiteur' ), $texte );
				}
				if ( $bureau ) {
					// Le bureau peut faire partir une commande incomplète, en connaissance de cause.
					$a['boutons'][] = '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'livrer', 'id' => $id ) ) ) . '" data-confirmer="' . esc_attr__( 'La commande n’est pas marquée prête. Livrer quand même ?', 'espace-traiteur' ) . '">' . esc_html__( 'Livrer quand même', 'espace-traiteur' ) . '</a>';
				}
				break;
			case 'chargee':
				/* translators: 1 : chargés, 2 : colis, 3 : date de livraison. */
				$texte = sprintf( __( '%1$s / %2$s colis scannés · livraison %3$s', 'espace-traiteur' ), number_format_i18n( $bilan['sortis'] ), number_format_i18n( $bilan['colis'] ), $quand );
				if ( $livreur ) {
					$moi( __( 'Charger les colis dans le camion', 'espace-traiteur' ), $texte );
					$a['boutons'][] = self::lien( self::url( array( 'vue' => 'scanner', 'mode' => 'sortie', 'd' => $id ) ), __( 'Scanner le chargement', 'espace-traiteur' ), true, 'scan' );
					$a['boutons'][] = self::lien( self::url( array( 'vue' => 'livrer', 'id' => $id ) ), __( 'Passer à la livraison', 'espace-traiteur' ), false, 'camion' );
				} else {
					$attente( __( 'le livreur', 'espace-traiteur' ), __( 'Commande prête, à charger', 'espace-traiteur' ), $texte );
				}
				break;
			case 'livree':
				if ( $livreur ) {
					$moi( __( 'Livrer et faire signer', 'espace-traiteur' ), $quand ? sprintf( /* translators: %s : date. */ __( 'Livraison %s', 'espace-traiteur' ), $quand ) : '' );
					$a['boutons'][] = self::lien( self::url( array( 'vue' => 'livrer', 'id' => $id ) ), __( 'Livrer', 'espace-traiteur' ), true, 'camion' );
				} else {
					$attente( __( 'le livreur', 'espace-traiteur' ), __( 'À livrer', 'espace-traiteur' ), $quand );
				}
				break;
			case 'reprise':
				/* translators: %s : date. */
				$texte = $jour_rep ? sprintf( __( 'Reprise %s', 'espace-traiteur' ), self::quand( $jour_rep ) ) : '';
				if ( $livreur ) {
					$moi( __( 'Reprendre le matériel', 'espace-traiteur' ), $texte );
					$a['boutons'][] = self::lien( self::url( array( 'vue' => 'reprendre', 'id' => $id ) ), __( 'Reprendre le matériel', 'espace-traiteur' ), true, 'reprise' );
				} else {
					$attente( __( 'le livreur', 'espace-traiteur' ), __( 'Matériel chez le traiteur', 'espace-traiteur' ), $texte );
				}
				break;
			case 'cloturee':
				$ecarts = $reprise ? ETR_Operations::texte_ecarts( (int) $reprise['manquants'], (int) $reprise['casses'] ) : '';
				if ( $bureau ) {
					$moi( __( 'Régler les écarts puis clôturer', 'espace-traiteur' ), $ecarts ? sprintf( /* translators: %s : écarts. */ __( 'À la reprise : %s. Facturez la casse ou retrouvez les manquants (onglet Finances), puis clôturez.', 'espace-traiteur' ), $ecarts ) : '' );
					$a['boutons'][] = self::bouton_decision( $id, 'cloturee', __( 'Clôturer la demande', 'espace-traiteur' ), true );
					$a['boutons'][] = self::lien( self::url( array( 'vue' => 'demande', 'id' => $id, 'onglet' => 'argent' ) ), __( 'Voir les finances', 'espace-traiteur' ), false, 'suite', 'argent' );
				} else {
					$attente( __( 'le bureau', 'espace-traiteur' ), __( 'Écarts à régler', 'espace-traiteur' ), $ecarts );
				}
				if ( $livreur && 'livree' === $statut ) {
					// Matériel retrouvé, erreur de comptage : la reprise se refait tant que ce n'est pas clôturé.
					$a['boutons'][] = self::lien( self::url( array( 'vue' => 'reprendre', 'id' => $id ) ), __( 'Refaire la reprise', 'espace-traiteur' ), false, 'reprise' );
				}
				break;
			default:
				$a['sur']   = __( 'Terminé', 'espace-traiteur' );
				$a['titre'] = __( 'Dossier terminé', 'espace-traiteur' );
				if ( $bureau && class_exists( 'ETR_Facture' ) && ETR_Modules::actif( 'facture' ) && ! ETR_Facture::facture( $id ) && ETR_Facture::etablissable( $id ) ) {
					$a['a_moi']     = true;
					$a['sur']       = __( 'À vous de jouer', 'espace-traiteur' );
					$a['titre']     = __( 'Établir la facture', 'espace-traiteur' );
					$a['boutons'][] = self::lien( self::url( array( 'vue' => 'demande', 'id' => $id, 'onglet' => 'argent' ) ), __( 'Aller à la facture', 'espace-traiteur' ), true, 'suite', 'argent' );
				}
		}
		return $a;
	}

	/**
	 * « aujourd'hui », « demain » ou la date.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	public static function quand( $date ) {
		$auj = current_time( 'Y-m-d' );
		if ( $date === $auj ) {
			return __( 'aujourd’hui', 'espace-traiteur' );
		}
		if ( gmdate( 'Y-m-d', strtotime( $auj . ' +1 day' ) ) === $date ) {
			return __( 'demain', 'espace-traiteur' );
		}
		/* translators: %s : date. */
		return sprintf( __( 'le %s', 'espace-traiteur' ), date_i18n( 'l j F', strtotime( $date ) ) );
	}

	/**
	 * Adresse dans l'application.
	 *
	 * @param array $args Paramètres.
	 * @return string
	 */
	private static function url( $args ) {
		return ETR_Gestion::url( $args );
	}

	/**
	 * Lien-bouton.
	 *
	 * @param string $url     Adresse.
	 * @param string $texte   Libellé.
	 * @param bool   $plein   Bouton principal.
	 * @param string $icone   Icône.
	 * @param string $onglet  Onglet de la fiche (changement sans rechargement).
	 * @return string
	 */
	private static function lien( $url, $texte, $plein, $icone = '', $onglet = '' ) {
		return '<a class="etr-btn' . ( $plein ? '' : ' etr-btn--discret' ) . '" href="' . esc_url( $url ) . '"' . ( $onglet ? ' data-onglet="' . esc_attr( $onglet ) . '"' : '' ) . '>' . ( $icone ? ETR_Gestion::icone( $icone ) : '' ) . '<span>' . esc_html( $texte ) . '</span></a>';
	}

	/**
	 * Bouton qui change le statut (même action que le panneau « Statut », message gardé).
	 *
	 * @param int    $id     Demande.
	 * @param string $statut Nouveau statut.
	 * @param string $texte  Libellé.
	 * @param bool   $plein  Bouton principal.
	 * @return string
	 */
	private static function bouton_decision( $id, $statut, $texte, $plein ) {
		return '<form method="post" class="etr-parcours__form" action="' . esc_url( ETR_Gestion::url_demande( $id ) ) . '">' . wp_nonce_field( 'etr_gestion_decision_' . $id, 'etr_nonce', true, false )
			. '<input type="hidden" name="etr_gestion_action" value="decision"><input type="hidden" name="demande_id" value="' . (int) $id . '"><input type="hidden" name="etr_statut" value="' . esc_attr( $statut ) . '">'
			. '<input type="hidden" name="etr_reponse" value="' . esc_attr( (string) get_post_meta( $id, '_etr_reponse', true ) ) . '">'
			. '<button type="submit" class="etr-btn' . ( $plein ? '' : ' etr-btn--discret' ) . '">' . ETR_Gestion::icone( 'coche' ) . '<span>' . esc_html( $texte ) . '</span></button></form>';
	}

	/**
	 * Refus : message au traiteur facultatif, puis confirmation.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	private static function bouton_refus( $id ) {
		return '<details class="etr-parcours__refus"><summary class="etr-btn etr-btn--discret">' . esc_html__( 'Refuser', 'espace-traiteur' ) . '</summary>'
			. '<form method="post" action="' . esc_url( ETR_Gestion::url_demande( $id ) ) . '" data-confirmer="' . esc_attr__( 'Refuser la demande ? Le traiteur est prévenu et le matériel libéré.', 'espace-traiteur' ) . '">' . wp_nonce_field( 'etr_gestion_decision_' . $id, 'etr_nonce', true, false )
			. '<input type="hidden" name="etr_gestion_action" value="decision"><input type="hidden" name="demande_id" value="' . (int) $id . '"><input type="hidden" name="etr_statut" value="refusee">'
			. '<p class="etr-champ"><label for="etr-refus-' . (int) $id . '">' . esc_html__( 'Message au traiteur (facultatif)', 'espace-traiteur' ) . '</label><textarea id="etr-refus-' . (int) $id . '" name="etr_reponse" rows="3" placeholder="' . esc_attr__( 'Matériel indisponible à ces dates…', 'espace-traiteur' ) . '">' . esc_textarea( (string) get_post_meta( $id, '_etr_reponse', true ) ) . '</textarea></p>'
			. '<button type="submit" class="etr-btn etr-btn--alerte">' . esc_html__( 'Refuser la demande', 'espace-traiteur' ) . '</button></form></details>';
	}

	/* ------------------------------------------------------------------ */
	/* Affichage                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Frise des sept étapes (compacte : cartes de tournée, listes).
	 *
	 * @param int  $id      Demande.
	 * @param bool $details Avec le détail de chaque étape.
	 * @return string
	 */
	public static function frise( $id, $details = false ) {
		$courante = self::courante( $id );
		$close    = in_array( etr_get_statut( $id ), array( 'refusee', 'annulee' ), true );
		$html     = '<ol class="etr-parcours__frise' . ( $close ? ' est-close' : '' ) . '" aria-label="' . esc_attr__( 'Étapes de la demande', 'espace-traiteur' ) . '">';
		$n        = 0;
		foreach ( self::etapes( $id ) as $cle => $e ) {
			++$n;
			$etat  = $e[1] ? 'est-fait' : ( $cle === $courante ? 'est-courant' : 'est-a-venir' );
			$html .= '<li class="etr-parcours__etape ' . $etat . '"' . ( $cle === $courante ? ' aria-current="step"' : '' ) . '><span class="etr-parcours__puce" aria-hidden="true">' . ( $e[1] ? ETR_Gestion::icone( 'coche' ) : (int) $n ) . '</span><span class="etr-parcours__nom">' . esc_html( $e[0] ) . '</span>';
			if ( $details && $e[2] ) {
				$html .= '<small class="etr-parcours__detail">' . esc_html( $e[2] ) . '</small>';
			}
			$html .= '<span class="screen-reader-text">' . esc_html( $e[1] ? __( '(faite)', 'espace-traiteur' ) : ( $cle === $courante ? __( '(en cours)', 'espace-traiteur' ) : __( '(à venir)', 'espace-traiteur' ) ) ) . '</span></li>';
		}
		return $html . '</ol>';
	}

	/**
	 * Panneau « Parcours » en tête de la fiche : frise, puis l'action du moment.
	 *
	 * @param int $id Demande.
	 */
	public static function panneau( $id ) {
		$a = self::action( $id );
		echo '<section class="etr-parcours" aria-label="' . esc_attr__( 'Parcours de la demande', 'espace-traiteur' ) . '">';
		echo self::frise( $id, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans frise().
		echo '<div class="etr-parcours__carte' . ( $a['a_moi'] ? ' est-a-moi' : '' ) . '">';
		echo '<p class="etr-parcours__sur">' . esc_html( $a['sur'] ) . '</p><p class="etr-parcours__titre">' . esc_html( $a['titre'] ) . '</p>';
		if ( $a['texte'] ) {
			echo '<p class="etr-parcours__texte">' . esc_html( $a['texte'] ) . '</p>';
		}
		// Réimprimer une étiquette (abîmée au chargement…) : pour le préparateur et le livreur.
		if ( 'validee' === etr_get_statut( $id ) && ETR_Operations::bilan_colis( $id )['colis'] && ( ETR_Equipe::preparateur() || ETR_Equipe::livreur() ) ) {
			$a['boutons'][] = '<a class="etr-btn etr-btn--discret" href="' . esc_url( self::url( array( 'vue' => 'etiquettes', 'id' => $id ) ) ) . '" target="_blank">' . ETR_Gestion::icone( 'telecharger' ) . '<span>' . esc_html__( 'Étiquettes', 'espace-traiteur' ) . '</span></a>';
		}
		if ( $a['boutons'] ) {
			echo '<div class="etr-parcours__boutons">' . implode( '', $a['boutons'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- construits et échappés ci-dessus.
		}
		echo '</div></section>';
	}

	/* ------------------------------------------------------------------ */
	/* Accueil du bureau : compteurs par étape                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Étapes proposées en filtre (liste des demandes) : clé => libellé.
	 *
	 * @return string[]
	 */
	public static function filtres() {
		return array(
			'validee'  => __( 'À valider', 'espace-traiteur' ),
			'preparee' => __( 'À préparer', 'espace-traiteur' ),
			'livrer'   => __( 'À charger et livrer', 'espace-traiteur' ),
			'reprise'  => __( 'Chez le traiteur', 'espace-traiteur' ),
			'cloturee' => __( 'À clôturer', 'espace-traiteur' ),
		);
	}

	/**
	 * La demande est-elle à cette étape du filtre ?
	 *
	 * @param int    $id    Demande.
	 * @param string $filtre Clé de filtres().
	 * @return bool
	 */
	public static function correspond( $id, $filtre ) {
		$c = self::courante( $id );
		return 'livrer' === $filtre ? in_array( $c, array( 'chargee', 'livree' ), true ) : $c === $filtre;
	}

	/**
	 * Compteurs par étape (accueil du bureau), chacun ouvre la liste filtrée.
	 *
	 * @param int[] $ids Demandes en cours.
	 */
	public static function compteurs( $ids ) {
		$n = array_fill_keys( array_keys( self::filtres() ), 0 );
		foreach ( $ids as $id ) {
			foreach ( array_keys( $n ) as $f ) {
				if ( self::correspond( $id, $f ) ) {
					++$n[ $f ];
				}
			}
		}
		echo '<nav class="etr-pipeline" aria-label="' . esc_attr__( 'Demandes par étape', 'espace-traiteur' ) . '"><ol>';
		foreach ( self::filtres() as $f => $libelle ) {
			echo '<li' . ( $n[ $f ] ? '' : ' class="est-vide"' ) . '><a href="' . esc_url( self::url( array( 'vue' => 'demandes', 'etape' => $f ) ) ) . '"><strong>' . esc_html( number_format_i18n( $n[ $f ] ) ) . '</strong><span>' . esc_html( $libelle ) . '</span></a></li>';
		}
		echo '</ol></nav>';
	}

	/* ------------------------------------------------------------------ */
	/* Écran « À préparer » (préparateurs)                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Commandes validées pas encore prêtes, et celles prêtes à partir dans les 7 jours.
	 *
	 * @return array { a_faire: int[] (par date de livraison), pretes: int[] }
	 */
	public static function preparations() {
		static $resultat = null; // Pastille de l'onglet et écran : une seule lecture par page.
		if ( null !== $resultat ) {
			return $resultat;
		}
		$ids = get_posts(
			array(
				'post_type'     => 'etr_demande',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_key'      => '_etr_statut', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'    => 'validee', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		update_meta_cache( 'post', $ids );
		$a_faire = array();
		$pretes  = array();
		$limite  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +7 days' ) );
		foreach ( $ids as $id ) {
			if ( ! etr_get_lignes( $id ) || ETR_Operations::livraison( $id ) ) {
				continue;
			}
			list( $jour ) = ETR_Operations::jours( $id );
			if ( ETR_Operations::preparation( $id )['prete'] ) {
				if ( $jour && $jour <= $limite ) {
					$pretes[ $id ] = $jour;
				}
			} else {
				$a_faire[ $id ] = $jour ? $jour : '9999-12-31';
			}
		}
		asort( $a_faire );
		asort( $pretes );
		$resultat = array( 'a_faire' => $a_faire, 'pretes' => $pretes );
		return $resultat;
	}

	/**
	 * Nombre de commandes à préparer pour aujourd'hui et demain (pastille de l'onglet).
	 *
	 * @return int
	 */
	public static function urgentes() {
		$demain = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +1 day' ) );
		return count(
			array_filter(
				self::preparations()['a_faire'],
				function ( $jour ) use ( $demain ) {
					return $jour <= $demain;
				}
			)
		);
	}

	/**
	 * Écran « À préparer ».
	 */
	public static function vue_preparations() {
		$p = self::preparations();
		ETR_Gestion::entete( __( 'À préparer', 'espace-traiteur' ), __( 'Les commandes validées, de la plus urgente à la moins urgente. Touchez une commande pour la préparer.', 'espace-traiteur' ) );
		$auj     = current_time( 'Y-m-d' );
		$demain  = gmdate( 'Y-m-d', strtotime( $auj . ' +1 day' ) );
		$groupes = array(
			'retard'    => array( __( 'En retard', 'espace-traiteur' ), array() ),
			'auj'       => array( __( 'Pour aujourd’hui', 'espace-traiteur' ), array() ),
			'demain'    => array( __( 'Pour demain', 'espace-traiteur' ), array() ),
			'plus_tard' => array( __( 'Plus tard', 'espace-traiteur' ), array() ),
		);
		foreach ( $p['a_faire'] as $id => $jour ) {
			$cle                   = $jour < $auj ? 'retard' : ( $jour === $auj ? 'auj' : ( $jour === $demain ? 'demain' : 'plus_tard' ) );
			$groupes[ $cle ][1][] = $id;
		}
		if ( ! $p['a_faire'] ) {
			echo '<div class="etr-vide etr-vide--compact"><p>' . esc_html__( 'Rien à préparer : toutes les commandes validées sont prêtes.', 'espace-traiteur' ) . '</p></div>';
		}
		foreach ( $groupes as $cle => $g ) {
			if ( ! $g[1] ) {
				continue;
			}
			echo '<section class="etr-a-preparer' . ( in_array( $cle, array( 'retard', 'auj' ), true ) ? ' est-urgent' : '' ) . '"><h2 class="etr-section-titre">' . esc_html( $g[0] ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $g[1] ) ) ) . '</span></h2><ul class="etr-a-preparer__liste">';
			foreach ( $g[1] as $id ) {
				self::carte_preparation( $id );
			}
			echo '</ul></section>';
		}
		if ( $p['pretes'] ) {
			echo '<section class="etr-a-preparer est-prete"><h2 class="etr-section-titre">' . esc_html__( 'Prêtes, à charger', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $p['pretes'] ) ) ) . '</span></h2><ul class="etr-a-preparer__liste">';
			foreach ( array_keys( $p['pretes'] ) as $id ) {
				self::carte_preparation( $id );
			}
			echo '</ul></section>';
		}
	}

	/**
	 * Carte d'une commande à préparer.
	 *
	 * @param int $id Demande.
	 */
	private static function carte_preparation( $id ) {
		$prepa   = ETR_Operations::preparation( $id );
		$part    = (int) floor( 100 * $prepa['prepare'] / max( 1, $prepa['total'] ) );
		list( $jour ) = ETR_Operations::jours( $id );
		$creneau = (string) get_post_meta( $id, '_etr_creneau_livraison', true );
		$libelle = $prepa['prete'] ? __( 'Voir', 'espace-traiteur' ) : ( $prepa['prepare'] ? __( 'Continuer', 'espace-traiteur' ) : __( 'Commencer', 'espace-traiteur' ) );
		echo '<li class="etr-a-preparer__carte"><a href="' . esc_url( self::url( array( 'vue' => 'preparer', 'id' => $id ) ) ) . '" data-hors-ligne>';
		echo '<span class="etr-a-preparer__tete"><strong>' . esc_html( ETR_Operations::traiteur( $id ) ) . '</strong><span>' . esc_html( get_the_title( $id ) ) . '</span></span>';
		/* translators: %s : date. */
		echo '<span class="etr-a-preparer__quand">' . ETR_Gestion::icone( 'camion' ) . esc_html( ( $jour ? sprintf( __( 'Livraison %s', 'espace-traiteur' ), self::quand( $jour ) ) : __( 'Date de livraison à fixer', 'espace-traiteur' ) ) . ( $creneau ? ' · ' . $creneau : '' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icône statique.
		echo '<span class="etr-a-preparer__progres"><span class="etr-prepa__barre"><span style="width:' . esc_attr( (string) $part ) . '%"></span></span><small>' . esc_html(
			$prepa['prete']
				/* translators: %s : colis. */
				? sprintf( _n( 'Prête · %s colis', 'Prête · %s colis', (int) $prepa['colis'], 'espace-traiteur' ), number_format_i18n( (int) $prepa['colis'] ) )
				/* translators: 1 : préparés, 2 : total. */
				: sprintf( __( '%1$s / %2$s articles', 'espace-traiteur' ), number_format_i18n( $prepa['prepare'] ), number_format_i18n( $prepa['total'] ) )
		) . '</small></span>';
		echo '<span class="etr-btn' . ( $prepa['prete'] ? ' etr-btn--discret' : '' ) . ' etr-a-preparer__bouton">' . esc_html( $libelle ) . '</span>';
		echo '</a></li>';
	}
}
