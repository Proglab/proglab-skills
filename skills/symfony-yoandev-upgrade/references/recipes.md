# Flex recipes, and the drift nobody looks at

`composer update` updates `vendor/`. It does not touch `config/`, `.env`,
`phpunit.dist.xml`, `public/index.php`, `src/Kernel.php` or the `Dockerfile` — those were
written **once**, by a Flex recipe, on the day the package was installed. The recipes
have been maintained upstream ever since. Your copies have not.

This is the least-known maintenance task in the ecosystem and one of the cheapest. Most
projects have never run it.

## symfony.lock is the record

Flex writes, per package, the recipe repository, the recipe folder version, the exact
ref it applied, and the list of files it created:

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

Two fields carry different information. **`recipe.version`** is which folder of the
recipe applies to your installed package version — it lags on purpose: PHPUnit 13.3
installs the `11.1` recipe, because that is the newest folder at or below your version,
and that is correct, not stale. **`recipe.ref`** is the commit that folder was at. Drift
is `ref` moving upstream, not `version` looking old.

`symfony.lock` is committed. Deleting it does not reset anything useful; it just makes
Flex forget what it did.

## Finding drift

```bash
composer recipes            # all of them, outdated ones marked
composer recipes -o         # only the outdated ones
```

`-o` on a current project prints **nothing at all** — no "up to date" line, no table.
That silence is the good outcome, and it is easy to mistake for a broken command. When
something has moved:

```
  Outdated recipes.

 * symfony/framework-bundle (update available)
```

One package at a time:

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

`status` is `up to date` or `update available`; the last two lines only appear in the
second case. Do not try to compare the two "recipe" URLs — in the outdated state both
render as `tree/main`. **`recipe history` is the link worth following before running
anything**: it is the upstream commit log for that recipe, and it tells you *why* the
files changed, which the diff alone will not.

## Applying it

```bash
composer recipes:update symfony/framework-bundle
```

What it actually does, from `UpdateRecipesCommand`:

1. **Requires `git`**, and refuses to run on a dirty index —
   `git status --porcelain --untracked-files=no` must be empty. Untracked files are
   tolerated; modified tracked files are not.
2. Downloads the **original** recipe at the ref in `symfony.lock` and the **new** one.
3. Builds a patch between those two, and applies it to your files — so your own edits
   are preserved wherever they do not collide.
4. **Stages everything**, `symfony.lock` included.
5. Prints the recipe's upstream changelog for the range, unless `--no-changelog`.

So the review is:

```bash
git diff --cached
```

and nothing is committed for you. That is deliberate: a recipe update is a diff you read,
not an operation you trust.

On conflicts it says so and leaves standard conflict markers in the files:

```
  The recipe was updated but with one or more conflicts.
  Run git status to see them.
```

Resolve them like any merge conflict. A conflict is usually a good sign — it means you
had customised that file, and now you get to decide whether upstream's reason for
changing it applies to you.

## When the update refuses

```
Failed to download recipe: … archived/symfony.framework-bundle/<ref>.json … (HTTP/2 404)
The original recipe version you have installed could not be found, it may be too old.
Update the recipe by re-installing the latest version with:
  composer recipes:install symfony/framework-bundle --force -v
```

The three-way patch needs the *original* recipe, fetched from the recipes archive by
ref. If that ref is gone — a very old project, or a recipe removed upstream — there is
no original to diff against, and Flex falls back:

```bash
composer recipes:install symfony/framework-bundle --force -v
```

This is a different operation and you must know it: it **overwrites** the recipe's files
with the current version rather than patching them. Your customisations in those files
are lost. Do it on a clean branch, and diff before committing — with `-v` Flex reports
each file it touches. `--reset --force` goes further and puts every recipe back to its
initial state, which is a repair tool for a broken skeleton, not a maintenance step.

The same message appears, with different wording, when `symfony.lock` has no `recipe`
key for the package (installed before Flex tracked refs) or no `ref`/`version` inside it
(installed by an old Flex). Same fallback, same warning.

## What it will not update

Two categories are reported and skipped:

- **`copy-from-package` paths.** Flex has no way to know whether you changed a file it
  copied verbatim out of a bundle, so it leaves them alone and tells you which ones.
- **Files you deleted.** If your project no longer has a file the recipe manages, it is
  not recreated. Flex offers to write the skipped hunks to
  `vendor.package.updates-for-deleted-files.patch` so you can read what you are missing.
  Say yes, read it, delete it. Sometimes the answer is that you should not have deleted
  the file.

## Which recipes actually matter

Not all drift is worth a commit. Ranked by what it buys you:

| Recipe | Why it is worth reading |
|---|---|
| `symfony/framework-bundle` | `public/index.php`, `src/Kernel.php`, `config/packages/framework.yaml` — the boot path and the framework defaults |
| `symfony/security-bundle` | `security.yaml`. Hardening lands here: hashers, `login_throttling`, stateless CSRF token ids |
| `phpunit/phpunit` | `phpunit.dist.xml`. Where the deprecation and issue flags live — see `deprecations.md` |
| `symfony/flex` | `.env`, `.env.dev`. New variables the framework expects (`APP_SHARE_DIR` arrived this way) |
| `doctrine/doctrine-bundle` | `doctrine.yaml`. Defaults that changed across ORM majors |
| `symfony/monolog-bundle` | Production logging, including the `deprecation` channel |

An asset recipe that only rewrites `assets/app.js` can wait.

## Where this fits in an upgrade

Run it **twice**.

Before a major jump, on the last minor: you want the current major's configuration to be
current before you change anything else, so that when the jump breaks something you know
it was the jump.

After the jump, once `composer update` has installed the new major: the new major ships
new recipe folders, and that diff is often the clearest statement of what the new version
expects from your configuration — faster to read, and more specific to your project, than
the upgrade notes.

Both times, in their own commits. A recipe diff mixed into a dependency bump is a diff
nobody reviews.

## Symptom, cause, fix

| Symptom | Cause | Fix |
|---|---|---|
| `composer recipes -o` prints nothing | Nothing is outdated | That is the answer, not a failure |
| `Cannot run recipes:update: Your git index contains uncommitted changes.` | Modified tracked files | Commit or stash; untracked files are fine |
| `Cannot run "recipes:update": git not found.` | No git in the container or PATH | Run it on the host, or in an image that has git |
| `The original recipe version … may be too old` | The archived ref is gone | `recipes:install --force -v`, on a branch, expect to lose local edits to those files |
| Update ran, "No files were changed" | The upstream change did not affect files you still have | Nothing to do; the lock ref is still advanced |
| Files you customised came back with conflict markers | Expected — the patch collided with your edits | Resolve as a merge conflict; decide per hunk |
| A recipe file reappeared that you had deleted | Only with `recipes:install --force`; `recipes:update` does not recreate them | Delete it again, or reconsider why it was deleted |
