# Les recipes Flex, et la dérive que personne ne regarde

`composer update` met à jour `vendor/`. Ça ne touche pas à `config/`, `.env`,
`phpunit.dist.xml`, `public/index.php` ni `src/Kernel.php` — ces fichiers ont été
écrits **une fois**, par une recipe Flex, le jour où le paquet a été installé. Les
recipes ont été maintenues en amont depuis. Vos copies, elles, ne l'ont pas été.

C'est la tâche de maintenance la moins connue de l'écosystème, et l'une des moins
coûteuses. La plupart des projets ne l'ont jamais exécutée.

## symfony.lock est le registre

Flex écrit, par paquet, le dépôt de la recipe, la version du dossier de recipe, le ref
exact appliqué, et la liste des fichiers créés :

```json
"symfony/framework-bundle": {
    "version": "8.1",
    "recipe": {
        "repo": "github.com/symfony/recipes",
        "branch": "main",
        "version": "8.1",
        "ref": "312027aea160796a50bf2d185503afdb5d71f570"
    },
    "files": [
        "config/packages/cache.yaml",
        "config/packages/framework.yaml",
        "config/preload.php",
        "config/routes/framework.yaml",
        "config/services.yaml",
        "public/index.php",
        "src/Kernel.php",
        ".editorconfig"
    ]
}
```

Deux champs portent des informations différentes. **`recipe.version`** indique quel
dossier de la recipe s'applique à la version installée de ton paquet — il retarde
volontairement : PHPUnit 13.3 installe la recipe `11.1`, parce que c'est le dossier le
plus récent à ou en dessous de ta version, et c'est correct, pas obsolète.
**`recipe.ref`** est le commit auquel ce dossier était figé. La dérive, c'est `ref` qui
bouge en amont, pas `version` qui a l'air ancien.

`symfony.lock` est committé. Le supprimer ne réinitialise rien d'utile ; ça fait juste
oublier à Flex ce qu'il a fait.

## Trouver la dérive

```bash
composer recipes            # all of them, outdated ones marked
composer recipes -o         # only the outdated ones
```

`-o` sur un projet à jour n'affiche **absolument rien** — pas de ligne « up to date »,
pas de tableau. Ce silence est le bon résultat, et il est facile de le prendre pour une
commande cassée. Quand quelque chose a bougé :

```
  Outdated recipes.

 * symfony/framework-bundle (update available)
```

Un paquet à la fois :

```bash
composer recipes symfony/framework-bundle
```

```
name             : symfony/framework-bundle
version          : 8.1
status           : update available
installed recipe : https://github.com/symfony/recipes/tree/main/symfony/framework-bundle/8.1
latest recipe    : https://github.com/symfony/recipes/tree/main/symfony/framework-bundle/8.1
recipe history   : https://github.com/symfony/recipes/commits/main/symfony/framework-bundle
files            : ...
```

`status` vaut `up to date` ou `update available` ; les deux dernières lignes
n'apparaissent que dans le second cas. N'essaie pas de comparer les deux URLs
« recipe » — dans l'état obsolète, les deux s'affichent en `tree/main`.
**`recipe history` est le lien qui vaut la peine d'être suivi avant de lancer quoi que
ce soit** : c'est le journal de commits en amont pour cette recipe, et il te dit
*pourquoi* les fichiers ont changé, ce que le diff seul ne dira pas.

## L'appliquer

```bash
composer recipes:update symfony/framework-bundle
```

Ce que ça fait réellement, d'après `UpdateRecipesCommand` :

1. **Nécessite `git`**, et refuse de s'exécuter sur un index sale —
   `git status --porcelain --untracked-files=no` doit être vide. Les fichiers non
   suivis sont tolérés ; les fichiers suivis modifiés ne le sont pas.
2. Télécharge la recipe **d'origine** au ref présent dans `symfony.lock` et la
   **nouvelle**.
3. Construit un patch entre les deux, et l'applique à tes fichiers — de sorte que tes
   propres modifications soient préservées partout où elles n'entrent pas en collision.
4. **Met tout en staging**, `symfony.lock` compris.
5. Affiche le changelog amont de la recipe pour la plage concernée, sauf avec
   `--no-changelog`.

Donc la revue, c'est :

```bash
git diff --cached
```

et rien n'est committé pour toi. C'est voulu : une mise à jour de recipe est un diff
que tu lis, pas une opération à qui tu fais confiance les yeux fermés.

En cas de conflit, ça le signale et laisse des marqueurs de conflit standard dans les
fichiers :

```
  The recipe was updated but with one or more conflicts.
  Run git status to see them.
```

Résous-les comme n'importe quel conflit de merge. Un conflit est généralement bon
signe — ça veut dire que tu avais personnalisé ce fichier, et maintenant tu décides
si la raison qu'a l'amont de le changer s'applique à toi.

## Quand la mise à jour refuse

```
Failed to download recipe: … archived/symfony.framework-bundle/<ref>.json … (HTTP/2 404)
The original recipe version you have installed could not be found, it may be too old.
Update the recipe by re-installing the latest version with:
  composer recipes:install symfony/framework-bundle --force -v
```

Le patch à trois voies a besoin de la recipe *d'origine*, récupérée depuis les
archives de recipes par ref. Si ce ref a disparu — un projet très ancien, ou une
recipe supprimée en amont — il n'y a pas d'original avec quoi comparer, et Flex se
replie :

```bash
composer recipes:install symfony/framework-bundle --force -v
```

C'est une opération différente et tu dois le savoir : ça **écrase** les fichiers de
la recipe avec la version actuelle plutôt que de les patcher. Tes personnalisations
dans ces fichiers sont perdues. Fais-le sur une branche propre, et diffe avant de
committer — avec `-v` Flex rapporte chaque fichier qu'il touche. `--reset --force` va
plus loin et remet chaque recipe à son état initial, ce qui est un outil de réparation
pour un squelette cassé, pas une étape de maintenance.

Le même message apparaît, avec un texte différent, quand `symfony.lock` n'a pas de clé
`recipe` pour le paquet (installé avant que Flex ne trace les refs) ou pas de
`ref`/`version` à l'intérieur (installé par un ancien Flex). Même repli, même
avertissement.

## Ce que ça ne mettra pas à jour

Deux catégories sont signalées et ignorées :

- **Les chemins `copy-from-package`.** Flex n'a aucun moyen de savoir si tu as
  modifié un fichier qu'il a copié tel quel depuis un bundle, donc il les laisse
  tranquilles et te dit lesquels.
- **Les fichiers que tu as supprimés.** Si ton projet n'a plus un fichier géré
  par la recipe, il n'est pas recréé. Flex propose d'écrire les hunks ignorés dans
  `vendor.package.updates-for-deleted-files.patch` pour que tu puisses lire ce que
  tu manques. Dis oui, lis-le, supprime-le. Parfois la réponse est que tu
  n'aurais pas dû supprimer le fichier.

## Quelles recipes comptent vraiment

Toute dérive ne vaut pas un commit. Classées par ce qu'elles apportent :

| Recipe | Pourquoi ça vaut le coup de la lire |
|---|---|
| `symfony/framework-bundle` | `public/index.php`, `src/Kernel.php`, `config/packages/framework.yaml` — le chemin de démarrage et les défauts du framework |
| `symfony/security-bundle` | `security.yaml`. Le durcissement atterrit ici : hashers, `login_throttling`, identifiants de token CSRF stateless |
| `phpunit/phpunit` | `phpunit.dist.xml`. Là où vivent les flags de dépréciation et d'issue — voir `deprecations.md` |
| `symfony/flex` | `.env`, `.env.dev`. Les nouvelles variables attendues par le framework (`APP_SHARE_DIR` est arrivée comme ça) |
| `doctrine/doctrine-bundle` | `doctrine.yaml`. Les défauts qui ont changé au fil des majeures de l'ORM |
| `symfony/monolog-bundle` | Le logging de production, y compris le canal `deprecation` |

Une recipe d'assets qui ne fait que réécrire `assets/app.js` peut attendre.

## Où ça s'insère dans une montée de version

Lance-la **deux fois**.

Avant un saut de majeure, sur la dernière mineure : tu veux que la configuration de
la majeure actuelle soit à jour avant de changer quoi que ce soit d'autre, pour que
lorsque le saut casse quelque chose, tu saches que c'est le saut le responsable.

Après le saut, une fois que `composer update` a installé la nouvelle majeure : la
nouvelle majeure embarque de nouveaux dossiers de recipe, et ce diff est souvent
l'énoncé le plus clair de ce que la nouvelle version attend de ta configuration —
plus rapide à lire, et plus spécifique à ton projet, que les notes de montée de
version.

Les deux fois, dans leurs propres commits. Un diff de recipe mélangé à une montée de
version de dépendance est un diff que personne ne relit.

## Symptôme, cause, correctif

| Symptôme | Cause | Correctif |
|---|---|---|
| `composer recipes -o` n'affiche rien | Rien n'est obsolète | C'est la réponse, pas un échec |
| `Cannot run recipes:update: Your git index contains uncommitted changes.` | Fichiers suivis modifiés | Committe ou stashe ; les fichiers non suivis ne posent pas de problème |
| `Cannot run "recipes:update": git not found.` | Pas de git dans le conteneur ou le PATH | Lance-le sur l'hôte, ou dans une image qui a git |
| `The original recipe version … may be too old` | Le ref archivé a disparu | `recipes:install --force -v`, sur une branche, attends-toi à perdre les modifications locales sur ces fichiers |
| Mise à jour lancée, « No files were changed » | Le changement amont n'affectait pas des fichiers que tu as encore | Rien à faire ; le ref du lock a quand même avancé |
| Des fichiers que tu avais personnalisés reviennent avec des marqueurs de conflit | Attendu — le patch est entré en collision avec tes modifications | Résous comme un conflit de merge ; décide au cas par cas |
| Un fichier de recipe supprimé est réapparu | Seulement avec `recipes:install --force` ; `recipes:update` ne les recrée pas | Supprime-le à nouveau, ou reconsidère pourquoi il a été supprimé |
