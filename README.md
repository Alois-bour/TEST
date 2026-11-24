# Espace Traiteur — plugin WordPress

Un espace privé pour les traiteurs partenaires de [Maison Anamé](https://maisonaname.com). Chaque traiteur se connecte à **son** espace et demande du mobilier, de l'art de la table, du textile ou de la décoration pour ses événements. L'équipe traite les demandes depuis l'administration WordPress.

## Catalogue : les produits WooCommerce du site

Quand WooCommerce est actif (cas de maisonaname.com), le plugin **reprend directement les produits du site** : pas de double saisie.

- Sont proposés les produits **publiés** et visibles dans le catalogue, regroupés par famille et sous-catégorie (ex. *Mobilier › Tables*, *Art de la table › Verrerie*).
- *Espace traiteur › Réglages* : choisir les catégories proposées (toutes par défaut), afficher ou non les prix du site (catalogue, et prix de chaque ligne et total indicatif dans le détail d'une demande), e-mail de l'équipe.
- En haut de cette page, un **récapitulatif** indique le nombre de produits publiés, proposés et écartés, la liste des produits écartés avec la raison (non publié, visibilité « Masqué », case « Masquer » cochée, hors des catégories proposées), et les produits proposés sans quantité possédée.
- Sur chaque produit, onglet **Inventaire**, bloc « Espace traiteur » :
  - **Quantité possédée** : nombre de pièces réservables par les traiteurs ;
  - **Unité** (pièce(s), lot(s) de 10…) ;
  - **Masquer** : ne pas proposer ce produit aux traiteurs.
- Si « Quantité possédée » est vide : le stock WooCommerce est utilisé s'il est géré ; un produit « en rupture » est indisponible ; sinon la quantité est illimitée.
- Chaque article affiche sa photo, sa description, un lien « Voir la fiche » vers la page produit, et le prix si l'option est cochée.

Sans WooCommerce, le plugin utilise son propre catalogue (menu *Espace traiteur › Catalogue*).

## Installation

1. Copier le dossier du plugin dans `wp-content/plugins/espace-traiteur/` (ou zipper le dépôt et l'importer via *Extensions › Ajouter*).
2. Activer **Espace Traiteur**.

À l'activation, le plugin :

- crée le rôle **Traiteur** ;
- crée la page **Espace traiteur** (`/espace-traiteur/`) avec le shortcode `[espace_traiteur]` ;
- sans WooCommerce seulement : crée les catégories par défaut de son catalogue interne.

## Utilisation

### Côté équipe (administrateurs)

**Espace de gestion** (`/gestion-traiteur/`, lien « Espace de gestion » dans le menu *Espace traiteur* et « Gestion traiteurs » dans la barre d'admin) : un back-office plein écran, dans l'identité de l'espace traiteur, réservé à l'équipe (droit `gerer_espace_traiteur`). Les écrans WordPress restent disponibles.

- **Tableau de bord** : compteurs (demandes à traiter, événements validés, livraisons et reprises des 7 jours), agenda des 7 prochains jours, file « À traiter » avec alerte sur le matériel insuffisant.
- **Demandes** : liste classée par date d'événement, filtres par statut et par traiteur, recherche ; **export CSV** (filtres appliqués) : une ligne par demande (dates, statut, sous-total, remise, livraison, total) ou une ligne par article (référence, quantité, prix), pour Excel, Odoo ou la comptabilité.
- **Fiche d'une demande** : matériel et disponibilités sur la période, prix de chaque ligne et prix final indicatif (tarifs actuels du site) ; **tarification** : remise en % (ex. traiteur partenaire) et frais de livraison et reprise, intégrés au prix final (sous-total, remise, livraison, total), aussi modifiables dans l'administration WordPress ; **documents à imprimer** : bon de préparation (liste à cocher pour l'entrepôt, groupée par famille) et bon de retour (rendu OK, casse, manquants, observations, signatures), au format A4, informations de l'événement, coordonnées du traiteur ; choix du statut et message au traiteur (e-mail envoyé si le statut change) ; **historique** de la demande (envoi, changements de statut, messages, modifications et annulation : qui, quoi, quand), aussi visible dans l'administration WordPress.
- **Planning** : calendrier du mois avec livraisons, événements et reprises (en pointillés : demandes encore en attente) ; export CSV des mouvements du mois.
- **Traiteurs** : liste, nombre de demandes, ajout d'un traiteur, renvoi d'invitation, désactivation et réactivation.
- **Catalogue** : stock, quantités sorties et disponibles aujourd'hui, lien vers la fiche produit ; recherche instantanée (nom, référence, catégorie) pendant la frappe.

Sans permaliens, l'adresse est `/?etr_gestion=1`. Filtre `etr_gestion_slug` pour changer `gestion-traiteur`.

Écrans WordPress :

- **Espace traiteur › Traiteurs** : créer un traiteur (société, contact, e-mail, téléphone). Il reçoit une invitation par e-mail pour choisir son mot de passe. Depuis cette page : renvoyer l'invitation, désactiver ou réactiver un accès, voir ses demandes.
- **Produits WooCommerce** : renseigner la « Quantité possédée » des articles à limiter (voir ci-dessus).
- **Espace traiteur › Réglages** : catégories proposées, prix, e-mail de l'équipe, affichage de l'espace des traiteurs (application plein écran ou intégrée au site).
- **Espace traiteur › Demandes** : voir les demandes, filtrer par statut, changer le statut et écrire un message au traiteur.

Statuts : En attente, Validée, Refusée, Livrée, Clôturée, Annulée.

### Côté traiteur

Sur la page **Espace traiteur**, le traiteur :

- se connecte avec son identifiant (ou son e-mail) et son mot de passe, sur la page Espace traiteur uniquement ;
- arrive sur un tableau de bord : son **prochain événement** mis en avant (date, lieu, délai, statut), puis toutes **ses** demandes classées par date d'événement, filtrables par statut (il ne voit jamais celles des autres) ;
- crée une demande en quatre étapes : événement (nom, date, invités, lieu, contact), livraison et reprise, catalogue, compléments (besoins hors catalogue, commentaire). Un récapitulatif « Votre sélection » reste visible à côté du formulaire ;
- choisit parmi le mobilier et la vaisselle **disponibles à ses dates** : dès qu'il saisit la date, chaque article affiche la quantité encore libre et les articles complets sont grisés. Recherche par nom ou référence, filtre par famille, boutons − / + pour les quantités ;
- consulte le détail : suivi de la demande (Envoyée, Validée, Livrée, Reprise et clôturée), réponse de l'équipe, informations et matériel demandé ;
- peut annuler une demande tant qu'elle est « En attente ».

### Présentation de l'espace

Une fois connecté, le traiteur utilise l'espace comme une application : barre latérale (logo, navigation, compte, retour au site, déconnexion), contenu en pleine largeur, sans l'en-tête ni le pied de page du thème. Sur mobile, la barre latérale devient une barre en haut de l'écran.

- *Espace traiteur › Réglages › Espace des traiteurs* : décocher « Afficher l'espace en plein écran » pour l'afficher dans la mise en page du site (navigation en bandeau au-dessus du contenu).
- Mêmes polices que le site (serif italique pour les titres et les dates, Roboto pour le texte), couleurs sobres, un seul accent vert olive.
- Clavier, lecteurs d'écran (`aria-current`, libellés) et réglage « réduire les animations » pris en compte.

## Page de connexion

Écran en deux panneaux, dans l'esprit du site : à gauche une grande photo d'ambiance avec une accroche, à droite le logo, un titre en serif italique et un formulaire épuré (bouton « Afficher » le mot de passe, état de chargement, messages clairs). Les écrans « Mot de passe oublié » et « Choisir votre mot de passe » reprennent la même présentation. Adapté au mobile (la photo devient un bandeau).

- **Plein écran** par défaut : la page de connexion occupe toute la fenêtre, sans l'en-tête ni le pied de page du site, avec un lien « Retour au site ». Désactivable dans les réglages (l'espace connecté a son propre réglage, voir ci-dessus).
- **Logo et photo automatiques** : sans choix dans les réglages, le plugin utilise le logo du site (logo du thème, logo Elementor, ou fichier `maison-aname-logo1` de la médiathèque) et une photo d'ambiance du site (`location-art-de-la-table-mariage-nancy`, puis d'autres photos de la page d'accueil, l'image d'une catégorie de produits ou l'image de la page d'accueil). Filtres `etr_logos_par_defaut` et `etr_photos_par_defaut`.
- *Espace traiteur › Réglages › Page de connexion* : choisir un autre **logo** ou une autre **photo** dans la médiathèque ; l'image utilisée par défaut y est affichée.
- Polices : celles du site si elles sont chargées par le thème (Ethic Serif, Cormorant Garamond, Roboto), sinon des polices proches.
- Le titre de page du thème est masqué sur l'écran de connexion.
- Filtre `etr_slogan_connexion` pour changer l'accroche.

## Comptes traiteurs : aucun accès à WordPress

Les traiteurs sont techniquement des utilisateurs WordPress (rôle « Traiteur »), pour profiter de la sécurité de WordPress (mots de passe chiffrés, sessions), mais **ils n'ont accès qu'à leur espace** :

- **Connexion uniquement sur la page Espace traiteur.** Sur `wp-login.php`, le « Mon compte » WooCommerce ou XML-RPC, la connexion d'un traiteur est refusée, avec le même message que le mot de passe soit juste ou faux.
- **Choix du mot de passe et mot de passe oublié dans l'espace** : les liens envoyés par e-mail mènent à l'espace traiteur, jamais aux écrans WordPress (y compris si la demande part de `wp-login.php`). Liens valables 24 h et utilisables une seule fois.
- **Tentatives limitées** : après 5 échecs, le couple adresse IP + identifiant est bloqué 15 minutes, sans gêner les autres traiteurs.
- **Rien d'autre** : pas d'administration (`/wp-admin` renvoie vers l'espace, le rôle n'a même pas le droit « lecture »), pas de barre d'admin, pas de « Mon compte », panier, paiement ni commande WooCommerce, pas d'API WordPress (sauf la Store API publique nécessaire à l'affichage du site), pas de mots de passe d'application.
- **Désactivation** depuis *Espace traiteur › Traiteurs* : le traiteur est déconnecté immédiatement et ne peut plus se reconnecter ; ses demandes sont conservées.

## Disponibilités

- Le **stock total** d'un article est sa « Quantité possédée » (voir plus haut). 0 = indisponible (ex. : en réparation).
- Le matériel est bloqué de la **date de livraison** (ou du jour J) jusqu'à la **date de reprise** (ou au jour J).
- Les demandes **En attente**, **Validée** et **Livrée** bloquent le matériel. Une demande **Refusée**, **Annulée** ou **Clôturée** le libère.
- Le contrôle est fait deux fois : à l'affichage (mise à jour dès le choix des dates) et à l'envoi (le serveur refuse toute quantité supérieure à ce qui reste).
- Dans l'admin, la fiche d'une demande indique pour chaque article ce qui reste disponible sur la période, hors cette demande, et signale en rouge un manque.
- Calcul prudent : toute demande dont la période chevauche celle demandée est déduite, même si elles ne se chevauchent pas entre elles.

## Notifications e-mail

| Événement | Destinataire |
|---|---|
| Nouvelle demande | Équipe (e-mail des réglages, sinon e-mail d'administration du site) |
| Demande annulée par le traiteur | Équipe |
| Changement de statut | Traiteur concerné |
| Rappel de livraison (J-3) et de reprise (lendemain de l'événement) | Traiteur concerné |

Les rappels concernent les demandes validées ou livrées (pour la livraison, uniquement les demandes validées). Sans date de livraison, la date de l'événement est utilisée. Ils sont traités toutes les heures par WP-Cron, selon le fuseau horaire du site, avec une seule expédition réussie par date. Un échec est retenté le jour même et journalisé. WP-Cron nécessite des visites ou un déclenchement externe ; il ne rattrape pas les rappels dont la journée est passée.

Pour changer le destinataire côté équipe :

```php
add_filter( 'etr_email_equipe', fn() => 'logistique@exemple.fr' );
```

## Personnalisation (hooks)

- `etr_statuts` : modifier la liste des statuts.
- `etr_champs_demande` : ajouter ou retirer des champs du formulaire.
- `etr_email_equipe` : destinataire(s) des e-mails côté équipe.
- `etr_statuts_reservant` : statuts qui bloquent le matériel.
- `etr_source_woocommerce` : forcer ou désactiver l'utilisation des produits WooCommerce.
- `etr_catalogue` : filtrer la liste des articles proposés.
- `etr_routes_api_traiteurs` : routes de l'API REST autorisées aux traiteurs connectés.
- `etr_migrations` : étapes de migration (par version).
- `etr_gestion_slug` : adresse de l'espace de gestion (`gestion-traiteur` par défaut).
- `etr_prix_unitaire` : prix unitaire utilisé pour le chiffrage indicatif d'une demande (`$prix, $article_id`).
- Actions : `etr_demande_creee`, `etr_demande_annulee`, `etr_statut_change` (`$id, $ancien, $nouveau`), `etr_reponse_modifiee` (`$id, $message`), `etr_tarif_change` (`$id, $tarif`).
- `etr_export_csv` : lignes d'un export CSV avant écriture (`$lignes, $type` : demandes, articles ou planning).
- `etr_bon_signatures` : libellés des signatures en bas des bons (`$signatures, $type, $id`).
- Historique : `ETR_Historique::ajouter( $id, $type, $texte )` pour journaliser un événement personnalisé.

## Structure

```
espace-traiteur.php                 Fichier principal
includes/functions.php              Fonctions utilitaires, disponibilités
includes/catalogue.php              Catalogue : produits WooCommerce ou catalogue interne
includes/class-etr-installer.php    Activation, rôle, page, catégories
includes/class-etr-post-types.php   Types de contenus (demandes, catalogue, catégories)
includes/class-etr-access.php       Accès : traiteurs tenus hors de wp-admin, WooCommerce et API
includes/class-etr-comptes.php      Connexion, mot de passe oublié, choix du mot de passe
includes/class-etr-traiteurs.php    Page « Traiteurs » : création, invitation, désactivation
includes/class-etr-admin.php        Écrans d'administration
includes/class-etr-frontend.php     Espace traiteur (shortcode)
includes/class-etr-gestion.php      Espace de gestion de l'équipe (/gestion-traiteur/)
includes/class-etr-notifications.php E-mails
includes/class-etr-historique.php   Historique des demandes
includes/class-etr-bons.php         Bons de préparation et de retour imprimables
assets/css/espace-traiteur.css      Styles
assets/css/gestion.css              Styles de l'espace de gestion
assets/js/espace-traiteur.js        Mise à jour des disponibilités selon les dates
assets/js/connexion.js              Écran de connexion (afficher le mot de passe, envoi)
assets/js/admin-reglages.js         Choix du logo et de la photo dans la médiathèque
includes/class-etr-migrations.php   Migrations de la base après mise à jour
includes/class-etr-cli.php          Commandes WP-CLI (wp espace-traiteur …)
templates/connexion.php             Gabarit plein écran (connexion et espace connecté)
templates/gestion.php               Gabarit plein écran de l'espace de gestion
.github/workflows/deployer.yml      Déploiement automatique (GitHub Actions)
docs/DEPLOIEMENT.md                 Mise en place du déploiement, retour arrière
uninstall.php                       Nettoyage à la désinstallation
```

## Déploiement et mises à jour

- **Déploiement automatique** : à chaque fusion dans `main`, GitHub Actions vérifie le code, sauvegarde la base, envoie le plugin par SSH sur le serveur et met à jour la base. Mise en place et retour arrière : [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md).
- **Migrations de la base** : quand une nouvelle version change des données, les étapes nécessaires s'exécutent une seule fois, dans l'ordre (`wp espace-traiteur migrer` au déploiement, sinon automatiquement à la première visite). Verrou contre les exécutions simultanées, traitement par lots, journal et alerte en cas d'échec dans *Espace traiteur › Réglages › Mises à jour du plugin*.
- Commandes WP-CLI : `wp espace-traiteur statut`, `wp espace-traiteur migrer`.

## Limites actuelles / pistes

- L'équipe ne peut pas encore modifier les quantités d'une demande depuis l'admin (elle répond via le message).
