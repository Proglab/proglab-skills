---
name: symfony-proglab-upgrade
description: >-
  Garder une application Symfony vivante à travers les versions : le cycle de vie des
  releases et ce que « pas de rupture de compatibilité dans une mineure » garantit
  réellement, trier les dépréciations entre ton propre code et tes dépendances,
  rejouer les recipes Flex qui ont dérivé depuis la création du projet (composer
  recipes), Rector pour les réécritures mécaniques, et la procédure ordonnée pour un
  saut de version majeure. Utilise ce skill dès que quelqu'un demande de monter vers
  Symfony 8 ou 7, de faire évoluer le framework, de mettre à jour les dépendances, de
  mettre à jour Doctrine ou PHPUnit, ou dit « notre Symfony est vieux », « on est
  encore en 6.4 », « c'est quoi ces avertissements de dépréciation », « peut-on mettre
  à jour Doctrine », « ce bundle n'est pas compatible », « composer refuse de mettre à
  jour », « est-ce sûr de monter de version », « combien de temps cette version est-elle
  supportée », « combien de travail pour rattraper le retard ». Utilise-le aussi quand
  un projet est resté à l'abandon pendant un an, quand quelqu'un veut lancer Rector,
  quand `composer recipes` signale quelque chose d'obsolète, et quand une dépendance
  s'avère abandonnée ou n'a aucune version compatible avec la cible.
---

# Montée de version

> **Niveau : à la demande** — à lire quand une dépréciation, une mise à jour de recipe ou une nouvelle version majeure est sur la table. La règle qui prime toujours : l'ordre « dernière mineure d'abord ».

Le coût récurrent que personne ne budgète, et il est invisible jusqu'à ce qu'il faille le payer.

Une équipe qui ignore les dépréciations pendant deux ans ne sent rien. Puis 7.4 → 8.0
devient un projet de trois mois au lieu d'un après-midi, parce que le contrat de
Symfony est que **tout ce qu'il faut corriger est annoncé sur la dernière mineure de la
version majeure en cours**. Ignore les annonces et tu rencontres tout d'un coup,
sous forme d'erreurs fatales, sans application fonctionnelle entre les deux pour te
dire quel changement a cassé quoi.

## Où tu en es

```bash
php bin/console about
```

```
  Version              v8.1.5
  Long-Term Support    No
  End of maintenance   01/2027 (in +154 days)
  End of life          01/2027 (in +154 days)
```

Ces dates sont compilées dans `Symfony\Component\HttpKernel\Kernel` sous forme de
`END_OF_MAINTENANCE` et `END_OF_LIFE` : ce sont des faits sur le code installé, pas une
affirmation de ce skill. Lis-les plutôt que de faire confiance à un tableau, celui-ci
y compris.

Sur une mineure standard, les deux dates sont identiques — les correctifs de bugs et de
sécurité s'arrêtent le même jour. Seule une LTS les sépare, et de plusieurs années.

Regarde ensuite ce qui te contraint :

```bash
php -r '$j = json_decode(file_get_contents("composer.json"), true); echo $j["extra"]["symfony"]["require"], PHP_EOL;'
```

`extra.symfony.require` est un réglage Flex, et c'est la raison pour laquelle un
`composer update` sur un projet laissé à l'abandon répond « nothing to update » alors
que trois mineures sont sorties depuis. Flex installe un `PackageFilter` qui masque au
resolver toute release `symfony/*` en dehors de cette contrainte. La variable
d'environnement `SYMFONY_REQUIRE` la surcharge, ce qui permet d'essayer la mineure
suivante sans toucher à `composer.json` (voir `references/major-upgrade.md`).

## Le cycle de vie, et pourquoi la dernière mineure compte

- Une **mineure** tous les six mois. Elle ajoute des fonctionnalités et des
  *dépréciations*, et elle ne casse rien : le code qui tourne sur x.0 tourne sur x.4.
- Une **majeure** tous les deux ans. Elle retire ce qui a été déprécié, et rien d'autre
  — c'est toute la promesse.
- La **dernière mineure d'une majeure** (x.4) est une LTS et porte toutes les
  notifications de dépréciation dont tu auras besoin. Tourner en x.4 avec zéro
  dépréciation, c'est quasiment la définition d'être prêt pour la (x+1).0.

La conséquence pratique, et la raison d'être de ce skill : **on ne saute jamais de x.2
à (x+1).0.** On va d'abord en x.4, on y devient vert, on y nettoie les dépréciations —
sur une application qui démarre encore et dont les tests tournent encore — et
seulement ensuite on bascule la majeure. Chacune de ces étapes est réversible seule.
Un saut unique ne l'est pas.

« Pas de rupture de compatibilité dans une mineure » ne veut pas dire « rien ne
change ». Une mineure peut changer une recipe, changer une valeur par défaut dans un
fichier de configuration qu'elle ne possède plus, ou déprécier l'option dont dépend
ton `services.yaml`. Cela veut dire que ton *code* continue de fonctionner, pas que
ton projet est identique à un projet fraîchement généré. C'est cet écart que mesure
`composer recipes`.

## Dépréciations : à qui le problème ?

Une dépréciation que tu peux corriger et une dépréciation qu'il faut seulement
attendre se ressemblent trait pour trait dans la sortie. Les trier est tout le travail,
et PHPUnit 13 le fait pour toi — il classe chaque dépréciation selon *qui a appelé
qui*, en utilisant `<source>` pour décider ce qui compte comme ton code :

| Classe | Signification | À toi ? |
|---|---|---|
| **self** | Déclenchée dans un fichier sous `<source>` (ou un test) | Oui — supprime le code déprécié |
| **direct** | Ton code a appelé une API tierce qui s'est ensuite dépréciée | Oui — corrige le point d'appel |
| **indirect** | Du code tiers a appelé du code tiers | Non — mets à jour la dépendance, ou attends |

La recipe de `phpunit.dist.xml` embarque `ignoreIndirectDeprecations="true"`, donc la
suite échoue déjà sur les deux premières catégories et reste silencieuse sur la
troisième. C'est le bon réglage par défaut : c'est une porte sur du travail que tu
peux réellement faire. Mesuré sur PHPUnit 13.3.2, un test par cas :

| Dépréciation | Code de sortie |
|---|---|
| `trigger_deprecation()` depuis une classe de `src/` (self) | `1` |
| un test appelle une méthode dépréciée du vendor (direct) | `1` |
| du vendor appelle du vendor (indirect) | `0`, pas même affiché |
| pareil, avec `ignoreIndirectDeprecations="false"` | `1` |

Deux pièges dans cette sortie. La ligne de résumé affiche encore
**`OK, but there were issues!`** alors que le code de sortie est `1` — la personne qui
lit le terminal et le job CI ne sont pas d'accord, et c'est la CI qui a raison. Et
`ignoreSuppressionOfDeprecations="true"` n'est pas optionnel : le `trigger_deprecation()`
de Symfony est un `@trigger_error(...)`, silencieux, donc sans cet attribut PHPUnit
ignore quasiment toutes les dépréciations Symfony qui existent.

Basculer `ignoreIndirectDeprecations` à `false` pour une seule exécution, c'est le geste
de planification de montée de version, pas le réglage quotidien : ça montre lesquelles
de tes dépendances ne sont elles-mêmes pas prêtes pour la prochaine majeure.

Tout le reste — les trois endroits où les dépréciations apparaissent, la détection
statique, les baselines, et comment écrire les tiennes : `references/deprecations.md`.

## Les recipes dérivent, et presque personne ne regarde

Quand le projet a été créé, Flex a exécuté une recipe pour chaque paquet et a écrit
`security.yaml`, `phpunit.dist.xml`, `.env`. Ces recipes ont été modifiées en amont
depuis. Tes fichiers, eux, ne l'ont pas été.

```bash
composer recipes           # everything, with a marker on what is outdated
composer recipes -o        # only the outdated ones
```

`-o` n'affiche absolument rien quand le projet est à jour, ce qui est la réponse
souhaitée mais se confond facilement avec une commande cassée. Quand quelque chose a
bougé :

```
 * symfony/framework-bundle (update available)
```

```bash
composer recipes:update symfony/framework-bundle
```

Cette commande calcule la recipe d'origine, la nouvelle recipe et tes fichiers actuels,
et applique un patch à trois voies — **mis en staging dans git**, donc `git diff
--cached` est la revue. Elle refuse de s'exécuter sur un index sale, et c'est voulu :
le diff doit être le tien à inspecter. Mécanique, conflits, le repli `--force -v` pour
les recipes trop anciennes pour être récupérées, et quels fichiers valent le coup du
churn : `references/recipes.md`.

Ça compte le plus lors d'une montée de version majeure, parce que la recipe de la
nouvelle majeure est souvent l'endroit où vit la nouvelle configuration. Lire ce diff
est plus rapide que lire les notes de montée de version.

## Rector

```bash
composer require --dev rector/rector rector/rector-symfony rector/rector-doctrine rector/rector-phpunit
vendor/bin/rector process --dry-run --no-progress-bar
```

Même raisonnement que pour tous les autres outils de `symfony-proglab-quality` : une
dépendance require-dev épinglée, résolue par `composer.lock`, identique sur toutes les
machines. **Retire-la une fois la montée de version terminée** — Rector réécrit du
code, il n'a pas besoin de rester installé entre deux migrations, et un rule set
inutilisé laissé dans `composer.json` est une chose de plus à garder synchronisée avec
la version du framework.

`--dry-run` sort avec le code `2` quand des changements seraient appliqués, ce qui le
rend utilisable aussi bien comme vérification que comme outil.

**Les sets numérotés par version ont disparu.** `SymfonySetList` dans rector-symfony ne
contient plus que `COMPOSER_BASED`, `SYMFONY_CODE_QUALITY`,
`SYMFONY_CONSTRUCTOR_INJECTION` et `CONFIGS` — tout article de blog citant
`SymfonySetList::SYMFONY_64` décrit une constante de classe qui n'existe plus. Le
remplacement lie chaque règle à la version du paquet réellement installé :

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withComposerBased(symfony: true, doctrine: true, phpunit: true);
```

Rector excelle dans les réécritures mécaniques sur toute une base de code —
annotations vers attributs, classes renommées, signatures modifiées — et il est
inutile dès qu'il faut du jugement. Il transformera volontiers un docblock en
attribut ; il ne te dira pas que le service aurait dû être un Voter. Donc : **un seul
sujet par exécution, relis le diff, commite séparément.** Un commit unique
mélangeant trois rule sets n'est pas relisible, et le jour où l'un d'eux se révèle faux
tu ne peux pas le revert seul.

Méfie-toi des flags de confort. Ajouter `->withImportNames()` à la config ci-dessus a
fait passer un diff d'un fichier à quatre fichiers, en réécrivant des noms
pleinement qualifiés dans des tests qui n'avaient aucun changement lié à la montée de
version. Utile ; pas quelque chose à glisser discrètement dans un commit de montée de
version.

## Le saut de version majeure, dans l'ordre

1. **Vérifie d'abord les dépendances.** Un seul bundle sans release compatible bloque
   tout le reste, et mieux vaut le savoir avant de commencer qu'après une journée de
   travail. `composer why-not symfony/framework-bundle 9.0` nomme chaque paquet qui
   fait obstacle.
2. **Deviens vert sur la dernière mineure.** Tests passants, `composer audit` propre,
   recipes à jour.
3. **Nettoie les dépréciations** — self et direct, puis revérifie avec indirect
   activé pour voir ce que tes dépendances te doivent encore.
4. **Fais évoluer les contraintes** : `extra.symfony.require`, et les lignes
   `symfony/*` dans `require` / `require-dev` que Flex a écrites en `8.1.*`.
5. **Résous.** `composer update "symfony/*" --with-all-dependencies`, puis lis ce
   que Composer refuse.
6. **Lance la suite, puis les recipes**, puis l'application.

Détail de chaque étape, y compris quoi faire quand l'étape 1 dit non :
`references/major-upgrade.md`.

## Une dépendance abandonnée ou incompatible

`composer audit --abandoned=report` nomme les paquets dont les auteurs ont dit qu'ils
avaient arrêté. Un paquet abandonné n'est pas une urgence — c'est un compte à rebours
qui démarre à la prochaine majeure. Les options, de la pire à la meilleure : épingler
la majeure et arrêter de monter de version (tu as déplacé le problème, pas
résolu) ; le forker (tu le maintiens désormais) ; le remplacer par le mécanisme
natif du framework, ce qui est là où finit un nombre surprenant d'entre eux, puisque
Symfony a absorbé une décennie de bundles.

Dis lequel tu choisis et pourquoi. « On s'en occupera plus tard » n'est honnête
que si quelqu'un écrit la date.

## Pas ce skill

| Question | Skill |
|---|---|
| Configuration CI, PHPStan, contenu de `phpunit.dist.xml` | `symfony-proglab-quality` |
| La séquence de déploiement une fois la montée de version mergée | `symfony-proglab-deployment` |
| Écrire ou corriger une migration | `symfony-proglab-doctrine` |
| Si un test vaut la peine d'être gardé | `symfony-proglab-testing` |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/deprecations.md` | Une dépréciation que tu n'arrives pas à situer, ou planifier ce qu'il faut nettoyer avant une majeure |
| `references/recipes.md` | `composer recipes` signale quelque chose d'obsolète, ou `recipes:update` entre en conflit |
| `references/major-upgrade.md` | Faire le saut : contraintes, dépendances bloquées, runbook Rector, rollback |
