# La séquence de déploiement

## Sommaire

- [Le temps du répertoire de release et le temps de mise en ligne](#le-temps-du-répertoire-de-release-et-le-temps-de-mise-en-ligne)
- [Ce qui se passe dans le nouveau répertoire de release](#ce-qui-se-passe-dans-le-nouveau-répertoire-de-release)
- [Ce qui se passe à la bascule du symlink et après](#ce-qui-se-passe-à-la-bascule-du-symlink-et-après)
- [Les migrations à la release](#les-migrations-à-la-release)
- [La fenêtre d'interruption, et le pattern qui la supprime](#la-fenêtre-dinterruption-et-le-pattern-qui-la-supprime)
- [Workers](#workers)
- [Vérifier le déploiement](#vérifier-le-déploiement)
- [Rollback](#rollback)

## Le temps du répertoire de release et le temps de mise en ligne

La même liste de commandes, scindée par une seule question : **`current` a-t-il
commencé à pointer vers cette release ?**

| | Dans la release (avant la bascule) | Au moment de la bascule et après |
|---|---|---|
| Le trafic l'atteint | non | oui |
| S'exécute combien de fois | une fois par déploiement | une fois par déploiement |
| Un échec signifie | la release ne passe jamais en ligne, `current` reste intact, la production n'est pas affectée | la production est en plein changement — c'est là que la prudence s'impose |

Tout ce qui peut se passer avant la bascule doit s'y passer. Une étape qui échoue à ce
moment-là laisse `current` exactement où il était — la release précédente, qui continue
de servir le trafic normalement. Une étape qui échoue après la bascule est un incident
en production.

## Ce qui se passe dans le nouveau répertoire de release

La `recipe/symfony.php` de Deployer câble tout ça pour toi ; lis le code source de la
recipe elle-même pour le graphe de tâches exact, car il change d'une version à l'autre
et cette page n'en est pas la référence. Dans l'ordre :

```bash
# deploy:update_code — un checkout neuf dans releases/<n>/
# deploy:shared — shared_files et shared_dirs symlinkés depuis shared/
composer install --no-dev --optimize-autoloader --no-progress
composer dump-env prod
php bin/console cache:warmup --env=prod
php bin/console asset-map:compile
php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
```

Pourquoi cet ordre :

- **`deploy:shared` avant Composer.** `.env.local` doit déjà être symlinké depuis
  `shared/` avant que `dump-env` ne le lise, et avant que `cache:warmup` n'ait besoin
  d'un vrai `DATABASE_URL` pour compiler le conteneur.
- **`--optimize-autoloader`, pas `--classmap-authoritative`, sauf si tu as vérifié que
  rien ne génère de classe à l'exécution.** L'option plus stricte empêche complètement
  Composer de regarder le système de fichiers pour une classe inconnue — plus rapide,
  et un échec net dès que quelque chose le fait.
- **`composer dump-env prod`** écrit `.env.local.php`. Après ça, `Dotenv` inclut un seul
  tableau PHP compilé au lieu d'analyser `.env`, `.env.prod`, `.env.local` à chaque
  requête.
- **`cache:warmup`**, pas `cache:clear` — il n'y a rien à vider dans un checkout qui n'a
  jamais tourné, donc réchauffer directement est le choix honnête. C'est aussi ce qui
  écrit `var/cache/prod/App_KernelProdContainer.preload.php`, que `config/preload.php`
  requiert. **Pas de cache prod réchauffé ici, pas de préchargement**, et l'échec est
  silencieux car `config/preload.php` protège le require avec `file_exists()`.
- **`asset-map:compile`** écrit des fichiers hashés dans `public/assets/`. Sans ça,
  l'AssetMapper retombe sur le service de chaque asset via le front controller PHP, à
  l'exécution, pour toujours.
- **Les migrations s'exécutent en dernier, toujours avant la bascule.** La release n'est
  pas encore en ligne, donc un échec de migration ici signifie que la release ne passe
  jamais en ligne et que `current` reste intact — l'endroit le plus sûr où les
  migrations puissent s'exécuter.

Vérifie qu'une release a bien tout fait avant de lui faire confiance : connecte-toi en
SSH, fais `cd` dans `releases/<n>/` et lance directement les mêmes vérifications que le
check proche-de-la-production de `symfony-proglab-local-dev`, avant même que `current`
ne pointe jamais là.

## Ce qui se passe à la bascule du symlink et après

```bash
# deploy:symlink — current pointe désormais vers releases/<n>/, de façon atomique
php bin/console messenger:stop-workers
# rechargement de php-fpm — prend en compte la nouvelle cible de `current` pour opcache.preload
```

Seules deux choses se passent après la bascule, et toutes deux concernent des **choses
déjà en cours d'exécution** qu'un simple changement de symlink ne peut pas atteindre :
des workers qui détiennent une connexion ouverte, et un processus maître PHP-FPM qui a
lu `config/preload.php` une fois, à son propre démarrage, et ne l'a plus regardé
depuis. Tout le reste du « nouveau code » est déjà en ligne à l'instant même où le
symlink bouge.

## Les migrations à la release

Trois règles, et la raison de chacune :

- **Un seul exécutant.** Doctrine prend un verrou, donc des exécutions concurrentes sont
  sûres mais inutiles ; ce qu'elles produisent, c'est une file de déploiements qui ont
  l'air bloqués. Avec une seule machine pilotant Deployer, c'est naturellement vrai ; ça
  cesse de l'être dès que deux personnes lancent `dep deploy` en même temps, ce que
  `deploy:unlock`/le fichier de verrou de déploiement existe pour empêcher.
- **Faire échouer le déploiement en cas de migration ratée.** `--no-interaction` rend la
  commande non bloquante, pas tolérante. Deployer arrête toute l'exécution sur un code
  de sortie non nul par défaut — ne l'avale pas dans une tâche personnalisée.
- **Logger le SQL.** `-vv` affiche chaque instruction. Quand une migration prend vingt
  minutes sur les données de production et deux secondes en local, cette sortie est
  toute l'investigation.

Un dry run contre une copie de la production vaut plus que n'importe quelle relecture :

```bash
php bin/console doctrine:migrations:migrate --dry-run -vv
```

Si le projet utilise le transport Doctrine de Messenger avec `auto_setup=0` — le défaut
actuel de la recipe — la table `messenger_messages` est ajoutée au schéma par le
transport, donc `make:migration` la détecte comme n'importe quelle autre table. Ce
n'est pas une magie qui opère à l'exécution ; si la table manque, c'est une migration
qui manque.

## La fenêtre d'interruption, et le pattern qui la supprime

Ce standard exécute les migrations sans contrainte de rétrocompatibilité. Entre la fin
de la migration et le déplacement effectif du symlink — généralement des
millisecondes, mais pas zéro — **la release précédente dialogue avec le nouveau
schéma.** Entre la bascule et le redémarrage du dernier worker, l'inverse est
brièvement vrai pour les messages en file déjà en cours de traitement.

C'est un choix, pas un oubli. L'alternative — chaque changement de schéma conçu pour
être lisible à la fois par l'ancien et le nouveau code — transforme chaque renommage en
un projet multi-releases et laisse des états intermédiaires en production tant que
personne ne termine la séquence.

**Si tu as réellement besoin de zéro downtime, déroge délibérément à la règle** et
utilise expand/contract :

| Release | Schéma | Code |
|---|---|---|
| 1 | Ajoute `published_at` nullable | l'ignore |
| 2 | — | écrit à la fois `published_at` et l'ancien `is_published` |
| 3 | Backfill de `published_at` à partir des lignes existantes | inchangé |
| 4 | `published_at` NOT NULL | ne lit que `published_at` |
| 5 | Supprime `is_published` | inchangé |

Cinq déploiements, chacun sûr à annuler, aucun jamais incompatible avec le code qui
tourne à côté. C'est le prix réel de la garantie — paie-le là où ça vaut la peine (un
checkout, une table de paiement), pas partout.

## Workers

### Comment `messenger:stop-workers` fonctionne vraiment

Elle n'envoie aucun signal. Elle écrit un timestamp :

```php
$cacheItem = $this->restartSignalCachePool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
$cacheItem->set(microtime(true));
$this->restartSignalCachePool->save($cacheItem);
```

Chaque worker compare ce timestamp à sa propre heure de démarrage entre deux messages,
termine le message qu'il détient, puis s'arrête. Trois conséquences qui déterminent si
ça fonctionne du tout :

- **Le pool est `cache.messenger.restart_workers_signal`, un enfant de `cache.app`.**
  Sur un seul hôte avec l'adaptateur filesystem par défaut, c'est automatique — le job
  de déploiement et les workers partagent le même disque. Dès qu'il y a plus d'un
  serveur applicatif, un `cache.app` reposant sur le filesystem est propre à chaque
  hôte et **le signal n'atteint jamais les workers des autres hôtes.** Pointer
  `cache.app` vers Redis, Valkey ou la base de données — c'est la vraie raison de le
  faire dans un déploiement multi-serveurs, avant même les besoins propres de mise à
  l'échelle de Messenger.
- **Un `cache.prefix_seed` fixe compte aussi ici.** Sans ça, les clés de `cache.app`
  sont dérivées de `kernel.project_dir` (voir `references/production-config.md`), qui
  est un nouveau chemin absolu à chaque release — donc un worker démarré sous
  l'ancienne release et un autre redémarré sous la nouvelle lisent deux pools
  différents et sans rapport, et le signal d'arrêt n'atteint jamais les workers qu'il
  visait.
- **Rien ne redémarre les workers.** L'aide de la commande elle-même le dit. Un
  gestionnaire de processus doit s'en charger — c'est tout l'objet de la section
  suivante.

### Les faire tourner

Les workers sont des processus PHP longue durée, et les processus PHP longue durée
fuient. Les deux limites ci-dessous sont une protection, pas un réglage de
performance :

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```

- **`--time-limit`** borne la durée pendant laquelle un worker peut continuer à détenir
  une connexion de base de données morte ou une identity map Doctrine pleine d'objets
  dont personne n'a besoin. Une heure est un défaut raisonnable.
- **`--memory-limit`** l'arrête avant que l'OOM killer du kernel ne le fasse. Le killer
  abat le processus en plein traitement d'un message ; le worker s'arrête proprement
  entre deux messages.

Les deux font *sortir* le worker. Le gestionnaire de processus le redémarre, et — parce
que l'unité fait pointer `WorkingDirectory` vers le symlink `current`, pas vers une
release spécifique — ce redémarrage est aussi précisément ce qui permet au worker de
récupérer le nouveau code : il résout `current` à neuf à chaque démarrage. C'est pour
ça qu'un worker supervisé combiné à `messenger:stop-workers` donne un redémarrage
progressif et propre, sans aucun message perdu.

Fichiers d'unité pour systemd et supervisor, avec ce qu'il faut adapter :
`../assets/README.md`.

### Redémarrage propre au déploiement

```bash
php bin/console messenger:stop-workers   # après la bascule du symlink
```

Les workers terminent leur message en cours, s'arrêtent, le gestionnaire les redémarre
— en résolvant `current` à nouveau, qui pointe désormais vers la nouvelle release. Rien
n'est tué en plein handler, donc rien n'est fait à moitié. Ça fonctionne parce que les
handlers, dans ce standard, sont idempotents (voir `symfony-proglab-async`) : un
message rejoué après un redémarrage est traité à nouveau sans doubler son effet.

Ne fais pas de `kill -9` sur un worker pour forcer un redéploiement. Le message qu'il
détenait n'est ni acquitté ni rejeté, et ce qui se passe ensuite dépend du visibility
timeout du transport plutôt que de quoi que ce soit que tu as décidé.

## Vérifier le déploiement

```bash
php bin/console about                            # env, debug, versions, chemins
php bin/console doctrine:migrations:up-to-date   # code de sortie 0, sinon le schéma a du retard
php bin/console lint:container                   # tous les services peuvent réellement être construits
php bin/console messenger:stats                  # nombre de messages par transport
php bin/console debug:dotenv                     # de quel fichier vient chaque variable
```

À lire ainsi :

| Vérification | À quoi ressemble une mauvaise réponse |
|---|---|
| `about` | `Debug: true`, ou `Environment: dev` — `APP_ENV`/`APP_DEBUG` n'ont pas atteint le processus |
| `doctrine:migrations:up-to-date` | Code de sortie non nul — l'étape de migration ne s'est pas exécutée, ou a échoué et personne n'a regardé |
| `lint:container` | La moindre erreur. Un service qui ne peut pas être construit échoue sur la requête qui en a besoin, pas au démarrage |
| `messenger:stats` | `failed` au-dessus de zéro, ou `async` qui grossit entre deux appels — rien ne consomme |
| `debug:dotenv` | Une valeur de production qui vient de `.env` au lieu de l'environnement |

Puis une requête depuis l'extérieur, et trois choses dans le panneau réseau du
navigateur : le code de statut, un asset servi depuis `/assets/<nom>-<hash>.<ext>` (pas
via PHP), et HTTP/2 — Apache a besoin de `mod_http2` activé et de
`Protocols h2 h2c http/1.1` dans le vhost pour ça ; ce n'est pas automatique comme
derrière Caddy. Enfin, regarde les logs — un log vide après un déploiement est un
résultat ; un log que tu n'as pas regardé n'en est pas un.

**Un message en échec dans `failed` doit déclencher une alerte**, sans attendre que
quelqu'un lance `messenger:failed:show`. Cette règle appartient à
`symfony-proglab-async`, et un déploiement est le moment où elle est mise à l'épreuve.

## Rollback

```bash
vendor/bin/dep rollback production
```

Relie `current` vers le répertoire de la release précédente. Rien n'est reconstruit —
`vendor/`, le cache compilé et les assets sont exactement dans l'état où ils étaient la
dernière fois que cette release était en ligne — ce qui rend le rollback rapide et sûr
à faire sous pression.

**Ce qui rend le rollback possible**

- `keep_releases` réglé assez haut pour que la release dont tu as besoin soit encore sur
  le disque — le défaut de Deployer, 5, suffit généralement, et ça vaut la peine de
  vérifier avant un incident, pas pendant.
- Des migrations qui ajoutent plutôt qu'elles ne suppriment. Revenir en arrière sur le
  code est instantané ; revenir en arrière sur les données ne l'est pas.
- `shared_files`/`shared_dirs` correctement cadrés, pour que la release précédente
  retrouve le même `.env.local` et les mêmes répertoires persistants qu'elle avait la
  dernière fois qu'elle était en ligne.

**Ce qui le rend impossible**

- Une migration destructrice dans la release que tu veux quitter. `down()` recrée la
  colonne, pas son contenu — et `down()` n'a presque certainement jamais été exécutée.
- Une migration qui s'est exécutée mais dont tu annules le code, quand l'ancien code ne
  peut pas lire le nouveau schéma. C'est la même fenêtre que ci-dessus, mais entrée
  délibérément.
- Un état que seule la nouvelle version écrit : un format de cache, un payload de
  session, un message en file que l'ancien handler ne peut pas désérialiser. Vider la
  file avant de revenir en arrière suffit généralement ; une enveloppe de message
  versionnée est la réponse durable.
- **Oublier de recharger PHP-FPM après le rollback.** Le symlink pointe désormais vers
  l'ancienne release, mais le processus maître en cours d'exécution a préchargé les
  classes de la plus récente et continuera de les servir jusqu'à son redémarrage — la
  seule partie du « retour en arrière » qu'un simple échange de symlink ne peut pas
  accomplir.

Quand le rollback n'est pas disponible, dis-le *avant* de déployer, pas pendant
l'incident. Une release qui ne peut pas être annulée est une release qui mérite une
fenêtre de maintenance.
