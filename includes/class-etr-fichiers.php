<?php
/**
 * Fichiers privés des demandes : photos et signatures de livraison et de reprise.
 *
 * Rangés hors de la médiathèque, dans « uploads/etr-prive » (accès direct interdit par
 * .htaccess, noms aléatoires), et servis uniquement par l'application de l'équipe, après
 * vérification des droits sur la demande.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fichiers privés.
 */
class ETR_Fichiers {

	const DOSSIER     = 'etr-prive';
	const TAILLE_MAX  = 15728640; // 15 Mo par photo.
	const TYPES       = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);
	const MOTIF       = '/^(\d+)-([a-z]+)-([a-f0-9]{24})\.(jpg|png|webp)$/';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'before_delete_post', array( __CLASS__, 'supprimer_demande' ) );
	}

	/**
	 * Dossier privé, créé et protégé au premier usage.
	 *
	 * @return string Chemin (avec / final), ou '' si impossible.
	 */
	public static function dossier() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$dossier = trailingslashit( $uploads['basedir'] ) . self::DOSSIER . '/';
		if ( ! is_dir( $dossier ) && ! wp_mkdir_p( $dossier ) ) {
			return '';
		}
		if ( ! file_exists( $dossier . '.htaccess' ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichiers de protection.
			file_put_contents( $dossier . '.htaccess', "# Espace Traiteur : fichiers privés, servis par l'application.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
			file_put_contents( $dossier . 'index.php', "<?php\n// Silence.\n" );
			// phpcs:enable
		}
		return $dossier;
	}

	/**
	 * Enregistre une photo envoyée (champ de formulaire).
	 *
	 * @param array  $fichier    Entrée de $_FILES (une seule photo).
	 * @param int    $demande_id Demande.
	 * @param string $type       livraison | reprise.
	 * @return string|WP_Error Nom du fichier.
	 */
	public static function enregistrer_envoi( $fichier, $demande_id, $type ) {
		if ( empty( $fichier['tmp_name'] ) || ! empty( $fichier['error'] ) || ! is_uploaded_file( $fichier['tmp_name'] ) ) {
			return new WP_Error( 'etr_fichier', __( 'Une photo n’a pas pu être reçue.', 'espace-traiteur' ) );
		}
		if ( (int) $fichier['size'] > self::TAILLE_MAX ) {
			return new WP_Error( 'etr_fichier', __( 'Une photo est trop lourde (15 Mo au plus).', 'espace-traiteur' ) );
		}
		$extension = self::extension_image( $fichier['tmp_name'] );
		if ( ! $extension ) {
			return new WP_Error( 'etr_fichier', __( 'Seules les photos (JPEG, PNG, WebP) sont acceptées.', 'espace-traiteur' ) );
		}
		$nom    = self::nouveau_nom( $demande_id, $type, $extension );
		$cible  = self::dossier();
		if ( ! $cible || ! move_uploaded_file( $fichier['tmp_name'], $cible . $nom ) ) {
			return new WP_Error( 'etr_fichier', __( 'La photo n’a pas pu être enregistrée sur le serveur.', 'espace-traiteur' ) );
		}
		return $nom;
	}

	/**
	 * Enregistre une signature (image PNG en « data URL », tracée sur le téléphone).
	 *
	 * @param string $data_url   data:image/png;base64,…
	 * @param int    $demande_id Demande.
	 * @param string $type       livraison | reprise.
	 * @return string|WP_Error Nom du fichier.
	 */
	public static function enregistrer_signature( $data_url, $demande_id, $type ) {
		$prefixe = 'data:image/png;base64,';
		if ( 0 !== strpos( (string) $data_url, $prefixe ) || strlen( $data_url ) > 2000000 ) {
			return new WP_Error( 'etr_signature', __( 'La signature est manquante ou invalide.', 'espace-traiteur' ) );
		}
		$png = base64_decode( substr( $data_url, strlen( $prefixe ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $png || 0 !== strpos( $png, "\x89PNG\r\n\x1a\n" ) ) {
			return new WP_Error( 'etr_signature', __( 'La signature est manquante ou invalide.', 'espace-traiteur' ) );
		}
		$nom    = self::nouveau_nom( $demande_id, $type . 'signature', 'png' );
		$cible  = self::dossier();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( ! $cible || false === file_put_contents( $cible . $nom, $png ) || ! self::extension_image( $cible . $nom ) ) {
			if ( $cible && file_exists( $cible . $nom ) ) {
				wp_delete_file( $cible . $nom );
			}
			return new WP_Error( 'etr_signature', __( 'La signature n’a pas pu être enregistrée.', 'espace-traiteur' ) );
		}
		return $nom;
	}

	/**
	 * Extension d'une vraie image (contenu vérifié, pas le nom).
	 *
	 * @param string $chemin Fichier.
	 * @return string jpg | png | webp, ou '' si ce n'est pas une image acceptée.
	 */
	private static function extension_image( $chemin ) {
		$infos = @getimagesize( $chemin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- fichier non image : false.
		if ( ! $infos || empty( $infos['mime'] ) || ! isset( self::TYPES[ $infos['mime'] ] ) ) {
			return '';
		}
		return self::TYPES[ $infos['mime'] ];
	}

	/**
	 * Nom aléatoire, préfixé par la demande (pour vérifier les droits).
	 *
	 * @param int    $demande_id Demande.
	 * @param string $type       Type.
	 * @param string $extension  Extension.
	 * @return string
	 */
	private static function nouveau_nom( $demande_id, $type, $extension ) {
		return (int) $demande_id . '-' . preg_replace( '/[^a-z]/', '', $type ) . '-' . bin2hex( random_bytes( 12 ) ) . '.' . $extension;
	}

	/**
	 * Chemin d'un fichier existant (nom vérifié).
	 *
	 * @param string $nom Nom.
	 * @return string Chemin, ou ''.
	 */
	public static function chemin( $nom ) {
		if ( ! preg_match( self::MOTIF, (string) $nom ) ) {
			return '';
		}
		$dossier = self::dossier();
		return $dossier && is_file( $dossier . $nom ) ? $dossier . $nom : '';
	}

	/**
	 * Demande à laquelle appartient un fichier.
	 *
	 * @param string $nom Nom.
	 * @return int
	 */
	public static function demande( $nom ) {
		return preg_match( self::MOTIF, (string) $nom, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Adresse d'affichage dans l'application.
	 *
	 * @param string $nom Nom.
	 * @return string
	 */
	public static function url( $nom ) {
		return ETR_Gestion::url(
			array(
				'vue' => 'fichier',
				'f'   => $nom,
			)
		);
	}

	/**
	 * Sert un fichier à un membre de l'équipe qui voit la demande, puis arrête.
	 *
	 * @param string $nom Nom.
	 */
	public static function servir( $nom ) {
		$chemin = self::chemin( $nom );
		if ( ! $chemin || ! ETR_Gestion::demande_visible( self::demande( $nom ) ) ) {
			status_header( 404 );
			exit;
		}
		$types = array_flip( self::TYPES );
		header( 'Content-Type: ' . $types[ pathinfo( $chemin, PATHINFO_EXTENSION ) ] );
		header( 'Content-Length: ' . filesize( $chemin ) );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline; filename="' . $nom . '"' );
		readfile( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Supprime un fichier.
	 *
	 * @param string $nom Nom.
	 */
	public static function supprimer( $nom ) {
		$chemin = self::chemin( $nom );
		if ( $chemin ) {
			wp_delete_file( $chemin );
		}
	}

	/**
	 * Une demande supprimée définitivement emporte ses photos et signatures.
	 *
	 * @param int $post_id Contenu supprimé.
	 */
	public static function supprimer_demande( $post_id ) {
		if ( 'etr_demande' !== get_post_type( $post_id ) ) {
			return;
		}
		$dossier = self::dossier();
		foreach ( $dossier ? (array) glob( $dossier . (int) $post_id . '-*' ) : array() as $chemin ) {
			if ( preg_match( self::MOTIF, basename( $chemin ) ) ) {
				wp_delete_file( $chemin );
			}
		}
	}
}
