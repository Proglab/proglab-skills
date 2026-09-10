# Configuration de production

Tout ce qui fait la différence entre une application qui tourne et une application qui
tourne en production, pour une cible Apache + PHP-FPM déployée avec Deployer. Quand
cette page et `vendor/` se contredisent, `vendor/` a raison.

## Sommaire

- [Les deux interrupteurs](#les-deux-interrupteurs)
- [php.ini](#phpini)
- [Le préchargement OPcache](#le-préchargement-opcache)
- [`validate_timestamps = 0` et le coût qu'il implique](#validate_timestamps--0-et-le-coût-quil-implique)
- [Réglages côté Symfony](#réglages-côté-symfony)
- [Variables d'environnement](#variables-denvironnement)
- [Secrets](#secrets)
- [Le système de fichiers](#le-système-de-fichiers)
- [Logs](#logs)
- [PHP-FPM et Apache](#php-fpm-et-apache)

## Les deux interrupteurs

```bash
APP_ENV=prod
APP_DEBUG=0
```

`APP_DEBUG` n'est pas un réglage de verbosité. Quand il est activé, le conteneur est
reconstruit à chaque changement d'un fichier de config, le profiler collecte chaque
requête, et les exceptions s'affichent avec le code source et les variables
d'environnement — un problème de performance *et* une fuite d'information.

`Dotenv::bootEnv()` dérive `APP_DEBUG` quand il n'est pas défini : `1`, sauf si
`APP_ENV` fait partie de la liste prod. Donc `APP_ENV=prod` seul est déjà correct —
définis quand même les deux, car cette dérivation est invisible et quelqu'un finira par
ajouter un environnement appelé `staging` et se demandera pourquoi la toolbar est là.

## php.ini

Un seul `php.ini` pour tous les environnements, plus une petite surcharge au niveau du
pool pour les réglages que seule la production peut se permettre — la plupart des
distributions découpent déjà la configuration de PHP-FPM de cette façon
(`/etc/php/8.4/fpm/php.ini` et un fichier de pool sous `/etc/php/8.4/fpm/pool.d/`), ce
qui est un endroit naturel où placer le second bloc sans toucher au premier.

```ini
; php.ini — tous les environnements
expose_php = 0
date.timezone = UTC
session.use_strict_mode = 1
zend.detect_unicode = 0

realpath_cache_size = 4096K
realpath_cache_ttl = 600
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 32531
opcache.memory_consumption = 256
opcache.enable_file_override = 1
```

```ini
; une surcharge de pool réservée à la prod, ex. /etc/php/8.4/fpm/pool.d/app-prod.conf
php_admin_value[opcache.preload_user] = www-data
php_admin_value[opcache.preload] = /var/www/app/current/config/preload.php
php_admin_flag[opcache.validate_timestamps] = off
```

Ce que chacun des réglages non évidents apporte :

| Réglage | Pourquoi |
|---|---|
| `realpath_cache_size = 4096K` | PHP résout les chemins via `stat()`. Une application Symfony touche des milliers de fichiers ; le défaut de 16K évince en permanence, donc chaque autoload refait le tour du système de fichiers. C'est le gain le moins cher de la liste |
| `opcache.max_accelerated_files = 32531` | Le défaut (~10 000) est inférieur au nombre de fichiers d'une vraie application Symfony plus ses vendors — et ici, les fichiers de plusieurs releases peuvent être résidents en même temps (voir plus bas). Une fois la table pleine, le reste n'est jamais mis en cache — compilé à chaque requête, silencieusement. La valeur est un nombre premier, ce que le réglage attend |
| `opcache.memory_consumption = 256` | Même échec, autre ressource : quand le buffer se remplit, OPcache arrête de mettre en cache. 128M est souvent insuffisant avec un gros vendor, et encore moins suffisant une fois que plusieurs releases partagent le cache |
| `opcache.interned_strings_buffer = 16` | Les noms de classes, méthodes et propriétés sont internés une fois au lieu de l'être par processus |
| `session.use_strict_mode = 1` | Refuse les identifiants de session que le serveur n'a jamais émis — le correctif pour la fixation de session |
| `expose_php = 0` | Supprime l'en-tête `X-Powered-By`. Gratuit |

## Le préchargement OPcache

Le préchargement compile les classes de l'application en mémoire partagée **une fois,
au démarrage**, pour qu'aucune requête n'ait à payer leur linkage. `config/preload.php`
est fourni par la recipe Symfony et tient en trois lignes :

```php
<?php

if (file_exists(dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';
}
```

Lis attentivement la protection par `file_exists()` : **si le cache prod n'a pas été
réchauffé, le préchargement ne fait rien et ne le signale pas.** Le fichier
`.preload.php` est écrit par `cache:warmup` quand les warmers optionnels s'exécutent —
à l'intérieur du nouveau répertoire de release, avant la bascule du symlink
(`references/deploy-sequence.md`).

`opcache.preload_user` est obligatoire dès que le processus maître de PHP-FPM ne
démarre pas en tant que root : PHP refuse sinon de précharger, avec un avertissement au
démarrage facile à rater dans le log de FPM. Il doit nommer l'utilisateur sous lequel le
pool tourne réellement (`www-data` sur une installation Debian ou Ubuntu par défaut). Se
tromper fait perdre le préchargement sans perdre l'application — invisible, le pire
genre d'échec.

`opcache.preload` doit pointer vers le symlink `current`, jamais vers un chemin
`releases/<n>/` spécifique — voir `../SKILL.md` pour le pourquoi : il n'est lu qu'une
fois, au démarrage de FPM, et jamais réévalué avant le prochain rechargement.

Les classes préchargées sont figées pour toute la durée de vie du processus maître FPM,
donc le préchargement et le rechargement de FPM à chaque déploiement ne forment en
réalité qu'une seule et même décision.

## `validate_timestamps = 0` et le coût qu'il implique

Avec `opcache.validate_timestamps = 0`, PHP arrête de vérifier si un fichier a changé
depuis sa compilation — un `stat()` par fichier et par requête, disparu. C'est le plus
gros gain OPcache à lui seul, et il n'est pas optionnel en production.

**Cela signifie aussi que le nouveau code n'est jamais chargé tant que l'OPcache
continue de pointer une requête vers l'ancien fichier.** Sur ce modèle de déploiement,
ça se produit automatiquement, et le préchargement est la seule chose qui ne suit pas
gratuitement :

| Ce qui change à un déploiement | Nécessite un redémarrage pour prendre effet ? | Pourquoi |
|---|---|---|
| Code applicatif (`src/`, `templates/`, …) | Non | Chaque release est un nouveau chemin absolu (`releases/<n>/…`), donc OPcache — indexé par chemin réel — compile simplement les nouveaux fichiers sous leur propre clé. Les entrées compilées de l'ancienne release restent inutilisées jusqu'à leur éviction |
| `opcache.preload` | **Oui — recharger PHP-FPM** | Le préchargement s'exécute une fois, au démarrage du processus maître FPM, en lisant `current` à cet instant. Il ne se réexécute pas à la requête suivante comme le fait la compilation ordinaire |
| Une arborescence source montée en bind ou éditée sur place (pas ce modèle) | Oui | Sans nouveau chemin à chaque release, PHP n'a aucun moyen de distinguer un fichier édité d'un fichier inchangé. C'est pourquoi `validate_timestamps = 0` est dangereux sur un simple déploiement rsync sur place, et ne l'est pas ici |

`opcache_reset()` appelé depuis une requête web ne vide que le pool du processus qui y a
répondu ; avec plusieurs workers FPM derrière un même maître, ce n'est de toute façon
pas une réinitialisation complète — recharger le service FPM est le moyen fiable de
faire rattraper son retard au préchargement, et c'est ce que fait `php-fpm:reload` dans
`deploy.php` après chaque bascule de symlink.

## Réglages côté Symfony

```yaml
# config/services.yaml
when@prod:
    parameters:
        .container.dumper.inline_factories: true
```

Le conteneur dumpé devient quelques gros fichiers au lieu d'un fichier par service —
moins d'`include` par requête. Le compromis est un warmup plus lent et plus gourmand en
mémoire, qui se déroule à l'intérieur du nouveau répertoire de release, avant la
bascule du symlink, là où personne n'attend.

**Le point en tête compte.** Les paramètres qui commencent par `.` sont des *paramètres
de build* : retirés du conteneur compilé par `RemoveBuildParametersPass` et transmis au
dumper, seul endroit où le kernel lit celui-ci. La forme sans le point,
`container.dumper.inline_factories`, a été dépréciée dans Symfony 6.3 et n'est plus lue
du tout sur la 8.x — elle survit comme un paramètre ordinaire, rien ne prévient, et
l'optimisation est désactivée. La forme avec le point fonctionne à partir de la 6.3,
donc écris-la ainsi sur toutes les versions supportées.

```yaml
# config/packages/translation.yaml
framework:
    enabled_locales: ['en', 'fr']
```

Sans ça, Symfony compile un catalogue de traduction pour chaque locale qu'il trouve, y
compris celles fournies par tes bundles et que tu ne sers jamais. Ça restreint aussi
l'exigence de route `_locale` à cette liste, ce qui transforme « un crawler demande
`/zh/books` » d'une 500 en une 404.

**Requis sur ce modèle de déploiement, pas optionnel :**

```yaml
# config/packages/cache.yaml
framework:
    cache:
        prefix_seed: app/%kernel.environment%
```

Sans seed fixe, les clés de cache sont namespacées à partir de `kernel.project_dir` plus
la classe du conteneur. `kernel.project_dir` vaut `releases/<n>/`, un chemin nouveau à
chaque déploiement — donc tout le pool `cache.app`, y compris le signal de redémarrage
de Messenger (`references/deploy-sequence.md`), repart à froid et devient inatteignable
à chaque release. C'était une note de bas de page sur une cible conteneurisée, où le
chemin ne change jamais ; ici, c'est un point de checklist du premier déploiement.

## Variables d'environnement

Trois mécanismes, dans l'ordre où ils sont résolus :

1. **Les véritables variables d'environnement** — définies par le pool PHP-FPM
   (`env[…]` dans le fichier de pool) ou par l'unité systemd. Elles l'emportent sur
   tout le reste.
2. **`.env.local.php`**, produit par `composer dump-env prod`. Un seul tableau PHP
   compilé, inclus en un seul `include`, au lieu d'analyser `.env`, `.env.prod` et
   `.env.local` à chaque requête.
3. **`.env.prod.local` / `.env.local`** — lus seulement quand `.env.local.php` n'existe
   pas, ou quand le fichier compilé est contourné (voir ci-dessous).

La précédence est codée dans `Dotenv::populate()` : avec `$overrideExistingVars =
false`, un nom déjà présent dans `$_ENV` est ignoré. `dump-env` est donc une
*compilation de valeurs par défaut*, pas un verrou — un `env[DATABASE_URL]` défini au
niveau du pool le surcharge quand même, sans toucher à la release.

**Le piège.** `bootEnv()` n'inclut `.env.local.php` que si l'`APP_ENV` qu'il contient
correspond à celui déjà présent dans l'environnement. Compile pour `prod`, exécute avec
`APP_ENV=staging`, et le fichier compilé est écarté au profit d'une analyse des
fichiers `.env` à l'exécution — qui peuvent même ne pas être présents sur l'hôte si
`.env.local` était cadré comme spécifique à `staging`. Le symptôme est une variable
manquante, et rien ne pointe vers la cause. `php bin/console debug:dotenv` montre de
quel fichier chaque valeur provient réellement ; lance-la quand une variable est
« définie » mais ne prend pas effet.

## Secrets

**Le vault de secrets Symfony est le standard ici** — il n'existe pas de coffre à
secrets de plateforme sur lequel se replier comme le ferait un orchestrateur de
conteneurs. `secrets:set`, `config/secrets/prod/` commité, la clé de déchiffrement
injectée en tant que `SYMFONY_DECRYPTION_SECRET` via le `env[]` du pool PHP-FPM (ou
l'unité systemd pour les workers) et nulle part ailleurs. Sa clé de déchiffrement est
une seule variable d'environnement — pas plus difficile à déployer que le DSN — et
c'est le mécanisme qui permet à un secret d'être versionné, relu dans une pull request
et tourné dans un commit.

Ce qui n'est jamais acceptable : un `.env.local` portant de vraies valeurs secrètes en
clair. `.env.local` existe toujours ici (il fait partie des `shared_files` de
`deploy.php`), mais son rôle est la configuration non secrète propre à chaque
environnement — l'hôte et le nom de la base de `DATABASE_URL`, par exemple — pas les
mots de passe ni les clés d'API une fois le vault adopté. Quel que soit le coffre
utilisé, **un seul, pas deux.** Une valeur dans le vault et la même clé dans
`.env.local`, c'est la garantie qu'une rotation met à jour l'un des deux pendant que
l'application lit l'autre.

## Le système de fichiers

Le pool PHP-FPM tourne sous son propre utilisateur (`www-data` par défaut sur
Debian/Ubuntu), qui doit posséder `var/` dans la release courante — le `writable_dirs`
de Deployer s'en charge à chaque déploiement, donc une erreur de permission à cet
endroit signifie qu'une entrée manque dans `deploy.php`, pas un `chown` ponctuel à
faire. Tout le reste d'un répertoire de release doit être traité en lecture seule.
`var/` a trois rôles distincts :

| Répertoire | Contient | Partagé entre les releases ? |
|---|---|---|
| `var/cache/<env>` | Conteneur compilé, routes, Twig, fichier de préchargement | Non — reconstruit à neuf à chaque nouvelle release, et c'est *voulu* : un répertoire de cache partagé signifierait déployer du code sans jamais reconstruire ce vers quoi il compile |
| `var/log` | Fichiers de log, quand un handler y écrit | Dépend du choix de logging ci-dessous |
| Le pool de `cache.app`, les sessions, les fichiers uploadés | Tout ce dont l'application a besoin pour survivre à un déploiement | Oui — via `shared_dirs`/`shared_files` dans `deploy.php`, ou en pointant le pool vers Redis/Valkey/la base de données plutôt que vers le système de fichiers |

Tout ce qui est écrit à un chemin du répertoire de release et non listé dans
`shared_dirs` repart vide au déploiement suivant. C'est l'équivalent direct de « l'état
écrit sur le système de fichiers d'un conteneur disparaît quand le conteneur est
remplacé » — même mode de défaillance, mécanisme différent, même solution : ne compte
pas sur un stockage local et non partagé pour quoi que ce soit qui doit survivre à plus
d'une release.

## Logs

Le défaut de production de la recipe Symfony fonctionne ici sans modification :

```yaml
when@prod:
    monolog:
        handlers:
            nested:
                type: stream
                path: php://stderr
                level: debug
                formatter: monolog.formatter.json
```

`php://stderr`, formaté en JSON, enveloppé dans un handler `fingers_crossed` pour
qu'une requête qui se termine en erreur déverse toute sa trace de debug et qu'une
requête qui réussit n'écrive rien. PHP-FPM lancé sous systemd envoie son stderr
directement au journal (`journalctl -u php8.4-fpm`), qui le fait déjà tourner et
l'indexe — pas de fichier tournant dans `var/log` que le prochain déploiement risque
d'oublier, ni de job `logrotate` à se rappeler. Si un projet préfère un fichier à la
place, ajoute son répertoire à `shared_dirs` pour qu'il survive entre les releases, et
gère la rotation explicitement.

Deux choses à vérifier plutôt qu'à supposer : que `buffer_size` est bien défini (`50`
dans la recipe) pour qu'une requête incontrôlée ne puisse pas bufferiser sans limite, et
que le canal `deprecation` part quelque part que tu regarderas réellement — les
dépréciations sont le signal d'alerte précoce de la prochaine montée de version
Symfony.

## PHP-FPM et Apache

**Le gestionnaire de processus du pool décide combien de requêtes tournent à la fois.**
`pm = dynamic` avec `pm.max_children` dimensionné selon la mémoire du serveur (`mémoire
disponible / taille moyenne d'un processus PHP`, avec de la marge pour tout le reste
sur la machine) est le défaut raisonnable ; `pm = static` à un nombre fixe supprime le
coût de démarrage de workers sous charge, au prix d'une mémoire réservée qu'elle soit
utilisée ou non. Se tromper sur `pm.max_children`, dans un sens comme dans l'autre,
produit le même symptôme : des requêtes qui s'entassent au socket FPM pendant que le
CPU reste inactif.

**Apache dialogue avec le pool via `mod_proxy_fcgi`**, pas via `mod_php` — `mod_php`
exécute PHP à l'intérieur même des processus worker d'Apache, un interpréteur PHP par
thread Apache, ce qui ne se combine pas avec un pool FPM réglé séparément et qui est
déprécié pour tout ce qui dépasse les installations les plus simples.

```apache
<VirtualHost *:443>
    ServerName app.example.com
    DocumentRoot /var/www/app/current/public

    <Directory /var/www/app/current/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm-app.sock|fcgi://localhost"
    </FilesMatch>

    Protocols h2 h2c http/1.1
</VirtualHost>
```

**`DocumentRoot` et tout chemin communiqué à Apache pointent vers `current`, jamais vers
un répertoire `releases/<n>/` spécifique** — la même règle que pour `opcache.preload`,
pour la même raison : un chemin figé n'importe où en dehors du symlink est un chemin
qui survit à son propre déploiement.

**`Protocols h2 h2c http/1.1` n'est pas optionnel ici.** Contrairement à un conteneur
exposé derrière Caddy, Apache ne négocie pas HTTP/2 par défaut même avec `mod_http2`
activé — c'est cette directive qui l'active pour le vhost. AssetMapper dépend du
multiplexage HTTP/2 pour ne pas être plus lent qu'un bundle ; sauter cette ligne est la
raison la plus fréquente pour laquelle une vérification proche-de-la-production passe
en local alors que le vrai site semble encore lent. Le TLS lui-même est une pièce de
plus qu'aucun conteneur ne fournit gratuitement : `certbot --apache` appliqué à ce vhost
obtient un certificat Let's Encrypt et un timer de renouvellement en une seule commande.
