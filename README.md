# Symfony Skills

Un ensemble de skills d'agent opinionés pour écrire des applications Symfony.

La plupart des agents de codage connaissent Symfony. Ce qu'ils ne savent pas, c'est
*quel* Symfony tu veux : ils placeront volontiers un `QueryBuilder` dans un service, te
rendront une entité sérialisée directement en JSON, et écriront les tests après coup —
si tant est qu'ils les écrivent. Ces skills comblent cet écart en prenant les décisions
en amont, et en expliquant *pourquoi* chacune a été prise, pour que l'agent puisse
appliquer ce raisonnement aux cas que les skills n'ont jamais anticipés.

Les skills vivent dans `.claude/skills/` : ce sont des skills de projet, chargés
automatiquement par Claude Code quand on travaille depuis ce dépôt, et invocables par
leur nom. Ce dépôt n'est plus un catalogue installable ailleurs — c'est l'espace de
travail lui-même, qui embarque aussi [BMAD](https://bmadcode.com/) (`_bmad/`), dont les
agents sont alignés sur cette suite via les overrides de `_bmad/custom/`.

## Porte de qualité

Le socle ERP qui vit dans ce dépôt a une porte unique, en deux façades qui vérifient la
même liste de catégories : `make qa` en local, la CI de `.github/workflows/ci.yml` en
ligne. Un job de CI par catégorie, nommé d'après elle, et
`tests/Core/Quality/QualityGateParityTest.php` échoue en nommant l'orpheline dès qu'une
catégorie n'existe que d'un côté.

```bash
make          # liste les cibles
make qa       # la porte complète — c'est ce que la CI exécute
make cs       # corrige le style plutôt que de le signaler
```

| Catégorie | Cible | Ce qu'elle attrape |
|---|---|---|
| **Static analysis** | `stan` | PHPStan au niveau `max`, extensions Symfony et Doctrine comprises. Aucune baseline : le socle naît avec sa porte, il n'a pas de dette à geler |
| **Code style** | `cs-check` | php-cs-fixer, jeu `@PHP85Migration` par-dessus `@Symfony:risky` et `@PER-CS` |
| **Layer contract** | `deptrac` | La frontière des deux racines — `Core → Module` interdit, `Module → Module` interdit, `Module → Core` hors `Core\Contract` interdit |
| **Tests** | `test` | PHPUnit, chaque test fonctionnel isolé dans une transaction annulée par `dama/doctrine-test-bundle` |
| **Linters and audits** | `lint`, `audit` | Conteneur, Twig, YAML, mapping Doctrine, et `composer audit` |

Tous les outils sont des `require-dev` épinglés par `composer.lock` et lancés par le PHP
du projet depuis `vendor/` : même version en local et en CI, aucune installation globale,
aucun daemon.

### Ce qu'il faut avoir en local

- **PHP 8.5** — `composer.json` exige `>=8.5`, et le style applique `@PHP85Migration`.
- **MySQL 8.4 LTS.** C'est le plancher contractuel du socle et des serveurs clients :
  MySQL 8.0 est en fin de vie depuis avril 2026 et ne tient pas la fenêtre de Symfony 7.4
  LTS. `serverVersion=8.4` le dit dans `.env`, dans `.env.test` et dans le workflow de
  CI, et un test refuse toute dérive entre les trois. SQLite n'est pas une option de
  repli : la suite doit voir le moteur de production, et `dbname_suffix` — le garde-fou
  qui empêche les tests d'écrire dans la base de développement — n'a même aucun effet sur
  cette plateforme.
- **`make`**, déjà présent sur la plupart des hôtes. Sous Windows le `Makefile` bascule
  sur Git Bash, livré avec Git.

La base de test est `app_test`, montée par les migrations et jamais par
`doctrine:schema:create` — une suite qui construit son schéma depuis le mapping n'exerce
jamais les migrations, et les migrations sont la première chose que la production
exécute.

```bash
php bin/console --env=test doctrine:database:create --if-not-exists
php bin/console --env=test doctrine:migrations:migrate --no-interaction
```

Une surcharge locale du DSN se met dans `.env.local` **et** dans `.env.test.local` :
Symfony ne charge pas `.env.local` en environnement de test.

## Les skills

Commencer par `symfony-proglab-standards`. Il est volontairement court : il détecte le
projet, énonce les cinq règles, trace la limite entre le socle et ce qu'un projet doit
mériter, et pointe vers le skill ci-dessous pertinent. Les autres tiennent seuls et se
déclenchent directement quand une tâche relève clairement de leur domaine, et chacun
indique sous son titre à quel niveau il appartient.

| Skill | Couvre |
|---|---|
| **symfony-proglab-standards** | Point d'entrée : détection du projet, les cinq règles, routage vers le reste |
| **symfony-proglab-architecture** | Contrat de couches, DTOs, services, événements, configuration, patterns |
| **symfony-proglab-testing** | Boucle TDD, unitaire vs intégration vs fonctionnel, fixtures, test doubles |
| **symfony-proglab-http** | Contrôleurs, routing, payloads de requête, réponses, formulaires, erreurs |
| **symfony-proglab-doctrine** | Entités, repositories, requêtes, relations, migrations |
| **symfony-proglab-security** | Authentification, autorisation, voters, CSRF, durcissement |
| **symfony-proglab-frontend** | AssetMapper, Stimulus, Turbo, Twig et Live Components, Tailwind |
| **symfony-proglab-ui** | symfony/ux-toolkit et le kit Shadcn : composants Twig prêts à l'emploi, installation, thème |
| **symfony-proglab-accessibility** | Conception universelle WCAG 2.1 : couleur, contraste, labels, focus, alt, titres, survol |
| **symfony-proglab-async** | Messenger, Scheduler, Mailer, retries et gestion des échecs |
| **symfony-proglab-console** | Familles de commandes et commandes invocables, opérations destructives, verrouillage |
| **symfony-proglab-performance** | Requêtes N+1, cache HTTP et applicatif, profiling |
| **symfony-proglab-quality** | PHPStan et php-cs-fixer comme socle, deptrac à la demande, la pipeline CI — avec une config prête à committer |
| **symfony-proglab-local-dev** | Services natifs de Laragon, serveur de dev de la CLI Symfony, Mailpit, vérifications de type production |
| **symfony-proglab-deployment** | Séquence de déploiement, migrations, réglages de production |
| **symfony-proglab-observability** | Canaux Monolog, identifiants de corrélation, ce qu'il ne faut jamais logger, alerting, health checks |
| **symfony-proglab-storage** | Où vont les fichiers uploadés, public vs protégé, les servir, les orphelins |
| **symfony-proglab-upgrade** | Dépréciations, dérive des recipes Flex, Rector, passage à une nouvelle version majeure |

## Ce que ces skills décident

Opinioné signifie que certaines portes sont fermées volontairement. Les principales,
pour que tu puisses juger d'un coup d'œil si cette suite correspond à ta façon de
travailler :

- **Le test d'abord, et le voir échouer.** Un test qui n'a jamais été rouge ne prouve
  rien. Quand le rouge d'abord est impossible, casser le code et vérifier que le test le
  remarque.
- **Rien d'autre que de la traduction dans un contrôleur.** Aucune règle métier, aucune
  persistance.
- **Aucun DQL, QueryBuilder ou SQL en dehors d'un repository.** Les requêtes portent le
  nom de l'intention, pas du mécanisme.
- **Des DTOs des deux côtés.** Une entité n'atteint jamais un template ni une réponse
  JSON.
- **AssetMapper, aucun bundler.** Ce qui veut dire que le front reste Twig, Stimulus et
  Symfony UX — pas de JSX, pas de composants monofichiers.
- **Messenger uniquement pour le travail asynchrone.** Pas comme bus de commandes
  in-process.
- **Deux niveaux.** Cinq règles socles qui suppriment des décisions et ne coûtent rien
  par fonctionnalité ; tout le reste — deptrac, tests de comptage de requêtes,
  Messenger, caches, health checks — s'active sur un besoin réel, jamais par
  anticipation. `symfony-proglab-standards` trace la limite.
- **Une API JSON écrite à la main, jusqu'à un certain point.** Passé une poignée de
  ressources — ou dès qu'il faut un contrat OpenAPI documenté — la suite recommande
  d'utiliser API Platform à la place, et ne le couvre pas.
- **MySQL et le serveur web natifs via Laragon, aucun conteneur.** La CLI Symfony
  ajoute HTTPS et HTTP/2 par-dessus ; un git worktree jetable tient lieu de conteneur
  pour vérifier les conditions de production avant de livrer.

Le raisonnement complet vit dans chaque skill ; `symfony-proglab-architecture` porte le
résumé de chaque rejet délibéré et pourquoi.

## Versions

Les skills ciblent Symfony moderne (6.4 à 8.x) sur PHP 8.2 ou plus récent. Ils ne
présument d'aucune version : chaque skill commence par lire `composer.lock` et s'adapte,
et chacun liste quoi écrire à la place quand un attribut ou un composant donné n'est pas
disponible.

`vendor/` est toujours traité comme la source de vérité au-dessus de tout ce qui est
écrit ici — c'est aussi la règle que les skills s'appliquent à eux-mêmes.

## Origine

Cette suite est un fork personnalisé de
[symfony-yoandev-skills](https://github.com/yoanbernabeu/symfony-yoandev-skills) : même
architecture opinionée, adaptée à une autre stack (Laragon, MySQL, Deployer, Apache) et
traduite en français. Sa valeur vient de ce qu'elle est cohérente et tranchée, pas d'être
un consensus — les options rejetées sont documentées comme des rejets délibérés, avec
leur raison, dans `symfony-proglab-architecture/references/rejections.md`.

## Licence

MIT. Voir [LICENSE](LICENSE).
