# Le saut de version majeure

Une procédure ordonnée. L'ordre est toute la valeur : chaque étape te laisse avec une
application qui démarre, et un échec à l'étape *n* est causé par l'étape *n*, pas par
les six choses que tu as changées en même temps.

Fais-le sur une branche, un commit par étape. Si une étape ne peut pas être committée
seule, c'est qu'elle est trop grosse.

## 0. Savoir d'où tu pars

```bash
php bin/console about
```

Si `Long-Term Support` dit `No` et que tu prévois un saut de version majeure,
**arrête-toi et va d'abord à la dernière mineure de la majeure en cours**. C'est
la version qui porte les notifications de dépréciation pour tout ce que la prochaine
majeure supprime ; sauter depuis le milieu d'une majeure veut dire rencontrer ces
suppressions comme des erreurs fatales sans rien entre les deux pour faire une
recherche par dichotomie.

Aller de 6.2 à 7.0 directement, c'est une réécriture de taille inconnue. Aller de 6.2 à
6.4 puis à 7.0, ce sont deux petits changements et une liste de dépréciations. Même
destination, risque incomparable.

## 1. Vérifie les dépendances avant de toucher à quoi que ce soit

Un seul bundle sans release compatible bloque tout le saut, et le découvrir le
deuxième jour est la manière classique de perdre une semaine.

```bash
composer why-not symfony/framework-bundle 9.0
```

```
Package "symfony/framework-bundle" could not be found with constraint "9.0", results below will most likely be incomplete.
__root__                            dev-main requires symfony/framework-bundle (8.1.*)
doctrine/doctrine-bundle            3.3.1    requires symfony/framework-bundle (^6.4 || ^7.0 || ^8.0)
symfony/web-profiler-bundle         v8.1.5   requires symfony/framework-bundle (^7.4|^8.0)
twig/extra-bundle                   v3.24.0  requires symfony/framework-bundle (^5.4|^6.4|^7.0|^8.0)
```

Lis ça comme une matrice de compatibilité. Chaque ligne dont la contrainte n'inclut
pas ta cible est un paquet qui doit bouger en premier — et `__root__` est ton
propre `composer.json`, ce qui est l'étape 3. L'avertissement sur la première ligne est
normal quand la version cible n'existe pas encore ; les lignes en dessous restent les
contraintes dont tu as besoin.

Les bundles tiers sont en retard sur le framework de plusieurs semaines ou mois.
Vérifie ceux qui comptent avant de t'engager sur une date ; pour chaque blocage, la
question est de savoir si une montée de version existe, est en cours, ou n'arrivera
jamais. `composer why <package>` te dit qui l'a fait entrer, ce qui révèle parfois
que rien de ce que tu as écrit n'en dépend réellement.

`composer outdated --direct` donne une image plus petite et plus actionnable : tes
propres requirements seulement, pas les cent paquets transitifs que tu n'as jamais
choisis.

## 2. Deviens vert sur la dernière mineure

Non négociable, et c'est plus que « les tests passent » :

- la suite de tests est verte, dépréciations comprises (voir `deprecations.md`) ;
- `composer audit` ne rapporte rien ;
- `composer recipes -o` ne rapporte rien (voir `recipes.md`) ;
- `php bin/console --env=prod debug:container --deprecations` est propre.

Ce dernier point attrape les dépréciations au niveau configuration qu'aucun test
n'exécute et qui se transforment en `InvalidConfigurationException` dès que la majeure
arrive.

Lance ensuite la suite une fois avec `ignoreIndirectDeprecations="false"` dans
`phpunit.dist.xml`. Ne committe pas ce changement — c'est un audit ponctuel, et ce
qu'il affiche est la préparation de tes dépendances, pas ton propre travail.

## 3. Fais évoluer les contraintes

Deux endroits, et rater le premier est la raison pour laquelle « j'ai lancé
`composer update` et rien ne s'est passé » est un signalement si fréquent.

**`extra.symfony.require`.** Flex installe un filtre de paquets qui masque au resolver
toute release `symfony/*` en dehors de cette contrainte. Tant qu'elle dit `8.1.*`,
Composer ne considérera même pas la 8.2.

```json
"extra": {
    "symfony": {
        "allow-contrib": false,
        "require": "8.1.*"
    }
}
```

**Les lignes `symfony/*` elles-mêmes**, que Flex a écrites épinglées sur la même
mineure :

```json
"symfony/asset": "8.1.*",
"symfony/console": "8.1.*",
"symfony/framework-bundle": "8.1.*",
```

Les deux bougent ensemble. Tout ce que Flex n'a pas écrit — `symfony/flex` lui-même
(`^2`), `symfony/ux-*` (`^3.4`), `doctrine/*`, `twig/*` — a son propre cycle de release
et ne fait pas partie de cette modification.

### L'essayer sans s'y engager

`SYMFONY_REQUIRE` surcharge `extra.symfony.require` depuis l'environnement, ce qui
permet de tester une version sans rien éditer — et comment une matrice CI teste la
mineure suivante avant qu'elle soit adoptée :

```bash
SYMFONY_REQUIRE=8.2.* composer update "symfony/*" --dry-run --no-scripts
```

La première ligne de la sortie confirme que le filtre a pris effet, en répétant la
contrainte que tu as passée :

```
Restricting packages listed in "symfony/symfony" to "8.2.*"
```

Si cette ligne est absente, la variable n'a pas atteint Composer et tu ne testes
rien.

Si tu n'as pas aussi relâché les contraintes `8.1.*` par paquet, ça rapporte des
conflits plutôt qu'un plan — les requirements racine et le filtre se contredisent.
C'est instructif en soi, et c'est pourquoi les deux modifications ci-dessus vont
ensemble.

## 4. Résous

```bash
composer update "symfony/*" --with-all-dependencies
```

`--with-all-dependencies` (`-W`) est presque toujours nécessaire : un saut de majeure
entraîne des paquets transitifs verrouillés sur des versions que la nouvelle majeure
rejette, et sans ce flag Composer refuse et te le dit.

Lis ce qu'il refuse. La sortie de conflit de Composer nomme le paquet exact et la
contrainte ; c'est fastidieux mais jamais vague :

```
- symfony/form[v8.1.0, ..., v8.1.5] require symfony/var-exporter ^8.1 -> found
  symfony/var-exporter[...] but these were not loaded, likely because it conflicts
  with another require.
```

Résiste à `--ignore-platform-reqs` et à l'édition manuelle de `composer.lock`. Les
deux produisent une installation qui résout puis échoue à l'exécution, ce qui est
strictement pire que ne pas résoudre du tout.

## 5. Lance tout

Dans cet ordre, parce que chaque étape répond à une question différente :

1. `php bin/console cache:clear` — le conteneur compile-t-il encore ? La plupart des
   suppressions apparaissent ici, avant qu'un seul test ne tourne.
2. `php bin/console --env=prod debug:container --deprecations` — la configuration.
3. La suite de tests.
4. `composer recipes -o`, puis mets à jour ce qu'elle nomme (`recipes.md`). Les
   dossiers de recipe de la nouvelle majeure sont souvent l'énoncé le plus clair de ce
   qui a changé.
5. L'application elle-même, dans un navigateur, sur les chemins que les tests ne
   couvrent pas.

## 6. Rector, pour la partie mécanique

```bash
composer require --dev rector/rector rector/rector-symfony rector/rector-doctrine rector/rector-phpunit
vendor/bin/rector process --dry-run --no-progress-bar
```

`--dry-run` sort avec le code `2` quand des changements seraient appliqués et affiche
un diff par fichier avec les règles qui l'ont produit. Retire `--dry-run` pour
appliquer.

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    // Un flag à la fois — voir « La discipline » ci-dessous. Lance d'abord avec
    // symfony seul, relis, committe ; puis décommente doctrine ; puis phpunit.
    ->withComposerBased(symfony: true /* , doctrine: true, phpunit: true */);
```

`withComposerBased()` lie chaque règle à la version du paquet réellement installé, ce
qui explique qu'il n'y ait plus de sets numérotés par version : `SymfonySetList`
contient `COMPOSER_BASED`, `SYMFONY_CODE_QUALITY`, `SYMFONY_CONSTRUCTOR_INJECTION`,
`CONFIGS` et un `ANNOTATIONS_TO_ATTRIBUTES` déprécié — et rien de numéroté par version.
Tout exemple citant `SymfonySetList::SYMFONY_64` date d'avant ce changement et
provoquera une erreur fatale sur une constante non définie.

**La discipline**, et elle n'est pas optionnelle :

- **Un seul sujet par exécution, un commit chacun.** Active `symfony: true` seul,
  relis, committe. Puis `doctrine`. Puis `phpunit`. Un commit mélangeant trois rule
  sets sur deux cents fichiers ne peut être ni relu ni partiellement annulé.
- **Jamais à l'aveugle.** `--dry-run` d'abord, toujours. Rector réécrit ce qu'il peut
  prouver ; il ne connaît pas ton intention. Il convertira correctement un docblock
  en attribut et ne remarquera pas que la méthode ne devrait pas exister.
- **Garde les flags de confort hors des commits de montée de version.** Ajouter
  `->withImportNames()` à la config ci-dessus a transformé un diff d'un fichier en diff
  de quatre fichiers, en réécrivant des noms de classe pleinement qualifiés dans des
  tests qui n'avaient aucun changement lié à la montée de version. Utile ; commit
  séparé.
- **Le formatage est le travail de php-cs-fixer.** Rector émet des noms pleinement
  qualifiés dans les attributs générés (`#[\PHPUnit\Framework\Attributes\Test]`).
  Lance l'outil de style après coup plutôt que de demander à Rector de l'être.
- **La suite de tests est le critère d'acceptation.** Verte avant, verte après, à
  chaque exécution.

Ce que Rector fait mal : tout ce qui demande du jugement. Restructurer un contrôleur,
choisir entre un Voter et un rôle, décider qu'une entité ne devrait pas avoir de
setters. N'attends pas de lui qu'il produise l'architecture décrite dans
`symfony-proglab-architecture` ; attends de lui qu'il retire la corvée pour qu'il te
reste de l'attention pour ce qui compte vraiment.

## Quand une dépendance n'a nulle part où aller

Tôt ou tard, l'étape 1 dit non. `composer audit --abandoned=report` nomme les paquets
dont les mainteneurs se sont déclarés terminés ; `--abandoned=fail` transforme ça en
échec de build une fois que tu as décidé que ça te concerne.

Dans l'ordre approximatif de l'avenir que ça t'achète :

**Le remplacer par le framework.** Vérifie ça en premier, et sérieusement. Symfony a
absorbé une décennie de bundles — pagination, authentification par token façon JWT,
limitation de débit, planification, UUID, clients HTTP, horloges simulées. Une
dépendance ajoutée en 2019 pour combler un manque comble souvent un manque qui n'existe
plus. C'est la seule option qui te laisse avec moins de code qu'au départ.

**Le remplacer par une alternative maintenue.** Du vrai travail, borné, et qui se
termine.

**Le forker.** Parfois la réponse honnête pour un petit paquet : le vendoriser dans le
projet, supprimer ce que tu n'utilises pas, le posséder. Dis à voix haute que tu
le maintiens désormais — un fork que personne n'a accepté de maintenir est une
dépendance abandonnée avec des étapes en plus.

**Épingler la majeure et arrêter.** Tu n'as rien résolu ; tu as choisi une date
à laquelle ça devient urgent. Légitime seulement quand cette date est écrite quelque
part et que le paquet est réellement périphérique.

Quoi que tu choisisses, le mode d'échec est le même : personne ne décide, la montée
de version est repoussée, et deux ans plus tard le saut fait trois majeures de large.
**Une dépendance bloquée est une décision, pas un délai.**

## Rollback

La branche est le rollback, ce qui est la raison du commit unique par étape. Si la
montée de version atteint la production et échoue là-bas, la séquence est celle de
`symfony-proglab-deployment` — et il vaut la peine de vérifier avant de commencer que
faire un rollback de l'application fait aussi un rollback de ce que les migrations ont
fait, parce que cette partie-là n'est pas gratuite (`symfony-proglab-doctrine`).
