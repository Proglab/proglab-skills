# Revue indépendante — ancrage des versions et des technologies dans le réel

- **Cible :** `ARCHITECTURE-SPINE.md` (socle ERP proglab), statut `final`, mis à jour 2026-09-10.
- **Lentille :** chaque décision engagée a-t-elle été réellement recherchée, ou affirmée de mémoire ?
- **Date des contrôles :** 2026-09-10. Toutes les vérifications ci-dessous ont été refaites
  sur `symfony.com`, `repo.packagist.org`, `php.net`, `endoflife.date` et les dépôts amont.
- **Relecture du `.memlog.md` :** oui. Il contient des lignes `(version)` datées et détaillées,
  donc la recherche **a bien eu lieu**. Les constats ci-dessous portent donc moins sur une
  absence de recherche que sur deux autres défauts : (a) des faits correctement recherchés
  dont la conséquence n'a pas été tirée dans le spine, et (b) deux affirmations fausses
  recopiées du `.memlog.md` vers le spine.

## Verdict

La pile est réelle, à jour et cohérente : **aucune version fantôme, aucun paquet abandonné,
aucune incompatibilité croisée**. Les dates Symfony 7.4 sont exactes. Mais deux engagements
techniques ne tiennent pas à la vérification — le choix de PHP 8.4 et le mécanisme de
`#[When('dev')]` — et trois justifications écrites dans le spine sont factuellement fausses
ou périmées.

---

## 1. Section Stack — contrôle version par version

Toutes les versions citées existent, sont la version courante de leur branche, et leur
branche est maintenue. Contraintes croisées vérifiées sur les `composer.json` réels.

| Spine | Réel au 2026-09-10 | Branche maintenue ? | Contrainte croisée |
| --- | --- | --- | --- |
| PHP 8.4 | 8.4.25 ; support **actif jusqu'au 31/12/2026**, sécurité 31/12/2028 | oui, mais voir § 3 | OK pour toute la pile |
| Symfony 7.4 LTS | 7.4.18 (2026-08-30), `php: >=8.2` | oui — LTS, dernière de la branche 7.x | OK |
| Doctrine ORM 3.7 | **3.7.0, publiée le 2026-09-07** ; `php ^8.1`, `doctrine/dbal ^3.8.2 \|\| ^4` ; pas d'ORM 4 stable (4.0.x-dev) | oui | OK avec DBAL 4.4 |
| Doctrine DBAL 4.4 | 4.4.4 (2026-07-21), `php ^8.2` ; 5.0.x-dev en cours | oui | OK |
| scheb/2fa 8.6 | 8.6.1 ; `php ~8.4.0 \|\| ~8.5.0`, Symfony `^7.4 \|\| ^8.0` ; sous-paquets `2fa-totp`, `2fa-email`, `2fa-backup-code` tous en 8.6.1 | oui | OK |
| Symfony UX 3.4 | `ux-turbo` 3.4.0, `stimulus-bundle` 3.4.0, `ux-twig-component` 3.4.0 ; `php >=8.4`, Symfony `^7.4\|^8.0` | oui | OK — **impose PHP ≥ 8.4**, c'est le plancher réel de la pile |
| ux-toolkit 3.4 (expérimental) | 3.4.0 (2026-07-31) ; mention amont verbatim : « This component is currently experimental and is likely to change, or even change drastically. » | oui | OK — la réserve du spine est exacte |
| symfonycasts/tailwind-bundle 1.0 | 1.0.0 (2026-07-23) ; `php >=8.2`, Symfony `^6.4\|^7.0\|^8.0` | oui | OK — voir constat 1.a |
| deptrac/deptrac 4.7 | 4.7.1 (2026-07-23), `php ^8.2`, Symfony `^6.4 \|\| ^7.4 \|\| ^8.0`, `phpstan/phpstan ^2.0` | oui, non abandonné | OK |
| dama/doctrine-test-bundle | 8.6.0, `framework-bundle ^6.4 \|\| ^7.3 \|\| ^8.0` | oui | OK |
| phpstan, php-cs-fixer/shim | 2.2.13 / 3.95.25, tous deux actifs en 2026-09 | oui | OK (deptrac exige phpstan ^2.0 → aligné) |

Sources : <https://symfony.com/releases.json>, <https://packagist.org/packages/doctrine/orm>,
<https://packagist.org/packages/doctrine/dbal>, <https://packagist.org/packages/scheb/2fa-bundle>,
<https://packagist.org/packages/symfony/ux-toolkit>, <https://packagist.org/packages/symfonycasts/tailwind-bundle>,
<https://packagist.org/packages/deptrac/deptrac>, <https://endoflife.date/api/php.json>.

### Constat 1.a — « Tailwind 4 » n'est pas une propriété de tailwind-bundle 1.0 — **basse**

La ligne Stack lit « symfonycasts/tailwind-bundle — Tailwind 4 | 1.0 ». Le bundle 1.0.0 ne
*contient* pas Tailwind : il télécharge le binaire standalone, et la doc amont est explicite —
`tailwind:update` reste **dans la majeure courante**, et passer d'une majeure à l'autre
**oblige à renseigner `binary_version` à la main**. Sans `binary_version` épinglé dans
`config/packages/symfonycasts_tailwind.yaml`, deux releases du même dérivé peuvent être bâties
avec deux binaires différents, ce qui est incompatible avec la promesse de reproductibilité du
socle. À écrire comme une convention, pas comme une version de paquet.

Source : <https://symfony.com/bundles/TailwindBundle/current/index.html>

### Constat 1.b — la seule cellule sans version n'est pas gratuite — **moyenne**

« MySQL | version du serveur client » est la seule ligne de la Stack qui laisse une variable
ouverte, et DBAL 4 ne le permet pas sans contrainte : DBAL 4 a **supprimé** le support de
MySQL ≤ 5.6 et de MariaDB ≤ 10.4.2, et la détection de plate-forme exige un `serverVersion`
correct dans la configuration DBAL (sinon Doctrine se trompe de plate-forme et génère des
migrations fausses). Un socle destiné à être déployé chez des clients inconnus doit nommer un
plancher (« MySQL 8.0+ ou MariaDB 10.5+, `server_version` obligatoire dans le DSN ») plutôt
qu'accepter ce que le client a.

Sources : <https://github.com/doctrine/dbal/blob/4.4.x/UPGRADE.md>,
<https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/platforms.html>

### Constat 1.c — le paquet du transport Doctrine n'est pas celui qui est cité — **basse**

La Stack liste « symfony/messenger — transport Doctrine | 7.4 ». Le transport n'est pas dans
`symfony/messenger` : il faut `composer require symfony/doctrine-messenger`, paquet séparé,
absent de la Stack. Deux garde-fous documentés amont manquent aussi alors qu'AD-4 engage ce
transport comme non optionnel : `auto_setup: false` en production avec la table
`messenger_messages` créée par migration (le spine dit « les migrations s'exécutent à chaque
release » sans nommer cette table), et `redeliver_timeout` supérieur à la durée du plus long
message.

Source : <https://symfony.com/doc/7.4/messenger.html>

---

## 2. Technologies nommées hors Stack

| Affirmation du spine | Vérifié | Verdict |
| --- | --- | --- |
| `#[When('dev')]` rend absent du conteneur **et de la table de routage** | `Symfony\Component\DependencyInjection\Attribute\When` existe (et `WhenNot` depuis 7.2) | **faux sur la moitié routage** → constat 2.a |
| `EquatableInterface` + comparaison d'utilisateur déauthentifie les sessions | documenté, mécanisme réel | **exact**, avec un angle mort → constat 2.b |
| Transport Doctrine de Messenger | réel, supporté en 7.4, DSN `doctrine://default`, table `messenger_messages` | exact (paquet mal nommé, cf. 1.c) |
| Login throttling natif du SecurityBundle | réel : `login_throttling`, `max_attempts` (5/min par défaut), `interval`, `limiter` ; 7.4 ajoute `cache_pool` et `storage_service` | exact **mais absent du spine** → constat 2.c |
| `symfony/object-mapper` « reste expérimental sur 7.4 » | **non** — il a cessé d'être expérimental en 7.4 | **faux** → constat 2.d |
| Coffre de secrets Symfony en production | réel, composant `secrets`, extension Sodium | exact, prérequis de déploiement non dit → constat 2.e |
| Deployer | `deployer/deployer` v8.0.5 (2026-05-18), `php ^8.3`, non abandonné, 28,6 M d'installations | exact |

### Constat 2.a — AD-11 : `#[When('dev')]` ne retire pas la route. FR-16 exige « impossible ». — **haute**

AD-11 écrit : « contrôleur, service et route de FR-16 portent `#[When('dev')]`, donc sont
absents du conteneur et de la table de routage en production », et appuie dessus l'exigence
« FR-16 exige *impossible*, pas *masqué* ».

Le réel : `#[When]` est un attribut de **la conteneur d'injection de dépendances**. La doc
Symfony le décrit exclusivement comme « registered only in certain scenarios », et son unique
condition supportée est l'environnement de configuration — aucune mention du routage. Les
routes déclarées par `#[Route]` sont chargées par le loader de routage qui scanne le
répertoire de contrôleurs, **indépendamment du conteneur**. Conséquence : en production la
route reste dans la table (visible avec `debug:router`) et la requête échoue à la résolution
du contrôleur, qui n'est plus un service. Ce n'est pas l'absence promise par AD-11 : c'est une
erreur serveur sur un chemin qui existe.

Le bon outil existe et est documenté : l'option `env` de l'attribut `Route`.

```php
#[Route('/tools', name: 'tools', env: 'dev')]
```

Symfony **7.4 accepte même un tableau** (`env: ['dev', 'test']`). La rédaction correcte
d'AD-11 est donc `#[When('dev')]` **sur le service** *et* `env: 'dev'` **sur la route** — les
deux, parce qu'ils ne retirent pas la même chose. Tel qu'écrit, l'AD réclame une garantie que
le mécanisme cité ne fournit pas, et c'est précisément l'AD dont le `Prevents` dit « qu'un
dérivé livré chez un client expose un point d'entrée capable de démarrer un processus sur le
serveur ».

Sources : <https://symfony.com/doc/7.4/service_container.html#limiting-services-to-a-specific-symfony-environment>,
<https://symfony.com/doc/7.4/routing.html> (section « Matching Environments »),
<https://jolicode.com/blog/desactiver-des-routes-symfony-en-production>

### Constat 2.b — AD-9 est exact, mais ne couvre pas le firewall sans état d'AD-5 — **moyenne**

Le mécanisme est réel et c'est bien le mécanisme recommandé. Texte amont vérifié :

> « At the end of every request (unless your firewall is `stateless`), your `User` object is
> serialized to the session. At the beginning of the next request, it's deserialized and then
> passed to your user provider to "refresh" it […] If any of these are different, your user
> will be logged out. » — puis, pour `EquatableInterface` : « your `isEqualTo()` method will be
> called when comparing users instead of the core logic. »

Deux précisions que le spine n'a pas tirées :

1. La parenthèse « *unless your firewall is `stateless`* » est exactement le firewall `/api`
   d'AD-5. Le rafraîchissement et donc `isEqualTo()` **ne s'exécutent jamais** sur `/api`. Le
   `Prevents` d'AD-9 (« qu'une désactivation laisse une session ouverte ») est donc vrai pour
   l'interface et faux pour l'API : un jeton Bearer émis avant la désactivation continue de
   fonctionner, car rien dans la pile par défaut ne vérifie l'état actif du compte lors d'une
   authentification par jeton. Il faut soit un `UserCheckerInterface` qui refuse un compte
   désactivé, soit une révocation des jetons à la désactivation. Ni l'un ni l'autre n'est écrit.
2. Symfony 7.3 a introduit une alternative pour le seul cas du changement de mot de passe
   (hachage `crc32c` du mot de passe dans `__serialize()`). Elle ne remplace pas AD-9 — qui
   couvre aussi désactivation et réinitialisation 2FA — mais son existence confirme que le
   jeton de sécurité porté par le compte est le bon choix, pas un contournement.

Source : <https://symfony.com/doc/7.4/security.html#understanding-how-users-are-refreshed-from-the-session>
(texte brut : `symfony-docs`, branche 7.4, `security.rst`, lignes 3026-3084)

### Constat 2.c — le login throttling a été recherché, vérifié, et n'est pas entré dans le spine — **haute**

Le `.memlog.md` (ligne 19) porte une note de recherche exacte : login throttling natif depuis
Symfony 5.2, adossé à RateLimiter, 5 tentatives/minute par couple identifiant+IP et un
limiteur global par IP, configurable par `max_attempts`, `limiter`, `cache_pool`. J'ai
revérifié : tout est juste, et 7.4 ajoute `cache_pool` et `storage_service`. Le spine, lui,
n'en dit **rien** : ni AD, ni ligne de convention, ni entrée de Stack, ni `symfony/rate-limiter`.

Dans ma lentille, c'est le cas le plus net de recherche non convertie en décision : le fait a
été établi, il répond à une exigence, et il s'est perdu entre le journal et le document
engageant. La revue `reconcile-ux.md` l'a déjà signalé en « haute » depuis l'angle UX ; je le
confirme depuis l'angle factuel, avec une précision qui durcit le constat : l'exception levée
par le throttling natif (`TooManyLoginAttemptsAuthenticationException`) porte un seuil en
**minutes**, pas les secondes restantes que l'UX demande d'afficher. Le choix « natif vs
limiteur personnalisé » est donc un vrai arbitrage d'architecture, pas un détail d'implémentation.

Sources : <https://symfony.com/doc/7.4/security.html> (section login throttling),
<https://symfony.com/doc/7.4/rate_limiter.html>

### Constat 2.d — « `symfony/object-mapper` reste expérimental sur 7.4 » est faux — **moyenne**

C'est la justification de la convention DTO (« le mapping se fait par `static fromEntity()` ;
`symfony/object-mapper` reste expérimental sur 7.4 (AD-1) et n'entre pas dans le socle »), et
elle est périmée :

- la doc **7.3** porte l'avertissement : « The ObjectMapper component was introduced in
  Symfony 7.3 **as an experimental feature** » ;
- la doc **7.4** ne le porte plus : elle ne dit que « The ObjectMapper component was introduced
  in Symfony 7.3 », et documente au contraire deux ajouts 7.4 (`MapCollection`, décoration du
  mapper) ;
- le changement est traçable : PR `symfony/symfony#61500`, « [JsonPath][JsonStreamer]
  [ObjectMapper] the components are no longer experimental », livrée dans 7.4.0-BETA1 ;
- le paquet a bien une branche 7.4 utilisable : `symfony/object-mapper` v7.4.17 (2026-08-17),
  `php >=8.2`.

La décision (`static fromEntity()`) peut rester la bonne — explicite, testable, sans magie,
cohérente avec le reste du standard proglab. Mais **la raison écrite est fausse**, et une
raison fausse dans un document `final` se recopiera dans les dérivés. À reformuler sur le vrai
motif (lisibilité et contrôle du contrat de sortie), ou à rouvrir.

Sources : <https://symfony.com/doc/7.4/object_mapper.html>, <https://symfony.com/doc/7.3/object_mapper.html>,
<https://github.com/symfony/symfony/pull/61500>, <https://packagist.org/packages/symfony/object-mapper>

### Constat 2.e — le coffre de secrets a un prérequis de déploiement non écrit — **basse**

Le composant existe et convient. Mais la doc amont est catégorique : « Due to the fact that
decryption keys should never be committed, you will need to **manually store this file
somewhere and deploy it**. » Concrètement, avec l'arborescence `releases/current/shared` de
Deployer, il faut soit `config/secrets/prod/prod.decrypt.private.php` en `shared_files`, soit
`SYMFONY_DECRYPTION_SECRET` dans l'environnement, soit `secrets:decrypt-to-local --force` à
l'enveloppe de déploiement. Le spine engage le coffre sans nommer aucune des trois, et exige
par ailleurs l'extension Sodium côté serveur client. Une ligne dans l'enveloppe opérationnelle
suffit à fermer le trou.

Source : <https://symfony.com/doc/7.4/configuration/secrets.html>

---

## 3. Affirmations de calendrier

### Symfony 7.4 LTS : exact — **aucun constat**

Vérifié sur la page officielle de la release : sortie novembre 2025, **fin des corrections de
bugs novembre 2028**, **fin des corrections de sécurité novembre 2029**, statut « maintenue »,
dernier correctif 7.4.18. `releases.json` confirme que 7.4 est la version LTS courante et que
les branches maintenues sont 6.4 (LTS), 7.4 (LTS), 8.1 (stable, 8.1.6) et 8.2 (à venir). 7.4
est la dernière de la branche 7.x. AD-1 est solide sur ce point.

Sources : <https://symfony.com/releases/7.4>, <https://symfony.com/releases.json>

### Constat 3.a — PHP 8.4 ne couvre pas la fenêtre de Symfony 7.4 LTS. C'est une contradiction datable. — **critique**

C'est le constat le plus grave de cette revue, et il est d'autant plus embêtant que
**le fait était connu de l'auteur**. Le `.memlog.md`, ligne 15, dit textuellement : « PHP :
8.5 dernière stable (support actif 12/2027), 8.4 support actif jusqu'au 31/12/2026 puis
sécurité jusqu'au 31/12/2028 ». J'ai tout revérifié : c'est juste.

| Branche | Dernière | Support actif jusqu'au | Sécurité jusqu'au |
| --- | --- | --- | --- |
| PHP 8.4 | 8.4.25 | **31/12/2026** | **31/12/2028** |
| PHP 8.5 | 8.5.10 (sortie 20/11/2025) | 31/12/2027 | **31/12/2029** |

Mis en regard de Symfony 7.4 LTS (bugs jusqu'en novembre 2028, sécurité jusqu'en novembre 2029) :

- **PHP 8.4 perd son support actif dans ~15 mois**, le 31/12/2026 — c'est-à-dire pendant la
  vague des premiers dérivés livrés. Tout dérivé mis en production en 2027 tournera dès son
  premier jour sur une branche PHP en sécurité seule.
- Pire, et c'est le point décisif : **la sécurité de PHP 8.4 s'arrête le 31/12/2028, soit
  onze mois avant la fin de la fenêtre de sécurité de Symfony 7.4 (novembre 2029)**. Le
  framework survit à son interpréteur. Il existe donc une période de près d'un an où le socle
  est, par construction, sur un PHP totalement hors support — exactement ce qu'AD-1 prétend
  empêcher : « Prevents : qu'un dérivé livré chez un client tourne sur un framework hors
  support ».
- PHP 8.5, à l'inverse, couvre **toute** la fenêtre Symfony 7.4 (sécurité jusqu'au 31/12/2029
  contre novembre 2029 pour Symfony). C'est la seule branche PHP qui le fasse.

Et rien dans la pile ne bloque PHP 8.5. J'ai relu les contraintes réelles, une par une :

| Paquet | Contrainte PHP | PHP 8.5 ? |
| --- | --- | --- |
| symfony/symfony 7.4.18 | `>=8.2` | oui |
| doctrine/orm 3.7.0 | `^8.1` | oui |
| doctrine/dbal 4.4.4 | `^8.2` | oui |
| scheb/2fa 8.6.1 (+ totp, email, backup-code) | `~8.4.0 \|\| ~8.5.0` | **oui, explicitement** |
| symfony/ux-* 3.4.0, ux-toolkit 3.4.0 | `>=8.4` | oui |
| symfonycasts/tailwind-bundle 1.0.0 | `>=8.2` | oui |
| symfonycasts/verify-email-bundle 1.18.0 | `>=8.1` | oui |
| deptrac/deptrac 4.7.1 | `^8.2` | oui |
| dama/doctrine-test-bundle 8.6.0 | `>= 8.2` | oui |
| deployer/deployer 8.0.5 | `^8.3` | oui |

**Aucune dépendance de la pile n'exige PHP 8.4 plutôt que 8.5.** scheb/2fa, le paquet le plus
strict de toute la liste, nomme 8.5 explicitement. Le plancher réel de la pile est PHP 8.4,
imposé par Symfony UX 3.4 — un plancher, pas un plafond.

**Conséquence :** AD-1 engage la moitié basse d'un choix binaire sans l'argumenter, alors que
l'autre moitié est disponible, compatible avec 100 % de la pile, et la seule qui tienne
jusqu'au bout du LTS. Le diagramme de déploiement inscrit d'ailleurs « Apache + PHP-FPM 8.4 »
en dur, ce qui grave le choix dans l'enveloppe opérationnelle. Si une raison légitime existe
(ce que Laragon embarque en local, ce que l'hébergeur du premier client propose), elle doit
être écrite dans AD-1 avec sa date de réexamen ; sinon, **AD-1 devrait lire PHP 8.5**, et le
plancher `>=8.4` devrait rester ce qu'il est, un plancher.

Sources : <https://www.php.net/supported-versions.php>, <https://endoflife.date/api/php.json>,
<https://www.php.net/releases/8.5/en.php>, <https://symfony.com/releases/7.4>

---

## 4. Affirmations techniques vérifiables, contrôlées une par une

### Constat 4.a — « aucun bundle d'audit Doctrine maintenu » : la conclusion tient, la raison écrite non — **moyenne**

Le spine écrit : « `damienharper/auditor-bundle` n'apparaît pas ici : sa branche 7.x exige
Symfony 8, ce qu'AD-1 exclut, et AD-3 écrit l'audit à la main. »

La première moitié est exacte — auditor-bundle 7.2.1 exige `php ^8.4` et Symfony `>= 8.0`.
Mais elle est **sélective**, et le `.memlog.md` le savait (ligne 21 : « limité à sa branche
6.3 »), alors que le spine ne le dit pas. Vérifié :

- `damienharper/auditor-bundle` **6.3.0 (2026-02-08)** : `php >=8.2`, tous ses composants
  Symfony en `^5.4|^6.4|^7.0|^8.0`, adossé à `damienharper/auditor ^3.2` ;
- `damienharper/auditor` **3.4.0 (2026-02-07)** : `doctrine/orm ^2.13|^3.2`,
  `doctrine/dbal ^3.2|^4.0`, Symfony `^5.4|^6.4|^7.4|^8.0` ;
- ni l'un ni l'autre n'est marqué abandonné ; la 6.x est en support actif.

Donc : **une branche maintenue et parfaitement compatible Symfony 7.4 + ORM 3.7 + DBAL 4.4
existe**. Le spine, tel qu'il est écrit, laisse croire qu'AD-1 a tranché pour lui — ce n'est
pas le cas, et un relecteur de dérivé qui vérifiera ce point trouvera la faille.

Sur l'affirmation plus large (« aucun bundle d'audit Doctrine maintenu ne couvre les actions
métier explicites »), elle est **trop absolue** :

- `xiidea/easy-audit` 3.0.2 (2025-12-03), `framework-bundle >=5.4 <8.0` — donc compatible 7.4 —
  se présente comme « A Symfony Bundle To Log Selective Events » et traduit un événement
  arbitraire en entité `AuditLog` par un resolver : c'est exactement le cas « action métier
  explicite » ;
- `loremipsum/action-logger-bundle` v0.4.2 (2025-10-21), Symfony `^6.0 || ^7.0`, se décrit
  comme « log custom actions and events with doctrine » — mais en 0.x, donc sans promesse ;
- `data-dog/audit-bundle` 1.3.0 (2026-04-30) reste, lui, centré sur le diff d'entités.

Ces deux premiers sont de faible adoption, et l'un est en 0.x — les écarter est parfaitement
défendable. **La décision d'AD-3 reste la bonne**, mais pour sa vraie raison, qui est déjà
écrite dans AD-3 lui-même : un bundle de diff d'entités à côté des actions métier déclarées
créerait les **deux magasins d'audit** qu'AD-3 interdit, et FR-13 (filtres, page, export)
devrait fusionner deux sources. C'est cet argument qu'il faut mettre en avant, pas un « aucun
bundle maintenu » que cinq minutes sur Packagist contredisent.

Sources : <https://packagist.org/packages/damienharper/auditor-bundle>,
<https://packagist.org/packages/damienharper/auditor>,
<https://packagist.org/packages/xiidea/easy-audit>,
<https://github.com/loremipsum/action-logger-bundle>,
<https://packagist.org/packages/data-dog/audit-bundle>

### Constat 4.b — `verify-email-bundle` : affirmation **confirmée** — aucun constat

Vérifié à la source. Le README amont dit que le bundle fait son travail « without needing any
storage, so you can use your existing entities with minor modifications » : c'est bien une URL
signée HMAC, sans enregistrement serveur, avec `lifetime` par défaut de 3600 s et aucun
mécanisme de révocation d'un lien actif. Version courante 1.18.0 (2025-11-29), `php >=8.1`,
Symfony `^5.4 | ^6.0 | ^7.0 | ^8.0`, non abandonné. L'écarter pour FR-4 est justifié.

Sources : <https://github.com/SymfonyCasts/verify-email-bundle>,
<https://packagist.org/packages/symfonycasts/verify-email-bundle>

### Constat 4.c — « les Login Links ne stockent aucun état » est faux ; la conclusion survit quand même — **basse**

AD-15 écarte « symfonycasts/verify-email-bundle et les Login Links de Symfony, qui signent une
URL sans rien stocker et ne peuvent donc ni marquer un lien consommé ni invalider le précédent ».
Vrai pour verify-email-bundle (cf. 4.b). **Faux pour les Login Links.** La doc amont :

> « It is a common characteristic of login links to limit the number of times it can be used.
> Symfony can support this by **storing used login links in the cache**. Enable this support by
> setting the `max_uses` option »

avec l'option `used_link_cache` pour désigner le pool, et l'avertissement que si le cache
déborde, « invalid links can no longer be stored (and thus become valid again) ». Donc
`max_uses: 1` **donne bien l'usage unique**, par un état en cache.

Ce qui reste vrai, et ce qui sauve AD-15 : les Login Links ne peuvent toujours pas (a)
invalider un lien **précédent encore inutilisé** lors d'un renvoi, ni (b) porter les états
« invitation en attente » / « invitation expirée » que FR-4 veut montrer à l'administrateur,
ni (c) transporter le rôle prévu. **La décision d'AD-15 tient ; sa formulation est à corriger**
— « ne stockent aucun état » devient « dont l'état en cache ne couvre que l'usage unique, pas
l'invalidation d'un lien précédent ni les états visibles de l'administrateur ».

Source : <https://symfony.com/doc/7.4/security/login_link.html>

### Constat 4.d — « scheb/2fa ne fonctionne pas sur un firewall sans état » : affirmation **confirmée** — aucun constat

C'est l'affirmation la mieux fondée du lot, et elle est centrale pour AD-5. La doc du bundle
est sans ambiguïté :

> « To make two-factor authentication work in an API, the firewall that you're doing your
> authentication on **has to be stateful** (`stateless: false` […] or not configured at all) »

et, sur le pourquoi :

> « **The session is necessary for two-factor authentication to store the state of the login** —
> if the user has already completed two-factor authentication or not. »

AD-5 (« l'API n'a pas d'endpoint d'authentification », firewall `/api` sans état, Bearer
uniquement) est donc **correctement fondé**. Le corollaire à ne pas perdre est le constat 2.b :
ce même firewall sans état désactive aussi `EquatableInterface`.

Sources : <https://symfony.com/bundles/SchebTwoFactorBundle/current/api.html>,
<https://github.com/scheb/2fa/issues/78>

---

## Synthèse par sévérité

| Sévérité | Constats |
| --- | --- |
| **critique** | **3.a** — PHP 8.4 perd son support actif le 31/12/2026 et toute sécurité le 31/12/2028, soit **onze mois avant la fin de la fenêtre Symfony 7.4 LTS** (novembre 2029) ; PHP 8.5 est sorti depuis le 20/11/2025, couvre toute la fenêtre, et **aucune dépendance de la pile ne l'interdit** (scheb/2fa le nomme explicitement). AD-1 contredit son propre `Prevents`. |
| **haute** | **2.a** — AD-11 : `#[When('dev')]` ne retire pas la route ; le bon outil est `#[Route(..., env: 'dev')]`, et il faut les deux. L'AD promet une garantie que son mécanisme ne donne pas, sur le seul AD dont l'exigence est « impossible ». **2.c** — le login throttling natif a été recherché et vérifié dans le `.memlog.md` puis n'est jamais entré dans le spine ; l'exception native ne rend d'ailleurs que des minutes, là où l'UX demande des secondes. |
| **moyenne** | **1.b** — MySQL sans plancher alors que DBAL 4 a supprimé MySQL ≤ 5.6 / MariaDB ≤ 10.4.2 et exige un `serverVersion` correct. **2.b** — AD-9 est exact mais inopérant sur le firewall sans état d'AD-5 : un jeton Bearer survit à la désactivation du compte. **2.d** — « `symfony/object-mapper` reste expérimental sur 7.4 » est faux (PR #61500, non expérimental depuis 7.4.0-BETA1 ; branche 7.4.17 disponible). **4.a** — « aucun bundle d'audit maintenu » : auditor-bundle 6.3 est maintenu et compatible 7.4, et `xiidea/easy-audit` couvre les actions explicites ; la décision d'AD-3 tient, sa justification écrite non. |
| **basse** | **1.a** — « Tailwind 4 » n'est pas une propriété de tailwind-bundle 1.0 ; `binary_version` doit être épinglé. **1.c** — le transport exige `symfony/doctrine-messenger`, absent de la Stack ; `auto_setup: false` et `redeliver_timeout` non traités. **2.e** — la clé de déchiffrement du coffre de secrets doit être déployée à la main (`shared_files` ou `SYMFONY_DECRYPTION_SECRET`) ; extension Sodium requise. **4.c** — « les Login Links ne stockent aucun état » est faux (`max_uses` + `used_link_cache`) ; la conclusion d'AD-15 survit, sa formulation est à corriger. |
| **confirmé, aucun constat** | Toutes les versions de la Stack existent, sont courantes, maintenues et mutuellement compatibles — aucune version fantôme ni paquet abandonné. Dates Symfony 7.4 exactes (bugs nov. 2028, sécurité nov. 2029). Réserve sur `ux-toolkit` expérimental exacte, au mot près. `EquatableInterface`, transport Doctrine, coffre de secrets, Deployer, login throttling : tous réels. `verify-email-bundle` bien sans stockage (**4.b**). `scheb/2fa` bien incompatible avec un firewall sans état (**4.d**) — AD-5 est correctement fondé. |

## Ce que je changerais dans le document, par ordre

1. **AD-1** : passer à PHP 8.5, ou écrire noir sur blanc la contrainte qui impose 8.4 **et** la
   date butoir du 31/12/2026. Mettre à jour « PHP-FPM 8.4 » dans le diagramme de déploiement.
2. **AD-11** : ajouter `env: 'dev'` sur la route, en plus de `#[When('dev')]` sur le service.
3. **Nouvel AD ou ligne de convention** sur le ralentissement des échecs de connexion, avec
   l'arbitrage natif / limiteur personnalisé tranché (granularité en secondes).
4. **Conventions, ligne DTO** : retirer « reste expérimental sur 7.4 », remplacer par la vraie
   raison.
5. **Stack** : plancher MySQL + `server_version` ; `symfony/doctrine-messenger` ;
   `binary_version` de Tailwind.
6. **AD-9** : nommer le `UserChecker` (ou la révocation des jetons) qui fait tenir la
   désactivation sur `/api`.
7. **Réserves de la Stack** : reformuler le paragraphe auditor-bundle sur l'argument des deux
   magasins d'AD-3, et mentionner l'existence de la 6.3 compatible.
8. **AD-15** : reformuler l'exclusion des Login Links.
