<?php
/**
 * Bons imprimables d'une demande : bon de préparation (entrepôt) et bon de retour (reprise).
 *
 * Page autonome, sans le thème ni l'application, pensée pour l'impression A4.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bons de préparation et de retour.
 */
class ETR_Bons {

	/**
	 * Types de bons.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'preparation' => __( 'Bon de préparation', 'espace-traiteur' ),
			'retour'      => __( 'Bon de retour', 'espace-traiteur' ),
		);
	}

	/**
	 * Affiche le bon puis arrête l'exécution.
	 *
	 * @param int    $id   Demande.
	 * @param string $type preparation|retour.
	 * @param string $retour URL de retour à la fiche.
	 */
	public static function afficher( $id, $type, $retour ) {
		$demande = get_post( $id );
		// Même règle que l'application : le bureau voit tout, le terrain les demandes validées, livrées ou clôturées.
		if ( ! $demande || ! ETR_Gestion::demande_visible( $id ) ) {
			wp_die( esc_html__( 'Demande introuvable.', 'espace-traiteur' ), '', array( 'response' => 404 ) );
		}
		$types = self::types();
		$type  = isset( $types[ $type ] ) ? $type : 'preparation';
		$titre = $types[ $type ];

		$auteur  = get_userdata( (int) $demande->post_author );
		$champs  = etr_champs_demande();
		$groupes = self::groupes( etr_get_lignes( $id ) );
		$logo    = ETR_Comptes::logo_id();
		$total   = 0;
		foreach ( etr_get_lignes( $id ) as $ligne ) {
			$total += (int) $ligne['quantite'];
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $titre . ' — n° ' . $id ); ?></title>
<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput -- CSS statique. ?></style>
</head>
<body>
<div class="outils">
	<a href="<?php echo esc_url( $retour ); ?>">← <?php esc_html_e( 'Retour à la demande', 'espace-traiteur' ); ?></a>
	<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimer', 'espace-traiteur' ); ?></button>
</div>
<main class="bon bon--<?php echo esc_attr( $type ); ?>">
	<header class="entete">
		<div class="marque">
			<?php
			if ( $logo ) {
				echo wp_get_attachment_image( $logo, 'medium', false, array( 'class' => 'logo', 'alt' => get_bloginfo( 'name' ) ) );
			} else {
				echo '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>';
			}
			?>
		</div>
		<div class="titre">
			<h1><?php echo esc_html( $titre ); ?></h1>
			<p>
				<?php
				/* translators: 1 : numéro, 2 : statut. */
				echo esc_html( sprintf( __( 'Demande n° %1$d · %2$s', 'espace-traiteur' ), $id, wp_strip_all_tags( etr_statut_badge( etr_get_statut( $id ) ) ) ) );
				?>
			</p>
			<p class="muted">
				<?php
				/* translators: %s : date et heure. */
				echo esc_html( sprintf( __( 'Imprimé le %s', 'espace-traiteur' ), wp_date( get_option( 'date_format' ) . ', H:i' ) ) );
				?>
			</p>
		</div>
	</header>

	<section class="infos">
		<div>
			<h2><?php esc_html_e( 'Événement', 'espace-traiteur' ); ?></h2>
			<p class="fort"><?php echo esc_html( get_the_title( $demande ) ); ?></p>
			<p><?php echo etr_valeur_affichee( $id, 'date_evenement', 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php
			$invites = (string) get_post_meta( $id, '_etr_nb_invites', true );
			if ( '' !== $invites ) {
				/* translators: %s : nombre d'invités. */
				echo ' · ' . esc_html( sprintf( __( '%s invités', 'espace-traiteur' ), number_format_i18n( (int) $invites ) ) );
			}
			?>
			</p>
		</div>
		<div>
			<h2><?php esc_html_e( 'Traiteur', 'espace-traiteur' ); ?></h2>
			<?php if ( $auteur ) : ?>
				<p class="fort"><?php echo esc_html( etr_nom( $auteur ) ); ?></p>
				<p><?php echo esc_html( trim( get_user_meta( $auteur->ID, 'etr_contact', true ) . ' ' . get_user_meta( $auteur->ID, 'etr_telephone', true ) ) ); ?></p>
				<p><?php echo esc_html( $auteur->user_email ); ?></p>
			<?php endif; ?>
		</div>
		<div>
			<h2><?php echo esc_html( 'retour' === $type ? __( 'Reprise', 'espace-traiteur' ) : __( 'Livraison', 'espace-traiteur' ) ); ?></h2>
			<p class="fort">
				<?php
				$cle = 'retour' === $type ? 'date_reprise' : 'date_livraison';
				echo '' !== (string) get_post_meta( $id, '_etr_' . $cle, true ) ? etr_valeur_affichee( $id, $cle, 'date' ) : etr_valeur_affichee( $id, 'date_evenement', 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</p>
			<p><?php echo etr_valeur_affichee( $id, 'lieu', 'textarea' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php if ( '' !== (string) get_post_meta( $id, '_etr_contact', true ) ) : ?>
				<p><?php echo esc_html( isset( $champs['contact'] ) ? $champs['contact']['label'] : '' ); ?> : <?php echo etr_valeur_affichee( $id, 'contact', 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php endif; ?>
		</div>
	</section>

	<table class="lignes">
		<thead>
			<tr>
				<?php if ( 'preparation' === $type ) : ?>
					<th class="case"><span class="sr"><?php esc_html_e( 'Préparé', 'espace-traiteur' ); ?></span>✓</th>
					<th><?php esc_html_e( 'Article', 'espace-traiteur' ); ?></th>
					<th><?php esc_html_e( 'Réf.', 'espace-traiteur' ); ?></th>
					<th class="num"><?php esc_html_e( 'Demandé', 'espace-traiteur' ); ?></th>
					<th class="saisie"><?php esc_html_e( 'Préparé', 'espace-traiteur' ); ?></th>
					<th class="notes"><?php esc_html_e( 'Remarques', 'espace-traiteur' ); ?></th>
				<?php else : ?>
					<th><?php esc_html_e( 'Article', 'espace-traiteur' ); ?></th>
					<th><?php esc_html_e( 'Réf.', 'espace-traiteur' ); ?></th>
					<th class="num"><?php esc_html_e( 'Livré', 'espace-traiteur' ); ?></th>
					<th class="saisie"><?php esc_html_e( 'Rendu OK', 'espace-traiteur' ); ?></th>
					<th class="saisie"><?php esc_html_e( 'Casse', 'espace-traiteur' ); ?></th>
					<th class="saisie"><?php esc_html_e( 'Manquant', 'espace-traiteur' ); ?></th>
					<th class="notes"><?php esc_html_e( 'Remarques', 'espace-traiteur' ); ?></th>
				<?php endif; ?>
			</tr>
		</thead>
		<?php foreach ( $groupes as $groupe => $lignes ) : ?>
			<tbody>
				<?php if ( count( $groupes ) > 1 ) : ?>
					<tr class="groupe"><th colspan="<?php echo 'preparation' === $type ? 6 : 7; ?>"><?php echo esc_html( $groupe ); ?></th></tr>
				<?php endif; ?>
				<?php foreach ( $lignes as $ligne ) : ?>
					<tr>
						<?php if ( 'preparation' === $type ) : ?>
							<td class="case"><span class="boite"></span></td>
						<?php endif; ?>
						<td class="article"><?php echo esc_html( $ligne['titre'] ); ?></td>
						<td class="ref"><?php echo esc_html( $ligne['reference'] ); ?></td>
						<td class="num"><strong><?php echo esc_html( number_format_i18n( (int) $ligne['quantite'] ) ); ?></strong><?php echo $ligne['unite'] ? ' <small>' . esc_html( $ligne['unite'] ) . '</small>' : ''; ?></td>
						<td class="saisie"></td>
						<?php if ( 'retour' === $type ) : ?>
							<td class="saisie"></td>
							<td class="saisie"></td>
						<?php endif; ?>
						<td class="notes"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		<?php endforeach; ?>
		<tfoot>
			<tr>
				<th colspan="<?php echo 'preparation' === $type ? 3 : 2; ?>">
					<?php
					/* translators: %s : nombre de lignes. */
					echo esc_html( sprintf( _n( '%s ligne', '%s lignes', count( etr_get_lignes( $id ) ), 'espace-traiteur' ), number_format_i18n( count( etr_get_lignes( $id ) ) ) ) );
					?>
				</th>
				<th class="num"><?php echo esc_html( number_format_i18n( $total ) ); ?></th>
				<th colspan="<?php echo 'preparation' === $type ? 2 : 4; ?>"></th>
			</tr>
		</tfoot>
	</table>

	<?php if ( ! etr_get_lignes( $id ) ) : ?>
		<p class="muted"><?php esc_html_e( 'Aucun article du catalogue.', 'espace-traiteur' ); ?></p>
	<?php endif; ?>

	<?php
	foreach ( array( 'autres_besoins', 'commentaire' ) as $cle ) {
		if ( isset( $champs[ $cle ] ) && '' !== (string) get_post_meta( $id, '_etr_' . $cle, true ) ) {
			echo '<section class="note"><h2>' . esc_html( $champs[ $cle ]['label'] ) . '</h2><p>' . etr_valeur_affichee( $id, $cle, 'textarea' ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	$reponse = (string) get_post_meta( $id, '_etr_reponse', true );
	if ( '' !== $reponse ) {
		echo '<section class="note"><h2>' . esc_html__( 'Message de l’équipe au traiteur', 'espace-traiteur' ) . '</h2><p>' . nl2br( esc_html( $reponse ) ) . '</p></section>';
	}
	?>

	<?php if ( 'retour' === $type ) : ?>
		<section class="note note--vide"><h2><?php esc_html_e( 'Observations à la reprise (casse, manquants, état)', 'espace-traiteur' ); ?></h2></section>
	<?php endif; ?>

	<footer class="signatures">
		<?php
		$signatures = 'retour' === $type
			? array( __( 'Repris par', 'espace-traiteur' ), __( 'Pour le traiteur', 'espace-traiteur' ) )
			: array( __( 'Préparé par', 'espace-traiteur' ), __( 'Contrôlé par', 'espace-traiteur' ) );
		$signatures = apply_filters( 'etr_bon_signatures', $signatures, $type, $id );
		foreach ( $signatures as $signature ) {
			echo '<div><p>' . esc_html( $signature ) . '</p><p class="ligne">' . esc_html__( 'Nom, date et signature', 'espace-traiteur' ) . '</p></div>';
		}
		?>
	</footer>
</main>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Lignes groupées par famille (premier niveau de catégorie), avec la référence.
	 *
	 * @param array $lignes Lignes de la demande.
	 * @return array<string,array>
	 */
	private static function groupes( $lignes ) {
		$groupes = array();
		foreach ( $lignes as $ligne ) {
			$article            = etr_get_article( $ligne['article_id'] );
			$famille            = $article && $article['categorie'] ? explode( ' › ', $article['categorie'] )[0] : __( 'Autres', 'espace-traiteur' );
			$ligne['reference'] = $article ? $article['reference'] : '';
			$ligne['unite']     = isset( $ligne['unite'] ) ? $ligne['unite'] : '';
			$groupes[ $famille ][] = $ligne;
		}
		ksort( $groupes, SORT_NATURAL | SORT_FLAG_CASE );
		return $groupes;
	}

	/**
	 * Styles du bon (écran et impression A4).
	 *
	 * @return string
	 */
	private static function css() {
		return '
*{box-sizing:border-box}
body{margin:0;background:#efebe4;color:#1d1d1b;font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.outils{display:flex;justify-content:space-between;align-items:center;max-width:210mm;margin:16px auto;padding:0 4px}
.outils a{color:#4d5b3e;text-decoration:none}
.outils button{padding:9px 20px;border:0;border-radius:8px;background:#1d1d1b;color:#fff;font:inherit;font-weight:600;cursor:pointer}
.bon{max-width:210mm;margin:0 auto 32px;padding:14mm 12mm;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.entete{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;padding-bottom:12px;border-bottom:2px solid #1d1d1b}
.logo{max-height:56px;width:auto;height:auto}
.titre{text-align:right}
.titre h1{margin:0 0 4px;font:400 24px/1.1 Georgia,"Times New Roman",serif}
.titre p{margin:0}
.muted{color:#6b655f;font-size:12px}
.infos{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:16px 0}
.infos h2,.note h2{margin:0 0 4px;color:#6b655f;font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase}
.infos p{margin:0}
.fort{font-weight:600;font-size:14px}
.lignes{width:100%;border-collapse:collapse;margin-top:8px}
.lignes th,.lignes td{padding:7px 6px;border-bottom:1px solid #d8d2c8;text-align:left;vertical-align:middle}
.lignes thead th{border-bottom:2px solid #1d1d1b;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.lignes .groupe th{padding-top:12px;background:#f6f3ee;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#4d5b3e}
.lignes tfoot th{border-bottom:0;border-top:2px solid #1d1d1b}
.num{text-align:right!important;white-space:nowrap;font-variant-numeric:tabular-nums}
.ref{color:#6b655f;font-size:12px;white-space:nowrap}
.case{width:28px;text-align:center!important}
.boite{display:inline-block;width:14px;height:14px;border:1.5px solid #1d1d1b;border-radius:2px}
.saisie{width:72px;border-left:1px solid #e6e1d8}
th.saisie{white-space:nowrap}
.notes{width:22%;border-left:1px solid #e6e1d8}
.lignes tr{break-inside:avoid}
.note{margin-top:16px;padding:10px 12px;border:1px solid #d8d2c8;border-radius:6px;break-inside:avoid}
.note p{margin:0}
.note--vide{min-height:90px}
.signatures{display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:28px;break-inside:avoid}
.signatures p{margin:0}
.signatures .ligne{margin-top:44px;padding-top:4px;border-top:1px solid #1d1d1b;color:#6b655f;font-size:11px}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
@page{size:A4;margin:10mm}
@media print{body{background:#fff}.outils{display:none}.bon{max-width:none;margin:0;padding:0;box-shadow:none}thead{display:table-header-group}}
@media (max-width:640px){.infos{grid-template-columns:1fr}.bon{padding:16px}.notes{display:none}}
';
	}
}
