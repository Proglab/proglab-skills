# What must never reach a log

A log line is not a private note to yourself. It is copied into a nightly backup, sent
to a third-party aggregator over the internet, pasted into a support ticket, and shown
on a screen in an open-plan office. Anything written there should be assumed readable
by more people than the database it came from — and, unlike the database, it is rarely
covered by a retention policy or a deletion request.

## The list

| Never | Why it is worse than it looks |
|---|---|
| Passwords, in any form | Including the "wrong" one a user typed — that is usually their password for another site |
| Tokens: session ids, API keys, JWTs, reset and confirmation tokens, `Authorization` headers | A reset token in a log is a working account takeover for as long as it is valid |
| Card numbers, CVV, IBAN | Also a compliance question, not only a security one |
| Full request bodies and full query strings | The cheap way to log all of the above at once |
| Personal data beyond the identifier you need | An id, not a name plus an email plus an address |
| Health, religion, political, biometric data | Special categories under GDPR. There is no acceptable reason for these in a log |

The one you will argue about: **the user identifier**. It is genuinely useful for
support and incident work, and Symfony's `TokenProcessor` adds it (usually an email
address) to every record. Log the database id where you can, accept the email where you
must, and be able to say how long logs are kept.

## The mechanism: a redacting processor

```php
#[AsMonologProcessor(priority: -1000)]
final readonly class RedactingProcessor
{
    private const array REDACTED_KEYS = [
        'password', 'plainpassword', 'token', 'api_key', 'apikey',
        'secret', 'authorization', 'cookie', 'credit_card', 'iban',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($key) && \in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $values[$key] = '[redacted]';
                continue;
            }

            if (\is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
```

Measured on Symfony 8.1: with

```php
$logger->error('signup failed for {email} with {password}', [
    'email' => 'reader@example.com',
    'password' => 'hunter2',
    'payload' => ['token' => 'abc123', 'title' => 'Dune'],
]);
```

the record written was

```
app.ERROR: signup failed for reader@example.com with [redacted]
{"email":"reader@example.com","password":"[redacted]","payload":{"token":"[redacted]","title":"Dune"}}
```

Two things that result proves:

- **Nesting works.** `payload.token` was redacted, so a whole DTO or form array dumped
  into the context is covered.
- **The interpolated message was redacted too.** A logger-level processor runs before
  the handler-level `PsrLogMessageProcessor` that expands `{placeholders}`, so
  `{password}` came out as `[redacted]`. `priority: -1000` only orders it against other
  *logger* processors — it does not push it after the handlers.

`priority:` on the attribute needs Monolog 3.4 and monolog-bundle 3.11; below that, use
the YAML tag with a `priority` key, or drop the priority (the ordering only matters
against other processors of your own).

### What it cannot do

`$logger->error('login failed for '.$email.' / '.$password)` passes straight through.
A processor sees keys, not prose. **The rule that makes redaction possible is: the
message string is a constant, and everything variable goes in the context array.** That
is the same discipline PSR-3 asks for, and it is why `{placeholders}` exist.

## Where secrets get in without anyone writing a log call

### Doctrine's SQL logging

`Doctrine\DBAL\Logging\Statement::execute()` logs, at `debug`:

```
Executing statement: {sql} (parameters: {params}, types: {types})
```

`params` is every bound value: the hashed password on registration, the reset token on
lookup, the email on every login. `doctrine.dbal.logging` defaults to `%kernel.debug%`,
so it is **off** in production — the risk is the afternoon someone turns it on to chase
a slow query and forgets. Note the interaction: `fingers_crossed` at `level: debug` is
buffering those records, so the next error flushes fifty statements' worth of
parameters into the log at once.

If you must profile against production data, do it with an explicit, time-boxed change
and turn it off in the same session.

### Exception messages

`ErrorListener` logs `Uncaught PHP Exception <class>: "<message>"`. Whatever you put in
the exception message is now in the log — verified: an exception constructed with
`sprintf('No book #%d in the library', $bookId)` appears in full. Ids are fine. Do not
build exception messages out of the value that failed validation when that value is a
password, a token or a card number.

### URLs

`WebProcessor` records the request URI, query string included. Any design that puts a
token in a URL — password reset links, signed URLs, magic login links — logs that token
on every request. Prefer POST bodies; where a URL token is unavoidable, restrict
`WebProcessor`'s `$extraFields` and keep the token short-lived and single-use.

### Serialised messages in the queue

The Doctrine Messenger transport stores the serialised envelope in
`messenger_messages.body`, and DBAL logging then writes that whole blob when it is
inserted. A message carrying only identifiers — which this standard requires for
unrelated reasons — is also the version that does not put personal data in two places.

### Third-party handlers

`slackwebhook` and `slack` handlers take an `exclude_fields` option (monolog-bundle
3.11+) listing dotted paths to strip before sending, e.g. `['context.exception']`.
Useful, but it is a per-handler patch: the redacting processor is the thing that
protects *every* sink at once.

## Verifying it

The check is cheap and worth automating:

```bash
php bin/console debug:container --tag=monolog.processor
```

The redactor must be there, and it must have no `channel` or `handler` value — scoped
to one channel, it silently stops covering the others.

Then assert it in a test the same way as any other rule, with a `TestHandler`:

```php
#[Test]
public function it_redacts_credentials_from_the_context(): void
{
    $handler = new TestHandler();
    $logger = new Logger('app', [$handler], [new RedactingProcessor()]);

    $logger->error('signup failed', ['email' => 'reader@example.com', 'password' => 'hunter2']);

    self::assertSame('[redacted]', $handler->getRecords()[0]->context['password']);
    self::assertSame('reader@example.com', $handler->getRecords()[0]->context['email']);
}
```

Asserting the *second* line as well is the point: it pins the deliberate decision that
the identifier survives, so a later broadening of `REDACTED_KEYS` that swallows it goes
red instead of quietly destroying your ability to investigate anything.

## Retention, briefly

Where logs live is out of scope for this skill, but one question belongs to whoever
writes the log call: **how long does this line survive?** If the answer is "forever, in
an S3 bucket nobody owns", then the identifier you were comfortable logging for a week
is a different decision. Ask before adding a field, not after an audit.
