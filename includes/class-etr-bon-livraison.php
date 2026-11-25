<?php
/**
 * Bon de livraison signé (PDF) : matériel livré, colis, réception, signature et photos.
 *
 * Envoyé au traiteur par e-mail dès la livraison enregistrée sur le terrain, et téléchargeable
 * depuis la fiche (équipe) et depuis l'espace traiteur (le traiteur de la demande). C'est la
 * preuve de livraison en cas de désaccord.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bon de livraison.
 */
class ETR_Bon_Livraison {

	const MARGE  = 42;
	const PHOTOS = 6;

	/**
	 * Hooks.
	 */
	public static function init() {
		// Fonction activable : vérifiée à chaque usage (un filtre « etr_module_actif » du thème compte).
		add_action( 'etr_livree', array( __CLASS__, 'envoyer' ) );
		add_action( 'template_redirect', array( __CLASS__, 'telechargement_traiteur' ), 5 );
		add_action( 'etr_traiteur_demande_apres_suivi', array( __CLASS__, 'lien_traiteur' ) );
	}

	/**
	 * Adresse du bon dans l'application de l'équipe.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function url( $id ) {
		return ETR_Gestion::url(
			array(
				'vue' => 'bon-livraison',
				'id'  => (int) $id,
			)
		);
	}

	/**
	 * Adresse du bon dans l'espace traiteur.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function url_traiteur( $id ) {
		return add_query_arg( 'etr_bon_livraison', (int) $id, etr_url() );
	}

	/**
	 * Lien de téléchargement dans l'espace traiteur, une fois la livraison signée.
	 *
	 * @param int $id Demande.
	 */
	public static function lien_traiteur( $id ) {
		if ( ! ETR_Modules::actif( 'bon_livraison' ) || ! ETR_Operations::livraison( $id ) ) {
			return;
		}
		echo '<p class="etr-bon-livraison"><a class="etr-lien" href="' . esc_url( self::url_traiteur( $id ) ) . '">' . esc_html__( 'Télécharger le bon de livraison signé (PDF)', 'espace-traiteur' ) . '</a></p>';
	}

	/**
	 * Téléchargement par le traiteur (ou l'équipe) depuis le site : ?etr_bon_livraison=ID.
	 */
	public static function telechargement_traiteur() {
		if ( ! isset( $_GET['etr_bon_livraison'] ) || ! ETR_Modules::actif( 'bon_livraison' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id      = absint( $_GET['etr_bon_livraison'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$user_id = get_current_user_id();
		$permis  = $user_id && 'etr_demande' === get_post_type( $id ) && 'publish' === get_post_status( $id )
			&& ( (int) get_post_field( 'post_author', $id ) === $user_id || ( ETR_Equipe::acces() && ETR_Gestion::demande_visible( $id ) ) );
		if ( ! $permis || ! ETR_Operations::livraison( $id ) ) {
			wp_die( esc_html__( 'Bon de livraison introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		self::servir( $id );
	}

	/**
	 * Envoie le PDF au navigateur, puis arrête.
	 *
	 * @param int $id Demande.
	 */
	public static function servir( $id ) {
		$pdf = self::pdf( $id );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . self::nom_fichier( $id ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- document PDF.
		exit;
	}

	/**
	 * Nom du fichier.
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function nom_fichier( $id ) {
		return 'bon-livraison-' . (int) $id . '-' . sanitize_title( get_the_title( $id ) ) . '.pdf';
	}

	/**
	 * Envoie le bon au traiteur (pièce jointe), dès la livraison signée.
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	public static function envoyer( $id ) {
		if ( ! ETR_Modules::actif( 'bon_livraison' ) ) {
			return false;
		}
		$user = get_userdata( (int) get_post_field( 'post_author', $id ) );
		if ( ! $user || ! is_email( $user->user_email ) || ! ETR_Operations::livraison( $id ) ) {
			return false;
		}
		$dossier = trailingslashit( get_temp_dir() ) . 'etr-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dossier ) ) {
			return false;
		}
		$fichier = $dossier . '/' . self::nom_fichier( $id );
		file_put_contents( $fichier, self::pdf( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$livraison = ETR_Operations::livraison( $id );
		$site      = etr_nom_site();
		/* translators: 1 : site, 2 : événement. */
		$objet  = sprintf( __( '[%1$s] Bon de livraison — %2$s', 'espace-traiteur' ), $site, get_the_title( $id ) );
		/* translators: %s : prénom et nom. */
		$corps  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n";
		/* translators: 1 : événement, 2 : date et heure, 3 : personne qui a signé. */
		$corps .= sprintf( __( 'Le matériel de votre demande « %1$s » a été livré le %2$s et réceptionné par %3$s.', 'espace-traiteur' ), get_the_title( $id ), wp_date( 'j F Y à H:i', $livraison['le'] ), $livraison['signataire'] ) . "\n\n";
		$corps .= __( 'Vous trouverez le bon de livraison signé en pièce jointe. Il reste disponible dans votre espace :', 'espace-traiteur' ) . "\n" . self::url_traiteur( $id ) . "\n";
		$envoye = etr_envoyer_mail( $user->user_email, $objet, $corps, array(), array( $fichier ) );

		wp_delete_file( $fichier );
		@rmdir( $dossier ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		if ( $envoye ) {
			ETR_Historique::ajouter( $id, 'livraison', __( 'Bon de livraison signé envoyé au traiteur', 'espace-traiteur' ), 0 );
		}
		return $envoye;
	}

	/**
	 * Image (fichier ou contenu) → JPEG sur fond blanc, réduite.
	 *
	 * @param string $source  Chemin ou contenu.
	 * @param int    $max     Plus grand côté (pixels).
	 * @param bool   $contenu $source est le contenu.
	 * @return string JPEG, ou '' si impossible (GD absent, image illisible).
	 */
	public static function jpeg( $source, $max, $contenu = false ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		$octets = $contenu ? $source : ( is_readable( $source ) ? (string) file_get_contents( $source ) : '' );
		$image  = '' !== $octets ? @imagecreatefromstring( $octets ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $image ) {
			return '';
		}
		$l       = imagesx( $image );
		$h       = imagesy( $image );
		$echelle = min( 1, $max / max( $l, $h ) );
		$copie   = imagecreatetruecolor( max( 1, (int) round( $l * $echelle ) ), max( 1, (int) round( $h * $echelle ) ) );
		imagefill( $copie, 0, 0, imagecolorallocate( $copie, 255, 255, 255 ) ); // Transparence (signature, logo) : fond blanc.
		imagecopyresampled( $copie, $image, 0, 0, 0, 0, imagesx( $copie ), imagesy( $copie ), $l, $h );
		ob_start();
		imagejpeg( $copie, null, 82 );
		$jpeg = (string) ob_get_clean();
		imagedestroy( $image );
		imagedestroy( $copie );
		return $jpeg;
	}

	/**
	 * Le bon de livraison (PDF).
	 *
	 * @param int $id Demande.
	 * @return string
	 */
	public static function pdf( $id ) {
		$livraison = ETR_Operations::livraison( $id );
		$auteur    = get_userdata( (int) get_post_field( 'post_author', $id ) );
		$bilan     = ETR_Operations::bilan_colis( $id );
		$m         = self::MARGE;
		$largeur   = ETR_PDF::LARGEUR - 2 * $m;
		$encre     = '#1d1d1b';
		$gris      = '#6b655f';
		/* translators: %d : numéro de demande. */
		$pdf = new ETR_PDF( sprintf( __( 'Bon de livraison n° %d', 'espace-traiteur' ), $id ) );

		// En-tête : logo (ou nom du site) et titre.
		$y       = $m;
		$logo_id = ETR_Comptes::logo_id();
		$logo    = $logo_id ? self::jpeg( get_attached_file( $logo_id ), 400 ) : '';
		if ( $logo ) {
			$pdf->image( $logo, $m, $y, 110 );
		} else {
			$pdf->texte( $m, $y + 18, etr_nom_site(), 18, true, $encre );
		}
		$pdf->texte( $m + $largeur, $y + 14, __( 'BON DE LIVRAISON', 'espace-traiteur' ), 15, true, $encre, 'droite' );
		/* translators: %d : numéro. */
		$pdf->texte( $m + $largeur, $y + 30, sprintf( __( 'Demande n° %d', 'espace-traiteur' ), $id ), 10, false, $gris, 'droite' );
		if ( $livraison ) {
			/* translators: %s : date et heure. */
			$pdf->texte( $m + $largeur, $y + 44, sprintf( __( 'Livrée le %s', 'espace-traiteur' ), wp_date( 'j F Y à H:i', $livraison['le'] ) ), 10, false, $gris, 'droite' );
		}
		$y += 70;
		$pdf->trait( $m, $y, $m + $largeur, $y, '#1d1d1b', 1 );
		$y += 24;

		// Traiteur et événement (deux colonnes).
		$col   = $largeur / 2 - 10;
		$y_g   = $y;
		$y_d   = $y;
		$pdf->texte( $m, $y_g, __( 'TRAITEUR', 'espace-traiteur' ), 8, true, $gris );
		$y_g = $pdf->paragraphe( $m, $y_g + 15, $col, etr_nom( $auteur ), 11, true );
		if ( $auteur ) {
			$contact = trim( get_user_meta( $auteur->ID, 'etr_contact', true ) . ' ' . get_user_meta( $auteur->ID, 'etr_telephone', true ) );
			$y_g     = $pdf->paragraphe( $m, $y_g, $col, trim( $contact . "\n" . $auteur->user_email ), 9.5, false, '#3a3732' );
		}
		$x_d = $m + $largeur / 2 + 10;
		$pdf->texte( $x_d, $y_d, __( 'ÉVÉNEMENT', 'espace-traiteur' ), 8, true, $gris );
		$y_d = $pdf->paragraphe( $x_d, $y_d + 15, $col, get_the_title( $id ), 11, true );
		$evenement = (string) get_post_meta( $id, '_etr_date_evenement', true );
		$lieu      = (string) get_post_meta( $id, '_etr_lieu', true );
		$contact   = (string) get_post_meta( $id, '_etr_contact', true );
		$details   = array_filter( array( $evenement ? etr_format_date( $evenement ) : '', $lieu, $contact ? __( 'Contact sur place :', 'espace-traiteur' ) . ' ' . $contact : '' ) );
		$y_d       = $pdf->paragraphe( $x_d, $y_d, $col, implode( "\n", $details ), 9.5, false, '#3a3732' );
		$y         = max( $y_g, $y_d ) + 18;

		// Matériel livré.
		$pdf->texte( $m, $y, __( 'MATÉRIEL LIVRÉ', 'espace-traiteur' ), 8, true, $gris );
		$y += 10;
		$pdf->rectangle( $m, $y, $largeur, 20, '#efebe4' );
		$pdf->texte( $m + 8, $y + 13.5, __( 'Article', 'espace-traiteur' ), 9, true );
		$pdf->texte( $m + $largeur - 8, $y + 13.5, __( 'Quantité', 'espace-traiteur' ), 9, true, $encre, 'droite' );
		$y    += 20;
		$total = 0;
		foreach ( etr_get_lignes( $id ) as $i => $ligne ) {
			if ( $y > ETR_PDF::HAUTEUR - 80 ) {
				$pdf->page();
				$y = $m;
			}
			$lignes = ETR_PDF::couper( $ligne['titre'], 10, $largeur - 120 );
			$haut   = 8 + 13.5 * count( $lignes );
			if ( $i % 2 ) {
				$pdf->rectangle( $m, $y, $largeur, $haut, '#faf9f6' );
			}
			foreach ( $lignes as $n => $texte ) {
				$pdf->texte( $m + 8, $y + 14 + 13.5 * $n, $texte, 10 );
			}
			$quantite = (int) $ligne['quantite'];
			$total   += $quantite;
			$pdf->texte( $m + $largeur - 8, $y + 14, number_format_i18n( $quantite ) . ( empty( $ligne['unite'] ) ? '' : ' ' . $ligne['unite'] ), 10, false, $encre, 'droite' );
			$y += $haut;
			$pdf->trait( $m, $y, $m + $largeur, $y, '#e3ddd3', 0.5 );
		}
		$y += 16;
		/* translators: %s : nombre d'articles. */
		$resume = sprintf( _n( '%s article', '%s articles', $total, 'espace-traiteur' ), number_format_i18n( $total ) );
		if ( $bilan['colis'] ) {
			/* translators: 1 : nombre de colis, 2 : colis scannés au chargement. */
			$resume .= ' · ' . sprintf( __( '%1$s colis (%2$s scannés au chargement)', 'espace-traiteur' ), number_format_i18n( $bilan['colis'] ), number_format_i18n( $bilan['sortis'] ) );
		}
		$pdf->texte( $m + $largeur - 8, $y, $resume, 9.5, true, $encre, 'droite' );
		$y += 30;

		// Réception et signature.
		if ( $y > ETR_PDF::HAUTEUR - 200 ) {
			$pdf->page();
			$y = $m;
		}
		$pdf->texte( $m, $y, __( 'RÉCEPTION', 'espace-traiteur' ), 8, true, $gris );
		$y += 16;
		if ( $livraison ) {
			/* translators: 1 : date et heure, 2 : livreur, 3 : personne qui a signé. */
			$y = $pdf->paragraphe( $m, $y, $largeur, sprintf( __( 'Livré le %1$s par %2$s. Matériel réceptionné par %3$s, qui a signé ci-dessous.', 'espace-traiteur' ), wp_date( 'j F Y à H:i', $livraison['le'] ), etr_nom( get_userdata( (int) $livraison['par'] ) ), $livraison['signataire'] ), 10 );
			$chemin    = empty( $livraison['signature'] ) ? '' : ETR_Fichiers::chemin( $livraison['signature'] );
			$signature = $chemin ? self::jpeg( $chemin, 700 ) : '';
			if ( $signature ) {
				$h = $pdf->image( $signature, $m, $y + 4, 190 );
				$pdf->trait( $m, $y + 8 + $h, $m + 190, $y + 8 + $h, '#d9d3c8' );
				$pdf->texte( $m, $y + 22 + $h, $livraison['signataire'], 9, false, $gris );
				$y += $h + 36;
			}
			if ( ! empty( $livraison['note'] ) ) {
				$pdf->texte( $m, $y, __( 'REMARQUE', 'espace-traiteur' ), 8, true, $gris );
				$y = $pdf->paragraphe( $m, $y + 15, $largeur, $livraison['note'], 10 ) + 10;
			}

			// Photos (6 au plus), 3 par ligne.
			$photos = array_slice( isset( $livraison['photos'] ) ? (array) $livraison['photos'] : array(), 0, self::PHOTOS );
			if ( $photos ) {
				$y      += 8;
				$cote    = ( $largeur - 20 ) / 3;
				$hauteur = $cote * 0.75;
				if ( $y + 14 + $hauteur > ETR_PDF::HAUTEUR - 60 ) {
					$pdf->page();
					$y = $m;
				}
				$pdf->texte( $m, $y, __( 'PHOTOS À LA LIVRAISON', 'espace-traiteur' ), 8, true, $gris );
				$y += 10;
				foreach ( $photos as $n => $nom ) {
					$chemin = ETR_Fichiers::chemin( $nom );
					$jpeg   = $chemin ? self::jpeg( $chemin, 900 ) : '';
					if ( ! $jpeg ) {
						continue;
					}
					if ( $n && 0 === $n % 3 ) {
						$y += $hauteur + 10;
						if ( $y + $hauteur > ETR_PDF::HAUTEUR - 60 ) {
							$pdf->page();
							$y = $m;
						}
					}
					$pdf->image( $jpeg, $m + ( $n % 3 ) * ( $cote + 10 ), $y, $cote, $hauteur );
				}
				$y += $hauteur + 10;
			}
		} else {
			$y = $pdf->paragraphe( $m, $y, $largeur, __( 'Livraison pas encore enregistrée.', 'espace-traiteur' ), 10 );
		}

		/* translators: 1 : site, 2 : date et heure. */
		$pdf->texte( $m, ETR_PDF::HAUTEUR - 28, sprintf( __( '%1$s — document établi le %2$s à partir de la livraison enregistrée sur le terrain.', 'espace-traiteur' ), etr_nom_site(), wp_date( 'j F Y à H:i' ) ), 7.5, false, '#a49d92' );
		return $pdf->sortie();
	}
}
