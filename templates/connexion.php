<?php
/**
 * Gabarit plein écran de l'espace traiteur : connexion et application
 * (sans l'en-tête ni le pied de page du thème).
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main id="contenu" class="etr-plein-ecran__main">
	<?php echo do_shortcode( '[espace_traiteur]' ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé par le shortcode. ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
