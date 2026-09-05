# Email

Asynchronous by default, rendered by the worker — which is where the traps come from.

## Contents

- [What actually happens when you call `send()`](#what-actually-happens-when-you-call-send)
- [The serialisation trap](#the-serialisation-trap)
- [Writing the email](#writing-the-email)
- [CSS inlining](#css-inlining)
- [Configuration per environment](#configuration-per-environment)
- [Testing email](#testing-email)
- [When email is not delivered](#when-email-is-not-delivered)

## What actually happens when you call `send()`

With Messenger installed, `MailerInterface::send()` does not send anything. It wraps the
message in a `SendEmailMessage` and dispatches it; the recipe routes that to `async`.

The consequences are worth spelling out, because most email surprises come from here:

1. The controller returns before the email exists as bytes.
2. `MessageEvent` is dispatched twice: once at dispatch time with `queued = true` on a
   **clone** (so listeners can inspect it), and once in the worker with `queued = false`
   on the real message.
3. **Twig rendering happens in the worker**, in the second event. `MessageListener` runs
   `BodyRenderer` there. The clone rendered at dispatch time is thrown away.
4. What travels through the queue is therefore the template *name* plus the context
   array, serialised with PHP's `serialize()`.

Point 4 is the whole reason for the next section.

## The serialisation trap

```php
// Broken. It may even pass locally with a sync transport.
$email->context(['review' => $review]);
```

A Doctrine entity in the context is serialised into the queue row. Three ways that goes
wrong, in increasing order of how long they take to diagnose:

- **It fails loudly**: the property was a lazy proxy, and deserialising it throws
  `Cannot instantiate proxy` or drags an uninitialised collection along.
- **It fails quietly**: the entity serialises fine, the worker renders it, and the email
  shows the review's title from before the user corrected it thirty seconds ago.
- **It fails later**: someone adds a relation to the entity, the serialised payload
  triples in size, and the queue table grows for reasons nobody connects to that commit.

Two correct shapes. Prefer the first.

```php
// Scalars only — the template gets exactly what it renders.
$email = (new TemplatedEmail())
    ->to(new Address($authorEmail, $authorName))
    ->subject('Your review has been published')
    ->htmlTemplate('emails/review_published.html.twig')
    ->context([
        'reviewTitle' => $review->getTitle(),
        'bookTitle' => $review->getBook()->getTitle(),
        'reviewUrl' => $this->urlGenerator->generate(
            'review_show',
            ['id' => $review->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ),
    ]);

$this->mailer->send($email);
```

```php
// Or render now and queue plain HTML, when the content must reflect this exact moment.
$email = (new Email())
    ->to($authorEmail)
    ->subject('Your review has been published')
    ->html($this->twig->render('emails/review_published.html.twig', ['review' => $review]));
```

Rendering early costs you the worker's isolation — a Twig error now breaks the request
instead of a background job — so it is the exception, not the default.

The identifier rule from `SKILL.md` is the same rule seen from another angle: **an id
plus the scalars the template needs**, never the object graph.

Absolute URLs are also part of this. In a worker there is no request, so a relative
`path()` in an email template produces a link to nowhere. Generate with
`UrlGeneratorInterface::ABSOLUTE_URL`, or use `url()` in Twig with
`framework.router.default_uri` set — and set it, because `http://localhost` in a
production email is a support ticket.

## Writing the email

`TemplatedEmail` (`Symfony\Bridge\Twig\Mime\TemplatedEmail`) takes `htmlTemplate()` and
optionally `textTemplate()`. With only an HTML template, Symfony derives the text part by
stripping tags, which is usually adequate; write a text template when the HTML is
layout-heavy enough that stripping it produces noise.

Building the email belongs in a service — a `ReviewMailer`, not the controller and not
the handler. That service is what gets unit-tested, and it is what the direct-call rule
in `symfony-yoandev-architecture` points at when a use case ends with "and notify the author".

## CSS inlining

Email clients ignore `<style>` blocks unevenly, so the CSS has to end up in `style=`
attributes. Symfony does this in Twig:

```bash
composer require twig/cssinliner-extra
```

`twig/extra-bundle` registers `CssInlinerExtension` automatically once the package is
present, which adds the `inline_css` filter. Then:

```twig
{% apply inline_css(source('@styles/email.css')) %}
    <h1>{{ reviewTitle }}</h1>
{% endapply %}
```

Two things to know: the filter runs at render time, which for a queued email is **in the
worker**, so the stylesheet must be readable from the worker's filesystem; and it is a
per-render cost on every email, so keep the stylesheet small rather than piping your
whole application CSS through it.

## Configuration per environment

```yaml
# config/packages/mailer.yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'

when@dev:
    framework:
        mailer:
            envelope:
                recipients: ['dev@example.com']
```

```yaml
# config/packages/messenger.yaml — the recipe writes this line with `async`; this
# standard routes email to the high-priority transport, because a signup confirmation
# must not queue behind a nightly export (see references/messenger.md).
framework:
    messenger:
        routing:
            Symfony\Component\Mailer\Messenger\SendEmailMessage: async_high
```

`envelope.recipients` rewrites every recipient, which is what you want on a staging
environment restored from a production dump. `allowed_recipients` (a list of regular
expressions, Symfony 7.1+) punches holes in it so real addresses on your own domain still
get through; before 7.1, `recipients` is all-or-nothing.

Locally, `MAILER_DSN` points at Mailpit and is injected by the Symfony CLI — see
`symfony-yoandev-local-dev`. **Nothing appears in Mailpit until a worker consumes the
transport.** That is the single most common "my emails are broken" report on this
standard, and the answer is `symfony console messenger:consume async -vv`.

## Testing email

```php
// The service that builds the email — a unit test, no kernel.
$mailer = $this->createMock(MailerInterface::class);
$mailer->expects(self::once())
    ->method('send')
    ->with(self::callback(static function (TemplatedEmail $email): bool {
        self::assertSame('emails/review_published.html.twig', $email->getHtmlTemplate());
        self::assertArrayNotHasKey('review', $email->getContext());

        return true;
    }));
```

Asserting that the context has no entity in it is worth writing once: it is the rule
that breaks silently, and this is the only place that notices.

```php
// Functionally, through the kernel.
$client->request('POST', '/reviews/12/publish');

self::assertQueuedEmailCount(1);          // not assertEmailCount()
$email = self::getMailerMessage(0);
self::assertEmailAddressContains($email, 'To', 'author@example.com');
self::assertEmailHtmlBodyContains($email, 'has been published');
```

`assertEmailCount()` counts events where `queued` is false. With mail routed to `async`
there are none, and the assertion fails with "has sent 0 emails" while the mail is
sitting in the transport — accurate, and completely misleading if you do not know why.

The body assertions still work on a queued email, because the clone captured by
`MessageLoggerListener` at dispatch time was rendered before being discarded. Useful, and
worth knowing it is a side effect rather than a guarantee to build a suite around.

Both assertions need `framework.test: true` and a booted client; they read the
`mailer.message_logger_listener` service, and fail with an explicit message if Mailer is
not installed.

## When email is not delivered

| Symptom | Cause |
|---|---|
| Nothing in Mailpit | No worker consuming `async` |
| `assertEmailCount()` fails, `assertQueuedEmailCount()` passes | Working as designed — use the queued assertion |
| `Cannot instantiate proxy` in the worker | A Doctrine entity in the email context |
| Links point at `http://localhost` | No `framework.router.default_uri`, or `path()` instead of `url()` |
| Styles missing in Gmail | `inline_css` not applied, or the stylesheet is unreadable from the worker |
| Sent twice | A retried handler that sends before recording that it sent — idempotency, rule 2 |
| Correct in dev, empty in prod | `MAILER_DSN` still `null://null` in the deployed environment |
