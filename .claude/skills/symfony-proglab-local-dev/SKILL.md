---
name: symfony-proglab-local-dev
description: >-
  Configure et exécute un projet Symfony en local sur Laragon : MySQL et Apache/Nginx
  tournent nativement (sans conteneurs), un binaire Mailpit autonome capture les emails,
  le serveur de développement de la CLI Symfony apporte HTTPS et HTTP/2, et une
  vérification de type production basée sur un git worktree avant de livrer. Utilise ce
  skill dès que quelqu'un demande comment démarrer ou lancer le projet, configurer un
  environnement de développement, faire tourner la base de données, retrouver où sont
  partis les emails envoyés, corriger « connection refused » ou « could not find
  driver », activer HTTPS en local, prendre en main un projet Symfony inconnu, ou
  vérifier qu'une fonctionnalité marche avec APP_ENV=prod. Utilise-le aussi quand un
  projet n'a pas de README expliquant comment le démarrer.
---

# Développement local

> **Niveau : socle** — un projet qui ne peut pas démarrer ne peut pas être testé. La vérification de type production est la partie à la demande : à exécuter avant de livrer, pas quotidiennement.

MySQL et le serveur web tournent nativement via Laragon, PHP tourne sur la machine
hôte, et la CLI Symfony ajoute HTTPS et HTTP/2 par-dessus.

```bash
# Laragon démarré (icône de la barre des tâches, ou laragon.exe), MySQL et Apache déjà lancés
symfony server:start -d       # serveur de dev, HTTPS, HTTP/2
symfony open:local            # ouvre l'application
mailpit &                     # une fois par session, si ce n'est pas déjà lancé
```

C'est toute la boucle quotidienne. La suite explique pourquoi ça fonctionne et ce qui
peut casser.

## Ce qui diffère d'une configuration Dockerisée

Il n'y a pas de conteneurs, donc rien à auto-détecter. `DATABASE_URL` et `MAILER_DSN`
sont écrits une fois pour toutes dans `.env.local`, exactement comme pour n'importe quel
projet Symfony sans Docker :

```env
# .env.local — valeurs par défaut de MySQL sous Laragon : root, sans mot de passe, port 3306
DATABASE_URL="mysql://root:@127.0.0.1:3306/app?serverVersion=8.0&charset=utf8mb4"
MAILER_DSN=smtp://127.0.0.1:1025
```

Cela élimine toute une catégorie de bugs dont la version Docker de ce skill doit mettre
en garde : il n'y a pas de couche d'injection susceptible de diverger entre
`symfony console` et `php bin/console`, ni de risque qu'un « service connecté » écrase
silencieusement `--env=test`. **`symfony console` et `php bin/console` sont
interchangeables ici** — tous deux lisent `.env` et `.env.local` de la même façon, rien
de plus.

`symfony var:export --debug` reste utile à connaître : elle affiche la valeur finale de
chaque variable et le fichier dont elle provient (`.env`, `.env.local`, `.env.test`, le
shell). Sur cette configuration, c'est du débogage classique de superposition de
fichiers `.env`, pas du débogage de conteneurs.

## Faire tourner plusieurs projets en parallèle

MySQL et Apache/Nginx de Laragon sont partagés, une seule instance pour tous les
projets — pas un conteneur par projet. Deux choses évitent les collisions entre
projets :

- **Une base de données par projet**, pas un serveur par projet : `app`, `app_test`, et
  les mêmes noms pour un second projet entreraient en collision. Nomme les bases de
  données d'après le projet (`bookshelf`, `bookshelf_test`), pas de façon générique.
- **Les hôtes virtuels automatiques de Laragon.** Tout dossier placé sous le répertoire
  `www/` de Laragon obtient gratuitement `<nomdudossier>.test`, servi par Apache sur le
  port 80 — aucun jonglage de ports. C'est un hôte de confort, pas celui utilisé pour le
  travail AssetMapper/HTTP-2 ci-dessous ; continue d'utiliser `symfony server:start`
  pour cela.

## Configurer un projet qui n'a ni l'un ni l'autre

Crée la base de données (via HeidiSQL, phpMyAdmin, ou `mysql -u root`), écris
`DATABASE_URL` dans `.env.local` comme ci-dessus, puis :

```bash
symfony console doctrine:database:create
symfony console doctrine:migrations:migrate --no-interaction
symfony console doctrine:fixtures:load --no-interaction   # si le projet a des fixtures
symfony server:start -d
```

Si le projet n'a ni fixtures ni données de départ, dis-le plutôt que d'inventer des
lignes — une application vide qui démarre est un résultat normal, et fabriquer
discrètement des données masque le fait que la prise en main est incomplète.

## HTTPS, et pourquoi ce n'est pas optionnel ici

```bash
symfony server:ca:install     # une fois par machine
symfony server:start -d
```

Cela donne à chaque projet HTTPS avec HTTP/2, sans avertissement de certificat — par-
dessus le même PHP hôte que Laragon fournit déjà, donc rien ne change côté base de
données ou mailer.

Cela va au-delà du confort. **AssetMapper dépend du multiplexage HTTP/2** : il sert de
nombreux petits fichiers au lieu d'un seul bundle. Développer en HTTP simple signifie
que le seul environnement où tu remarquerais un problème se comporte différemment de la
production. Cela fait aussi apparaître les problèmes de contenu mixte et de cookies
sécurisés le jour où tu écris le code, plutôt qu'après un déploiement.

## Emails

[Mailpit](https://mailpit.axllent.org) capture tout, exécuté comme un simple binaire —
sans conteneur. Télécharge-le une fois, puis :

```bash
mailpit                      # SMTP sur le port 1025, interface web sur le port 8025
```

Laisse-le tourner dans un terminal, ou enregistre-le comme tâche d'arrière-plan pour
qu'il survive à un redémarrage aux côtés de Laragon. Deux choses à vérifier quand les
emails semblent disparaître :

- **Ils sont peut-être mis en file d'attente, pas envoyés.** Par défaut, ce standard
  route `SendEmailMessage` vers le transport asynchrone, donc rien n'arrive à Mailpit
  tant qu'un worker ne l'a pas consommé. Lance `symfony console messenger:consume async
  -vv`, ou accepte que le mail apparaisse en retard. Dans les tests, c'est
  `assertQueuedEmailCount()` qui s'applique, pas `assertEmailCount()`.
- **`MAILER_DSN` doit pointer vers le port 1025**, pas 8025 — ce dernier n'est que
  l'interface web.

## Vérifier comme la production l'exécutera

Le serveur de dev est indulgent d'une manière que la production ne l'est pas : le
profiler est chargé, OPcache revalide à chaque requête, les assets sont servis via PHP,
les erreurs sont visibles. Certains bugs n'existent que de l'autre côté de cette
frontière. Sans conteneur jetable, un **git worktree** jetable fait le même travail : un
second checkout, à usage unique, avec son propre `vendor/` et `var/`, de sorte que la
vérification ne puisse pas laisser la copie de dev quotidienne dans un état semi-prod.

```bash
git worktree add ../app-prod-check
cd ../app-prod-check

cp ../app/.env.local .env.local          # même DATABASE_URL, même MAILER_DSN
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader --no-progress
php bin/console cache:clear --env=prod
php bin/console asset-map:compile

symfony server:start -d --port=8443
symfony open:local
```

Les deux répertoires partagent la même base de données, ce qui rend la vérification
significative — ce sont les mêmes données, pas un jeu de fixtures qui fonctionne par
chance.

## Ce qu'il faut observer

Parcours cette liste plutôt que de cliquer au hasard. Chaque ligne est quelque chose que
le serveur de dev ne peut pas te dire.

- [ ] **L'application démarre tout court.** La moitié de la valeur est ici : un bundle
      enregistré uniquement en dev, un service `public` dans la config dev, une
      variable d'environnement qui n'existe que dans `.env.local` et n'a jamais été
      copiée dans le worktree.
- [ ] **Les assets sont servis, pas générés.** Ouvre le panneau réseau : les fichiers
      doivent provenir de `/assets/…` avec un hash de contenu dans le nom. Si
      `asset-map:compile` n'a pas tourné, Symfony les génère à chaque requête via PHP.
- [ ] **Le protocole est HTTP/2.** Visible dans le panneau réseau. AssetMapper sert de
      nombreux petits fichiers au lieu d'un seul bundle ; en HTTP/1.1, c'est plus lent
      qu'un bundle, ce qui amène certains à conclure qu'AssetMapper est lent.
- [ ] **Pas de profiler, pas de barre de debug.** Si l'un des deux apparaît, `APP_ENV`
      n'a pas été pris en compte.
- [ ] **Les pages d'erreur sont les vraies.** Déclenche une 404 et une 500. Une stack
      trace ici signifie que le debug est actif ; une page blanche signifie que le
      template d'erreur est manquant.
- [ ] **Rien n'écrit en dehors de `var/`.**

## Ce qu'un worktree ne peut pas simuler

Laragon sert chaque processus PHP à partir du **même `php.ini`** — il n'y a pas de
runtime séparé et jetable comme le donnerait une image de conteneur. Deux
conséquences :

- **OPcache avec `validate_timestamps=0`** est un réglage de production réel que cette
  vérification n'exerce pas, sauf si tu le modifies toi-même dans le `php.ini` de
  Laragon, exécutes la vérification, puis le rétablis. Fais-le délibérément, et
  uniquement quand un bug y est spécifiquement suspecté — pas systématiquement, car il
  est facile d'oublier de le rétablir.
- Tout le reste — bundles chargés, assets compilés, HTTP/2, pages d'erreur, profiler —
  est réellement isolé par le `vendor/`, le `var/` et le `.env.local` propres au
  worktree, car ceux-ci sont par répertoire, pas par `php.ini`.

**C'est une étape de vérification, pas un environnement de travail.** Ne déplace pas le
développement quotidien dans le worktree : pas de rechargement instantané, et chaque
changement implique de répéter `composer install --no-dev` et `asset-map:compile`.
Exécute-la avant de livrer quelque chose d'important, pas avant chaque commit. Nettoie
ensuite avec
`symfony server:stop && cd ../app && git worktree remove ../app-prod-check` une fois
terminé.

## Quand quelque chose ne démarre pas

| Symptôme | Cause |
|---|---|
| `connection refused` sur la base de données | Laragon n'est pas lancé, ou MySQL a été arrêté depuis le menu de Laragon |
| `could not find driver` | `pdo_mysql` manque dans le PHP réellement utilisé par Laragon (ou par la CLI Symfony) — vérifie `symfony local:php:list` / `php -m` |
| `DATABASE_URL` semble incorrecte | Vérifie directement `.env.local` — rien ne l'injecte ni ne l'écrase ici, contrairement à une configuration Docker |
| Les emails n'arrivent jamais | Mailpit n'est pas lancé, ou ils sont en file d'attente ; consomme le transport |
| Port 80/443 déjà utilisé | Un autre service (Apache/IIS/vieux Skype) l'occupe ; arrête-le ou change le port de Laragon depuis son menu |

`symfony server:log` affiche les logs du serveur, de PHP et de l'application dans un
seul flux, ce qui est généralement plus rapide que d'ouvrir `var/log/dev.log`.

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/symfony-cli.md` | Le serveur de dev de la CLI Symfony, HTTPS, workers, configuration par projet |
| `references/prod-like-check.md` | Exécuter la vérification basée sur le worktree et ce qu'il faut observer |
