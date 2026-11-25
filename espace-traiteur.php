<?php
/**
 * Plugin Name:       Espace Traiteur
 * Description:       Espace privé pour les traiteurs : chaque traiteur se connecte à son espace et fait ses demandes de mobilier, vaisselle, linge et matériel, à partir des produits WooCommerce du site.
 * Version:           3.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       espace-traiteur
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ETR_VERSION', '3.0.0' );
define( 'ETR_FILE', __FILE__ );
define( 'ETR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETR_URL', plugin_dir_url( __FILE__ ) );

require_once ETR_DIR . 'includes/functions.php';
require_once ETR_DIR . 'includes/catalogue.php';
require_once ETR_DIR . 'includes/class-etr-modules.php';
require_once ETR_DIR . 'includes/class-etr-mail.php';
require_once ETR_DIR . 'includes/class-etr-apparence.php';
require_once ETR_DIR . 'includes/class-etr-installer.php';
require_once ETR_DIR . 'includes/class-etr-migrations.php';
require_once ETR_DIR . 'includes/class-etr-post-types.php';
require_once ETR_DIR . 'includes/class-etr-access.php';
require_once ETR_DIR . 'includes/class-etr-comptes.php';
require_once ETR_DIR . 'includes/class-etr-traiteurs.php';
require_once ETR_DIR . 'includes/class-etr-equipe.php';
require_once ETR_DIR . 'includes/class-etr-equipe-acces.php';
require_once ETR_DIR . 'includes/class-etr-journal-liens.php';
require_once ETR_DIR . 'includes/class-etr-pwa.php';
require_once ETR_DIR . 'includes/class-etr-push.php';
require_once ETR_DIR . 'includes/class-etr-fichiers.php';
require_once ETR_DIR . 'includes/class-etr-operations.php';
require_once ETR_DIR . 'includes/class-etr-terrain.php';
require_once ETR_DIR . 'includes/class-etr-pdf.php';
require_once ETR_DIR . 'includes/class-etr-bon-livraison.php';
require_once ETR_DIR . 'includes/class-etr-cles-acces.php';
require_once ETR_DIR . 'includes/class-etr-traiteur-direct.php';
require_once ETR_DIR . 'includes/class-etr-materiel.php';
require_once ETR_DIR . 'includes/class-etr-devis.php';
require_once ETR_DIR . 'includes/class-etr-pilotage.php';
require_once ETR_DIR . 'includes/class-etr-estimation.php';
require_once ETR_DIR . 'includes/class-etr-logistique.php';
require_once ETR_DIR . 'includes/class-etr-traiteur-plus.php';
require_once ETR_DIR . 'includes/class-etr-stock-plus.php';
require_once ETR_DIR . 'includes/class-etr-facture.php';
require_once ETR_DIR . 'includes/class-etr-stripe.php';
require_once ETR_DIR . 'includes/class-etr-admin.php';
require_once ETR_DIR . 'includes/class-etr-frontend.php';
require_once ETR_DIR . 'includes/class-etr-statistiques.php';
require_once ETR_DIR . 'includes/class-etr-gestion.php';
require_once ETR_DIR . 'includes/class-etr-notifications.php';
require_once ETR_DIR . 'includes/class-etr-historique.php';
require_once ETR_DIR . 'includes/class-etr-bons.php';

register_activation_hook( __FILE__, array( 'ETR_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ETR_Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		ETR_Migrations::init();
		ETR_Post_Types::init();
		ETR_Access::init();
		ETR_Comptes::init();
		ETR_Traiteurs::init();
		ETR_Equipe::init();
		ETR_Equipe_Acces::init();
		ETR_Journal_Liens::init();
		ETR_PWA::init();
		ETR_Push::init();
		ETR_Fichiers::init();
		ETR_Operations::init();
		ETR_Bon_Livraison::init();
		ETR_Cles_Acces::init();
		ETR_Modules::init();
		ETR_Mail::init();
		ETR_Apparence::init();
		ETR_Traiteur_Direct::init();
		ETR_Materiel::init();
		ETR_Devis::init();
		ETR_Pilotage::init();
		ETR_Estimation::init();
		ETR_Logistique::init();
		ETR_Traiteur_Plus::init();
		ETR_Stock_Plus::init();
		ETR_Facture::init();
		ETR_Stripe::init();
		ETR_Admin::init();
		ETR_Frontend::init();
		ETR_Gestion::init();
		ETR_Notifications::init();
		ETR_Historique::init();
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ETR_DIR . 'includes/class-etr-cli.php';
	WP_CLI::add_command( 'espace-traiteur', 'ETR_CLI' );
}

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'espace-traiteur', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
