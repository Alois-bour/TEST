# Espace Traiteur — plugin WordPress

Un espace privé pour les traiteurs partenaires. Chaque traiteur se connecte à **son** espace et demande du mobilier, de la vaisselle, du linge ou du matériel pour ses événements. L'équipe traite les demandes depuis l'administration WordPress.

## Installation

1. Copier le dossier du plugin dans `wp-content/plugins/espace-traiteur/` (ou zipper le dépôt et l'importer via *Extensions › Ajouter*).
2. Activer **Espace Traiteur**.

À l'activation, le plugin :

- crée le rôle **Traiteur** ;
- crée la page **Espace traiteur** (`/espace-traiteur/`) avec le shortcode `[espace_traiteur]` ;
- crée les catégories par défaut : Mobilier, Vaisselle, Verrerie, Couverts, Linge de table, Matériel de cuisine.

## Utilisation

### Côté équipe (administrateurs)

- **Utilisateurs › Ajouter** : créer un compte par traiteur avec le rôle **Traiteur**.
- **Espace traiteur › Catalogue** : ajouter les articles (nom, description, photo, catégorie, référence, quantité disponible, unité).
- **Espace traiteur › Demandes** : voir les demandes, filtrer par statut, changer le statut et écrire un message au traiteur.

Statuts : En attente, Validée, Refusée, Livrée, Clôturée, Annulée.

### Côté traiteur

Sur la page **Espace traiteur**, le traiteur :

- se connecte (formulaire intégré à la page) ;
- voit la liste de **ses** demandes et leur statut (il ne voit jamais celles des autres) ;
- crée une demande : événement, date, nombre d'invités, lieu, dates de livraison et de reprise, contact sur place, articles du catalogue avec quantités, besoins hors catalogue ;
- consulte le détail et la réponse de l'équipe ;
- peut annuler une demande tant qu'elle est « En attente ».

Les traiteurs n'ont pas accès à l'administration WordPress : ils sont renvoyés vers leur espace et la barre d'admin est masquée.

## Notifications e-mail

| Événement | Destinataire |
|---|---|
| Nouvelle demande | Équipe (e-mail d'administration du site) |
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
- Actions : `etr_demande_creee`, `etr_demande_annulee`, `etr_statut_change` (`$id, $ancien, $nouveau`).

## Structure

```
espace-traiteur.php                 Fichier principal
includes/functions.php              Fonctions utilitaires
includes/class-etr-installer.php    Activation, rôle, page, catégories
includes/class-etr-post-types.php   Types de contenus (demandes, catalogue, catégories)
includes/class-etr-access.php       Accès : traiteurs tenus hors de wp-admin
includes/class-etr-admin.php        Écrans d'administration
includes/class-etr-frontend.php     Espace traiteur (shortcode)
includes/class-etr-notifications.php E-mails
assets/css/espace-traiteur.css      Styles
uninstall.php                       Nettoyage à la désinstallation
```

## Limites actuelles / pistes

- Le stock est une quantité maximale par demande : il n'est pas encore décompté selon les dates (pas de réservation sur un calendrier).
- L'équipe ne peut pas encore modifier les quantités d'une demande depuis l'admin (elle répond via le message).
- Pas encore d'export (PDF / CSV) des demandes.
