# Espace Traiteur — plugin WordPress

Un espace privé pour les traiteurs partenaires de [Maison Anamé](https://maisonaname.com). Chaque traiteur se connecte à **son** espace et demande du mobilier, de l'art de la table, du textile ou de la décoration pour ses événements. L'équipe traite les demandes depuis l'administration WordPress.

## Catalogue : les produits WooCommerce du site

Quand WooCommerce est actif (cas de maisonaname.com), le plugin **reprend directement les produits du site** : pas de double saisie.

- Sont proposés les produits **publiés** et visibles dans le catalogue, regroupés par famille et sous-catégorie (ex. *Mobilier › Tables*, *Art de la table › Verrerie*).
- *Espace traiteur › Réglages* : choisir les catégories proposées (toutes par défaut), afficher ou non les prix du site, e-mail de l'équipe.
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

- **Espace traiteur › Traiteurs** : créer un traiteur (société, contact, e-mail, téléphone). Il reçoit une invitation par e-mail pour choisir son mot de passe. Depuis cette page : renvoyer l'invitation, désactiver ou réactiver un accès, voir ses demandes.
- **Produits WooCommerce** : renseigner la « Quantité possédée » des articles à limiter (voir ci-dessus).
- **Espace traiteur › Réglages** : catégories proposées, prix, e-mail de l'équipe.
- **Espace traiteur › Demandes** : voir les demandes, filtrer par statut, changer le statut et écrire un message au traiteur.

Statuts : En attente, Validée, Refusée, Livrée, Clôturée, Annulée.

### Côté traiteur

Sur la page **Espace traiteur**, le traiteur :

- se connecte avec son identifiant (ou son e-mail) et son mot de passe, sur la page Espace traiteur uniquement ;
- voit la liste de **ses** demandes et leur statut (il ne voit jamais celles des autres) ;
- crée une demande : événement, date, nombre d'invités, lieu, dates de livraison et de reprise, contact sur place, articles du catalogue avec quantités, besoins hors catalogue ;
- choisit parmi le mobilier et la vaisselle **disponibles à ses dates** : dès qu'il saisit la date, chaque article affiche la quantité encore libre et les articles complets sont grisés ;
- consulte le détail et la réponse de l'équipe ;
- peut annuler une demande tant qu'elle est « En attente ».

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
- Actions : `etr_demande_creee`, `etr_demande_annulee`, `etr_statut_change` (`$id, $ancien, $nouveau`).

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
includes/class-etr-notifications.php E-mails
assets/css/espace-traiteur.css      Styles
assets/js/espace-traiteur.js        Mise à jour des disponibilités selon les dates
uninstall.php                       Nettoyage à la désinstallation
```

## Limites actuelles / pistes

- L'équipe ne peut pas encore modifier les quantités d'une demande depuis l'admin (elle répond via le message).
- Pas encore d'export (PDF / CSV) des demandes.
