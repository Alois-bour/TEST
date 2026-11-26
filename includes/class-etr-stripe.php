<?php
/**
 * Paiements Stripe (fonction « stripe ») : acompte, solde de la facture et caution.
 *
 * - Paiement par Stripe Checkout (page de paiement hébergée par Stripe : aucune donnée de carte
 *   ne passe par le site).
 * - Caution, au choix : « empreinte » (montant bloqué sur la carte sans être débité, 7 jours au
 *   plus : proposée à partir de 6 jours avant la livraison) ou « carte » (carte enregistrée, débitée
 *   seulement en cas de casse ou de manquants, avec l'accord du traiteur).
 * - Confirmation par webhook signé (etr/v1/stripe/webhook) et au retour du traiteur sur le site.
 * - Le bureau libère ou débite la caution depuis la fiche ; reprise complète : caution libérée.
 *
 * Montants par défaut de l'acompte et de la caution (fonction « acompte ») appliqués à la validation.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe.
 */
class ETR_Stripe {

	const OPTION          = 'etr_stripe';
	const OPTION_DEFAUTS  = 'etr_acompte_defauts';
	const META            = '_etr_stripe';
	const API             = 'https://api.stripe.com/v1/';
	const VERSION_API     = '2024-06-20';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
		add_action( 'etr_fonctions_reglages', array( __CLASS__, 'champs_reglages' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'etr_traiteur_demande_fin', array( __CLASS__, 'encart_traiteur' ), 5 );
		add_action( 'etr_facture_encart_traiteur', array( __CLASS__, 'bouton_solde' ) );
		add_action( 'etr_traiteur_action', array( __CLASS__, 'action_payer' ) );
		add_action( 'template_redirect', array( __CLASS__, 'retour' ), 4 );
		add_action( 'etr_gestion_fiche_cote', array( __CLASS__, 'panneau' ), 35 );
		add_action( 'etr_gestion_action', array( __CLASS__, 'action_bureau' ) );
		add_action( 'etr_reprise_enregistree', array( __CLASS__, 'reprise_complete' ), 20, 2 );
		add_action( 'etr_statut_change', array( __CLASS__, 'montants_par_defaut' ), 5, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Réglages                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Paramètres.
	 *
	 * @return array
	 */
	public static function parametres() {
		$p = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $p ) ? $p : array(),
			array(
				'secrete'  => '',
				'webhook'  => '',
				'acompte'  => 1,
				'solde'    => 1,
				'caution'  => 'empreinte', // aucune | empreinte | carte.
			)
		);
	}

	/**
	 * Montants par défaut (fonction « acompte »).
	 *
	 * @return array { pct (acompte en % du total), caution (€) }
	 */
	public static function defauts_montants() {
		$p = get_option( self::OPTION_DEFAUTS, array() );
		return wp_parse_args( is_array( $p ) ? $p : array(), array( 'pct' => 0, 'caution' => 0 ) );
	}

	/**
	 * Options.
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
					if ( ! is_array( $s ) || ! isset( $s['caution'] ) ) {
						return $p;
					}
					// Clés : un champ laissé vide garde la clé enregistrée (jamais réaffichée).
					foreach ( array( 'secrete' => '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', 'webhook' => '/^whsec_[A-Za-z0-9]+$/' ) as $cle => $forme ) {
						$v = isset( $s[ $cle ] ) ? trim( sanitize_text_field( $s[ $cle ] ) ) : '';
						if ( '' !== $v && preg_match( $forme, $v ) ) {
							$p[ $cle ] = $v;
						}
						if ( ! empty( $s[ 'effacer_' . $cle ] ) ) {
							$p[ $cle ] = '';
						}
					}
					$p['acompte'] = empty( $s['acompte'] ) ? 0 : 1;
					$p['solde']   = empty( $s['solde'] ) ? 0 : 1;
					$p['caution'] = in_array( $s['caution'], array( 'aucune', 'empreinte', 'carte' ), true ) ? $s['caution'] : 'empreinte';
					return $p;
				},
			)
		);
		register_setting(
			'etr_modules',
			self::OPTION_DEFAUTS,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => function ( $s ) {
					$pct = isset( $s['pct'] ) ? etr_lire_nombre( (string) $s['pct'] ) : 0;
					$c   = isset( $s['caution'] ) ? etr_lire_nombre( (string) $s['caution'] ) : 0;
					return array( 'pct' => min( 100, (float) $pct ), 'caution' => (float) $c );
				},
			)
		);
	}

	/**
	 * Clé masquée.
	 *
	 * @param string $cle Clé.
	 * @return string
	 */
	private static function masque( $cle ) {
		return '' === $cle ? '' : substr( $cle, 0, 8 ) . '…' . substr( $cle, -4 );
	}

	/**
	 * Champs sous les fonctions.
	 *
	 * @param string $cle Fonction.
	 */
	public static function champs_reglages( $cle ) {
		if ( 'acompte' === $cle ) {
			$d = self::defauts_montants();
			echo '<p><label>' . esc_html__( 'Acompte par défaut', 'espace-traiteur' ) . ' <input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( self::OPTION_DEFAUTS ) . '[pct]" value="' . esc_attr( str_replace( '.', ',', (string) $d['pct'] ) ) . '"> % ' . esc_html__( 'du total', 'espace-traiteur' ) . '</label> &nbsp; ';
			echo '<label>' . esc_html__( 'Caution par défaut', 'espace-traiteur' ) . ' <input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( self::OPTION_DEFAUTS ) . '[caution]" value="' . esc_attr( str_replace( '.', ',', (string) $d['caution'] ) ) . '"> €</label></p>';
			echo '<p class="description">' . esc_html__( 'Appliqués à la validation d’une demande quand les montants ne sont pas encore renseignés (0 = aucun). Modifiables ensuite sur chaque fiche.', 'espace-traiteur' ) . '</p>';
			return;
		}
		if ( 'stripe' !== $cle ) {
			return;
		}
		$p   = self::parametres();
		$nom = function ( $champ ) {
			return esc_attr( self::OPTION . '[' . $champ . ']' );
		};
		echo '<fieldset style="margin-top:8px">';
		echo '<p><label>' . esc_html__( 'Clé secrète (Stripe › Développeurs › Clés API)', 'espace-traiteur' ) . '<br><input type="password" autocomplete="off" class="regular-text" name="' . $nom( 'secrete' ) . '" placeholder="' . esc_attr( $p['secrete'] ? self::masque( $p['secrete'] ) : 'sk_live_…' ) . '"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $p['secrete'] ) {
			echo ' <label><input type="checkbox" name="' . $nom( 'effacer_secrete' ) . '" value="1"> ' . esc_html__( 'Effacer', 'espace-traiteur' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<br><span class="description">' . esc_html( $p['secrete'] ? ( 0 === strpos( $p['secrete'], 'sk_test' ) || 0 === strpos( $p['secrete'], 'rk_test' ) ? __( 'Clé de test enregistrée : aucun vrai paiement.', 'espace-traiteur' ) : __( 'Clé de production enregistrée.', 'espace-traiteur' ) ) : __( 'Aucune clé : les paiements en ligne ne sont pas proposés.', 'espace-traiteur' ) ) . '</span></p>';
		echo '<p><label>' . esc_html__( 'Secret du webhook', 'espace-traiteur' ) . '<br><input type="password" autocomplete="off" class="regular-text" name="' . $nom( 'webhook' ) . '" placeholder="' . esc_attr( $p['webhook'] ? self::masque( $p['webhook'] ) : 'whsec_…' ) . '"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $p['webhook'] ) {
			echo ' <label><input type="checkbox" name="' . $nom( 'effacer_webhook' ) . '" value="1"> ' . esc_html__( 'Effacer', 'espace-traiteur' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		/* translators: %s : adresse du webhook. */
		echo '<br><span class="description">' . esc_html( sprintf( __( 'Dans Stripe › Développeurs › Webhooks, ajoutez l’adresse %s avec l’événement « checkout.session.completed », puis collez ici son secret de signature.', 'espace-traiteur' ), rest_url( ETR_Push::API . '/stripe/webhook' ) ) ) . '</span></p>';
		echo '<p><input type="hidden" name="' . $nom( 'acompte' ) . '" value="0"><label><input type="checkbox" name="' . $nom( 'acompte' ) . '" value="1"' . checked( $p['acompte'], 1, false ) . '> ' . esc_html__( 'Paiement de l’acompte en ligne', 'espace-traiteur' ) . '</label><br>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<input type="hidden" name="' . $nom( 'solde' ) . '" value="0"><label><input type="checkbox" name="' . $nom( 'solde' ) . '" value="1"' . checked( $p['solde'], 1, false ) . '> ' . esc_html__( 'Paiement du solde de la facture en ligne', 'espace-traiteur' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><label>' . esc_html__( 'Caution', 'espace-traiteur' ) . ' <select name="' . $nom( 'caution' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( array( 'empreinte' => __( 'Empreinte bancaire (montant bloqué, 7 jours au plus)', 'espace-traiteur' ), 'carte' => __( 'Carte enregistrée, débitée seulement en cas de casse', 'espace-traiteur' ), 'aucune' => __( 'Pas de caution en ligne', 'espace-traiteur' ) ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $p['caution'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__( 'Empreinte : Stripe ne garde le montant bloqué que 7 jours ; elle est proposée au traiteur à partir de 6 jours avant la livraison, et libérée automatiquement après une reprise complète. Carte enregistrée : aucun délai, le traiteur accepte au moment d’enregistrer sa carte que la casse et les manquants puissent être débités.', 'espace-traiteur' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Paiements en ligne possibles ?
	 *
	 * @return bool
	 */
	public static function pret() {
		return ETR_Modules::actif( 'stripe' ) && '' !== self::parametres()['secrete'];
	}

	/* ------------------------------------------------------------------ */
	/* API Stripe                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Appel à l'API.
	 *
	 * @param string $methode GET | POST.
	 * @param string $chemin  Ressource (ex. « checkout/sessions »).
	 * @param array  $params  Paramètres.
	 * @param string $idem    Clé d'idempotence (créations).
	 * @return array|WP_Error
	 */
	public static function api( $methode, $chemin, $params = array(), $idem = '' ) {
		$cle = self::parametres()['secrete'];
		if ( '' === $cle ) {
			return new WP_Error( 'etr_stripe', __( 'Stripe n’est pas configuré.', 'espace-traiteur' ) );
		}
		$url  = self::API . $chemin;
		$args = array(
			'method'  => $methode,
			'timeout' => 25,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $cle,
				'Stripe-Version' => self::VERSION_API,
			),
		);
		if ( 'GET' === $methode && $params ) {
			$url .= '?' . http_build_query( $params, '', '&' );
		} elseif ( $params ) {
			$args['body'] = http_build_query( $params, '', '&' );
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
		}
		if ( $idem ) {
			$args['headers']['Idempotency-Key'] = $idem;
		}
		$reponse = wp_remote_request( $url, $args );
		if ( is_wp_error( $reponse ) ) {
			return new WP_Error( 'etr_stripe', __( 'Stripe ne répond pas. Réessayez dans un instant.', 'espace-traiteur' ) );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $reponse ), true );
		$code = (int) wp_remote_retrieve_response_code( $reponse );
		if ( $code >= 400 || ! is_array( $json ) ) {
			$message = isset( $json['error']['message'] ) ? (string) $json['error']['message'] : __( 'Erreur Stripe.', 'espace-traiteur' );
			return new WP_Error( 'etr_stripe', $message );
		}
		return $json;
	}

	/**
	 * Centimes.
	 *
	 * @param float $euros Montant.
	 * @return int
	 */
	private static function centimes( $euros ) {
		return (int) round( (float) $euros * 100 );
	}

	/* ------------------------------------------------------------------ */
	/* État des paiements d'une demande                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Paiements : type => { … }.
	 *
	 * @param int $id Demande.
	 * @return array
	 */
	public static function paiements( $id ) {
		$p = get_post_meta( $id, self::META, true );
		return is_array( $p ) ? $p : array();
	}

	/**
	 * Enregistre un paiement.
	 *
	 * @param int    $id   Demande.
	 * @param string $type acompte | solde | caution.
	 * @param array  $data Données.
	 */
	private static function noter( $id, $type, $data ) {
		$p          = self::paiements( $id );
		$p[ $type ] = array_merge( isset( $p[ $type ] ) ? $p[ $type ] : array(), $data );
		update_post_meta( $id, self::META, $p );
	}

	/**
	 * Résumé pour l'export comptable.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function resume_paiements( $id ) {
		$out = array();
		foreach ( self::paiements( $id ) as $type => $p ) {
			if ( ! empty( $p['statut'] ) ) {
				$out[] = $type . ' ' . $p['statut'] . ( isset( $p['montant'] ) ? ' ' . number_format( (float) $p['montant'], 2, ',', '' ) : '' ) . ( ! empty( $p['debite'] ) ? ' (débité ' . number_format( (float) $p['debite'], 2, ',', '' ) . ')' : '' );
			}
		}
		return implode( ' ; ', $out );
	}

	/**
	 * Montant à payer pour un type, ou 0.
	 *
	 * @param int    $id   Demande.
	 * @param string $type acompte | solde | caution.
	 * @return float
	 */
	public static function a_payer( $id, $type ) {
		$p = self::parametres();
		$a = ETR_Devis::acompte( $id );
		$s = self::paiements( $id );
		$f = class_exists( 'ETR_Facture' ) && ETR_Modules::actif( 'facture' ) ? ETR_Facture::facture( $id ) : array();
		if ( 'acompte' === $type ) {
			// Une fois la facture établie, on règle le solde (acompte compris), plus l'acompte seul.
			return $p['acompte'] && ETR_Modules::actif( 'acompte' ) && ! $f && $a['acompte'] > 0 && ! $a['acompte_recu'] ? (float) $a['acompte'] : 0.0;
		}
		if ( 'solde' === $type ) {
			$reste = $f ? ETR_Facture::reglements( $id, $f )['reste'] : 0.0;
			return $p['solde'] && $f && ! $f['payee'] && $reste > 0 ? (float) $reste : 0.0;
		}
		if ( 'caution' === $type ) {
			$deja = isset( $s['caution']['statut'] ) && in_array( $s['caution']['statut'], array( 'bloquee', 'enregistree', 'debitee' ), true );
			return 'aucune' !== $p['caution'] && ETR_Modules::actif( 'acompte' ) && $a['caution'] > 0 && ! $a['caution_recue'] && ! $deja && in_array( etr_get_statut( $id ), array( 'validee', 'livree' ), true ) ? (float) $a['caution'] : 0.0;
		}
		return 0.0;
	}

	/**
	 * Empreinte : à partir de quand peut-on la poser (6 jours avant la livraison) ?
	 *
	 * @param int $id Demande.
	 * @return string AAAA-MM-JJ, vide si déjà possible.
	 */
	public static function empreinte_a_partir_du( $id ) {
		// Stripe garde l'empreinte 7 jours : elle doit encore valoir le jour de la reprise.
		list( , $reprise ) = ETR_Operations::jours( $id );
		if ( ! $reprise ) {
			return '';
		}
		$debut = gmdate( 'Y-m-d', strtotime( $reprise . ' -6 days' ) );
		return $debut > current_time( 'Y-m-d' ) ? $debut : '';
	}

	/* ------------------------------------------------------------------ */
	/* Espace traiteur : payer                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Bouton de paiement.
	 *
	 * @param int    $id      Demande.
	 * @param string $type    Type.
	 * @param string $libelle Libellé.
	 * @return string
	 */
	private static function bouton( $id, $type, $libelle ) {
		return '<form method="post" class="etr-stripe__form" action="' . esc_url( etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) ) . '">' . wp_nonce_field( 'etr_stripe_' . $type . '_' . $id, 'etr_nonce', true, false ) . '<input type="hidden" name="etr_action" value="stripe_payer"><input type="hidden" name="type" value="' . esc_attr( $type ) . '"><input type="hidden" name="demande_id" value="' . (int) $id . '"><button type="submit" class="etr-btn">' . esc_html( $libelle ) . '</button></form>';
	}

	/**
	 * Encart « Paiement en ligne » : acompte et caution.
	 *
	 * @param int $id Demande.
	 */
	public static function encart_traiteur( $id ) {
		if ( ! self::pret() || ! etr_peut_voir_demande( $id ) ) {
			return;
		}
		$html = '';
		// phpcs:ignore WordPress.Security.NonceVerification -- message après retour de Stripe.
		$retour = isset( $_GET['etr_paiement'] ) ? sanitize_key( wp_unslash( $_GET['etr_paiement'] ) ) : '';
		if ( 'ok' === $retour ) {
			$html .= '<p class="etr-notice etr-notice--succes">' . esc_html__( 'Merci : votre paiement est enregistré.', 'espace-traiteur' ) . '</p>';
		} elseif ( 'annule' === $retour ) {
			$html .= '<p class="etr-notice etr-notice--info">' . esc_html__( 'Paiement annulé : rien n’a été débité.', 'espace-traiteur' ) . '</p>';
		}
		$acompte = self::a_payer( $id, 'acompte' );
		if ( $acompte > 0 ) {
			/* translators: %s : montant. */
			$html .= self::bouton( $id, 'acompte', sprintf( __( 'Payer l’acompte (%s)', 'espace-traiteur' ), ETR_Devis::prix( $acompte ) ) );
		}
		$caution = self::a_payer( $id, 'caution' );
		$mode    = self::parametres()['caution'];
		if ( $caution > 0 ) {
			$debut = 'empreinte' === $mode ? self::empreinte_a_partir_du( $id ) : '';
			if ( $debut ) {
				/* translators: 1 : montant, 2 : date. */
				$html .= '<p class="etr-muted">' . esc_html( sprintf( __( 'Caution de %1$s : empreinte bancaire à déposer à partir du %2$s (elle ne reste valable que 7 jours et doit couvrir la reprise).', 'espace-traiteur' ), ETR_Devis::prix( $caution ), etr_format_date( $debut ) ) ) . '</p>';
			} else {
				$html .= 'empreinte' === $mode
					/* translators: %s : montant. */
					? '<p class="etr-muted">' . esc_html( sprintf( __( 'Caution de %s : le montant est bloqué sur votre carte, pas débité. Il est libéré après la reprise du matériel complet.', 'espace-traiteur' ), ETR_Devis::prix( $caution ) ) ) . '</p>' . self::bouton( $id, 'caution', __( 'Déposer l’empreinte bancaire', 'espace-traiteur' ) )
					/* translators: %s : montant. */
					: '<p class="etr-muted">' . esc_html( sprintf( __( 'Caution de %s : votre carte est enregistrée, rien n’est débité. Seuls la casse et les manquants constatés à la reprise pourront l’être, dans cette limite.', 'espace-traiteur' ), ETR_Devis::prix( $caution ) ) ) . '</p>' . self::bouton( $id, 'caution', __( 'Enregistrer ma carte pour la caution', 'espace-traiteur' ) );
			}
		}
		$s = self::paiements( $id );
		if ( isset( $s['caution']['statut'] ) ) {
			$etats = array(
				'bloquee'     => __( 'Caution : empreinte bancaire déposée.', 'espace-traiteur' ),
				'enregistree' => __( 'Caution : carte enregistrée.', 'espace-traiteur' ),
				'liberee'     => __( 'Caution libérée.', 'espace-traiteur' ),
				/* translators: %s : montant. */
				'debitee'     => isset( $s['caution']['debite'] ) ? sprintf( __( 'Caution : %s débités pour la casse et les manquants.', 'espace-traiteur' ), ETR_Devis::prix( $s['caution']['debite'] ) ) : '',
			);
			if ( ! empty( $etats[ $s['caution']['statut'] ] ) ) {
				$html .= '<p>' . esc_html( $etats[ $s['caution']['statut'] ] ) . '</p>';
			}
		}
		if ( '' !== $html ) {
			echo '<section class="etr-panneau etr-encart-traiteur etr-stripe" id="etr-paiement"><h2 class="etr-panneau__titre">' . esc_html__( 'Paiement en ligne', 'espace-traiteur' ) . '</h2>' . $html . '<p class="etr-muted etr-stripe__secure">' . esc_html__( 'Paiement sécurisé par Stripe : vos données bancaires ne passent pas par notre site.', 'espace-traiteur' ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- construit et échappé ci-dessus.
		}
	}

	/**
	 * Bouton « Payer le solde » (encart Facture).
	 *
	 * @param int $id Demande.
	 */
	public static function bouton_solde( $id ) {
		$solde = self::pret() ? self::a_payer( $id, 'solde' ) : 0;
		if ( $solde > 0 ) {
			/* translators: %s : montant. */
			echo self::bouton( $id, 'solde', sprintf( __( 'Payer en ligne (%s)', 'espace-traiteur' ), ETR_Devis::prix( $solde ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans bouton().
		}
	}

	/**
	 * Le traiteur lance un paiement : session Stripe Checkout, puis redirection vers Stripe.
	 *
	 * @param string $action Action.
	 */
	public static function action_payer( $action ) {
		if ( 'stripe_payer' !== $action || ! self::pret() ) {
			return;
		}
		$id    = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$nonce = isset( $_POST['etr_nonce'] ) ? sanitize_key( wp_unslash( $_POST['etr_nonce'] ) ) : '';
		if ( ! in_array( $type, array( 'acompte', 'solde', 'caution' ), true ) || ! wp_verify_nonce( $nonce, 'etr_stripe_' . $type . '_' . $id ) || ! etr_peut_voir_demande( $id ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		$montant = self::a_payer( $id, $type );
		$mode    = self::parametres()['caution'];
		if ( $montant <= 0 || ( 'caution' === $type && 'empreinte' === $mode && self::empreinte_a_partir_du( $id ) ) ) {
			wp_safe_redirect( etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) );
			exit;
		}
		$session = self::creer_session( $id, $type, $montant );
		if ( is_wp_error( $session ) || empty( $session['url'] ) || 'checkout.stripe.com' !== wp_parse_url( $session['url'], PHP_URL_HOST ) ) {
			wp_die( esc_html( is_wp_error( $session ) ? $session->get_error_message() : __( 'Paiement indisponible.', 'espace-traiteur' ) ), '', array( 'response' => 502, 'back_link' => true ) );
		}
		self::noter( $id, $type, array( 'session' => $session['id'], 'montant' => $montant, 'statut' => 'en_cours', 'le' => time() ) );
		wp_redirect( $session['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- page de paiement Stripe (hôte vérifié).
		exit;
	}

	/**
	 * Crée la session Stripe Checkout.
	 *
	 * @param int    $id      Demande.
	 * @param string $type    acompte | solde | caution.
	 * @param float  $montant Montant.
	 * @return array|WP_Error
	 */
	public static function creer_session( $id, $type, $montant ) {
		$user    = wp_get_current_user();
		$retour  = etr_url( array( 'etr_vue' => 'demande', 'id' => $id, 'etr_stripe' => 'retour' ) );
		$libelle = array(
			/* translators: 1 : événement, 2 : numéro. */
			'acompte' => sprintf( __( 'Acompte — %1$s (demande n° %2$d)', 'espace-traiteur' ), get_the_title( $id ), $id ),
			/* translators: 1 : numéro de facture, 2 : événement. */
			'solde'   => sprintf( __( 'Facture %1$s — %2$s', 'espace-traiteur' ), class_exists( 'ETR_Facture' ) && ETR_Facture::facture( $id ) ? ETR_Facture::facture( $id )['numero'] : '', get_the_title( $id ) ),
			/* translators: 1 : événement, 2 : numéro. */
			'caution' => sprintf( __( 'Caution — %1$s (demande n° %2$d)', 'espace-traiteur' ), get_the_title( $id ), $id ),
		);
		$meta    = array( 'demande' => (string) $id, 'type' => $type, 'site' => home_url() );
		$params  = array(
			'success_url'         => $retour . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => etr_url( array( 'etr_vue' => 'demande', 'id' => $id, 'etr_paiement' => 'annule' ) ),
			'client_reference_id' => (string) $id,
			'locale'              => 'fr',
			'metadata'            => $meta,
		);
		$mode = self::parametres()['caution'];
		if ( 'caution' === $type && 'carte' === $mode ) {
			$client = self::api( 'POST', 'customers', array( 'email' => $user->user_email, 'name' => etr_nom( $user ), 'metadata' => $meta ), 'etr-client-' . $id . '-' . md5( $user->user_email ) );
			if ( is_wp_error( $client ) ) {
				return $client;
			}
			$raison = class_exists( 'ETR_Facture' ) ? ETR_Facture::parametres()['raison'] : '';
			$params += array(
				'mode'                 => 'setup',
				'customer'             => $client['id'],
				'currency'             => 'eur',
				'payment_method_types' => array( 'card' ),
				'setup_intent_data'    => array( 'metadata' => $meta ),
				'custom_text'          => array(
					'submit' => array(
						/* translators: 1 : société, 2 : montant. */
						'message' => sprintf( __( 'J’autorise %1$s à débiter cette carte du montant de la casse et des manquants constatés à la reprise du matériel, dans la limite de %2$s.', 'espace-traiteur' ), $raison ? $raison : etr_nom_site(), ETR_Devis::prix( $montant ) ),
					),
				),
			);
		} else {
			$params += array(
				'mode'                => 'payment',
				'customer_email'      => $user->user_email,
				'line_items'          => array(
					array(
						'quantity'   => 1,
						'price_data' => array(
							'currency'     => 'eur',
							'unit_amount'  => self::centimes( $montant ),
							'product_data' => array( 'name' => $libelle[ $type ] ),
						),
					),
				),
				'payment_intent_data' => array( 'metadata' => $meta ) + ( 'caution' === $type ? array( 'capture_method' => 'manual' ) : array() ),
			);
		}
		return self::api( 'POST', 'checkout/sessions', $params, 'etr-' . $type . '-' . $id . '-' . md5( wp_json_encode( array( $montant, $mode, $user->ID, (int) ( time() / 600 ) ) ) ) );
	}

	/**
	 * Retour de Stripe sur le site : la session est vérifiée auprès de Stripe (le webhook peut tarder).
	 */
	public static function retour() {
		// phpcs:disable WordPress.Security.NonceVerification -- identifiant vérifié auprès de Stripe.
		if ( ! isset( $_GET['etr_stripe'], $_GET['session_id'], $_GET['id'] ) || 'retour' !== $_GET['etr_stripe'] || ! self::pret() ) {
			return;
		}
		$id      = absint( $_GET['id'] );
		$session = sanitize_text_field( wp_unslash( $_GET['session_id'] ) );
		// phpcs:enable
		$ok = false;
		if ( preg_match( '/^cs_[A-Za-z0-9_]+$/', $session ) && etr_peut_voir_demande( $id ) ) {
			$objet = self::api( 'GET', 'checkout/sessions/' . rawurlencode( $session ) );
			$ok    = ! is_wp_error( $objet ) && self::enregistrer_session( $objet );
		}
		wp_safe_redirect( etr_url( array( 'etr_vue' => 'demande', 'id' => $id, 'etr_paiement' => $ok ? 'ok' : 'attente' ) ) . '#etr-paiement' );
		exit;
	}

	/**
	 * Session terminée (retour ou webhook) : paiement, empreinte ou carte enregistrés (une fois).
	 *
	 * @param array $s Session Stripe.
	 * @return bool Enregistré (ou déjà enregistré).
	 */
	public static function enregistrer_session( $s ) {
		$id   = isset( $s['metadata']['demande'] ) ? absint( $s['metadata']['demande'] ) : 0;
		$type = isset( $s['metadata']['type'] ) ? sanitize_key( $s['metadata']['type'] ) : '';
		if ( ! $id || 'etr_demande' !== get_post_type( $id ) || ! in_array( $type, array( 'acompte', 'solde', 'caution' ), true ) || ( isset( $s['metadata']['site'] ) && home_url() !== $s['metadata']['site'] ) ) {
			return false;
		}
		// Retour du traiteur et webhook en même temps : un seul enregistre (verrou par session).
		if ( ! self::verrou( 'etr_stripe_' . md5( (string) $s['id'] ) ) ) {
			return true;
		}
		try {
			return self::enregistrer_session_verrouillee( $s, $id, $type );
		} finally {
			self::deverrouiller( 'etr_stripe_' . md5( (string) $s['id'] ) );
		}
	}

	/**
	 * Verrou atomique (insertion unique, expiré après 60 s).
	 *
	 * @param string $nom Nom.
	 * @return bool Obtenu.
	 */
	private static function verrou( $nom ) {
		global $wpdb;
		$pris = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $nom, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $pris ) {
			return true;
		}
		$depuis = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $nom ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $depuis && time() - $depuis > 60 ) {
			self::deverrouiller( $nom );
			return (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $nom, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return false;
	}

	/**
	 * Libère un verrou.
	 *
	 * @param string $nom Nom.
	 */
	private static function deverrouiller( $nom ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $nom ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Enregistrement d'une session, sous verrou.
	 *
	 * @param array  $s    Session Stripe.
	 * @param int    $id   Demande.
	 * @param string $type Type.
	 * @return bool
	 */
	private static function enregistrer_session_verrouillee( $s, $id, $type ) {
		wp_cache_delete( $id, 'post_meta' ); // Relire l'état réel (l'autre requête vient peut-être d'écrire).
		$deja = self::paiements( $id );
		if ( isset( $deja[ $type ]['session'], $deja[ $type ]['statut'] ) && $deja[ $type ]['session'] === $s['id'] && 'en_cours' !== $deja[ $type ]['statut'] ) {
			return true; // Déjà enregistré (webhook et retour).
		}
		$aujourdhui = current_time( 'Y-m-d' );
		$acompte    = ETR_Devis::acompte( $id );
		$traiteur   = etr_nom( get_userdata( (int) get_post_field( 'post_author', $id ) ) );

		if ( 'setup' === $s['mode'] ) {
			$si = self::api( 'GET', 'setup_intents/' . rawurlencode( (string) $s['setup_intent'] ) );
			if ( is_wp_error( $si ) || 'succeeded' !== $si['status'] ) {
				return false;
			}
			self::noter( $id, 'caution', array( 'session' => $s['id'], 'mode' => 'carte', 'client' => $si['customer'] ? $si['customer'] : $s['customer'], 'carte' => $si['payment_method'], 'montant' => (float) $acompte['caution'], 'statut' => 'enregistree', 'le' => time() ) );
			$acompte['caution_recue'] = $aujourdhui;
			update_post_meta( $id, ETR_Devis::META_ACOMPTE, $acompte );
			ETR_Historique::ajouter( $id, 'paiement', __( 'Caution : carte enregistrée en ligne (Stripe)', 'espace-traiteur' ), 0 );
			/* translators: %s : traiteur. */
			ETR_Push::notifier( 'bureau', sprintf( __( 'Caution enregistrée : %s', 'espace-traiteur' ), $traiteur ), get_the_title( $id ), $id, '', 'paiement' );
			return true;
		}

		$pi = self::api( 'GET', 'payment_intents/' . rawurlencode( (string) $s['payment_intent'] ) );
		if ( is_wp_error( $pi ) ) {
			return false;
		}
		$montant = $pi['amount'] / 100;
		if ( 'caution' === $type ) {
			if ( 'requires_capture' !== $pi['status'] ) {
				return false;
			}
			self::noter( $id, 'caution', array( 'session' => $s['id'], 'mode' => 'empreinte', 'pi' => $pi['id'], 'montant' => $montant, 'statut' => 'bloquee', 'expire' => (int) $pi['created'] + 7 * DAY_IN_SECONDS, 'le' => time() ) );
			$acompte['caution_recue'] = $aujourdhui;
			update_post_meta( $id, ETR_Devis::META_ACOMPTE, $acompte );
			/* translators: %s : montant. */
			ETR_Historique::ajouter( $id, 'paiement', sprintf( __( 'Caution : empreinte bancaire de %s déposée (Stripe)', 'espace-traiteur' ), ETR_Devis::prix( $montant ) ), 0 );
			/* translators: %s : traiteur. */
			ETR_Push::notifier( 'bureau', sprintf( __( 'Empreinte déposée : %s', 'espace-traiteur' ), $traiteur ), get_the_title( $id ) . ' · ' . ETR_Devis::prix( $montant ), $id, '', 'paiement' );
			return true;
		}
		if ( 'succeeded' !== $pi['status'] ) {
			return false;
		}
		self::noter( $id, $type, array( 'session' => $s['id'], 'pi' => $pi['id'], 'montant' => $montant, 'statut' => 'paye', 'le' => time() ) );
		if ( 'acompte' === $type ) {
			$acompte['acompte_recu'] = $aujourdhui;
			update_post_meta( $id, ETR_Devis::META_ACOMPTE, $acompte );
		} elseif ( class_exists( 'ETR_Facture' ) ) {
			ETR_Facture::marquer_payee( $id, $aujourdhui );
		}
		/* translators: 1 : type, 2 : montant. */
		ETR_Historique::ajouter( $id, 'paiement', sprintf( __( '%1$s payé en ligne : %2$s (Stripe)', 'espace-traiteur' ), 'acompte' === $type ? __( 'Acompte', 'espace-traiteur' ) : __( 'Solde de la facture', 'espace-traiteur' ), ETR_Devis::prix( $montant ) ), 0 );
		/* translators: 1 : type, 2 : traiteur. */
		ETR_Push::notifier( 'bureau', sprintf( __( '%1$s payé : %2$s', 'espace-traiteur' ), 'acompte' === $type ? __( 'Acompte', 'espace-traiteur' ) : __( 'Facture', 'espace-traiteur' ), $traiteur ), get_the_title( $id ) . ' · ' . ETR_Devis::prix( $montant ), $id, '', 'paiement' );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Webhook                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Route du webhook.
	 */
	public static function routes() {
		register_rest_route(
			ETR_Push::API,
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Authentifié par la signature Stripe.
			)
		);
	}

	/**
	 * Signature Stripe valide ? (HMAC-SHA256 de « horodatage.contenu », 5 minutes de tolérance.)
	 *
	 * @param string $contenu Corps brut.
	 * @param string $entete  En-tête Stripe-Signature.
	 * @param string $secret  Secret du webhook.
	 * @param int    $maintenant Horodatage courant (tests).
	 * @return bool
	 */
	public static function signature_valide( $contenu, $entete, $secret, $maintenant = 0 ) {
		$maintenant = $maintenant ? $maintenant : time();
		$t          = 0;
		$v1         = array();
		foreach ( explode( ',', (string) $entete ) as $partie ) {
			$kv = explode( '=', trim( $partie ), 2 );
			if ( 2 === count( $kv ) ) {
				if ( 't' === $kv[0] ) {
					$t = (int) $kv[1];
				} elseif ( 'v1' === $kv[0] ) {
					$v1[] = $kv[1];
				}
			}
		}
		if ( ! $t || ! $v1 || '' === $secret || abs( $maintenant - $t ) > 300 ) {
			return false;
		}
		$attendu = hash_hmac( 'sha256', $t . '.' . $contenu, $secret );
		foreach ( $v1 as $signature ) {
			if ( hash_equals( $attendu, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Événements Stripe.
	 *
	 * @param WP_REST_Request $requete Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function webhook( $requete ) {
		$p = self::parametres();
		if ( ! ETR_Modules::actif( 'stripe' ) || ! self::signature_valide( $requete->get_body(), (string) $requete->get_header( 'stripe_signature' ), $p['webhook'] ) ) {
			return new WP_Error( 'etr_signature', 'Signature invalide.', array( 'status' => 400 ) );
		}
		$evenement = json_decode( $requete->get_body(), true );
		$type      = isset( $evenement['type'] ) ? $evenement['type'] : '';
		if ( in_array( $type, array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) && isset( $evenement['data']['object'] ) ) {
			self::enregistrer_session( $evenement['data']['object'] );
		}
		return rest_ensure_response( array( 'recu' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Bureau : libérer ou débiter la caution                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Panneau « Paiements en ligne » de la fiche.
	 *
	 * @param int $id Demande.
	 */
	public static function panneau( $id ) {
		if ( ! ETR_Modules::actif( 'stripe' ) ) {
			return;
		}
		$s = self::paiements( $id );
		if ( ! $s ) {
			return;
		}
		echo '<section class="etr-panneau etr-stripe"><h2 class="etr-panneau__titre">' . esc_html__( 'Paiements en ligne (Stripe)', 'espace-traiteur' ) . '</h2><ul class="etr-liste-simple">';
		$libelles = array( 'acompte' => __( 'Acompte', 'espace-traiteur' ), 'solde' => __( 'Solde de la facture', 'espace-traiteur' ), 'caution' => __( 'Caution', 'espace-traiteur' ) );
		$etats    = array( 'en_cours' => __( 'commencé, pas terminé', 'espace-traiteur' ), 'paye' => __( 'payé', 'espace-traiteur' ), 'bloquee' => __( 'empreinte bloquée', 'espace-traiteur' ), 'enregistree' => __( 'carte enregistrée', 'espace-traiteur' ), 'liberee' => __( 'libérée', 'espace-traiteur' ), 'debitee' => __( 'débitée', 'espace-traiteur' ) );
		foreach ( $s as $type => $p ) {
			$texte = $libelles[ $type ] . ' : ' . ( isset( $etats[ $p['statut'] ] ) ? $etats[ $p['statut'] ] : $p['statut'] ) . ( isset( $p['montant'] ) ? ' · ' . ETR_Devis::prix( $p['montant'] ) : '' );
			if ( ! empty( $p['debite'] ) ) {
				/* translators: %s : montant. */
				$texte .= ' · ' . sprintf( __( '%s débités', 'espace-traiteur' ), ETR_Devis::prix( $p['debite'] ) );
			}
			if ( 'bloquee' === $p['statut'] && ! empty( $p['expire'] ) ) {
				/* translators: %s : date. */
				$texte .= ' · ' . sprintf( __( 'expire le %s', 'espace-traiteur' ), wp_date( 'j F à H:i', (int) $p['expire'] ) );
			}
			echo '<li>' . esc_html( $texte ) . '</li>';
		}
		echo '</ul>';
		if ( isset( $s['caution']['statut'] ) && in_array( $s['caution']['statut'], array( 'bloquee', 'enregistree' ), true ) && ETR_Equipe::bureau() ) {
			$suggere = class_exists( 'ETR_Devis' ) && ETR_Modules::actif( 'casse' ) ? ETR_Devis::releve( $id )['total'] : 0;
			if ( class_exists( 'ETR_Facture' ) && ETR_Modules::actif( 'facture' ) && ETR_Facture::facture( $id ) ) {
				// La casse est sur la facture : le montant débité en sera déduit (jamais payée deux fois).
				echo '<p class="etr-muted">' . esc_html__( 'Le montant débité sur la caution est déduit du reste à payer de la facture.', 'espace-traiteur' ) . '</p>';
			}
			$url     = ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id ) );
			$champs  = wp_nonce_field( 'etr_caution_' . $id, 'etr_nonce', true, false ) . '<input type="hidden" name="etr_gestion_action" value="caution"><input type="hidden" name="demande_id" value="' . (int) $id . '">';
			echo '<form method="post" class="etr-stripe__debit" action="' . esc_url( $url ) . '" data-confirmer="' . esc_attr__( 'Débiter ce montant sur la carte du traiteur ?', 'espace-traiteur' ) . '">' . $champs . '<input type="hidden" name="quoi" value="debiter"><label>' . esc_html__( 'Débiter', 'espace-traiteur' ) . ' <input type="text" inputmode="decimal" name="montant" size="7" value="' . esc_attr( $suggere > 0 ? number_format( min( $suggere, (float) $s['caution']['montant'] ), 2, ',', '' ) : '' ) . '" required> €</label> <button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Débiter', 'espace-traiteur' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<form method="post" action="' . esc_url( $url ) . '" data-confirmer="' . esc_attr__( 'Libérer la caution ? Rien ne pourra plus être débité.', 'espace-traiteur' ) . '">' . $champs . '<input type="hidden" name="quoi" value="liberer"><button type="submit" class="etr-btn etr-btn--discret">' . esc_html__( 'Libérer la caution', 'espace-traiteur' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</section>';
	}

	/**
	 * Libère la caution.
	 *
	 * @param int $id Demande.
	 * @return true|WP_Error
	 */
	public static function liberer( $id ) {
		$c = self::paiements( $id )['caution'] ?? array();
		if ( empty( $c['statut'] ) || ! in_array( $c['statut'], array( 'bloquee', 'enregistree' ), true ) ) {
			return true;
		}
		$r = 'bloquee' === $c['statut']
			? self::api( 'POST', 'payment_intents/' . rawurlencode( $c['pi'] ) . '/cancel', array(), 'etr-liberer-' . $c['pi'] )
			: self::api( 'POST', 'payment_methods/' . rawurlencode( $c['carte'] ) . '/detach', array(), 'etr-liberer-' . $c['carte'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::noter( $id, 'caution', array( 'statut' => 'liberee', 'libere_le' => time() ) );
		$a                   = ETR_Devis::acompte( $id );
		$a['caution_rendue'] = current_time( 'Y-m-d' );
		update_post_meta( $id, ETR_Devis::META_ACOMPTE, $a );
		ETR_Historique::ajouter( $id, 'paiement', __( 'Caution libérée (Stripe)', 'espace-traiteur' ) );
		return true;
	}

	/**
	 * Débite la caution (casse, manquants).
	 *
	 * @param int   $id      Demande.
	 * @param float $montant Montant.
	 * @return true|WP_Error
	 */
	public static function debiter( $id, $montant ) {
		$c       = self::paiements( $id )['caution'] ?? array();
		$montant = round( (float) $montant, 2 );
		if ( empty( $c['statut'] ) || ! in_array( $c['statut'], array( 'bloquee', 'enregistree' ), true ) ) {
			return new WP_Error( 'etr_caution', __( 'Pas de caution à débiter.', 'espace-traiteur' ) );
		}
		$f = class_exists( 'ETR_Facture' ) && ETR_Modules::actif( 'facture' ) ? ETR_Facture::facture( $id ) : array();
		if ( $f && $f['payee'] ) {
			return new WP_Error( 'etr_caution', __( 'La facture, casse et manquants compris, est déjà réglée : libérez la caution.', 'espace-traiteur' ) );
		}
		if ( $montant <= 0 || $montant > (float) $c['montant'] ) {
			/* translators: %s : montant de la caution. */
			return new WP_Error( 'etr_caution', sprintf( __( 'Montant à débiter entre 0,01 € et %s.', 'espace-traiteur' ), ETR_Devis::prix( $c['montant'] ) ) );
		}
		if ( 'bloquee' === $c['statut'] ) {
			$r = self::api( 'POST', 'payment_intents/' . rawurlencode( $c['pi'] ) . '/capture', array( 'amount_to_capture' => self::centimes( $montant ) ), 'etr-debit-' . $c['pi'] . '-' . wp_generate_uuid4() );
			$ok = ! is_wp_error( $r ) && 'succeeded' === $r['status'];
		} else {
			$r  = self::api(
				'POST',
				'payment_intents',
				array(
					'amount'         => self::centimes( $montant ),
					'currency'       => 'eur',
					'customer'       => $c['client'],
					'payment_method' => $c['carte'],
					'off_session'    => 'true',
					'confirm'        => 'true',
					/* translators: %d : numéro. */
					'description'    => sprintf( __( 'Casse et manquants — demande n° %d', 'espace-traiteur' ), $id ),
					'metadata'       => array( 'demande' => (string) $id, 'type' => 'caution' ),
				),
				'etr-debit-' . $id . '-' . wp_generate_uuid4() // Nouvelle tentative après un refus : la carte est vraiment réessayée.
			);
			$ok = ! is_wp_error( $r ) && 'succeeded' === $r['status'];
		}
		if ( ! $ok ) {
			return is_wp_error( $r ) ? $r : new WP_Error( 'etr_caution', __( 'Le débit n’a pas abouti (carte refusée ou authentification demandée).', 'espace-traiteur' ) );
		}
		self::noter( $id, 'caution', array( 'statut' => 'debitee', 'debite' => $montant, 'debite_le' => time() ) );
		/* translators: %s : montant. */
		ETR_Historique::ajouter( $id, 'paiement', sprintf( __( 'Caution débitée de %s (Stripe)', 'espace-traiteur' ), ETR_Devis::prix( $montant ) ) );
		/* translators: %s : montant. */
		ETR_Traiteur_Direct::prevenir( $id, __( 'Caution débitée', 'espace-traiteur' ), sprintf( __( '%1$s : %2$s ont été débités pour la casse et les manquants constatés à la reprise.', 'espace-traiteur' ), get_the_title( $id ), ETR_Devis::prix( $montant ) ) );
		return true;
	}

	/**
	 * Actions du bureau sur la caution.
	 *
	 * @param string $action Action.
	 */
	public static function action_bureau( $action ) {
		if ( 'caution' !== $action || ! self::pret() ) {
			return;
		}
		$id = isset( $_POST['demande_id'] ) ? absint( $_POST['demande_id'] ) : 0;
		check_admin_referer( 'etr_caution_' . $id, 'etr_nonce' );
		if ( ! ETR_Gestion::demande_visible( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$quoi = isset( $_POST['quoi'] ) ? sanitize_key( wp_unslash( $_POST['quoi'] ) ) : '';
		$r    = 'liberer' === $quoi ? self::liberer( $id ) : self::debiter( $id, (float) etr_lire_nombre( isset( $_POST['montant'] ) ? sanitize_text_field( wp_unslash( $_POST['montant'] ) ) : '' ) );
		if ( is_wp_error( $r ) ) {
			set_transient( 'etr_erreur_dates_' . get_current_user_id(), $r->get_error_message(), 60 );
		}
		wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'demande', 'id' => $id, 'etr_msg' => is_wp_error( $r ) ? 'stripe_erreur' : 'fait' ) ) );
		exit;
	}

	/**
	 * Reprise complète (ni casse ni manquant) : la caution est libérée.
	 *
	 * @param int   $id     Demande.
	 * @param array $lignes index => { rendu, casse }.
	 */
	public static function reprise_complete( $id, $lignes ) {
		if ( ! self::pret() ) {
			return;
		}
		foreach ( etr_get_lignes( $id ) as $i => $l ) {
			if ( ! isset( $lignes[ $i ] ) || (int) $lignes[ $i ]['casse'] > 0 || (int) $lignes[ $i ]['rendu'] < (int) $l['quantite'] ) {
				return; // Écarts : le bureau décide.
			}
		}
		self::liberer( $id );
	}

	/**
	 * Montants par défaut de l'acompte et de la caution, à la validation.
	 *
	 * @param int    $id      Demande.
	 * @param string $ancien  Ancien statut.
	 * @param string $nouveau Nouveau statut.
	 */
	public static function montants_par_defaut( $id, $ancien, $nouveau ) {
		if ( 'validee' !== $nouveau || ! ETR_Modules::actif( 'acompte' ) ) {
			return;
		}
		$d = self::defauts_montants();
		$a = ETR_Devis::acompte( $id );
		$change = false;
		if ( null === $a['acompte'] && $d['pct'] > 0 ) {
			$total = etr_chiffrage( $id )['total'];
			if ( $total > 0 ) {
				$a['acompte'] = round( $total * $d['pct'] / 100, 2 );
				$change       = true;
			}
		}
		if ( null === $a['caution'] && $d['caution'] > 0 ) {
			$a['caution'] = (float) $d['caution'];
			$change       = true;
		}
		if ( $change ) {
			update_post_meta( $id, ETR_Devis::META_ACOMPTE, $a );
		}
	}
}
