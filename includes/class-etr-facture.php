<?php
/**
 * Facture finale numérotée (fonction « facture ») et export comptable (« export_compta »).
 *
 * La facture est établie par le bureau (ou à la clôture, si réglé), une seule fois par demande :
 * numéro chronologique sans trou (préfixe-année-numéro), contenu figé (le devis accepté s'il
 * existe, sinon le chiffrage du jour ; livraison ; casse et manquants), TVA, acompte déjà réglé
 * déduit. PDF envoyé au traiteur, téléchargeable dans son espace ; « payée le » renseigné par le
 * bureau ou par Stripe.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Facturation.
 */
class ETR_Facture {

	const OPTION   = 'etr_facturation';
	const META     = '_etr_facture';
	const VERROU   = 'etr_facture_verrou';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
		add_action( 'etr_fonctions_reglages', array( __CLASS__, 'champs_reglages' ) );
		add_action( 'etr_gestion_fiche_cote', array( __CLASS__, 'panneau' ), 40 );
		add_action( 'etr_gestion_action', array( __CLASS__, 'actions' ) );
		add_action( 'etr_traiteur_demande_fin', array( __CLASS__, 'encart_traiteur' ), 6 );
		add_action( 'template_redirect', array( __CLASS__, 'telechargement' ), 5 );
		add_action( 'etr_statut_change', array( __CLASS__, 'a_la_cloture' ), 30, 3 );
		add_filter( 'etr_gestion_vues', array( __CLASS__, 'vues' ) );
		add_action( 'etr_gestion_vue_factures', array( __CLASS__, 'vue_factures' ) );
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
			'raison'     => '',
			'adresse'    => '',
			'siret'      => '',
			'tva_intra'  => '',
			'tva'        => 20.0,
			'prix'       => 'ttc',   // Les prix du catalogue, la remise et la livraison sont TTC (ou HT).
			'prefixe'    => 'F',
			'prochain'   => 1,
			'annee'      => '',     // Année du compteur (remis à 1 chaque année).
			'delai'      => 30,
			'iban'       => '',
			'mentions'   => "En cas de retard de paiement : pénalités au taux de 3 fois le taux d’intérêt légal et indemnité forfaitaire pour frais de recouvrement de 40 €.\nPas d’escompte pour paiement anticipé.",
			'cloture'    => 0,      // Établir et envoyer la facture à la clôture de la demande.
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
	 * Option.
	 */
	public static function reglages() {
		register_setting(
			'etr_modules',
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => function ( $s ) {
					$p = self::parametres();
					if ( ! is_array( $s ) || ! isset( $s['raison'] ) ) {
						return $p;
					}
					foreach ( array( 'raison', 'siret', 'tva_intra', 'prefixe', 'iban' ) as $cle ) {
						$p[ $cle ] = isset( $s[ $cle ] ) ? sanitize_text_field( $s[ $cle ] ) : '';
					}
					foreach ( array( 'adresse', 'mentions' ) as $cle ) {
						$p[ $cle ] = isset( $s[ $cle ] ) ? sanitize_textarea_field( $s[ $cle ] ) : '';
					}
					$p['prefixe'] = preg_replace( '/[^A-Za-z0-9-]/', '', $p['prefixe'] );
					$p['prefixe'] = '' === $p['prefixe'] ? 'F' : $p['prefixe'];
					$tva          = isset( $s['tva'] ) ? etr_lire_nombre( (string) $s['tva'] ) : null;
					$p['tva']     = null === $tva ? 20.0 : min( 100, $tva );
					$p['prix']    = isset( $s['prix'] ) && 'ht' === $s['prix'] ? 'ht' : 'ttc';
					$p['delai']   = isset( $s['delai'] ) ? min( 120, absint( $s['delai'] ) ) : 30;
					$p['cloture'] = empty( $s['cloture'] ) ? 0 : 1;
					// Le compteur ne recule jamais (numérotation sans trou ni doublon).
					if ( isset( $s['prochain'] ) && absint( $s['prochain'] ) > (int) $p['prochain'] ) {
						$p['prochain'] = absint( $s['prochain'] );
					}
					return $p;
				},
			)
		);
	}

	/**
	 * Champs sous les fonctions.
	 *
	 * @param string $cle Fonction.
	 */
	public static function champs_reglages( $cle ) {
		if ( 'facture' !== $cle ) {
			return;
		}
		$p   = self::parametres();
		$nom = function ( $champ ) {
			return esc_attr( self::OPTION . '[' . $champ . ']' );
		};
		$texte = function ( $champ, $libelle, $classe = 'regular-text' ) use ( $p, $nom ) {
			echo '<p><label>' . esc_html( $libelle ) . '<br><input type="text" class="' . esc_attr( $classe ) . '" name="' . $nom( $champ ) . '" value="' . esc_attr( (string) $p[ $champ ] ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		echo '<fieldset style="margin-top:8px">';
		$texte( 'raison', __( 'Raison sociale (émetteur)', 'espace-traiteur' ) );
		echo '<p><label>' . esc_html__( 'Adresse', 'espace-traiteur' ) . '<br><textarea class="large-text" rows="2" name="' . $nom( 'adresse' ) . '">' . esc_textarea( $p['adresse'] ) . '</textarea></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$texte( 'siret', __( 'SIRET', 'espace-traiteur' ) );
		$texte( 'tva_intra', __( 'N° de TVA intracommunautaire', 'espace-traiteur' ) );
		echo '<p><label>' . esc_html__( 'Taux de TVA', 'espace-traiteur' ) . ' <input type="text" inputmode="decimal" class="small-text" name="' . $nom( 'tva' ) . '" value="' . esc_attr( str_replace( '.', ',', (string) $p['tva'] ) ) . '"> %</label> &nbsp; '; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<label>' . esc_html__( 'Les prix (catalogue, remise, livraison) sont', 'espace-traiteur' ) . ' <select name="' . $nom( 'prix' ) . '"><option value="ttc"' . selected( $p['prix'], 'ttc', false ) . '>TTC</option><option value="ht"' . selected( $p['prix'], 'ht', false ) . '>HT</option></select></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><label>' . esc_html__( 'Préfixe', 'espace-traiteur' ) . ' <input type="text" class="small-text" name="' . $nom( 'prefixe' ) . '" value="' . esc_attr( $p['prefixe'] ) . '"></label> &nbsp; '; // phpcs:ignore WordPress.Security.EscapeOutput
		/* translators: %s : exemple de numéro. */
		echo '<label>' . esc_html__( 'Prochain numéro', 'espace-traiteur' ) . ' <input type="number" min="' . (int) $p['prochain'] . '" class="small-text" name="' . $nom( 'prochain' ) . '" value="' . (int) $p['prochain'] . '"></label> <span class="description">' . esc_html( sprintf( __( 'Exemple : %s. Le compteur ne peut qu’avancer et repart à 1 chaque année.', 'espace-traiteur' ), self::format_numero( $p['prefixe'], (int) wp_date( 'Y' ), (int) $p['prochain'] ) ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><label>' . esc_html__( 'Délai de paiement', 'espace-traiteur' ) . ' <input type="number" min="0" max="120" class="small-text" name="' . $nom( 'delai' ) . '" value="' . (int) $p['delai'] . '"> ' . esc_html__( 'jours', 'espace-traiteur' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$texte( 'iban', __( 'IBAN (affiché pour le virement, facultatif)', 'espace-traiteur' ) );
		echo '<p><label>' . esc_html__( 'Mentions légales en pied de facture', 'espace-traiteur' ) . '<br><textarea class="large-text" rows="3" name="' . $nom( 'mentions' ) . '">' . esc_textarea( $p['mentions'] ) . '</textarea></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><input type="hidden" name="' . $nom( 'cloture' ) . '" value="0"><label><input type="checkbox" name="' . $nom( 'cloture' ) . '" value="1"' . checked( $p['cloture'], 1, false ) . '> ' . esc_html__( 'Établir et envoyer la facture automatiquement à la clôture de la demande', 'espace-traiteur' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</fieldset>';
	}

	/* ------------------------------------------------------------------ */
	/* Numérotation et contenu                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Numéro lisible.
	 *
	 * @param string $prefixe Préfixe.
	 * @param int    $annee   Année.
	 * @param int    $n       Numéro.
	 * @return string
	 */
	public static function format_numero( $prefixe, $annee, $n ) {
		return sprintf( '%s-%d-%04d', $prefixe, $annee, $n );
	}

	/**
	 * Prend le numéro suivant (verrou : deux factures ne peuvent pas avoir le même numéro).
	 *
	 * @return string|WP_Error
	 */
	private static function numero_suivant() {
		global $wpdb;
		for ( $essai = 0; $essai < 20; $essai++ ) {
			// Insertion atomique : seule une requête à la fois obtient le verrou (expiré après 30 s).
			$pris = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::VERROU, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $pris ) {
				break;
			}
			$depuis = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::VERROU ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $depuis && time() - $depuis > 30 ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => self::VERROU ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			usleep( 150000 );
		}
		if ( empty( $pris ) ) {
			return new WP_Error( 'etr_facture_verrou', __( 'Numérotation occupée, réessayez.', 'espace-traiteur' ) );
		}
		wp_cache_delete( self::OPTION, 'options' );
		$p     = self::parametres();
		$annee = (int) wp_date( 'Y' );
		$n     = (string) $annee === (string) $p['annee'] ? (int) $p['prochain'] : ( '' === (string) $p['annee'] ? (int) $p['prochain'] : 1 );
		$p['annee']    = $annee;
		$p['prochain'] = $n + 1;
		update_option( self::OPTION, $p );
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::VERROU ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::format_numero( $p['prefixe'], $annee, $n );
	}

	/**
	 * Facture d'une demande.
	 *
	 * @param int $id Demande.
	 * @return array Vide si aucune.
	 */
	public static function facture( $id ) {
		$f = get_post_meta( $id, self::META, true );
		return is_array( $f ) ? $f : array();
	}

	/**
	 * Montant HT d'un montant saisi selon la convention des prix.
	 *
	 * @param float $montant Montant.
	 * @param array $p       Paramètres.
	 * @return float
	 */
	private static function ht( $montant, $p ) {
		return 'ttc' === $p['prix'] ? $montant / ( 1 + $p['tva'] / 100 ) : $montant;
	}

	/**
	 * Contenu (sans numéro) : lignes, totaux, acompte.
	 *
	 * @param int $id Demande.
	 * @return array
	 */
	public static function contenu( $id ) {
		$p      = self::parametres();
		$lignes = array();
		$somme  = 0.0; // Montants dans la convention des prix (TTC ou HT).
		$devis  = class_exists( 'ETR_Devis' ) ? ETR_Devis::devis( $id ) : array();
		if ( ! empty( $devis['accepte'] ) && ! empty( $devis['lignes'] ) ) {
			$detail    = $devis['lignes'];
			$chiffrage = $devis['chiffrage'];
		} else {
			$chiffrage = etr_chiffrage( $id );
			$detail    = array();
			foreach ( etr_get_lignes( $id ) as $i => $l ) {
				$detail[] = array( 'titre' => $l['titre'], 'quantite' => (int) $l['quantite'], 'unitaire' => $chiffrage['lignes'][ $i ]['unitaire'], 'total' => $chiffrage['lignes'][ $i ]['total'] );
			}
		}
		foreach ( $detail as $l ) {
			$lignes[] = array( 'libelle' => $l['titre'], 'qte' => (int) $l['quantite'], 'pu' => null === $l['unitaire'] ? null : self::ht( (float) $l['unitaire'], $p ), 'total' => self::ht( (float) $l['total'], $p ) );
			$somme   += (float) $l['total'];
		}
		if ( ! empty( $chiffrage['remise'] ) ) {
			/* translators: %s : pourcentage. */
			$lignes[] = array( 'libelle' => sprintf( __( 'Remise (%s %%)', 'espace-traiteur' ), number_format_i18n( $chiffrage['remise_pct'], fmod( (float) $chiffrage['remise_pct'], 1 ) ? 2 : 0 ) ), 'qte' => 1, 'pu' => null, 'total' => - self::ht( (float) $chiffrage['remise'], $p ) );
			$somme   -= (float) $chiffrage['remise'];
		}
		if ( null !== $chiffrage['livraison'] && (float) $chiffrage['livraison'] > 0 ) {
			$lignes[] = array( 'libelle' => __( 'Livraison et reprise', 'espace-traiteur' ), 'qte' => 1, 'pu' => self::ht( (float) $chiffrage['livraison'], $p ), 'total' => self::ht( (float) $chiffrage['livraison'], $p ) );
			$somme   += (float) $chiffrage['livraison'];
		}
		if ( class_exists( 'ETR_Devis' ) && ETR_Modules::actif( 'casse' ) ) {
			foreach ( ETR_Devis::releve( $id )['lignes'] as $l ) {
				if ( null !== $l['total'] ) {
					/* translators: %s : article. */
					$lignes[] = array( 'libelle' => sprintf( __( 'Casse et manquants : %s', 'espace-traiteur' ), $l['titre'] ), 'qte' => $l['manquants'] + $l['casses'], 'pu' => self::ht( (float) $l['unitaire'], $p ), 'total' => self::ht( (float) $l['total'], $p ) );
					$somme   += (float) $l['total'];
				}
			}
		}
		if ( 'ttc' === $p['prix'] ) {
			$ttc = round( $somme, 2 );
			$ht  = round( $ttc / ( 1 + $p['tva'] / 100 ), 2 );
			$tva = round( $ttc - $ht, 2 );
		} else {
			$ht  = round( $somme, 2 );
			$tva = round( $ht * $p['tva'] / 100, 2 );
			$ttc = round( $ht + $tva, 2 );
		}
		$acompte = 0.0;
		$a       = class_exists( 'ETR_Devis' ) && ETR_Modules::actif( 'acompte' ) ? ETR_Devis::acompte( $id ) : array();
		if ( ! empty( $a['acompte'] ) && ! empty( $a['acompte_recu'] ) ) {
			$acompte = min( $ttc, (float) $a['acompte'] );
		}
		return array(
			'lignes'  => $lignes,
			'ht'      => $ht,
			'tva'     => $tva,
			'taux'    => (float) $p['tva'],
			'ttc'     => $ttc,
			'acompte' => $acompte,
			'reste'   => round( $ttc - $acompte, 2 ),
		);
	}

	/**
	 * Établit la facture (une seule fois) : numéro, contenu figé, émetteur et client.
	 *
	 * @param int $id Demande.
	 * @return array|WP_Error
	 */
	public static function etablir( $id ) {
		if ( self::facture( $id ) ) {
			return self::facture( $id );
		}
		$numero = self::numero_suivant();
		if ( is_wp_error( $numero ) ) {
			return $numero;
		}
		$p      = self::parametres();
		// Client : la société (compte principal) du traiteur qui a fait la demande.
		$auteur = (int) get_post_field( 'post_author', $id );
		$client = get_userdata( etr_societe_id( $auteur ) );
		$client = $client ? $client : get_userdata( $auteur );
		$f      = self::contenu( $id ) + array(
			'numero'   => $numero,
			'le'       => time(),
			'echeance' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS * (int) $p['delai'] ),
			'emetteur' => array_intersect_key( $p, array_flip( array( 'raison', 'adresse', 'siret', 'tva_intra', 'iban', 'mentions' ) ) ),
			'client'   => array(
				'nom'     => etr_nom( $client ),
				'contact' => $client ? (string) get_user_meta( $client->ID, 'etr_contact', true ) : '',
				'email'   => $client ? $client->user_email : '',
			),
			'payee'    => '',
		);
		update_post_meta( $id, self::META, $f );
		/* translators: 1 : numéro, 2 : montant. */
		ETR_Historique::ajouter( $id, 'facture', sprintf( __( 'Facture %1$s établie (%2$s TTC)', 'espace-traiteur' ), $numero, ETR_Devis::prix( $f['ttc'] ) ) );
		return $f;
	}

	/**
	 * Envoie la facture au traiteur.
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	public static function envoyer( $id ) {
		$f    = self::facture( $id );
		$user = get_userdata( (int) get_post_field( 'post_author', $id ) );
		if ( ! $f || ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$dossier = trailingslashit( get_temp_dir() ) . 'etr-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dossier ) ) {
			return false;
		}
		$fichier = $dossier . '/' . self::nom_fichier( $f );
		file_put_contents( $fichier, self::pdf( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		/* translators: 1 : site, 2 : numéro. */
		$objet  = sprintf( __( '[%1$s] Facture %2$s', 'espace-traiteur' ), etr_nom_site(), $f['numero'] );
		/* translators: %s : nom. */
		$corps  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n";
		/* translators: 1 : numéro, 2 : événement. */
		$corps .= sprintf( __( 'Veuillez trouver ci-joint la facture %1$s pour « %2$s ».', 'espace-traiteur' ), $f['numero'], get_the_title( $id ) ) . "\n\n";
		$corps .= __( 'Total TTC', 'espace-traiteur' ) . ' : ' . ETR_Devis::prix( $f['ttc'] ) . "\n";
		if ( $f['acompte'] > 0 ) {
			$corps .= __( 'Acompte déjà réglé', 'espace-traiteur' ) . ' : ' . ETR_Devis::prix( $f['acompte'] ) . "\n";
		}
		$corps .= __( 'Reste à payer', 'espace-traiteur' ) . ' : ' . ETR_Devis::prix( $f['reste'] ) . "\n";
		$corps .= __( 'Échéance', 'espace-traiteur' ) . ' : ' . etr_format_date( $f['echeance'] ) . "\n\n";
		$corps .= __( 'Voir la demande :', 'espace-traiteur' ) . ' ' . etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) . "\n";
		$envoye = etr_envoyer_mail( $user->user_email, $objet, $corps, array(), array( $fichier ) );
		wp_delete_file( $fichier );
		@rmdir( $dossier ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		if ( $envoye ) {
			/* translators: %s : numéro. */
			ETR_Historique::ajouter( $id, 'facture', sprintf( __( 'Facture %s envoyée au traiteur', 'espace-traiteur' ), $f['numero'] ) );
		}
		return $envoye;
	}

	/**
	 * Clôture : facture établie et envoyée si réglé ainsi.
	 *
	 * @param int    $id      Demande.
	 * @param string $ancien  Ancien statut.
	 * @param string $nouveau Nouveau statut.
	 */
	public static function a_la_cloture( $id, $ancien, $nouveau ) {
		if ( 'cloturee' === $nouveau && ETR_Modules::actif( 'facture' ) && self::parametres()['cloture'] && ! self::facture( $id ) && etr_get_lignes( $id ) ) {
			$f = self::etablir( $id );
			if ( ! is_wp_error( $f ) ) {
				self::envoyer( $id );
			}
		}
	}

	/**
	 * Marque payée (bureau, ou paiement Stripe du solde).
	 *
	 * @param int    $id   Demande.
	 * @param string $date AAAA-MM-JJ (vide : non payée).
	 */
	public static function marquer_payee( $id, $date ) {
		$f = self::facture( $id );
		if ( ! $f ) {
			return;
		}
		$f['payee'] = etr_date_valide( $date ) ? $date : '';
		update_post_meta( $id, self::META, $f );
		if ( $f['payee'] ) {
			/* translators: 1 : numéro, 2 : date. */
			ETR_Historique::ajouter( $id, 'facture', sprintf( __( 'Facture %1$s payée le %2$s', 'espace-traiteur' ), $f['numero'], etr_format_date( $f['payee'] ) ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Écrans                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Panneau « Facture » de la fiche (bureau).
	 *
	 * @param int $id Demande.
	 */
	public static function panneau( $id ) {
		if ( ! ETR_Modules::actif( 'facture' ) || ! etr_get_lignes( $id ) ) {
			return;
		}
		$f    = self::facture( $id );
		$url  = ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) );
		echo '<section class="etr-panneau etr-facture"><h2 class="etr-panneau__titre">' . esc_html__( 'Facture', 'espace-traiteur' ) . '</h2>';
		if ( ! $f ) {
			if ( ! in_array( etr_get_statut( $id ), array( 'livree', 'cloturee' ), true ) ) {
				echo '<p class="etr-muted">' . esc_html__( 'Possible une fois le matériel livré.', 'espace-traiteur' ) . '</p></section>';
				return;
			}
			$c = self::contenu( $id );
			/* translators: 1 : HT, 2 : TTC. */
			echo '<p>' . esc_html( sprintf( __( 'Montant : %1$s HT, %2$s TTC', 'espace-traiteur' ), ETR_Devis::prix( $c['ht'] ), ETR_Devis::prix( $c['ttc'] ) ) ) . '</p>';
			if ( '' === self::parametres()['raison'] ) {
				echo '<p class="etr-notice etr-notice--erreur">' . esc_html__( 'Renseignez d’abord la raison sociale, l’adresse et le SIRET (Espace traiteur › Fonctions › Facture).', 'espace-traiteur' ) . '</p></section>';
				return;
			}
			echo '<form method="post" action="' . esc_url( $url ) . '" data-confirmer="' . esc_attr__( 'Établir la facture ? Elle reçoit un numéro définitif et ne peut plus être modifiée.', 'espace-traiteur' ) . '">' . wp_nonce_field( 'etr_facture_' . $id, 'etr_nonce', true, false ) . '<input type="hidden" name="etr_gestion_action" value="facture"><input type="hidden" name="quoi" value="etablir"><input type="hidden" name="demande_id" value="' . (int) $id . '"><button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Établir et envoyer la facture', 'espace-traiteur' ) . '</button></form></section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- champ de sécurité WordPress.
			return;
		}
		/* translators: 1 : numéro, 2 : date, 3 : TTC. */
		echo '<p><strong>' . esc_html( $f['numero'] ) . '</strong> · ' . esc_html( sprintf( __( 'du %1$s · %2$s TTC', 'espace-traiteur' ), wp_date( 'j F Y', (int) $f['le'] ), ETR_Devis::prix( $f['ttc'] ) ) ) . '</p>';
		echo $f['payee'] ? '<p class="etr-pastille-etat etr-pastille-etat--ok">' . esc_html( sprintf( /* translators: %s : date. */ __( 'Payée le %s', 'espace-traiteur' ), etr_format_date( $f['payee'] ) ) ) . '</p>' : '<p class="etr-pastille-etat etr-pastille-etat--cours">' . esc_html( sprintf( /* translators: 1 : montant, 2 : date. */ __( 'Reste %1$s à payer avant le %2$s', 'espace-traiteur' ), ETR_Devis::prix( $f['reste'] ), etr_format_date( $f['echeance'] ) ) ) . '</p>';
		echo '<p><a class="etr-lien" target="_blank" href="' . esc_url( self::url_pdf( $id ) ) . '">' . esc_html__( 'Voir la facture (PDF)', 'espace-traiteur' ) . '</a></p>';
		echo '<form method="post" class="etr-facture__payee" action="' . esc_url( $url ) . '">' . wp_nonce_field( 'etr_facture_' . $id, 'etr_nonce', true, false ) . '<input type="hidden" name="etr_gestion_action" value="facture"><input type="hidden" name="quoi" value="payee"><input type="hidden" name="demande_id" value="' . (int) $id . '"><label>' . esc_html__( 'Payée le', 'espace-traiteur' ) . ' <input type="date" name="payee" value="' . esc_attr( $f['payee'] ) . '"></label> <button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Enregistrer', 'espace-traiteur' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<form method="post" action="' . esc_url( $url ) . '">' . wp_nonce_field( 'etr_facture_' . $id, 'etr_nonce', true, false ) . '<input type="hidden" name="etr_gestion_action" value="facture"><input type="hidden" name="quoi" value="envoyer"><input type="hidden" name="demande_id" value="' . (int) $id . '"><button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Renvoyer au traiteur', 'espace-traiteur' ) . '</button></form></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Actions du bureau.
	 *
	 * @param string $action Action.
	 */
	public static function actions( $action ) {
		if ( 'export_factures' === $action && ETR_Modules::actif( 'export_compta' ) ) {
			check_admin_referer( 'etr_export_factures', 'etr_nonce' );
			self::exporter( isset( $_REQUEST['mois'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mois'] ) ) : '' );
		}
		if ( 'facture' !== $action || ! ETR_Modules::actif( 'facture' ) ) {
			return;
		}
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		check_admin_referer( 'etr_facture_' . $id, 'etr_nonce' );
		if ( ! $id || ! ETR_Gestion::demande_visible( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$quoi = isset( $_POST['quoi'] ) ? sanitize_key( wp_unslash( $_POST['quoi'] ) ) : '';
		$msg  = 'fait';
		if ( 'etablir' === $quoi && in_array( etr_get_statut( $id ), array( 'livree', 'cloturee' ), true ) ) {
			$f   = self::etablir( $id );
			$msg = is_wp_error( $f ) ? 'mail_echec' : ( self::envoyer( $id ) ? 'facture_envoyee' : 'mail_echec' );
		} elseif ( 'envoyer' === $quoi ) {
			$msg = self::envoyer( $id ) ? 'facture_envoyee' : 'mail_echec';
		} elseif ( 'payee' === $quoi ) {
			self::marquer_payee( $id, isset( $_POST['payee'] ) ? sanitize_text_field( wp_unslash( $_POST['payee'] ) ) : '' );
		}
		wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => $msg ) ) );
		exit;
	}

	/**
	 * Encart de l'espace traiteur.
	 *
	 * @param int $id Demande.
	 */
	public static function encart_traiteur( $id ) {
		$f = ETR_Modules::actif( 'facture' ) ? self::facture( $id ) : array();
		if ( ! $f || ! etr_peut_voir_demande( $id ) ) {
			return;
		}
		echo '<section class="etr-panneau etr-encart-traiteur"><h2 class="etr-panneau__titre">' . esc_html__( 'Facture', 'espace-traiteur' ) . '</h2>';
		/* translators: 1 : numéro, 2 : montant. */
		echo '<p>' . esc_html( sprintf( __( 'Facture %1$s · %2$s TTC', 'espace-traiteur' ), $f['numero'], ETR_Devis::prix( $f['ttc'] ) ) ) . ' — <a class="etr-lien" href="' . esc_url( self::url_pdf( $id ) ) . '">' . esc_html__( 'Télécharger (PDF)', 'espace-traiteur' ) . '</a></p>';
		echo $f['payee'] ? '<p class="etr-notice etr-notice--succes">' . esc_html( sprintf( /* translators: %s : date. */ __( 'Réglée le %s. Merci !', 'espace-traiteur' ), etr_format_date( $f['payee'] ) ) ) . '</p>' : '<p>' . esc_html( sprintf( /* translators: 1 : montant, 2 : date. */ __( 'Reste à payer : %1$s avant le %2$s.', 'espace-traiteur' ), ETR_Devis::prix( $f['reste'] ), etr_format_date( $f['echeance'] ) ) ) . '</p>';
		do_action( 'etr_facture_encart_traiteur', $id, $f ); // Paiement en ligne du solde (Stripe).
		echo '</section>';
	}

	/**
	 * Adresse du PDF.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function url_pdf( $id ) {
		return add_query_arg( array( 'etr_document' => 'facture', 'id' => (int) $id ), etr_url() );
	}

	/**
	 * Nom du fichier.
	 *
	 * @param array $f Facture.
	 * @return string
	 */
	private static function nom_fichier( $f ) {
		return 'facture-' . sanitize_file_name( $f['numero'] ) . '.pdf';
	}

	/**
	 * Téléchargement : traiteur de la demande (ou de sa société) et bureau.
	 */
	public static function telechargement() {
		if ( ! isset( $_GET['etr_document'], $_GET['id'] ) || 'facture' !== $_GET['etr_document'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$f  = self::facture( $id );
		$ok = ETR_Modules::actif( 'facture' ) && $f && is_user_logged_in() && ( etr_peut_voir_demande( $id ) || ( ETR_Equipe::acces() && ETR_Equipe::bureau() ) );
		if ( ! $ok ) {
			wp_die( esc_html__( 'Document introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$pdf = self::pdf( $id );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . self::nom_fichier( $f ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- document PDF.
		exit;
	}

	/**
	 * Facture (PDF), d'après le contenu figé.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function pdf( $id ) {
		$f       = self::facture( $id );
		$m       = 42;
		$largeur = ETR_PDF::LARGEUR - 2 * $m;
		$encre   = '#1d1d1b';
		$gris    = '#6b655f';
		$pdf     = new ETR_PDF( $f['numero'] );
		$prix    = array( 'ETR_Devis', 'prix' );

		$y       = $m;
		$logo_id = ETR_Comptes::logo_id();
		$logo    = $logo_id ? ETR_Bon_Livraison::jpeg( get_attached_file( $logo_id ), 400 ) : '';
		if ( $logo ) {
			$pdf->image( $logo, $m, $y, 110 );
		} else {
			$pdf->texte( $m, $y + 18, etr_nom_site(), 18, true, $encre );
		}
		$pdf->texte( $m + $largeur, $y + 14, __( 'FACTURE', 'espace-traiteur' ), 15, true, $encre, 'droite' );
		$pdf->texte( $m + $largeur, $y + 30, $f['numero'], 11, true, $encre, 'droite' );
		/* translators: %s : date. */
		$pdf->texte( $m + $largeur, $y + 45, sprintf( __( 'Date : %s', 'espace-traiteur' ), wp_date( 'd/m/Y', (int) $f['le'] ) ), 9.5, false, $gris, 'droite' );
		/* translators: %s : date. */
		$pdf->texte( $m + $largeur, $y + 58, sprintf( __( 'Échéance : %s', 'espace-traiteur' ), gmdate( 'd/m/Y', strtotime( $f['echeance'] ) ) ), 9.5, false, $gris, 'droite' );
		$y += 78;
		$pdf->trait( $m, $y, $m + $largeur, $y, $encre, 1 );
		$y += 22;

		// Émetteur et client.
		$col = $largeur / 2 - 10;
		$e   = $f['emetteur'];
		$pdf->texte( $m, $y, __( 'ÉMETTEUR', 'espace-traiteur' ), 8, true, $gris );
		$y_g = $pdf->paragraphe( $m, $y + 15, $col, $e['raison'], 11, true );
		$id_legal = array_filter(
			array(
				$e['adresse'],
				$e['siret'] ? 'SIRET ' . $e['siret'] : '',
				$e['tva_intra'] ? __( 'TVA intracommunautaire', 'espace-traiteur' ) . ' ' . $e['tva_intra'] : '',
			)
		);
		$y_g = $pdf->paragraphe( $m, $y_g, $col, implode( "\n", $id_legal ), 9, false, '#3a3732' );
		$x_d = $m + $largeur / 2 + 10;
		$pdf->texte( $x_d, $y, __( 'CLIENT', 'espace-traiteur' ), 8, true, $gris );
		$y_d = $pdf->paragraphe( $x_d, $y + 15, $col, $f['client']['nom'], 11, true );
		$y_d = $pdf->paragraphe( $x_d, $y_d, $col, trim( $f['client']['contact'] . "\n" . $f['client']['email'] . "\n" . sprintf( /* translators: 1 : événement, 2 : numéro. */ __( '%1$s (demande n° %2$d)', 'espace-traiteur' ), get_the_title( $id ), $id ) ), 9, false, '#3a3732' );
		$y   = max( $y_g, $y_d ) + 20;

		// Lignes.
		$x = array( $m + 8, $m + $largeur - 210, $m + $largeur - 100, $m + $largeur - 8 );
		$pdf->rectangle( $m, $y, $largeur, 20, '#efebe4' );
		foreach ( array( __( 'Désignation', 'espace-traiteur' ), __( 'Qté', 'espace-traiteur' ), __( 'PU HT', 'espace-traiteur' ), __( 'Total HT', 'espace-traiteur' ) ) as $n => $libelle ) {
			$pdf->texte( $x[ $n ], $y + 13.5, $libelle, 9, true, $encre, $n ? 'droite' : 'gauche' );
		}
		$y += 20;
		foreach ( $f['lignes'] as $i => $l ) {
			if ( $y > ETR_PDF::HAUTEUR - 190 ) {
				$pdf->page();
				$y = $m;
			}
			$morceaux = ETR_PDF::couper( $l['libelle'], 9.5, $largeur - 280 );
			$haut     = 8 + 13 * count( $morceaux );
			if ( $i % 2 ) {
				$pdf->rectangle( $m, $y, $largeur, $haut, '#faf9f6' );
			}
			foreach ( $morceaux as $n => $t ) {
				$pdf->texte( $x[0], $y + 14 + 13 * $n, $t, 9.5 );
			}
			$pdf->texte( $x[1], $y + 14, number_format_i18n( $l['qte'] ), 9.5, false, $encre, 'droite' );
			$pdf->texte( $x[2], $y + 14, null === $l['pu'] ? '—' : call_user_func( $prix, $l['pu'] ), 9.5, false, $encre, 'droite' );
			$pdf->texte( $x[3], $y + 14, call_user_func( $prix, $l['total'] ), 9.5, false, $encre, 'droite' );
			$y += $haut;
			$pdf->trait( $m, $y, $m + $largeur, $y, '#e3ddd3', 0.5 );
		}
		$y += 8;
		$totaux = array(
			array( __( 'Total HT', 'espace-traiteur' ), $f['ht'], false ),
			/* translators: %s : taux. */
			array( sprintf( __( 'TVA %s %%', 'espace-traiteur' ), number_format_i18n( $f['taux'], fmod( $f['taux'], 1 ) ? 1 : 0 ) ), $f['tva'], false ),
			array( __( 'Total TTC', 'espace-traiteur' ), $f['ttc'], true ),
		);
		if ( $f['acompte'] > 0 ) {
			$totaux[] = array( __( 'Acompte réglé', 'espace-traiteur' ), - $f['acompte'], false );
			$totaux[] = array( __( 'Reste à payer', 'espace-traiteur' ), $f['reste'], true );
		}
		foreach ( $totaux as $t ) {
			$y += 16;
			$pdf->texte( $x[2], $y, $t[0], $t[2] ? 11 : 10, $t[2], $encre, 'droite' );
			$pdf->texte( $x[3], $y, ( $t[1] < 0 ? '– ' . call_user_func( $prix, - $t[1] ) : call_user_func( $prix, $t[1] ) ), $t[2] ? 11 : 10, $t[2], $encre, 'droite' );
		}
		$y += 26;
		if ( $f['payee'] ) {
			/* translators: %s : date. */
			$y = $pdf->paragraphe( $m, $y, $largeur, sprintf( __( 'Facture réglée le %s.', 'espace-traiteur' ), gmdate( 'd/m/Y', strtotime( $f['payee'] ) ) ), 10, true ) + 6;
		} else {
			/* translators: %s : date. */
			$y = $pdf->paragraphe( $m, $y, $largeur, sprintf( __( 'À régler avant le %s.', 'espace-traiteur' ), gmdate( 'd/m/Y', strtotime( $f['echeance'] ) ) ) . ( $e['iban'] ? ' ' . __( 'Virement :', 'espace-traiteur' ) . ' ' . $e['iban'] : '' ), 10 ) + 6;
		}
		if ( $e['mentions'] ) {
			$pdf->paragraphe( $m, max( $y + 10, ETR_PDF::HAUTEUR - 110 ), $largeur, $e['mentions'], 7.5, false, '#6b655f' );
		}
		return $pdf->sortie();
	}

	/* ------------------------------------------------------------------ */
	/* Liste et export comptable                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Onglet « Factures » (bureau).
	 *
	 * @param array $vues Vues.
	 * @return array
	 */
	public static function vues( $vues ) {
		if ( ETR_Modules::actif( 'facture' ) ) {
			$vues['factures'] = array( __( 'Factures', 'espace-traiteur' ), 'demandes' );
		}
		return $vues;
	}

	/**
	 * Factures d'un mois (ou toutes), les plus récentes d'abord.
	 *
	 * @param string $mois AAAA-MM ou vide.
	 * @return array[] { id, facture }
	 */
	public static function factures( $mois = '' ) {
		$ids = get_posts( array( 'post_type' => 'etr_demande', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => self::META ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$out = array();
		foreach ( $ids as $id ) {
			$f = self::facture( $id );
			if ( $f && ( '' === $mois || wp_date( 'Y-m', (int) $f['le'] ) === $mois ) ) {
				$out[] = array( 'id' => $id, 'facture' => $f );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $b['facture']['numero'], $a['facture']['numero'] );
			}
		);
		return $out;
	}

	/**
	 * Écran « Factures ».
	 */
	public static function vue_factures() {
		if ( ! ETR_Modules::actif( 'facture' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		$mois = isset( $_GET['mois'] ) ? sanitize_text_field( wp_unslash( $_GET['mois'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$mois = preg_match( '/^\d{4}-\d{2}$/', $mois ) ? $mois : '';
		$liste = self::factures( $mois );
		$action = '';
		if ( ETR_Modules::actif( 'export_compta' ) ) {
			$action = '<a class="etr-btn etr-btn--discret" href="' . esc_url( wp_nonce_url( ETR_Gestion::url( array( 'etr_gestion_action' => 'export_factures', 'mois' => $mois ) ), 'etr_export_factures', 'etr_nonce' ) ) . '">' . esc_html__( 'Export comptable (CSV)', 'espace-traiteur' ) . '</a>';
		}
		ETR_Gestion::entete( __( 'Factures', 'espace-traiteur' ), $mois ? ucfirst( date_i18n( 'F Y', strtotime( $mois . '-01' ) ) ) : __( 'Toutes les factures', 'espace-traiteur' ), $action );
		echo '<form method="get" class="etr-factures__filtre" action="' . esc_url( ETR_Gestion::url() ) . '">';
		if ( ! get_option( 'permalink_structure' ) ) {
			echo '<input type="hidden" name="etr_gestion" value="1">';
		}
		echo '<input type="hidden" name="vue" value="factures"><label>' . esc_html__( 'Mois', 'espace-traiteur' ) . ' <input type="month" name="mois" value="' . esc_attr( $mois ) . '" onchange="this.form.submit()"></label></form>';
		if ( ! $liste ) {
			echo '<div class="etr-vide"><p>' . esc_html__( 'Aucune facture.', 'espace-traiteur' ) . '</p></div>';
			return;
		}
		$ttc = array_sum( array_map( function ( $l ) { return $l['facture']['ttc']; }, $liste ) );
		$du  = array_sum( array_map( function ( $l ) { return $l['facture']['payee'] ? 0 : $l['facture']['reste']; }, $liste ) );
		/* translators: 1 : total, 2 : reste dû. */
		echo '<p class="etr-factures__total">' . esc_html( sprintf( __( 'Total : %1$s TTC · reste dû : %2$s', 'espace-traiteur' ), ETR_Devis::prix( $ttc ), ETR_Devis::prix( $du ) ) ) . '</p>';
		echo '<div class="etr-table-cadre"><table class="etr-table"><thead><tr><th>' . esc_html__( 'Numéro', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'Client', 'espace-traiteur' ) . '</th><th class="etr-table__nombre">' . esc_html__( 'TTC', 'espace-traiteur' ) . '</th><th>' . esc_html__( 'État', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
		foreach ( $liste as $l ) {
			$f = $l['facture'];
			echo '<tr><td><a class="etr-lien" href="' . esc_url( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $l['id'] ) ) ) . '">' . esc_html( $f['numero'] ) . '</a><br><small class="etr-muted">' . esc_html( wp_date( 'j M Y', (int) $f['le'] ) ) . '</small></td><td>' . esc_html( $f['client']['nom'] ) . '</td><td class="etr-table__nombre">' . esc_html( ETR_Devis::prix( $f['ttc'] ) ) . '</td><td>' . ( $f['payee'] ? '<span class="etr-pastille-etat etr-pastille-etat--ok">' . esc_html__( 'Payée', 'espace-traiteur' ) . '</span>' : ( $f['echeance'] < current_time( 'Y-m-d' ) ? '<span class="etr-pastille-etat etr-pastille-etat--alerte">' . esc_html__( 'En retard', 'espace-traiteur' ) . '</span>' : '<span class="etr-pastille-etat">' . esc_html__( 'À payer', 'espace-traiteur' ) . '</span>' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Export CSV pour la comptabilité (séparateur « ; », virgule décimale, UTF-8 pour Excel).
	 *
	 * @param string $mois AAAA-MM ou vide.
	 */
	public static function exporter( $mois ) {
		$mois = preg_match( '/^\d{4}-\d{2}$/', (string) $mois ) ? $mois : '';
		$nombre = function ( $n ) {
			return number_format( (float) $n, 2, ',', '' );
		};
		$cellule = function ( $v ) {
			$v = (string) $v;
			if ( preg_match( '/^[=+\-@\t\r]/', $v ) ) {
				$v = "'" . $v; // Pas de formule exécutée à l'ouverture dans un tableur.
			}
			return '"' . str_replace( '"', '""', $v ) . '"';
		};
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="factures' . ( $mois ? '-' . $mois : '' ) . '.csv"' );
		$sortie = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $sortie, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$entetes = array( 'Numéro', 'Date', 'Échéance', 'Client', 'Demande', 'Total HT', 'TVA', 'Taux TVA', 'Total TTC', 'Acompte réglé', 'Reste à payer', 'Payée le', 'Paiement Stripe' );
		fwrite( $sortie, implode( ';', array_map( $cellule, $entetes ) ) . "\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( array_reverse( self::factures( $mois ) ) as $l ) {
			$f      = $l['facture'];
			$stripe = class_exists( 'ETR_Stripe' ) ? ETR_Stripe::resume_paiements( $l['id'] ) : '';
			$ligne  = array( $f['numero'], wp_date( 'd/m/Y', (int) $f['le'] ), gmdate( 'd/m/Y', strtotime( $f['echeance'] ) ), $f['client']['nom'], '#' . $l['id'] . ' ' . get_the_title( $l['id'] ), $nombre( $f['ht'] ), $nombre( $f['tva'] ), $nombre( $f['taux'] ), $nombre( $f['ttc'] ), $nombre( $f['acompte'] ), $nombre( $f['reste'] ), $f['payee'] ? gmdate( 'd/m/Y', strtotime( $f['payee'] ) ) : '', $stripe );
			fwrite( $sortie, implode( ';', array_map( $cellule, $ligne ) ) . "\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fclose( $sortie ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
