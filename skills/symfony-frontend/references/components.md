# Twig Components and Live Components

Verified against `ux-twig-component` / `ux-live-component` 3.4. When a version differs,
`vendor/` wins over this document.

## Contents

- [Twig Components](#twig-components)
- [Anonymous components](#anonymous-components)
- [Props are DTOs](#props-are-dtos)
- [Live Components](#live-components)
- [`LiveProp`](#liveprop)
- [Actions](#actions)
- [The remount cost](#the-remount-cost)
- [Live actions are real controllers, so secure them](#live-actions-are-real-controllers-so-secure-them)
- [Talking between components](#talking-between-components)
- [Forms in a Live Component](#forms-in-a-live-component)
- [Testing](#testing)
- [Symptoms](#symptoms)

## Twig Components

A component is a class plus a template. No state, no interactivity — a function from
props to HTML.

```php
<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\Output\BookCardView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

#[AsTwigComponent]
final class BookCard
{
    public BookCardView $book;
    public bool $compact = false;

    #[ExposeInTemplate('cover_url')]
    public function coverUrl(): string
    {
        return $this->book->coverPath ?? '/images/book-placeholder.svg';
    }
}
```

```twig
{# templates/components/BookCard.html.twig #}
<article {{ attributes.defaults({ class: 'book-card' }) }}>
    <img src="{{ cover_url }}" alt="">
    <h3>{{ book.title }}</h3>
    {% if not compact %}<p>{{ book.summary }}</p>{% endif %}
</article>
```

```twig
<twig:BookCard :book="book" compact class="book-card--wide" />
```

Points that are easy to get wrong:

- **Public properties are the props.** `:book="book"` passes a value, `compact` alone
  passes `true`, `class="…"` is not a prop — it lands in `attributes`.
- **`attributes.defaults({…})`** merges caller-supplied HTML attributes with your
  defaults instead of letting either win silently. Rendering `{{ attributes }}` bare
  works too; `defaults()` is what makes a component styleable from the outside.
- **`#[ExposeInTemplate]`** publishes a method result as a plain template variable, so
  the template reads `{{ cover_url }}` rather than `{{ this.coverUrl }}`. Templates stay
  readable and the component keeps its logic in PHP.
- Template resolution defaults to `templates/components/<Name>.html.twig`; override with
  `#[AsTwigComponent(template: '…')]`. The namespace and directory come from
  `config/packages/twig_component.yaml`.

## Anonymous components

A component with no behaviour does not need a class. A file at
`templates/components/Badge.html.twig` is usable as `<twig:Badge label="New" />`
immediately, with `{% props label, tone = 'neutral' %}` at the top declaring its props.

Use it for anything with no PHP logic. Adding an empty class "for consistency" adds a
service, a discovery pass and a file, and buys nothing.

## Props are DTOs

```twig
<twig:BookCard :book="book" />
```

`book` here is a `BookCardView`, never a `Book` entity. A Twig template holding an
entity triggers lazy loading during rendering: `book.author.name` in a loop is an N+1
that appears in no PHP file, and `book.ratings|length` hydrates every rating row in
order to count them. The queries fire inside template rendering, far from the
repository, and no service-level test can see them.

Build the DTO in the service (a `SELECT NEW` projection, or a mapping from the entity),
pass it in, and the component's data requirements become a type signature instead of
an implicit contract with the ORM.

## Live Components

A Live Component is a Twig Component whose state is serialised into the DOM and which
re-renders itself over HTTP when that state changes.

**That serialised state is the whole reason to reach for one.** The arbitration in
`SKILL.md` is a single question — *is there state to remember between two interactions?*
A filter that remembers what it filters on, a search refining as you type, a form
validated field by field, a wizard on step three: yes, and the component is right. A
button that posts once and updates three boxes: no, and the cheaper answer is a Turbo
Stream returned as the response to that submission — no class, no lifecycle, no state in
the DOM. See `references/turbo-and-stimulus.md`.

```php
<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\Output\BookListView;
use App\Service\BookSearch;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class BookList
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $query = '';

    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(private readonly BookSearch $search)
    {
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $page): void
    {
        $this->page = $page;
    }

    public function getResults(): BookListView
    {
        return $this->search->paginate($this->query, $this->page);
    }
}
```

```twig
<div {{ attributes }}>
    <input type="search" data-model="debounce(400)|query" value="{{ query }}">

    {% for book in this.results.items %}
        <twig:BookCard :book="book" />
    {% endfor %}

    <button data-action="live#action"
            data-live-action-param="goToPage"
            data-live-page-param="{{ this.results.page + 1 }}">Next</button>
</div>
```

`{{ attributes }}` on the root element is mandatory — it carries the serialised state
and the Stimulus controller. Without it the component renders once and never reacts.

## `LiveProp`

Only properties marked `#[LiveProp]` are part of the state. Everything else is
recomputed from scratch on each request, which is usually what you want for derived
data and never what you want for something the user changed.

| Option | Effect |
|---|---|
| `writable: true` | The browser may change it (`data-model`). Without it the prop is read-only from the front end |
| `writable: ['title', 'isbn']` | Only these paths of an object prop are writable — the safe way to bind a form-like DTO |
| `url: true` | Mirrors the value into a query parameter, so a filtered list is bookmarkable and survives a reload |
| `onUpdated: 'method'` | Called after the prop changes; the old value is passed in. Where you reset `page` to 1 when `query` changes |
| `format: 'Y-m-d'` | Serialisation format for date props |
| `updateFromParent: true` | A parent re-render pushes the new value into the child |

**`writable: true` is a trust boundary.** The value comes from the browser, signed
against tampering but entirely user-chosen. A writable `$sortBy` interpolated into DQL
is an injection; a writable `$userId` is an authorisation hole. Validate it, or make it
non-writable and expose an action instead.

## Actions

`#[LiveAction]` marks a method callable from the template; `#[LiveArg]` binds a
`data-live-<name>-param` attribute to a parameter. Services can be autowired as extra
arguments of the action method.

`data-model` on an input writes a prop and re-renders. **Always debounce a text
input** — `debounce(400)|query` — otherwise every keystroke is one HTTP request and one
full render, and a search field over a slow query becomes a self-inflicted load test.

Two attributes worth knowing when the re-render fights you:

- `data-live-ignore` — the subtree is never touched by the morphing. Necessary around
  anything a third-party library owns (a map, a rich text editor), which would otherwise
  be reset on every render.
- `data-live-preserve` — keeps an existing element across re-renders instead of
  replacing it, preserving DOM state such as focus or scroll position.

## The remount cost

**Every interaction is a full HTTP request, and the component object is built again.**
Constructor runs, `mount()` runs, props rehydrate from the DOM, the template renders
completely. Nothing survives in a plain property between two clicks.

The practical consequence is that `getResults()` above runs on **every keystroke**. That
is acceptable when it is one indexed, paginated query; it is not acceptable when the
component fans out into a dozen queries or an external API call. The mitigations, in
order of preference: debounce the input, paginate rather than loading everything, keep
the query in a repository method that a query-count test covers (see
`symfony-performance`), and only then reach for a cache.

The same logic explains the DTO rule from the other direction: a `LiveProp` holding an
entity is serialised into the DOM and rehydrated through the ORM on every single
interaction.

It also explains why a stateless interaction should not be a Live Component at all: you
pay the remount — constructor, `mount()`, rehydration, full render — for state the
component never had. One form POST answered with a Turbo Stream pays none of it.

## Live actions are real controllers, so secure them

A `#[LiveAction]` is dispatched through the HTTP kernel as a genuine controller
(`_controller` is rewritten to `component::action`). Two consequences:

- `#[IsGranted('BOOK_EDIT', 'book')]` on the action method works exactly as it does on
  a controller action, and `#[IsGranted]` on the component class covers all of them.
- Conversely, **an action with no check is a publicly reachable endpoint.** It is not
  protected by the fact that the button rendering it is only shown to admins — the
  route is `ux_live_component` and anyone can post to it. Treat every `#[LiveAction]`
  as a route in the security review.

## Talking between components

`ComponentToolsTrait` gives `emit()`, `emitUp()`, `emitSelf()` and
`dispatchBrowserEvent()`; the receiving component declares
`#[LiveListener('book:saved')]` on a method. Use it for genuinely separate components on
one page (a filter panel and a result list).

Before reaching for it, ask whether the two are really separate: two components that
always change together are one component split too early, and an event pair is harder
to follow than a shared prop.

## Forms in a Live Component

Two levels, and the choice matters:

- **`ValidatableComponentTrait`** — constraints on the component's own props,
  `validate()` / `validateField()` / `getError()`. Right for a small interaction: one
  field, an inline rename, a rating.
- **`ComponentWithFormTrait`** — wraps a real `FormType` (implement
  `instantiateForm()`), giving `getFormView()` and per-field live validation. Right when
  a form already exists as a PHP class and you want it validated as the user types.
  `LiveCollectionTrait` adds `addCollectionItem` / `removeCollectionItem` for
  `CollectionType`.

The FormType itself, its `data_class` and its input DTO belong to `symfony-http`. A Live
Component wraps a form; it does not replace it, and the server-side validation remains
the only validation that counts.

## Testing

```php
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BookListTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    #[Test]
    public function it_filters_on_query(): void
    {
        $component = $this->createLiveComponent(BookList::class);

        $component->set('query', 'dune');

        self::assertStringContainsString('Dune', (string) $component->render());
    }
}
```

`createLiveComponent()`, `set()`, `call()` and `render()` exercise the real hydration
and rendering path in a `KernelTestCase`, with no browser and no HTTP layer — so
interactive behaviour is cheap to test, and has no excuse for being the untested part
of the UI.

## Symptoms

| Symptom | Cause |
|---|---|
| A component whose props are the same on every render | It has no state between interactions. It wanted to be a controller answering with a Turbo Stream |
| The component renders but nothing reacts | `{{ attributes }}` missing from the root element |
| A value resets on every click | The property is not a `#[LiveProp]` |
| Typing in a field does nothing | The prop is not `writable: true` |
| One request per keystroke | No `debounce(…)` on the `data-model` |
| `The action "…" is not allowed` | Missing `#[LiveAction]` on the method |
| A third-party widget resets on every render | Wrap it in `data-live-ignore` |
| Hundreds of queries on a live search | An entity in a `LiveProp`, or an unpaginated query in the render path |
| An action runs for a user who should not reach it | No `#[IsGranted]` — the button being hidden protects nothing |
