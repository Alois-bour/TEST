<?php
/**
 * Administration des comptes traiteurs : création, invitation, désactivation.
 *
 * @package EspaceTraiteur
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page « Espace traiteur › Traiteurs ».
 */
class ETR_Traiteurs {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_etr_creer_traiteur', array( __CLASS__, 'creer' ) );
		add_action( 'admin_post_etr_inviter_traiteur', array( __CLASS__, 'inviter' ) );
		add_action( 'admin_post_etr_basculer_traiteur', array( __CLASS__, 'basculer' ) );
	}

	/**
	 * Sous-menu.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=etr_demande',
			__( 'Traiteurs', 'espace-traiteur' ),
			__( 'Traiteurs', 'espace-traiteur' ),
			'gerer_espace_traiteur',
			'etr-traiteurs',
			array( __CLASS__, 'page' ),
			1
		);
	}

	/**
	 * URL de la page.
	 *
	 * @param array $args Paramètres.
	 * @return string
	 */
	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => 'etr_demande', 'page' => 'etr-traiteurs' ), $args ), admin_url( 'edit.php' ) );
	}

	/**
	 * Envoie (ou renvoie) l'invitation : lien pour choisir son mot de passe.
	 *
	 * @param WP_User $user Traiteur.
	 * @return bool
	 */
	public static function envoyer_invitation( $user ) {
		$cle = get_password_reset_key( $user );
		if ( is_wp_error( $cle ) ) {
			return false;
		}
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		/* translators: %s : nom du site. */
		$objet = sprintf( __( '[%s] Votre accès à l’espace traiteur', 'espace-traiteur' ), $site );
		/* translators: %s : nom du traiteur. */
		$corps  = sprintf( __( 'Bonjour %s,', 'espace-traiteur' ), etr_nom( $user ) ) . "\n\n";
		/* translators: %s : nom du site. */
		$corps .= sprintf( __( 'Votre espace traiteur %s est prêt. Vous pourrez y faire vos demandes de mobilier, d’art de la table et de décoration, et suivre leur traitement.', 'espace-traiteur' ), $site ) . "\n\n";
		/* translators: %s : identifiant. */
		$corps .= sprintf( __( 'Votre identifiant : %s', 'espace-traiteur' ), $user->user_login ) . "\n\n";
		$corps .= __( 'Choisissez votre mot de passe (lien valable 24 heures) :', 'espace-traiteur' ) . "\n";
		$corps .= ETR_Comptes::url_mot_de_passe( $cle, $user->user_login ) . "\n\n";
		$corps .= __( 'Ensuite, connectez-vous à tout moment ici :', 'espace-traiteur' ) . "\n" . etr_url() . "\n";

		$envoye = wp_mail( $user->user_email, $objet, $corps );
		if ( $envoye ) {
			update_user_meta( $user->ID, 'etr_invitation_envoyee', time() );
		}
		return $envoye;
	}

	/**
	 * Date du dernier envoi d'invitation (0 si jamais envoyée).
	 *
	 * @param int $user_id Traiteur.
	 * @return int Horodatage.
	 */
	public static function date_invitation( $user_id ) {
		return (int) get_user_meta( $user_id, 'etr_invitation_envoyee', true );
	}

	/**
	 * Message à afficher après une création, selon le sort de l'invitation.
	 *
	 * @param array $resultat Résultat de creer_compte().
	 * @return string cree | cree_sans_invitation | cree_sans_mail
	 */
	public static function code_creation( $resultat ) {
		if ( null === $resultat['invitation'] ) {
			return 'cree_sans_invitation';
		}
		return $resultat['invitation'] ? 'cree' : 'cree_sans_mail';
	}

	/**
	 * Création d'un traiteur.
	 */
	public static function creer() {
		check_admin_referer( 'etr_creer_traiteur' );
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas le droit de créer des comptes.', 'espace-traiteur' ) );
		}

		$resultat = self::creer_compte(
			array(
				'societe'     => isset( $_POST['societe'] ) ? wp_unslash( $_POST['societe'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé par creer_compte().
				'contact'     => isset( $_POST['contact'] ) ? wp_unslash( $_POST['contact'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'email'       => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'telephone'   => isset( $_POST['telephone'] ) ? wp_unslash( $_POST['telephone'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'identifiant' => isset( $_POST['identifiant'] ) ? wp_unslash( $_POST['identifiant'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			),
			! empty( $_POST['envoyer_invitation'] )
		);
		if ( is_wp_error( $resultat ) ) {
			wp_safe_redirect( self::url( array( 'etr_erreur' => $resultat->get_error_code() ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'etr_notice' => self::code_creation( $resultat ) ) ) );
		exit;
	}

	/**
	 * Crée un compte traiteur et, si demandé, lui envoie son invitation.
	 *
	 * Codes d'erreur : societe, email, email_existe, identifiant_existe, creation.
	 *
	 * @param array $donnees societe, contact, email, telephone, identifiant (bruts).
	 * @param bool  $inviter Envoyer l'invitation tout de suite.
	 * @return array|WP_Error { user_id, invitation : true envoyée, false échec d'envoi, null non demandée }
	 */
	public static function creer_compte( $donnees, $inviter = false ) {
		$societe   = sanitize_text_field( isset( $donnees['societe'] ) ? $donnees['societe'] : '' );
		$contact   = sanitize_text_field( isset( $donnees['contact'] ) ? $donnees['contact'] : '' );
		$email     = sanitize_email( isset( $donnees['email'] ) ? $donnees['email'] : '' );
		$telephone = sanitize_text_field( isset( $donnees['telephone'] ) ? $donnees['telephone'] : '' );
		$login     = sanitize_user( isset( $donnees['identifiant'] ) ? $donnees['identifiant'] : '', true );

		$erreur = '';
		if ( '' === $societe ) {
			$erreur = 'societe';
		} elseif ( ! is_email( $email ) ) {
			$erreur = 'email';
		} elseif ( email_exists( $email ) ) {
			$erreur = 'email_existe';
		}

		if ( ! $erreur ) {
			if ( '' === $login ) {
				$login = sanitize_user( strtolower( remove_accents( strstr( $email, '@', true ) ) ), true );
				$base  = $login ? $login : 'traiteur';
				$login = $base;
				for ( $i = 2; username_exists( $login ); $i++ ) {
					$login = $base . $i;
				}
			} elseif ( username_exists( $login ) ) {
				$erreur = 'identifiant_existe';
			}
		}

		if ( $erreur ) {
			return new WP_Error( $erreur );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $societe,
				'nickname'     => $societe,
				'role'         => 'traiteur',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'creation' );
		}
		update_user_meta( $user_id, 'etr_contact', $contact );
		update_user_meta( $user_id, 'etr_telephone', $telephone );

		return array(
			'user_id'    => $user_id,
			'invitation' => $inviter ? self::envoyer_invitation( get_userdata( $user_id ) ) : null,
		);
	}

	/**
	 * Badge « Accès » : désactivé, à inviter, ou actif (avec la date d'invitation).
	 *
	 * @param bool $desactive Accès désactivé.
	 * @param int  $invite_le Date d'envoi de l'invitation (0 si jamais).
	 * @return string HTML échappé.
	 */
	public static function badge_acces( $desactive, $invite_le ) {
		if ( $desactive ) {
			return '<span class="etr-statut etr-statut--refusee">' . esc_html__( 'Désactivé', 'espace-traiteur' ) . '</span>';
		}
		if ( ! $invite_le ) {
			return '<span class="etr-statut etr-statut--en_attente">' . esc_html__( 'À inviter', 'espace-traiteur' ) . '</span>';
		}
		/* translators: %s : date. */
		return '<span class="etr-statut etr-statut--validee">' . esc_html__( 'Actif', 'espace-traiteur' ) . '</span><small class="etr-acces-date">' . esc_html( sprintf( __( 'Invité le %s', 'espace-traiteur' ), wp_date( 'j M Y', $invite_le ) ) ) . '</small>';
	}

	/**
	 * Désactive (et déconnecte) ou réactive un traiteur.
	 *
	 * @param int $user_id Traiteur.
	 * @return string « desactive » ou « reactive ».
	 */
	public static function basculer_acces( $user_id ) {
		if ( ETR_Comptes::est_desactive( $user_id ) ) {
			delete_user_meta( $user_id, '_etr_desactive' );
			return 'reactive';
		}
		update_user_meta( $user_id, '_etr_desactive', '1' );
		WP_Session_Tokens::get_instance( $user_id )->destroy_all(); // Déconnexion immédiate.
		return 'desactive';
	}

	/**
	 * Renvoi de l'invitation.
	 */
	public static function inviter() {
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		check_admin_referer( 'etr_inviter_' . $user_id );
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'traiteur', (array) $user->roles, true ) ) {
			wp_die( esc_html__( 'Traiteur introuvable.', 'espace-traiteur' ) );
		}
		$envoye = self::envoyer_invitation( $user );
		wp_safe_redirect( self::url( array( 'etr_notice' => $envoye ? 'invite' : 'mail_echec' ) ) );
		exit;
	}

	/**
	 * Désactive ou réactive un accès.
	 */
	public static function basculer() {
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		check_admin_referer( 'etr_basculer_' . $user_id );
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'espace-traiteur' ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'traiteur', (array) $user->roles, true ) ) {
			wp_die( esc_html__( 'Traiteur introuvable.', 'espace-traiteur' ) );
		}

		$notice = self::basculer_acces( $user_id );
		wp_safe_redirect( self::url( array( 'etr_notice' => $notice ) ) );
		exit;
	}

	/**
	 * Page d'administration.
	 */
	public static function page() {
		if ( ! current_user_can( 'gerer_espace_traiteur' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- simples messages après redirection.
		$notice = isset( $_GET['etr_notice'] ) ? sanitize_key( wp_unslash( $_GET['etr_notice'] ) ) : '';
		$erreur = isset( $_GET['etr_erreur'] ) ? sanitize_key( wp_unslash( $_GET['etr_erreur'] ) ) : '';
		// phpcs:enable

		$notices = array(
			'cree'           => __( 'Traiteur créé. Une invitation lui a été envoyée par e-mail pour choisir son mot de passe.', 'espace-traiteur' ),
			'cree_sans_invitation' => __( 'Traiteur créé, sans invitation. Utilisez « Envoyer l’invitation » dans la liste quand vous êtes prêt.', 'espace-traiteur' ),
			'cree_sans_mail' => __( 'Traiteur créé, mais l’e-mail d’invitation n’a pas pu partir. Utilisez « Renvoyer l’invitation ».', 'espace-traiteur' ),
			'invite'         => __( 'Invitation envoyée.', 'espace-traiteur' ),
			'mail_echec'     => __( 'L’e-mail n’a pas pu être envoyé. Vérifiez la configuration des e-mails du site.', 'espace-traiteur' ),
			'desactive'      => __( 'Accès désactivé : le traiteur est déconnecté et ne peut plus se connecter.', 'espace-traiteur' ),
			'reactive'       => __( 'Accès réactivé.', 'espace-traiteur' ),
		);
		$erreurs = array(
			'societe'            => __( 'Le nom de la société est obligatoire.', 'espace-traiteur' ),
			'email'              => __( 'L’adresse e-mail est invalide.', 'espace-traiteur' ),
			'email_existe'       => __( 'Un compte existe déjà avec cette adresse e-mail.', 'espace-traiteur' ),
			'identifiant_existe' => __( 'Cet identifiant est déjà utilisé.', 'espace-traiteur' ),
			'creation'           => __( 'Le compte n’a pas pu être créé.', 'espace-traiteur' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Traiteurs', 'espace-traiteur' ) . '</h1>';
		if ( isset( $notices[ $notice ] ) ) {
			echo '<div class="notice notice-' . ( in_array( $notice, array( 'cree_sans_mail', 'mail_echec' ), true ) ? 'warning' : 'success' ) . ' is-dismissible"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
		}
		if ( isset( $erreurs[ $erreur ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $erreurs[ $erreur ] ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Les traiteurs se connectent uniquement sur la page « Espace traiteur » avec leur identifiant et leur mot de passe. Ils n’ont accès ni à l’administration, ni au compte client de la boutique.', 'espace-traiteur' ) . ' <a href="' . esc_url( etr_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir l’espace traiteur', 'espace-traiteur' ) . '</a></p>';

		self::liste();
		if ( current_user_can( 'gerer_espace_traiteur' ) ) {
			self::formulaire();
		}
		echo '</div>';
	}

	/**
	 * Liste des traiteurs.
	 */
	private static function liste() {
		$traiteurs = get_users(
			array(
				'role'    => 'traiteur',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		echo '<table class="widefat striped etr-traiteurs"><thead><tr>';
		foreach ( array( __( 'Société', 'espace-traiteur' ), __( 'Contact', 'espace-traiteur' ), __( 'E-mail', 'espace-traiteur' ), __( 'Téléphone', 'espace-traiteur' ), __( 'Identifiant', 'espace-traiteur' ), __( 'Demandes', 'espace-traiteur' ), __( 'Accès', 'espace-traiteur' ), __( 'Actions', 'espace-traiteur' ) ) as $titre ) {
			echo '<th>' . esc_html( $titre ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $traiteurs ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Aucun traiteur pour le moment. Ajoutez-en un ci-dessous.', 'espace-traiteur' ) . '</td></tr>';
		}

		foreach ( $traiteurs as $user ) {
			$desactive = ETR_Comptes::est_desactive( $user->ID );
			$demandes  = count_user_posts( $user->ID, 'etr_demande' );
			$inviter   = wp_nonce_url( admin_url( 'admin-post.php?action=etr_inviter_traiteur&user=' . $user->ID ), 'etr_inviter_' . $user->ID );
			$basculer  = wp_nonce_url( admin_url( 'admin-post.php?action=etr_basculer_traiteur&user=' . $user->ID ), 'etr_basculer_' . $user->ID );
			$voir      = add_query_arg(
				array(
					'post_type' => 'etr_demande',
					'author'    => $user->ID,
				),
				admin_url( 'edit.php' )
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( etr_nom( $user ) ) . '</strong></td>';
			echo '<td>' . esc_html( get_user_meta( $user->ID, 'etr_contact', true ) ) . '</td>';
			echo '<td><a href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a></td>';
			echo '<td>' . esc_html( get_user_meta( $user->ID, 'etr_telephone', true ) ) . '</td>';
			echo '<td><code>' . esc_html( $user->user_login ) . '</code></td>';
			echo '<td>' . ( $demandes ? '<a href="' . esc_url( $voir ) . '">' . esc_html( number_format_i18n( $demandes ) ) . '</a>' : '0' ) . '</td>';
			$invite_le = self::date_invitation( $user->ID );
			echo '<td>' . self::badge_acces( $desactive, $invite_le ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans badge_acces().
			echo '<td>';
			if ( ! $desactive ) {
				echo '<a href="' . esc_url( $inviter ) . '">' . ( $invite_le ? esc_html__( 'Renvoyer l’invitation', 'espace-traiteur' ) : '<strong>' . esc_html__( 'Envoyer l’invitation', 'espace-traiteur' ) . '</strong>' ) . '</a> | ';
			}
			echo '<a href="' . esc_url( $basculer ) . '"' . ( $desactive ? '' : ' onclick="return confirm(\'' . esc_js( __( 'Désactiver l’accès de ce traiteur ? Il sera déconnecté immédiatement.', 'espace-traiteur' ) ) . '\');"' ) . '>' . ( $desactive ? esc_html__( 'Réactiver', 'espace-traiteur' ) : esc_html__( 'Désactiver', 'espace-traiteur' ) ) . '</a>';
			if ( current_user_can( 'edit_user', $user->ID ) ) {
				echo ' | <a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html__( 'Modifier', 'espace-traiteur' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Formulaire d'ajout.
	 */
	private static function formulaire() {
		echo '<h2>' . esc_html__( 'Ajouter un traiteur', 'espace-traiteur' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'etr_creer_traiteur' );
		echo '<input type="hidden" name="action" value="etr_creer_traiteur">';
		echo '<table class="form-table" role="presentation"><tbody>';
		$champs = array(
			'societe'     => array( __( 'Société', 'espace-traiteur' ), 'text', true, '' ),
			'contact'     => array( __( 'Nom du contact', 'espace-traiteur' ), 'text', false, '' ),
			'email'       => array( __( 'E-mail', 'espace-traiteur' ), 'email', true, __( 'Adresse à laquelle l’invitation sera envoyée.', 'espace-traiteur' ) ),
			'telephone'   => array( __( 'Téléphone', 'espace-traiteur' ), 'tel', false, '' ),
			'identifiant' => array( __( 'Identifiant', 'espace-traiteur' ), 'text', false, __( 'Facultatif : par défaut, la partie avant le @ de l’e-mail. Le traiteur peut aussi se connecter avec son e-mail.', 'espace-traiteur' ) ),
		);
		foreach ( $champs as $cle => $champ ) {
			printf(
				'<tr><th scope="row"><label for="etr-%1$s">%2$s%3$s</label></th><td><input type="%4$s" class="regular-text" id="etr-%1$s" name="%1$s"%5$s>%6$s</td></tr>',
				esc_attr( $cle ),
				esc_html( $champ[0] ),
				$champ[2] ? ' <span class="description">(' . esc_html__( 'obligatoire', 'espace-traiteur' ) . ')</span>' : '',
				esc_attr( $champ[1] ),
				$champ[2] ? ' required' : '',
				$champ[3] ? '<p class="description">' . esc_html( $champ[3] ) . '</p>' : ''
			);
		}
		echo '<tr><th scope="row">' . esc_html__( 'Invitation', 'espace-traiteur' ) . '</th><td><label><input type="checkbox" name="envoyer_invitation" value="1"> ' . esc_html__( 'Envoyer l’invitation maintenant', 'espace-traiteur' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Sinon, le compte est créé sans e-mail : vous l’enverrez plus tard depuis la liste (« Envoyer l’invitation »).', 'espace-traiteur' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Créer le traiteur', 'espace-traiteur' ) );
		echo '</form>';
	}
}
