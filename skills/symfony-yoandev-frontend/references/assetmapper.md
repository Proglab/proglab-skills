# AssetMapper in practice

## Contents

- [The model in one paragraph](#the-model-in-one-paragraph)
- [`importmap.php` is the file that matters](#importmapphp-is-the-file-that-matters)
- [What `importmap()` actually renders](#what-importmap-actually-renders)
- [Adding, updating and removing dependencies](#adding-updating-and-removing-dependencies)
- [CSS](#css)
- [Tailwind without Node](#tailwind-without-node)
- [Third-party Stimulus controllers](#third-party-stimulus-controllers)
- [Content Security Policy](#content-security-policy)
- [Preloading](#preloading)
- [The production checklist](#the-production-checklist)
- [Debugging](#debugging)

## The model in one paragraph

Every file under a mapped path (`assets/` by default) gets a **logical path** —
`styles/app.css`, `controllers/search_controller.js`. `asset('styles/app.css')` resolves
it to a **digested** public path, `/assets/styles/app-3d9f1c2.css`, where the digest is
a hash of the content. Change the file, the digest changes, the URL changes, caches
invalidate themselves. That is the whole versioning story: no manifest to maintain, no
cache-busting query string, and `Cache-Control: immutable` is safe on `/assets/`.

JavaScript is served as native ES modules. Bare imports (`import { Chart } from
'chart.js'`) are resolved by an **import map** in the `<head>`, which the browser reads
before executing anything. No bundling, no rewriting of your source.

## `importmap.php` is the file that matters

```php
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    'chart.js' => ['version' => '4.4.1'],
    'chart.js/auto' => ['version' => '4.4.1'],
];
```

Three shapes of entry, and the difference is worth knowing:

| Shape | Meaning |
|---|---|
| `'path' => './assets/…'` | A file in the project. Nothing is downloaded |
| `'version' => '4.4.1'` | A remote package, **downloaded into `assets/vendor/`**. Never fetched from a CDN at runtime |
| `'entrypoint' => true` | Can be passed to `importmap()` in a template. Everything it imports is preloaded |

Whether `assets/vendor/` is committed is a project preference; either way
`importmap:install` reconstructs it from `importmap.php`, and it runs automatically as
a Composer `post-install` script. `importmap.php` is therefore the file to read in a
code review — `assets/vendor/` is generated output.

Additional entrypoints (an admin bundle, a print stylesheet) are declared the same way
and rendered with `{{ importmap(['app', 'admin']) }}`.

## What `importmap()` actually renders

Not a single script tag. In order:

1. `<link rel="stylesheet">` for every CSS entry in the map;
2. `<script type="importmap">` with the name → URL mapping;
3. a small inline script that loads the **`es-module-shims` polyfill** *only if*
   `HTMLScriptElement.supports('importmap')` is false;
4. `<link rel="modulepreload">` for each module reachable from the entrypoint;
5. `<script type="module">import 'app';</script>`.

Two consequences people trip over. The polyfill, if `es-module-shims` is not itself in
`importmap.php`, falls back to a **jspm.io CDN URL** (with `integrity` and
`crossorigin` set). If a third-party request from your pages is unacceptable, either
`importmap:require es-module-shims` to host it yourself, or set
`framework.asset_mapper.importmap_polyfill: false` — every browser that matters has
supported import maps for years.

And the modulepreload list means **the whole dependency graph of the entrypoint is
declared in `<head>`**. That is what makes many small files fast, and it is also why an
entrypoint that transitively imports everything defeats the point.

## Adding, updating and removing dependencies

```bash
importmap:require chart.js                 # pin + download
importmap:require chart.js --path=./assets/vendor/chart.js   # pin a local file instead
importmap:outdated                         # what has moved, with the new version
importmap:update chart.js                  # or with no argument: everything
importmap:remove chart.js
importmap:audit                            # advisories — put this in CI
```

`importmap:audit` queries the GitHub advisory database for the pinned versions. It is
the only vulnerability check you have on the JavaScript side, because there is no
`npm audit` in this stack. It belongs in the CI job next to `composer audit`; the
`symfony-yoandev-quality` skill wires both.

## CSS

CSS is imported from JavaScript:

```js
// assets/app.js
import './styles/app.css';
```

AssetMapper detects the import, adds the file to the map, and `importmap()` emits a
real `<link rel="stylesheet">` for it — the CSS is not injected by JavaScript on the
critical path, so there is no flash of unstyled content.

Inside CSS, `url()` references are rewritten to digested paths automatically, and
`@import` pulls the imported file into the map. A URL that AssetMapper cannot resolve
is governed by `missing_import_mode`:

```yaml
framework:
    asset_mapper:
        missing_import_mode: strict      # dev: fail loudly
when@prod:
    framework:
        asset_mapper:
            missing_import_mode: warn    # prod: do not take the site down for a typo
```

Keep `strict` in dev. `warn` everywhere means a broken import ships silently and is
discovered by a user.

## Tailwind without Node

`symfonycasts/tailwind-bundle` downloads the standalone Tailwind CLI binary into
`var/` and registers an AssetMapper compiler on the configured input file.

```yaml
# config/packages/symfonycasts_tailwind.yaml
symfonycasts_tailwind:
    binary_version: 'v4.1.11'     # pin it
    # input_css defaults to %kernel.project_dir%/assets/styles/app.css
```

```bash
symfony console tailwind:init             # once
symfony console tailwind:build --watch    # while developing
symfony console tailwind:build --minify   # done for you by asset-map:compile
```

`--watch` must be running for CSS edits to appear; "my Tailwind classes do nothing" is
almost always a missing watcher, or a class name built by string concatenation that
Tailwind's scanner cannot see in the template source.

Pin `binary_version`. Unpinned, the bundle downloads the latest CLI, which means a
Tailwind major version can arrive on a CI runner that has no cache — with a different
config format — on a day nobody changed any CSS.

The form theme goes in `config/packages/twig.yaml`
(`twig.form_themes: ['tailwind_2_layout.html.twig']`), once, never per template.

## Third-party Stimulus controllers

`assets/controllers.json` is written by the UX recipes and controls which bundled
controllers are enabled, and how they load:

```json
{
    "controllers": {
        "@symfony/ux-live-component": {
            "live": { "enabled": true, "fetch": "eager" }
        }
    }
}
```

`"fetch": "lazy"` defers loading a controller until an element using it appears in the
DOM. For a controller used on one admin page out of forty, that is the difference
between shipping it to everyone and shipping it to the people who need it. Your own
controllers in `assets/controllers/` are lazy-loadable too, with a
`/* stimulusFetch: 'lazy' */` comment above the class.

## Content Security Policy

The import map, the polyfill loader and the entrypoint are inline `<script>` elements,
so a CSP without `unsafe-inline` needs a nonce:

```yaml
framework:
    asset_mapper:
        importmap_script_attributes:
            nonce: '%csp_nonce%'
```

**This collides head-on with the `data-turbo-track` recipe, and the collision is silent.**
`importmap_script_attributes` is a single attribute bag: put `nonce` *and*
`'data-turbo-track': 'reload'` in it and both land on the same `<script>` elements. Turbo
compares tracked elements by their `outerHTML` — measured: it compares **only** the
elements marked `data-turbo-track="reload"`, not the whole `<head>` — so a nonce that
changes every response means the signature never matches and **every navigation is a full
page reload**. Drive is installed and inert, which is the worst of both.

Pick one deliberately:

- **Nonce the scripts, track something else.** Put `data-turbo-track="reload"` on a
  `<meta name="app-version" content="{{ release_sha }}">`. That is what you wanted to
  compare in the first place — the deployed release, not the nonce.
- **Track the scripts, source the nonce elsewhere** — a `script-src` hash, or a nonce
  applied outside `importmap_script_attributes`.

Symfony's renderer already propagates the nonce at runtime for the polyfill script rather
than inlining it, so that one is fine either way; the importmap and entrypoint tags still
render the attribute per request. Untracked `<head>` content that varies per request costs
nothing — only tracked elements are compared.

## Preloading

With `symfony/web-link` installed, `importmap()` also sets `Link: <…>; rel=preload`
headers for the preloaded modules, so the browser can start fetching them from the
response headers, before it has parsed the HTML body. Combined with the
`<link rel="modulepreload">` tags and HTTP/2 multiplexing, that is the mechanism that
makes "many small files" competitive with a bundle. Remove any of the three and it
stops being competitive.

## The production checklist

```bash
php bin/console asset-map:compile
```

- **Run it at every deploy.** Skipped, `framework.asset_mapper.server` serves each
  asset through a full PHP kernel boot. The site works; it is just paying a PHP request
  for every image.
- **Serve `public/assets/` from the web server**, with a long `Cache-Control` and
  `immutable`. Safe because the filename contains the content digest.
- **HTTP/2 or HTTP/3, non-negotiable.** Over HTTP/1.1 the many-small-files model loses
  to a bundle. Verify with `curl -I --http2` rather than assuming.
- **Compression.** Symfony 7.3+:

  ```yaml
  framework:
      asset_mapper:
          precompress: true
  ```

  `asset-map:compile` then writes `.br`, `.zst` and `.gz` next to each asset. The
  formats actually produced depend on what is available: brotli and zstd need either the
  matching PHP extension or the `brotli` / `zstd` binary on `PATH`. **Install them in the
  build image**, because the failure is quiet in the shape most people use: with
  `precompress: true` and no explicit `formats`, a missing compressor is reported through
  `$logger->warning()` and not to the command's output, so the build succeeds and you are
  back to gzip with nothing on screen. Pinning `formats: ['br', 'zst', 'gz']` explicitly
  turns that into a `RuntimeException` instead — worth doing precisely because it fails
  the build rather than the page. `assets:compress` (that name, not
  `asset-map:compress`) runs the same compression as a standalone step. On 6.4–7.2 there
  is no such option; compress at the web-server layer.
- **`APP_ENV=prod` before compiling**, so the compiled config and digests match what the
  application will ask for at runtime.

## Debugging

| Symptom | Cause |
|---|---|
| `Unable to find asset "…"` | The logical path is wrong. `debug:asset-map` lists every one |
| Asset 404s only in production | `asset-map:compile` not run, or `public/assets/` not deployed |
| Stale CSS/JS after a deploy | `public/assets/` from a previous compile, or a CDN caching the *undigested* path |
| `Failed to resolve module specifier` | A bare import missing from `importmap.php` — `importmap:require` it |
| Everything works in dev, nothing in prod | `missing_import_mode: warn` swallowed a broken import at compile time. Read the compile output |
| An image is served by PHP | Same missing compile step; `asset_mapper.server` is answering |
| A JS package looks vulnerable but nothing fails | `importmap:audit` is not in CI |
