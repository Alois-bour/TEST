<?php
/**
 * Tests sans WordPress : quelques fonctions de WordPress simulées, puis les fichiers du plugin.
 * Lancement : php tests/executer.php (aussi lancé avant chaque déploiement).
 *
 * @package EspaceTraiteur
 */

if ( 'cli' !== PHP_SAPI ) {
	exit; // Jamais depuis le navigateur.
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'ETR_VERSION', 'test' );
define( 'ETR_DIR', dirname( __DIR__ ) . '/' );
define( 'ETR_URL', 'https://exemple.test/wp-content/plugins/espace-traiteur/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'YEAR_IN_SECONDS', 31536000 );

$GLOBALS['etr_test_options'] = array();
$GLOBALS['etr_test_filtres'] = array();

function __( $t ) { return $t; }
function _n( $s, $p, $n ) { return 1 === (int) $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $t ) { return esc_html( $t ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', ' ' ); }
function apply_filters( $nom, $valeur ) {
	$args = array_slice( func_get_args(), 1 );
	foreach ( isset( $GLOBALS['etr_test_filtres'][ $nom ] ) ? $GLOBALS['etr_test_filtres'][ $nom ] : array() as $f ) {
		$args[0] = call_user_func_array( $f, $args );
	}
	return $args[0];
}
function add_filter( $nom, $f ) { $GLOBALS['etr_test_filtres'][ $nom ][] = $f; }
function add_action() {}
function do_action() {}
function get_option( $nom, $defaut = false ) { return array_key_exists( $nom, $GLOBALS['etr_test_options'] ) ? $GLOBALS['etr_test_options'][ $nom ] : $defaut; }
function update_option( $nom, $valeur ) { $GLOBALS['etr_test_options'][ $nom ] = $valeur; return true; }
function delete_option( $nom ) { unset( $GLOBALS['etr_test_options'][ $nom ] ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $c ) ? $c : null; }
function home_url( $p = '' ) { return 'https://exemple.test' . $p; }
function untrailingslashit( $t ) { return rtrim( $t, '/' ); }
function trailingslashit( $t ) { return rtrim( $t, '/' ) . '/'; }
function wp_specialchars_decode( $t ) { return htmlspecialchars_decode( $t, ENT_QUOTES ); }
function wp_get_attachment_image_url() { return ''; }
function wp_date( $f, $t = null ) { return gmdate( $f, null === $t ? time() : $t ); }
function get_bloginfo() { return 'Maison Test'; }
function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
function is_admin() { return false; }

/** ETR_Modules simulé : tout actif. */
class ETR_Modules { public static function actif() { return true; } }
/** ETR_Comptes simulé : pas de logo. */
class ETR_Comptes { public static function logo_id() { return 0; } }

require ETR_DIR . 'includes/functions.php';
require ETR_DIR . 'includes/class-etr-mail.php';
require ETR_DIR . 'includes/class-etr-estimation.php';
require ETR_DIR . 'includes/class-etr-logistique.php';
require ETR_DIR . 'includes/class-etr-stripe.php';
require ETR_DIR . 'includes/class-etr-apparence.php';
require ETR_DIR . 'includes/class-etr-facture.php';
require ETR_DIR . 'includes/class-etr-pdf.php';

/**
 * Vérification.
 *
 * @param bool   $condition Vrai attendu.
 * @param string $message   Ce qui est vérifié.
 */
function verifier( $condition, $message ) {
	$GLOBALS['etr_test_total'] = ( isset( $GLOBALS['etr_test_total'] ) ? $GLOBALS['etr_test_total'] : 0 ) + 1;
	if ( $condition ) {
		echo "  ✓ $message\n";
	} else {
		$GLOBALS['etr_test_echecs'][] = $message;
		echo "  ✗ $message\n";
	}
}
