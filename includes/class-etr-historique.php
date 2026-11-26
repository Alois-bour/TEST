<?php
/**
 * Historique d'une demande : qui a fait quoi, et quand.
 *
 * Chaque événement est ajouté à la méta « _etr_historique » (liste d'entrées
 * date / user / type / texte). Les entrées sont écrites par les hooks du plugin,
 * on ne les modifie jamais après coup.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Historique des demandes.
 */
class ETR_Historique {

	const META = '_etr_historique';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'etr_demande_creee', array( __CLASS__, 'creee' ) );
		add_action( 'etr_demande_annulee', array( __CLASS__, 'annulee' ) );
		add_action( 'etr_demande_modifiee', array( __CLASS__, 'modifiee' ) );
		add_action( 'etr_statut_change', array( __CLASS__, 'statut' ), 10, 3 );
		add_action( 'etr_reponse_modifiee', array( __CLASS__, 'reponse' ), 10, 2 );
	}

	/**
	 * Ajoute une entrée.
	 *
	 * @param int    $demande_id Demande.
	 * @param string $type       creation|statut|message|annulation|modification|tarif|rappel…
	 * @param string $texte      Description lisible.
	 * @param int    $user_id    Auteur (par défaut l'utilisateur connecté, 0 = automatique).
	 */
	public static function ajouter( $demande_id, $type, $texte, $user_id = null ) {
		$entrees   = self::entrees( $demande_id );
		$entrees[] = array(
			'date' => time(),
			'user' => null === $user_id ? get_current_user_id() : (int) $user_id,
			'type' => sanitize_key( $type ),
			'text' => (string) $texte,
		);
		update_post_meta( $demande_id, self::META, $entrees );
	}

	/**
	 * Entrées, de la plus ancienne à la plus récente.
	 *
	 * @param int $demande_id Demande.
	 * @return array<int,array>
	 */
	public static function entrees( $demande_id ) {
		$entrees = get_post_meta( $demande_id, self::META, true );
		return is_array( $entrees ) ? array_values( $entrees ) : array();
	}

	/**
	 * Demande envoyée.
	 *
	 * @param int $id Demande.
	 */
	public static function creee( $id ) {
		self::ajouter( $id, 'creation', __( 'Demande envoyée', 'espace-traiteur' ) );
	}

	/**
	 * Demande annulée par le traiteur.
	 *
	 * @param int $id Demande.
	 */
	public static function annulee( $id ) {
		self::ajouter( $id, 'annulation', __( 'Demande annulée par le traiteur', 'espace-traiteur' ) );
	}

	/**
	 * Demande modifiée par le traiteur.
	 *
	 * @param int $id Demande.
	 */
	public static function modifiee( $id ) {
		self::ajouter( $id, 'modification', __( 'Demande modifiée par le traiteur', 'espace-traiteur' ) );
	}

	/**
	 * Changement de statut.
	 *
	 * @param int    $id      Demande.
	 * @param string $ancien  Ancien statut.
	 * @param string $nouveau Nouveau statut.
	 */
	public static function statut( $id, $ancien, $nouveau ) {
		$statuts = etr_statuts();
		self::ajouter(
			$id,
			'statut',
			/* translators: 1 : ancien statut, 2 : nouveau statut. */
			sprintf( __( 'Statut : %1$s → %2$s', 'espace-traiteur' ), isset( $statuts[ $ancien ] ) ? $statuts[ $ancien ] : $ancien, isset( $statuts[ $nouveau ] ) ? $statuts[ $nouveau ] : $nouveau )
		);
	}

	/**
	 * Message au traiteur modifié.
	 *
	 * @param int    $id      Demande.
	 * @param string $reponse Nouveau message.
	 */
	public static function reponse( $id, $reponse ) {
		if ( '' === trim( $reponse ) ) {
			self::ajouter( $id, 'message', __( 'Message au traiteur effacé', 'espace-traiteur' ) );
			return;
		}
		/* translators: %s : message. */
		self::ajouter( $id, 'message', sprintf( __( 'Message au traiteur : « %s »', 'espace-traiteur' ), wp_trim_words( $reponse, 30, '…' ) ) );
	}

	/**
	 * Liste HTML (la plus récente en premier).
	 *
	 * @param int $demande_id Demande.
	 * @param int $max        Entrées affichées d'emblée (0 : toutes) ; les plus anciennes sont repliées.
	 * @return string
	 */
	public static function html( $demande_id, $max = 0 ) {
		$entrees = array_reverse( self::entrees( $demande_id ) );
		if ( ! $entrees ) {
			return '<p class="etr-muted">' . esc_html__( 'Aucun événement enregistré (demande antérieure à l’historique).', 'espace-traiteur' ) . '</p>';
		}
		if ( $max > 0 && count( $entrees ) > $max ) {
			$anciennes = count( $entrees ) - $max;
			/* translators: %s : nombre. */
			$suite = '<details class="etr-historique__suite"><summary class="etr-lien">' . esc_html( sprintf( _n( 'Voir l’événement plus ancien', 'Voir les %s événements plus anciens', $anciennes, 'espace-traiteur' ), number_format_i18n( $anciennes ) ) ) . '</summary>' . self::liste( array_slice( $entrees, $max ) ) . '</details>';
			return self::liste( array_slice( $entrees, 0, $max ) ) . $suite;
		}
		return self::liste( $entrees );
	}

	/**
	 * Entrées en liste HTML.
	 *
	 * @param array[] $entrees Entrées (plus récentes d'abord).
	 * @return string
	 */
	private static function liste( $entrees ) {
		$html = '<ol class="etr-historique">';
		foreach ( $entrees as $entree ) {
			$user  = ! empty( $entree['user'] ) ? get_userdata( (int) $entree['user'] ) : false;
			$qui   = $user ? etr_nom( $user ) : __( 'Automatique', 'espace-traiteur' );
			$date  = isset( $entree['date'] ) ? (int) $entree['date'] : 0;
			$type  = isset( $entree['type'] ) ? $entree['type'] : '';
			$html .= '<li class="etr-historique__entree etr-historique__entree--' . esc_attr( $type ) . '">';
			$html .= '<span class="etr-historique__texte">' . esc_html( isset( $entree['text'] ) ? $entree['text'] : '' ) . '</span>';
			$html .= '<span class="etr-historique__meta"><time datetime="' . esc_attr( gmdate( 'c', $date ) ) . '">' . esc_html( wp_date( 'j M Y, H:i', $date ) ) . '</time> · ' . esc_html( $qui ) . '</span>';
			$html .= '</li>';
		}
		return $html . '</ol>';
	}
}
