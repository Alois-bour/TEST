<?php
/**
 * Stock (fonctions activables) :
 * - historique de la casse et des manquants par article (« historique_casse ») ;
 * - inventaire depuis le téléphone (« inventaire ») ;
 * - alertes de stock bas sur les semaines à venir (« alertes_stock »).
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock.
 */
class ETR_Stock_Plus {

	const OPTION             = 'etr_stock_plus';
	const OPTION_INVENTAIRES = 'etr_inventaires';
	const OPTION_ALERTE      = 'etr_alerte_stock_jour';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reglages' ) );
		add_action( 'etr_fonctions_reglages', array( __CLASS__, 'champs_reglages' ) );
		add_action( 'etr_gestion_statistiques', array( __CLASS__, 'statistiques_casse' ), 10 );
		add_filter( 'etr_gestion_vues', array( __CLASS__, 'vues' ) );
		add_action( 'etr_gestion_vue_inventaire', array( __CLASS__, 'vue_inventaire' ) );
		add_action( 'etr_gestion_action', array( __CLASS__, 'action_inventaire' ) );
		add_action( 'etr_gestion_catalogue', array( __CLASS__, 'alertes_catalogue' ), 5 );
		add_action( 'etr_rappels_horaires', array( __CLASS__, 'alertes_quotidiennes' ), 40 );
	}

	/**
	 * Paramètres par défaut.
	 *
	 * @return array
	 */
	public static function defauts() {
		return array(
			'seuil'     => 20,  // % du stock encore libre en dessous duquel on alerte.
			'horizon'   => 30,  // Jours examinés.
			'rachat'    => 10,  // % de pertes (casse + manquants) au-delà duquel l'article est « à racheter ».
		);
	}

	/**
	 * Paramètres.
	 *
	 * @return array
	 */
	public static function parametres() {
		$p = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $p ) ? $p : array(), self::defauts() );
	}

	/**
	 * Option.
	 */
	public static function reglages() {
		register_setting(
			'etr_modules',
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => function ( $saisie ) {
					$propre = self::parametres();
					foreach ( array( 'seuil' => array( 1, 100 ), 'horizon' => array( 1, 180 ), 'rachat' => array( 1, 100 ) ) as $cle => $b ) {
						if ( isset( $saisie[ $cle ] ) ) {
							$propre[ $cle ] = min( $b[1], max( $b[0], absint( $saisie[ $cle ] ) ) );
						}
					}
					return $propre;
				},
			)
		);
	}

	/**
	 * Champs sous les fonctions.
	 *
	 * @param string $cle Fonction.
	 */
	public static function champs_reglages( $cle ) {
		$p   = self::parametres();
		$nom = function ( $champ ) {
			return esc_attr( self::OPTION . '[' . $champ . ']' );
		};
		if ( 'alertes_stock' === $cle ) {
			echo '<p><label>' . esc_html__( 'Alerter quand il reste moins de', 'espace-traiteur' ) . ' <input type="number" min="1" max="100" class="small-text" name="' . $nom( 'seuil' ) . '" value="' . esc_attr( (string) $p['seuil'] ) . '"> % ' . esc_html__( 'du stock libre, sur les', 'espace-traiteur' ) . ' <input type="number" min="1" max="180" class="small-text" name="' . $nom( 'horizon' ) . '" value="' . esc_attr( (string) $p['horizon'] ) . '"> ' . esc_html__( 'prochains jours', 'espace-traiteur' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'historique_casse' === $cle ) {
			echo '<p><label>' . esc_html__( 'Signaler « à racheter » au-delà de', 'espace-traiteur' ) . ' <input type="number" min="1" max="100" class="small-text" name="' . $nom( 'rachat' ) . '" value="' . esc_attr( (string) $p['rachat'] ) . '"> % ' . esc_html__( 'de pertes (casse et manquants)', 'espace-traiteur' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ------------------------------------------------------------------ */
	/* Historique de la casse                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Casse et manquants par article sur 12 mois.
	 *
	 * @return array article => { titre, repris, casses, manquants, taux }
	 */
	public static function historique() {
		$ids = get_posts(
			array(
				'post_type'     => 'etr_demande',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_key'      => ETR_Operations::META_REPRISE, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$h      = array();
		$depuis = time() - YEAR_IN_SECONDS;
		foreach ( $ids as $id ) {
			$reprise = ETR_Operations::reprise( $id );
			if ( ! $reprise || (int) $reprise['le'] < $depuis ) {
				continue;
			}
			foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
				$a = (int) $ligne['article_id'];
				if ( ! isset( $h[ $a ] ) ) {
					$h[ $a ] = array( 'titre' => $ligne['titre'], 'repris' => 0, 'casses' => 0, 'manquants' => 0 );
				}
				$q                     = (int) $ligne['quantite'];
				$rendu                 = isset( $reprise['lignes'][ $index ]['rendu'] ) ? (int) $reprise['lignes'][ $index ]['rendu'] : $q;
				$h[ $a ]['repris']    += $q;
				$h[ $a ]['casses']    += isset( $reprise['lignes'][ $index ]['casse'] ) ? (int) $reprise['lignes'][ $index ]['casse'] : 0;
				$h[ $a ]['manquants'] += max( 0, $q - $rendu );
			}
		}
		foreach ( $h as &$l ) {
			$l['taux'] = $l['repris'] ? 100 * ( $l['casses'] + $l['manquants'] ) / $l['repris'] : 0;
		}
		unset( $l );
		uasort(
			$h,
			function ( $a, $b ) {
				return ( $b['casses'] + $b['manquants'] ) <=> ( $a['casses'] + $a['manquants'] );
			}
		);
		return $h;
	}

	/**
	 * Statistiques : articles qui cassent ou disparaissent le plus.
	 */
	public static function statistiques_casse() {
		if ( ! ETR_Modules::actif( 'historique_casse' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		$h = array_filter(
			self::historique(),
			function ( $l ) {
				return $l['casses'] + $l['manquants'] > 0;
			}
		);
		$seuil = (int) self::parametres()['rachat'];
		echo '<div class="etr-stats etr-stats--terrain"><section class="etr-stats__section"><div class="etr-stats__entete"><div><h2 class="etr-section-titre">' . esc_html__( 'Casse et manquants par article', 'espace-traiteur' ) . '</h2><p>' . esc_html__( '12 derniers mois, d’après les reprises. « À racheter » : pertes au-delà du seuil réglé dans Fonctions.', 'espace-traiteur' ) . '</p></div></div>';
		if ( ! $h ) {
			echo '<p class="etr-stats__vide">' . esc_html__( 'Aucune casse ni aucun manquant enregistré.', 'espace-traiteur' ) . '</p></section></div>';
			return;
		}
		echo '<div class="etr-table-cadre" tabindex="0" role="region"><table class="etr-table etr-stats__table"><thead><tr><th scope="col">' . esc_html__( 'Article', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Repris', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Cassés', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Manquants', 'espace-traiteur' ) . '</th><th scope="col" class="etr-table__nombre">' . esc_html__( 'Pertes', 'espace-traiteur' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $h, 0, 20, true ) as $l ) {
			$alerte = $l['taux'] >= $seuil;
			echo '<tr><td>' . esc_html( $l['titre'] ) . ( $alerte ? ' <span class="etr-pastille-etat etr-pastille-etat--alerte">' . esc_html__( 'À racheter', 'espace-traiteur' ) . '</span>' : '' ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $l['repris'] ) ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $l['casses'] ) ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $l['manquants'] ) ) . '</td><td class="etr-table__nombre">' . esc_html( number_format_i18n( $l['taux'], 1 ) ) . ' %</td></tr>';
		}
		echo '</tbody></table></div></section></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Inventaire                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Onglet « Inventaire » (bureau).
	 *
	 * @param array $vues Vues.
	 * @return array
	 */
	public static function vues( $vues ) {
		if ( ETR_Modules::actif( 'inventaire' ) ) {
			$vues['inventaire'] = array( __( 'Inventaire', 'espace-traiteur' ), 'catalogue' );
		}
		return $vues;
	}

	/**
	 * Quantités actuellement chez les traiteurs (demandes livrées, pas encore reprises).
	 *
	 * @return array<int,int>
	 */
	public static function dehors() {
		$ids = get_posts(
			array(
				'post_type'     => 'etr_demande',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_key'      => '_etr_statut', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'    => 'livree', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$d = array();
		foreach ( $ids as $id ) {
			// Reprise faite avec écarts (demande encore « livrée ») : le matériel rendu est revenu,
			// les manquants sont toujours dehors.
			$reprise = ETR_Operations::reprise( $id );
			$rendus  = $reprise && isset( $reprise['lignes'] ) ? (array) $reprise['lignes'] : null;
			foreach ( etr_get_lignes( $id ) as $index => $ligne ) {
				$quantite = (int) $ligne['quantite'];
				if ( null !== $rendus ) {
					$rendu    = isset( $rendus[ $index ]['rendu'] ) ? (int) $rendus[ $index ]['rendu'] : $quantite;
					$quantite = max( 0, $quantite - $rendu );
				}
				if ( $quantite ) {
					$d[ (int) $ligne['article_id'] ] = ( isset( $d[ (int) $ligne['article_id'] ] ) ? $d[ (int) $ligne['article_id'] ] : 0 ) + $quantite;
				}
			}
		}
		return $d;
	}

	/**
	 * Quantité attendue à l'entrepôt : possédé − en réparation − chez les traiteurs.
	 *
	 * @param int   $article Article.
	 * @param array $dehors  Résultat de dehors().
	 * @return array { possede, reparation, dehors, attendu }
	 */
	public static function attendu( $article, $dehors ) {
		$possede    = (int) etr_stock_brut( $article );
		$liste      = ETR_Modules::actif( 'reparation' ) ? ETR_Materiel::en_reparation() : array();
		$reparation = isset( $liste[ $article ] ) ? (int) $liste[ $article ] : 0;
		$sorti      = isset( $dehors[ $article ] ) ? (int) $dehors[ $article ] : 0;
		return array(
			'possede'    => $possede,
			'reparation' => $reparation,
			'dehors'     => $sorti,
			'attendu'    => max( 0, $possede - $reparation - $sorti ),
		);
	}

	/**
	 * Écran « Inventaire » : compter, comparer, corriger le stock.
	 */
	public static function vue_inventaire() {
		if ( ! ETR_Modules::actif( 'inventaire' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		$dehors   = self::dehors();
		$articles = array_filter(
			etr_catalogue(),
			function ( $a ) {
				return null !== etr_stock_brut( $a['id'] );
			}
		);
		ETR_Gestion::entete( __( 'Inventaire', 'espace-traiteur' ), __( 'Comptez ce qui est à l’entrepôt : l’écart avec le stock attendu s’affiche, puis corrigez le stock en un clic.', 'espace-traiteur' ) );
		$inventaires = get_option( self::OPTION_INVENTAIRES, array() );
		$dernier     = is_array( $inventaires ) && $inventaires ? end( $inventaires ) : null;
		if ( $dernier ) {
			/* translators: 1 : date, 2 : personne, 3 : nombre d'écarts. */
			echo '<p class="etr-notice etr-notice--info">' . esc_html( sprintf( __( 'Dernier inventaire le %1$s par %2$s : %3$s écart(s).', 'espace-traiteur' ), wp_date( 'j F Y à H:i', (int) $dernier['le'] ), etr_nom( get_userdata( (int) $dernier['par'] ) ), number_format_i18n( count( $dernier['ecarts'] ) ) ) ) . '</p>';
		}
		if ( ! $articles ) {
			echo '<div class="etr-vide"><p>' . esc_html__( 'Aucun article à stock suivi (renseignez la quantité possédée des produits).', 'espace-traiteur' ) . '</p></div>';
			return;
		}
		echo '<form method="post" class="etr-inventaire" action="' . esc_url( ETR_Gestion::url( array( 'vue' => 'inventaire' ) ) ) . '">';
		wp_nonce_field( 'etr_inventaire', 'etr_nonce' );
		echo '<input type="hidden" name="etr_gestion_action" value="inventaire">';
		echo '<p><input type="search" class="etr-inventaire__filtre" placeholder="' . esc_attr__( 'Filtrer les articles…', 'espace-traiteur' ) . '" oninput="var q=this.value.toLowerCase();document.querySelectorAll(\'.etr-inventaire__ligne\').forEach(function(l){l.hidden=l.dataset.r.indexOf(q)<0;});"></p>';
		echo '<ul class="etr-inventaire__liste">';
		foreach ( $articles as $a ) {
			$s = self::attendu( $a['id'], $dehors );
			echo '<li class="etr-inventaire__ligne" data-r="' . esc_attr( mb_strtolower( $a['titre'] . ' ' . $a['reference'] ) ) . '" data-attendu="' . (int) $s['attendu'] . '">';
			echo '<span class="etr-inventaire__article"><strong>' . esc_html( $a['titre'] ) . '</strong><small>';
			/* translators: 1 : attendu, 2 : possédé, 3 : chez les traiteurs, 4 : en réparation. */
			echo esc_html( sprintf( __( 'Attendu %1$s (possédé %2$s, chez les traiteurs %3$s, en réparation %4$s)', 'espace-traiteur' ), number_format_i18n( $s['attendu'] ), number_format_i18n( $s['possede'] ), number_format_i18n( $s['dehors'] ), number_format_i18n( $s['reparation'] ) ) );
			echo '</small></span><label class="screen-reader-text" for="etr-inv-' . (int) $a['id'] . '">' . esc_html__( 'Compté', 'espace-traiteur' ) . '</label>';
			echo '<input type="number" min="0" inputmode="numeric" id="etr-inv-' . (int) $a['id'] . '" name="compte[' . (int) $a['id'] . ']" placeholder="' . esc_attr( (string) $s['attendu'] ) . '" oninput="var e=this.value===\'\'?\'\':(this.value-this.closest(\'li\').dataset.attendu);this.nextElementSibling.textContent=e===\'\'?\'\':(e>0?\'+\':\'\')+e;this.nextElementSibling.className=\'etr-inventaire__ecart\'+(e<0?\' est-negatif\':e>0?\' est-positif\':\'\');"><span class="etr-inventaire__ecart"></span></li>';
		}
		echo '</ul>';
		echo '<p class="etr-case"><label><input type="checkbox" name="corriger" value="1" checked> ' . esc_html__( 'Corriger le stock possédé avec les écarts (sinon, seulement noter l’inventaire)', 'espace-traiteur' ) . '</label></p>';
		echo '<p class="etr-barre-action"><button type="submit" class="etr-btn">' . esc_html__( 'Enregistrer l’inventaire', 'espace-traiteur' ) . '</button></p></form>';
	}

	/**
	 * Enregistre l'inventaire.
	 *
	 * @param string $action Action.
	 */
	public static function action_inventaire( $action ) {
		if ( 'inventaire' !== $action || ! ETR_Modules::actif( 'inventaire' ) ) {
			return;
		}
		check_admin_referer( 'etr_inventaire', 'etr_nonce' );
		$comptes = isset( $_POST['compte'] ) && is_array( $_POST['compte'] ) ? wp_unslash( $_POST['compte'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nombres lus ci-dessous.
		$corriger = ! empty( $_POST['corriger'] );
		$dehors   = self::dehors();
		$ecarts   = array();
		foreach ( $comptes as $article => $compte ) {
			$article = absint( $article );
			if ( '' === trim( (string) $compte ) || ! etr_get_article( $article ) || null === etr_stock_brut( $article ) ) {
				continue;
			}
			$s     = self::attendu( $article, $dehors );
			$ecart = absint( $compte ) - $s['attendu'];
			if ( 0 === $ecart ) {
				continue;
			}
			$ecarts[ $article ] = $ecart;
			if ( $corriger ) {
				self::fixer_stock( $article, max( 0, $s['possede'] + $ecart ) );
			}
		}
		$inventaires   = get_option( self::OPTION_INVENTAIRES, array() );
		$inventaires   = is_array( $inventaires ) ? $inventaires : array();
		$inventaires[] = array( 'le' => time(), 'par' => get_current_user_id(), 'ecarts' => $ecarts, 'corrige' => $corriger );
		update_option( self::OPTION_INVENTAIRES, array_slice( $inventaires, -24 ), false );
		wp_safe_redirect( ETR_Gestion::url( array( 'vue' => 'inventaire', 'etr_msg' => 'fait' ) ) );
		exit;
	}

	/**
	 * Fixe la quantité possédée (réglage du plugin, sinon stock WooCommerce).
	 *
	 * @param int $article  Article.
	 * @param int $quantite Quantité.
	 */
	public static function fixer_stock( $article, $quantite ) {
		$propre = get_post_meta( $article, '_etr_stock', true );
		if ( '' === $propre && etr_source_woocommerce() && function_exists( 'wc_get_product' ) ) {
			$produit = wc_get_product( $article );
			if ( $produit && $produit->managing_stock() ) {
				wc_update_product_stock( $produit, $quantite );
				return;
			}
		}
		update_post_meta( $article, '_etr_stock', (int) $quantite );
	}

	/* ------------------------------------------------------------------ */
	/* Alertes de stock bas                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Articles en stock bas dans les jours à venir.
	 *
	 * @return array[] { article, titre, libre, stock, jour }
	 */
	public static function stock_bas() {
		$p      = self::parametres();
		$auj    = current_time( 'Y-m-d' );
		$alerte = array();
		foreach ( etr_catalogue() as $a ) {
			$stock = etr_stock_total( $a['id'] );
			if ( null === $stock || $stock < 1 ) {
				continue;
			}
			$jours = ETR_Traiteur_Plus::dispo_jours( $a['id'], $auj, (int) $p['horizon'] );
			$min   = min( $jours );
			if ( $min <= $stock * $p['seuil'] / 100 ) {
				$alerte[] = array(
					'article' => $a['id'],
					'titre'   => $a['titre'],
					'libre'   => (int) $min,
					'stock'   => (int) $stock,
					'jour'    => (string) array_search( $min, $jours, true ),
				);
			}
		}
		return $alerte;
	}

	/**
	 * Catalogue : encart des articles en stock bas.
	 */
	public static function alertes_catalogue() {
		if ( ! ETR_Modules::actif( 'alertes_stock' ) || ! ETR_Equipe::bureau() ) {
			return;
		}
		$bas = self::stock_bas();
		if ( ! $bas ) {
			return;
		}
		echo '<section class="etr-panneau etr-stock-bas"><h2 class="etr-panneau__titre">' . esc_html__( 'Stock bas à venir', 'espace-traiteur' ) . ' <span class="etr-compteur">' . esc_html( number_format_i18n( count( $bas ) ) ) . '</span></h2><ul class="etr-liste-simple">';
		foreach ( $bas as $b ) {
			/* translators: 1 : article, 2 : libre, 3 : stock, 4 : date. */
			echo '<li>' . esc_html( sprintf( __( '%1$s : %2$s libre(s) sur %3$s le %4$s', 'espace-traiteur' ), $b['titre'], number_format_i18n( $b['libre'] ), number_format_i18n( $b['stock'] ), etr_format_date( $b['jour'] ) ) ) . '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Une notification par jour au bureau (à partir de 8 h) s'il y a du stock bas.
	 */
	public static function alertes_quotidiennes() {
		if ( ! ETR_Modules::actif( 'alertes_stock' ) ) {
			return;
		}
		$maintenant = current_datetime();
		$jour       = $maintenant->format( 'Y-m-d' );
		if ( (int) $maintenant->format( 'G' ) < 8 || get_option( self::OPTION_ALERTE ) === $jour ) {
			return;
		}
		update_option( self::OPTION_ALERTE, $jour, false );
		$bas = self::stock_bas();
		if ( ! $bas ) {
			return;
		}
		$noms = array_map(
			function ( $b ) {
				return $b['titre'] . ' (' . $b['libre'] . '/' . $b['stock'] . ')';
			},
			array_slice( $bas, 0, 4 )
		);
		/* translators: %s : nombre d'articles. */
		ETR_Push::notifier( 'bureau', sprintf( _n( 'Stock bas : %s article', 'Stock bas : %s articles', count( $bas ), 'espace-traiteur' ), number_format_i18n( count( $bas ) ) ), implode( ', ', $noms ) . ( count( $bas ) > 4 ? '…' : '' ), 0, ETR_Gestion::url( array( 'vue' => 'catalogue' ) ), 'stock' );
	}
}
