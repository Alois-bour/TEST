<?php
// Exécution autonome : php tests/rappels.php
define( 'ABSPATH', __DIR__ );
$meta = array(
	1 => array( '_etr_statut' => 'validee', '_etr_date_evenement' => '2026-03-30', '_etr_date_livraison' => '2026-03-29' ),
	2 => array( '_etr_statut' => 'validee', '_etr_date_evenement' => '2026-03-29', '_etr_date_livraison' => '' ),
	3 => array( '_etr_statut' => 'livree', '_etr_date_evenement' => '2026-03-25', '_etr_date_reprise' => '2026-03-28' ),
	4 => array( '_etr_statut' => 'annulee', '_etr_date_evenement' => '2026-03-25' ),
	5 => array( '_etr_statut' => 'validee', '_etr_date_evenement' => '2026-03-30', '_etr_date_livraison' => '2026-03-29' ),
);
$emails = array();
$fail = 5;
class TestDB {
	public $prefix = 'wp_';
	public function prepare( $query, $arg ) { return $query; }
	public function get_var( $query ) { return '1'; }
}
$wpdb = new TestDB();
function __( $s, $domain = '' ) { return $s; }
function current_datetime() { return new DateTimeImmutable( '2026-03-26 12:00:00', new DateTimeZone( 'Europe/Paris' ) ); }
function get_posts( $args ) {
	global $meta;
	return array_keys( array_filter( $meta, function ( $m ) { return in_array( $m['_etr_statut'], array( 'validee', 'livree' ), true ); } ) );
}
function update_meta_cache( $type, $ids ) {}
function get_post_meta( $id, $key, $single ) { global $meta; return isset( $meta[ $id ][ $key ] ) ? $meta[ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { global $meta; $meta[ $id ][ $key ] = $value; }
function etr_get_statut( $id ) { return get_post_meta( $id, '_etr_statut', true ); }
function get_post_field( $field, $id ) { return $id; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'user_email' => $id . '@example.org' ); }
function is_email( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function etr_nom( $user ) { return 'Traiteur ' . $user->ID; }
function get_option( $key ) { return 'Site'; }
function wp_specialchars_decode( $s, $flags ) { return $s; }
function etr_champs_demande() { return array(); }
function etr_get_lignes( $id ) { return array(); }
function etr_url( $args ) { return 'https://example.org/demande/' . $args['id']; }
function wp_mail( $email, $subject, $body ) {
	global $emails, $fail;
	$id = (int) $email;
	$emails[] = array( $id, $subject, $body );
	return $id !== $fail;
}
require __DIR__ . '/../includes/class-etr-notifications.php';
ETR_Notifications::envoyer_rappels();
if ( array_column( $emails, 0 ) !== array( 1, 2, 3, 5 ) || isset( $meta[5]['_etr_rappel_livraison'] ) || $meta[3]['_etr_rappel_reprise'] !== '2026-03-25' ) {
	throw new RuntimeException( 'Première exécution incorrecte.' );
}
$fail = 0;
ETR_Notifications::envoyer_rappels();
if ( array_column( $emails, 0 ) !== array( 1, 2, 3, 5, 5 ) || $meta[5]['_etr_rappel_livraison'] !== '2026-03-29' ) {
	throw new RuntimeException( 'La reprise après échec ou la déduplication a échoué.' );
}
echo "Rappels OK\n";
