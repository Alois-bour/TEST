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
