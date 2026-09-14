---
title: 'Ralentir les tentatives de connexion répétées'
type: 'feature'
created: '2026-09-14'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '8d5117a430ca405d7a4c664e6d4a991c9b4f1157'
context:
  - '{project-root}/.claude/skills/symfony-proglab-security/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-6-se-connecter-avec-son-email-et-son-mot-de-passe.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** la story 1.6 a livré `/login` sans aucune limitation de débit —
`config/packages/security.yaml` l'écrit noir sur blanc (l. 16-17), et `symfony/rate-limiter`
n'est même pas installé. Un mot de passe se tente aujourd'hui aussi vite que le serveur
répond, et rien dans le socle ne le remarque.

**Approach :** activer le `login_throttling` natif du SecurityBundle sur le pare-feu
`main`, et rendre lisible ce que son exception native cache : `LoginFailureListener` relit
le limiteur par `peek()`, en tire les **secondes** restantes, et compose le message du
tableau de ton — « Email ou mot de passe incorrect. Patientez 40 secondes avant de
réessayer. » Aucun compteur, aucune table, aucun état de blocage écrit à la main.

## Boundaries & Constraints

**Always :**
- Le mécanisme est **`login_throttling` natif** adossé à `symfony/rate-limiter`. Toute
  configuration passe par `security.yaml` et, si des limiteurs nommés sont retenus, par
  `framework.rate_limiter` — jamais par une classe qui compte les échecs.
- Les secondes restantes sont **lues sur le limiteur** (`RateLimit::getRetryAfter()`),
  jamais reconstituées depuis le message de l'exception, qui n'expose que des minutes
  arrondies au supérieur (AD-18).
- **Aucun blocage définitif**, et rien ne s'affiche comme tel : le message dit toujours un
  délai fini et le bouton reste actif.
- Le formulaire garde son jeton CSRF (AD-18) : cette story ne touche pas à la protection
  déjà posée, elle en fait constater la présence par un test que le critère nomme.
- Le refus reste **indifférencié** : le ralentissement est indexé sur l'identifiant
  *soumis*, existant ou non, donc il ne dit pas si le compte existe.
- Tout fonctionne **sans JavaScript** (AD-17) : aucun compte à rebours côté client, le
  délai restant est recalculé à chaque soumission.

**Never :**
- Pas de champ, de colonne ni d'entité « compte bloqué », « tentatives » ou « verrouillé
  jusqu'à » : rien ne persiste en base.
- Pas de `symfony/lock` (voir Design Notes pour la course acceptée et documentée).
- Pas de bouton désactivé, pas de CAPTCHA, pas de bannissement d'IP.
- Pas de limiteur pour la 2FA (Epic 5), la réinitialisation (story 1.9) ni l'invitation
  (Epic 2) : AD-18 leur donne chacun le sien, dans leur propre story.
- Aucune écriture d'audit (Epic 4).

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Comportement attendu | Gestion d'erreur |
|---|---|---|---|
| Sous le seuil | 1ᵉʳ à 4ᵉ échec | Exactement la story 1.6 : **422**, « Email ou mot de passe incorrect. », aucune mention de délai | Inchangé |
| Seuil atteint | 5 échecs consécutifs, même identifiant et même IP, puis une tentative de plus | Refus **avant** toute vérification du mot de passe ; page réaffichée avec le message générique **suivi de** « Patientez N secondes avant de réessayer. », N lu sur le limiteur | Statut **429** |
| Bons identifiants pendant le ralentissement | Le délai court encore | **Même refus** : le ralentissement précède la vérification | Aucune session ouverte |
| Après le délai | Le délai est écoulé, identifiants justes | 302 vers `/`, connexion normale | Aucun résidu : rien à débloquer à la main |
| Une seconde restante | N = 1 | « Patientez 1 seconde avant de réessayer. » — le singulier vient du pluriel ICU, jamais d'un `if` dans un template | N/A |
| Succès avant le seuil | 3 échecs puis un succès | La connexion réussit ; les 3 échecs **restent comptés** jusqu'à l'expiration de la fenêtre | Documenté, voir Design Notes |
| Autre IP, même identifiant | Le seuil est atteint depuis une première IP | La seconde IP n'hérite pas du refus local ; seul le limiteur global par IP la borne | N/A |
| Jeton CSRF absent pendant le ralentissement | POST sans jeton valide | Le ralentissement l'emporte : même refus, même message | Aucune fuite |
| Journal applicatif | Un refus ralenti | Rien de sensible n'est écrit, et une rafale ne vide pas le buffer `fingers_crossed` | Voir la tâche `monolog.yaml` |

## Décisions de Fabrice (2026-09-14)

- **Le délai croît par paliers natifs, en configuration pure.** Les sources se
  contredisaient : `SPEC.md:159` posait en *hypothèse* « délai doublé à partir du cinquième
  échec, plafonné à quinze minutes », là où le quatrième critère de la story et AD-18
  imposent le `login_throttling` natif, « jamais un compteur écrit à la main » — et aucune
  politique native ne double. **C'est la seconde contradiction amont tranchée dans le
  code** (après la langue du compte à la story 1.6), et elle l'est du côté de l'AC : deux
  limiteurs nommés, **5 échecs par minute puis 20 par quart d'heure** sur le même couple
  identifiant+IP, composés par le `DefaultLoginRateLimiter` natif. Le refus dure environ
  une minute au sixième échec, puis jusqu'à quinze minutes si l'acharnement se prolonge :
  croissant par paliers, plafonné à quinze minutes comme l'hypothèse du SPEC le demandait,
  jamais définitif, et pas une ligne de compteur. L'hypothèse du doublement exact est
  abandonnée, explicitement.
  À vérifier à l'installation : que la politique `compound` de `framework.rate_limiter`
  produise bien une fabrique acceptée par `DefaultLoginRateLimiter` — le composant n'était
  pas installé au moment de la planification, donc ce point n'a pas pu être lu dans
  `vendor/`. **Si elle ne la produit pas, le repli est le palier long porté par le limiteur
  global, sans classe maison** ; le troisième chemin — un `RequestRateLimiterInterface`
  écrit à la main — reste fermé dans tous les cas.
- **Le spec est gardé entier** malgré ses ~6 000 tokens. Ses six couches — dépendance,
  configuration, écouteur, message, traductions ICU, tests — ne livrent rien de visible
  séparément : couper entre « activer le ralentissement » et « afficher les secondes
  restantes » mettrait de côté exactement le critère que la story existe pour tenir. Même
  arbitrage qu'aux stories 1.5 et 1.6.

</frozen-after-approval>

## Code Map

- `config/packages/security.yaml` — les l. 16-17 disent « pas de `login_throttling` : c'est
  la story 1.7 » : **c'est ce commentaire qu'il faut remplacer**, pas contourner. La clé
  s'ajoute sous `firewalls.main`, à côté de `form_login`. `when@test` y abaisse déjà le
  coût du hachage — précédent pour tout réglage propre aux tests.
- `composer.json` — `symfony/rate-limiter` **absent**, `symfony/lock` absent,
  `symfony/cache` présent en transitif (le pool `cache.rate_limiter` existe donc).
  `symfony/rate-limiter:7.4.*` est la seule dépendance à ajouter. La CI n'installe aucune
  extension supplémentaire : `.github/workflows/ci.yml` ne bouge pas.
- `vendor/symfony/security-bundle/DependencyInjection/Security/Factory/LoginThrottlingFactory.php:51-62`
  — options réelles : `limiter`, `max_attempts` (5), `interval` (`'1 minute'`),
  `lock_factory` (`'auto'`), `cache_pool` (`cache.rate_limiter`), `storage_service`.
  `:71-95` — **sans** `limiter:`, le bundle fabrique `security.login_throttling.main.limiter`
  (un `DefaultLoginRateLimiter`, politique `fixed_window`, limite globale = 5 × `max_attempts`) ;
  **avec** `limiter:`, cet id n'existe pas et c'est le nôtre qu'il faut injecter.
  `:84-89` — `'auto'` ne pose un verrou que si `symfony/lock` est là, donc son absence ne
  lève rien.
- `vendor/symfony/security-http/EventListener/LoginThrottlingListener.php:36-60` — le refus
  est levé sur `CheckPassportEvent` à la priorité **2080**, donc **avant** `UserChecker` et
  avant la vérification du mot de passe. `:69-74` — la consommation réelle a lieu sur
  `LoginFailureEvent` **à la priorité 0** : notre écouteur doit donc passer **après**, sinon
  il relit un compteur d'un cran en retard. `:46-53` — le refus tombe dès
  `0 === getRemainingTokens()`, c'est-à-dire à la tentative qui suit le cinquième échec.
- `vendor/symfony/http-foundation/RateLimiter/AbstractRequestRateLimiter.php:27-52` —
  `peek()` = `doConsume(…, 0)` : **c'est la lecture sans consommation** que demande le
  critère. Elle rend le plus restrictif des deux limiteurs.
  `Symfony\Component\RateLimiter\RateLimit` porte `getRetryAfter(): \DateTimeImmutable`,
  `isAccepted()` et `getRemainingTokens()`.
- `vendor/symfony/security-core/Exception/TooManyLoginAttemptsAuthenticationException.php:22-38`
  — son `$threshold` est en **minutes** (`ceil`), donc « 3 secondes restantes » y devient
  « 1 minute ». C'est exactement la raison d'être d'AD-18 : ne jamais déduire le délai du
  message.
- `src/Core/EventListener/LoginFailureListener.php` — le 422 et le rendu de la page. Il
  relaie déjà `AuthenticationServiceException` (une panne n'est pas un mauvais mot de
  passe) ; `TooManyLoginAttemptsAuthenticationException` n'en est **pas** une et ne doit pas
  être relayée. `#[AsEventListener]` n'y porte **aucune priorité** aujourd'hui — à fixer.
  `src/Core/EventListener/` n'a **aucune couche technique** dans `deptrac.yaml` (l. 40-45) :
  c'est la seule porte par laquelle une `Request`, un limiteur et Twig peuvent se croiser.
- `src/Core/Security/LoginFailureMessage.php` — le seul traducteur d'exception en clé de
  message, appelé par **deux** chemins (contrôleur et écouteur). Aujourd'hui il rend une
  `?string`. Le ruleset `Security:` de `deptrac.yaml` n'autorise que
  `Security, Entity, Dto, Enum` : **rien de HTTP ni du composant RateLimiter ne peut entrer
  dans cette classe** — elle ne reçoit qu'un nombre de secondes déjà calculé.
- `templates/security/login.html.twig:78-84` — le bloc `twig:Alert` rend
  `{{ error_message|trans }}`. Le délai s'ajoute **dans ce bloc**, comme une seconde phrase,
  et jamais par une chaîne concaténée en PHP.
- `translations/messages.{fr,en,nl}.yaml` — domaine simple **non ICU**, clés anglaises
  pointées, source française ; l'en-tête de `messages.fr.yaml` prescrit déjà que « la
  première story qui a besoin d'une variable ou d'un pluriel ouvre son **propre** domaine
  suffixé `+intl-icu` ». C'est cette story.
  `tests/Core/Translation/CatalogParityTest.php:289` découvre un catalogue par
  `^(domaine).(locale).(ext)$` : un fichier `security+intl-icu.fr.yaml` est vu comme le
  domaine `security+intl-icu` et exige ses trois langues — **aucune modification du test**.
- `config/packages/monolog.yaml` — `excluded_http_codes: [403, 404, 405]`, écrit **mot pour
  mot** sous `when@test` et `when@prod` ;
  `tests/Core/Observability/ErrorLoggingTest.php` tient les deux listes face à face. Une
  entrée de `deferred-work.md` (story 1.10) attend le 429 ici.
- `config/services.yaml` (`when@test`, l. 55-67) — le précédent d'alias public réservé aux
  tests, avec sa consigne de retrait. Le pool `cache.rate_limiter` en aura besoin : sans
  remise à zéro entre deux tests, l'état du limiteur fuit d'une méthode à l'autre et la
  suite devient dépendante de son ordre.
- `tests/Core/Security/LoginTest.php` (368 l.) et `Accounts.php` — les conventions à
  reprendre : `#[Test]`, méthodes snake_case anglaises, `self::createClient()`, assertions
  statiques avec message explicatif, comptes créés par `Accounts::create()`.
- `phpunit.dist.xml`, `Makefile`, `tests/Core/Quality/QualityGateParityTest.php` — aucune
  nouvelle testsuite, aucune nouvelle catégorie de porte : **ils ne bougent pas**.
- `tests/Core/Accessibility/AccessibilityFloorTest.php` — découvre les routes `App\` en GET
  sans paramètre. Cette story n'ajoute **aucune route** : rien à y déclarer.

## Tasks & Acceptance

**Execution :**
- [x] `composer.json` -- ajouter `symfony/rate-limiter` en `7.4.*` -- le `login_throttling`
      natif lève `LogicException` sans lui ; c'est la seule dépendance de la story.
- [x] `config/packages/framework.yaml` (ou un fichier dédié) -- déclarer les limiteurs
      nommés des deux paliers, 5 par minute et 20 par quart d'heure, et le limiteur global
      par IP -- c'est là que vit le « croissant » ; vérifier d'abord que `compound` rend
      une fabrique utilisable, et se replier sinon comme le disent les Décisions.
- [x] `config/packages/security.yaml` -- brancher `login_throttling.limiter` sur le
      `DefaultLoginRateLimiter` composé des limiteurs ci-dessus, et **remplacer** le
      commentaire des l. 16-17 par ce qui a été décidé et pourquoi -- un fichier de
      sécurité se relit ; un réglage sans sa raison est un réglage que la story suivante
      défera.
- [x] `src/Core/Security/LoginFailureMessage.php` -- reconnaître
      `TooManyLoginAttemptsAuthenticationException` et rendre, au lieu d'une `?string`, un
      objet portant la clé du message **et** les secondes restantes (`null` hors
      ralentissement) -- la classe ne voit ni HTTP ni le limiteur : elle reçoit un nombre
      déjà calculé et reste testable sans conteneur.
- [x] `src/Core/EventListener/LoginFailureListener.php` -- injecter le limiteur du
      pare-feu, appeler `peek()` sur la requête de l'événement quand l'exception est celle
      du ralentissement, en dériver les secondes restantes, rendre la page en **429** dans
      ce cas et en 422 sinon, et poser une **priorité négative** sur `#[AsEventListener]`
      -- la consommation native a lieu sur le même événement à la priorité 0 ; sans
      priorité explicite, l'ordre dépend de celui de l'enregistrement et le délai affiché
      est celui d'avant la tentative en cours.
- [x] `templates/security/login.html.twig` -- rendre la seconde phrase dans le même bloc
      `role="alert"`, sous la première -- un seul bloc d'alerte, focalisé par `page-focus` ;
      jamais deux régions, jamais une chaîne assemblée en PHP.
- [x] `translations/security+intl-icu.{fr,en,nl}.yaml` -- le nouveau domaine ICU et sa
      seule clé, avec son pluriel, en français au mot près d'`EXPERIENCE.md:191` --
      l'en-tête de `messages.fr.yaml` le prescrit ; `CatalogParityTest` exige les trois
      fichiers.
- [x] `config/packages/monolog.yaml` -- ajouter `429` aux deux listes `excluded_http_codes`
      **si et seulement si** un test montre qu'un refus ralenti vide le buffer, et mettre à
      jour l'entrée correspondante de `deferred-work.md` dans les deux cas -- une règle qui
      ne défend rien n'est vérifiée par rien ; c'est le test qui tranche, pas la supposition
      de la story 1.10.
- [x] `config/services.yaml` -- alias public sous `when@test` sur le pool
      `cache.rate_limiter`, avec sa consigne de retrait -- un test qui n'efface pas l'état
      du limiteur entre deux méthodes rend la suite dépendante de son ordre.
- [x] `tests/Core/Security/LoginThrottlingTest.php` -- couvrir **chaque ligne** de la
      matrice, dont « bons identifiants pendant le ralentissement », « après le délai » et
      le singulier à une seconde ; remettre le limiteur à zéro en `setUp()` ; lire les
      secondes attendues sur le limiteur plutôt que sur le texte rendu -- un test qui relit
      le message pour vérifier le message ne vérifie rien.
- [x] `tests/Core/Security/LoginTest.php` -- vérifier que les quatre premiers échecs sont
      **inchangés** (422, message sans délai), et que le jeton CSRF est toujours exigé -- le
      troisième critère de la story porte sur un mécanisme déjà livré : il demande une
      preuve, pas un changement.

**Acceptance Criteria :**
- Given un clone frais, when on lance `php bin/console debug:firewall main`, then le
  ralentissement figure parmi les écouteurs du pare-feu et son limiteur est nommé.
- Given la porte de qualité, when on lance `make qa`, then les six catégories passent, sans
  nouvelle testsuite ni nouvelle catégorie.
- Given le code livré, when on le relit, then aucun compteur, aucune colonne et aucun état
  de blocage n'a été écrit à la main.

## Implementation Notes

Trois points de la spec se sont révélés faux à la lecture de `vendor/`, et ils sont
tranchés ici plutôt qu'enterrés.

**1. La politique `compound` ne convient pas — le repli prévu a été pris.** Vérifié dans
`FrameworkExtension::registerRateLimiterConfiguration()` : un limiteur `compound` est un
`CompoundRateLimiterFactory`, qui implémente `RateLimiterFactoryInterface` mais **n'est
pas** un `RateLimiterFactory` ; or `DefaultLoginRateLimiter::__construct()` type ses deux
arguments sur la classe concrète. Empiler les deux paliers sur la moitié « locale » est
donc impossible. Le repli nommé par les Décisions a été appliqué : **le palier court (5 /
minute) sur la moitié locale — identifiant + IP — et le palier long (20 / quart d'heure)
sur la moitié globale — IP seule.** Les deux limiteurs sont nommés
(`config/packages/rate_limiter.yaml`), composés par le `DefaultLoginRateLimiter` natif
(`config/services.yaml`), et aucune classe maison n'existe. La promesse tenue est la
même : croissant par paliers, plafonné à un quart d'heure, jamais définitif.

**2. La priorité négative ne tient pas ce que la Design Note lui attribue — mais elle en
tient une autre.** Elle est bien posée (`priority: -64`, vérifiée par
`debug:firewall --events main`), et inverser la priorité ne fait bouger **aucun test** sur
le palier court : une tentative déjà refusée n'y est jamais comptée —
`FixedWindowLimiter::reserve()` lève sa `MaxWaitDurationExceededException` **avant**
`Window::add()`, et `consume()` la rattrape. En revanche
`AbstractRequestRateLimiter::doConsume()` sollicite **les deux** limiteurs : celui qui n'est
pas encore épuisé, lui, compte. Chaque refus draine donc le palier long, et c'est ce qui
fait l'escalade — quelques secondes d'abord, jusqu'à un quart d'heure ensuite. La propriété
« s'acharner ne repousse pas l'échéance » est donc vraie **dans la portée du palier court**
seulement, et `LoginThrottlingTest::the_announced_delay_shrinks_from_one_submission_to_the_next()`
le dit maintenant en toutes lettres ;
`one_address_sweeping_many_identifiers_is_stopped_by_the_long_tier()` couvre l'autre moitié.

**2 bis. Une seconde priorité, haute celle-là.** `rethrowServiceFailure()` est enregistrée à
**256**, donc avant la consommation native. Relayer une `AuthenticationServiceException`
depuis un écouteur de priorité négative aurait laissé le limiteur compter une tentative à
chaque essai pendant une panne de base : l'utilisateur se serait retrouvé ralenti une fois
le service rétabli, pour des échecs qui n'étaient pas les siens. L'exception interrompt la
propagation avant `LoginThrottlingListener::onFailedLogin()`, donc une panne ne coûte rien.

**3. Le `429` ne rejoint pas `excluded_http_codes`, et `monolog.yaml` n'a pas bougé.** La
condition de la tâche était « si et seulement si un test montre qu'un refus ralenti vide le
buffer ». Le test montre l'inverse : le 429 n'est pas une exception mais une **réponse
posée** sur `LoginFailureEvent`, donc aucun listener d'exception ne s'exécute et aucune
ligne `error` n'est écrite. Test vérifié par mutation (`action_level: debug` sous
`when@test` → rouge). L'entrée correspondante de `deferred-work.md` est close par une
nouvelle entrée, comme l'exige l'append-only.

**Décision non prévue par la spec : le sel du limiteur.** `container.build_hash` — ce que
le bundle passe lui-même — n'existe que dans le dumper (`PhpDumper.php:374`) et pas dans le
sac de paramètres : `%container.build_hash%` en YAML échoue à la compilation. Le sel est
donc `%env(default:app.login_throttling.fallback_secret:APP_SECRET)%`, avec repli parce
qu'`APP_SECRET` est vide dans `.env` et n'a aucun chemin de production — sans repli, cette
story ferait répondre 500 à chaque connexion en production. Détail et conditions de
réouverture dans `deferred-work.md`.

**Ce que les tests ont coûté au reste de la suite.** L'état du limiteur vit dans un pool de
cache **fichier**, hors de portée de DAMA : il survit à la méthode, à la classe et à
l'exécution, et la fenêtre du palier long dure un quart d'heure. Les **trois** classes qui
soumettent le formulaire de connexion — `LoginTest`, `LoginThrottlingTest`,
`LoginLocaleTest` — appellent donc `LoginThrottling::forget()` dans leur `setUp()` ; le
rituel entier (noyau ouvert, pool vidé, noyau refermé) vit dans le helper, appelé une fois
par classe. Deux alias publics sous `when@test` (`app.test.rate_limiter_pool`,
`app.test.login_rate_limiter`) rendent cela possible, chacun avec sa consigne de retrait.

**Où vit le plancher à une seconde.** Dans `LoginFailureMessage`, pas dans l'écouteur. Dans
l'écouteur, c'était une expression qu'aucun test ne pouvait distinguer de sa propre copie
— le helper de test recalculait la même —, donc la supprimer ne rougissait rien. C'est une
règle : elle vit avec les règles, et `LoginFailureMessageTest` (un `TestCase` sans
conteneur) l'épingle à 0 et à −3, en même temps que les quatre autres entrées de `of()` —
dont le ralentissement **sans** secondes, la branche qu'emprunte `SecurityController` et
qu'aucun test fonctionnel ne peut atteindre.

**`Retry-After` sur la 429.** Le délai est calculé de toute façon ; la RFC 9110 lui donne
son en-tête, et il porte la même valeur que la phrase affichée — deux nombres qui
divergeraient, c'est un client automatique qui réessaie au mauvais moment.

**Écart de vérification.** Le critère écrit `php bin/console debug:firewall main` ; cette
commande ne liste les écouteurs qu'avec `--events` : c'est
`php bin/console debug:firewall --events main` qui montre le ralentissement, et
`php bin/console debug:container DefaultLoginRateLimiter` qui montre ses deux limiteurs
nommés.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (15), edge-case-hunter (10, dont 2 « claims »),
verification-gap (4 pré-vérifiées + 2 autres), proglab-conformance (3). Chaque trouvaille
non pré-vérifiée a été rouverte à l'emplacement cité — `vendor/`, tests, `.env*` — avant
verdict. Les doublons entre couches sont fusionnés sur cause commune.

| # | Couche(s) | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| 1 | blind, verif-gap, conformance | Le palier long (`login_address`, 20 par quart d'heure) n'est atteint par aucun test | medium | Démontré : porter `limit` à 2000, ou échanger `$globalFactory` et `$localFactory`, laisse toute la suite verte. `spendEveryAttempt()` s'arrête à cinq soumissions, un seul identifiant, une seule IP | patch |
| 2 | blind, verif-gap | Le plancher `max(1, …)` n'est épinglé par rien : le helper de test recalcule la même expression | medium | `LoginThrottling::secondsLeft()` reproduit `max(1, retryAfter - time())`, et `assertEqualsWithDelta(…, 1)` compare donc la formule à sa copie. Supprimer le plancher côté production ne rougit rien | patch |
| 3 | blind, verif-gap | La branche de repli du sel n'est exécutée par aucun test, or c'est le seul chemin de production | medium | Vérifié : `.env:19` porte `APP_SECRET=` vide, `.env.test:3` une valeur non vide — le processeur `default:` ne se replie donc jamais sous test. `lint:container --env=prod` ne résout pas les variables d'environnement sans `--resolve-env-vars` | patch |
| 4 | verif-gap, blind | `LoginLocaleTest` soumet le formulaire de connexion sans le `forget()` que ce changement érige en invariant ; le `setUp()` est recopié au lieu d'être porté par le helper | medium | Vérifié : la classe n'a aucun `setUp()`. Latent aujourd'hui — ses connexions réussissent et ne consomment rien — mais l'invariant écrit dans `LoginThrottling.php` n'est tenu par rien | patch |
| 5 | edge-case | « S'acharner ne repousse pas l'échéance » est faux au-delà du palier court | medium | Vérifié dans `AbstractRequestRateLimiter::doConsume()` : **les deux** limiteurs consomment ; seul celui qui est épuisé n'ajoute rien, `FixedWindowLimiter::consume()` rattrapant l'exception avant `Window::add()`. Chaque refus draine donc le palier long | patch |
| 6 | blind, edge-case | La réponse 429 ne porte pas d'en-tête `Retry-After` | low | Vrai : `new Response($html, 429)` sans en-tête, alors que les secondes viennent d'être calculées. RFC 9110 §15.5.30 l'attend, et `TooManyRequestsHttpException` de Symfony le pose | patch |
| 7 | edge-case | Une `AuthenticationServiceException` est relayée à la priorité −64, donc **après** la consommation native à 0 : une panne de base brûle des tentatives | medium | Vérifié : `LoginThrottlingListener::onFailedLogin()` est à la priorité 0. Une panne d'infrastructure peut donc ralentir l'utilisateur une fois le service rétabli. Relayer plus tôt interrompt la propagation avant la consommation | patch |
| 8 | blind | Rien ne vérifie que la page 429 reste une page de connexion utilisable — jeton CSRF présent, adresse conservée | low | Vrai : le chemin 429 n'hérite d'aucune des deux assertions que `LoginTest` porte sur le 422. Risque faible, les deux chemins partageant le template ; assertion triviale | patch |
| 9 | blind | `LoginThrottling::rewind()` fait `continue` en silence quand l'état stocké n'est pas une `Window` | low | Vrai, et c'est la seule garde muette d'une classe qui lève une `RuntimeException` précise partout ailleurs. `Window` est marquée `@internal` chez Symfony | patch |
| 10 | blind, verif-gap | `assertStringNotContainsString('{', …)` n'est appliqué qu'au singulier | low | Vrai : la branche `other` est vérifiée pour son contenu, jamais pour avoir été formatée | patch |
| 11 | edge-case, verif-gap | Les marges `assertEqualsWithDelta(…, 1)` couvrent une durée d'horloge réelle — deux allers-retours HTTP et une écriture par réflexion | low | Vrai : sur un runner froid le test peut rougir pour une raison de temps et non de comportement | patch |
| 12 | conformance | `LoginFailureMessage` n'a aucun test unitaire ; la branche `TooManyLoginAttempts` **avec `null` seconde** — celle du contrôleur — n'est exercée nulle part | low | Vrai : la classe porte une règle, quelle exception donne quelle clé, et n'est prouvée qu'à travers `WebTestCase` | patch |
| 13 | conformance | `#[AsEventListener(event: …)]` : l'argument est redondant | low | Vrai, vérifié dans `symfony-proglab-architecture/SKILL.md` : « la déduction à partir du type déclaré ne demande aucun argument `event:` ». La ligne est réécrite par ce diff, donc l'occasion est ouverte | patch |
| 14 | blind, edge-case | `trusted_proxies` absent : derrière un reverse proxy, `getClientIp()` rend l'adresse du proxy et le palier long dégénère en compteur unique pour tout le monde | medium, non vérifié | Le dépôt ne configure aucun proxy et `public/` ne porte pas de `.htaccess` : rien ne le déclenche ici. Ce qui trancherait : la cible de déploiement réelle, qui appartient à la story 3.1 | defer |
| 15 | blind | Un refus ralenti ne laisse **aucune** ligne de journal, et `assertSame([], …)` interdit d'en ajouter une | medium | Vrai : le 429 est une réponse posée et non une exception, donc rien n'est journalisé. Distinct de l'audit (Epic 4) — cela appartient à l'observabilité | defer |
| 16 | edge-case | Le sel de repli `%kernel.project_dir%` change à chaque release Deployer | low | Vrai : `releases/<horodatage>` change le sel, donc un déploiement remet les compteurs à zéro. Marginal, et adossé à l'entrée `APP_SECRET` déjà ouverte | defer |
| 17 | blind, edge-case | « Patientez 873 secondes » quand c'est le palier long qui refuse | low | Vrai, mais y arriver seul demande vingt échecs depuis une IP en quinze minutes — un acharnement délibéré, pas une faute de frappe. Le correctif, une branche « minutes » en ICU dans trois langues, est une décision de ton et non une correction directe | rejeté |
| 18 | blind | La ligne « Sous le seuil : 1ᵉʳ à 4ᵉ échec » de la matrice omet que le **cinquième** échec est aussi un 422 sans délai | low | Factuellement exact : le refus commence au sixième. Le correctif édite le bloc gelé du spec | rejeté |
| 19 | blind | La matrice n'a pas de ligne « même IP, plusieurs identifiants » — NAT, bureau partagé | low | Exact, et c'est le miroir de la ligne « autre IP ». Le correctif édite le bloc gelé du spec ; le fond est couvert par la ligne 14 | rejeté |
| 20 | blind | Le limiteur du pare-feu et celui de l'écouteur sont câblés deux fois sans que rien n'assure que c'est le même objet | low | Les deux désignent le **même id de service**, le FQCN défini dans `config/services.yaml` : ils coïncident par construction. La divergence exigerait qu'on change `login_throttling.limiter`, ce que rien ne fait | rejeté |
| 21 | blind | `sprint-status.yaml` (`in-progress`) et le spec (`in-review`) divergent | false | `in-progress` est exact à cet instant : c'est l'étape 5 du workflow qui synchronise le suivi de sprint, pas l'étape 4 | rejeté |
| 22 | blind | `renderedDelay()` fige la phrase française, et sa capture de chiffres ne matcherait pas un nombre supérieur à 999 formaté par ICU | low | La seconde moitié est inatteignable tant qu'aucun intervalle ne dépasse 999 secondes ; la première est un couplage de test dont le correctif ajoute de la machinerie | rejeté |
| 23 | edge-case | La tâche annonçait que `LoginTest` vérifie les quatre premiers échecs ; c'est `LoginThrottlingTest` qui le fait | low | La couverture existe et a tourné, seulement pas dans le fichier que la tâche nommait. Aucun préjudice pour personne | rejeté |
| 24 | edge-case | Absence de verrou : dépassement du seuil sous requêtes concurrentes | low | Déjà consigné dans `deferred-work.md` par l'implémentation, avec ce qui le rouvrirait | rejeté, doublon |


## Design Notes

**Pourquoi la lecture se fait dans l'écouteur et non dans `LoginFailureMessage`.** Le
`peek()` prend une `Request` et rend un objet du composant RateLimiter. Le ruleset
`Security:` de `deptrac.yaml` n'autorise `src/Core/Security/` qu'à voir
`Security, Entity, Dto, Enum` — `Symfony\Component\HttpFoundation\*` y est fermé. C'est la
même contrainte qui a mis le 422 dans un écouteur à la story 1.6, et elle tranche pareil :
l'écouteur calcule les secondes, `LoginFailureMessage` reçoit un `?int` et reste une
fonction pure.

**Pourquoi une priorité négative, et ce qui se casse sans elle.**
`LoginThrottlingListener::onFailedLogin()` consomme un jeton sur `LoginFailureEvent` à la
priorité **0**, et `LoginFailureListener` y est aujourd'hui enregistré **sans priorité**.
Deux écouteurs à égalité sont exécutés dans l'ordre où le conteneur les a compilés — un
ordre que rien ne fixe. S'il passe avant, le `peek()` rend le délai *d'avant* la tentative
qu'on est en train de refuser : l'écran affiche un compte à rebours faux, et aucun test
naïf ne le voit. Ce n'est pas une préférence de forme : la priorité fait partie du
comportement.

**Ce qu'un succès ne fait pas.** `DefaultLoginRateLimiter` est *peekable*, donc
`LoginThrottlingListener::onSuccessfulLogin()` **ne remet rien à zéro** (`:61-67`). Quatre
échecs suivis d'une connexion réussie laissent quatre jetons consommés jusqu'à l'expiration
de la fenêtre. C'est le comportement natif, il est volontaire côté Symfony — trouver le mot
de passe ne doit pas offrir une nouvelle salve — et il doit être **écrit**, pas découvert
par le premier utilisateur qui se trompe quatre fois avant de réussir.

**La course acceptée, faute de `symfony/lock`.** Sans verrou, deux requêtes simultanées
lisent puis écrivent le même compteur de cache et peuvent dépasser le seuil d'un cran ou
deux. La fenêtre borne quand même l'attaque, et installer `symfony/lock` ajouterait un
composant et un magasin pour fermer un dépassement marginal. Le choix est ici, il est
assumé, et il part dans `deferred-work.md` avec ce qui le rouvrirait : un déploiement
multi-serveurs, où le pool de cache par défaut cesse d'ailleurs d'être partagé.

**Le 429 plutôt que le 422.** Le refus n'est pas une entrée invalide, c'est une limite de
débit — et c'est le statut que `deferred-work.md` attendait déjà pour `excluded_http_codes`.
Turbo rend le corps des réponses d'erreur comme celui d'un 422 : l'écran se comporte de la
même façon, le statut dit seulement la vérité.

## Verification

**Commands :**
- `composer require symfony/rate-limiter:7.4.*` -- installé, `config/bundles.php` inchangé
- `php bin/console debug:firewall main` -- le ralentissement apparaît parmi les écouteurs
- `php bin/console debug:container limiter` -- les limiteurs attendus existent, sous les
  noms prévus
- `php bin/console lint:yaml config/ translations/` -- valides
- `vendor/bin/phpunit --filter LoginThrottling` -- vu **rouge** d'abord, puis vert
- `vendor/bin/phpunit` -- la suite entière au vert, `LoginTest` compris
- `make qa` -- les six catégories passent

**Manual checks :**
- JavaScript coupé : cinq échecs, puis une sixième tentative — le message porte un nombre
  de secondes qui **décroît** d'une soumission à l'autre, et le bouton reste actif
- Attendre le délai, puis se connecter avec les bons identifiants : la connexion aboutit
  sans aucune intervention
- L'état du ralentissement vit dans le pool de cache du limiteur et nulle part ailleurs :
  aucune colonne, aucun fichier nommé d'après un utilisateur
