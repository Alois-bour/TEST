<?php
/**
 * Petit générateur de PDF, sans dépendance : pages A4, texte (Helvetica, accents français),
 * traits, rectangles et images JPEG. Suffisant pour un bon de livraison.
 *
 * Les coordonnées sont en points (1/72 de pouce), depuis le coin haut gauche de la page.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Document PDF.
 */
class ETR_PDF {

	const LARGEUR = 595.28;
	const HAUTEUR = 841.89;

	/**
	 * Largeurs des caractères (Helvetica, 1/1000 em), pour couper les lignes.
	 *
	 * @var int[]
	 */
	private static $chasses = array(
		' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333,
		'*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584,
		'>' => 584, '?' => 556, '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
		'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778,
		'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278,
		'\\' => 278, ']' => 278, '_' => 556, 'f' => 278, 'i' => 222, 'j' => 222, 'l' => 222, 'm' => 833, 'r' => 333, 't' => 278,
		'w' => 722,
	);

	/**
	 * Pages : contenu (opérateurs PDF) et images utilisées.
	 *
	 * @var array[]
	 */
	private $pages = array();

	/**
	 * Images JPEG : nom => { donnees, largeur, hauteur, gris }.
	 *
	 * @var array[]
	 */
	private $images = array();

	/**
	 * Métadonnées.
	 *
	 * @var string
	 */
	private $titre = '';

	/**
	 * Document.
	 *
	 * @param string $titre Titre (métadonnées).
	 */
	public function __construct( $titre = '' ) {
		$this->titre = $titre;
		$this->page();
	}

	/**
	 * Nouvelle page.
	 */
	public function page() {
		$this->pages[] = array(
			'contenu' => '',
			'images'  => array(),
		);
	}

	/**
	 * Nombre de pages.
	 *
	 * @return int
	 */
	public function nombre_pages() {
		return count( $this->pages );
	}

	/**
	 * Ajoute des opérateurs à la page courante.
	 *
	 * @param string $operateurs Opérateurs PDF.
	 */
	private function ecrire( $operateurs ) {
		$this->pages[ count( $this->pages ) - 1 ]['contenu'] .= $operateurs . "\n";
	}

	/**
	 * Texte UTF-8 → Windows-1252 (accents français), échappé pour PDF.
	 *
	 * @param string $texte Texte.
	 * @return string
	 */
	private static function chaine( $texte ) {
		// Espace fine insécable : absente de Windows-1252 (guillemets, tirets et apostrophe y sont).
		$texte = str_replace( "\u{202F}", ' ', (string) $texte );
		$texte = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $texte, 'Windows-1252', 'UTF-8' ) : utf8_decode( $texte ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.utf8_decodeDeprecated
		return '(' . strtr( $texte, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ' ) ) . ')';
	}

	/**
	 * Couleur « #rrggbb » → opérandes PDF.
	 *
	 * @param string $couleur Couleur.
	 * @return string
	 */
	private static function rvb( $couleur ) {
		list( $r, $v, $b ) = sscanf( $couleur, '#%02x%02x%02x' );
		return sprintf( '%.3F %.3F %.3F', $r / 255, $v / 255, $b / 255 );
	}

	/**
	 * Largeur d'un texte en points.
	 *
	 * @param string $texte  Texte.
	 * @param float  $taille Corps.
	 * @param bool   $gras   Gras (un peu plus large).
	 * @return float
	 */
	public static function largeur( $texte, $taille, $gras = false ) {
		$total = 0;
		foreach ( preg_split( '//u', (string) $texte, -1, PREG_SPLIT_NO_EMPTY ) as $car ) {
			$base   = remove_accents( $car );
			$total += isset( self::$chasses[ $base ] ) ? self::$chasses[ $base ] : ( ctype_upper( $base ) ? 667 : 556 );
		}
		return $total * $taille / 1000 * ( $gras ? 1.06 : 1 );
	}

	/**
	 * Coupe un texte en lignes d'une largeur maximale.
	 *
	 * @param string $texte   Texte.
	 * @param float  $taille  Corps.
	 * @param float  $largeur Largeur maximale.
	 * @param bool   $gras    Gras.
	 * @return string[]
	 */
	public static function couper( $texte, $taille, $largeur, $gras = false ) {
		$lignes = array();
		foreach ( preg_split( '/\R/u', (string) $texte ) as $paragraphe ) {
			$ligne = '';
			foreach ( preg_split( '/\s+/u', trim( $paragraphe ) ) as $mot ) {
				$essai = '' === $ligne ? $mot : $ligne . ' ' . $mot;
				if ( '' !== $ligne && self::largeur( $essai, $taille, $gras ) > $largeur ) {
					$lignes[] = $ligne;
					$ligne    = $mot;
				} else {
					$ligne = $essai;
				}
			}
			$lignes[] = $ligne;
		}
		return $lignes;
	}

	/**
	 * Écrit une ligne de texte.
	 *
	 * @param float  $x       Gauche.
	 * @param float  $y       Ligne de base, depuis le haut.
	 * @param string $texte   Texte.
	 * @param float  $taille  Corps.
	 * @param bool   $gras    Gras.
	 * @param string $couleur Couleur.
	 * @param string $aligne  gauche | droite (x = bord droit).
	 */
	public function texte( $x, $y, $texte, $taille = 10, $gras = false, $couleur = '#1d1d1b', $aligne = 'gauche' ) {
		if ( 'droite' === $aligne ) {
			$x -= self::largeur( $texte, $taille, $gras );
		}
		$this->ecrire( sprintf( 'BT /%s %.2F Tf %s rg %.2F %.2F Td %s Tj ET', $gras ? 'F2' : 'F1', $taille, self::rvb( $couleur ), $x, self::HAUTEUR - $y, self::chaine( $texte ) ) );
	}

	/**
	 * Paragraphe coupé en lignes ; retourne la position sous le dernier.
	 *
	 * @param float  $x       Gauche.
	 * @param float  $y       Première ligne de base.
	 * @param float  $largeur Largeur.
	 * @param string $texte   Texte.
	 * @param float  $taille  Corps.
	 * @param bool   $gras    Gras.
	 * @param string $couleur Couleur.
	 * @return float
	 */
	public function paragraphe( $x, $y, $largeur, $texte, $taille = 10, $gras = false, $couleur = '#1d1d1b' ) {
		foreach ( self::couper( $texte, $taille, $largeur, $gras ) as $ligne ) {
			$this->texte( $x, $y, $ligne, $taille, $gras, $couleur );
			$y += $taille * 1.35;
		}
		return $y;
	}

	/**
	 * Trait.
	 *
	 * @param float  $x1      Départ.
	 * @param float  $y1      Départ.
	 * @param float  $x2      Arrivée.
	 * @param float  $y2      Arrivée.
	 * @param string $couleur Couleur.
	 * @param float  $epaisseur Épaisseur.
	 */
	public function trait( $x1, $y1, $x2, $y2, $couleur = '#d9d3c8', $epaisseur = 0.6 ) {
		$this->ecrire( sprintf( '%s RG %.2F w %.2F %.2F m %.2F %.2F l S', self::rvb( $couleur ), $epaisseur, $x1, self::HAUTEUR - $y1, $x2, self::HAUTEUR - $y2 ) );
	}

	/**
	 * Rectangle plein.
	 *
	 * @param float  $x       Gauche.
	 * @param float  $y       Haut.
	 * @param float  $l       Largeur.
	 * @param float  $h       Hauteur.
	 * @param string $couleur Couleur.
	 */
	public function rectangle( $x, $y, $l, $h, $couleur ) {
		$this->ecrire( sprintf( '%s rg %.2F %.2F %.2F %.2F re f', self::rvb( $couleur ), $x, self::HAUTEUR - $y - $h, $l, $h ) );
	}

	/**
	 * Image JPEG.
	 *
	 * @param string $jpeg Contenu JPEG.
	 * @param float  $x    Gauche.
	 * @param float  $y    Haut.
	 * @param float  $l    Largeur affichée (la hauteur suit les proportions si $h = 0).
	 * @param float  $h    Hauteur affichée.
	 * @return float Hauteur affichée (0 si l'image est illisible).
	 */
	public function image( $jpeg, $x, $y, $l, $h = 0 ) {
		$infos = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $jpeg ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $infos || IMAGETYPE_JPEG !== $infos[2] ) {
			return 0;
		}
		$nom                  = 'Im' . ( count( $this->images ) + 1 );
		$this->images[ $nom ] = array(
			'donnees' => $jpeg,
			'largeur' => $infos[0],
			'hauteur' => $infos[1],
			'gris'    => isset( $infos['channels'] ) && 1 === $infos['channels'],
		);
		$h = $h ? $h : $l * $infos[1] / max( 1, $infos[0] );
		$this->pages[ count( $this->pages ) - 1 ]['images'][] = $nom;
		$this->ecrire( sprintf( 'q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $l, $h, $x, self::HAUTEUR - $y - $h, $nom ) );
		return $h;
	}

	/**
	 * Le document PDF.
	 *
	 * @return string
	 */
	public function sortie() {
		$objets   = array();
		$objets[] = ''; // 1 : catalogue (rempli plus bas).
		$objets[] = ''; // 2 : arbre des pages.
		$objets[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objets[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$numeros  = array();
		foreach ( $this->images as $nom => $image ) {
			$objets[]        = array(
				'dict'    => sprintf( '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>', $image['largeur'], $image['hauteur'], $image['gris'] ? 'DeviceGray' : 'DeviceRGB', strlen( $image['donnees'] ) ),
				'flux'    => $image['donnees'],
			);
			$numeros[ $nom ] = count( $objets );
		}
		$enfants = array();
		foreach ( $this->pages as $page ) {
			$flux     = function_exists( 'gzcompress' ) ? gzcompress( $page['contenu'] ) : $page['contenu'];
			$objets[] = array(
				'dict' => '<< /Length ' . strlen( $flux ) . ( function_exists( 'gzcompress' ) ? ' /Filter /FlateDecode' : '' ) . ' >>',
				'flux' => $flux,
			);
			$contenu  = count( $objets );
			$xobjets  = '';
			foreach ( array_unique( $page['images'] ) as $nom ) {
				$xobjets .= '/' . $nom . ' ' . $numeros[ $nom ] . ' 0 R ';
			}
			$objets[]  = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> >>', self::LARGEUR, self::HAUTEUR, $contenu, $xobjets ? ' /XObject << ' . $xobjets . '>>' : '' );
			$enfants[] = count( $objets ) . ' 0 R';
		}
		$objets[0] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objets[1] = '<< /Type /Pages /Kids [' . implode( ' ', $enfants ) . '] /Count ' . count( $enfants ) . ' >>';
		$objets[]  = '<< /Title ' . self::chaine( $this->titre ) . ' /Producer (Espace Traiteur) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';
		$infos     = count( $objets );

		$pdf      = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$adresses = array();
		foreach ( $objets as $i => $objet ) {
			$adresses[] = strlen( $pdf );
			$pdf       .= ( $i + 1 ) . " 0 obj\n" . ( is_array( $objet ) ? $objet['dict'] . "\nstream\n" . $objet['flux'] . "\nendstream" : $objet ) . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objets ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $adresses as $adresse ) {
			$pdf .= sprintf( "%010d 00000 n \n", $adresse );
		}
		$pdf .= 'trailer' . "\n<< /Size " . ( count( $objets ) + 1 ) . ' /Root 1 0 R /Info ' . $infos . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}
}
