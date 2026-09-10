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
| **Linters and audits** | `lint`, `audit` | Conteneur, Twig, YAML, mapping Doctrine, puis `composer audit` et `importmap:audit` — sans `npm` dans la chaîne, c'est la seule vérification de vulnérabilité côté JavaScript |
| **Accessibility** | `a11y` | Le plancher WCAG 2.1 AA du gabarit de base : les sept règles de `symfony-proglab-accessibility` sur les pages réellement rendues et sur les sources, les repères et le lien d'évitement, la région d'annonces, et les ratios de contraste **recalculés** depuis les `oklch` du thème plutôt que recopiés. C'est aussi la seule catégorie qui exécute du **JavaScript** — les deux contrôleurs Stimulus du gabarit, sur un DOM simulé (voir plus bas) |

Tous les outils sont des `require-dev` épinglés par `composer.lock` et lancés par le PHP
du projet depuis `vendor/` : même version en local et en CI, aucune installation globale,
aucun daemon.

### La catégorie « Accessibility » exécute aussi du JavaScript

C'est la seule entorse à « aucune étape Node », et elle est bornée par un test plutôt que
par une bonne intention.

Le gabarit porte deux contrôleurs Stimulus qui **sont** du comportement d'accessibilité :
`page-focus` replace le focus après chaque navigation Turbo — bloc d'erreur, champ
invalide, Flash, puis `h1` —, et `announce` recopie le texte d'un Flash ou d'un bloc
d'erreur dans la région live `#annonces`, en `assertive` pour une erreur et en `polite`
sinon. Sans eux, une navigation Turbo laisse le focus sur un lien qui n'existe plus et un
message rendu côté serveur n'est jamais annoncé. Il n'y a pas de DOM en PHP : aucun test
de la suite ne peut prouver l'un ou l'autre.

```bash
npm --prefix tests/js install   # une seule fois, puis à chaque changement du lockfile
node --test "tests/js/**/*.test.js"
```

Le runner est celui de Node (`node --test`), le DOM vient de **jsdom**, et Stimulus n'est
pas installé depuis npm : les tests importent `assets/vendor/@hotwired/stimulus/`, le
fichier commité qu'`importmap.php` épingle et que le navigateur reçoit. Une seconde copie
en dépendance de test dériverait un jour de celle qui est servie.

**Pourquoi c'est la seule entorse, et pourquoi elle ne s'élargit pas.** La chaîne d'assets
reste sans Node : ni bundler, ni `package.json` à la racine, ni `node_modules` à la racine.
Tout le nécessaire — manifeste, lockfile, dépendances installées, harnais — vit sous
`tests/js/`, et rien de ce répertoire n'atteint un navigateur. Deux tests de
`tests/Core/Theme/AssetPipelineTest.php` tiennent ce partage sans qu'on ait à le relire :
`nothing_in_the_chain_needs_node()` refuse tout artefact Node à la racine, et
`the_node_exception_is_confined_to_the_test_directory()` refuse qu'il en apparaisse
ailleurs qu'à `tests/js/` **et** que `assets/`, `importmap.php` ou la cible de build se
mettent à dépendre de `tests/js/`. La flèche va dans un seul sens : les tests lisent les
assets, jamais l'inverse.

### Ce que la catégorie « Accessibility » ne peut pas voir

Il n'y a toujours pas de navigateur dans la porte : jsdom exécute du JavaScript sur un
arbre DOM, il n'a ni moteur de mise en page, ni ordre de tabulation réel, ni lecteur
d'écran. Trois choses restent donc à vérifier à la main, sur `/` et sur chaque écran
ajouté ensuite — c'est court, et c'est le complément assumé du contrôle automatisé, pas un
oubli :

- **Tab depuis le haut de la page.** Le premier élément doit être « Aller au contenu », il
  doit devenir visible en prenant le focus, et l'activer doit poser le focus dans le
  `<main>`. Puis continuer jusqu'en bas : à chaque arrêt, on doit pouvoir dire où on est.
- **JavaScript coupé** (DevTools › Command Palette › « Disable JavaScript »). La page reste
  complète et navigable ; Turbo et les deux contrôleurs Stimulus n'ajoutent que le confort.
- **Zoom à 400 %**, soit 320 px de large en CSS. La mise en page se réorganise sans
  défilement horizontal du `body` ; seuls un tableau ou un bloc à chasse fixe ont le droit
  de défiler dans leur propre conteneur.

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
- **Node 22.15 ou plus récent**, pour la seule étape Node du dépôt : les tests des deux
  contrôleurs Stimulus, sous `tests/js/`. Le plancher n'est pas arrondi — le harnais résout
  `@hotwired/stimulus` vers le fichier commité par `module.registerHooks()`, qui n'existe
  pas avant 22.15. Il est déclaré une seule fois, dans l'`engines.node` de
  `tests/js/package.json` : `engine-strict=true` en fait un refus d'npm, la CI lit ce même
  champ, et un test tient les trois endroits alignés. `make a11y` installe les dépendances
  quand elles manquent ou que le lockfile a bougé, et ne touche pas au réseau sinon. Le
  reste de la porte, et toute la chaîne d'assets, s'en passent — c'est détaillé plus haut.

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

### Le frontend, sans Node

AssetMapper sert les assets en modules ES natifs et Tailwind 4 est compilé par un binaire
autonome que `symfonycasts/tailwind-bundle` télécharge dans `var/`. **Ni `npm`, ni
`node_modules`, ni bundler** — et donc ni JSX, ni composants monofichiers, ni TypeScript :
c'est le marché, et il ne se renégocie pas fonctionnalité par fonctionnalité. La seule
exception du dépôt est `tests/js/`, qui exécute les deux contrôleurs Stimulus du gabarit
sous la catégorie « Accessibility » et ne touche pas la chaîne d'assets — voir plus haut.

```bash
php bin/console tailwind:build --watch   # à laisser tourner pendant le développement
php bin/console debug:asset-map          # ce qui est réellement mappé

php bin/console tailwind:build --minify  # au déploiement, dans cet ordre
php bin/console asset-map:compile        # digère et écrit dans public/assets/
```

**Les deux commandes de déploiement vont dans cet ordre.** Le bundle Tailwind ne
s'accroche à aucun événement d'`asset-map:compile` : il intercepte la feuille d'entrée et
sert son résultat compilé. Lancer la seconde sans la première sur une release neuve
avorte sur « Built Tailwind CSS file does not exist ».

**« Mes classes Tailwind ne font rien » est presque toujours un `--watch` qui ne tourne
pas.** La sortie de compilation vit dans `var/`, ignorée par git ; `public/assets/` l'est
aussi. `assets/vendor/`, en revanche, est **commité** : un `composer install` sur le
serveur n'a pas besoin d'aller rechercher les dépendances JavaScript. Le binaire Tailwind,
lui, se télécharge au premier build — la chaîne est sans Node, elle n'est pas hors ligne.

Le thème est en deux feuilles. `assets/styles/theme.css` porte tout le socle — rôles de
couleur en clair et en sombre, typographie, rayons, espacement, mouvement — et n'est
jamais édité par un dérivé. `assets/styles/brand.css` est livré vide : c'est le seul
fichier qu'un dérivé touche, et il ne contient que les variables qu'il a le droit de
redéfinir. `tests/Core/Theme/` refuse un rôle sans variante sombre, une couleur codée en
dur dans un template, un second kit de composants et un binaire Tailwind non épinglé.

Toutes les pages passent par un gabarit unique, `templates/base.html.twig` : lien
« Aller au contenu », repères, `<main id="contenu" tabindex="-1">`, région d'annonces
persistante que les navigations Turbo ne recréent pas, et un `<title>` composé
« <page> — <nom du dérivé> ». Le nom du dérivé vient de la variable d'environnement
`APP_NAME` — c'est, avec `brand.css` et le logo, tout ce qu'un rebranding touche. Le `h1`
appartient au template de page, jamais au gabarit.

Les composants viennent du kit shadcn de Symfony UX Toolkit, **copiés** dans le dépôt :

```bash
php bin/console ux:install <nom> --kit shadcn   # un seul kit, jamais un second
php bin/console ux:icons:lock                   # verrouille les icônes Tabler utilisées
```

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
