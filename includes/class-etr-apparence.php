<?php
/**
 * Couleurs de l'application de l'équipe et de l'espace traiteur, réglables dans
 * Espace traiteur › Réglages › Couleurs. Elles remplacent les variables CSS des feuilles
 * de style (mode clair ; le mode sombre garde ses couleurs), la couleur de la barre d'état
 * (manifestes) et celle des e-mails.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Couleurs.
 */
class ETR_Apparence {

	const OPTION = 'etr_couleurs';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ), 30 );
		add_filter( 'etr_mail_couleurs', array( __CLASS__, 'couleurs_mail' ) );
	}

	/**
	 * Couleurs par défaut et libellés.
	 *
	 * @return array clé => { défaut, libellé, espace }
	 */
	public static function champs() {
		return array(
			'equipe_accent'   => array( '#4d5b3e', __( 'Couleur principale (boutons, liens, onglet actif)', 'espace-traiteur' ), 'equipe' ),
			'equipe_entete'   => array( '#2c3424', __( 'Menu et en-tête', 'espace-traiteur' ), 'equipe' ),
			'equipe_fond'     => array( '#faf9f6', __( 'Fond des pages', 'espace-traiteur' ), 'equipe' ),
			'traiteur_accent' => array( '#4d5b3e', __( 'Couleur principale (boutons, liens, étapes)', 'espace-traiteur' ), 'traiteur' ),
			'traiteur_menu'   => array( '#efebe4', __( 'Menu et en-tête', 'espace-traiteur' ), 'traiteur' ),
			'traiteur_encre'  => array( '#1d1d1b', __( 'Titres et texte foncé', 'espace-traiteur' ), 'traiteur' ),
		);
	}

	/**
	 * Couleurs enregistrées.
	 *
	 * @return array<string,string>
	 */
	public static function couleurs() {
		$enreg  = get_option( self::OPTION, array() );
		$enreg  = is_array( $enreg ) ? $enreg : array();
		$retour = array();
		foreach ( self::champs() as $cle => $champ ) {
			$valeur         = isset( $enreg[ $cle ] ) ? sanitize_hex_color( $enreg[ $cle ] ) : '';
			$retour[ $cle ] = $valeur ? $valeur : $champ[0];
		}
		return $retour;
	}

	/**
	 * Déclare l'option (page Réglages).
	 */
	public static function reglages() {
		register_setting(
			'etr_reglages',
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => function ( $saisie ) {
					$propre = array();
					foreach ( array_keys( self::champs() ) as $cle ) {
						$valeur = isset( $saisie[ $cle ] ) ? sanitize_hex_color( $saisie[ $cle ] ) : '';
						if ( $valeur ) {
							$propre[ $cle ] = $valeur;
						}
					}
					return ! empty( $saisie['reinitialiser'] ) ? array() : $propre;
				},
			)
		);
	}

	/**
	 * Champs de la page Réglages.
	 */
	public static function champs_reglages() {
		$c      = self::couleurs();
		$titres = array(
			'equipe'   => __( 'Application de l’équipe', 'espace-traiteur' ),
			'traiteur' => __( 'Espace traiteur (et e-mails)', 'espace-traiteur' ),
		);
		echo '<tr><th scope="row" colspan="2"><h2 style="margin:16px 0 0">' . esc_html__( 'Couleurs', 'espace-traiteur' ) . '</h2></th></tr>';
		foreach ( $titres as $espace => $titre ) {
			echo '<tr><th scope="row">' . esc_html( $titre ) . '</th><td><fieldset>';
			foreach ( self::champs() as $cle => $champ ) {
				if ( $champ[2] !== $espace ) {
					continue;
				}
				echo '<p><label><input type="color" name="' . esc_attr( self::OPTION . '[' . $cle . ']' ) . '" value="' . esc_attr( $c[ $cle ] ) . '" style="width:48px;height:32px;padding:0;vertical-align:middle"> ' . esc_html( $champ[1] ) . ' <code>' . esc_html( $c[ $cle ] ) . '</code></label></p>';
			}
			echo '</fieldset></td></tr>';
		}
		echo '<tr><th scope="row"></th><td><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[reinitialiser]" value="1"> ' . esc_html__( 'Revenir aux couleurs d’origine', 'espace-traiteur' ) . '</label><p class="description">' . esc_html__( 'Les nuances (survol, fonds clairs) sont calculées à partir de ces couleurs. Le mode sombre de l’application garde ses propres couleurs.', 'espace-traiteur' ) . '</p></td></tr>';
	}

	/**
	 * Mélange deux couleurs.
	 *
	 * @param string $a     Couleur.
	 * @param string $b     Couleur.
	 * @param float  $poids Part de $b (0 à 1).
	 * @return string
	 */
	public static function melanger( $a, $b, $poids ) {
		$ca = sscanf( $a, '#%02x%02x%02x' );
		$cb = sscanf( $b, '#%02x%02x%02x' );
		$r  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$r[] = (int) round( $ca[ $i ] * ( 1 - $poids ) + $cb[ $i ] * $poids );
		}
		return vsprintf( '#%02x%02x%02x', $r );
	}

	/**
	 * Variables CSS d'un espace.
	 *
	 * @param string $espace equipe | traiteur.
	 * @return string
	 */
	public static function variables( $espace ) {
		$c = self::couleurs();
		if ( 'equipe' === $espace ) {
			$v = array(
				'--etr-olive'       => $c['equipe_accent'],
				'--etr-olive-fonce' => self::melanger( $c['equipe_accent'], '#000000', 0.22 ),
				'--etr-olive-clair' => self::melanger( $c['equipe_accent'], '#ffffff', 0.9 ),
				'--etr-nuit'        => $c['equipe_entete'],
				'--etr-voile'       => $c['equipe_fond'],
			);
		} else {
			$v = array(
				'--etr-olive'       => $c['traiteur_accent'],
				'--etr-olive-fonce' => self::melanger( $c['traiteur_accent'], '#000000', 0.22 ),
				'--etr-olive-clair' => self::melanger( $c['traiteur_accent'], '#ffffff', 0.9 ),
				'--etr-lin'         => $c['traiteur_menu'],
				'--etr-encre'       => $c['traiteur_encre'],
			);
		}
		$css = '';
		foreach ( $v as $nom => $valeur ) {
			$css .= $nom . ':' . $valeur . ';';
		}
		return $css;
	}

	/**
	 * Feuille de style des couleurs, après celles du plugin (mode clair seulement).
	 */
	public static function styles() {
		$defauts = array();
		foreach ( self::champs() as $cle => $champ ) {
			$defauts[ $cle ] = $champ[0];
		}
		if ( self::couleurs() === $defauts ) {
			return; // Couleurs d'origine : rien à ajouter.
		}
		if ( class_exists( 'ETR_Gestion' ) && ETR_Gestion::actif() && wp_style_is( 'espace-traiteur-gestion', 'enqueued' ) ) {
			wp_add_inline_style( 'espace-traiteur-gestion', '@media not all and (prefers-color-scheme: dark){body .etr-gestion,body .etr-gestion .etr-app{' . self::variables( 'equipe' ) . '}}' );
		} elseif ( wp_style_is( 'espace-traiteur', 'enqueued' ) ) {
			wp_add_inline_style( 'espace-traiteur', 'body .etr-app,body .etr-auth{' . self::variables( 'traiteur' ) . '}' );
		}
	}

	/**
	 * Couleur de la barre d'état de l'application installée.
	 *
	 * @param string $espace equipe | traiteur.
	 * @return string
	 */
	public static function barre( $espace ) {
		$c = self::couleurs();
		return 'equipe' === $espace ? $c['equipe_entete'] : $c['traiteur_menu'];
	}

	/**
	 * Couleurs des e-mails : celles de l'espace traiteur.
	 *
	 * @param array $couleurs Couleurs.
	 * @return array
	 */
	public static function couleurs_mail( $couleurs ) {
		$c                  = self::couleurs();
		$couleurs['accent'] = $c['traiteur_accent'];
		$couleurs['encre']  = $c['traiteur_encre'];
		$couleurs['fond']   = self::melanger( $c['traiteur_menu'], '#ffffff', 0.35 );
		$couleurs['clair']  = self::melanger( $c['traiteur_menu'], '#ffffff', 0.6 );
		return $couleurs;
	}
}
