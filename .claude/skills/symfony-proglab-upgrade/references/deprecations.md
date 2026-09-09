# Dépréciations

Tout ce qu'il faut corriger avant une majeure est annoncé sous forme de dépréciation
sur les mineures qui la précèdent. Ce fichier explique comment les trouver, décider à
qui appartient chacune, et quand les geler est honnête.

## Quatre détecteurs, et aucun ne suffit seul

| Détecteur | Voit | Aveugle à |
|---|---|---|
| PHPUnit | Ce que les tests exécutent | Tout chemin que les tests ne couvrent pas |
| Profiler / `var/log/dev.log` | Ce sur quoi tu as cliqué | Ce que tu n'as pas cliqué |
| Le canal `deprecation` de prod | Ce que font les vrais utilisateurs | Rien, à terme — mais ça coûte une release |
| PHPStan `phpstan-deprecation-rules` | Chaque appel à une API `@deprecated`, exécuté ou non | Les dépréciations déclenchées par la *configuration* plutôt que par un appel |

La dernière ligne est l'association importante. Une grande part des dépréciations
Symfony ne sont pas « tu as appelé une méthode dépréciée » — ce sont « ton
`services.yaml` utilisait une option en voie de disparition », levées à la compilation
du conteneur, invisibles pour un analyseur statique qui lit ton PHP. Et une grande
part de ton *propre* code mort est invisible aux détecteurs à l'exécution, parce que
rien ne l'exerce. Utilise les deux.

## PHPUnit : la classification

PHPUnit 13 ne se contente pas de rapporter les dépréciations, il décide qui a causé
chacune, en utilisant `<source>` pour définir ce qui est « ton code ». D'après
`IssueTrigger` :

- **self** — la dépréciation se déclenche dans un fichier sous `<source>`, ou dans du
  code de test.
- **direct** — du code de premier niveau a appelé du code tiers, qui s'est déprécié
  lui-même.
- **indirect** — du tiers a appelé du tiers. Tu es spectateur.
- **unknown** — la trace n'a donné ni appelant ni appelé que PHPUnit ait pu classer.

Ça correspond exactement à ce que tu peux en faire :

```
self       → supprime la chose dépréciée ; tu possèdes chaque appelant
direct     → change ton point d'appel ; le correctif est dans ton repository
indirect   → mets à jour la dépendance, ou attends. Rien d'autre ne fonctionne.
```

Mesuré, PHPUnit 13.3.2, un test par ligne, sur le `phpunit.dist.xml` de la recipe :

| Cas | Affiché | Sortie |
|---|---|---|
| `trigger_deprecation()` dans une classe sous `src/` | oui | `1` |
| un test appelle une méthode dépréciée dans `vendor/` | oui | `1` |
| une classe de `vendor/` appelle une autre classe de `vendor/` | **non** | `0` |
| pareil, `ignoreIndirectDeprecations="false"` | oui | `1` |

Note bien la troisième ligne : **une dépréciation vendor-vers-vendor n'apparaît pas du
tout dans la sortie.** Elle n'est pas listée-mais-tolérée ; elle est filtrée avant même
l'affichage. Si tu essaies de répondre à « à quel point mes dépendances sont-elles
prêtes pour la prochaine majeure », la configuration par défaut te dira qu'elles sont
parfaites.

### Les deux attributs qui font que ça marche

**Tout ce mécanisme nécessite PHPUnit ≥ 11.2** — la classification self / direct /
indirect, les attributs `<source>` ci-dessous, `<deprecationTrigger>`, et
`--generate-baseline` / `--use-baseline` pour les dépréciations sont tous arrivés à
cette version. Un projet Symfony 6.4 encore en PHPUnit 9.6 ou 10.5 n'a rien de tout
ça. Ce qu'il faut écrire à la place : installer `symfony/phpunit-bridge` et régler
`SYMFONY_DEPRECATIONS_HELPER` dans `phpunit.dist.xml` — ses seuils `max[self]` /
`max[direct]` / `max[indirect]` expriment les mêmes trois catégories par une autre
voie, y compris le réglage par défaut « échouer sur le mien, tolérer celui des
vendors » que défend cette section. Les règles de tri ci-dessous ne changent pas ;
seul l'outil qui les rapporte change.

```xml
<source ignoreSuppressionOfDeprecations="true"
        ignoreIndirectDeprecations="true"
        restrictNotices="true"
        restrictWarnings="true"
>
    <include>
        <directory>src</directory>
    </include>

    <deprecationTrigger>
        <method>Doctrine\Deprecations\Deprecation::trigger</method>
        <method>Doctrine\Deprecations\Deprecation::delegateTriggerToBackend</method>
        <function>trigger_deprecation</function>
    </deprecationTrigger>
</source>
```

- **`ignoreSuppressionOfDeprecations="true"` est structurant.**
  `symfony/deprecation-contracts` implémente `trigger_deprecation()` comme
  `@trigger_error($message, \E_USER_DEPRECATED)` — silencieux à cause du `@`. Retire
  cet attribut et PHPUnit respecte la suppression, ce qui veut dire qu'il ignore
  quasiment toutes les dépréciations Symfony jamais levées, silencieusement, pendant
  que `failOnDeprecation="true"` reste dans le fichier à donner un faux sentiment de
  sécurité.
- **`<include><directory>src</directory>` est ce que « ton code » veut dire ici.** Si
  un projet garde du code hors de `src/`, ajoute-le, sinon ces fichiers sont classés
  comme tiers et leurs dépréciations sont écartées.
- **`<deprecationTrigger>`** indique à PHPUnit qu'une dépréciation rapportée *à la
  ligne de `trigger_deprecation()`* doit être attribuée à l'appelant de cette fonction
  plutôt qu'à elle-même. Sans ça, chaque dépréciation Symfony est imputée à
  `deprecation-contracts/function.php`, et la classification s'effondre.

`symfony-proglab-quality` possède ce fichier ; c'est à ça que sert la partie du fichier
consacrée aux dépréciations.

## Le profiler et le log de dev

En passant par le vrai front controller, une dépréciation part vers le canal Monolog
dédié `deprecation`. Vérifié sur une requête en dev :

```
[2026-08-30T17:04:43.247873+00:00] deprecation. ... Since app/shelf 1.4: ProbeController is deprecated.
```

Dans le navigateur, c'est le **panneau Logs du profiler**, qui a un onglet Deprecation
avec son propre badge de compteur, à côté d'Errors ; la barre d'outils affiche le même
nombre. C'est le moyen le plus rapide de répondre à « qu'est-ce que *cette page* fait
encore de travers », et ça couvre les chemins pour lesquels personne n'a écrit de test.

**Ça ne fonctionne pas depuis un test.** Mesuré : un `WebTestCase` qui appelle
`$client->enableProfiler()` sur une action qui déclenche une dépréciation obtient
`countDeprecations() === 0`. Le gestionnaire d'erreurs de debug de Symfony — la pièce
qui transforme `E_USER_DEPRECATED` en entrée de log — est installé par
`symfony/runtime` dans le front controller, et un `WebTestCase` ne passe jamais par là ;
c'est le propre gestionnaire de PHPUnit qui intercepte la dépréciation à la place.
Donc n'écris pas de test qui vérifie le compteur de dépréciations du profiler.
N'affirme rien : `failOnDeprecation` *est* déjà l'assertion.

### Les dépréciations qu'aucune requête ne déclenche

```bash
php bin/console debug:container --deprecations
```

```
 [OK] There are no deprecations in the logs!
```

Ceci lit les dépréciations émises pendant la **compilation du conteneur et le
réchauffement du cache** — options de configuration, extensions de bundle, définitions
de service dépréciées. Rien d'autre ne fait apparaître ça, et c'est exactement la
catégorie qui se transforme en `InvalidConfigurationException` fatale à la prochaine
majeure. Lance-le sur chaque environnement que tu déploies, pas seulement `dev` :
`php bin/console --env=prod debug:container --deprecations`.

### En production

La recipe Monolog fournit déjà le bon réglage, donc vérifie-le plutôt que de le
construire : un canal `deprecation` est déclaré, le handler principal
`fingers_crossed` exclut ce canal (`channels: ["!deprecation"]`, pour qu'une
dépréciation ne vide pas un buffer de logs de debug), et un handler dédié l'écrit sur
`php://stderr` en JSON.

La production est le seul détecteur qui voit ce que tes utilisateurs font réellement.
Sur un projet à la couverture de tests légère, c'est la réponse honnête à « quels
chemins dépréciés sont encore vivants » — déploie une release, attends une semaine,
lis les logs.

## Baselines

PHPUnit peut geler l'ensemble actuel :

```bash
vendor/bin/phpunit --generate-baseline phpunit-baseline.xml
vendor/bin/phpunit --use-baseline phpunit-baseline.xml
```

L'exécution qui génère sort quand même avec le code `1` ; la seconde exécution sort
avec `0` et affiche `1 issue was ignored by baseline.` Le fichier est par ligne, avec
un hash de contenu :

```xml
<?xml version="1.0"?>
<files version="1">
 <file path="src/Legacy/ShelfCounter.php">
  <line number="11" hash="0a9cde23e0c630bdef02750cae963781e0b07e0d">
   <issue><![CDATA[Since app/shelf 1.4: Use "ShelfReader::count()" instead.]]></issue>
  </line>
 </file>
</files>
```

Cette granularité est la bonne et la mauvaise nouvelle à la fois. Touche le fichier et
les hash bougent, donc la baseline doit être régénérée — ce qui veut dire qu'une
baseline n'est pas un endroit où les choses peuvent pourrir en silence, mais aussi que
« régénérer la baseline » devient un réflexe qui cache de la nouvelle dette dans un
ancien fichier.

**Quand une baseline est honnête :** tu viens d'adopter ce standard sur une base de
code avec des centaines de dépréciations préexistantes et tu as besoin que la
suite passe au rouge sur les *nouvelles* dès demain. La dette est gelée, visible dans
un fichier committé, et la règle est la même que celle que `symfony-proglab-quality`
applique à la baseline PHPStan — **elle peut rétrécir, jamais grandir**.

**Quand c'est de la procrastination :** tu es à trois mois d'une montée de version
majeure et la baseline contient précisément les dépréciations qui vont devenir des
erreurs fatales. Les geler n'apporte rien ; l'échéance ne bouge pas parce que tu as
arrêté de regarder. Le signe qui ne trompe pas : une baseline pleine d'entrées
`Since symfony/… 7.x:` est une liste de tes tâches de montée de version, pas une liste
de choses que tu as décidé de tolérer.

Entre les deux : garde la baseline, et supprimes-en des entrées par lots dans le cadre
du travail normal, de sorte que le fichier rétrécisse de lui-même avant que la montée
de version ne commence.

## Détection statique

`phpstan-deprecation-rules` est déjà présent dans le `phpstan.dist.neon` du standard.
Il rapporte les points d'appel indépendamment de la couverture :

```
  Line   probe.php
  14     Call to deprecated method count() of class Probe\Shelf:
         since app/shelf 1.4, use countBooks() instead
         🪪  method.deprecated
```

Le message porte le texte du docblock `@deprecated`, qui nomme généralement le
remplacement. C'est la boucle de correction la plus rapide qui soit — pas d'exécution
de test, pas de navigation.

Lance-le via `vendor/bin/phpstan` comme n'importe quel autre outil
(`symfony-proglab-quality` possède l'invocation et le `phpstan.dist.neon` qui inclut
`vendor/phpstan/phpstan-deprecation-rules/rules.neon` — ce n'est pas auto-enregistré,
donc une config sans cette ligne `includes` ne trouve rien ici non plus).

## Écrire les vôtres

`trigger_deprecation('vendor/package', '1.4', 'message %s', $arg)` vient de
`symfony/deprecation-contracts`, déjà installé de manière transitive. Dans une
application, c'est presque toujours le mauvais outil : tu possèdes chaque appelant,
donc tu les changes dans le même commit et supprimes l'ancien code. N'y aie recours
que là où les appelants sont réellement hors de portée — un paquet interne partagé, une
API publique à toi avec d'autres équipes derrière.

Si tu l'utilises, souviens-toi que ça te coûte une suite au rouge dès que quelque
chose dans `src/` l'appelle, parce que c'est une dépréciation *self*. C'est le
comportement correct, et ça veut dire qu'une dépréciation dans ton propre code est
une tâche avec une échéance plutôt qu'une simple note.

## Symptôme, cause, correctif

| Symptôme | Cause | Correctif |
|---|---|---|
| `OK, but there were issues!` et la CI échoue quand même | La ligne de résumé n'est pas le code de sortie ; les dépréciations sortent en `1` | Fais confiance au code de sortie ; lis la liste des dépréciations au-dessus du résumé |
| Aucune dépréciation jamais rapportée, sur un projet très ancien | `ignoreSuppressionOfDeprecations` manquant ; celles de Symfony sont toutes suppressées avec `@` | Ajoute-le à `<source>` |
| Chaque dépréciation imputée à `function.php` | `<deprecationTrigger>` manquant | Ajoute les trois entrées de la recipe |
| Les dépréciations disparaissent après avoir déplacé du code hors de `src/` | Le nouveau répertoire n'est pas dans `<source><include>` | Ajoute le répertoire |
| Tests verts, log de production plein de dépréciations | Chemins non testés | Lis le canal `deprecation` de prod ; c'est le rapport de couverture que tu n'as pas écrit |
| Une dépréciation introuvable dans aucun fichier PHP | Levée à la compilation du conteneur | `debug:container --deprecations`, par environnement |
| `composer update` n'a rien corrigé | `extra.symfony.require` épingle la mineure | Voir `major-upgrade.md` |
