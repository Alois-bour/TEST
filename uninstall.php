<?php
/**
 * Désinstallation : retire le rôle, les capacités et les options.
 * Les demandes et le catalogue sont conservés en base (historique).
 *
 * @package EspaceTraiteur
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

remove_role( 'traiteur' );
foreach ( array( 'etr_bureau', 'etr_preparateur', 'etr_livreur' ) as $etr_role ) {
	remove_role( $etr_role );
}

$etr_admin = get_role( 'administrator' );
if ( $etr_admin ) {
	$etr_admin->remove_cap( 'acceder_espace_traiteur' );
	$etr_admin->remove_cap( 'gerer_espace_traiteur' );
	foreach ( array( 'acceder_gestion', 'preparer_commandes', 'livrer_commandes' ) as $etr_cap ) {
		$etr_admin->remove_cap( $etr_cap );
	}
}

delete_option( 'etr_version' );
delete_option( 'etr_page_id' );
delete_option( 'etr_categories' );
delete_option( 'etr_journal_liens' );
delete_option( 'etr_push_vapid' );
delete_option( 'etr_rappel_veille' );
delete_metadata( 'user', 0, 'etr_push_appareils', '', true );
delete_option( 'etr_afficher_prix' );
delete_option( 'etr_email_equipe' );
delete_option( 'etr_logo' );
delete_option( 'etr_image_connexion' );
delete_option( 'etr_connexion_plein_ecran' );
delete_option( 'etr_espace_plein_ecran' );
delete_option( 'etr_journal_migrations' );
delete_option( 'etr_migration_echec' );
delete_option( 'etr_migration_verrou' );
wp_clear_scheduled_hook( 'etr_migration_suite' );
wp_clear_scheduled_hook( 'etr_rappels_horaires' );
