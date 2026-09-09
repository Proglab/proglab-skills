---
name: symfony-proglab-deployment
description: >-
  Déployer une application Symfony en production avec Deployer par SSH : l'organisation
  en répertoires releases/current, les migrations exécutées automatiquement à chaque
  release, le php.ini de production et le préchargement OPcache avec Apache et PHP-FPM,
  les variables d'environnement et les secrets sur le serveur, les workers Messenger
  sous systemd ou supervisor, les vérifications post-déploiement et le rollback.
  Utilise ce skill dès que quelqu'un dit déployer ceci, mettre en production, livrer,
  configurer le serveur, écrire un deploy.php, lancer les migrations sur le serveur,
  redémarrer les workers, configurer OPcache ou le préchargement, définir des variables
  d'environnement en production, revenir à une release précédente — et de la même façon
  quand il décrit un symptôme : ça marche en local mais pas sur le serveur, le site est
  cassé après le déploiement, mon CSS a disparu en production, la production est lente,
  les workers exécutent du vieux code, j'obtiens une 500 sans message d'erreur, le
  nouveau code n'est pas pris en compte. Utilise-le aussi avant un premier déploiement,
  quand personne ne sait comment l'application est déployée.
---

# Déploiement

> **Niveau : à la demande, puis non négociable** — rien ici ne s'applique tant qu'il n'y a pas de cible de production. Une fois qu'il y en a une, la séquence et son ordre ne sont plus négociables.

Un artefact — un répertoire de release — une séquence, une façon de l'annuler.

La cible est **Apache avec PHP-FPM, déployé par [Deployer](https://deployer.org) par
SSH.** Tout ce qui suit part de ce modèle. Deployer construit chaque release dans son
propre répertoire sous `releases/`, puis bascule le lien symbolique `current` de façon
atomique une fois la release prête — le répertoire de release est ce qui fait de « ce
qui tourne » une question à réponse exacte, le même rôle que jouait une image immuable
pour une cible conteneurisée, mais par un mécanisme différent.

Pour faire tourner l'application en local — y compris la vérification proche-de-la-
production à faire *avant* de livrer — voir `symfony-proglab-local-dev`. Pour le
pipeline CI qui doit passer en premier, voir `symfony-proglab-quality`. Ce skill
commence au moment où une release est sur le point d'être construite, et s'arrête à
*où* partent les logs et les métriques : **ce que l'application émet — canaux, niveaux
de log, masquage des données sensibles, alerting, health checks — appartient à
`symfony-proglab-observability`.** Ce skill possède la configuration du handler de prod ;
l'autre possède tout ce qui y est écrit.

## Utiliser la recipe Symfony de Deployer

N'écris pas le graphe de tâches à la main. `require 'recipe/symfony.php';` dans
`deploy.php` te donne déjà la chorégraphie des répertoires release/shared/writable,
`deploy:vendors`, le réchauffement du cache et la bascule du symlink câblés dans le bon
ordre — lis le code source de la recipe elle-même quand tu as besoin du graphe de
tâches exact, car il change d'une version de Deployer à l'autre et cette page n'en est
pas la référence.

```php
<?php
// deploy.php
namespace Deployer;

require 'recipe/symfony.php';

set('repository', 'git@github.com:proglab/app.git');
set('keep_releases', 5);

// Fichiers et répertoires qui doivent persister entre les releases — tout le reste dans
// un répertoire de release est jetable, reconstruit depuis git et Composer à chaque fois.
add('shared_files', ['.env.local']);
add('shared_dirs', ['var/log']);
// Un projet qui stocke les uploads sur le système de fichiers local plutôt que sur un
// stockage objet (`symfony-proglab-storage`) ajoute aussi son répertoire ici —
// 'public/uploads', par exemple — pour qu'il soit symlinké dans chaque nouvelle release
// au lieu de repartir vide à chaque déploiement.
add('writable_dirs', ['var']);

host('production')
    ->setHostname('app.example.com')
    ->setRemoteUser('deploy')
    ->setDeployPath('/var/www/app');

before('deploy:symlink', 'database:migrate');
after('deploy:symlink', 'php-fpm:reload');
after('deploy:failed', 'deploy:unlock');
```

```bash
vendor/bin/dep deploy production
```

## La séquence

Chaque étape a un rôle. La liste en elle-même vaut peu ; **ce qui casse quand on la
saute** est ce qui empêche de la sauter.

| Étape | Son rôle | Si on la saute → |
|---|---|---|
| `composer install --no-dev --optimize-autoloader` | Dépendances de production, class map plutôt que sondage du système de fichiers | Des bundles de dev en prod (profiler, maker), et chaque chargement de classe tape le disque |
| `composer dump-env prod` | Compile les fichiers `.env` dans `.env.local.php` | `.env` est analysé à chaque requête, et `Dotenv` a besoin que les fichiers soient toujours présents |
| `cache:warmup` | Construit le conteneur, les routes, Twig, les métadonnées du validator, et le fichier `.preload.php` | La première requête de la nouvelle release paie la construction complète ; **`opcache.preload` ne fait silencieusement rien** |
| `asset-map:compile` | Écrit les fichiers d'assets hashés sous `public/assets/` | **Chaque asset est généré par PHP à l'exécution**, à chaque requête. C'est pour ça qu'on pense à tort qu'AssetMapper est lent |
| `doctrine:migrations:migrate --no-interaction` | Amène le schéma au niveau attendu par le code, avant que ce code ne puisse recevoir la moindre requête | Le symlink bascule vers du code qui interroge des colonnes qui n'existent pas encore |
| la bascule du symlink | `current` pointe vers la nouvelle release, entièrement construite et migrée, de façon atomique | Une release à moitié construite ou à moitié migrée n'est jamais mise en ligne — c'est l'étape qui rend toute la séquence sûre en cas d'échec |
| `messenger:stop-workers` | Demande aux workers en cours d'exécution de s'arrêter après leur message en cours | **Les workers continuent d'exécuter l'ancien code sur le nouveau schéma** — le pire échec de cette liste, car rien ne semble cassé |
| recharger PHP-FPM | Prend en compte la nouvelle release pour `opcache.preload`, qui ne s'exécute qu'une fois au démarrage du processus maître | Le site exécute le nouveau code, mais le préchargement continue de servir les classes compilées de la release précédente |

Deux de ces étapes sont fréquemment mal câblées :

- **Les migrations s'exécutent *avant* la bascule du symlink, depuis l'intérieur du
  nouveau répertoire de release.** La release n'est pas encore en ligne — rien ne lit
  le nouveau code — c'est donc exactement le même moment qu'un déploiement conteneurisé
  migre avant de déployer la nouvelle image : le schéma est prêt avant que le code qui
  l'attend ne passe en ligne. Câble-le avec
  `before('deploy:symlink', 'database:migrate')`. L'interruption que cela ne peut pas
  supprimer est le miroir du cas conteneurisé : entre la fin de la migration et la
  bascule effective du symlink, le schéma est nouveau et `current` sert encore la
  release *précédente* pendant un instant.
- **`messenger:stop-workers` n'envoie aucun signal à un processus.** Elle écrit un
  timestamp dans le pool `cache.app` ; les workers le lisent entre deux messages et
  s'arrêtent. Le job de déploiement et les workers doivent partager ce pool — vrai par
  défaut sur un seul hôte, et un point à vérifier délibérément dès qu'il y a plus d'un
  serveur applicatif. Détails et correctif : `references/deploy-sequence.md`.

## Chaque release vit dans son propre répertoire

Avec un conteneur, c'était l'image qui rendait « ce qui tourne » sans ambiguïté. Ici,
le répertoire de release joue le même rôle, pour une raison qui mérite d'être dite
clairement : **chaque release obtient un nouveau chemin absolu**, si bien que l'OPcache
de PHP — qui indexe les fichiers compilés par leur chemin réel — ne confond jamais le
`src/Service/BookService.php` de la release `47` avec celui de la release `48`. La mise
en cache d'opcode ordinaire (`opcache.validate_timestamps`, activé ou non) n'a besoin
d'aucun redémarrage pour servir le nouveau code après un déploiement, gratuitement — la
même garantie qu'offrait un conteneur neuf.

**Le préchargement est la seule exception**, et c'est pour ça que `php-fpm:reload` est
câblé dans le `deploy.php` ci-dessus : `opcache.preload` s'exécute exactement une fois,
au démarrage du processus maître PHP-FPM, et lit ce que `config/preload.php` résout *à
cet instant précis* — à travers le symlink `current`. Un déploiement qui ne recharge
jamais FPM continue de servir indéfiniment, silencieusement, les classes préchargées de
la release précédente, alors que tout le reste du site semble à jour. Le préchargement
et la bascule du symlink sont deux mécanismes de fraîcheur différents ; un seul des deux
survit sans redémarrage.

## Les migrations s'exécutent automatiquement, et c'est un choix

**Délibéré, avec un prix assumé :** les migrations ne sont pas écrites pour être
rétrocompatibles avec le code actuellement en cours d'exécution. Une courte
interruption est acceptable si une migration est incompatible avec la version
remplacée.

Cela permet d'écrire une migration lisible comme un seul changement — renommer la
colonne, terminé — au lieu d'une chorégraphie en trois déploiements pour chaque
modification de schéma, et sans état intermédiaire à moitié appliqué qui traîne en
production pendant une semaine.

Ce que ça coûte, dit clairement pour pouvoir y déroger volontairement : **il existe une
fenêtre, généralement de quelques secondes, où la nouvelle release tourne sur le schéma
laissé par sa devancière, jusqu'à ce que la migration se termine.** Si ton exigence est
le zéro downtime, cette règle ne s'applique pas à toi et le pattern qu'il te faut est
**expand/contract** : ajouter la nouvelle colonne nullable → déployer du code qui écrit
dans les deux → backfill → la rendre non-nulle → déployer du code qui ne lit plus que la
nouvelle → supprimer l'ancienne. Cinq déploiements au lieu d'un. Écris-le selon les
termes de `references/deploy-sequence.md`, et sache que tu choisis une cadence plus
lente pour une garantie réelle.

Écrire la migration elle-même — relire et corriger ce que `make:migration` a généré —
appartient à `symfony-proglab-doctrine`. Ce skill dit seulement quand elle s'exécute.

## Réglages de production

`APP_ENV=prod` et `APP_DEBUG=0`, puis le php.ini qui fait la différence entre « ça
tourne » et « c'est rapide » :

```ini
opcache.preload = /var/www/app/current/config/preload.php
opcache.preload_user = www-data
opcache.validate_timestamps = 0
realpath_cache_size = 4096K
realpath_cache_ttl = 600
opcache.memory_consumption = 256
opcache.max_accelerated_files = 32531
opcache.interned_strings_buffer = 16
```

`opcache.preload` doit pointer vers le **symlink `current`**, pas vers un répertoire
de release spécifique — pointer directement vers `releases/47/config/preload.php` fige
le préchargement sur cette seule release pour toujours, puisque le réglage n'est lu
qu'une fois au démarrage de FPM et jamais réévalué. Via `current`, chaque
`php-fpm:reload` après un déploiement relit le symlink et précharge ce vers quoi il
pointe désormais.

`opcache.preload` a besoin de `config/preload.php`, que la recipe Symfony fournit déjà
et qui ne fait rien tant que le cache **prod** n'a pas été réchauffé — il fait un
`require` sur `var/cache/prod/App_KernelProdContainer.preload.php`. C'est pour ça que le
réchauffement s'exécute à l'intérieur du nouveau répertoire de release, avant la
bascule du symlink.

Deux autres réglages valent la peine, tous deux dans `config/` :
`.container.dumper.inline_factories` (moins de fichiers à charger par requête),
`framework.enabled_locales` (arrêter de compiler des catalogues de traduction pour des
langues qu'on ne sert pas), et — spécifique à un déploiement en répertoires de release —
un `cache.prefix_seed` fixe, car le seed par défaut est dérivé de `kernel.project_dir`,
qui change à chaque release. Sans ça, `cache.app` repart à froid à chaque déploiement.
Les trois, avec la syntaxe exacte et les mises en garde selon les versions, dans
`references/production-config.md`.

## Variables d'environnement et secrets

`composer dump-env prod` compile les fichiers `.env` dans `.env.local.php`. Les
véritables variables d'environnement l'emportent quand même sur ce fichier — c'est
comme ça qu'on injecte un DSN propre à chaque environnement sans reconstruire quoi que
ce soit.

**Hors d'une plateforme conteneurisée, le vault de secrets Symfony est le standard**,
pas une option écartée : `secrets:set`, `config/secrets/prod/` commité, la clé de
déchiffrement injectée en tant que `SYMFONY_DECRYPTION_SECRET` sur le serveur et nulle
part ailleurs. C'est le mécanisme qui permet à un secret d'être versionné, relu dans une
pull request et tourné dans un commit — et ici, il n'existe pas de coffre à secrets de
plateforme pour le dupliquer. Ce qui n'est jamais acceptable : une valeur en clair posée
dans `.env.local` sans aucune stratégie de rotation — exactement le fichier que le vault
remplace.

`.env.local` fait partie des `shared_files` dans `deploy.php` — non commité, ne fait
partie d'aucune release, symlinké dans chaque nouvelle release depuis `shared/`.
`references/production-config.md` détaille les règles de précédence et le piège où
`dump-env` est silencieusement contourné.

## Vérifier le déploiement

Un déploiement que personne ne vérifie est un déploiement dont l'échec est signalé par
les utilisateurs, des heures plus tard, sans aucune stack trace. Quatre commandes, dix
secondes :

```bash
php bin/console about                          # env prod, debug désactivé, bonne version
php bin/console doctrine:migrations:up-to-date # le schéma correspond au code
php bin/console lint:container                 # tous les services peuvent être construits
php bin/console messenger:stats                # les files se vident, elles ne grossissent pas
```

Puis une vraie requête : vérifier le code de statut, qu'un asset provient bien de
`/assets/…-<hash>.css` et non de PHP, et que le journal d'erreurs reste silencieux. La
checklist complète, avec à quoi ressemble un `messenger:stats` sain :
`references/deploy-sequence.md`.

## Rollback

```bash
vendor/bin/dep rollback production
```

Relie `current` vers le répertoire de la release précédente — déjà construite,
`vendor/`, assets compilés et cache réchauffé tous intacts — et c'est rapide pour la
même raison que le déploiement est sûr : rien n'est reconstruit. **Ça nécessite quand
même un rechargement de PHP-FPM**, pour la même raison qu'un déploiement : le
préchargement ne relit `current` qu'au démarrage de FPM, donc sans rechargement, un
rollback change la cible de `current` alors que la production continue de servir les
classes préchargées de la release plus récente. Câble `php-fpm:reload` aussi après
`deploy:rollback`.

Le rollback fonctionne quand deux conditions sont vraies, et elles se décident *avant*
l'incident :

- **Les anciennes releases sont conservées**, pas purgées jusqu'à zéro.
  `keep_releases` dans `deploy.php` en contrôle le nombre ; il n'y a plus rien vers quoi
  revenir une fois qu'elles ont disparu.
- **Les migrations de la release n'ont pas détruit de données.** Une migration qui a
  supprimé une colonne peut être annulée dans le code, mais pas dans la réalité.

Le second point **n'est pas** une règle de rétrocompatibilité, et ne rouvre pas celle
que ce standard rejette. Les migrations continuent de s'exécuter à la release,
continuent de renommer une colonne en une seule instruction, et la nouvelle release
continue de rencontrer l'ancien schéma pendant quelques secondes. Ce qu'il dit est plus
étroit : **une release qui embarque une migration destructrice n'a pas de rollback, et
cela se dit avant de déployer**, pas découvert pendant l'incident. La plupart des
releases n'en portent aucune — une colonne ajoutée, une nouvelle table, un nouvel index
— et reviennent en arrière gratuitement.

Quand une release particulière doit rester réversible *et* supprime quelque chose,
scinde ce changement en deux : arrêter d'écrire dans la colonne dans cette release, la
supprimer dans une release ultérieure. C'est un choix fait release par release, pour un
checkout ou une table de paiement, et son prix est un déploiement supplémentaire pour ce
changement précis — pas expand/contract comme politique permanente pour chaque
modification de schéma.

Ce qui rend le rollback impossible : `keep_releases` réglé à 1, une méthode `down()`
que personne n'a jamais exécutée, et une entrée `shared_dirs` censée contenir quelque
chose de persistant mais qui a été cantonnée à un répertoire de release à la place.

## Quand la production se comporte mal

| Symptôme | Cause |
|---|---|
| Page blanche, 500, rien dans le log | `APP_DEBUG=0` masque l'erreur mais le handler de log est mal configuré. Si le handler est correct et que le log reste silencieux, c'est `fingers_crossed` qui fait son travail, et la question relève de `symfony-proglab-observability` |
| CSS et JS en 404 ou lents | `asset-map:compile` ne s'est pas exécuté avant la bascule du symlink |
| Nouveau code déployé, ancien comportement, tout le reste du déploiement semble correct | `opcache.preload` — PHP-FPM n'a pas été rechargé, donc le préchargement sert encore la release précédente |
| Les workers traitent les messages avec l'ancien code | `messenger:stop-workers` sauté, ou le job de déploiement et les workers ne partagent pas le même backend `cache.app` |
| `Unable to write in the cache directory` | `var/` n'est pas accessible en écriture par l'utilisateur du pool PHP-FPM, ou une entrée `shared_dirs`/`writable_dirs` manque dans `deploy.php` |
| Fonctionne après un déploiement, casse après le suivant | `cache.app` n'a pas de `prefix_seed` défini, donc ses clés sont dérivées d'un répertoire de projet qui change à chaque release |
| Une page renvoie 404 alors qu'elle fonctionnait | `shared_dirs` ne mentionne pas un répertoire dans lequel l'application écrit — il est reparti vide dans la nouvelle release |
| Une variable d'env est définie sur le serveur mais ignorée | Elle est définie pour le shell, pas pour le pool PHP-FPM |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/deploy-sequence.md` | Écrire ou corriger `deploy.php` ; les migrations à la release ; les workers, le signal d'arrêt, supervisor et systemd ; la checklist de vérification ; le rollback |
| `references/production-config.md` | php.ini et OPcache, le préchargement, les réglages du conteneur et des locales, les variables d'environnement et les secrets, le système de fichiers et les logs, le réglage de PHP-FPM |
| `assets/README.md` | Avant de copier les fichiers d'unité des workers — les deux ont besoin de valeurs spécifiques au projet |
