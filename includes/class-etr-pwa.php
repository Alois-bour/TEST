<?php
/**
 * Application installable (PWA) de l'équipe : l'espace de gestion s'installe sur
 * l'écran d'accueil de l'iPhone (icône, plein écran), avec une connexion intégrée.
 *
 * Fichiers servis par le plugin, dans le périmètre de l'application :
 * - /gestion-traiteur/manifest.webmanifest : nom, couleurs, icônes ;
 * - /gestion-traiteur/sw.js                : service worker (réseau d'abord, page hors ligne) ;
 * - /gestion-traiteur/hors-ligne           : page affichée sans connexion.
 *
 * Ces fichiers ne contiennent aucune donnée : ils sont publics. Les pages de l'application
 * ne sont jamais mises en cache (données toujours à jour, rien ne reste sur un téléphone prêté).
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Application installable.
 */
class ETR_PWA {

	const FICHIERS = array( 'manifest.webmanifest', 'manifest-traiteur.webmanifest', 'sw.js', 'sw-traiteur.js', 'hors-ligne' );

	/**
	 * Écrans de démarrage iPhone : largeur et hauteur en points, densité (portrait).
	 */
	const DEMARRAGES = array(
		array( 440, 956, 3 ),
		array( 430, 932, 3 ),
		array( 428, 926, 3 ),
		array( 414, 896, 3 ),
		array( 414, 896, 2 ),
		array( 414, 736, 3 ),
		array( 402, 874, 3 ),
		array( 393, 852, 3 ),
		array( 390, 844, 3 ),
		array( 375, 812, 3 ),
		array( 375, 667, 2 ),
	);

	/**
	 * Couleur de la barre d'application (vert nuit de l'espace de gestion).
	 */
	const COULEUR = '#2c3424';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'regle_reecriture' ) );
		add_filter( 'query_vars', array( __CLASS__, 'variables' ) );
		add_action( 'template_redirect', array( __CLASS__, 'servir' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'balises' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'balises_traiteur' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script_traiteur' ) );
	}

	/**
	 * Règle de réécriture des fichiers de l'application.
	 */
	public static function regle_reecriture() {
		add_rewrite_rule( '^' . preg_quote( ETR_Gestion::slug(), '/' ) . '/(manifest\.webmanifest|manifest-traiteur\.webmanifest|sw\.js|sw-traiteur\.js|hors-ligne|demarrage-\d+x\d+\.png)$', 'index.php?etr_gestion_fichier=$matches[1]', 'top' );
	}

	/**
	 * Variable de requête.
	 *
	 * @param string[] $vars Variables.
	 * @return string[]
	 */
	public static function variables( $vars ) {
		$vars[] = 'etr_gestion_fichier';
		return $vars;
	}

	/**
	 * URL d'un fichier de l'application.
	 *
	 * @param string $fichier manifest.webmanifest | sw.js | hors-ligne.
	 * @return string
	 */
	public static function url( $fichier ) {
		return get_option( 'permalink_structure' )
			? home_url( ETR_Gestion::slug() . '/' . $fichier )
			: add_query_arg( 'etr_gestion_fichier', $fichier, home_url( '/' ) );
	}

	/**
	 * Périmètre de l'application (chemin) : seules ces pages s'ouvrent dans l'app installée.
	 *
	 * @return string
	 */
	public static function perimetre() {
		if ( ! get_option( 'permalink_structure' ) ) {
			// Sans permaliens, l'app vit dans « /?etr_gestion=1 » : le service worker ne traite que ces pages.
			$chemin = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			return '' === $chemin ? '/' : trailingslashit( $chemin );
		}
		return untrailingslashit( (string) wp_parse_url( home_url( ETR_Gestion::slug() ), PHP_URL_PATH ) );
	}

	/**
	 * Chemin de l'espace traiteur (portée de son service worker).
	 *
	 * @return string
	 */
	public static function perimetre_traiteur() {
		$chemin = (string) wp_parse_url( etr_url(), PHP_URL_PATH );
		return '' === $chemin ? '/' : trailingslashit( $chemin );
	}

	/**
	 * Nom de l'application.
	 *
	 * @param bool $court Nom court (sous l'icône).
	 * @return string
	 */
	public static function nom( $court = false ) {
		$site = etr_nom_site();
		if ( $court ) {
			return (string) apply_filters( 'etr_app_nom_court', __( 'Gestion', 'espace-traiteur' ) );
		}
		/* translators: %s : nom du site. */
		return (string) apply_filters( 'etr_app_nom', sprintf( __( '%s – Gestion', 'espace-traiteur' ), $site ) );
	}

	/**
	 * Icône de l'application : « Icône du site » de WordPress, sinon icône fournie par le plugin.
	 *
	 * @param int $taille 180, 192 ou 512.
	 * @return string
	 */
	public static function icone( $taille ) {
		$url = get_site_icon_url( $taille );
		return $url ? $url : ETR_URL . 'assets/img/app-icone-' . (int) $taille . '.png';
	}

	/* ------------------------------------------------------------------ */
	/* Fichiers                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Sert le manifeste, le service worker ou la page hors ligne.
	 */
	public static function servir() {
		$fichier = (string) get_query_var( 'etr_gestion_fichier' );
		if ( '' === $fichier ) {
			return;
		}
		if ( preg_match( '/^demarrage-(\d+)x(\d+)\.png$/', $fichier, $taille ) ) {
			self::ecran_demarrage( (int) $taille[1], (int) $taille[2] );
			exit;
		}
		if ( ! in_array( $fichier, self::FICHIERS, true ) ) {
			status_header( 404 );
			exit;
		}
		status_header( 200 );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		if ( 'manifest.webmanifest' === $fichier || 'manifest-traiteur.webmanifest' === $fichier ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );
			$manifeste = 'manifest.webmanifest' === $fichier ? self::manifeste() : self::manifeste_traiteur();
			echo wp_json_encode( $manifeste, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		} elseif ( 'sw.js' === $fichier ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			header( 'Service-Worker-Allowed: ' . self::perimetre() );
			self::service_worker();
		} elseif ( 'sw-traiteur.js' === $fichier ) {
			// Notifications de l'espace traiteur (fonction « push_traiteur ») : rien n'est mis en cache.
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			header( 'Service-Worker-Allowed: ' . self::perimetre_traiteur() );
			$config = array(
				'nom'     => etr_nom_site(),
				'icone'   => self::icone( 192 ),
				'accueil' => etr_url(),
			);
			echo 'const ETR = ' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ";\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du plugin.
			echo file_get_contents( ETR_DIR . 'assets/js/sw-traiteur.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- code JavaScript du plugin.
		} else {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			self::page_hors_ligne();
		}
		exit;
	}

	/**
	 * Manifeste de l'application.
	 *
	 * @return array
	 */
	public static function manifeste() {
		$icones = array();
		foreach ( array( 192, 512 ) as $taille ) {
			$icones[] = array(
				'src'     => self::icone( $taille ),
				'sizes'   => $taille . 'x' . $taille,
				'type'    => 'image/png',
				'purpose' => 'any',
			);
		}
		return array(
			'id'               => self::perimetre(),
			'name'             => self::nom(),
			'short_name'       => self::nom( true ),
			'description'      => __( 'Demandes, planning, préparation et livraisons du matériel des traiteurs.', 'espace-traiteur' ),
			'lang'             => 'fr',
			'dir'              => 'ltr',
			'start_url'        => ETR_Gestion::url(),
			'scope'            => get_option( 'permalink_structure' ) ? self::perimetre() : '/',
			'display'          => 'standalone',
			'background_color' => '#f8f5f2',
			'theme_color'      => ETR_Apparence::barre( 'equipe' ),
			'icons'            => $icones,
		);
	}

	/**
	 * Manifeste de l'espace traiteur (installable sur l'écran d'accueil de l'iPhone du traiteur).
	 *
	 * @return array
	 */
	public static function manifeste_traiteur() {
		$icones = array();
		foreach ( array( 192, 512 ) as $taille ) {
			$icones[] = array(
				'src'     => self::icone( $taille ),
				'sizes'   => $taille . 'x' . $taille,
				'type'    => 'image/png',
				'purpose' => 'any',
			);
		}
		$chemin = (string) wp_parse_url( etr_url(), PHP_URL_PATH );
		return array(
			'id'               => $chemin,
			/* translators: %s : nom du site. */
			'name'             => (string) apply_filters( 'etr_app_traiteur_nom', sprintf( __( '%s – Espace traiteur', 'espace-traiteur' ), etr_nom_site() ) ),
			'short_name'       => (string) apply_filters( 'etr_app_traiteur_nom_court', etr_nom_site() ),
			'description'      => __( 'Vos demandes de matériel et leur suivi, de la préparation à la reprise.', 'espace-traiteur' ),
			'lang'             => 'fr',
			'start_url'        => etr_url(),
			'scope'            => $chemin ? $chemin : '/',
			'display'          => 'standalone',
			'background_color' => ETR_Apparence::barre( 'traiteur' ),
			'theme_color'      => ETR_Apparence::barre( 'traiteur' ),
			'icons'            => $icones,
		);
	}

	/**
	 * Aide à l'installation de l'espace traiteur (iPhone, Safari).
	 */
	public static function script_traiteur() {
		if ( is_user_logged_in() && class_exists( 'ETR_Frontend' ) && ETR_Frontend::sur_page_espace() ) {
			wp_enqueue_script( 'espace-traiteur-app-traiteur', ETR_URL . 'assets/js/traiteur-app.js', array(), ETR_VERSION, true );
		}
	}

	/**
	 * Balises de l'espace traiteur installable (page de l'espace seulement).
	 */
	public static function balises_traiteur() {
		if ( ! class_exists( 'ETR_Frontend' ) || ! ETR_Frontend::sur_page_espace() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( self::url( 'manifest-traiteur.webmanifest' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( ETR_Apparence::barre( 'traiteur' ) ) . '">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( etr_nom_site() ) . '">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( self::icone( 180 ) ) . '">' . "\n";
		echo "<script>if(navigator.standalone===true||window.matchMedia('(display-mode: standalone)').matches){document.documentElement.classList.add('etr-installee');}</script>\n";
	}

	/**
	 * Service worker : pages toujours chargées depuis le réseau (page « hors ligne » sinon),
	 * fichiers CSS/JS/images du plugin servis depuis le cache puis rafraîchis.
	 */
	private static function service_worker() {
		$config = array(
			'version'   => ETR_VERSION,
			'horsLigne' => self::url( 'hors-ligne' ),
			'assets'    => ETR_URL . 'assets/',
			'perimetre' => self::perimetre(),
			'permalien' => (bool) get_option( 'permalink_structure' ),
			'nom'       => self::nom(),
			'icone'     => self::icone( 192 ),
			'accueil'   => ETR_Gestion::url(),
		);
		echo '/* Espace Traiteur : service worker de l’application de l’équipe. */' . "\n";
		echo 'const ETR = ' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ";\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du plugin.
		echo file_get_contents( ETR_DIR . 'assets/js/sw.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- code JavaScript du plugin.
	}

	/**
	 * Image de démarrage (couleur de l'application, icône au centre), pour une taille d'iPhone connue.
	 *
	 * @param int $largeur Largeur en pixels.
	 * @param int $hauteur Hauteur en pixels.
	 */
	private static function ecran_demarrage( $largeur, $hauteur ) {
		$connue = false;
		foreach ( self::DEMARRAGES as $ecran ) {
			$connue = $connue || ( $ecran[0] * $ecran[2] === $largeur && $ecran[1] * $ecran[2] === $hauteur );
		}
		if ( ! $connue || ! function_exists( 'imagecreatetruecolor' ) ) {
			status_header( 404 );
			return;
		}
		$image = imagecreatetruecolor( $largeur, $hauteur );
		list( $r, $v, $b ) = sscanf( ETR_Apparence::barre( 'equipe' ), '#%02x%02x%02x' );
		imagefill( $image, 0, 0, imagecolorallocate( $image, $r, $v, $b ) );

		$icone_id = (int) get_option( 'site_icon' );
		$source   = $icone_id ? get_attached_file( $icone_id ) : '';
		$source   = $source && is_readable( $source ) ? $source : ETR_DIR . 'assets/img/app-icone-512.png';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		$icone = is_readable( $source ) ? @imagecreatefromstring( (string) file_get_contents( $source ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $icone ) {
			$cote = (int) round( $largeur * 0.28 );
			imagecopyresampled( $image, $icone, (int) ( ( $largeur - $cote ) / 2 ), (int) ( ( $hauteur - $cote ) / 2 ), 0, 0, $cote, $cote, imagesx( $icone ), imagesy( $icone ) );
			imagedestroy( $icone );
		}
		status_header( 200 );
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=2592000' );
		imagepng( $image, null, 6 );
		imagedestroy( $image );
	}

	/**
	 * Page affichée quand le téléphone n'a pas de réseau.
	 */
	private static function page_hors_ligne() {
		$titre = __( 'Pas de connexion', 'espace-traiteur' );
		?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?php echo esc_attr( ETR_Apparence::barre( 'equipe' ) ); ?>">
<title><?php echo esc_html( $titre ); ?></title>
<style>
	html { color-scheme: light; }
	body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: max(24px, env(safe-area-inset-top)) 24px max(24px, env(safe-area-inset-bottom)); background: #f8f5f2; color: #1d1d1b; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; text-align: center; box-sizing: border-box; }
	main { max-width: 340px; }
	h1 { margin: 0 0 8px; font: italic 400 30px/1.2 Georgia, "Times New Roman", serif; }
	p { margin: 0 0 24px; color: #6b655f; }
	button { min-height: 48px; padding: 0 28px; border: 0; border-radius: 10px; background: <?php echo esc_attr( ETR_Apparence::barre( 'equipe' ) ); ?>; color: #fff; font: 600 16px/1 inherit; cursor: pointer; }
</style>
</head>
<body>
<main>
	<h1><?php echo esc_html( $titre ); ?></h1>
	<p><?php echo esc_html__( 'L’application a besoin du réseau pour afficher les demandes à jour. Vérifiez le Wi-Fi ou les données mobiles.', 'espace-traiteur' ); ?></p>
	<button type="button" onclick="location.reload()"><?php echo esc_html__( 'Réessayer', 'espace-traiteur' ); ?></button>
</main>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Balises de l'application (espace de gestion)                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Manifeste, icône et réglages iPhone dans l'espace de gestion.
	 */
	public static function balises() {
		if ( ! ETR_Gestion::actif() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( self::url( 'manifest.webmanifest' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( ETR_Apparence::barre( 'equipe' ) ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( self::nom( true ) ) . '">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( self::icone( 180 ) ) . '">' . "\n";
		if ( function_exists( 'imagecreatetruecolor' ) && get_option( 'permalink_structure' ) ) {
			foreach ( self::DEMARRAGES as $ecran ) {
				$media = sprintf( '(device-width: %1$dpx) and (device-height: %2$dpx) and (-webkit-device-pixel-ratio: %3$d) and (orientation: portrait)', $ecran[0], $ecran[1], $ecran[2] );
				echo '<link rel="apple-touch-startup-image" media="' . esc_attr( $media ) . '" href="' . esc_url( self::url( 'demarrage-' . ( $ecran[0] * $ecran[2] ) . 'x' . ( $ecran[1] * $ecran[2] ) . '.png' ) ) . '">' . "\n";
			}
		}
		echo '<meta name="color-scheme" content="light dark">' . "\n";
		// App lancée depuis l'écran d'accueil : présentation « app » (barre d'onglets), posée avant l'affichage.
		echo "<script>if(navigator.standalone===true||window.matchMedia('(display-mode: standalone)').matches){document.documentElement.classList.add('etr-installee');}</script>\n";
		// Transition vers cet écran : sens (avance, retour, onglet) choisi au toucher (app.js).
		echo "<script>addEventListener('pagereveal',function(e){var t=e.viewTransition;if(!t){return;}['ready','finished','updateCallbackDone'].forEach(function(p){if(t[p]){t[p].catch(function(){});}});try{var s=sessionStorage.getItem('etr-sens')||'avance';sessionStorage.removeItem('etr-sens');if(t.types){t.types.add(s);}}catch(x){}});</script>\n";
	}

	/**
	 * Configuration transmise au script de l'application.
	 *
	 * @return array
	 */
	public static function config_script() {
		$connecte = is_user_logged_in() && ETR_Equipe::acces() && ! ETR_Equipe_Acces::ecran();
		return array(
			'sw'        => self::url( 'sw.js' ),
			'perimetre' => get_option( 'permalink_structure' ) ? self::perimetre() : '/',
			// Pastille sur l'icône de l'application (iPhone, app installée avec notifications autorisées).
			'badge'     => $connecte ? ETR_Gestion::pastille_app() : null,
		);
	}
}
