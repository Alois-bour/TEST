<?php
/**
 * Notifications par e-mail.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * E-mails envoyés à l'équipe et aux traiteurs.
 */
class ETR_Notifications {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'etr_demande_creee', array( __CLASS__, 'nouvelle_demande' ) );
		add_action( 'etr_demande_annulee', array( __CLASS__, 'demande_annulee' ) );
		add_action( 'etr_statut_change', array( __CLASS__, 'statut_change' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'planifier_rappels' ) );
		add_action( 'etr_rappels_horaires', array( __CLASS__, 'envoyer_rappels' ) );
	}

	/**
	 * Planifie aussi les rappels sur les installations déjà actives.
	 */
	public static function planifier_rappels() {
		if ( ! wp_next_scheduled( 'etr_rappels_horaires' ) ) {
			$resultat = wp_schedule_event( time(), 'hourly', 'etr_rappels_horaires', array(), true );
			if ( is_wp_error( $resultat ) ) {
				error_log( 'Espace Traiteur : planification des rappels impossible : ' . $resultat->get_error_message() );
			}
		}
	}

	/**
	 * Traite les rappels du jour ; les échecs seront retentés au prochain passage.
	 */
	public static function envoyer_rappels() {
		global $wpdb;
		$verrou = 'etr_rappels_' . md5( $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $verrou ) ) ) {
			return;
		}

		try {
			$aujourdhui = current_datetime();
			$livraison  = $aujourdhui->modify( '+3 days' )->format( 'Y-m-d' );
			$evenement  = $aujourdhui->modify( '-1 day' )->format( 'Y-m-d' );
			$ids = get_posts(
				array(
					'post_type'        => 'etr_demande',
					'post_status'      => 'publish',
					'numberposts'      => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'meta_query'       => array(
						'relation' => 'AND',
						array(
							'key'     => '_etr_statut',
							'value'   => array( 'validee', 'livree' ),
							'compare' => 'IN',
						),
						array(
							'relation' => 'OR',
							array( 'key' => '_etr_date_livraison', 'value' => $livraison ),
							array( 'key' => '_etr_date_evenement', 'value' => $livraison ),
							array( 'key' => '_etr_date_evenement', 'value' => $evenement ),
						),
					),
				)
			);
			update_meta_cache( 'post', $ids );
			foreach ( $ids as $id ) {
				$date_event = (string) get_post_meta( $id, '_etr_date_evenement', true );
				$date_livraison = (string) get_post_meta( $id, '_etr_date_livraison', true );
				if ( ! $date_livraison ) {
					$date_livraison = $date_event;
				}
				if ( 'validee' === etr_get_statut( $id ) && $date_livraison === $livraison ) {
					self::envoyer_rappel( $id, 'livraison', $livraison );
				}
				if ( $date_event === $evenement ) {
					self::envoyer_rappel( $id, 'reprise', $evenement );
				}
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $verrou ) );
		}
	}

	/**
	 * @param int    $id   Demande.
	 * @param string $type livraison ou reprise.
	 * @param string $date Date de référence du rappel.
	 */
	private static function envoyer_rappel( $id, $type, $date ) {
		$cle = '_etr_rappel_' . $type;
		if ( get_post_meta( $id, $cle, true ) === $date ) {
			return;
		}
		$user = get_userdata( (int) get_post_field( 'post_author', $id ) );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			error_log( 'Espace Traiteur : rappel ' . $type . ' impossible, destinataire invalide pour la demande #' . (int) $id );
			return;
		}
		$objet = 'livraison' === $type
			? sprintf( __( '[%1$s] Livraison de votre demande #%2$d dans trois jours', 'espace-traiteur' ), self::site(), $id )
			: sprintf( __( '[%1$s] Reprise du matériel de votre demande #%2$d', 'espace-traiteur' ), self::site(), $id );
		$corps = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n";
		$corps .= 'livraison' === $type
			? __( 'Votre livraison est prévue dans trois jours.', 'espace-traiteur' )
			: __( 'Votre événement est passé ; pensez à la reprise du matériel.', 'espace-traiteur' );
		$corps .= "\n\n" . self::resume( $id );
		$corps .= "\n" . __( 'Voir la demande :', 'espace-traiteur' ) . ' ' . etr_url( array( 'etr_vue' => 'demande', 'id' => $id ) ) . "\n";
		if ( wp_mail( $user->user_email, $objet, $corps ) ) {
			update_post_meta( $id, $cle, $date );
		} else {
			error_log( 'Espace Traiteur : échec du rappel ' . $type . ' pour la demande #' . (int) $id );
		}
	}

	/**
	 * Destinataire(s) côté équipe.
	 *
	 * @return string|string[]
	 */
	private static function equipe() {
		$email = get_option( 'etr_email_equipe' );
		return apply_filters( 'etr_email_equipe', is_email( $email ) ? $email : get_option( 'admin_email' ) );
	}

	/**
	 * Nom du site pour les objets d'e-mail.
	 *
	 * @return string
	 */
	private static function site() {
		return wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * Résumé texte d'une demande.
	 *
	 * @param int $demande_id ID.
	 * @return string
	 */
	private static function resume( $demande_id ) {
		$texte = '';
		foreach ( etr_champs_demande() as $cle => $champ ) {
			$valeur = (string) get_post_meta( $demande_id, '_etr_' . $cle, true );
			if ( '' === $valeur ) {
				continue;
			}
			if ( 'date' === $champ['type'] ) {
				$valeur = etr_format_date( $valeur );
			}
			$texte .= $champ['label'] . ' : ' . $valeur . "\n";
		}

		$lignes = etr_get_lignes( $demande_id );
		if ( $lignes ) {
			$texte .= "\n" . __( 'Articles demandés :', 'espace-traiteur' ) . "\n";
			foreach ( $lignes as $ligne ) {
				$texte .= sprintf( "- %s × %s%s\n", $ligne['titre'], number_format_i18n( $ligne['quantite'] ), $ligne['unite'] ? ' ' . $ligne['unite'] : '' );
			}
		}
		return $texte;
	}

	/**
	 * Nouvelle demande → équipe.
	 *
	 * @param int $demande_id ID.
	 */
	public static function nouvelle_demande( $demande_id ) {
		$user  = get_userdata( (int) get_post_field( 'post_author', $demande_id ) );
		$nom   = etr_nom( $user );
		/* translators: 1 : site, 2 : numéro, 3 : traiteur. */
		$objet = sprintf( __( '[%1$s] Nouvelle demande #%2$d de %3$s', 'espace-traiteur' ), self::site(), $demande_id, $nom );

		/* translators: %s : traiteur. */
		$corps  = sprintf( __( '%s a envoyé une nouvelle demande.', 'espace-traiteur' ), $nom ) . "\n\n";
		$corps .= self::resume( $demande_id );
		$corps .= "\n" . __( 'Traiter la demande :', 'espace-traiteur' ) . ' ' . admin_url( 'post.php?post=' . $demande_id . '&action=edit' ) . "\n";

		$entetes = array();
		if ( $user && is_email( $user->user_email ) ) {
			$entetes[] = 'Reply-To: ' . $nom . ' <' . $user->user_email . '>';
		}

		wp_mail( self::equipe(), $objet, $corps, $entetes );
	}

	/**
	 * Demande annulée par le traiteur → équipe.
	 *
	 * @param int $demande_id ID.
	 */
	public static function demande_annulee( $demande_id ) {
		$user = get_userdata( (int) get_post_field( 'post_author', $demande_id ) );
		$nom  = etr_nom( $user );
		/* translators: 1 : site, 2 : numéro, 3 : traiteur. */
		$objet = sprintf( __( '[%1$s] Demande #%2$d annulée par %3$s', 'espace-traiteur' ), self::site(), $demande_id, $nom );
		$corps = self::resume( $demande_id ) . "\n" . admin_url( 'post.php?post=' . $demande_id . '&action=edit' ) . "\n";
		wp_mail( self::equipe(), $objet, $corps );
	}

	/**
	 * Changement de statut → traiteur.
	 *
	 * @param int    $demande_id ID.
	 * @param string $ancien     Ancien statut.
	 * @param string $nouveau    Nouveau statut.
	 */
	public static function statut_change( $demande_id, $ancien, $nouveau ) {
		$user = get_userdata( (int) get_post_field( 'post_author', $demande_id ) );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$statuts = etr_statuts();
		$label   = isset( $statuts[ $nouveau ] ) ? $statuts[ $nouveau ] : $nouveau;

		/* translators: 1 : site, 2 : numéro, 3 : statut. */
		$objet = sprintf( __( '[%1$s] Votre demande #%2$d : %3$s', 'espace-traiteur' ), self::site(), $demande_id, $label );

		/* translators: %s : prénom/nom. */
		$corps  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n";
		/* translators: 1 : numéro, 2 : événement, 3 : statut. */
		$corps .= sprintf( __( 'Votre demande #%1$d (%2$s) est désormais : %3$s.', 'espace-traiteur' ), $demande_id, get_the_title( $demande_id ), $label ) . "\n";

		$reponse = get_post_meta( $demande_id, '_etr_reponse', true );
		if ( $reponse ) {
			$corps .= "\n" . __( 'Message de notre équipe :', 'espace-traiteur' ) . "\n" . $reponse . "\n";
		}
		$corps .= "\n" . __( 'Voir la demande :', 'espace-traiteur' ) . ' ' . etr_url(
			array(
				'etr_vue' => 'demande',
				'id'      => $demande_id,
			)
		) . "\n";

		wp_mail( $user->user_email, $objet, $corps );
	}
}
