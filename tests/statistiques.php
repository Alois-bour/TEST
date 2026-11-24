<?php
// Exécution autonome : php tests/statistiques.php
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
define( 'ABSPATH', __DIR__ );
$demandes = array(
	1 => array( 'statut' => 'validee', 'date' => '2026-03-30', 'fin' => '2026-03-31', 'user' => 10, 'total' => 100, 'sans_prix' => 0, 'livraison' => 10, 'lignes' => array( array( 'article_id' => 8, 'titre' => 'Chaise', 'quantite' => 6 ) ) ),
	2 => array( 'statut' => 'livree', 'date' => '2026-03-31', 'fin' => '2026-04-01', 'user' => 20, 'total' => 50, 'sans_prix' => 1, 'livraison' => null, 'lignes' => array( array( 'article_id' => 8, 'titre' => 'Chaise', 'quantite' => 4 ) ) ),
	3 => array( 'statut' => 'refusee', 'date' => '2026-03-30', 'fin' => '2026-03-30', 'user' => 20, 'total' => 999, 'sans_prix' => 0, 'livraison' => 0, 'lignes' => array( array( 'article_id' => 8, 'titre' => 'Chaise', 'quantite' => 20 ) ) ),
	4 => array( 'statut' => 'cloturee', 'date' => '2026-02-01', 'fin' => '2026-02-01', 'user' => 10, 'total' => 25, 'sans_prix' => 0, 'livraison' => 0, 'lignes' => array( array( 'article_id' => 9, 'titre' => 'Table', 'quantite' => 2 ) ) ),
	5 => array( 'statut' => 'en_attente', 'date' => '2026-04-10', 'fin' => '2026-04-11', 'user' => 10, 'total' => 1000, 'sans_prix' => 0, 'livraison' => 0, 'lignes' => array( array( 'article_id' => 9, 'titre' => 'Table', 'quantite' => 1 ) ) ),
);
function etr_statuts_reservant() { return array( 'en_attente', 'validee', 'livree' ); }
function etr_get_statut( $id ) { global $demandes; return $demandes[ $id ]['statut']; }
function etr_get_lignes( $id ) { global $demandes; return $demandes[ $id ]['lignes']; }
function get_post_meta( $id, $key, $single ) { global $demandes; return '_etr_date_evenement' === $key ? $demandes[ $id ]['date'] : ''; }
function etr_date_valide( $date ) { $d = DateTime::createFromFormat( 'Y-m-d', $date ); return $d && $d->format( 'Y-m-d' ) === $date; }
function etr_chiffrage( $id ) { global $demandes; return $demandes[ $id ]; }
function get_post_field( $field, $id ) { global $demandes; return $demandes[ $id ]['user']; }
function etr_periode_demande( $id ) { global $demandes; return array( $demandes[ $id ]['date'], $demandes[ $id ]['fin'] ); }
function etr_stock_total( $id ) { return 8 === $id ? 10 : null; }
require __DIR__ . '/../includes/class-etr-statistiques.php';
$resultat = ETR_Statistiques::calculer( array_keys( $demandes ), '2026-03-26' );
if ( array_keys( $resultat['mois'] ) !== array( '2026-03', '2026-02' )
	|| 150.0 !== $resultat['mois']['2026-03']['total']
	|| 1 !== $resultat['mois']['2026-03']['incomplets']
	|| 125.0 !== $resultat['traiteurs'][10]['total']
	|| 10 !== $resultat['articles'][8]['quantite']
	|| 2 !== $resultat['ruptures'][8]['demandes']
	|| isset( $resultat['ruptures'][9] ) ) {
	throw new RuntimeException( 'Agrégation des statistiques incorrecte.' );
}
$demandes[2]['date'] = '2026-04-02';
$demandes[2]['fin']  = '2026-04-03';
$resultat = ETR_Statistiques::calculer( array_keys( $demandes ), '2026-03-26' );
if ( isset( $resultat['ruptures'][8] ) || $resultat['mois']['2026-04']['total'] !== 50.0 ) {
	throw new RuntimeException( 'Des périodes distinctes ne doivent pas être cumulées.' );
}
if ( ETR_Statistiques::calculer( array(), '2026-03-26' ) !== array( 'mois' => array(), 'traiteurs' => array(), 'articles' => array(), 'ruptures' => array() ) ) {
	throw new RuntimeException( 'Le cas sans demande est incorrect.' );
}
echo "Statistiques OK\n";
