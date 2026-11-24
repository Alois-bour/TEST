<?php
/**
 * Désinstallation : retire le rôle, les capacités et les options.
 * Les demandes et le catalogue sont conservés en base (historique).
 *
 * @package EspaceTraiteur
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

remove_role( 'traiteur' );

$etr_admin = get_role( 'administrator' );
if ( $etr_admin ) {
	$etr_admin->remove_cap( 'acceder_espace_traiteur' );
	$etr_admin->remove_cap( 'gerer_espace_traiteur' );
}

delete_option( 'etr_version' );
delete_option( 'etr_page_id' );
delete_option( 'etr_categories' );
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
