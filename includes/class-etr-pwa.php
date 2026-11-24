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

	const FICHIERS = array( 'manifest.webmanifest', 'sw.js', 'hors-ligne' );

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
	}

	/**
	 * Règle de réécriture des fichiers de l'application.
	 */
	public static function regle_reecriture() {
		add_rewrite_rule( '^' . preg_quote( ETR_Gestion::slug(), '/' ) . '/(manifest\.webmanifest|sw\.js|hors-ligne)$', 'index.php?etr_gestion_fichier=$matches[1]', 'top' );
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
		if ( ! in_array( $fichier, self::FICHIERS, true ) ) {
			status_header( 404 );
			exit;
		}
		status_header( 200 );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		if ( 'manifest.webmanifest' === $fichier ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );
			echo wp_json_encode( self::manifeste(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		} elseif ( 'sw.js' === $fichier ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			header( 'Service-Worker-Allowed: ' . self::perimetre() );
			self::service_worker();
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
			'theme_color'      => self::COULEUR,
			'icons'            => $icones,
		);
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
	 * Page affichée quand le téléphone n'a pas de réseau.
	 */
	private static function page_hors_ligne() {
		$titre = __( 'Pas de connexion', 'espace-traiteur' );
		?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?php echo esc_attr( self::COULEUR ); ?>">
<title><?php echo esc_html( $titre ); ?></title>
<style>
	html { color-scheme: light; }
	body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: max(24px, env(safe-area-inset-top)) 24px max(24px, env(safe-area-inset-bottom)); background: #f8f5f2; color: #1d1d1b; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; text-align: center; box-sizing: border-box; }
	main { max-width: 340px; }
	h1 { margin: 0 0 8px; font: italic 400 30px/1.2 Georgia, "Times New Roman", serif; }
	p { margin: 0 0 24px; color: #6b655f; }
	button { min-height: 48px; padding: 0 28px; border: 0; border-radius: 10px; background: <?php echo esc_attr( self::COULEUR ); ?>; color: #fff; font: 600 16px/1 inherit; cursor: pointer; }
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
		echo '<meta name="theme-color" content="' . esc_attr( self::COULEUR ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( self::nom( true ) ) . '">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( self::icone( 180 ) ) . '">' . "\n";
		// App lancée depuis l'écran d'accueil : présentation « app » (barre d'onglets), posée avant l'affichage.
		echo "<script>if(navigator.standalone===true||window.matchMedia('(display-mode: standalone)').matches){document.documentElement.classList.add('etr-installee');}</script>\n";
	}

	/**
	 * Configuration transmise au script de l'application.
	 *
	 * @return array
	 */
	public static function config_script() {
		return array(
			'sw'        => self::url( 'sw.js' ),
			'perimetre' => get_option( 'permalink_structure' ) ? self::perimetre() : '/',
		);
	}
}
