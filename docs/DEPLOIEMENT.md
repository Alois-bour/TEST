# Déploiement automatique sur maisonaname.com

À chaque fusion dans `main`, GitHub Actions met le plugin en ligne :

1. **vérifie** la syntaxe de tous les fichiers PHP (en cas d'erreur : arrêt, rien n'est envoyé) ;
2. **sauvegarde** la base de données sur le serveur (`~/sauvegardes-espace-traiteur/`, les 10 dernières sont gardées) ;
3. **envoie** les fichiers dans `wp-content/plugins/espace-traiteur/` (seuls les fichiers modifiés partent) ;
4. **met à jour la base** avec `wp espace-traiteur migrer` (en cas d'échec : déploiement en erreur et e-mail de GitHub).

Le fichier qui pilote tout : [`.github/workflows/deployer.yml`](../.github/workflows/deployer.yml).

## Prérequis chez l'hébergeur

- accès **SSH** ;
- **rsync** et **WP-CLI** (`wp`) disponibles en SSH — sur la plupart des hébergeurs c'est le cas.
  Vérifier : se connecter en SSH puis taper `rsync --version` et `wp --info`.
  Sans WP-CLI, le déploiement fonctionne quand même : pas de sauvegarde automatique, et la base se met à jour à la première visite du site.

## Mise en place (une seule fois, ~15 minutes)

### 1. Créer une clé SSH réservée au déploiement

Sur votre ordinateur (Terminal sur Mac, PowerShell sur Windows) :

```bash
ssh-keygen -t ed25519 -C "deploiement-github-espace-traiteur" -f deploiement-espace-traiteur -N ""
```

Deux fichiers sont créés :

- `deploiement-espace-traiteur` : la **clé privée** → ira dans GitHub (étape 3), ne la donnez à personne d'autre ;
- `deploiement-espace-traiteur.pub` : la **clé publique** → ira chez l'hébergeur (étape 2).

### 2. Autoriser la clé chez l'hébergeur

Dans l'espace client de l'hébergeur, rubrique « SSH » / « Clés SSH » : ajouter le contenu de `deploiement-espace-traiteur.pub`.
(Sinon, en SSH : l'ajouter à la fin du fichier `~/.ssh/authorized_keys`.)

### 3. Enregistrer les secrets dans GitHub

Dépôt sur GitHub → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**, un par ligne :

| Nom | Valeur | Exemple |
|---|---|---|
| `SSH_HOTE` | adresse du serveur SSH | `ssh.monhebergeur.fr` |
| `SSH_PORT` | port SSH (facultatif, 22 par défaut) | `22` |
| `SSH_UTILISATEUR` | identifiant SSH | `maisonaname` |
| `SSH_CLE_PRIVEE` | tout le contenu du fichier `deploiement-espace-traiteur` (lignes `-----BEGIN` et `-----END` comprises) | |
| `SSH_EMPREINTE_SERVEUR` | résultat de la commande ci-dessous | |
| `CHEMIN_WORDPRESS` | dossier où se trouve `wp-config.php`, chemin absolu | `/home/maisonaname/www` |

Empreinte du serveur (protège contre un faux serveur) — sur votre ordinateur :

```bash
ssh-keyscan -p 22 ssh.monhebergeur.fr
```

Copiez toutes les lignes affichées dans `SSH_EMPREINTE_SERVEUR`.

Le chemin de WordPress : en SSH, allez dans le dossier du site et tapez `pwd` (il doit contenir `wp-config.php`).

> Les secrets sont chiffrés : personne ne peut les relire (ni vous, ni Claude), ils sont masqués dans les journaux.
> Ne les mettez jamais dans un fichier du dépôt ni dans une conversation.

### 4. Premier déploiement

Onglet **Actions** du dépôt → **Déployer le plugin** → **Run workflow** (branche `main`).
Chaque étape s'affiche avec son détail ; le résumé indique le commit déployé.

Tant que les secrets ne sont pas renseignés, le déploiement s'arrête sans rien envoyer (avertissement « Déploiement non configuré »).

## Au quotidien

- **Mettre en ligne** : fusionner une pull request dans `main`. C'est tout.
- **Suivre** : onglet **Actions** du dépôt ; e-mail de GitHub en cas d'échec.
- **Côté WordPress** : *Espace traiteur › Réglages › Mises à jour du plugin* montre la version des fichiers, celle des données et le journal des migrations.

## Revenir en arrière

**Fichiers seuls** (aucune migration dans la version fautive) : sur GitHub, ouvrir la pull request fusionnée → **Revert** → fusionner la PR créée. L'ancienne version est redéployée automatiquement.

**Fichiers + données** : restaurer aussi la sauvegarde prise juste avant le déploiement fautif. En SSH :

```bash
ls -lt ~/sauvegardes-espace-traiteur/          # le nom contient la date et le commit déployé
cd /home/maisonaname/www                       # dossier de WordPress
wp db export ~/sauvegardes-espace-traiteur/avant-restauration.sql   # sécurité
gunzip -c ~/sauvegardes-espace-traiteur/base-AAAAMMJJ-HHMMSS-xxxxxxx.sql.gz | wp db import -
```

⚠️ Restaurer la base efface tout ce qui a été enregistré depuis (demandes, commandes WooCommerce…). À réserver aux vrais problèmes, idéalement en accord avec la personne qui gère le site.

## Écrire une migration (pour les développeurs)

Dans `includes/class-etr-migrations.php`, méthode `etapes()` :

```php
'1.4.0' => array(
	'description' => __( 'Ajoute le champ « créneau de livraison » aux demandes existantes.', 'espace-traiteur' ),
	'callback'    => array( __CLASS__, 'migration_1_4_0' ),
),
```

puis passer `ETR_VERSION` à `1.4.0` dans `espace-traiteur.php`.

Règles :

- **rejouable** : si l'étape échoue au milieu, elle sera relancée — elle ne doit rien dupliquer ;
- **additive** : ajouter avant de supprimer (supprimer l'ancien champ dans une version suivante) pour garder un retour arrière possible ;
- **par lots** si beaucoup de données : traiter 100 éléments, renvoyer `'a_suivre'` tant qu'il en reste, `true` à la fin ;
- en cas d'erreur, renvoyer un `WP_Error` : la migration s'arrête, l'équipe est alertée dans l'administration, nouvel essai automatique après 15 minutes (ou bouton « Relancer maintenant »).

Commandes utiles en SSH :

```bash
wp espace-traiteur statut    # versions, étapes en attente, verrou, journal
wp espace-traiteur migrer    # lancer / relancer la migration
```
