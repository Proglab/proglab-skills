# La vérification de type production

## Sommaire

- [Quand l'exécuter](#quand-lexécuter)
- [L'exécuter](#lexécuter)
- [Ce qu'il faut observer](#ce-quil-faut-observer)
- [Les bugs que le serveur de dev ne détecte pas](#les-bugs-que-le-serveur-de-dev-ne-détecte-pas)
- [Ce qu'elle ne peut pas simuler](#ce-quelle-ne-peut-pas-simuler)
- [Pourquoi ne pas développer dedans](#pourquoi-ne-pas-développer-dedans)

## Quand l'exécuter

Pas à chaque commit. Avant de livrer quelque chose où la différence entre dev et prod
pourrait compter :

- tout ce qui a touché aux assets, aux templates ou au front ;
- un premier déploiement, ou le premier après une montée de version de Symfony ;
- l'ajout d'un bundle, d'un listener, ou de tout ce qui est enregistré dans le
  conteneur ;
- un bug qui « n'arrive qu'en production » et qu'il faut reproduire ;
- avant une release qu'on ne peut pas facilement annuler (rollback).

## L'exécuter

```bash
git worktree add ../app-prod-check
cd ../app-prod-check

cp ../app/.env.local .env.local
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader --no-progress
php bin/console cache:clear --env=prod
php bin/console asset-map:compile

symfony server:start -d --port=8443
symfony open:local
```

Le worktree partage l'historique Git avec le checkout principal mais possède son propre
`vendor/`, `var/` et sa propre copie de travail, donc `composer install --no-dev`
exécuté là ne peut pas retirer une dépendance de dev dont le checkout quotidien a encore
besoin. Copier `.env.local` fait pointer les deux répertoires vers la **même base de
données** — l'objectif est de vérifier avec de vraies données, pas avec un jeu de
fixtures qui fonctionne par chance.

Le certificat est auto-signé, donc le navigateur avertit une fois. C'est normal, et
c'est ce qui donne HTTP/2 en local, comme pour le serveur de dev quotidien.

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

## Les bugs que le serveur de dev ne détecte pas

| Bug | Pourquoi le dev le masque |
|---|---|
| `asset-map:compile` manquant dans le script de déploiement | Le dev sert les assets à la volée, donc ça marche toujours |
| Du code qui dépend du profiler ou de `dump()` | Les deux sont chargés en dev |
| Un service qui n'est `public` qu'en config dev | `$container->get()` continue de fonctionner en dev |
| Un template référençant une variable non définie | `strict_variables` est souvent désactivé en config prod, donc l'échec diffère |
| Une variable d'environnement définie seulement dans `.env.local` et jamais copiée dans le worktree | C'est tout l'intérêt de la copier explicitement |
| Un échec du réchauffement du cache (warmup) | Le dev réchauffe paresseusement, une entrée à la fois |

## Ce qu'elle ne peut pas simuler

Un conteneur donne un `php.ini` véritablement séparé ; un worktree sur la même machine
ne le fait pas — Laragon sert chaque processus PHP à partir de celui qu'il a installé.
Donc :

- **OPcache avec `validate_timestamps=0`** est un comportement de production réel que
  cette vérification n'exerce pas, sauf si tu modifies toi-même `validate_timestamps`
  dans le `php.ini` de Laragon, exécutes la vérification, puis le rétablis ensuite. Ça
  vaut le coup quand un bug y est spécifiquement suspecté ; pas par défaut, car oublier
  de le rétablir transforme l'environnement de dev quotidien en piège à cache périmé.
- **Le durcissement du `php.ini` de production** (fonctions désactivées, `expose_php`,
  limites mémoire) n'est absolument pas exercé ici — cette vérification contrôle
  l'*application*, pas la configuration serveur qu'exécute une vraie cible de
  déploiement.

## Pourquoi ne pas développer dedans

Chaque changement implique de répéter `composer install --no-dev` et
`asset-map:compile` — il n'y a pas de rechargement instantané. Le débogage pas à pas sur
du code `--no-dev` est aussi trompeur : Xdebug se comporte différemment dès que les
hypothèses de mise en cache des opcodes changent.

La boucle quotidienne reste `symfony server:start` dans le checkout principal. Le
worktree répond à une question précise, puis on le supprime :

```bash
symfony server:stop
cd ../app
git worktree remove ../app-prod-check
```
