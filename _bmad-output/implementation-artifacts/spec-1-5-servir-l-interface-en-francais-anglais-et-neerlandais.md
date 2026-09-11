---
title: 'Servir l''interface en français, anglais et néerlandais'
type: 'feature'
created: '2026-09-11'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'a1626c6f444e2178fef5a939255998ed8ae62051'
context:
  - '{project-root}/.claude/skills/symfony-proglab-doctrine/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Le socle ne parle qu'une langue, et de la plus mauvaise façon : `translations/`
est vide, `default_locale` vaut `en` alors que le français est la langue source, et les
deux textes visibles du gabarit sont écrits en dur dans le Twig. Rien ne dit quelles
langues existent, rien ne dit lesquelles sont actives, et les stories 1.6, 1.7, 1.9 et
1.10 devraient chacune inventer leur convention.

**Approach:** Poser les trois piliers de l'AD-14 et les rendre mécaniquement vérifiables :
les **langues supportées en code** (un enum ordonné `fr`, `en`, `nl`), les **langues
actives en base** (une première entité, une première migration, un service de lecture),
et **une seule résolution de locale par requête** qui alimente l'attribut `lang` du
document et le formatage des dates et des nombres. Les catalogues adoptent leur
convention définitive — par domaine, clés anglaises pointées, source française — et un
test de parité refuse toute clé qui manquerait dans l'une des trois langues.

## Boundaries & Constraints

**Always:**
- Les langues **supportées** vivent en code, les langues **actives** en base. Aucune
  variable d'environnement ne porte l'une ni l'autre (AD-14).
- `framework.enabled_locales` reste la liste complète des langues supportées : « actif »
  filtre l'**offre**, jamais la compilation des catalogues. C'est la tension que la revue
  d'architecture demandait d'écrire noir sur blanc.
- Un seul écouteur pose la locale de la requête, à une priorité supérieure à celle du
  `LocaleListener` de Symfony. Aucun autre point du socle n'appelle `setLocale()`.
- Le repli après désactivation est **calculé à la lecture**, jamais écrit : désactiver une
  langue ne réécrit aucune ligne. L'ordre de repli est celui de l'enum — français,
  anglais, néerlandais.
- Aucun chemin d'URL ne gagne de préfixe de langue (AD-6).
- La version française de chaque chaîne vient du tableau *Voice and Tone* d'`EXPERIENCE.md`
  au mot près ; l'anglais et le néerlandais en sont les traductions.
- Le test d'abord, vu rouge. Première entité et première migration du dépôt : la migration
  est relue à la main après génération.

**Never:**
- Aucun écran de gestion des langues, aucun sélecteur `?lang=` rendu : ce sont les stories
  2.9 et 1.6. Cette story livre le **mécanisme** que toutes deux consommeront.
- Aucune référence à un compte utilisateur : l'entité `User` n'existe qu'à la story 1.6.
  Le barreau « langue du compte » de la chaîne de résolution est une couture nommée, pas
  du code écrit à l'avance.
- Aucun cache applicatif sur la lecture des langues actives : une requête indexée par
  requête HTTP, mémorisée dans le service. Le cache est « à la demande » et rien ne le
  déclenche ici.
- Ni `Accept-Language`, ni négociation de contenu : la chaîne est explicite et fermée.
- Aucune nouvelle catégorie de la porte de qualité — la parité des catalogues appartient
  à « Tests ».

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Comportement attendu | Gestion d'erreur |
|---|---|---|---|
| Aucune préférence | Session vide, trois langues actives | Locale `fr` — première active dans l'ordre de l'enum | N/A |
| Choix explicite | `?lang=nl`, `nl` active | Locale `nl`, mémorisée en session, `<html lang="nl">` | N/A |
| Choix inconnu | `?lang=de` | Ignoré ; la résolution continue comme si le paramètre était absent | Aucune erreur, aucune redirection |
| Choix d'une langue désactivée | `?lang=nl`, `nl` inactive | Ignoré, même traitement que ci-dessus | N/A |
| Langue désactivée pendant la session | Session porte `nl`, `nl` vient d'être désactivée | Repli sur `fr` ; la session n'est pas réécrite | N/A |
| Toutes les langues désactivées en base | Zéro ligne active | Repli sur `fr` (`default_locale`) | Aucune exception levée |
| Clé absente en néerlandais | `messages.nl.yaml` sans une clé de `messages.fr.yaml` | La porte « Tests » échoue en nommant le domaine, la locale et la clé | N/A |
| Catalogue d'un module | `src/Module/Sales/translations/sales.{fr,en,nl}.yaml` | Lu sans modifier `config/` ni `src/Core/` | N/A |
| Date et nombre rendus | Même valeur, locales `fr` puis `nl` | Sorties distinctes et correctes pour chaque locale | N/A |

## Décisions de Fabrice (2026-09-11)

- **Portée des catalogues : le mécanisme seul.** Le socle livre la convention et les
  seules chaînes qu'il rend aujourd'hui — lien d'évitement, libellé de navigation, titre
  de l'accueil. Les chaînes *Voice and Tone* des stories 1.6, 1.7, 1.9 et 1.10 arrivent
  avec l'écran qui les rend, pas avant : une chaîne que rien ne rend est une chaîne que
  rien ne vérifie.
- **La chaîne de résolution est livrée ici, et elle ferme T-28.** Un seul écouteur
  `kernel.request` : `?lang=` validé contre les langues **actives** et mémorisé en
  session → session → première langue active. La story 1.6 n'y insère qu'un barreau
  « champ du compte », entre la session et la première langue active. C'est l'AD que la
  revue d'architecture réclamait et que le spine n'a jamais écrit.
- **Le spec est gardé entier** malgré sa taille : découper une story dont les six couches
  ne livrent rien de visible séparément serait artificiel.

</frozen-after-approval>

## Code Map

- `config/packages/translation.yaml` — `default_locale: en` à passer à `fr` ; `paths` vers
  `%kernel.project_dir%/src/Module` et son miroir `when@test` sont **déjà câblés** et ne
  changent pas. `enabled_locales` est à ajouter.
- `translations/` — vide, ne contient qu'un `.gitignore`. C'est ici que naît la convention
  de nommage des catalogues du socle.
- `tests/Fixtures/Module/Demo/translations/demo.fr.yaml` — le précédent de format (YAML,
  clé `widget.index.title`). Le module de démonstration n'a que le français.
- `tests/Core/ModuleWiringTest.php:the_translation_catalogue_of_a_module_is_read` — le test
  qui tient déjà le critère « un module livre ses catalogues » ; à étendre aux trois langues.
- `templates/base.html.twig` — les deux seuls textes en dur du socle : « Aller au
  contenu » et « Navigation principale » (`{% block nav_label %}`). L'attribut `lang` lit
  déjà `app.request.locale` et convertit `_` en `-` : **rien à y changer**.
- `templates/home/index.html.twig` — `{% block title %}Accueil{% endblock %}` et le `h1`
  « Socle ERP ». Le `h1` est le nom du produit, pas une chaîne traduisible.
- `tests/Core/Template/BaseTemplateTest.php:38-50,132` — affirme « Aller au contenu » et
  « Accueil — <app_name> » sur la page rendue. Ces tests **doivent rester verts** : c'est
  la preuve que le français reste le rendu par défaut.
- `src/Core/Enum/` — vide ; couche deptrac `Enum`, atteignable par `Entity`, `Service`,
  `Repository`, `Dto`. C'est là que va l'enum des langues supportées. **Pas dans
  `Core/Contract/`** : le ruleset `CoreContract: ~` interdit à la porte publique de
  dépendre de quoi que ce soit, et rien dans cette story ne traverse un contrat de module.
- `src/Core/Entity/`, `src/Core/Repository/`, `src/Core/Service/`, `src/Core/EventListener/`
  — vides. `EventListener/` n'a **aucune couche technique** deptrac (comme `Command/` et
  `Twig/`) : seule sa couche de racine `Core` s'y applique, donc l'écouteur peut voir HTTP
  et les services.
- `config/packages/doctrine.yaml` — mapping `Core` sur `src/Core/Entity`, stratégie de
  nommage `underscore`, `auto_mapping: false`. Rien à modifier.
- `migrations/` — **vide**. Cette story écrit la première migration du dépôt.
  `make test` joue déjà `doctrine:migrations:migrate --allow-no-migration` avant la suite.
- `composer.json` — ni `ext-intl`, ni `twig/intl-extra`. `twig/extra-bundle` est présent et
  enregistre l'extension Intl dès que `twig/intl-extra` est là.
- `.github/workflows/ci.yml` jobs « Code style » et « Layer contract » — les **deux seuls**
  sans `extensions: … intl`. Déclarer `ext-intl` dans `composer.json` casse leur
  `composer install` tant que ces deux lignes ne sont pas alignées.
- `tests/Core/Quality/QualityGateParityTest.php` — parité catégories ↔ cibles ↔ jobs.
  Aucune catégorie n'est ajoutée : ce fichier ne bouge pas.
- `tests/Core/BoundaryTest.php` — le style de test du dépôt : `#[Test]`, `#[DataProvider]`,
  providers `Generator` à clés françaises, message d'échec qui nomme le fichier fautif.

## Tasks & Acceptance

**Execution:**
- [x] `composer.json` + `.github/workflows/ci.yml` -- ajouter `ext-intl` et
      `twig/intl-extra`, et aligner les deux jobs de CI qui n'installent pas `intl` --
      sans quoi le formatage localisé des dates et des nombres n'existe pas, et la CI casse
      au premier `composer install`.
- [x] `src/Core/Enum/SupportedLocale.php` -- enum de chaînes `Fr`/`En`/`Nl` dans l'ordre de
      repli, avec une méthode qui rend la liste des codes -- c'est la seule déclaration des
      langues supportées, et la source de l'ordre de repli.
- [x] `config/packages/translation.yaml` -- `default_locale: fr`, `enabled_locales` =
      les trois codes -- le français devient la langue source ; « actif » filtre l'offre,
      pas la compilation.
- [x] `src/Core/Entity/Language.php` + `src/Core/Repository/LanguageRepository.php` --
      entité au format maker (`id`, `code` unique porté par l'enum, `enabled`) et la seule
      requête dont le socle a besoin : les codes actifs -- les langues actives sont en base.
- [x] `migrations/VersionXXXX.php` -- créer la table et y insérer les trois langues, les
      trois actives -- « les trois sont actives à l'installation », et `make test` joue déjà
      les migrations avant la suite.
- [x] `src/Core/Service/ActiveLocales.php` -- service `final readonly` qui rend la liste des
      `SupportedLocale` actives, mémorisée pour la requête, et le repli quand elle est vide
      -- le repli est calculé, jamais écrit.
- [x] `src/Core/EventListener/LocaleListener.php` -- écouteur `kernel.request` unique,
      priorité 20, chaîne `?lang=` (validé contre les langues actives, mémorisé en session)
      → session → première langue active -- une seule autorité sur la locale de la requête.
- [x] `translations/messages.{fr,en,nl}.yaml` -- les clés des chaînes rendues aujourd'hui,
      en clés anglaises pointées -- la convention que toutes les stories suivantes copient.
- [x] `templates/base.html.twig` + `templates/home/index.html.twig` -- remplacer les textes
      en dur par leurs clés -- dernier texte non traduit du socle.
- [x] `tests/Fixtures/Module/Demo/translations/demo.{en,nl}.yaml` +
      `tests/Core/ModuleWiringTest.php` -- étendre le module de démonstration aux trois
      langues -- le critère « un module livre ses catalogues » vaut pour les trois.
- [x] `tests/Core/Translation/CatalogParityTest.php` -- parcourt `translations/` et
      `(src|tests/Fixtures)/Module/*/translations/`, exige les mêmes clés non vides dans les
      trois locales, et exige que l'enum, `enabled_locales` et les lignes semées coïncident
      -- la seule preuve mécanique du premier critère.
- [x] `tests/Core/Translation/LocaleResolutionTest.php` -- couvre chaque ligne de la matrice
      d'edge cases, y compris `<html lang>` sur une page rendue.
- [x] `tests/Core/Translation/IntlFormattingTest.php` -- rend une date et un nombre sous les
      trois locales et exige trois sorties correctes et distinctes là où elles le sont.

**Acceptance Criteria:**
- Given un clone frais migré, when on demande les langues actives, then elles viennent de
  la table et les trois sont actives.
- Given un chemin du socle, when on change de langue, then le chemin ne change pas et
  aucune route ne gagne de préfixe de locale.
- Given la porte de qualité, when une clé manque dans une des trois langues, then « Tests »
  échoue en nommant le domaine, la locale et la clé.

## Implementation Notes

**Trois écarts assumés par rapport à la lettre de la spec ou du standard.**

1. **`ActiveLocales` n'est pas `readonly`.** La spec demande un `final readonly` dont le
   résultat est « mémorisé pour la requête ». Les deux sont incompatibles en PHP : une
   classe `readonly` n'assigne aucune propriété après construction, donc aucune
   mémorisation n'y est possible. La mémorisation l'emporte — c'est elle qui tient « une
   requête indexée par requête HTTP ». La dépendance injectée reste `private readonly`, et
   le pourquoi est écrit dans la classe.

2. **`Language` n'a pas de `repositoryClass:`.** Le format maker l'écrit, et
   `deptrac.yaml` le refuse : le ruleset `Entity` n'autorise que `Entity`,
   `DoctrineMapping` et `Enum`. Ouvrir `Entity → Repository` pour un argument de mapping
   autoriserait du même coup une entité à *appeler* un repository — exactement ce que le
   contrat de couches de l'AD-7 existe pour interdire. Le contrat l'emporte sur la forme
   du maker. Conséquence : `$entityManager->getRepository(Language::class)` rend un
   `EntityRepository` générique ; `LanguageRepository` s'obtient par injection de type.
   C'est la première entité du dépôt, donc c'est le précédent.

3. **Catalogues en YAML et non en XLIFF.** `symfony-proglab-frontend` écrit « format
   XLIFF » ; les Design Notes de cette spec décident YAML, avec leur raison. La spec fait
   foi sur le *quoi*.

**Deux conséquences que la spec n'avait pas anticipées, traitées ici.**

- **Le job de CI « Accessibility » a désormais besoin d'une base de données.** La
  résolution de locale lit les langues actives à chaque requête, donc *toute* page rendue
  touche la base — ce que le commentaire du job excluait explicitement, en promettant de
  prendre le service MySQL de « Tests » le jour où ce serait le cas. La promesse est
  tenue : même image, mêmes identifiants, mêmes migrations. La cible `make a11y` gagne la
  même ligne, pour que `make a11y` seul sur un clone frais ne soit pas rouge.
- **`lint:yaml` couvre maintenant `translations/`.** Les catalogues sont du YAML que rien
  de la porte ne lisait ; la commande de la section Verification le disait déjà. Les deux
  façades ont été alignées ensemble.

**Ce que la porte n'a pas gagné.** Aucune catégorie n'a été ajoutée : la parité des
catalogues est un test de « Tests », et `QualityGateParityTest` n'a pas bougé.

**Un piège rencontré, utile à qui reprendra.** Le cache de conteneur de l'environnement de
test ne se régénère pas toujours quand seule la valeur d'une constante de classe lue par
un attribut change (ici `LocaleListener::PRIORITY`). Une vérification par mutation sur ce
point exige un `cache:clear --env=test` entre les deux exécutions, sinon le test reste
vert sur du code cassé.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (15), edge-case-hunter (12), verification-gap
(2 pré-vérifiées + 1 autre), proglab-conformance (1 bloquant, 3 écarts déclarés). Les
doublons entre couches sont fusionnés sur cause commune. Chaque trouvaille non
pré-vérifiée a été rouverte à l'emplacement cité avant verdict.

| # | Couche | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| 1 | blind + edge + vgap | `?lang[]=nl` renvoie 400 au lieu d'être ignoré : `InputBag::get()` lève `BadRequestException` sur une valeur non scalaire | `medium` | Vérifié `vendor/symfony/http-foundation/InputBag.php:44-50` — `getString()` délègue à `get()`, qui lève. La ligne « Choix inconnu » de la matrice promet « aucune erreur, aucune redirection », et le seul test de cette ligne passe un scalaire | patch |
| 2 | edge | La mesure de distinction d'`IntlFormattingTest` ne peut jamais échouer : `array_diff($v, array_unique($v))` vaut toujours `[]` | `medium` | Vérifié l. 101-105 et 131-135 : `array_diff` compare des valeurs, et `array_unique` conserve une occurrence de chacune — toute valeur du premier tableau est dans le second. L'assertion écrite pour attraper « deux locales rendent pareil » est morte ; seule la comparaison à ICU tient encore ce point | patch |
| 3 | vgap | `INVOCATIONS['Accessibility']` n'épingle pas l'étape de migrations dont la catégorie dépend désormais | `medium` | Pré-vérifié, et relu `tests/Core/Quality/QualityGateParityTest.php:140-146` : l'entrée ne liste que `tailwind:build`, `phpunit --testsuite Accessibility` et `node --test`. Retirer la ligne 98 du `Makefile` laisse la porte verte et rend `make a11y` rouge sur un clone frais | patch |
| 4 | edge | Le fragment `lint:yaml` d'`INVOCATIONS` est écrit sans ses arguments : perdre `translations/` d'une seule façade ne casse rien | `medium` | Vérifié l. 118 : `'lint:yaml'` nu. Les catalogues cesseraient d'être lintés d'un côté sans qu'aucune parité ne bouge | patch |
| 5 | vgap | `TestDatabaseEngineTest` n'épingle que le job `tests` : le nouveau service MySQL du job « Accessibility » n'est tenu par rien | `medium` | Pré-vérifié : passer ce job en `mysql:8.0` et `serverVersion=8.0` laisse les quatre tests verts. C'est l'asymétrie exacte que le plancher 8.4 existe pour refuser | patch |
| 6 | conformance | La mémorisation d'`ActiveLocales` — le seul motif de l'écart au `final readonly` — n'est couverte par aucun test | `medium` | Vérifié : aucun test ne compte les appels à `findActiveCodes()`. Supprimer le `??=` laisse toute la suite verte, et l'exception à la règle 5 serait payée pour rien | patch |
| 7 | blind | `ActiveLocales` mémorise sans jamais être réinitialisable : le docblock promet « sa mémoire meurt avec la requête », ce qui n'est vrai que sous PHP-FPM | `medium` | Vrai : la classe n'implémente pas `ResetInterface` et ne porte pas `kernel.reset`, donc le `services_resetter` n'a rien à appeler. Le worker Messenger de la story 1.8 servirait une liste de langues périmée indéfiniment | patch |
| 8 | blind | `nothing_else_in_the_socle_sets_a_locale()` exempte le répertoire `Core/EventListener` entier, pas le seul fichier autorisé | `medium` | Vérifié l. 245 : `->notPath('Core/EventListener')`. Un second écouteur posé à côté de `LocaleListener.php` échappe à la règle que ce test existe pour tenir | patch |
| 9 | blind + edge | `CatalogParityTest` balaie moins que le translator : `glob('*.yaml')` sur un seul niveau, quand le Finder de Symfony descend récursivement dans `src/Module` et charge aussi `.yml` et `.xlf` | `medium` | Vérifié l. 253-270. Un module livrant `Resources/translations/sales.fr.yml` est compilé, servi, et jamais contrôlé — le trou exactement là où le premier critère de la story se referme | patch |
| 10 | blind | Le message d'échec de la comparaison de priorité s'inverse en cours de phrase | `low` | Vérifié l. 232 : « passe après celui de Symfony » décrit bien la condition d'échec, mais « ce n'est plus lui qui a le dernier mot » dit le contraire de ce qui se passe alors. Correction directe d'une chaîne | patch |
| 11 | blind | `src/Core/Enum/` n'a pas été ajouté à la liste d'exclusion d'`App\Core\` dans `config/services.yaml` | `low` | Vérifié `config/services.yaml:34` : `{Contract,Dto,Entity}` — la liste des répertoires qui portent des données et non des services. `Enum/` vient d'en devenir un. Correction directe d'une liste | patch |
| 12 | blind | Trois copies privées de `projectDir()` coexistent avec `GateFiles::projectDir()`, qui est `public static` et déjà importé dans le même répertoire | `low` | Vérifié : `CatalogParityTest` et `LocaleResolutionTest` la redéclarent, `IntlFormattingTest` importe déjà `GateFiles`. Correction par suppression | patch |
| 13 | blind + edge | L'écriture de session n'a aucune garde, là où la lecture en a deux | `false` | `framework.session: true` et le `SessionListener` de Symfony (priorité 128) posent la fabrique de session sur **toute** requête principale, et l'écouteur sort tôt sur les sous-requêtes. `getSession()` ne peut pas lever ici | rejeté |
| 14 | edge | Une ligne portant un code absent de l'enum fait lever `ValueError` à l'hydratation | `false` | L'enum est le seul écrivain de cette colonne, et le retrait d'un cas est documenté dans l'entité comme une migration. Un échec bruyant sur un état dont rien ne montre qu'il est atteignable est le comportement correct | rejeté |
| 15 | edge | Base injoignable ou table absente : toute page part en 500 | `low` | Vrai, et c'est le cas de toute application adossée à une base. `make test`, `make a11y` et le déploiement migrent avant de servir ; un `try/catch` masquerait une installation cassée | rejeté |
| 16 | edge | `no_route_carries_a_locale_prefix()` ne regarde pas `_locale` posé en **défaut** de route | `low` | Vrai, et aucune route du socle n'en porte. Le correctif ajoute une branche à un test pour un état que rien ne rend atteignable | rejeté |
| 17 | edge | Le fuseau horaire du runner peut faire diverger le rendu Twig de la référence ICU | `false` | Vérifié l. 176 et 189 : `Europe/Brussels` est épinglé des deux côtés, sur le `DateTimeImmutable` **et** sur l'`IntlDateFormatter` | rejeté |
| 18 | edge | La ligne de tâche dit `final readonly` alors que la classe ne l'est pas | `low` | Vrai et déjà consigné dans les Implementation Notes. Le correctif consisterait à éditer le spec de ce build | rejeté |
| 19 | edge | La couverture de la matrice est répartie sur quatre fichiers, pas concentrée dans `LocaleResolutionTest` | `false` | Les neuf lignes sont couvertes et passent — audit fait à l'étape 3. Une répartition n'est pas un défaut | rejeté |
| 20 | blind | La parité vérifie la présence et le non-vide, jamais qu'une valeur a réellement été traduite | `low` | Vrai. Mais le correctif demande une heuristique plus une liste d'exceptions, et `home.index.title: Home` est légitimement identique en anglais et en néerlandais | rejeté |
| 21 | blind | Le test de parité des extensions de CI code `intl` en dur au lieu de lire les `ext-*` de `composer.json`, et il vit dans une classe qui parle d'ICU | `low` | Vrai. Généraliser ajoute de la logique à un test, et le placement est cosmétique | rejeté |
| 22 | blind | Le job « Accessibility » reprend le service MySQL de « Tests » sans son garde-fou `debug:config dama_doctrine_test` ni le commentaire sur `dbname_suffix` | `low` | Vrai. Le garde-fou existe parce qu'une suite qui **écrit** ne s'annonce pas ; le plancher d'accessibilité ne rend que des GET en lecture. Le correctif ajoute une étape plutôt qu'il n'en corrige une | rejeté |
| 23 | blind | Pas d'en-tête `Content-Language` sur la réponse | `low` | Vrai. Aucun critère ne le demande, `<html lang>` est ce que WCAG 3.1.1 exige, et l'activer ajoute un comportement que le spec n'a pas prévu | rejeté |
| 24 | blind | `sprint-status.yaml` dit `in-progress` pendant que le spec dit `in-review`, et la 1.4 y est encore à `review` | `low` | Vrai : la synchronisation du suivi appartient à l'étape de présentation, pas à la relecture. Même verdict qu'aux stories 1.3 et 1.4 | rejeté |
| 25 | blind | Le travail de la 1.5 atterrit sur la branche de la story 1.4 | `false` | Réfuté : `git branch --show-current` rend `story/1-5-interface-trilingue`, créée sur `a1626c6` avant toute écriture | rejeté |
| 26 | blind | `doctrine:schema:validate --skip-sync` ne compare jamais la migration écrite à la main au mapping | `low` | Vrai, et antérieur à cette story : `--skip-sync` est là depuis la 1.2 à cause de l'entité de fixture `demo_widget`, qui n'a pas de migration. La couche conformité a vérifié à la main que le `CREATE TABLE` correspond à ce que DBAL 4 produit | defer |
| 27 | blind | `<h1>Socle ERP</h1>` est codé en dur pendant que le `<title>` compose `app_name` : un dérivé renommé affiche deux noms différents | `low` | Vrai, et antérieur à cette story. Le nom du produit appartient à la surface de rebranding (logo, nom, favicon), que les stories 1.11 et 1.12 possèdent — même verdict que le favicon reporté par la story 1.4 | defer |

Aucune trouvaille ne route en `intent_gap` ni en `bad_spec` : aucune ne remonte au bloc
gelé, et aucune ne demande de redériver le code. Douze correctifs, deux reports, treize
rejets.

## Design Notes

**Pourquoi les catalogues sont en YAML et non en XLIFF.** Le dépôt a déjà un précédent
(`demo.fr.yaml`), personne n'outille de traduction externe ici, et la source est écrite à
la main depuis un tableau de ton. XLIFF paierait un format d'échange que rien n'échange.

**ICU ou pas.** Les chaînes de l'Epic 2 et de l'Epic 5 du tableau *Voice and Tone*
contiennent de vrais pluriels (« il vous en reste 4 », « 2 utilisateurs l'utilisent »). Le
suffixe `+intl-icu` d'un domaine n'est pas rétro-compatible : déplacer une clé d'un domaine
simple vers un domaine ICU change ses règles d'échappement. Poser `+intl-icu` dès la
première ligne évite une migration de catalogue plus tard, et `ext-intl` est de toute façon
requis par le formatage des dates. Mais la portée retenue est le mécanisme seul : trois
clés, aucune variable, aucun pluriel. On reste donc sur le domaine simple `messages`, et
la première story qui a besoin d'un pluriel ouvre son propre domaine `+intl-icu` — un
domaine ICU se pose à côté, il ne se substitue pas.

**Pourquoi `ext-intl` et non le repli de `symfony/intl`.** `symfony/intl` sans `ext-intl`
ne sait formater qu'en anglais : les dates néerlandaises sortiraient en anglais, en
silence, et le critère serait vert. La CI installe déjà `intl` sur quatre jobs sur six.

**Ce que cette story ne peut pas encore prouver.** Aucun écran ne rend de date ni de
sélecteur de langue. Le formatage est donc vérifié sur un template rendu en test, pas sur
une page du socle — l'écart est réel et doit être écrit dans le test lui-même.

## Verification

**Commands:**
- `php bin/console doctrine:migrations:migrate --no-interaction` -- la table `language` est
  créée et porte trois lignes actives
- `php bin/console lint:yaml translations/ config/` -- valide
- `php bin/console debug:translation fr --domain=messages` -- aucune clé manquante ni inutilisée
- `php bin/console debug:translation nl --domain=messages` -- idem
- `vendor/bin/phpunit` -- suite au vert, après avoir été vue rouge
- `make qa` -- les six catégories passent

**Manual checks:**
- `/?lang=nl` puis `/` sans paramètre : la seconde page reste en néerlandais (session)
- Le code source de `/` porte `<html lang="fr">` par défaut, `lang="nl"` après `?lang=nl`
