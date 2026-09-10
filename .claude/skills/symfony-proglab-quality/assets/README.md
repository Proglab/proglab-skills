# Configuration des outils

Fichiers prêts à committer. Copie-les à la racine du projet, adapte les parties
signalées ci-dessous, et commite-les — ils ont leur place dans le contrôle de
version, pas dans l'historique du shell de quelqu'un.

| Fichier | Va vers | Rôle |
|---|---|---|
| `phpstan.dist.neon` | racine du projet | Analyse statique au niveau max, avec les extensions Symfony et Doctrine |
| `deptrac.yaml` | racine du projet | Le contrat de couches, imposé — **à la demande** : ne le copie que si tu adoptes deptrac ; chaque tâche l'ignore tant qu'il est absent |
| `.php-cs-fixer.dist.php` | racine du projet | Style de code |
| `phpunit.dist.xml` | racine du projet | Le fichier de la recette plus `failOnPhpunitNotice` et l'extension DAMA |
| `Makefile` | racine du projet | Point d'entrée des tâches (par défaut) |
| `alternatives/castor.php` | racine du projet | Point d'entrée des tâches, pour qui préfère des tâches en PHP réel à Make |
| `github-workflow-ci.yml` | `.github/workflows/ci.yml` | Le pipeline |

## Make par défaut

`Makefile` est le point d'entrée. Il ne nécessite rien de plus que `make`
lui-même, déjà présent sur la plupart des hôtes.

```bash
make          # liste toutes les cibles
make qa       # la porte complète
```

`alternatives/castor.php` expose les mêmes noms de tâches, pour qui préfère des
tâches en PHP réel — arguments typés, complétion IDE, conditions qui restent
lisibles à mesure que la liste des tâches grandit, ce qu'un Makefile cesse
d'offrir quelque part autour de la dixième cible. Installe Castor depuis
[castor.jolicode.com](https://castor.jolicode.com) si tu choisis cette voie.
**Commite l'un ou l'autre, jamais les deux** — deux points d'entrée finissent
toujours par diverger, et alors plus personne ne sait lequel la CI exécute
réellement.

### Les outils sont épinglés par composer.lock

```bash
composer require --dev phpstan/phpstan phpstan/phpstan-symfony phpstan/phpstan-doctrine \
  phpstan/phpstan-strict-rules phpstan/phpstan-deprecation-rules phpstan/phpstan-phpunit \
  php-cs-fixer/shim deptrac/deptrac
```

`composer.lock`, commité, est ce qui garde le pipeline reproductible — un pipeline
qui était vert hier ne peut pas échouer aujourd'hui sans aucun changement de code,
puisque rien ne bouge sans un `composer update` dans son propre commit.

**`php-cs-fixer/shim`, pas `friendsofphp/php-cs-fixer` directement** — le shim isole
les propres dépendances de php-cs-fixer pour que sa contrainte sur `symfony/console`
et consorts ne bloque jamais une montée de version Symfony. PHPStan et deptrac n'ont
pas ce problème : les deux sont livrés en phars autonomes en interne, donc les
paquets classiques conviennent en `require-dev` — voir `symfony-proglab-quality`.

## Ce qu'il faut adapter

**`deptrac.yaml`** — les noms de couches doivent correspondre aux répertoires du
projet. S'il regroupe le code par domaine (`src/Billing/…`) plutôt que par rôle
technique, réécris les collecteurs ; les frontières sont le sujet, pas les noms de
dossiers.

**`phpstan.dist.neon`** — `containerXmlPath` doit correspondre au nom de la classe
du kernel, qui contient le namespace du projet. Vérifie le vrai nom de fichier dans
`var/cache/dev/`. La ligne `objectManagerLoader` a besoin d'un
`tests/object-manager.php` retournant l'entity manager ; supprime la ligne si le
projet n'a pas de Doctrine.

**`phpunit.dist.xml`** — écrase le fichier généré par la recette Symfony, ou
fusionne les deux ajouts dedans. Les chemins `<testsuites>` et `<source>` supposent
`tests/` et `src/`. Le bloc `<extensions>` enregistre `dama/doctrine-test-bundle`,
il a donc besoin de `composer require --dev dama/doctrine-test-bundle` **et** d'une
ligne
`DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true]`
dans `config/bundles.php` — la recette du bundle est une recette contrib et
Composer affiche `IGNORING` au lieu de l'appliquer. Supprime tout le bloc
`<extensions>` sur un projet sans Doctrine. Ce qu'il ne faut pas faire, c'est
garder le bloc et sauter la ligne dans `bundles.php` : l'extension ne trouve alors
aucune connexion à envelopper, chaque test valide (commit), et la suite reste
verte pendant qu'elle fuit des lignes.

Les deux attributs que la recette ne fournit pas sont délibérés.
`failOnPhpunitNotice="true"` est ce qui fait passer au rouge la notice de mock sans
expectation — `failOnNotice` seul ne le fait pas, mesuré sur PHPUnit 13.3.2 comme
code de sortie `0` contre `1`. `displayDetailsOnPhpunitNotices="true"` fait nommer
le test par le rapport au lieu de simplement le compter.

**`github-workflow-ci.yml`** — le service de base de données doit correspondre à la
production. Le fichier est fourni avec MySQL 8 ; tester sur un moteur et livrer sur
un autre revient à faire apparaître les différences en production plutôt qu'en CI.
Son job de test exécute `debug:config dama_doctrine_test` avant la suite, ce qui est
la seule vérification mécanique que l'isolation est vraiment activée.

## Adopter ceci sur une base de code existante

Le niveau max sur une base de code qui n'a jamais vu PHPStan produit des milliers
d'erreurs, et l'issue habituelle est que l'outil est retiré une semaine plus tard.

```bash
make stan-baseline    # ou : castor stan-baseline
```

Commite `phpstan-baseline.neon` et décommente la ligne `includes`. Le nouveau code
est écrit au niveau max ; la dette existante est gelée. La règle qui fait
fonctionner cela : **la baseline peut rétrécir, jamais grandir.** Une pull request
qui y ajoute des entrées ajoute de la dette délibérément, et cela devrait être une
conversation explicite plutôt qu'un commit silencieux.

Même chose pour deptrac : lis le rapport comme une carte de l'architecture réelle,
corrige les violations domaine par domaine, et ajoute seulement ensuite la tâche à
la CI. Il est fourni avec `--report-uncovered`, qui liste les dépendances vers des
classes extérieures à toute couche ; un répertoire `src/` dans cette liste est un
répertoire que personne n'a collecté. Il n'est **pas** fourni avec
`--fail-on-uncovered` : les classes vendor sont non couvertes elles aussi, et
l'option échouerait dès le premier `AbstractController`.
