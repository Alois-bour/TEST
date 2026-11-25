<?php
/**
 * Tests unitaires du plugin (sans WordPress ni base de données).
 *
 * @package EspaceTraiteur
 */

require __DIR__ . '/bootstrap.php';

echo "Dates de la demande\n";
$j = function ( $n ) {
	return gmdate( 'Y-m-d', strtotime( '2030-06-10 ' . ( $n >= 0 ? '+' : '' ) . $n . ' days' ) );
};
$auj = '2030-06-01';
verifier( array() === etr_erreurs_dates( $j( 0 ), $j( -1 ), $j( 1 ), $auj ), 'veille et lendemain acceptés' );
verifier( array() === etr_erreurs_dates( $j( 0 ), '', '', $auj ), 'sans dates de livraison ni de reprise : accepté' );
verifier( 1 === count( etr_erreurs_dates( $j( 0 ), $j( 3 ), '', $auj ) ), 'livraison après l’événement refusée' );
verifier( 1 === count( etr_erreurs_dates( $j( 0 ), $j( -1 ), $j( -2 ), $auj ) ), 'reprise avant l’événement refusée' );
verifier( 1 === count( etr_erreurs_dates( $j( 0 ), $j( -8 ), '', $auj ) ), 'livraison plus de 7 jours avant refusée' );
verifier( 1 === count( etr_erreurs_dates( $j( 0 ), '', $j( 8 ), $auj ) ), 'reprise plus de 7 jours après refusée' );
verifier( 1 === count( etr_erreurs_dates( '2030-05-01', '', '', $auj ) ), 'événement passé refusé' );
add_filter( 'etr_jours_livraison_avant', function () { return 10; } );
verifier( array() === etr_erreurs_dates( $j( 0 ), $j( -9 ), '', $auj ), 'écart réglable par filtre' );

echo "Nombres et périodes\n";
verifier( 1200.5 === etr_lire_nombre( '1 200,50 €' ), 'montant français relu' );
verifier( null === etr_lire_nombre( '1.200.00' ), 'montant ambigu refusé' );
verifier( array( '2030-06-08', '2030-06-12' ) === etr_periode( '2030-06-10', '2030-06-08', '2030-06-12' ), 'période livraison → reprise' );
verifier( array( '2030-06-10', '2030-06-10' ) === etr_periode( '2030-06-10' ), 'période sans dates = jour de l’événement' );

echo "Estimation de la livraison\n";
$c = ETR_Estimation::chiffrer( array( 'km' => 18.6, 'minutes' => 24.5 ), array_merge( ETR_Estimation::defauts(), array( 'gazole' => 1.70 ) ) );
verifier( abs( $c['km'] - 74.4 ) < 0.001, '2 allers-retours : 74,4 km' );
verifier( abs( $c['cout'] - 111.5868 ) < 0.01, 'coût de revient 111,59 €' );
verifier( 135.0 === $c['prix'], 'prix conseillé arrondi aux 5 € : 135 €' );
$c2 = ETR_Estimation::chiffrer( array( 'km' => 18.6, 'minutes' => 24.5 ), array_merge( ETR_Estimation::defauts(), array( 'gazole' => 1.70 ) ), 2 );
verifier( abs( $c2['km'] - 148.8 ) < 0.001 && $c2['camions'] === 2, 'deux camions : kilomètres doublés' );

echo "Tournée\n";
$o = ETR_Logistique::ordre_court( array( 'lon' => 0, 'lat' => 0 ), array( 'c' => array( 'lon' => 0.3, 'lat' => 0 ), 'a' => array( 'lon' => 0.1, 'lat' => 0 ), 'b' => array( 'lon' => 0.2, 'lat' => 0 ) ) );
verifier( array( 'a', 'b', 'c' ) === $o, 'ordre le plus court sur une ligne' );
$d = ETR_Logistique::distance( array( 'lon' => 6.1236, 'lat' => 48.5639 ), array( 'lon' => 6.1844, 'lat' => 48.6921 ) );
verifier( $d > 14 && $d < 15.5, 'Frolois → Nancy à vol d’oiseau ≈ 15 km' );

echo "Stripe\n";
$t   = time();
$sig = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.{"a":1}', 'whsec_x' );
verifier( ETR_Stripe::signature_valide( '{"a":1}', $sig, 'whsec_x' ), 'signature valide' );
verifier( ! ETR_Stripe::signature_valide( '{"a":2}', $sig, 'whsec_x' ), 'contenu modifié refusé' );
verifier( ! ETR_Stripe::signature_valide( '{"a":1}', $sig, 'whsec_y' ), 'mauvais secret refusé' );
verifier( ! ETR_Stripe::signature_valide( '{"a":1}', $sig, 'whsec_x', $t + 301 ), 'signature de plus de 5 minutes refusée' );
verifier( ! ETR_Stripe::signature_valide( '{"a":1}', $sig, '' ), 'sans secret : refusé' );

echo "Couleurs, factures\n";
verifier( '#808080' === ETR_Apparence::melanger( '#000000', '#ffffff', 0.5 ), 'mélange de couleurs' );
verifier( 'F-2030-0007' === ETR_Facture::format_numero( 'F', 2030, 7 ), 'format du numéro de facture' );

echo "E-mails\n";
$html = ETR_Mail::html( '[Maison Test] Votre demande #8 : Validée', "Bonjour Julie,\n\nVotre demande est validée.\n\nLieu : Château\n12 rue des Vignes\nInvités : 120\n\nArticles demandés :\n- Table × 15\n\nVoir la demande : https://exemple.test/espace/?id=8\n" );
verifier( false !== strpos( $html, '>Votre demande #8 : Validée</h1>' ), 'titre sans le préfixe [Site]' );
verifier( false !== strpos( $html, '>Voir la demande</a>' ), 'adresse transformée en bouton' );
verifier( false !== strpos( $html, 'Château<br />' . "\n" . '12 rue des Vignes' ), 'valeur sur plusieurs lignes gardée dans le tableau' );
verifier( false !== strpos( $html, '>Table × 15</td>' ), 'liste d’articles' );
$injection = ETR_Mail::html( 'x', "Bonjour <b>x</b>,\n\n<script>alert(1)</script>" );
verifier( false === strpos( $injection, '<script>' ) && false === strpos( $injection, '<b>' ), 'HTML du texte échappé' );

echo "PDF\n";
$pdf = new ETR_PDF( 'Test' );
$pdf->texte( 40, 40, 'Parenthèse ( et \\ échappées' );
$sortie = $pdf->sortie();
verifier( 0 === strpos( $sortie, '%PDF-' ) && false !== strpos( $sortie, '%%EOF' ), 'document PDF complet' );
$chaine = new ReflectionMethod( 'ETR_PDF', 'chaine' );
$chaine->setAccessible( true );
verifier( '(a\\(b\\)c\\\\  d)' === $chaine->invoke( null, "a(b)c\\\n d" ), 'parenthèses, barre oblique et retours à la ligne échappés' );

$echecs = isset( $GLOBALS['etr_test_echecs'] ) ? $GLOBALS['etr_test_echecs'] : array();
printf( "\n%d vérifications, %d échec(s).\n", $GLOBALS['etr_test_total'], count( $echecs ) );
exit( $echecs ? 1 : 0 );
