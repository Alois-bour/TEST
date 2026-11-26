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
- **Traiteurs** : liste, nombre de demandes, ajout d'un traiteur (invitation envoyée tout de suite ou plus tard), envoi ou renvoi de l'invitation, désactivation et réactivation. Les traiteurs pas encore invités apparaissent « À inviter ».
- **Catalogue** : stock, quantités sorties et disponibles aujourd'hui, lien vers la fiche produit ; recherche instantanée (nom, référence, catégorie) pendant la frappe.

Sans permaliens, l'adresse est `/?etr_gestion=1`. Filtre `etr_gestion_slug` pour changer `gestion-traiteur`.

Écrans WordPress :

- **Espace traiteur › Traiteurs** : créer un traiteur (société, contact, e-mail, téléphone). L'invitation (e-mail pour choisir son mot de passe) n'est envoyée que si la case « Envoyer l'invitation maintenant » est cochée ; sinon le traiteur apparaît « À inviter » et on l'invite plus tard d'un clic. Depuis cette page : envoyer ou renvoyer l'invitation (date du dernier envoi affichée), désactiver ou réactiver un accès, voir ses demandes.
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

## Application de l'équipe (iPhone)

L'espace de gestion (`/gestion-traiteur/`) s'installe comme une application sur l'iPhone : icône sur l'écran d'accueil, plein écran, barre d'onglets en bas.

- **Installer** : ouvrir `/gestion-traiteur/` dans Safari, touchez « Partager » puis « Sur l'écran d'accueil ». Un encart l'explique dans l'application tant qu'elle n'est pas installée.
- **Connexion dans l'application** (identifiant ou e-mail, « Rester connecté » 14 jours). Indispensable sur iPhone : une application installée a ses propres cookies. Les traiteurs y sont refusés (ils ont leur espace).
- **Icône** : l'« Icône du site » de WordPress (Réglages › Général), sinon une icône fournie par le plugin.
- **Toujours à jour** : les pages ne sont jamais stockées sur le téléphone ; sans réseau, une page « Pas de connexion » propose de réessayer. Seuls les fichiers CSS/JS du plugin sont gardés en cache pour un démarrage rapide.
- Fichiers servis par le plugin : `manifest.webmanifest`, `sw.js` (service worker, limité à `/gestion-traiteur`) et `hors-ligne`.

### Rôles de l'équipe

| Rôle | Ce qu'il voit et fait |
|---|---|
| **Bureau** | Tout l'espace de gestion : demandes et décisions, prix et tarification, exports, traiteurs, statistiques, équipe. Accès à l'administration WordPress pour les demandes et les réglages du plugin. |
| **Préparateur** | Planning, demandes validées, livrées ou clôturées (sans prix), catalogue, bons de préparation et de retour. Pas d'administration WordPress. |
| **Livreur** | Planning, demandes validées, livrées ou clôturées (sans prix), bons. Pas d'administration WordPress. |

- **Une personne peut avoir plusieurs rôles** (par exemple Préparateur et Livreur) : elle cumule ce que chacun permet. Le rôle Bureau inclut déjà la préparation et la livraison.
- *Gestion › Équipe* (bureau) : ajouter une personne (nom, e-mail, téléphone, un ou plusieurs rôles), cocher ou décocher ses rôles directement dans la liste (au moins un rôle ; pour fermer l'application, « Retirer l'accès »), envoyer ou renvoyer l'invitation, **retirer l'accès** (déconnexion immédiate, rôles mis de côté) ou le rendre. Les administrateurs et son propre compte ne sont pas modifiables ici.
- **Invitation** : envoyée à la création (case « Envoyer l'invitation maintenant », cochée par défaut ; décochez pour l'envoyer plus tard). Le mail donne l'identifiant, un lien pour choisir le mot de passe et l'adresse de l'application à installer.
- **Mot de passe : tout se passe dans l'application**, jamais sur les écrans de WordPress (choix du mot de passe depuis l'invitation, « Mot de passe oublié ? », y compris si la demande part de `wp-login.php`). Un lien ne sert qu'une fois, expire après 24 h et est annulé par une invitation plus récente : l'application l'explique et propose d'en recevoir un nouveau.
- **Comptes jamais mélangés** : si un lien d'invitation est ouvert sur un appareil où quelqu'un d'autre est connecté (par exemple l'administrateur), l'application l'affiche (« Ce lien permet à … de choisir son mot de passe, mais cet appareil est connecté au compte de … ») et propose de déconnecter ce compte avant de continuer, ou d'annuler. Après l'enregistrement du mot de passe, la personne arrive sur la connexion, identifiant prérempli : aucune connexion automatique.
- Les droits sont vérifiés côté serveur : une demande en attente est introuvable pour le terrain (fiche, bons), toute action de gestion est refusée hors bureau.
- Les administrateurs WordPress ont tous les droits.

### Expérience d'application (2.1.0)

Dans l'application installée sur iPhone, l'espace de gestion se comporte comme une app native :

- **Barre d'onglets** en bas avec 4 onglets et **Plus** (feuille avec les autres écrans, les notifications, les réglages et la déconnexion). En-tête compact ; le **grand titre** de la page se replie dans l'en-tête quand on fait défiler.
- **Transitions** entre les écrans (glissement vers l'avant ou vers l'arrière, fondu entre onglets ; Safari 18.2+), **barre de chargement** fine pendant la navigation.
- **Feuilles qui montent du bas** : changer le statut et la remise d'une demande (barre d'action en bas de la fiche), confirmations (retirer un accès, commande prête incomplète). Fermeture en touchant le fond ou en tirant la feuille vers le bas.
- **Notifications temporaires** (« Statut enregistré ») à la place des bandeaux ; l'adresse est nettoyée pour qu'un rechargement ne les réaffiche pas.
- **Tirer pour actualiser** sur les listes et la tournée (pas sur les écrans de saisie).
- **Liste des demandes en cartes** sur téléphone : toute la carte ouvre la demande ; **glisser vers la gauche** révèle « Appeler » (traiteur) et « Itinéraire ».
- **Bouton principal fixe en bas**, à portée du pouce (préparation, livraison, reprise).
- **Mode sombre** automatique (réglage de l'iPhone ou de l'ordinateur).
- **Écran de démarrage** aux couleurs de l'application, avec l'icône (images générées par le plugin, extension GD).
- **Pastilles** : demandes à traiter (bureau) ou étapes de la tournée du jour (livreurs), sur l'onglet et sur l'icône de l'application (iOS 16.4+, notifications autorisées), mise à jour aussi par les notifications.

### Terrain sans réseau (2.2.0)

Dans un château, une cave ou une salle sans 4G, le terrain continue de travailler :

- **Pages gardées sur le téléphone** : chaque écran de l'application ouvert avec du réseau est gardé ; à l'ouverture de la tournée, les fiches, les écrans de livraison et de reprise du jour, le scanner et son lecteur de QR le sont aussi. Sans réseau, la dernière version s'affiche.
- **Saisies gardées puis envoyées** : scans, préparation, « commande prête », livraisons signées et reprises (photos et signature comprises) sont enregistrés sur le téléphone (IndexedDB) et partent **tout seuls, dans l'ordre**, dès le retour du réseau (au retour de la connexion, à l'ouverture d'un écran, puis toutes les 30 s). Les renvois sont sans risque : un jeton par formulaire empêche tout doublon.
- **Pastille d'état** : « Hors connexion », « 3 en attente », « 1 refusée » ; la toucher affiche le détail. Un envoi refusé par le serveur (commande livrée entre-temps par un collègue, étiquette périmée) est signalé avec la raison, et peut être retiré.
- **Livraison hors connexion** : confirmation sur place, puis retour à la tournée, où l'étape apparaît « Livrée sur le téléphone · envoi en attente ».
- **Sécurité** : seules les vraies pages de l'application sont gardées, jamais l'écran de connexion ; elles sont effacées à la déconnexion, ou dès que l'écran de connexion s'affiche (session terminée, autre personne). Le jeton de sécurité est renouvelé avant chaque envoi différé.

### Dates de livraison et de reprise : seulement les dates possibles (3.1.2)

- **Formulaire du traiteur** : la livraison et la reprise se choisissent dans une liste des seules dates cohérentes avec l'événement (« Samedi 5 décembre · la veille », « … · le jour de l'événement », « … · le lendemain »). Le calendrier de l'iPhone ignorait les limites et laissait choisir une livraison après l'événement. Sans date d'événement, la liste est désactivée ; si l'événement change, une date devenue impossible est retirée.
- **Fiche, « Changer les dates » (bureau)** : même liste, construite côté serveur ; la date actuelle reste proposée même hors limites.
- Le serveur refuse toujours une date incohérente envoyée autrement.
- **Fiches** (traiteur, équipe, administration) : la livraison et la reprise sont dites par rapport à l'événement (« 26 novembre 2026 · la veille de l'événement ») ; sans date choisie, « Le jour de l'événement (27 novembre 2026) » au lieu d'un tiret.

### Comptes traiteurs : uniquement l'espace traiteur (3.1.1)

- Un compte traiteur n'a accès qu'à son espace traiteur, jamais à l'application de l'équipe, même si un rôle d'équipe lui était ajouté (par exemple depuis wp-admin).
- **Connexion à l'application** avec un compte traiteur : message clair, lien vers l'espace traiteur ; un membre de l'équipe utilise son compte d'équipe, avec sa propre adresse.
- **Équipe › Ajouter** : l'adresse d'un compte traiteur est refusée avec un message dédié (« utilisez une autre adresse pour le compte d'équipe »).

### Application : fiche en onglets et « À faire » (3.1.0)

- **Fiche d'une demande en onglets** : Résumé · Matériel · Échanges · Argent (bureau) · Historique. Pastilles sur les onglets (point d'argent à traiter, incident ouvert, message du traiteur sans réponse). Les onglets sont de vrais liens (`?onglet=argent`), changent sans rechargement, restent en haut de l'écran, et le même onglet revient après un enregistrement.
- **Prochaine étape** en tête du résumé (bureau) : demande à étudier (« Valider ou refuser » ouvre le statut), devis à envoyer, acompte ou caution à recevoir, écarts de reprise, facture à établir ou à encaisser, paiement en double, débit à relancer, caution à libérer, incident, message sans réponse ; les autres points sont listés dessous.
- **Argent en un coup d'œil** : montant indicatif et frise devis → acompte → caution → facture. « Acompte et caution » s'affiche en lecture, le formulaire s'ouvre avec « Modifier ».
- **Historique** : les 12 derniers événements, les plus anciens repliés.
- **Accueil du bureau** : liste « À faire » de toutes les demandes en cours, l'urgent d'abord, chaque ligne ouvre la fiche sur le bon onglet.
- **Espace traiteur sur téléphone** : onglets en bas de l'écran (Demandes, Nouvelle, Dispos, Équipe), comme l'application de l'équipe ; l'en-tête garde sa hauteur sur les pages courtes.
- **Fiche du traiteur** : « Actions de votre part » tout en haut (accepter le devis, payer l'acompte, déposer l'empreinte ou enregistrer la carte, régler la facture, donner son avis), chaque bouton mène au bon endroit ; devis, acompte et caution, casse, paiement en ligne et facture réunis dans un seul bloc « Paiement ».
- **Mes demandes** : l'action attendue s'affiche sur la demande dans la liste.
- **Planning sur téléphone** : liste jour par jour (jour de la semaine, journées sans rien masquées, « Rien de prévu aujourd'hui », « Rien de prévu ce mois-ci ») au lieu d'une grille qui débordait ; inchangé sur ordinateur.
- **Carte de tournée** : « Livrer » / « Reprendre » en grand bouton, incident et itinéraire en icônes, « En route · arrivée 10:30 » (icône camion).
- **Menu « Plus »** classé en Terrain, Gestion et Compte ; icônes propres à Statistiques, Équipe, Inventaire et Factures.
- **Demandes** : les deux exports dans un menu « Exporter ».
- **Libellés** : « À préparer : 15 pièces » (préparation), pluriels corrects (articles manquants, quantités libres).
- Extensions : panneaux à placer avec `etr_gestion_fiche_resume`, `etr_gestion_fiche_materiel`, `etr_gestion_fiche_echanges`, `etr_gestion_fiche_argent` ; filtres `etr_gestion_fiche_onglets` et `etr_gestion_taches`. `etr_gestion_fiche` et `etr_gestion_fiche_cote` restent appelés dans le résumé.

### Corrections de la quatrième revue (3.0.5)

- **Stripe** : si le retour du traiteur enregistre déjà la session, le webhook demande à Stripe de renvoyer l'événement (rien n'est perdu si ce retour échoue) ; un paiement en ligne après un règlement noté à la main (facture payée, acompte reçu) est signalé en double, la date n'est pas changée ; la caution ne peut pas être libérée tant qu'un débit attend la réponse de Stripe.
- **Déconnexion de l'application** : faite d'abord sur le serveur ; sans réseau, rien n'est effacé (message) ; les saisies abandonnées et les pages gardées ne sont supprimées qu'une fois la déconnexion réussie.
- **Acompte et caution** : une date que la personne n'a pas touchée garde la valeur écrite entre-temps (Stripe) ; une date changée ou effacée volontairement est respectée.
- **Face ID** : une demande expirée (jeton invalide) ne fait plus reculer le compteur par adresse IP.
- **Créneaux** : une demande « livrée » (même à la main) ne compte plus dans la charge de son jour de livraison ; métadonnées lues en une requête.
- **Espace traiteur** : le retrait des notifications à la déconnexion vaut sur toutes les pages.
- **Appareils** : le nom enregistré est gardé au rattachement automatique.

### Corrections de la troisième revue (3.0.4)

- **Téléphone partagé** : à la déconnexion, seules les saisies de la personne qui part sont comptées et supprimées ; celles d'un collègue restent. Les saisies gardées avant la 3.0.3 (sans auteur) ne partent plus d'office : elles sont signalées et ne partent qu'avec « Réessayer ».
- **Stripe** : un second paiement n'est signalé « à rembourser » qu'une fois réussi (un SEPA en cours n'est pas compté) ; un acompte payé depuis un vieil onglet après le règlement de la facture est signalé en double ; démarrer le paiement du solde ferme la page d'acompte restée ouverte.
- **Caution** : une nouvelle caution repart de zéro (débit en attente, essais), la libération efface le débit en attente ; clé d'idempotence propre au site et à la caution.
- **Facture** : sans la fonction « casse », le débit de caution est déduit de la facture ; avec, la part non déduite est indiquée « hors facture » sur la fiche.
- **Compteur de factures** : un changement du prochain numéro impossible pendant une numérotation est signalé dans les réglages.
- **Face ID** : essais par adresse IP comptés de façon atomique, avant la vérification, par quart d'heure fixe.
- **Espace traiteur** : Cmd/Ctrl-clic sur « Se déconnecter » laissé au navigateur.

### Corrections de la seconde revue (3.0.3)

- **Terrain** : une saisie refusée par le serveur n'est plus envoyée une seconde fois ; une saisie laissée par une autre personne sur un téléphone partagé ne bloque plus les envois de la personne connectée ; déconnexion : un seul déroulé même si l'on touche plusieurs fois, 8 s d'attente au plus.
- **Téléphone partagé** : les pages gardées sont effacées dès qu'une page arrive d'un autre compte (renvoi vers l'espace traiteur, accès refusé) ; les bons imprimables n'y touchent pas.
- **Stripe** : le webhook ne demande un renvoi qu'en cas de panne passagère (un paiement SEPA en traitement est acquitté) ; débit de caution : une seule clé par demande, sans le montant ; après une coupure, seul le même montant peut être relancé ; un débit « en traitement » est relu, jamais recréé.
- **Face ID** : envoyer le numéro d'une autre personne ne bloque plus son Face ID (blocage par compte seulement pour une clé enregistrée de ce compte).
- **Stock** : après une reprise avec écarts, les manquants restent comptés « chez les traiteurs ».
- **Notifications** : le nom de l'appareil et la date sont gardés au rattachement automatique.
- **Itinéraires** : `ETR_Terrain::urls_itineraire_tournee()` renvoie tous les tronçons.

### Version 3.0.0 : couleurs, logistique, traiteur, stock, facturation, Stripe

Tout s'active et se règle dans **Espace traiteur › Fonctions** (couleurs : **Réglages › Couleurs**). Les fonctions qui changent le parcours du traiteur, envoient des e-mails ou touchent à l'argent sont désactivées par défaut.

**Couleurs** (Réglages) : couleur principale, menu/en-tête et fond de l'application de l'équipe ; couleur principale, menu et texte de l'espace traiteur (aussi utilisées par les e-mails). Nuances calculées, barre d'état de l'iPhone et écrans de démarrage assortis ; mode sombre inchangé ; retour aux couleurs d'origine.

| Groupe | Fonction | Par défaut | En bref |
|---|---|---|---|
| Logistique | Volume du camion | activée | Volume par produit (Inventaire), part du camion sur chaque demande, alerte plusieurs trajets (comptés dans l'estimation), volume à charger sur la tournée. Réglages : volume du camion, remplissage utile. |
| | Créneaux | désactivée | Le traiteur choisit un créneau de livraison et de reprise ; limite par créneau et par jour ; affiché sur la fiche et la tournée. |
| | Tournée optimisée | activée | « Optimiser l'ordre de passage » : par créneau, puis au plus court depuis le dépôt (plus proche voisin + 2-opt). |
| Traiteur | Packs | activée | Espace traiteur › Packs : quantités fixes ou par invité, ajoutées en un clic dans la limite du disponible. |
| | Quantités suggérées | activée | « Quantité conseillée par invité » (produits) × nombre d'invités. |
| | Calendrier des disponibilités | activée | Écran « Disponibilités » : quantité libre jour par jour d'un article. |
| | Plusieurs comptes par société | désactivée | « Mon équipe » : collaborateurs invités par le traiteur, demandes partagées, retrait d'accès. |
| | Avis après l'événement | désactivée | Invitation après la clôture, note sur 5 et commentaire, bureau prévenu, moyenne dans les statistiques. |
| Stock | Historique de casse | activée | Casse et manquants par article sur 12 mois, « à racheter » au-delà d'un seuil. |
| | Inventaire | activée | Onglet « Inventaire » : comptage au téléphone, écart avec l'attendu, correction du stock. |
| | Alertes de stock bas | désactivée | Quantité libre sous un seuil dans les semaines à venir : encart du catalogue et notification quotidienne. |
| Argent | Facture finale | désactivée | Numéro chronologique sans trou (préfixe-année-numéro), contenu figé, TVA, acompte déduit, PDF au traiteur, « payée le », onglet « Factures ». Réglages : émetteur, SIRET, TVA, prix TTC ou HT, mentions, IBAN. |
| | Export comptable | désactivée | CSV du mois pour Excel ou le logiciel comptable. |
| | Paiement en ligne (Stripe) | désactivée | Acompte et solde par Stripe Checkout ; caution par empreinte bancaire (7 jours au plus, proposée 6 jours avant la livraison) ou carte enregistrée ; libérer ou débiter depuis la fiche ; libération automatique après une reprise complète ; webhook signé. Clés : Fonctions › Paiement en ligne (la clé secrète n'est jamais réaffichée). |

**Stripe, mise en place** : clé secrète (Développeurs › Clés API), puis un webhook vers `…/wp-json/etr/v1/stripe/webhook` avec l'événement `checkout.session.completed`, et son secret de signature. Essayez d'abord avec une clé de test (`sk_test_…`).

**Tests automatiques** : `php tests/executer.php` (sans WordPress), lancés avant chaque déploiement.

### E-mails mis en page (2.9.0)

- **Tous les e-mails du plugin** (invitations, mots de passe, nouvelle demande, statut, rappels, messages, devis, bon de livraison, relevé, relances, e-mail de test) partent en **HTML aux couleurs du site** : logo (celui de la page de connexion, sinon du site), titre, salutation, informations de la demande en tableau, liste des articles, message de l'équipe encadré, **boutons** d'action (avec l'adresse en clair dessous), pied de page. Lisible sur iPhone, Gmail et Outlook (mise en page en tableaux, styles en ligne).
- **Version texte** toujours jointe (messageries sans HTML, filtres anti-indésirables).
- **Aperçu** : Réglages › E-mails › « Voir un aperçu des e-mails ». Désactivable (Fonctions › « E-mails mis en page ») : retour au texte simple.
- Personnalisation : filtres `etr_mail_couleurs` et `etr_mail_pied`. Les e-mails des autres extensions ne sont pas touchés.

### Estimation du prix de la livraison (2.8.0)

Sur chaque demande, **pour le bureau et l'administration seulement** (jamais le traiteur, ni les préparateurs et livreurs) : panneau « Tarification » de la fiche dans l'application, et boîte « Estimation de la livraison » dans l'administration WordPress. Fonction activable (Espace traiteur › Fonctions, activée par défaut).

- **Distance par la route** entre l'adresse de départ (par défaut *Frolois 54140*, modifiable dans Fonctions en cas de déménagement) et le lieu de livraison de la demande. Adresses : Base Adresse Nationale (api-adresse.data.gouv.fr) ; itinéraire : Géoplateforme IGN (data.geopf.fr), sinon OSRM, sinon vol d'oiseau × 1,3 (signalé). Services publics gratuits, sans clé.
- **Calcul** (camion 20 m³ avec hayon, gazole) : deux allers-retours (livraison puis reprise) ; gazole (14 L/100 km × **prix réel du marché** : moyenne des stations à moins de 30 km du dépôt, sinon du département, sinon de la France, d'après les données publiques « Prix des carburants » de data.economie.gouv.fr, mise à jour toutes les 6 heures ; ou prix saisi, 2,00 €/L par défaut, aussi utilisé si le prix du marché est indisponible) ; usage du camion (0,20 €/km : entretien, pneus, assurance, amortissement) ; personnel (temps de route + 30 min sur place à chaque passage, 30 €/h, 1 personne) ; autres frais ; **prix conseillé** = coût + marge (20 %), arrondi aux 5 € supérieurs, avec un minimum possible. Tous ces paramètres sont réglables.
- **« Utiliser ce prix »** reporte le montant dans les frais de livraison (à enregistrer) ; « Recalculer » relance le calcul.
- **Rapide** : calcul lancé juste après l'envoi de la demande, puis gardé tant que le lieu et le départ ne changent pas ; après un échec des services, nouvel essai au bout de 30 minutes ; une approximation à vol d'oiseau est retentée le lendemain.

### Dates cohérentes et sélecteur de dates (2.7.1)

- **Règles** (vérifiées dans le formulaire et sur le serveur) : événement à venir ; livraison au plus tard le jour de l'événement, au plus tôt 7 jours avant ; reprise au plus tôt le jour de l'événement, après la livraison, au plus tard 7 jours après. Écarts réglables : filtres `etr_jours_livraison_avant` et `etr_jours_reprise_apres`.
- **Sélecteur** : calendrier limité aux jours possibles, date écrite en toutes lettres (« Samedi 5 décembre 2026 »), raccourcis « La veille », « Le jour même », « Le lendemain », message d'erreur sous le champ. Si l'événement change, une livraison ou une reprise devenue impossible est retirée, avec un message.
- **Corrections** : devis figé à l'envoi (PDF et acceptation sur le montant envoyé ; retiré s'il n'est pas encore accepté et que la demande ou le tarif changent) ; matériel en réparation compté par demande (une reprise refaite ne remet jamais en stock la casse d'une autre demande) ; acompte de 1 000 € ou plus bien relu ; deux dates changées ensemble : tout ou rien ; relance d'acompte réarmée si la livraison est déplacée ; bon de livraison réglable par filtre ; notifications du traiteur seulement avec des permaliens ; l'historique note de nouveau les modifications du traiteur.

### Fonctions activables (2.7.0)

Chaque fonction ci-dessous s'active ou se désactive dans **Espace traiteur › Fonctions**. Désactivée, elle disparaît de l'application de l'équipe et de l'espace des traiteurs, et ses routes sont refusées. Ses données sont gardées. Les fonctions qui touchent à l'argent ou envoient des e-mails nouveaux sont désactivées par défaut.

| Fonction | Par défaut | Ce qu'elle fait |
|---|---|---|
| Déplacer une livraison ou une reprise | activée | Planning : glisser l'étape sur un autre jour (ordinateur) ; fiche : panneau « Changer les dates » (téléphone). Stock vérifié sur la nouvelle période, dates cohérentes avec l'événement, traiteur prévenu. |
| Matériel en réparation | désactivée | La casse constatée à la reprise sort du stock disponible (une reprise refaite ne compte que la différence). Écran Catalogue : « Remis en stock ». |
| Notifications sur l'iPhone du traiteur | activée | Espace traiteur installé : carte « Être prévenu sur cet iPhone ». Demande validée ou refusée, devis prêt, livreur en route, livré, clôturé, message, date déplacée. Service worker dédié (`/gestion-traiteur/sw-traiteur.js`, sans cache), abonnement par admin-ajax avec jeton. |
| « En route » | activée | Tournée : le livreur touche « En route » et choisit le temps de trajet ; le traiteur reçoit l'heure d'arrivée (e-mail et notification) et la voit dans son suivi. |
| Messages par demande | activée | Fil de discussion bureau ↔ traiteur sur la fiche ; chaque message prévient l'autre partie (e-mail, notification ; type « Message d'un traiteur » réglable par le bureau). |
| Commander à nouveau | activée | Le traiteur reprend une ancienne demande : événement, lieu, compléments et matériel préremplis, dates à choisir. |
| Devis PDF et acceptation en ligne | désactivée | Le bureau envoie le devis (PDF joint : matériel, remise, livraison, total, acompte) ; le traiteur l'accepte dans son espace en cochant les conditions générales (adresse et validité dans Fonctions). Nom, date, heure et adresse IP gardés ; bureau prévenu. Un devis renvoyé doit être accepté à nouveau. |
| Acompte et caution | désactivée | Montants demandés, dates de réception (et de restitution de la caution), visibles par le traiteur et sur le devis. Pas de paiement en ligne : les traiteurs n'ont pas accès au panier. |
| Casse et manquants facturés | désactivée | Après une reprise avec écarts : montant au « Prix de remplacement (casse) » du produit (WooCommerce › Inventaire), relevé PDF envoyé par le bureau, téléchargeable par le traiteur. |
| Relances automatiques | désactivée | Chaque jour à partir de 9 h : devis sans réponse après 3 jours (e-mail au traiteur, une fois), reprise en retard et acompte non reçu à 7 jours de la livraison (notification au bureau). |
| Statistiques du terrain | activée | Onglet Statistiques : livraisons et reprises par livreur, taux de casse et de manquants, incidents par type, sur 12 mois. |
| Connexion par Face ID | activée | Désactivée : bouton masqué, clés refusées (gardées). |
| Bon de livraison PDF | activée | Désactivé : plus d'e-mail ni de téléchargement du bon signé. |

Les devis, relevés et bons ne sont servis qu'au traiteur de la demande et au bureau (`?etr_document=devis|releve&id=…`).

### Espace traiteur installable, suivi détaillé (2.6.0)

- L'**espace traiteur s'installe sur l'iPhone** du traiteur (Safari › Partager › « Sur l'écran d'accueil ») : manifeste dédié (nom, icône, plein écran), aide à l'installation affichée dans Safari tant que ce n'est pas fait.
- **Suivi de la commande** sur la fiche de chaque demande : matériel préparé (heure), en route (premier colis chargé), livré (heure, personne qui a réceptionné), repris (complet ou écarts) ; dates prévues de livraison et de reprise tant qu'elles ne sont pas faites. Lien vers le **bon de livraison signé**.

### Connexion par Face ID (2.5.0)

- **Activer** : dans l'application, menu › « Connexion par Face ID » › « Activer Face ID sur cet appareil » (une invitation le propose aussi). L'iPhone crée une **clé d'accès** pour le site, gardée dans le trousseau et protégée par Face ID ; le site n'en garde que la partie publique. Aucune donnée du visage n'est transmise.
- **Se connecter** : « Se connecter avec Face ID » sur l'écran de connexion, sans identifiant ni mot de passe. Le mot de passe reste valable.
- **Gérer** : liste des appareils (ajout, dernière utilisation), « Retirer ».
- **Sécurité** (norme WebAuthn, vérifiée sur le serveur, sans dépendance) : défi à usage unique (5 min), origine et domaine du site, présence et vérification de l'utilisateur (Face ID) exigées, signature ES256 ou RS256, compteur anti-clonage, accès à l'application vérifié à chaque connexion (un accès retiré bloque aussi Face ID), essais limités par adresse IP.

### Tournées réparties, bon de livraison signé (2.4.0)

- **Répartition des tournées** (bureau, écran Tournée) : chaque livraison ou reprise est confiée à un livreur (il est prévenu), et l'**ordre de passage** se règle avec ↑ ↓. Un livreur voit **ses étapes** et celles **à répartir** ; sa pastille et ses rappels de la veille ne comptent que celles-ci. « Commande prête » ne prévient que le livreur de la livraison (tous s'il n'y en a pas encore).
- **Itinéraire de la tournée** : toutes les étapes dans l'ordre, en un toucher (Google Maps, départ de la position actuelle) ; l'itinéraire de chaque étape reste disponible (Plans).
- **Bon de livraison signé (PDF)** : matériel livré, colis, date et heure, livreur, personne qui a réceptionné, **signature** et **photos**. Envoyé automatiquement au traiteur par e-mail (pièce jointe) dès la livraison, téléchargeable depuis la fiche (équipe) et depuis l'espace traiteur (le traiteur de la demande uniquement). Généré par le plugin, sans dépendance (extension GD pour la signature et les photos).
- L'heure des rappels de la veille se règle avec le filtre `etr_heure_rappel_veille` (17 par défaut).

### Notes, incidents, recherche, préférences (2.3.0)

- **Notes de l'équipe** sur chaque demande (fiche) : visibles seulement par l'équipe, jamais par le traiteur ; ajoutées sans recharger la page, gardées sur le téléphone sans réseau.
- **Signaler un incident** (casse, retard, accès impossible, matériel manquant, autre) avec description et photos, depuis la fiche ou la tournée ; le bureau est notifié tout de suite. Le panneau « Incidents » liste les incidents ouverts d'abord ; le bureau les marque résolus. Fonctionne aussi hors connexion.
- **Recherche globale** (loupe de l'en-tête, ou « Rechercher » dans le menu) : demandes par n°, événement, traiteur, lieu ou contact ; traiteurs pour le bureau ; raccourci vers le scanner.
- **Préférences de notifications** : sur la page Notifications, un interrupteur par type d'alerte reçu (nouvelle demande, livrée, reprise, incident, commande prête, rappels…).

### Notifications (iPhone)

- Sur iPhone (iOS 16.4 ou plus), elles fonctionnent dans l'application **installée sur l'écran d'accueil**. Chacun les active depuis la cloche (page *Notifications*) : l'iPhone demande l'autorisation, puis une notification de test arrive. Une invitation à les activer s'affiche dans l'app tant que la personne n'a pas répondu. Elles marchent aussi sur ordinateur (Chrome, Edge, Firefox, Safari).
- Envoi standard Web Push : messages chiffrés pour chaque appareil (RFC 8291), signés par le site (VAPID, RFC 8292). Rien à configurer, aucun service tiers ni dépendance : il suffit de l'extension PHP OpenSSL. Les clés du site sont créées au premier usage.
- La personne qui fait l'action n'est jamais notifiée. Toucher une alerte ouvre la demande. Un appareil désactivé ou désinstallé est retiré automatiquement. L'envoi se fait après l'affichage de la page, sans la ralentir.
- Sécurité : envoi uniquement vers les services de notification connus (Apple, Google, Mozilla, Microsoft) ; enregistrement par l'API `etr/v1/notifications/` réservé à l'équipe connectée, avec jeton.

### Terrain : préparation, étiquettes, tournées (2.0.0)

Le parcours d'une commande validée, entièrement sur le téléphone :

1. **Préparation** (préparateurs) : depuis la fiche, « Préparer » ouvre la liste du matériel. Un toucher coche une ligne (tout est prêt), les boutons − / + règlent une quantité partielle ; chaque changement est enregistré aussitôt. On indique le **nombre de colis**, puis « Commande prête » : les livreurs reçoivent une notification. Une commande incomplète demande confirmation et le manque est noté dans l'historique.
2. **Étiquettes QR** : une par colis, planche A4 de 8 étiquettes (99 × 67 mm, type Avery L7165) avec le colis (2 / 5), le traiteur, l'événement, la date et le lieu de livraison.
3. **Scanner** (onglet) : la caméra lit les étiquettes, en mode « Chargement » ou « Retour ». Chaque colis est compté une fois (« 3 / 5 chargés »), avec un son et un message ; une étiquette d'une autre commande ou périmée est refusée. Saisie manuelle possible si l'étiquette est abîmée. Lue avec l'appareil photo de l'iPhone, l'étiquette ouvre la page du colis avec les mêmes boutons.
4. **Tournée** (livreurs, onglet par défaut) : livraisons et reprises du jour (jour suivant / précédent), adresse avec bouton **Itinéraire** (Plans), téléphone du contact cliquable, état de la préparation et des colis.
5. **Livraison** : nom de la personne qui réceptionne, **signature au doigt**, photos (réduites sur le téléphone avant l'envoi), remarque. La demande passe en « Livrée » ; le bureau reçoit une notification et le traiteur l'e-mail habituel.
6. **Reprise** : pour chaque article, quantité rendue et cassée (tout est prérempli), photos, remarque, signature facultative. Sans écart, la demande est clôturée ; sinon elle reste « Livrée » avec le détail des écarts et le bureau est prévenu. Une reprise peut être refaite (matériel retrouvé).
7. **Rappels de la veille** (vers 17 h) : « Demain : 2 livraisons, 1 reprise » aux livreurs, « À préparer pour demain : … » aux préparateurs pour les commandes pas encore prêtes.

La fiche d'une demande affiche en tête le **Suivi** (préparation, colis, livraison, reprise) et les actions possibles selon le rôle, puis les comptes rendus (signature, photos, écarts).

- **Droits** vérifiés par l'API (`etr/v1/terrain/`) : préparation par les préparateurs, livraison et reprise par les livreurs (le bureau peut tout faire), seulement sur les demandes visibles et au bon statut.
- **Photos et signatures** : rangées dans `wp-content/uploads/etr-prive/` (accès direct bloqué par un `.htaccess` Apache, noms aléatoires), servies uniquement par l'application à l'équipe qui voit la demande, supprimées avec la demande. Sur un serveur Nginx, ajouter une règle `location ^~ /wp-content/uploads/etr-prive/ { deny all; }`.
- **Bibliothèques** (dans `assets/vendor/`, licences jointes) : *QR Code Generator* (MIT) pour les étiquettes, *jsQR* (Apache 2.0) pour la lecture, chargée seulement sur l'écran Scanner.

### Feuille de route

1. ✅ Rôles et application installable (1.8.0).
2. ✅ Notifications sur l'iPhone (1.9.0) : nouvelle demande et demande annulée pour le bureau ; commande validée, puis annulée ou refusée, pour les préparateurs et les livreurs.
3. ✅ Préparation (2.0.0) : liste à cocher sur le téléphone, colis, étiquettes QR, scan au chargement et au retour.
4. ✅ Tournées (2.0.0) : livraisons et reprises du jour, itinéraire, signature, photos, comptage des retours et de la casse, rappels de la veille.

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

**Vérifier que le site envoie bien ses e-mails** : *Espace traiteur › Réglages › E-mails* affiche l'adresse d'expédition utilisée et propose « Envoyer un e-mail de test ». Si l'envoi échoue, la raison technique est affichée (aussi après une invitation qui n'est pas partie). Si l'envoi réussit mais que rien n'arrive (indésirables compris), l'hébergement n'envoie pas les e-mails de façon fiable : installer une extension SMTP (WP Mail SMTP, FluentSMTP…) reliée à une vraie boîte d'envoi.

**Un lien « choisir mon mot de passe » est refusé** : l'écran dit pourquoi (déjà utilisé ou remplacé par un plus récent, expiré, compte sans accès). *Espace traiteur › Réglages › E-mails › Tester les liens de mot de passe* vérifie qu'un lien tout juste créé est accepté. Les liens et les connexions sont toujours vérifiés dans la base de données : un cache d'objets (LiteSpeed Cache, Redis…) qui garderait une ancienne copie des comptes ne peut plus les faire refuser. Le *Journal des liens* (même page) montre chaque clé créée, chaque lien ouvert (accepté ou refusé) et chaque connexion, avec l'heure et l'extension ou la fonction à l'origine : on voit ce qui a remplacé une clé.


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

## Statistiques

Dans l'espace de gestion, l'onglet **Statistiques** regroupe les demandes validées, livrées et clôturées par mois d'événement, et classe les traiteurs par montant indicatif et les articles par quantité. Les prix sont ceux du catalogue actuel ; les frais non renseignés et besoins hors catalogue ne sont pas connus. Ce n'est pas un chiffre d'affaires encaissé. Les demandes sans date d'événement valide sont exclues.

Les articles complets sont classés par nombre de demandes actives, présentes ou futures, dont les périodes chevauchantes atteignent le stock actuel. Ce calcul prudent ne constitue pas un historique des ruptures.

## Personnalisation (hooks)

- `etr_statuts` : modifier la liste des statuts.
- `etr_champs_demande` : ajouter ou retirer des champs du formulaire.
- `etr_email_equipe` : destinataire(s) des e-mails côté équipe.
- `etr_statuts_reservant` : statuts qui bloquent le matériel.
- `etr_source_woocommerce` : forcer ou désactiver l'utilisation des produits WooCommerce.
- `etr_catalogue` : filtrer la liste des articles proposés.
- `etr_routes_api_traiteurs` : routes de l'API REST autorisées aux traiteurs connectés.
- `etr_migrations` : étapes de migration (par version).
- `etr_app_nom`, `etr_app_nom_court` : nom de l'application installée (« Gestion » sous l'icône).
- `etr_nom_site` : nom du site dans les e-mails et l'application (par défaut, le titre du site sans son slogan : « Maison Anamé »).
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
includes/class-etr-equipe.php       Rôles de l'équipe (bureau, préparateur, livreur), page Équipe
includes/class-etr-equipe-acces.php Connexion, mot de passe oublié et choix du mot de passe dans l'application
includes/class-etr-pwa.php          Application installable : manifeste, service worker, hors ligne
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
assets/js/app.js                    Application : service worker, aide à l'installation sur iPhone
assets/js/sw.js                     Service worker (servi par ETR_PWA)
assets/img/app-icone-*.png          Icônes de secours de l'application
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
