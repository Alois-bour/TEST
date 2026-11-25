<?php
/**
 * E-mails HTML : tous les e-mails du plugin (traiteurs, équipe, mots de passe, devis…) partent
 * dans une mise en page aux couleurs du site, avec la version texte en alternative.
 *
 * Les e-mails sont écrits en texte simple ; la mise en forme est déduite de leur structure :
 * « Bonjour … , » (salutation), « Libellé : valeur » (tableau d'informations), « - article »
 * (liste), « Libellé : https://… » ou « Libellé : » suivi d'une adresse (bouton).
 * Un e-mail est concerné s'il porte l'en-tête « X-ETR-Mail » (ajouté par etr_envoyer_mail()
 * et par les e-mails de mot de passe de l'espace traiteur et de l'application).
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mise en page des e-mails.
 */
class ETR_Mail {

	const ENTETE = 'X-ETR-Mail: 1';

	/**
	 * Version texte du message en cours (alternative du HTML).
	 *
	 * @var string
	 */
	private static $texte = '';

	/**
	 * Un e-mail du plugin est déjà parti pendant cette requête.
	 *
	 * @var bool
	 */
	private static $deja = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'mettre_en_forme' ), 50 );
		add_action( 'admin_post_etr_apercu_mail', array( __CLASS__, 'apercu' ) );
	}

	/**
	 * Couleurs (filtrables).
	 *
	 * @return array
	 */
	private static function couleurs() {
		return (array) apply_filters(
			'etr_mail_couleurs',
			array(
				'fond'    => '#f4f0ea',
				'carte'   => '#ffffff',
				'encre'   => '#1d1d1b',
				'gris'    => '#6b655f',
				'trait'   => '#e8e2d8',
				'accent'  => '#4d5b3e',
				'clair'   => '#f6f3ee',
			)
		);
	}

	/**
	 * Filtre wp_mail : met en forme les e-mails du plugin.
	 *
	 * @param array $atts { to, subject, message, headers, attachments }.
	 * @return array
	 */
	public static function mettre_en_forme( $atts ) {
		$entetes = $atts['headers'];
		$liste   = is_array( $entetes ) ? $entetes : array_filter( explode( "\n", str_replace( "\r\n", "\n", (string) $entetes ) ) );
		$marque  = false;
		foreach ( $liste as $i => $entete ) {
			if ( 0 === stripos( trim( $entete ), 'X-ETR-Mail:' ) ) {
				$marque = true;
				unset( $liste[ $i ] );
			} elseif ( 0 === stripos( trim( $entete ), 'Content-Type:' ) ) {
				unset( $liste[ $i ] ); // Remplacé ci-dessous.
			}
		}
		if ( ! $marque ) {
			// Un e-mail du plugin envoyé juste avant ne doit pas laisser sa version texte à celui-ci.
			if ( self::$deja ) {
				add_action( 'phpmailer_init', array( __CLASS__, 'sans_alternative' ), 1 );
			}
			return $atts;
		}
		self::$deja = true;
		if ( ! ETR_Modules::actif( 'mails_html' ) ) {
			$atts['headers'] = array_values( $liste );
			return $atts;
		}
		self::$texte        = (string) $atts['message'];
		$liste[]            = 'Content-Type: text/html; charset=UTF-8';
		$atts['headers']    = array_values( $liste );
		$atts['message']    = self::html( (string) $atts['subject'], self::$texte );
		add_action( 'phpmailer_init', array( __CLASS__, 'alternative' ) );
		return $atts;
	}

	/**
	 * Version texte en alternative (messageries sans HTML, filtres anti-indésirables).
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Envoi.
	 */
	public static function alternative( $phpmailer ) {
		remove_action( 'phpmailer_init', array( __CLASS__, 'alternative' ) );
		$phpmailer->AltBody = self::$texte; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		self::$texte        = '';
	}

	/**
	 * E-mail d'une autre extension : pas de version texte héritée.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Envoi.
	 */
	public static function sans_alternative( $phpmailer ) {
		remove_action( 'phpmailer_init', array( __CLASS__, 'sans_alternative' ), 1 );
		$phpmailer->AltBody = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}

	/**
	 * Titre affiché : l'objet sans le préfixe « [Site] ».
	 *
	 * @param string $objet Objet.
	 * @return string
	 */
	private static function titre( $objet ) {
		return trim( preg_replace( '/^\[[^\]]*\]\s*/u', '', wp_specialchars_decode( $objet, ENT_QUOTES ) ) );
	}

	/**
	 * Adresse seule sur la ligne ?
	 *
	 * @param string $ligne Ligne.
	 * @return bool
	 */
	private static function est_url( $ligne ) {
		return (bool) preg_match( '#^https?://\S+$#i', trim( $ligne ) );
	}

	/**
	 * Bouton.
	 *
	 * @param string $libelle Libellé.
	 * @param string $url     Adresse.
	 * @param bool   $premier Bouton principal (plein) ; sinon contour.
	 * @return string
	 */
	private static function bouton( $libelle, $url, $premier ) {
		$c      = self::couleurs();
		$fond   = $premier ? $c['accent'] : $c['carte'];
		$texte  = $premier ? '#ffffff' : $c['accent'];
		$html   = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 14px"><tr><td style="border-radius:999px;background:' . $fond . ';border:1.5px solid ' . $c['accent'] . '">';
		$html  .= '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:13px 26px;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;line-height:1.2;color:' . $texte . ';text-decoration:none;border-radius:999px">' . esc_html( $libelle ) . '</a>';
		$html  .= '</td></tr></table>';
		$html  .= '<p style="margin:0 0 18px;font-size:12px;line-height:1.5;color:' . $c['gris'] . ';word-break:break-all">' . esc_html__( 'Ou copiez cette adresse :', 'espace-traiteur' ) . ' <a href="' . esc_url( $url ) . '" style="color:' . $c['gris'] . '">' . esc_html( $url ) . '</a></p>';
		return $html;
	}

	/**
	 * Corps HTML depuis le texte.
	 *
	 * @param string $texte Texte.
	 * @return array { html, apercu } apercu : première phrase (texte d'aperçu des messageries).
	 */
	private static function corps( $texte ) {
		$c       = self::couleurs();
		$p       = 'margin:0 0 16px;font-size:15px;line-height:1.6;color:' . $c['encre'];
		$blocs   = preg_split( '/\n\s*\n/', trim( str_replace( "\r\n", "\n", $texte ) ) );
		$html    = '';
		$apercu  = '';
		$boutons = 0;
		$label   = '';
		foreach ( $blocs as $bloc ) {
			$lignes = array_values( array_filter( array_map( 'rtrim', explode( "\n", $bloc ) ), 'strlen' ) );
			$infos  = array();
			$liste  = array();
			$texte_bloc = array();
			$suite  = false; // La ligne suivante peut prolonger la dernière information.
			$vider  = function () use ( &$infos, &$liste, &$texte_bloc, &$html, $c, $p ) {
				if ( $texte_bloc ) {
					// « Message de notre équipe : » suivi du message : encadré.
					if ( count( $texte_bloc ) > 1 && ':' === substr( $texte_bloc[0], -1 ) ) {
						$html .= '<p style="margin:0 0 8px;font-size:13px;font-weight:600;letter-spacing:.02em;color:' . $c['gris'] . '">' . esc_html( rtrim( array_shift( $texte_bloc ), ' :' ) ) . '</p>';
						$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px"><tr><td style="border-left:3px solid ' . $c['accent'] . ';background:' . $c['clair'] . ';padding:12px 16px;font-size:15px;line-height:1.6;color:' . $c['encre'] . ';border-radius:0 10px 10px 0">' . implode( '<br>', array_map( 'esc_html', $texte_bloc ) ) . '</td></tr></table>';
					} else {
						$html .= '<p style="' . $p . '">' . implode( '<br>', array_map( 'esc_html', $texte_bloc ) ) . '</p>';
					}
					$texte_bloc = array();
				}
				if ( $infos ) {
					$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px;border-collapse:separate;background:' . $c['clair'] . ';border-radius:12px">';
					foreach ( $infos as $n => $info ) {
						$bord  = $n ? 'border-top:1px solid ' . $c['trait'] . ';' : '';
						$html .= '<tr><td style="' . $bord . 'padding:10px 16px;font-size:13px;line-height:1.4;color:' . $c['gris'] . ';width:38%;vertical-align:top">' . esc_html( $info[0] ) . '</td><td style="' . $bord . 'padding:10px 16px 10px 0;font-size:14px;line-height:1.45;color:' . $c['encre'] . ';font-weight:600;vertical-align:top">' . nl2br( esc_html( $info[1] ) ) . '</td></tr>';
					}
					$html .= '</table>';
					$infos = array();
				}
				if ( $liste ) {
					$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px">';
					foreach ( $liste as $element ) {
						$html .= '<tr><td style="padding:7px 0;border-bottom:1px solid ' . $c['trait'] . ';font-size:14px;line-height:1.45;color:' . $c['encre'] . '">' . esc_html( $element ) . '</td></tr>';
					}
					$html .= '</table>';
					$liste = array();
				}
			};
			foreach ( $lignes as $i => $ligne ) {
				$t        = trim( $ligne );
				$precede  = $suite;
				$suite    = false;
				// « Libellé : https://… » → bouton.
				if ( preg_match( '#^(.{2,80}?)\s*:\s*(https?://\S+)$#u', $t, $m ) ) {
					$vider();
					$html .= self::bouton( $m[1], $m[2], 0 === $boutons++ );
					continue;
				}
				// Adresse seule : bouton, avec la ligne précédente « … : » comme libellé.
				if ( self::est_url( $t ) ) {
					$libelle = $label;
					if ( $texte_bloc && ':' === substr( end( $texte_bloc ), -1 ) ) {
						$libelle = array_pop( $texte_bloc );
					}
					$vider();
					$libelle = trim( rtrim( $libelle, ' :' ) );
					// Phrase longue (« Choisissez votre mot de passe (lien valable 24 h) : ») : gardée au-dessus.
					if ( mb_strlen( $libelle ) > 38 ) {
						$html   .= '<p style="' . $p . '">' . esc_html( $libelle . '.' ) . '</p>';
						$libelle = self::libelle_court( $libelle, $t );
					}
					$html .= self::bouton( '' !== $libelle ? $libelle : __( 'Ouvrir', 'espace-traiteur' ), $t, 0 === $boutons++ );
					$label = '';
					continue;
				}
				// Liste « - article ».
				if ( preg_match( '/^[-•]\s+(.+)$/u', $t, $m ) ) {
					if ( $texte_bloc || $infos ) {
						$vider();
					}
					$liste[] = $m[1];
					continue;
				}
				// « Libellé : valeur » (court) → tableau d'informations.
				if ( preg_match( '/^([^:]{2,40}?)\s+:\s+(.+)$/u', $t, $m ) && ! preg_match( '/[.!?]$/u', $m[1] ) && count( preg_split( '/\s+/u', $m[1] ) ) <= 5 ) {
					if ( $texte_bloc || $liste ) {
						$vider();
					}
					$infos[] = array( $m[1], $m[2] );
					$suite   = true;
					continue;
				}
				// Salutation.
				if ( 0 === $i && preg_match( '/^(Bonjour|Bonsoir)\b.*,$/u', $t ) ) {
					$vider();
					$html .= '<p style="margin:0 0 14px;font-size:16px;line-height:1.5;color:' . $c['encre'] . ';font-weight:600">' . esc_html( $t ) . '</p>';
					continue;
				}
				// Suite d'une valeur sur plusieurs lignes (adresse) : rattachée à l'information précédente.
				if ( $precede && $infos && ! preg_match( '/:$/', $t ) ) {
					$infos[ count( $infos ) - 1 ][1] .= "\n" . $t;
					$suite                            = true;
					continue;
				}
				if ( '' === $apercu && ! preg_match( '/:$/', $t ) ) {
					$apercu = $t;
				}
				if ( $infos || $liste ) {
					$vider();
				}
				$texte_bloc[] = $t;
			}
			// Bloc terminé par « … : » : libellé du bouton du bloc suivant.
			if ( $texte_bloc && ':' === substr( end( $texte_bloc ), -1 ) && ! $infos && ! $liste ) {
				$label = array_pop( $texte_bloc );
			}
			$vider();
		}
		return array( 'html' => $html, 'apercu' => $apercu );
	}

	/**
	 * Libellé de bouton court à partir d'une phrase.
	 *
	 * @param string $phrase Phrase.
	 * @param string $url    Adresse du bouton.
	 * @return string
	 */
	private static function libelle_court( $phrase, $url = '' ) {
		$p = mb_strtolower( $phrase );
		if ( false !== strpos( $url, 'etr_bon_livraison=' ) ) {
			return __( 'Télécharger le bon de livraison', 'espace-traiteur' );
		}
		if ( false !== strpos( $url, 'etr_document=releve' ) || false !== strpos( $p, 'relevé' ) ) {
			return __( 'Voir le relevé', 'espace-traiteur' );
		}
		if ( false !== strpos( $p, 'accepter' ) ) {
			return __( 'Voir et accepter le devis', 'espace-traiteur' );
		}
		if ( false !== strpos( $p, 'mot de passe' ) ) {
			return __( 'Choisir mon mot de passe', 'espace-traiteur' );
		}
		if ( false !== strpos( $p, 'install' ) || false !== strpos( $p, 'application' ) ) {
			return __( 'Ouvrir l’application', 'espace-traiteur' );
		}
		if ( false !== strpos( $p, 'connect' ) ) {
			return __( 'Accéder à mon espace', 'espace-traiteur' );
		}
		if ( false !== strpos( $p, 'devis' ) || false !== strpos( $p, 'espace' ) ) {
			return __( 'Ouvrir mon espace', 'espace-traiteur' );
		}
		return __( 'Ouvrir', 'espace-traiteur' );
	}

	/**
	 * E-mail complet (HTML).
	 *
	 * @param string $objet Objet.
	 * @param string $texte Texte.
	 * @return string
	 */
	public static function html( $objet, $texte ) {
		$c       = self::couleurs();
		$site    = etr_nom_site();
		$titre   = self::titre( $objet );
		$corps   = self::corps( $texte );
		$logo_id = ETR_Comptes::logo_id();
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$serif   = "Georgia,'Times New Roman',serif";
		$sans    = 'Helvetica,Arial,sans-serif';

		$h  = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>' . esc_html( $titre ) . '</title></head>';
		$h .= '<body style="margin:0;padding:0;background:' . $c['fond'] . ';-webkit-text-size-adjust:100%">';
		// Texte d'aperçu (affiché par la messagerie sous l'objet).
		$h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . esc_html( $corps['apercu'] ) . str_repeat( '&#8204;&nbsp;', 40 ) . '</div>';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $c['fond'] . '"><tr><td align="center" style="padding:28px 14px">';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;font-family:' . $sans . '">';
		// En-tête : logo ou nom du site.
		$h .= '<tr><td align="center" style="padding:6px 0 22px">';
		$h .= $logo
			? '<a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site ) . '" width="160" style="display:block;width:160px;max-width:60%;height:auto;border:0"></a>'
			: '<a href="' . esc_url( home_url( '/' ) ) . '" style="font-family:' . $serif . ';font-size:26px;font-style:italic;color:' . $c['encre'] . ';text-decoration:none">' . esc_html( $site ) . '</a>';
		$h .= '</td></tr>';
		// Carte.
		$h .= '<tr><td style="background:' . $c['carte'] . ';border-radius:18px;border:1px solid ' . $c['trait'] . ';overflow:hidden">';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="height:5px;background:' . $c['accent'] . ';font-size:0;line-height:0">&nbsp;</td></tr></table>';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:30px 32px 14px">';
		$h .= '<h1 style="margin:0 0 20px;font-family:' . $serif . ';font-size:26px;line-height:1.25;font-weight:400;font-style:italic;color:' . $c['encre'] . '">' . esc_html( $titre ) . '</h1>';
		$h .= $corps['html'];
		$h .= '</td></tr></table></td></tr>';
		// Pied.
		$h .= '<tr><td align="center" style="padding:22px 20px 6px;font-size:12px;line-height:1.6;color:' . $c['gris'] . '">';
		$h .= '<strong style="color:' . $c['encre'] . '">' . esc_html( $site ) . '</strong><br>';
		$h .= '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . $c['gris'] . ';text-decoration:underline">' . esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( home_url() ) ) ) . '</a>';
		$pied = (string) apply_filters( 'etr_mail_pied', __( 'Cet e-mail vous est envoyé automatiquement par l’espace traiteur.', 'espace-traiteur' ) );
		if ( '' !== $pied ) {
			$h .= '<br>' . esc_html( $pied );
		}
		$h .= '</td></tr></table></td></tr></table></body></html>';
		return $h;
	}

	/**
	 * Aperçu dans le navigateur (Réglages › E-mails).
	 */
	public static function apercu() {
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'etr_apercu_mail' );
		$site  = etr_nom_site();
		$texte = __( 'Bonjour Julie Martin,', 'espace-traiteur' ) . "\n\n"
			. __( 'Votre demande #128 (Mariage Dupont) est désormais : Validée.', 'espace-traiteur' ) . "\n\n"
			. __( 'Message de notre équipe :', 'espace-traiteur' ) . "\n" . __( 'Merci de prévoir un accès camion pour le déchargement.', 'espace-traiteur' ) . "\n\n"
			. __( 'Date de l’événement', 'espace-traiteur' ) . ' : ' . date_i18n( 'l j F Y', strtotime( '+12 days' ) ) . "\n"
			. __( 'Lieu', 'espace-traiteur' ) . " : Château de la Roche, 54600 Villers-lès-Nancy\n"
			. __( 'Nombre d’invités', 'espace-traiteur' ) . " : 120\n\n"
			. __( 'Articles demandés :', 'espace-traiteur' ) . "\n- Table ronde 1,80 m × 15\n- Assiette plate porcelaine × 120\n- Verre à vin × 120\n\n"
			. __( 'Voir la demande :', 'espace-traiteur' ) . ' ' . etr_url() . "\n";
		/* translators: %s : site. */
		$objet = sprintf( __( '[%s] Votre demande #128 : Validée', 'espace-traiteur' ), $site );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo self::html( $objet, $texte ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans html().
		exit;
	}
}
