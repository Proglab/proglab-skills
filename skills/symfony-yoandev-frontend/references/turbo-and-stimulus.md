# Turbo and Stimulus

## Contents

- [Turbo Drive](#turbo-drive)
- [What Drive breaks](#what-drive-breaks)
- [Turbo Frames](#turbo-frames)
- [Turbo Streams](#turbo-streams)
- [Answering a form submission with a stream](#answering-a-form-submission-with-a-stream)
- [`target` and `targets` are not the same attribute](#target-and-targets-are-not-the-same-attribute)
- [Streams over Mercure](#streams-over-mercure)
- [`#[Broadcast]`](#broadcast)
- [Stimulus: the shape of a controller](#stimulus-the-shape-of-a-controller)
- [No business rule in JavaScript](#no-business-rule-in-javascript)
- [Debugging](#debugging)

## Turbo Drive

Installing `symfony/ux-turbo` turns it on. Every link click and form submission is
intercepted, fetched with `fetch()`, and the response's `<body>` replaces the current
one. The page never fully reloads, so the JavaScript context, the CSS, and any
long-running connection survive navigation.

Opting out where it is wrong:

```twig
<a href="{{ path('legacy_report') }}" data-turbo="false">Report</a>
<form action="…" data-turbo="false">…</form>
```

`data-turbo="false"` on an element disables Drive for it and its descendants. Real
reasons to use it: a file download, a route that returns a non-HTML response, a page
under a different asset build.

A tag worth adding to anything version-sensitive in `<head>`:

```twig
{{ importmap('app') }}
```

with `framework.asset_mapper.importmap_script_attributes: { 'data-turbo-track': 'reload' }`.
Turbo then forces a full reload when the tracked element changes between navigations —
which is how a user who has been on the site since before a deploy stops running the
old JavaScript against the new HTML.

**Do not also put a per-request `nonce` in that same attribute bag** — see "What Drive
breaks" below, and `assetmapper.md`. It turns every navigation into a full reload.

## What Drive breaks

Everything that assumed a page load.

| Assumption | Reality with Drive |
|---|---|
| `DOMContentLoaded` fires per page | Fires **once**, on the very first load |
| `window.onload` runs on navigation | Never again |
| An inline `<script>` in the body runs when its page appears | Runs the first time; after that Turbo may or may not re-execute it, and you should not depend on either |
| Global variables reset between pages | They persist. So do timers, listeners and memory leaks |
| `<head>` is whatever the page sent | Turbo compares the elements marked `data-turbo-track="reload"`; if one changes, **full reload** |

That last row is the one that silently undoes Drive, and the usual summary of it — "Turbo
compares `<head>`" — is wrong in a way that sends you after the wrong element. Measured in
`turbo.index.js`: `HeadSnapshot::trackedElementSignature()` joins the `outerHTML` of
**only** the elements carrying `data-turbo-track="reload"`, and a navigation reloads when
that string differs between snapshots. Untracked `<head>` content is merged, not reloaded
— a per-request nonce on a `<meta>` costs nothing.

So the failure is specific: it happens when something **variable** lands on a **tracked**
element. The way this suite trips over it is putting a per-request CSP nonce into
`importmap_script_attributes` while also tracking those scripts — both attributes go onto
the same `<script>` tags, the signature changes on every response, and **every navigation
becomes a full page load, permanently**. Drive looks installed and does nothing; the
symptom is "Turbo is installed but the pages still flash". Pick one: track the scripts and
carry the nonce elsewhere, or nonce them and track a separate
`<meta name="app-version" content="{{ release_sha }}" data-turbo-track="reload">`, which
is the thing you actually wanted to compare. See `assetmapper.md`, "Content Security
Policy".

The fix for the first four rows is not `turbo:load` listeners scattered around — it is
Stimulus. A controller's `connect()` runs every time its element enters the DOM,
including after a Turbo body swap, and `disconnect()` gives you the cleanup hook that a
`DOMContentLoaded` handler never had.

## Turbo Frames

```twig
<turbo-frame id="book_reviews" src="{{ path('review_index', {book: book.id}) }}" loading="lazy">
    <p>Loading reviews…</p>
</turbo-frame>
```

A frame is a scoped navigation context: a link or form **inside** the frame replaces
only the frame's content, provided the response contains a `<turbo-frame>` with the same
id. `loading="lazy"` defers the fetch until the frame scrolls into view.

What frames are genuinely good at: a heavy panel that should not delay the main page
(lazy `src`), inline edit (the frame swaps a row for a form and back), and modals.

The trap: the response **must** contain a matching frame id, or Turbo reports
"Content missing". The controller answering a frame request usually renders a fragment
template that wraps its output in the same `<turbo-frame id="…">`. In Twig,
`turbo_is_frame_request()` tells you whether the current request came from a frame, so
one action can serve both the full page and the fragment.

Frames are a *layout* mechanism. Deciding "should this link replace only this box" is
not the same question as the Live Component / Stream arbitration in SKILL.md, and the
two compose: a Live Component can live inside a frame.

## Turbo Streams

A stream is a list of operations against elements of the current page:

```html
<turbo-stream action="append" targets="#notifications">
    <template><div class="toast">A new review was published</div></template>
</turbo-stream>
```

Actions: `append`, `prepend`, `replace`, `update`, `remove`, `before`, `after`,
`refresh`. A stream reaches the browser two ways — **as the response to a form
submission**, or **pushed** from the server over Mercure. `SKILL.md` arbitrates between
those two and a Live Component; this file is the mechanics.

Verified against `symfony/ux-turbo` 3.4 on Symfony 8.1.

**Check which major you are on before using any of the PHP helpers below.** `ux-turbo` 3.0
requires Symfony ≥ 7.4 and PHP ≥ 8.4, so a project anywhere in the 6.4–7.3 part of this
suite's range is on `ux-turbo` 2.x, where several of these do not exist:

| Helper | Since | Write instead |
|---|---|---|
| `TurboStreamResponse`, `Helper\TurboStream`, `<twig:Turbo:Stream:*>` | 2.21 (generic and custom actions 2.22) | `$request->setRequestFormat(TurboBundle::STREAM_FORMAT)` and render a template of `<turbo-stream>` elements by hand — the form-submission recipe below, which works on every version |
| `turbo_is_frame_request()` | 3.1 | `$request->headers->has('Turbo-Frame')` in the controller, passed to the template as a variable |
| `turbo_stream_from()` | 3.1 | `turbo_stream_listen()`, the 2.x name for the same tag |

`TurboBundle::STREAM_FORMAT` and `STREAM_MEDIA_TYPE` have been there since 1.x. The entry
points from PHP:

| Class / constant | What it is |
|---|---|
| `Symfony\UX\Turbo\TurboBundle::STREAM_FORMAT` | `'turbo_stream'` — the Symfony request format, registered for every request by `ux-turbo`'s `RequestListener` |
| `Symfony\UX\Turbo\TurboBundle::STREAM_MEDIA_TYPE` | `'text/vnd.turbo-stream.html'` |
| `Symfony\UX\Turbo\TurboStreamResponse` | A `Response` that sets that content type itself, with a fluent `append()` / `prepend()` / `replace()` / `update()` / `remove()` / `before()` / `after()` / `refresh()` / `action()` |
| `Symfony\UX\Turbo\Helper\TurboStream` | The same actions as static methods returning the `<turbo-stream>` string, for when you are assembling one by hand |

And in Twig, the bundle ships anonymous components for the same actions:
`<twig:Turbo:Stream:Append target="#book_list">…</twig:Turbo:Stream:Append>`, plus
`Prepend`, `Replace` (with `morph`), `Update`, `Remove`, `Before`, `After`, `Refresh` and
the generic `<twig:Turbo:Stream action="…">`. They are ordinary Twig components — use
them or write the tag by hand, they produce the same markup. Note the prop is named
`target` (singular) and renders as `targets`, so it takes a **selector**: verified,
`<twig:Turbo:Stream:Append target="#book_list">` outputs
`<turbo-stream action="append" targets="#book_list">`.

## Answering a form submission with a stream

**Turbo already asks for it.** On every non-GET submission it adds
`text/vnd.turbo-stream.html` to the `Accept` header — verified in the Turbo runtime:
the stream type is requested when the request is not safe, *or* when `data-turbo-stream`
is present on the form or the submitter. So a GET form or a link needs that attribute
explicitly; a POST, PUT, PATCH or DELETE form does not.

The controller therefore only has to decide to answer in that format:

```php
#[Route('/books', name: 'book_add', methods: ['POST'])]
public function add(Request $request, BookCreator $creator): Response
{
    $form = $this->createForm(AddBookType::class);
    $form->handleRequest($request);

    if (!$form->isSubmitted() || !$form->isValid()) {
        // 422 + full page, which is what Turbo needs to re-render the form with errors
        return $this->render('book/new.html.twig', ['form' => $form]);
    }

    $book = $creator->create($form->getData());

    if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('book/add.stream.html.twig', [
            'book' => $book,
            'count' => $this->shelf->count(),
        ]);
    }

    return $this->redirectToRoute('book_index', status: Response::HTTP_SEE_OTHER);
}
```

```twig
{# templates/book/add.stream.html.twig #}
<turbo-stream action="append" targets="#book_list">
    <template>{{ include('book/_book_row.html.twig', { book: book }) }}</template>
</turbo-stream>

<turbo-stream action="update" targets="#book_count">
    <template>{{ count }}</template>
</turbo-stream>

<turbo-stream action="prepend" targets="#flashes">
    <template>{{ include('_flash.html.twig', { type: 'success', message: 'book.flash.added'|trans }) }}</template>
</turbo-stream>
```

Three things that make this shape the right one, all verified by running it:

- **`setRequestFormat()` is what sets the content type.** `render()` returns a `Response`
  with no `Content-Type`, and `Response::prepare()` then fills it from the request
  format. With the call: `text/vnd.turbo-stream.html; charset=UTF-8`. Without it:
  `text/html`, and Turbo ignores the body — the classic "my stream does nothing".
- **The `getPreferredFormat()` guard keeps the action usable without Turbo.** Verified:
  the same POST with no stream in `Accept` falls through to the 303 redirect, so the
  form still works with JavaScript disabled, from a test client, or from curl. An action
  that *only* answers in stream format is broken for everyone else.
- **The invalid branch stays a normal 422 HTML render**, even when the request accepted a
  stream. Verified: 422 with `text/html`. Do not try to be clever and stream the form
  errors back; Turbo's 422 handling already re-renders the page with them.

`#[Template]` works here too — it renders the template into a `Response` exactly as
`render()` does, so a request whose format was set to `turbo_stream` comes back with the
stream content type. Verified: `#[Template('book/add.stream.html.twig')]` on an action
that calls `$request->setRequestFormat(TurboBundle::STREAM_FORMAT)` and returns an array
produces `text/vnd.turbo-stream.html; charset=UTF-8`. The attribute knows nothing about
Turbo; it simply does not get in the way.

The alternative, when the fragments are one-liners not worth a template:

```php
return (new TurboStreamResponse())
    ->append('#book_list', $this->renderView('book/_book_row.html.twig', ['book' => $book]))
    ->update('#book_count', (string) $count)
    ->remove('#empty_state');
```

(On PHP 8.4 the outer parentheses are optional — `new TurboStreamResponse()->append(…)`
parses. Below 8.4 they are required.)

Prefer the template for anything with markup in it: the fragments then live next to the
page's other templates, and `_book_row.html.twig` is the *same* include the full page
uses — which is the only thing that keeps the two rendering paths from drifting.

## `target` and `targets` are not the same attribute

Turbo reads them differently, verified in the Turbo runtime: `target` goes through
`getElementById()` and takes a **plain id**, `targets` goes through `querySelectorAll()`
and takes a **CSS selector** — and it may match several elements.

`TurboStreamResponse` and the `TurboStream` helper both emit `targets`. So the value you
pass them must be a selector:

```php
$response->append('#book_list', $html);   // ✔ matches
$response->append('book_list', $html);    // ✘ silently matches nothing
```

This is the second cause of "the stream arrived and nothing moved", after the content
type. Neither fails loudly.

## Streams over Mercure

The third case — nobody clicked, so there is no request to answer — needs a transport.
`ux-turbo` ships a Mercure bridge:

```twig
{{ turbo_stream_from('notifications') }}
```

(`turbo_stream_listen()` still works and is what older code uses; it is **deprecated
since ux-turbo 3.1** in favour of `turbo_stream_from()`. Verified in
`vendor/symfony/ux-turbo/src/Twig/TwigExtension.php`.)

The page subscribes to the topic; the server publishes stream fragments to the Mercure
hub; connected browsers apply them. This is the shape for a notification bell, a
"someone else edited this record" banner, or a progress bar fed by a Messenger handler.
It is *not* the shape for "the user submitted a form and three boxes must update" — that
is a stream response, above, and it needs no hub at all.

Be honest about the cost before proposing it: **a Mercure hub is another process to run,
monitor and secure**, with its own JWT configuration, and it must be reachable from the
browser. On a project that does not already have one, "add a live notification" is an
infrastructure decision, not a template change. Say so.

## `#[Broadcast]`

```php
#[Broadcast]
class Review { … }
```

On a Doctrine entity, this publishes a stream to Mercure on every insert, update and
delete, rendering `templates/broadcast/Review.stream.html.twig`.

It is genuinely useful for a live-updating admin table. It is also a rule that lives on
an entity and fires from a Doctrine flush — far from the code that caused it, and
outside the "services hold the rules, entities hold data" line this standard draws
elsewhere. Prefer an explicit publish from the service that performed the change; reach
for `#[Broadcast]` when the requirement really is "every change to this table, whoever
made it".

## Stimulus: the shape of a controller

```js
// assets/controllers/character_counter_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'count'];
    static values = { max: Number };

    connect() {
        this.update();
    }

    update() {
        const remaining = this.maxValue - this.inputTarget.value.length;
        this.countTarget.textContent = remaining;
        this.countTarget.classList.toggle('is-over', remaining < 0);
    }
}
```

```twig
<div {{ stimulus_controller('character-counter', { max: 280 }) }}>
    <textarea data-character-counter-target="input"
              data-action="input->character-counter#update"></textarea>
    <span data-character-counter-target="count"></span>
</div>
```

- The **filename decides the identifier**: `character_counter_controller.js` →
  `character-counter`. Get it wrong and the controller silently never connects.
- **`connect()` runs on every insertion** — first load, Turbo navigation, a Live
  Component re-render that introduces the element. **`disconnect()` is where you remove
  listeners and clear timers**; skipping it is how a Turbo application leaks across
  fifty navigations.
- Prefer the `stimulus_controller()` / `stimulus_action()` / `stimulus_target()` Twig
  functions over hand-written `data-` attributes: they escape values correctly and they
  merge when several controllers share an element.
- **One controller per behaviour, named after the behaviour.** `clipboard`,
  `character-counter`, `sortable`. A `book-page` controller accumulates every unrelated
  interaction on that page and is reusable nowhere.
- Mark a rarely used controller lazy with `/* stimulusFetch: 'lazy' */` above the class:
  it is then only fetched when a matching element appears.

## No business rule in JavaScript

A Stimulus controller may compute what to *show*. It may not decide what is *true*.

| Legitimate in JS | Belongs on the server |
|---|---|
| Enable the submit button when a field is non-empty | Whether the value is valid |
| Show a warning past 280 characters | Rejecting the submission past 280 characters |
| Format a number for display | Computing the price |
| Hide an admin-only menu item | Whether the user may perform the action |

The reason is not purity. A rule expressed twice diverges the first time one copy is
changed — and the client copy is the one an attacker edits. Client-side checks are
ergonomics; the server's copy is the rule. If a threshold must appear in both places,
pass it from the server as a Stimulus value rather than typing the number twice.

The same rule closes the inline-`<script>` question. A `<script>` in a template runs
once, does not survive a Turbo navigation, cannot be tested, cannot be reused, and is
invisible to `importmap:audit`. The only acceptable form is a
`<script type="application/json">` data island that a controller reads.

## Debugging

| Symptom | Cause |
|---|---|
| Controller never connects | Filename must be `<name>_controller.js` in `assets/controllers/`, element `data-controller="<name>"` |
| Works on first load, dead after clicking a link | Code outside a Stimulus controller: inline `<script>`, `DOMContentLoaded` |
| Full reload on every navigation | A **tracked** element differs per request — almost always a per-request `nonce` sitting on the same `<script>` tags as `data-turbo-track="reload"`. Untracked `<head>` content is merged and is not the cause |
| Frame does not update, "content missing" | The response has no `<turbo-frame>` with the same id |
| A form redirect does nothing visible | Turbo needs a 3xx redirect, or a 422 to re-render an invalid form. A 200 with the form HTML is not a valid Drive response |
| A stream response is downloaded as a file, or shown as raw markup | It went out as `text/html`. `$request->setRequestFormat(TurboBundle::STREAM_FORMAT)` before rendering, or return a `TurboStreamResponse` |
| The stream response is correct and nothing moves | `targets` is a CSS selector: `targets="#book_list"`, not `targets="book_list"`. Or the element genuinely is not in the DOM |
| A GET form or a link never gets a stream | Turbo only asks for the stream type on unsafe methods; add `data-turbo-stream` to the form or the submitter |
| Pushed stream published, nothing happens | No `turbo_stream_from()` on the page, the hub is unreachable, or the selector matches nothing in the DOM |
| Memory grows across navigations | Listeners registered in `connect()` and never removed in `disconnect()` |
