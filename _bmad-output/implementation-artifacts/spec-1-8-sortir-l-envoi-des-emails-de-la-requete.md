---
title: "Sortir l'envoi des emails de la requête"
type: 'feature'
created: '2026-09-14'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '57e5277eecbedbd6ab2e093cf74e3e2f3e88e7a4'
context:
  - '{project-root}/.claude/skills/symfony-proglab-async/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-7-ralentir-les-tentatives-de-connexion-repetees.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** le socle n'a ni `symfony/mailer` ni `symfony/messenger`. La story 1.9 enverra
le premier email du projet, et les stories 2.6, 5.2 et 5.3 suivront ; sans infrastructure,
chacune rebranchera un `send()` synchrone dans son cas d'usage, et un SMTP client lent fera
échouer l'enregistrement qui vient pourtant d'aboutir.

**Approach :** poser l'infrastructure une fois, complète et vérifiée, sans utilisateur
final : un message `App\Core\Message\SendEmail` porteur de scalaires et d'une locale
explicite, un handler qui compose et envoie un `TemplatedEmail`, un transport Doctrine avec
retry et file d'échec, un gabarit d'email et son domaine de traduction. Le seul consommateur
livré est le module de démonstration des tests — le socle ne gagne ni page ni route.

## Boundaries & Constraints

**Always :**
- **Un seul chemin d'envoi** : tout email du socle passe par `SendEmail` sur le bus, routé
  vers le transport Doctrine. Un cas d'usage qui veut envoyer un email dispatche ce message ;
  il n'appelle jamais `MailerInterface` lui-même.
- **Le message ne porte que des scalaires** et un `SupportedLocale`. Jamais une entité,
  jamais un objet `Email`, jamais rien qui dépende de la requête en cours. Le rendu — sujet
  et corps — a lieu dans le worker, dans la locale portée par le message.
- **Le message n'est consommable qu'après le commit** de la transaction qui l'a produit
  (AD-21). Le mécanisme retenu est celui de la décision **D-1**, et il est prouvé par un
  test, pas affirmé par un commentaire.
- Retry et file d'échec sont configurés dès la première ligne : `max_retries: 3`, backoff
  exponentiel, `failure_transport: failed` persistant sur la même base.
- La table du transport est créée par **migration relue**, jamais par `auto_setup`.
- Les catalogues du nouveau domaine existent dans les trois langues, source française
  (`CatalogParityTest`).
- La porte de qualité reste à **six catégories** : aucune nouvelle testsuite, aucun job de CI
  supplémentaire, aucun worker lancé pendant les tests.

**Never :**
- Ne pas router `Symfony\Component\Mailer\Messenger\SendEmailMessage` vers un transport
  asynchrone : le handler appelle `MailerInterface` **dans le worker**, et un double routage
  remettrait chaque email en file une seconde fois.
- Ne pas utiliser Messenger comme bus de commandes : aucun cas d'usage du socle ne transite
  par le bus, seul l'envoi d'email le fait.
- Ne pas livrer le Scheduler, ni les files priorisées (`async_high` / `async_low`), ni
  l'alerte sur la file d'échec ni le health check de la file (Epic 6, FR-18).
- Ne pas écrire d'email de production : aucun texte destiné à un utilisateur réel n'est
  rédigé ici. Le contenu des emails appartient à 1.9, 2.6 et 5.2.
- Ne pas toucher `deploy.php` ni l'orchestration SSH : le déploiement est la story 3.1.

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Sortie / Comportement attendu | Gestion d'erreur |
|---|---|---|---|
| Mise en file nominale | un cas d'usage dispatche `SendEmail` puis commite | une ligne dans `messenger_messages`, queue `default` ; la réponse HTTP part sans attendre le SMTP | N/A |
| Transaction annulée | le cas d'usage dispatche puis lève avant le commit | **aucune** ligne dans `messenger_messages` : le message disparaît avec l'entité qu'il annonçait | N/A |
| Visibilité avant commit | transaction ouverte, message dispatché, pas encore commitée | une connexion indépendante ne voit **aucune** ligne | N/A |
| Consommation | le worker dépile un `SendEmail` portant `locale: nl` | sujet et corps rendus en néerlandais, quelle que soit la locale par défaut du worker | N/A |
| SMTP injoignable | le worker dépile, le transport SMTP refuse | 3 tentatives à délai croissant, puis la ligne part sur le transport `failed` et y **reste** | aucune perte, aucune boucle infinie |
| Message irrécupérable | template inexistant, destinataire vide | `UnrecoverableMessageHandlingException` : pas de retry, passage direct en `failed` | N/A |
| Appel direct du mailer | un service appellerait `MailerInterface::send()` | l'envoi est **synchrone** — ce n'est pas un chemin du socle, et rien ne le rend asynchrone par surprise | N/A |
| Suite de tests | `vendor/bin/phpunit` | transport `in-memory`, aucun worker, aucun SMTP contacté | N/A |

## Décisions de Fabrice (2026-09-14)

**D-1 — « Après le commit » se garantit par la transaction, pas par un middleware.** AD-21
nomme `dispatch_after_current_bus` ; ce middleware ne diffère un dispatch que depuis
l'intérieur d'un handler, donc uniquement si Messenger sert de bus de commandes — ce que
`README.md:198` interdit. Le mécanisme retenu est le partage de connexion : le service porte
la frontière de transaction (`wrapInTransaction()`) et dispatche **à l'intérieur** ; le
transport `doctrine://default` écrit sur la connexion DBAL de l'application, donc l'INSERT
rejoint la même transaction et n'est visible qu'au commit. **Conséquences à tenir :** chaque
cas d'usage qui envoie un email ouvre sa transaction explicitement, le fait est écrit là où
un appelant le lira, et AD-21 est à corriger sur le nom du mécanisme (entrée dans
`deferred-work.md`, l'amendement de l'architecture n'appartient pas à cette story).

**D-2 — En développement, le worker est explicite.** `.env` porte
`MAILER_DSN=smtp://127.0.0.1:1025`, une cible `make worker` et une section du README le
disent. Aucun routage `sync` en `when@dev` : le développement exerce exactement le chemin de
production — la file, le retry et la file d'échec comprise. Le critère 4 de la story se lit
donc « sans configuration supplémentaire », et non « sans worker » ; l'écart est nommé ici
plutôt que contourné par une configuration parallèle.

**D-3 — L'unité systemd est livrée maintenant.** `deploy/systemd/` porte l'unité du worker et
le README la documente ; la story 3.1 l'installera et la redémarrera depuis Deployer. Le
critère 3 de la story se ferme, en sachant que le fichier ne sera exercé qu'à l'Epic 3 — ce
qui est inscrit dans `deferred-work.md` avec ce que 3.1 devra en faire.

</frozen-after-approval>

## Code Map

- `composer.json` — ni `symfony/mailer`, ni `symfony/messenger`, ni
  `symfony/doctrine-messenger`. Ce sont les **trois** paquets à ajouter en `7.4.*`.
  `symfony/lock` reste absent et le reste : le transport Doctrine s'appuie sur `SKIP LOCKED`.
- `config/packages/` — aucun `messenger.yaml` ni `mailer.yaml`. Les recettes Flex de ces
  paquets vivent dans `recipes` (et non `recipes-contrib`, que ce projet n'exécute pas) :
  elles seront appliquées, et les fichiers produits sont à **relire et réécrire** comme
  l'ont été `security.yaml` et `monolog.yaml`. Aucun bundle à ajouter dans
  `config/bundles.php` : ce sont des composants.
- `.env:17-48` — quatre blocs `###> … ###`, chacun précédé d'un commentaire français qui dit
  *pourquoi* la variable existe et *quel test* la tient. `MAILER_DSN` et
  `MESSENGER_TRANSPORT_DSN` suivent cette forme. `.env.test:1-11` porte un DSN de base
  explicite ; `.env.dev:1-4` ne porte qu'`APP_SECRET`.
- `deptrac.yaml:86-89` — la couche **`Message` existe déjà** :
  `(src|tests/Fixtures)/(Core|Module/[^/]+)/(Message|MessageHandler)/.*`. Son ruleset
  (`:277-285`) autorise `Message, Service, Repository, Dto, Entity, Exception, Enum` — donc
  **ni `Http`, ni `Doctrine`, ni `EntityManager`** : un handler ne peut ni requêter ni
  `flush()`, il appelle un service. `Controller` (`:243`) et `Service` (`:258`) peuvent
  dispatcher. **Rien à ajouter dans ce fichier.**
- `config/services.yaml:56-58` — le glob `App\Core\` exclut `{Contract,Dto,Entity,Enum}` :
  `Message/` n'y est **pas**, donc le message serait enregistré comme service. Vérifier par
  `debug:container` et, si c'est le cas, l'ajouter à l'exclusion — un objet de données n'est
  pas un service. `:89-145` — le précédent des alias publics `when@test`, chacun avec sa
  consigne de retrait.
- `config/packages/translation.yaml:16` — le commentaire anticipe déjà « le rendu d'un email
  déjà en file » : `enabled_locales` compile les trois catalogues indépendamment des langues
  actives, précisément pour que le worker sache rendre un email dont la langue a été
  désactivée entre-temps. Rien à y changer. `:32-40` — le précédent du **miroir `when@test`**
  qui ajoute `tests/Fixtures/Module` : c'est la forme exacte à reprendre pour un chemin de
  templates de test.
- `config/packages/twig.yaml` — deux globals (`app_name`, `active_locales`), aucun chemin
  supplémentaire. Un template rendu par le worker n'a **pas** de `app.request` : toute URL
  absolue doit venir de `framework.router.default_uri` (`DEFAULT_URI`, `.env:26`), et
  `active_locales` requête la base — à ne pas utiliser dans un email.
- `templates/` — `base.html.twig`, `components/` (kit shadcn), `security/`, `home/`,
  `bundles/TwigBundle/Exception/`. **Aucun `templates/emails/`** : à créer. Le gabarit de page
  ne convient pas à un email (landmarks, skip-link, importmap).
- `translations/` — `messages.{fr,en,nl}.yaml` et `security+intl-icu.{fr,en,nl}.yaml`.
  Convention : un domaine par surface, clés anglaises pointées, source française, ICU
  seulement dans un domaine suffixé `+intl-icu` posé **à côté** du domaine simple.
  `tests/Core/Translation/CatalogParityTest.php:48-61` exige les trois fichiers d'un nouveau
  domaine, toutes clés présentes et non vides.
- `migrations/Version20260911074811.php`, `Version20260911130140.php` — la convention :
  générée par `doctrine:migrations:diff`, **puis relue**, avec un docblock français qui dit
  explicitement ce que la relecture a corrigé, et `getDescription()` implémenté. Les
  migrations passent PHPStan max (`phpstan.dist.neon:27`) et php-cs-fixer
  (`.php-cs-fixer.dist.php:62`).
- `Makefile:14-19` — la table `# qa-category:` ; `:39` la cible `qa` ; `:55-59` la cible
  `test` (garde-fou DAMA → migrations → `tailwind:build` → phpunit).
  `tests/Core/Quality/QualityGateParityTest.php:32-38,103-120` : **six catégories**, et toute
  cible prérequis de `qa` doit avoir son job de CI homonyme lançant les mêmes commandes. Une
  cible `worker` **hors** des prérequis de `qa` ne déclenche rien.
- `.github/workflows/ci.yml:35-105` — le job « Tests » monte `mysql:8.4` et construit le
  schéma par `doctrine:migrations:migrate`, jamais `schema:create`
  (`tests/Core/Quality/TestDatabaseEngineTest.php:94,107`) : la migration du transport est
  donc jouée en CI comme en local. `:198` et `Makefile:161` —
  `doctrine:schema:validate --skip-sync` tourne dans « Linters and audits » ; il valide le
  **mapping**, pas la base, donc une table hors ORM ne le fait pas rougir.
- `tests/Core/Observability/ErrorLoggingTest.php:146-163` — une 500 doit produire
  **exactement un** record avec `extra` **vide** : n'ajouter aucun processor Monolog. `:173+`
  — `excluded_http_codes` doit rester mot pour mot identique entre `when@test` et `when@prod`.
  `config/packages/monolog.yaml:26-33` dit que le canal `messenger` **se filtre et ne se
  déclare pas** : ne pas l'ajouter à `channels`.
- `tests/Fixtures/Module/Demo/` — module de test complet (contrôleurs, entité `DemoWidget`,
  descripteur, catalogues `demo.{fr,en,nl}.yaml`), miroir des globs de `config/services.yaml`
  et de `translation.yaml`. C'est **là** que vit le consommateur de démonstration :
  `DemoErrorController` est le précédent exact d'un chemin livré uniquement pour prouver un
  mécanisme du socle. Pas de dossier `templates/` aujourd'hui.
- `tests/Core/Quality/DatabaseIsolationTest.php` — DAMA enveloppe chaque test dans une
  transaction annulée à la fin. C'est ce qui rend le scénario « visibilité avant commit »
  testable : la transaction du test **est** la transaction ouverte.
- `src/Core/Enum/SupportedLocale.php` — l'enum des trois langues, déjà source de vérité du
  translator ; c'est lui que le message porte, pas une chaîne libre.
- `src/Core/Service/ActiveLocales.php` — exemple de service `final readonly` nommé d'après sa
  responsabilité, avec mémorisation par requête.
- `README.md:198` — « Messenger uniquement pour le travail asynchrone, pas comme bus de
  commandes ». C'est la ligne qui met AD-21 en tension, et que la décision **D-1** tranche.
- `_bmad-output/implementation-artifacts/deferred-work.md` — aucune entrée ne mentionne
  encore Messenger, Mailer ou un worker : cette story ouvre la première.

## Tasks & Acceptance

**Execution :**
- [x] `composer.json` -- ajouter `symfony/mailer`, `symfony/messenger` et
      `symfony/doctrine-messenger` en `7.4.*`, puis relire les fichiers posés par les
      recettes Flex avant de les committer -- trois paquets, aucun bundle ; une recette non
      relue est une configuration que personne n'assume.
- [x] `config/packages/messenger.yaml` -- transport `async` sur
      `%env(MESSENGER_TRANSPORT_DSN)%` avec `auto_setup: false` et la politique de retry
      (`max_retries: 3`, `delay: 1000`, `multiplier: 2`, `jitter: 0.3`), transport `failed` en
      `queue_name=failed`, `failure_transport: failed`, routage de `App\Core\Message\SendEmail`
      vers `async`, et un `when@test` qui remplace le **DSN** par `in-memory://` en gardant les
      mêmes noms de transport -- écrire noir sur blanc, en commentaire, que `SendEmailMessage`
      n'est **pas** routé, et pourquoi.
- [x] `config/packages/mailer.yaml` -- `dsn: '%env(MAILER_DSN)%'` et rien d'autre -- le
      garde-fou `envelope.recipients` en développement n'a pas lieu d'être tant que Mailpit
      intercepte tout.
- [x] `.env` (et `.env.test` si nécessaire) -- `MAILER_DSN` et
      `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`, chacun dans son bloc
      `###> … ###` avec le commentaire français qui dit pourquoi il existe et quel test le
      tient -- suivre la forme des quatre blocs existants ; `.env.test` n'a besoin d'aucune
      surcharge si `when@test` porte déjà l'`in-memory`.
- [x] `migrations/VersionYYYYMMDDHHMMSS.php` -- la table `messenger_messages`, générée puis
      **relue** (docblock français, `getDescription()`), avec un `down()` cohérent --
      `auto_setup` est désactivé ; sans migration, la CI et le premier déploiement tombent sur
      une table absente.
- [x] `src/Core/Message/SendEmail.php` -- `final readonly` : destinataire (email et nom),
      `SupportedLocale`, clé de sujet, nom de template, et un `array<string, scalar|null>` de
      contexte servant **à la fois** de paramètres du sujet et de variables du template -- un
      seul tableau : deux tableaux de scalaires côte à côte divergent au premier usage.
- [x] `src/Core/MessageHandler/SendEmailHandler.php` -- `#[AsMessageHandler]`, un
      `__invoke(SendEmail $m): void` qui délègue au service d'envoi, et rien d'autre -- la
      couche `Message` de deptrac ne voit ni Doctrine ni HTTP : le handler traduit, il ne
      décide pas.
- [x] `src/Core/Service/EmailSender.php` -- `final readonly` : traduit le sujet dans la locale
      du message, compose le `TemplatedEmail`, l'envoie par `MailerInterface`, et lève
      `UnrecoverableMessageHandlingException` sur un message structurellement invalide
      (template inconnu, destinataire vide) -- rejouer trois fois un template inexistant n'est
      pas du retry, c'est du bruit.
- [x] `config/services.yaml` -- ajouter `Message` à l'exclusion du glob `App\Core\` si le
      conteneur enregistre le message comme service -- vérifier par `debug:container` plutôt
      que supposer.
- [x] `templates/emails/base.html.twig` -- gabarit d'email minimal (styles en ligne, en-tête
      portant `app_name`, bloc `content`, pied traduit), **sans** rien du gabarit de page -- un
      email n'a ni skip-link, ni landmark, ni importmap ; le plancher d'accessibilité ne le
      visite pas et ne doit pas être détourné pour le faire.
- [x] `translations/emails.{fr,en,nl}.yaml` -- le nouveau domaine et les clés du gabarit,
      source française, clés anglaises pointées, non ICU -- `CatalogParityTest` exige les trois
      fichiers ; aucune clé de contenu d'email de production ici.
- [x] `config/packages/twig.yaml` -- un miroir `when@test` ajoutant `tests/Fixtures/templates`
      aux chemins Twig -- même forme que `translation.yaml:32-40` ; c'est ce qui permet au
      module de démonstration de porter son propre email sans qu'un template de test entre
      dans `templates/`.
- [x] `tests/Fixtures/Module/Demo/…` et `tests/Fixtures/templates/emails/demo.html.twig` -- un
      cas d'usage de démonstration qui persiste un `DemoWidget` **et** dispatche un `SendEmail`
      dans la même frontière de transaction, plus son template -- c'est le seul consommateur
      livré ; `DemoErrorController` est le précédent.
- [x] `tests/Core/Mail/SendEmailHandlerTest.php` -- exercer le handler et `EmailSender` :
      locale respectée dans les trois langues, sujet traduit, contexte présent dans le corps,
      message invalide → `UnrecoverableMessageHandlingException`, double exécution sans effet
      de bord inattendu -- `MailerAssertionsTrait::assertQueuedEmailCount()` ne s'applique pas :
      `SendEmailMessage` n'est pas routé, l'envoi du worker est direct.
- [x] `tests/Core/Mail/EmailQueueTest.php` -- couvrir chaque ligne de la matrice côté file :
      mise en file sur `in-memory` depuis le cas d'usage de démonstration, contenu de
      l'enveloppe, et — sur le **vrai** transport Doctrine — la ligne « visibilité avant
      commit » avec une connexion DBAL indépendante -- c'est le seul test qui prouve le critère
      central de la story ; sans lui, « après le commit » n'est qu'un commentaire.
- [x] `Makefile` -- une cible `worker`, **hors** des prérequis de `qa`, documentée dans l'aide
      (D-2) -- `QualityGateParityTest` ne réclame une catégorie que pour les prérequis de
      `qa` ; la porte reste à six.
- [x] `deploy/systemd/proglab-worker.service` -- l'unité du worker : `Restart=always`,
      `RestartSec=1`, `TimeoutStopSec=20`, `--time-limit` et `--memory-limit` sur la ligne de
      commande, avec un en-tête disant que la story 3.1 l'installe et la redémarre (D-3) --
      une unité sans limites de temps et de mémoire est un worker qui fuit ; un fichier livré
      deux epics avant son installateur doit dire qui l'installera.
- [x] `README.md` -- la section « worker » : `make worker` et Mailpit en développement (D-2),
      la commande de production et ses limites, `messenger:failed:show`, et le renvoi vers
      l'unité systemd (D-3) -- écrit là où un dérivé le lira, pas seulement dans ce spec.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` -- consigner ce qui est assumé :
      pas de files priorisées, pas d'alerte sur la file d'échec (Epic 6), retry pouvant
      produire un double envoi si le SMTP a accepté avant l'échec, l'unité systemd non exercée
      jusqu'à la story 3.1 (D-3), et **AD-21 à corriger** sur le nom du mécanisme (D-1) -- une
      limite non écrite est une limite que le client découvre.

**Acceptance Criteria :**
- Given un clone frais, when on lance `php bin/console debug:messenger`, then `SendEmail`
  apparaît routé vers `async` et `SendEmailMessage` n'apparaît routé nulle part.
- Given la porte de qualité, when on lance `make qa`, then les **six** catégories passent,
  sans nouvelle testsuite, sans worker lancé et sans SMTP contacté.
- Given le code livré, when on le relit, then aucun `MailerInterface::send()` n'est appelé en
  dehors de `EmailSender`, et aucun texte d'email destiné à un utilisateur réel n'a été écrit.
- Given la décision D-1, when on relit le service de démonstration, then la frontière de
  transaction y est explicite et le dispatch a lieu **à l'intérieur**, avec le commentaire qui
  dit pourquoi l'ordre inverse ne tiendrait pas.

## Implementation Notes

Implémenté le 2026-09-14. Les six catégories de `make qa` passent (sortie 0), suite
complète 330 tests / 2086 assertions. Les deux tests de la story ont été vus rouges avant
le code (« Class App\Core\Message\SendEmail not found »), puis vérifiés par mutation :
faire relire la connexion de l'application à `independentConnection()` fait rougir
« visibilité avant commit », et retirer la locale du `trans()` comme du contexte Twig fait
rougir les trois cas de langue.

Quatre écarts au spec, chacun consigné dans `deferred-work.md` avec sa raison :

1. **Le consommateur de démonstration n'est pas dans `tests/Fixtures/Module/Demo/`** mais
   dans `tests/Fixtures/Mail/DemoEmailDispatch.php`. Le contrat de couches n'accorde à un
   module que `Core\Contract` : un module qui référence `App\Core\Message\SendEmail` est
   la violation `ModuleDemo on Core` que `BoundaryTest` tient déjà. Le spec n'avait relevé
   que la couche technique `Message`, dont le ruleset est bien satisfait.
2. **Il ne persiste aucune entité.** `DemoWidget` n'a volontairement pas de table, et un
   DDL au vol détruirait l'isolation DAMA. La frontière de transaction reste explicite et
   le dispatch a lieu dedans ; la propriété est vérifiée directement sur le transport
   Doctrine.
3. **`mailer.yaml` porte une seconde clé, `headers.from`** (`MAILER_SENDER` dans `.env`) :
   sans expéditeur par défaut, `Symfony\Component\Mime\Message` refuse d'envoyer. Ce que
   le spec écartait — `envelope.recipients` — reste écarté.
4. **`.env.test` porte `MAILER_DSN=null://null`.** `framework.test: true` ne neutralise pas
   le transport du mailer ; sans cette ligne, la suite tenterait d'ouvrir le port 1025.

Le premier critère d'acceptation est vérifié par `debug:config framework messenger` et non
par `debug:messenger` : cette commande liste les handlers, pas le routage. Elle confirme
bien que `SendEmailMessage` est *handled* par le mailer, et la configuration fusionnée
confirme qu'il n'est routé nulle part.

Vérification manuelle faite : un `SendEmail` dispatché en développement, un worker lancé
contre un SMTP injoignable — trois tentatives puis `messenger:failed:show` montrant la
ligne et son exception, qui reste en place ; puis le même message livré à un serveur SMTP
local, reçu avec `From: no-reply@localhost`, `lang="nl"` et le pied de page néerlandais.

**Complément après audit de la matrice (2026-09-14).** Deux lignes n'étaient couvertes par
aucun test automatisé — « SMTP injoignable » ne l'était que par la vérification manuelle
ci-dessus, et « appel direct du mailer » par rien. Un troisième fichier les couvre :
`tests/Core/Mail/EmailFailurePolicyTest.php`.

- **SMTP injoignable** : le `Worker` de Messenger est piloté **en processus**, sur les
  transports `in-memory` du conteneur et sur le vrai répartiteur d'événements — donc sur
  les vrais écouteurs de retry et de file d'échec. Seul le bus est un double, et c'est lui
  le « SMTP injoignable ». Le worker s'arrête à vide et est relancé, une horloge simulée
  avançant entre deux passages : compter les **passages** — `[1, 1, 1, 1, 0]` — prouve que
  chaque reprise a réellement été différée, et pas seulement qu'il y en a eu trois. La
  durée des reports est vérifiée à part, sur la vraie `MultiplierRetryStrategy` du
  conteneur, en bandes disjointes à cause du `jitter: 0.3`. Vérifié par mutation :
  `max_retries` à 1 comme à 5, `multiplier` à 1, et `failure_transport` commenté font tous
  rougir.
- **Appel direct du mailer** : `assertQueuedEmailCount(0)` **ne** tient **pas** ce
  garde-fou, contrairement à ce que le spec supposait. Dès que Messenger est installé,
  `Mailer::send()` dispatche toujours un `MessageEvent` `queued = true` sur un clone
  (`vendor/symfony/mailer/Mailer.php:50-53`) avant de passer au bus : le compte « queued »
  vaut 1 dans les deux cas. C'est `assertEmailCount(1)` — l'événement non queued, qui
  n'existe que si le message a réellement été remis — qui distingue, doublé d'une lecture
  du routage compilé par `SendersLocator::getSenders()`. Les deux rougissent quand on
  route `SendEmailMessage`.

Deux conséquences sur le spec lui-même, notées ici plutôt que corrigées dans le texte :
le premier critère d'acceptation nommait `debug:messenger`, qui en 7.4 liste les handlers
et non le routage — c'est ce test-là qui le mécanise ; et la Code Map affirmait que rien
n'était à changer dans `deptrac.yaml`, ce qui est vrai de la couche technique `Message`
mais taisait la dimension **racine**, qui est ce qui a déplacé le consommateur de
démonstration (écart 1).

`symfony/clock` a été déclaré en `require-dev` : `ClockSensitiveTrait` est utilisé
directement par ce test, et une dépendance directe qui n'existe que par transitivité est
une dépendance que la prochaine mise à jour peut retirer sans prévenir.

`make qa` relancé en entier après ces ajouts : **sortie 0**, 337 tests / 2119 assertions
dans « Tests », 22 / 64 en « Accessibility », deptrac 0 violation, PHPStan sans erreur.

**Correctifs de la passe de revue (2026-09-14).** Dix groupes de trouvailles routés en
`patch`, deux différés, quatorze rejetés — le détail et les réfutations sont dans le
journal de triage ci-dessus. Ce que le code a gagné :

- `EmailSender` — l'union du contexte est inversée : les clés `locale` et `subject` de la
  classe gagnent, comme le docblock l'affirmait et comme le code faisait l'inverse. Et la
  clé de sujet est désormais vérifiée dans le catalogue avant traduction, symétriquement
  au gabarit : sans cette garde, une clé absente partait comme objet de l'email, sans
  exception ni trace. Détecter l'absence demande `TranslatorBagInterface`, pour lequel
  aucun alias n'existe — d'où l'unique `#[Autowire(service: 'translator')]`.
- **Le routage passe sur `#[AsMessage('async')]`**, comme le standard le prescrit pour un
  message applicatif. Le YAML garde l'explication de ce qui n'y est *pas* routé, qui reste
  la décision la plus importante du fichier.
- **Trois trous de vérification fermés** : l'enregistrement du handler (retirer
  `#[AsMessageHandler]` laissait la suite verte et cassait chaque envoi en production), le
  DSN du transport écrit en dur dans le test qui prouve le critère central, et le
  `queue_name` que rien n'exerçait — `InMemoryTransportFactory` ignore `$options`, donc la
  configuration compilée est le seul endroit où cette propriété existe sous test.
- **L'unité systemd** : `StartLimitIntervalSec` / `StartLimitBurst`, sans quoi
  `Restart=always` + `RestartSec=1` faisait passer l'unité en `failed` définitivement après
  cinq redémarrages en dix secondes ; le nom de l'unité de base devient une valeur à
  substituer ; `APP_ENV` et `APP_DEBUG` posés par `Environment=`.
- `<meta name="viewport">` et l'en-tête `Auto-Submitted: auto-generated`, que les trois
  pieds de page « ne pas répondre » réclamaient.
- Trois commentaires devenus faux corrigés, et `DemoEmailDispatch` déclaré nommément
  plutôt que par un glob `public: true` sans `exclude`.

Une correction apportée à une trouvaille de la revue : `independentConnection()` ne peut
pas simplement retirer `driverClass` et `wrapperClass` — ces clés n'existent pas, DAMA
s'insérant par un *middleware* DBAL. Les six valeurs sont désormais lues sur la connexion
de l'application, et `driver` est épinglé par une assertion qui rougit si le socle change
de moteur.

`make qa` relancé une troisième fois, après correctifs : **sortie 0**, 344 tests / 2150
assertions, 22 / 64 en accessibilité, deptrac 0 violation, PHPStan sans erreur,
php-cs-fixer 0 sur 73.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (14 + 2 mineures), edge-case-hunter (14, dont 4
« claims »), verification-gap (3 pré-vérifiées + 1 autre), proglab-conformance (7). Chaque
trouvaille non pré-vérifiée a été rouverte à l'emplacement cité — `vendor/`, tests,
`deptrac.yaml` — avant verdict. Les doublons entre couches sont fusionnés sur cause commune
et portent le même numéro de groupe (G1…G10).

| # | Couche(s) | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| G1 | blind, edge, verif-gap | `$message->context + [locale, subject]` : l'union PHP garde les clés de **gauche**, donc un contexte portant `locale` ou `subject` écrase la classe — l'inverse de ce que le docblock affirme | medium | Vérifié dans le code : les constantes sont documentées « réservées », et elles ne le sont pas. Un message `Nl` avec `context: ['locale' => 'en']` sort avec un sujet néerlandais (calculé sur `$message->locale`) et un corps anglais (le gabarit lit le contexte). Aucun test ne construit un contexte en collision | patch |
| G2 | blind, edge | Aucune garde sur `subjectKey`, alors qu'il y en a une sur le gabarit : `trans()` sur une clé absente rend la clé | medium | Vrai et asymétrique : `template()` lève `Unrecoverable`, `subject()` ne vérifie rien. L'email part avec `user.password_reset.subject` en objet — pas d'exception, pas de retry, rien dans `messenger:failed:show`. C'est le mode d'échec silencieux que la garde du gabarit existe pour fermer | patch |
| G3 | verif-gap | L'enregistrement du handler n'est exercé par aucun test : `#[AsMessageHandler]` peut disparaître sans rougir | medium | Pré-vérifiée, et recoupée : `SendEmailHandlerTest` instancie la classe à la main, `EmailQueueTest` route vers un sender sans traiter, `EmailFailurePolicyTest` remplace le bus par un double. En production chaque email lèverait `NoHandlerForMessageException`, épuiserait ses tentatives et finirait en file d'échec | patch |
| G4 | verif-gap, edge, blind | `EmailQueueTest` fabrique son transport sur un DSN écrit en dur, et sa seconde connexion sur `pdo_mysql`/3306 en dur | medium | Pré-vérifiée. Changer `MESSENGER_TRANSPORT_DSN` pour un second magasin supprime la garantie transactionnelle — le critère central de la story — sans rougir un test, puisque le test observe un transport que plus personne n'utilise. Les cinq autres paramètres de connexion, eux, sont bien lus sur l'application | patch |
| G5 | verif-gap, blind | `queue_name` et `auto_setup` ne sont exercés par rien : sous test les deux transports sont `in-memory` | medium | Vérifié dans `vendor/symfony/messenger/Transport/InMemory/InMemoryTransportFactory.php:37-41` — `createTransport()` ignore intégralement `$options`. Supprimer `queue_name: failed` enverrait les échecs sur la file que le worker consomme : reprise, échec, re-parking, en boucle, avec une suite verte et un `messenger:failed:show` d'apparence saine | patch |
| G6 | blind, edge, conformance | L'unité systemd : pas de `StartLimitIntervalSec`, `mysql.service` codé en dur, ni `APP_ENV` ni `APP_DEBUG` | medium | Vrai sur les trois points. systemd applique par défaut 5 démarrages / 10 s : avec `RestartSec=1`, une base injoignable au boot met l'unité en `failed` **définitivement**, l'inverse exact de ce que son commentaire promet. `Wants=` sur une unité absente (MariaDB, RHEL, base distante) disparaît en silence, ramenant la rafale d'erreurs que ces lignes existent pour éviter. L'asset de `symfony-proglab-deployment` liste les deux `Environment=` | patch |
| G7 | conformance | `SendEmail` est routé par le bloc `routing:`, que le standard réserve aux messages tiers ; un message applicatif porte `#[AsMessage('async')]` dès 7.2 | low | Vérifié : `vendor/symfony/messenger/Attribute/AsMessage.php` existe bien en 7.4, l'exception de version ne joue pas. Harm nommé et certain : les stories 1.9, 2.6 et 4.4 ajouteront chacune un message et copieront le premier exemple du dépôt. Le correctif est direct, et le commentaire sur `SendEmailMessage` reste dans le YAML où il doit être | patch |
| G8 | blind, edge | Trois commentaires devenus faux — `mailer.yaml` « une seule ligne », `services.yaml` « déclaré à la main » au-dessus d'un glob `public: true` sans `exclude`, `README.md:136` « le seul endroit du dépôt » | low | Vérifiés un par un. Dans un dépôt où chaque fichier de configuration porte sa raison d'être en commentaire, un commentaire faux est la forme de dette la plus chère : c'est la première chose que lit celui qui ouvre le fichier. Le glob rend en outre publique toute classe future du répertoire, là où les trois alias voisins sont nommés | patch |
| G9 | blind, conformance | Trois comportements documentés sans test : l'en-tête `From` issu de `MAILER_SENDER`, la conversion d'un contexte `null` en chaîne vide, l'acceptation d'un `toName` vide | low | Vrai : aucun test ne lit `getFrom()`, et tous passent `['widget' => …]` et `'Alice Demo'`. `MAILER_SENDER` est la seule ligne de configuration non prévue par le spec, ajoutée après un échec constaté — exactement ce qui mérite d'être épinglé. Règle 1 du socle | patch |
| G10 | blind | Pas de `<meta name="viewport">` dans le gabarit, et aucun en-tête anti-boucle alors que les trois pieds de page disent « ne pas répondre » | low | Vrai. Les clients mobiles dézooment un `max-width: 600px` sans viewport ; un répondeur d'absence renverra sur `MAILER_SENDER` faute d'`Auto-Submitted` (RFC 3834). Deux lignes, sans branche ni paramètre | patch |
| 11 | blind | Le gabarit ne fixe ni couleur de texte ni fond : le contraste est décidé par le client de messagerie, mode sombre compris | medium | Vrai, et la tension est réelle : `HardcodedColorTest` refuse toute couleur littérale dans `templates/`, et un email n'a accès ni aux jetons du thème ni à la feuille compilée. Trancher demande une décision de conception — paire nommée, ou exemption assumée pour `templates/emails/` — qui appartient au premier email réel | defer |
| 12 | edge | Rien ne purge la file d'échec : `messenger_messages` grossit sans borne dans la base métier | medium | Vrai : aucune rétention, aucun `failed:remove` planifié, et le transport `failed` est persistant par construction. Ne mord qu'au bout de mois d'échecs répétés, et la réponse (Scheduler, rétention) appartient à l'Epic 4 qui porte déjà l'archivage | defer |
| 13 | blind | `symfony/clock` est déclaré en `require-dev` alors que Composer le résout en `packages` (dépendance dure de `symfony/messenger`) | low | Le fait est exact, la conclusion non : `require-dev` déclare ce dont le **développement** a besoin, et `ClockSensitiveTrait` est utilisé directement par un test. Que `messenger` l'installe aussi en production ne rend pas la déclaration fausse ; `composer validate --strict` passe. Les deux alternatives (la mettre en `require`, ou ne rien déclarer) ont chacune la même objection en miroir | rejeté |
| 14 | blind | Le transport `failed` hérite en silence de la stratégie de retry par défaut | low | Exact, mais sans conséquence atteignable : rien ne consomme `failed` — ni le `Makefile`, ni l'unité systemd, ni la CI —, et `messenger:failed:retry` renvoie le message vers son transport d'origine. La stratégie du transport `failed` ne s'appliquerait qu'à un `messenger:consume failed` que personne ne lance | rejeté |
| 15 | blind | `tests/Fixtures/templates/` échappe à `lint:twig templates/` et à `HardcodedColorTest` | low | Exact, sans préjudice : ce gabarit est le **seul email réellement rendu de bout en bout** par trois tests, donc une erreur de syntaxe rougit immédiatement. Et le plancher achromatique porte sur la surface du dérivé, ce qu'une fixture n'est pas | rejeté |
| 16 | edge | Une erreur de rendu Twig (gabarit existant mais cassé) n'est pas convertie en `Unrecoverable` : trois tentatives inutiles | low | Vrai, et sans gravité : le message finit quand même en file d'échec avec son exception, seule la trace est trois fois plus longue. Le correctif ajoute un `try`/`catch` autour de l'envoi, c'est-à-dire une branche pour économiser du bruit | rejeté |
| 17 | edge | Une valeur de contexte non scalaire lève une `Error` non rattrapée dans le worker | low | Vrai en théorie ; PHPStan au niveau max couvre tous les appelants du dépôt, et la charge utile ne vient que de notre propre code. Le correctif ajoute une garde et une branche pour un cas qu'aucun chemin atteint | rejeté |
| 18 | edge | Un `MAILER_SENDER` vide ou malformé fait échouer **tous** les emails, trois fois chacun, jusqu'à la file d'échec | low | Vrai, et déjà consigné : l'implémentation a ouvert l'entrée « rien ne vérifie qu'un dérivé l'a surchargé » dans `deferred-work.md`, rapprochée de l'entrée `APP_SECRET` et de la même story de déploiement. Une validation au démarrage est une surface en plus pour un trou déjà nommé | rejeté, doublon |
| 19 | conformance | La classification du retry vit dans `EmailSender` et non dans le handler, ce qui importe le vocabulaire de Messenger dans un service | low | La règle est réelle, le préjudice nommé ne l'est pas : `UnrecoverableMessageHandlingException` est un marqueur, pas une dépendance au bus, et les tests exercent bien `EmailSender` sans bus ni enveloppe — le docblock reste vrai. Le correctif ajouterait une classe d'exception métier pour un échec qu'aucun appelant ne rattrape | rejeté |
| 20 | conformance | Aucune alerte quand un message atteint le transport d'échec | low | Exact, et explicitement hors périmètre : FR-18, Epic 6. Nommé dans le README, consigné dans `deferred-work.md` avec le mécanisme attendu (`WorkerMessageFailedEvent` et un sink qui ne repasse pas par Messenger) | rejeté, doublon |
| 21 | conformance | Pas de gabarit texte alors que la mise en page en tableaux atteint la condition du standard | low | Exact, et déjà consigné par l'implémentation avec le correctif nommé (`base.txt.twig` et `textTemplate()`), reporté au premier email réel | rejeté, doublon |
| 22 | conformance | Un test pilote un vrai `Worker` Messenger | low | La consigne vise un worker en sous-processus ; celui-ci est instancié en processus, sur horloge simulée, et c'est précisément ce qui rend la politique de retry observable sans dormir sept secondes. L'épuisement mémoire que la trouvaille cite est tiré du commentaire du test, qui raconte comment il a été **évité** | rejeté |
| 23 | edge | Claim : `mailer.yaml` porte une seconde clé là où le spec disait « `dsn` et rien d'autre » | false | Écart réel mais déjà divulgué avant la revue, avec sa cause (`An email must have a "From" or a "Sender" header`) : notes d'implémentation du spec et entrée dédiée dans `deferred-work.md`. Ce n'est pas une trouvaille, c'est la lecture d'une divulgation | rejeté |
| 24 | edge | Claim : le cas d'usage de démonstration ne persiste aucune entité, contrairement au spec | false | Même chose : écart 2 des notes d'implémentation, avec sa raison (`DemoWidget` n'a volontairement pas de table, un DDL au vol détruirait l'isolation DAMA) et ce qui le referme (la story 1.9, premier chemin à faire coexister un `flush()` et un dispatch) | rejeté |
| 25 | edge | Claim : le critère d'acceptation nomme `debug:messenger`, qui ne liste pas le routage | low | Exact, déjà divulgué, et le correctif consisterait à éditer le spec de ce build. Le critère est désormais **mécanisé** par le test de routage compilé, ce qui vaut mieux qu'une commande dans un texte | rejeté |
| 26 | blind | `sprint-status.yaml` reste à `in-progress` alors que l'en-tête prévoit `review` | false | `in-progress` est exact à cet instant : c'est l'étape 5 du workflow qui synchronise le suivi de sprint, pas l'étape 4. Même constat qu'à la story 1.7 | rejeté |

## Design Notes

**Pourquoi notre propre message plutôt que le routage natif de `SendEmailMessage`.** La façon
Symfony de rendre le mail asynchrone est de router `SendEmailMessage` vers un transport :
`MailerInterface::send()` devient alors une mise en file, et l'objet `Email` complet part dans
la charge utile. C'est exactement ce qu'AR-10 interdit — « un message porte des scalaires et
une locale explicite, jamais une dépendance au contexte de requête » — et cela déplace le
problème de la locale sans le résoudre : le corps serait rendu par le worker, mais le sujet et
les chaînes traduites l'auraient déjà été dans la locale de la requête. Un message à nous,
porteur d'un `SupportedLocale`, fait rendre **tout** dans le worker et dans la bonne langue.
Conséquence directe : `SendEmailMessage` ne doit surtout pas être routé, sans quoi l'envoi du
handler repartirait en file.

**Pourquoi un `EmailSender` séparé du handler.** Le handler est une couche de traduction,
comme un contrôleur : la couche `Message` de deptrac ne voit ni `Http`, ni `Doctrine`, ni
l'`EntityManager`. Composer un `TemplatedEmail` — traduire un sujet, choisir un template,
assembler un contexte — est une règle, et une règle vit dans un service. Le gain est concret
et testable : `EmailSender` s'exerce sans bus et sans enveloppe.

**Pourquoi le module de démonstration, et pas un chemin réel.** Le socle n'a aujourd'hui aucun
email à envoyer : le premier est celui de la story 1.9. Livrer une infrastructure que rien
n'exerce, c'est livrer une infrastructure non vérifiée. `tests/Fixtures/Module/Demo` existe
précisément pour cela — `DemoErrorController` y prouve les pages d'erreur de la story 1.10
sans ajouter de route au socle — et il est déjà câblé par les miroirs `when@test` de
`config/services.yaml` et de `config/packages/translation.yaml`.

**Comment « avant le commit » se prouve réellement.** DAMA ouvre une transaction par test et
l'annule à la fin : la transaction du test **est** la transaction ouverte du scénario. Sur le
vrai transport Doctrine, le test dispatche, lit la table depuis la connexion de l'application
(la ligne est là), puis depuis une connexion DBAL indépendante créée à la main (la ligne n'y
est pas). C'est la propriété elle-même qui est vérifiée, pas l'ordre des appels dans le code.

**Ce que le retry ne garantit pas.** Un envoi SMTP accepté par le serveur puis interrompu
avant l'acquittement du message sera **rejoué** : le destinataire reçoit deux fois le même
email. Aucune idempotence n'est atteignable ici — l'effet de bord est chez le serveur SMTP,
pas dans notre base. C'est un choix assumé, pas un oubli, et il part dans `deferred-work.md`.

## Verification

**Commands :**
- `composer require symfony/mailer:7.4.* symfony/messenger:7.4.* symfony/doctrine-messenger:7.4.*`
  -- installés, `config/bundles.php` inchangé
- `php bin/console debug:messenger` -- `SendEmail` routé vers `async`, rien d'autre routé
- `php bin/console doctrine:migrations:migrate --no-interaction` -- la table
  `messenger_messages` est créée par la migration, pas par `auto_setup`
- `php bin/console lint:yaml config/ translations/` et `php bin/console lint:twig templates/`
  -- valides
- `vendor/bin/phpunit --filter 'SendEmailHandler|EmailQueue'` -- vus **rouges** d'abord, puis
  verts
- `vendor/bin/phpunit` -- la suite entière au vert
- `make qa` -- les six catégories passent

**Manual checks :**
- Mailpit ouvert et `make worker` lancé (D-2), un envoi déclenché : l'email arrive, dans la
  langue portée par le message et non celle du navigateur ; worker arrêté, il reste en file
  et repart au redémarrage
- `php bin/console messenger:failed:show` après un SMTP volontairement injoignable : la ligne
  est là, avec son exception, et elle n'a pas disparu
