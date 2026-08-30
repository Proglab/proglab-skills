# Forms, HTML responses and templates

The HTML half of the HTTP layer. Checked against `vendor/symfony/form/`,
`twig-bridge/`, `security-http/` and `vendor/twig/twig/src/Attribute/` on Symfony 8.1.
**`vendor/` is the source of truth over this file.**

## The FormType

`src/Form/`, `final`, `data_class` set to an **input DTO** from `src/Dto/Input/`.

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\Input\AddBookInput;
use App\Enum\ReadingStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AddBookType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'book.form.title',
                'empty_data' => '',
            ])
            ->add('pageCount', IntegerType::class, [
                'label' => 'book.form.page_count',
                'empty_data' => '0',
            ])
            ->add('status', EnumType::class, [
                'label' => 'book.form.status',
                'class' => ReadingStatus::class,
            ])
            ->add('lastReadAt', DateType::class, [
                'label' => 'book.form.last_read_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddBookInput::class,
        ]);
    }
}
```

### `data_class` is never an entity

Binding a form to an entity lets an unvalidated request write directly into a managed
object. Any `flush()` later in the same request — in an unrelated service, in a listener
— persists it. The DTO removes the possibility rather than relying on everyone
remembering.

It also collapses the two entry points into one rule set: the same
`AddBookInput`, with the same constraints, is what `#[MapRequestPayload]` hydrates on the
`api_` route and what the form hydrates on the HTML route. One definition of "a valid
book", two transports.

### No buttons in the FormType

`$builder->add('submit', SubmitType::class)` moves a label and a CSS class into a class
that has no idea whether it is rendering a creation page, a modal or an inline edit. The
button belongs to the template:

```twig
{{ form_start(form) }}
    {{ form_row(form.title) }}
    …
    <button type="submit" class="button">{{ 'book.form.submit'|trans }}</button>
{{ form_end(form) }}
```

The same reasoning applies to `form_start` attributes and to the layout: the FormType
declares fields and their types, the template decides how they look.

### No constraints in the FormType

Not `'constraints' => [new NotBlank()]` on a field, not `constraints` on the form. The
constraints are attributes on the DTO. Two places to declare validation means the API
route and the HTML route can disagree, and the disagreement is discovered by a user.

`#[UniqueEntity]` also goes on the DTO, with `entityClass:` — the details, including why
`errorPath` and `identifierFieldNames` matter, are in `references/input-mapping.md`.

## `empty_data`, and readonly DTOs

An unfilled text input submits `''`, and the Form component maps a missing value to
`null`. Written into a typed non-nullable property that is a `TypeError` before the
validator ever runs — the symptom is a 500 on an empty form rather than the "this field
is required" you expected.

**Mutable DTO** (public properties with defaults, hydrated property by property): set
`empty_data` per field to the neutral value of that field's type, and let the constraint
report the emptiness.

```php
public string $title = '';
public int $pageCount = 0;
```

```php
->add('title', TextType::class, ['empty_data' => ''])
->add('pageCount', IntegerType::class, ['empty_data' => '0'])   // a string: the view value
```

`empty_data` at field level is a *view* value — it goes through the field's data
transformer — which is why `IntegerType` takes `'0'` and not `0`.

**Readonly DTO** with a promoted constructor: the Form component cannot write properties,
so it needs a form-level `empty_data` callable that builds the object from the submitted
children.

```php
use Symfony\Component\Form\FormInterface;

$resolver->setDefaults([
    'data_class' => ReviewInput::class,
    'empty_data' => static fn (FormInterface $form): ReviewInput => new ReviewInput(
        body: (string) $form->get('body')->getData(),
        rating: (int) $form->get('rating')->getData(),
    ),
]);
```

Verified: submitting `['body' => 'Great', 'rating' => '5']` against this yields a valid
form whose `getData()` is a `ReviewInput`. Note the casts — the children still hand you
`null` for empty inputs, and the constructor is typed.

Readonly is the better shape for a DTO, but it costs this callable, which has to be kept
in step with the constructor. On a form with fifteen fields, a mutable DTO with defaults
is the honest trade.

## One action renders and processes

GET and POST on the same route. Two actions means two route names, a duplicated form
construction, and a redirect between them that loses the submitted data on error.

```php
#[Route('/new', name: 'new', methods: ['GET', 'POST'])]
#[Template('book/new.html.twig')]
public function new(Request $request): array|Response
{
    $form = $this->createForm(AddBookType::class);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $book = $this->creator->create($form->getData());

        $this->addFlash('success', 'book.flash.added');

        return $this->redirectToRoute('book_index', status: Response::HTTP_SEE_OTHER);
    }

    return ['form' => $form];
}
```

Three details that are not decoration:

- **`$form->getData()` is the DTO**, and it goes straight to the service. The controller
  does not read individual fields.
- **`Response::HTTP_SEE_OTHER` (303)** on the redirect, not the default 302. Turbo and
  the Fetch API follow a 302 after a POST by re-issuing a POST; 303 forces the GET that
  Post/Redirect/Get is supposed to produce.
- **The invalid path returns the array**, which renders at **422**, not 200 — see below.

## `#[Template]`

```php
#[Template('book/index.html.twig')]
public function index(): array
```

The action returns template variables; a `Response` returned instead is passed through
untouched, because Symfony only dispatches the view event when the controller did not
return one. That is the whole `array|Response` idiom.

From `vendor/symfony/twig-bridge/EventListener/TemplateAttributeListener.php`:

| Behaviour | Consequence |
|---|---|
| A `FormInterface` in the array is converted to a `FormView` | Pass `$form`, never `$form->createView()` |
| A submitted, invalid form sets the status to **422** | Turbo re-renders the page with its errors instead of ignoring the response |
| Returning `null`/nothing uses the controller's named arguments as variables | `#[Template(vars: ['book'])]` restricts them; `vars: []` passes none |
| `stream: true` returns a `StreamedResponse`; `block: 'x'` renders one block | HTTP streaming and block rendering — unrelated to Turbo Streams, despite the word |

`AbstractController::render()` does the same `FormView` conversion and the same 422, so
the two are interchangeable. `#[Template]` is preferred because it keeps the action
returning data, which is what makes it unit-testable and symmetrical with `#[Serialize]`.

`#[Template]` lives in `Symfony\Bridge\Twig\Attribute` — not in `HttpKernel\Attribute`
where the other response attributes are. It exists since Symfony 6.2.

## Answering a submission with a Turbo Stream

Same action, one extra branch, when the submission has to update several disjoint zones
of the page at once instead of navigating:

```php
use Symfony\UX\Turbo\TurboBundle;

#[Route('/books', name: 'book_add', methods: ['POST'])]
public function add(Request $request): Response
{
    $form = $this->createForm(AddBookType::class);
    $form->handleRequest($request);

    if (!$form->isSubmitted() || !$form->isValid()) {
        return $this->render('book/new.html.twig', ['form' => $form]);   // 422, unchanged
    }

    $book = $this->creator->create($form->getData());

    if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('book/add.stream.html.twig', ['book' => $book]);
    }

    return $this->redirectToRoute('book_index', status: Response::HTTP_SEE_OTHER);
}
```

What belongs to this skill, verified on `ux-turbo` 3.4:

- **`setRequestFormat()` is what sets the content type.** `render()` returns a `Response`
  with none, and `Response::prepare()` fills it from the request format —
  `text/vnd.turbo-stream.html`. Skip the call and the body goes out as `text/html`, which
  Turbo ignores.
- **`#[Template]` behaves identically** — it renders into a `Response` the same way, so an
  action that sets the format and returns an array still comes back in stream format. The
  attribute knows nothing about Turbo and does not interfere.
- **Keep the `getPreferredFormat()` guard and the 303 fallback.** Verified: without a
  stream in `Accept`, the same POST redirects, so the action still works from curl, from
  a test client and with JavaScript off. An action that answers *only* in stream format
  is broken for everybody else.
- **The invalid branch does not change.** It is still the 422 HTML render above, even
  when the request accepted a stream — that is what Turbo re-renders the form errors
  from.

**Whether a given interaction should be a stream response, a Live Component or a pushed
stream is arbitrated in `symfony-frontend`**, on one question: is there state to remember
between two interactions? This skill only owns the controller half.

## State-changing actions without a form

A form carries a CSRF token automatically. A POST from a plain `<button>` — "mark as
read", "remove from shelf" — does not, so declare it:

```php
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/{id}/read', name: 'mark_read', methods: ['POST'], requirements: ['id' => '\d+'])]
#[IsCsrfTokenValid('mark_read')]
public function markRead(Book $book): Response
```

```twig
<form method="post" action="{{ path('book_mark_read', {id: book.id}) }}">
    <input type="hidden" name="_token" value="{{ csrf_token('mark_read') }}">
    <button type="submit">{{ 'book.action.mark_read'|trans }}</button>
</form>
```

Since Symfony 7.1. `tokenKey:` renames the field, `methods:` restricts which verbs are
checked, and `tokenSource:` (7.4) reads the token from the query string or a header
instead of the payload — `IsCsrfTokenValid::SOURCE_HEADER` is what a `fetch()` call
wants. Before 7.1, `$this->isCsrfTokenValid('mark_read', $request->request->getString('_token'))`
in the action; the rule is unchanged, only the placement.

`#[IsSignatureValid]` (7.4) covers the other case: a URL that must be tamper-proof
without a session — an unsubscribe link, a one-time download. `UriSigner::sign()` builds
it, the attribute verifies it.

```php
#[Route('/unsubscribe/{id}', name: 'unsubscribe', methods: ['GET'])]
#[IsSignatureValid]
public function unsubscribe(int $id): Response
```

The statuses are already right, and you do not need to map anything:
`SignedUriException` carries `#[WithHttpStatus(404)]` and `ExpiredSignedUriException`
extends it with `#[WithHttpStatus(403)]`. Measured with an empty `framework.exceptions`:
an unsigned URL gives **404**, a tampered one **404**, an expired one **403**.

404 rather than 403 on a bad signature is deliberate: a signed URL that does not verify
should look like it never existed, rather than confirming that something is there behind
a wrong signature.

Override the levels only if you want them logged differently:

```yaml
# config/packages/framework.yaml
framework:
    exceptions:
        Symfony\Component\HttpFoundation\Exception\SignedUriException:
            log_level: warning
```

**Below 7.4 the attribute does not exist.** The rule survives, the means change: inject
`Symfony\Component\HttpFoundation\UriSigner` (6.4+) and check at the top of the action,
throwing the same status yourself.

```php
public function unsubscribe(Request $request, int $id, UriSigner $signer): Response
{
    if (!$signer->checkRequest($request)) {
        throw $this->createNotFoundException();      // 404, for the reason above
    }
    // …
}
```

`checkRequest()` does not separate "expired" from "tampered", so on those versions both
answer 404 — the conservative direction, and the same one the attribute picks for a bad
signature.

`#[Cache]` also lives on actions, but adding it is a performance decision that needs a
measurement first — `symfony-performance` covers when it is justified and why
`public: true` on a personalised response is a data leak.

## Twig conventions

- **snake_case file names**, one directory per controller: `templates/book/index.html.twig`.
- **Fragments prefixed `_`**: `_book_card.html.twig`, `_form_errors.html.twig`. The
  prefix says at a glance that a file is included and not rendered by a route, which is
  the only thing you need to know when scanning a directory.
- **Translation keys name the intent, not the text**: `book.flash.added`, not
  `Book added!`. A key that quotes its English wording has to be renamed when a
  copywriter changes a word, and the rename is invisible to the compiler. XLIFF catalogues.
- **No business logic in a template.** `{% if book.rating > 3 %}` is a rule that nothing
  tests. Compute it in the service, expose it on the output DTO as `book.recommended`,
  and let the template ask a question it can answer.
- **No inline `<script>`.** Behaviour goes in a Stimulus controller, and no business rule
  goes in JavaScript at all — see `symfony-frontend`.
- **Twig filters and functions via `Twig\Attribute\AsTwigFilter` and friends**, never an
  `AbstractExtension` class. `symfony-frontend` owns that rule, with the package and
  version caveats; do not restate it here.

Form themes are declared once in `config/packages/twig.yaml`, never with
`{% form_theme %}` in a template; the Tailwind theme and the rest of the front-end
standard are in `symfony-frontend`.
