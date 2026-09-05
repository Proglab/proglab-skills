# HTTP assets

| File | Goes to | Purpose |
|---|---|---|
| `ProblemJsonEncoder.php` | `src/Serializer/` | Makes `Accept: application/problem+json` actually return Problem Details |

## Why the encoder is not optional

This suite mandates RFC 7807 for API errors. Symfony produces the right body already —
`ProblemNormalizer` handles the `ValidationFailedException` that `#[MapRequestPayload]`
throws — but **no encoder is registered for the `problem` format**.

So a client that asks correctly gets an HTML error page:

```
Accept: application/problem+json
  → request format `problem`
  → no encoder supports it
  → UnsupportedFormatException
  → caught as NotEncodableValueException by SerializerErrorRenderer
  → HTML error renderer
```

Six lines fix it. The class carries its own explanation in a header comment, because the
symptom it prevents is invisible unless someone tests the `Accept` header — and a file
that looks inert is a file someone deletes during a cleanup.

## Checking it works

```bash
curl -s -i -X POST https://localhost/api/books \
  -H 'Accept: application/problem+json' \
  -H 'Content-Type: application/json' \
  -d '{"title":""}'
```

Expect `422`, `Content-Type: application/problem+json`, and a body with `type`, `title`,
`status`, `detail` and `violations`. An HTML page means the encoder is not registered.

Worth an assertion in a functional test, so the day someone removes the class the suite
says so instead of the API quietly regressing.
