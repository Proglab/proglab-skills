---
title: 'Amorcer le dépôt et la frontière des deux racines'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '5724cc02d4c5b6e8ce2f4bfae62d562666f05e90'
context:
  - '{project-root}/.claude/skills/symfony-proglab-standards/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-architecture/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** il n'existe aucune application Symfony. Rien ne dit à un développeur ou à un
agent où écrire le code du socle et où écrire le code du client, et rien ne les empêche de
se mélanger — ce qui rendrait illisible le report d'un correctif dès le troisième module.

**Approach :** amorcer un squelette Symfony 7.4 LTS sur PHP 8.5, y poser les deux racines
`src/Core/` et `src/Module/<Nom>/` avec les mêmes dossiers de couche, câbler `config/` par
glob pour qu'ajouter un module ne modifie aucun fichier existant, et rendre la frontière
vérifiable par deptrac plutôt que promise.

## Boundaries & Constraints

**Always :**
- Symfony 7.4 LTS, PHP 8.5, Doctrine ORM 3.7 / DBAL 4.4. `composer.json` fixe 7.4 et
  n'admet aucune dépendance exigeant Symfony 8.
- Les dix dossiers de couche portent les mêmes noms dans les deux racines : `Controller/`,
  `Service/`, `Repository/`, `Dto/{Input,Read,Output}/`, `Entity/`, `Exception/`,
  `EventListener/`, `Security/`, `Command/`, `Twig/`.
- `src/Core/Contract/` ne contient qu'interfaces et DTO, sans dépendance Doctrine ni HTTP.
  C'est la seule porte qu'un module voit.
- Le câblage framework — mapping Doctrine, routes par attributs, chemins du translator,
  découverte des services — est fixé **une fois** par glob sur `src/Module/*/`.
- Namespaces `App\Core\<Couche>\…` et `App\Module\<Nom>\<Couche>\…`. Routes
  `app_<ressource>_<action>`, chemins anglais sans préfixe de langue. Services
  `final readonly`, jamais `<Chose>Service`.
- Test écrit d'abord et vu rouge (règle 1 du standard proglab).
- `var/` est le seul répertoire inscriptible. `_bmad-output/` reste versionné et déployé,
  jamais ignoré.

**Never :**
- Aucun starter template tiers : le socle démarre d'un squelette Symfony nu.
- Aucun thème, kit de composants, gabarit de base, authentification, entité du socle ni
  catalogue de traduction du socle — stories 1.3 à 1.10.
- Aucune chaîne qualité complète et aucune CI — PHPStan, php-cs-fixer, Makefile,
  `dama/doctrine-test-bundle`, workflow : story 1.2. **Seul deptrac entre ici**, parce que
  les critères de cette story l'exigent.
- Aucun bundler, aucune étape Node, aucun conteneur.
- Aucune migration, aucune base de données requise pour faire passer les tests.
- Aucun fichier de `config/` ni de `src/Core/` ne doit être modifié pour ajouter un module.

## Decisions

Tranché avec Fabrice le 2026-09-10, là où les documents de planification étaient muets :

1. **Le socle s'amorce à la racine de ce dépôt**, pas dans un dépôt frère. Le
   `_bmad-output/` déjà présent devient celui que la story 3.3 affichera.
2. **La LICENSE passe en propriétaire interne** — la MIT au nom de l'auteur amont
   contredit « le socle reste interne : ni vendu, ni ouvert ». **Le README n'est pas
   touché** : c'est le sujet de la story 1.12.
3. **Le module de démonstration est une fixture de test**, sous
   `tests/Fixtures/Module/<Nom>/`. `src/Module/` est livré vide : rien ne part chez le
   client.
4. **Les quatre globs sont écrits deux fois** : sur `src/Module/*/` pour la production, et
   sous `when@test` sur `tests/Fixtures/Module/*/` pour que la fixture soit découverte par
   un glob de forme identique.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Page d'accueil | `GET /` | 200, page d'accueil minimale | N/A |
| Module découvert | Module de démonstration présent, `config/` intact | Son entité connue de l'ORM, sa route chargée, son service dans le conteneur, son catalogue résolu | N/A |
| Porte ouverte | Classe de `Module/` référençant `App\Core\Contract\…` | deptrac passe | N/A |
| Porte fermée | Classe de `Module/` référençant `App\Core\Service\…` | deptrac échoue | Nomme la règle `Module` → `Core` |
| Socle vers module | Classe de `Core/` référençant `App\Module\…` | deptrac échoue | Nomme la règle `Core` → `Module` |
| Module vers module | `Module/A` référençant `App\Module\B\…` | deptrac échoue | Nomme la règle `ModuleA` → `ModuleB` |
| Couche violée | Service référençant `Doctrine\ORM\QueryBuilder` | deptrac échoue | Nomme la règle `Service` → `Doctrine` |

</frozen-after-approval>

## Code Map

- `.claude/skills/symfony-proglab-quality/assets/deptrac.yaml` -- le contrat de couches
  proglab complet, en version **mono-racine**. Reprendre ses couches, ses collecteurs
  Doctrine / EntityManager / DoctrineMapping et son ruleset tels quels ; ne pas le copier
  sans transposer les collecteurs aux deux racines.
- `.claude/skills/symfony-proglab-quality/references/deptrac.md:60-160` -- le collecteur
  `src/.*/Controller/.*`, le patron « ouvrir exactement une porte » (`CatalogApi`) qui est
  celui de `Core\Contract`, et pourquoi `--report-uncovered` va sans `--fail-on-uncovered`.
- `…/architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md` -- AD-1:63,
  AD-2:74, AD-6:150 (table des 20 routes), AD-7:184 (les quatre règles), AD-13:335 (le
  glob), Structural Seed:586, Consistency Conventions:536.
- `_bmad-output/planning-artifacts/epics.md:297-324` -- les critères d'acceptation source.
- `.gitignore` -- ne contient que `.DS_Store` ; à compléter par la recette Symfony, sans
  jamais y faire entrer `_bmad-output/`.
- `LICENSE` -- MIT au nom de l'auteur amont, héritée du fork. À remplacer (décision 2).
- **Ne pas toucher :** `_bmad/`, `.claude/skills/`, `_bmad-output/planning-artifacts/`,
  `README.md`.

## Tasks & Acceptance

**Execution :**
- [x] `composer.json` -- amorcer le squelette Symfony 7.4 LTS sur PHP 8.5 et épingler
      `symfony/*` sur `7.4.*` -- point de départ imposé, aucun template tiers
- [x] `composer.json` -- ajouter `symfony/test-pack` et `deptrac/deptrac` en `require-dev`
      -- le minimum pour écrire le test d'abord et pour vérifier la frontière ; le reste de
      la chaîne qualité appartient à la story 1.2
- [x] `tests/` -- écrire d'abord les tests de la matrice et les voir rouges : réponse de
      `GET /`, et découverte du module (entité, route, service, catalogue)
- [x] `src/Core/**` et `src/Module/` -- créer les dix dossiers de couche plus `Contract/`
      dans `Core/`, et `src/Module/` vide, avec un `.gitkeep` partout où aucun fichier
      n'existe encore
- [x] `tests/Fixtures/Module/<Nom>/**` -- créer le module de démonstration : une entité, un
      contrôleur avec sa route, un service `final readonly` implémentant un contrat de
      `Core/Contract/`, un catalogue de traduction
- [x] `src/Core/Contract/` -- déclarer l'interface que le module implémente, sans
      dépendance Doctrine ni HTTP -- prouve que la porte publique existe et suffit
- [x] `src/Core/Controller/HomeController.php` -- route `app_home` sur `/`, page d'accueil
      minimale -- une classe de contrôleur par ressource, chemin anglais
- [x] `config/packages/doctrine.yaml`, `config/routes.yaml`,
      `config/packages/translation.yaml`, `config/services.yaml` -- câbler les deux racines
      par glob sur `src/Module/*/`, et sous `when@test` le miroir exact sur
      `tests/Fixtures/Module/*/` -- ajouter un module ne doit modifier aucun de ces fichiers
- [x] `deptrac.yaml` -- transposer le contrat de couches aux deux racines et ajouter les
      trois règles de frontière, en collectant aussi `tests/Fixtures/Module/` -- AD-7
- [x] `tests/` -- écrire les quatre cas de violation deptrac de la matrice -- chacun doit
      échouer en nommant sa règle
- [x] `.gitignore` -- compléter avec les entrées de la recette Symfony -- `var/` seul
      inscriptible, `_bmad-output/` jamais ignoré
- [x] `LICENSE` -- remplacer la MIT amont par une mention propriétaire interne -- décision 2

**Acceptance Criteria :**
- Given un clone du dépôt et PHP 8.5, when j'installe les dépendances et j'ouvre
  l'application, then elle répond sur une page d'accueil minimale
- Given `composer.json`, when je le lis, then il fixe Symfony 7.4 LTS et aucune dépendance
  n'exige Symfony 8
- Given les deux racines, when je cherche où placer un service, then `src/Core/` et
  `src/Module/<Nom>/` portent les mêmes dix dossiers de couche
- Given le module de démonstration en fixture, when la suite tourne sans qu'aucun fichier
  de `config/` ni de `src/Core/` ait été modifié pour l'accueillir, then son entité, sa
  route, son service et son catalogue sont pris en compte par le glob
- Given `src/Module/`, when je liste le dépôt livré, then il est vide : aucun code de
  démonstration ne part chez un client
- Given `vendor/bin/deptrac analyse`, when la base de code est saine, then il passe sans
  violation

## Implementation Notes

**Ce que la spec disait, et ce que les outils ont imposé.** Cinq points où
l'implémentation s'écarte de la lettre de la spec, tous vérifiés à l'exécution.

1. **Le glob Doctrine et le glob du translator ne peuvent pas être littéralement
   `src/Module/*/`.** `doctrine.orm.mappings.<nom>.dir` refuse une glob — DoctrineBundle
   vérifie `is_dir()` — et `framework.translator.paths` aussi. Les deux visent donc la
   *racine* `src/Module`, que leurs mécanismes parcourent récursivement : le driver par
   attributs ne retient que les classes portant `#[ORM\Entity]`, le translator ne retient
   que les fichiers `domaine.locale.format`. La propriété qui compte — ajouter un module
   ne modifie aucun fichier — est tenue, et démontrée : un troisième module posé à la main
   a été vu par les quatre câblages sans qu'aucun fichier de `config/` soit touché. Les
   globs de `config/routes.yaml` et de `config/services.yaml` sont, eux, littéraux.
2. **Le glob des routes vise des fichiers, pas un répertoire :
   `../src/Module/*/Controller/**/*.php`.** Un import de routage résout sa glob en mode
   non récursif, et une glob qui désigne un *répertoire* ne produit alors rien du tout
   (`GlobResource::getIterator`). La forme fichier fonctionne, et `**` récupère les
   contrôleurs rangés en sous-dossier.
3. **`config/routes.yaml` n'utilise plus `resource: routing.controllers`.** Le squelette
   7.4 charge les routes depuis les services de contrôleur taggés ; le conserver aurait
   fait cohabiter deux mécanismes de découverte. Un seul reste : les deux globs.
4. **Le tag `app.module` est posé par `_instanceof` dans `config/services.yaml`, pas par
   `#[AutoconfigureTag]` sur l'interface.** L'attribut n'est lu que si l'interface est
   elle-même passée par un `resource:` — or `Contract/` est exclu de la découverte des
   services, et doit le rester puisqu'il portera aussi des DTO. Bénéfice secondaire :
   `src/Core/Contract/` ne dépend plus de rien du tout, pas même d'un attribut de
   conteneur.
5. **Un second module de démonstration, `Billing`, réduit à un service.** « `Module` →
   `Module` interdit » est invérifiable avec un seul module : deptrac nomme une couche par
   module et n'exempte une couche que vis-à-vis d'elle-même. Le module de démonstration
   complet reste `Demo` ; `Billing` n'existe que pour que la troisième règle de frontière
   soit vérifiée plutôt que déclarée. Les deux vivent en fixture, `src/Module/` est livré
   vide.

**deptrac : la double dimension a un coût, écrit en toutes lettres dans le fichier.**
Chaque classe appartient à deux couches — sa couche technique et sa couche de racine.
deptrac évalue les deux, et **n'exempte une dépendance que si *toutes* les couches de la
cible sont identiques à celle de la source** (`MatchingLayersHandler`). Conséquence : sans
précaution, un contrôleur du socle appelant un service du socle est signalé
`Controller on Core`. La parade est que chaque dimension soit aveugle à l'autre — une
couche technique autorise toutes les racines, une racine autorise toutes les couches
techniques — via deux couches de vocabulaire vides, `AnyLayer` et `AnyRoot`, héritées avec
`+`. C'est aussi ce qui rend l'ajout d'un module bon marché : trois éditions dans
`deptrac.yaml`, et deptrac dit laquelle manque. Ce ruleset diverge donc de celui du skill
qualité sur deux points, tous deux commentés dans le fichier : chaque couche se voit
elle-même, et `CoreContract` est ouvert aux couches qui peuvent légitimement voir la porte
publique.

**Ce que `ModuleRegistry` fait ici.** La spec demandait l'interface seule. Une interface
que personne ne consomme n'est pas une porte : le service taggé est supprimé du conteneur
comme inutilisé, et « son service dans le conteneur » — la ligne « Module découvert » de
la matrice — devient invérifiable. `src/Core/Service/ModuleRegistry.php` est la moitié
socle de la porte : il reçoit les `ModuleDescriptor` par `#[AutowireIterator('app.module')]`
et n'expose que leurs noms. L'accueil les affiche, ce qui referme la chaîne
glob → contrat → socle en un seul test de bout en bout. En production, `src/Module/` étant
vide, la liste est vide et la page se réduit à son `h1`.

**Ce que le squelette a apporté et qu'on n'a pas touché.** `templates/base.html.twig` est
celui de la recette `symfony/twig-bundle` : il n'a ni `lang`, ni lien d'évitement, ni
région d'annonces — c'est la story 1.4 qui le remplace. `config/packages/framework.yaml`,
`cache.yaml`, `routing.yaml`, `twig.yaml` et `.editorconfig` sont ceux des recettes.
`compose.yaml` et `compose.override.yaml` ont été supprimés (aucun conteneur), et
`src/Controller`, `src/Entity`, `src/Repository` — la mono-racine des recettes — aussi.

**Deux réglages pris à la place de Fabrice.** `DATABASE_URL` passe du PostgreSQL de la
recette au MySQL de la pile (AD-1), avec `serverVersion` explicite pour qu'aucun test ne
demande de connexion. Et `framework.default_locale` reste `en` : la source française et
les trois langues sont la story 1.5.

**`config/reference.php` est ignoré par git** (correction apportée à la vérification). Ce
fichier est généré par un compiler pass de FrameworkBundle à chaque construction du
conteneur, et **seulement quand `kernel.debug` est vrai** — il n'est donc jamais écrit en
production, et la règle « seul `var/` est inscriptible » du dérivé livré tient. En
revanche son contenu dépend de l'environnement construit en dernier : un build en `dev` y
ajoute les formes `when@dev` qu'un build en `test` n'y met pas. Le committer garantissait
un arbre de travail jamais propre — vérifié : `cache:clear --env=test` puis
`--env=dev` le salissent tour à tour. C'est une aide d'IDE régénérée toute seule, donc
elle est ignorée plutôt que versionnée.

**Correctifs de la passe de revue** (appliqués sans reprise depuis l'étape 2 ; l'agent
d'implémentation n'était pas rejoignable, donc appliqués à la main).

- **Le filet `UndeclaredModule` dans `deptrac.yaml`.** La couche par module laissait
  passer tout module non déclaré : sa classe n'ayant que sa couche technique, dont le
  ruleset porte `+AnyRoot`, elle atteignait `Core` et les autres modules sans violation.
  Une couche attrape-tout (`must` : tous les modules, `must_not` : les déclarés) avec un
  ruleset vide referme le trou : ajouter un module coûte désormais **quatre** éditions, et
  en oublier une échoue bruyamment au lieu d'ouvrir la frontière.
- **`CoreContract: ~` est conservé, et documenté.** Le constat de revue voulait l'ouvrir
  aux enums ; c'était une erreur de jugement de ma part. Un enum de `src/Core/Enum/` est
  un interne du socle, et une porte qui atteint les internes n'est plus une porte. Ce qui
  manquait n'était pas une permission mais **une convention** : un enum ou un objet valeur
  que traverse un contrat vit dans `Contract/`. Vérifié : rangé là, il passe sans
  violation. Le fichier le dit maintenant à l'endroit où on se posera la question.
- **`BoundaryTest` passe par `Process` avec son `cwd`.** Le `cd … && …` passé à `exec()`
  ne changeait pas de volume sous Windows : un bac à sable sur un autre disque aurait fait
  analyser le vrai dépôt et les quatre cas de violation seraient passés à vide. Deux tests
  ajoutés : le module non déclaré, et l'absence de classe à nous dans `--report-uncovered`
  — ce que la section Verification promettait sans que rien ne l'observe.
- **La page d'accueil affirme enfin ce qu'elle rend.** Vérifié par mutation : supprimer le
  bloc `{% if modules %}` fait maintenant rougir la suite, ce qui n'était pas le cas.
- **`ModuleWiringTest` n'affirme plus la liste exacte** — le glob de production est actif
  en test, donc le premier vrai module d'un dérivé aurait cassé un test du socle.
- **Ce qu'on a mesuré sur les globs de production, et pas seulement supposé.** Un seul des
  quatre est observable tant que `src/Module/` est vide : le mapping Doctrine, parce qu'il
  vise un répertoire existant. Les trois autres sont des globs qui ne correspondent à rien,
  donc ni le routeur ni le conteneur n'enregistrent quoi que ce soit à observer — vérifié
  en écrivant l'assertion et en la voyant échouer. La couture reste, mais elle est
  désormais bornée précisément plutôt que décrite.
- **Le bloc FrankenPHP est retiré de `base.html.twig`** : deux `<script>` tiers chargés
  depuis un CDN, hors importmap donc invisibles pour `importmap:audit`, pour un runtime que
  la pile n'utilise pas. `lang` ajouté sur `<html>` — une ligne, et l'epic interdit de
  consigner un écart d'accessibilité en dette silencieuse.
- **`failOnPhpunitNotice` et `displayDetailsOnPhpunitNotices`** ajoutés : sans eux le filet
  du skill qualité est décoratif.
- **`symfony/process`, `symfony/filesystem` et `symfony/dom-crawler` déclarés** en
  `require-dev` — ils étaient importés par les tests sans l'être. Le constat s'est prouvé
  tout seul : le premier lancement après le passage à `Process` a échoué sur
  « Class Process not found ».
- **La fixture `Demo` porte les dix dossiers de couche**, pour que la symétrie des deux
  racines soit visible quelque part, et un test affirme que `src/Module/` est livré vide.
- **`auto_mapping: false`**, ménage de `symfony.lock` (entrée `phpstan` fantôme et fichiers
  de recette pointant la mono-racine supprimée), `.editorconfig`, marqueurs de
  `translations/`, `<extensions>` vide, `Kernel` en `final` avec `declare(strict_types=1)`.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (15), edge-case-hunter (13), verification-gap (3+2),
proglab-conformance (3). Verdicts rendus après vérification à la cited location.

| # | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|
| 1 | Un module non déclaré dans `deptrac.yaml` échappe à toute la frontière | `high` | Reproduit : `App\Module\Stock\Service\Reaching` → `App\Core\Service\Probe`, **0 violation, 0 uncovered, sortie 0**. Sa classe n'a que sa couche technique, dont le ruleset porte `+AnyRoot` qui inclut `Core` ; sans couche de racine, la règle restrictive ne s'applique jamais | patch |
| 2 | `CoreContract: ~` refuse ce que la porte doit porter | `high` | Reproduit : un DTO de `Core/Contract/` référençant `App\Core\Enum\Level` → 2 violations. Or les Boundaries disent que `Contract/` porte « interfaces et DTO », et AD-13 y fera passer les contributions des modules | patch |
| 3 | Le commentaire d'en-tête de `deptrac.yaml` affirme que deptrac nomme l'édition manquante | `medium` | Faux, prouvé par #1 : il ne signale rien du tout, pas même en `--report-uncovered` | patch |
| 4 | La liste des modules est rendue mais jamais affirmée | `medium` | `HomeControllerTest` n'affirme que 200, la route et une `h1` ; supprimer le bloc `{% if modules %}` laisse la suite verte. Le crochet `data-modules` n'est lu par aucun test. Trois couches sur quatre le remontent | patch |
| 5 | Les quatre globs de **production** ne sont observés par aucun test | `medium` | Les cinq tests de `ModuleWiringTest` tombent tous sur le miroir `when@test` ; `src/Module/` est vide, donc les entrées de production ne résolvent rien. Une faute de frappe dans l'une des quatre passe au vert | patch |
| 6 | `ModuleWiringTest` affirme `['Billing','Demo']` exactement | `medium` | Le glob de production est actif en test aussi : le premier vrai module d'un dérivé casse un test du socle — la friction exacte que le glob existe pour supprimer | patch |
| 7 | `BoundaryTest` construit `cd … && …` pour `exec()` | `medium` | Sur Windows, `cd` sans `/d` ne change pas de volume : un bac à sable sur un autre disque ferait tourner deptrac sur le vrai dépôt, et les quatre cas passeraient à vide. Aucune garde non plus sur un `exec` désactivé | patch |
| 8 | Deux `<script>` tiers depuis un CDN dans `base.html.twig` | `medium` | `cdn.jsdelivr.net/npm/idiomorph` et `frankenphp-hot-reload` : JS hors importmap, invisible pour `importmap:audit`, pour un runtime FrankenPHP que la pile n'utilise pas (Apache + PHP-FPM). Trois couches sur quatre | patch |
| 9 | `phpunit.dist.xml` sans `failOnPhpunitNotice` | `medium` | Le skill qualité mesure que `failOnNotice` seul sort en 0 sur un mock sans expectation. C'est un attribut, il ne dépend d'aucun paquet déféré à la 1.2 | patch |
| 10 | `src/Module/` n'expose aucun squelette de couches | `medium` | Le critère 3 dit que les deux racines portent les mêmes dix dossiers ; `src/Module/` livre un `.gitkeep`, et la fixture `Demo` n'a que 4 des 10. La symétrie n'est démontrée nulle part | patch |
| 11 | `symfony/filesystem` et `symfony/dom-crawler` importés sans être déclarés | `low` | `BoundaryTest` importe `Filesystem`, `assertSelectorCount` a besoin du crawler ; `require-dev` ne liste ni l'un ni l'autre. La tâche disait `symfony/test-pack`, absent | patch |
| 12 | `<html>` sans `lang` sur la première page livrée | `low` | Une ligne, et l'epic interdit de consigner un écart d'accessibilité en dette silencieuse. La 1.4 remplace le gabarit, pas une raison de livrer sans | patch |
| 13 | `auto_mapping: true` à côté des deux mappings explicites | `low` | Vérifié inerte : `doctrine:mapping:info` rend la même chose à `true` et à `false`. Reste un troisième mécanisme de découverte latent, contre l'argument tenu pour `routing.controllers` | patch |
| 14 | `symfony.lock` porte `phpstan/phpstan` absent de `composer.json`, et des listes de fichiers pointant `src/Controller`, `src/Entity`, `src/Repository` | `low` | `composer recipes:update` pourrait recréer la mono-racine supprimée ; l'entrée phpstan fera trébucher la 1.2 | patch |
| 15 | Ménage : `translations/.gitignore` + `.gitkeep`, stanza `compose.yaml` dans `.editorconfig`, `<extensions>` vide, `Kernel` sans `declare(strict_types=1)` ni `final` | `low` | Tous visibles au diff ; corrections directes ou suppressions | patch |
| 16 | `--report-uncovered` n'a aucun observateur automatisé | `low` | `BoundaryTest::deptrac()` ne passe pas le drapeau ; le critère de vérification est humain, sans CI pour le tenir | patch |
| 17 | La couche `Http` ne couvre que `HttpFoundation` | `low` | Réel : un service prenant `HttpClientInterface` ou `HttpKernel` n'est pas vu. Mais le collecteur vient mot pour mot du skill qualité — l'élargir est une décision de standard maison, pas de cette story | defer |
| 18 | `APP_SECRET` n'a aucun chemin de production | `medium` (non vérifié) | `.env` le laisse vide, `.env.dev` commite une valeur de dev, ni `.env.prod` ni coffre. Ce qui trancherait : la story de déploiement, qui possède le coffre de secrets Symfony (AD : « coffre en production ») | defer |
| 19 | La MIT amont exigeait la conservation de sa notice | `medium` (non vérifié) | `README.md`, `_bmad/` et `.claude/skills/` restent dérivés de l'amont et sont livrés sous une licence tous droits réservés. Ce qui trancherait : l'inventaire de ce qui reste écrit par l'auteur amont — question juridique, pas technique | defer |
| 20 | `ModuleDescriptor::translationDomain()` n'est consommé par rien | `low` | Réel : implémenté deux fois, appelé nulle part. Mais le corriger demande d'élargir la surface publique de `ModuleRegistry` — pas une correction directe | defer |
| 21 | `App\Kernel` apparaîtrait dans le rapport « uncovered » | `false` | Vérifié : zéro occurrence de `App\Kernel` dans le rapport. Il n'appartient à aucune couche, donc rien de lui n'est analysé — le rapport liste les dépendances *des classes couvertes* | rejeté |
| 22 | `APP_SHARE_DIR` n'est lu par personne | `false` | Le framework le lit : `bin/console about` affiche « Share directory ./var/share/dev » | rejeté |
| 23 | `ModuleRegistry::names()` ne détecte pas deux modules de même nom | `low` | Le correctif ajoute une branche et une exception pour un état qu'aucun test ne montre atteignable | rejeté |
| 24 | Le spec dit `in-review`, `sprint-status.yaml` dit `in-progress` | `false` | Transitoire par construction : c'est l'étape 5 du workflow qui synchronise le sprint, pas l'étape 4 | rejeté |
| 25 | `.phpunit.cache`, `.deptrac.cache` et `config/reference.php` écrivent hors de `var/` | `false` | L'invariant porte sur le dérivé **déployé** : les trois sont des artefacts d'outillage de développement, et `config/reference.php` est gardé par `kernel.debug`, donc jamais écrit en production | rejeté |

**Routage.** Aucun `intent_gap`, aucun `bad_spec` : les deux trouvailles `high` se corrigent
dans `deptrac.yaml` et ses tests, sans toucher à l'intention gelée ni à la forme du spec —
« ouvrir exactement une porte » reste le patron, il était juste mal fermé. Pas de
reprise depuis l'étape 2 : le socle est vert depuis un clone propre et juste par ailleurs,
et le re-dériver coûterait plus qu'il ne corrigerait.

## Design Notes

**Trois silences du spine, tranchés ici** — ils se déduisent du principe « mêmes couches,
mêmes noms dans les deux racines » :

- **Traductions et templates d'un module vivent dans le module**
  (`src/Module/<Nom>/{translations,templates}/`). AD-13 globe les chemins du translator sur
  `src/Module/*/` : le catalogue est donc dans le module, pas dans un dossier central qu'il
  faudrait éditer.
- **`tests/` reflète `src/`** : `tests/Core/…` porte les tests du socle. À ne pas confondre
  avec `tests/Fixtures/Module/<Nom>/`, qui n'est pas un dossier de tests mais le **code du
  module de démonstration** (décision 3), rangé là pour qu'il ne parte pas chez un client.
- **`migrations/` reste unique** à la racine — un dérivé n'a qu'une base, et scinder par
  racine casserait l'ordre d'application.

**Ce que le miroir `when@test` prouve, et ce qu'il ne prouve pas.** Il prouve qu'un module
placé sous une racine globée est découvert sans qu'aucun fichier de `config/` soit édité —
c'est le critère de la story. Il ne prouve pas que le chemin littéral `src/Module/*/` est
correct, puisque la fixture n'y vit pas. La parade est d'écrire les deux globs **dans le
même fichier, l'un sous l'autre**, où une divergence de forme se voit à l'œil. Le premier
vrai module d'un dérivé refermera l'écart.

**deptrac, forme retenue.** Les couches proglab gardent les collecteurs du skill qualité,
mais en `src/(Core|Module/[^/]+)/Controller/.*` pour s'appliquer identiquement dans les
deux racines. S'y ajoutent des couches de frontière — `Core` (tout `src/Core/` **hors**
`Contract/`), `CoreContract` (`src/Core/Contract/.*`), et une couche par module — dont le
ruleset n'accorde à un module que `CoreContract`, et à `Core` aucun module. C'est le patron
« ouvrir exactement une porte » de `deptrac.md`, appliqué à AD-2.

**Une couche par module ne suffit pas, et c'est le piège de cette forme.** Un module que
personne n'a déclaré n'a alors *que* sa couche technique, dont le ruleset porte `+AnyRoot`
— il atteint donc le socle et les autres modules sans qu'aucune règle ne s'y oppose, et
deptrac sort en 0. La frontière échoue en silence exactement là où on ne la regarde pas.
Il faut donc une couche attrape-tout `UndeclaredModule` — tous les modules, moins les
déclarés — dont le ruleset n'autorise rien : oublier une déclaration devient une erreur
bruyante au lieu d'une permission tacite. C'est vérifié par un test, pas seulement écrit.

**Un enum que traverse un contrat vit dans `Contract/`.** `CoreContract` ne dépend de rien,
y compris de `src/Core/Enum/` : un enum du socle est un interne, et une porte qui atteint
les internes n'est plus une porte. La conséquence pratique, à connaître avant d'écrire le
premier contrat, est que les enums et objets valeur publics se rangent à côté des DTO
qu'ils traversent.

## Verification

**Commands :**
- `composer install` -- attendu : installation sans conflit, aucun paquet exigeant Symfony 8
- `php bin/console about` -- attendu : Symfony 7.4.x sur PHP 8.5.x
- `vendor/bin/phpunit` -- attendu : toute la suite au vert, après avoir été vue rouge
- `vendor/bin/deptrac analyse --config-file=deptrac.yaml --report-uncovered` -- attendu :
  zéro violation, et aucune classe de `src/` dans le rapport « uncovered »

La découverte du module (route, entité, service, catalogue) est prouvée par les tests, pas
par une inspection manuelle de `debug:router` ou `doctrine:mapping:info`.
