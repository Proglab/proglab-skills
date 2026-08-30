# Serving a stored file

Public URLs, a controller that streams a protected file, handing the transfer to the
web server, signed and pre-signed URLs, and the cache headers that quietly undo all of
it. Every behaviour below was executed against Symfony 8.1.5 / PHP 8.4 with
`league/flysystem` 3.35 and `league/flysystem-async-aws-s3` 3.31.
**`vendor/` is the source of truth over this file.**

## Public files

A file under `public/` needs no PHP. `asset()` resolves it:

```twig
<img src="{{ asset('uploads/covers/' ~ book.coverKey) }}" alt="">
```

Verified: with AssetMapper installed, a path that is not a mapped asset falls through to
the base path — `asset('uploads/covers/x.jpg')` returns `/uploads/covers/x.jpg`, and
returns exactly the same for a file that does not exist, with no error. A deleted file
is a broken image, never an exception. That is why the sweep in `storing-files.md`
deletes files only *after* the row is gone, never before.

Once the storage is a bucket behind a CDN the URL comes from the storage instead —
`$covers->publicUrl($book->getCoverKey())`, with two verified traps:

- `publicUrl()` lives on the concrete `League\Flysystem\Filesystem`, not on
  `FilesystemOperator`, so inject `#[Autowire(service: 'book_covers.storage')] Filesystem`.
- With no `public_url:` and no adapter support it throws
  `League\Flysystem\UnableToGeneratePublicUrl: … No generator was configured`. On the
  async-aws adapter the message ends `Client needs to be instance of SimpleS3Client` —
  `AsyncAws\S3\S3Client` is enough for `temporaryUrl()` but not for `publicUrl()`.

Prefer configuring `public_url:` per storage: it becomes an environment variable, and
the CDN can then be swapped without touching code.

## Protected files: a controller that authorises, then streams

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
// … Response, Route, AbstractController

#[Route('/exports', name: 'export_')]
final class ReadingExportController extends AbstractController
{
    #[Route('/{id}/download', name: 'download', methods: ['GET'])]
    #[IsGranted('EXPORT_VIEW', subject: 'export')]
    public function download(ReadingExport $export, ReadingExportStorage $storage): Response
    {
        $response = new StreamedResponse(
            static function () use ($storage, $export): void {
                $out = fopen('php://output', 'w');
                stream_copy_to_stream($storage->readStream($export->getStorageKey()), $out);
            },
        );

        $response->headers->set('Content-Type', $export->getMimeType());
        $response->headers->set('Content-Length', (string) $export->getSizeInBytes());
        $response->headers->set('Content-Disposition', $storage->disposition($export));
        $response->setPrivate();

        return $response;
    }
}
```

Why it is shaped this way:

- **`#[IsGranted]` with `subject:`** — the answer depends on *this* export, so it is a
  Voter, not a role. The matching `access_control` rule on `/exports` is the net that
  catches the day someone adds an action and forgets the attribute. Writing and testing
  the Voter is `symfony-security`'s subject.
- **`StreamedResponse` over `BinaryFileResponse`** — `BinaryFileResponse` needs a local
  path, which stops existing the moment storage is S3. Streaming from
  `readStream()` works for both and never loads the file into memory.
- **The headers come from the database**, not from the file. The stored `mimeType` is
  the sniffed type recorded at upload; the original filename is what the user should
  see in their downloads folder.
- **`setPrivate()`** — see the cache section below.

### `Content-Disposition`, and the one call that throws

`HeaderUtils::makeDisposition()` emits both header forms — an ASCII `filename=` for old
clients and an RFC 5987 `filename*=` for the rest. Building the header by hand is how
filenames come out mangled and how a `"` in a filename becomes header injection.

But **it throws on a non-ASCII filename when you do not pass a fallback**:

```php
HeaderUtils::makeDisposition('attachment', 'Facture été 2026.pdf');
// InvalidArgumentException: The filename fallback must only contain ASCII characters.
```

It defaults the fallback to the filename itself and then rejects it. It also throws on
a `%` in the fallback, and on `/` or `\` in either. `BinaryFileResponse::setContentDisposition()`
hides this by computing an ASCII fallback for you; a `StreamedResponse` does not, so the
service builds it:

```php
public function disposition(ReadingExport $export): string
{
    $name = $export->getOriginalName();
    $stem = $this->slugger->slug(pathinfo($name, \PATHINFO_FILENAME))->toString() ?: 'download';
    $extension = pathinfo($name, \PATHINFO_EXTENSION);

    return HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_ATTACHMENT,
        $name,
        '' !== $extension ? $stem.'.'.$extension : $stem,
    );
}
```

Verified for `Facture été 2026 (100%).pdf`:

```
attachment; filename=Facture-ete-2026-100.pdf; filename*=utf-8''Facture%20%C3%A9t%C3%A9%202026%20%28100%25%29.pdf
```

The `?: 'download'` matters: a name that is entirely non-Latin slugs to something, but a
name made only of punctuation slugs to an empty string, and an empty `filename=` is a
malformed header.

`DISPOSITION_ATTACHMENT` forces a download. `DISPOSITION_INLINE` renders in the
browser — acceptable for a PDF or an image the application controls, dangerous for
anything HTML- or SVG-shaped, since it executes in the application's own origin.

### When the file really is local: `BinaryFileResponse`

```php
$response = new BinaryFileResponse($absolutePath, public: false);
$response->headers->set('Content-Type', $export->getMimeType());
$response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $export->getOriginalName());
$response->setPrivate();
```

It handles `Range`, `Content-Length`, `Last-Modified` and conditional responses for
free. Two measured defaults to override:

- **`public` defaults to `true`.** A shared cache is then entitled to store one user's
  invoice and serve it to the next. Measured, after `prepare()`:

  | Constructed as | `Cache-Control` |
  |---|---|
  | `new BinaryFileResponse($path)` | `public` |
  | `new BinaryFileResponse($path, public: false)` | `private, must-revalidate` |
  | `… public: false` then `setPrivate()` | `private` |

  `public: false` is enough to be safe; `setPrivate()` only drops the
  `must-revalidate`. What is never safe is the default.
- **`Content-Type` is sniffed from the bytes**, not from the extension or from what you
  stored. Measured: a text file named `x.pdf` was served as
  `text/plain; charset=UTF-8`. Set the header from the entity.

`deleteFileAfterSend(true)` is right for a file generated for this response alone (a
temporary export in `sys_get_temp_dir()`) and a data-loss bug on a stored file.

## Handing the transfer to the web server

Streaming megabytes through PHP occupies a worker for the whole download. When the file
is local and the web server can read it, `X-Sendfile` moves the transfer out of PHP:

```yaml
# config/packages/framework.yaml
framework:
    trust_x_sendfile_type_header: true
```

Measured with that enabled and a request carrying `X-Sendfile-Type: X-Sendfile`: the
response carried `X-Sendfile: /absolute/path/to/file` and a **zero-byte body**. The
authorisation check still ran — only the bytes were delegated.

Three conditions, all of which have to hold:

- The setting is on. It defaults to the `SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER`
  environment variable, i.e. off.
- **The web server sends `X-Sendfile-Type` on the request.** Symfony does not detect the
  server, it reacts to that header. If the server does not send it, nothing happens and
  nothing warns you.
- For nginx (`X-Sendfile-Type: X-Accel-Redirect`) the request must also carry
  `X-Accel-Mapping`, translating the filesystem prefix into an internal location;
  without it `prepare()` throws a `LogicException`. That location must be `internal;`
  in the nginx configuration, or you have just published the directory.

Only ever trust that header from your own reverse proxy: a client able to set
`X-Sendfile-Type` itself would be reading arbitrary paths. This is a local-disk
optimisation — on object storage the equivalent is a pre-signed URL, below.

## Signed URLs

For a link that has to work outside a session — an emailed download, a webhook target,
a `<img>` in a mail client — Symfony signs the URL itself.

```php
use Symfony\Component\HttpKernel\Attribute\IsSignatureValid;

#[Route('/exports/{id}/download', name: 'export_download', methods: ['GET'])]
#[IsSignatureValid]
public function download(ReadingExport $export): Response
```

```php
// In the service that builds the link:
$url = $this->uriSigner->sign(
    $this->urlGenerator->generate('export_download', ['id' => $export->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
    new \DateInterval('PT10M'),
);
```

Measured behaviour on Symfony 8.1:

| Request | Status |
|---|---|
| Correctly signed, not expired | `200` |
| No `_hash` at all | `404` (`UnsignedUriException`) |
| `_hash` present but wrong, or any parameter tampered with | `404` (`UnverifiedSignedUriException`) |
| Correct signature, `_expiration` in the past | `403` (`ExpiredSignedUriException`) |

The 404/403 come from `#[WithHttpStatus]` on the exception classes themselves, added in
Symfony 7.4 alongside `#[IsSignatureValid]`. **No `framework.exceptions` mapping is
needed** — an older note claiming these surface as a 500 does not hold; the attributes
are in `vendor/symfony/http-foundation/Exception/`. And 404 rather than 403 for a bad
signature is the right default: it does not confirm the resource exists.

**A signature is not authorisation.** It proves the link was minted by this application
and not altered; it says nothing about who holds it. So keep the expiry in minutes, not
days; keep the Voter on the session-authenticated route and sign only links to what the
recipient is already entitled to — a signed link is a permission granted to anyone the
mail is forwarded to. The URL, expiry included, also lands in access logs and `Referer`
headers.

### Version fallbacks

| Feature | Since | Write instead |
|---|---|---|
| `#[IsSignatureValid]`, and `#[WithHttpStatus]` on the exceptions | 7.4 | Inject `UriSigner`, check at the top of the action, throw the 404/403 yourself |
| `UriSigner::verify()` (throws) | 7.3 | `check()` / `checkRequest()`, which return `bool` — throw the 404 yourself |
| `$expiration` on `sign()` | 7.1 | Sign a URL that already carries your own expiry parameter, and check it in the action |
| `Symfony\Component\HttpFoundation\UriSigner` | 6.4 | `Symfony\Component\HttpKernel\UriSigner` — deprecated in 6.4, removed in 8.0 |

The service id is `uri_signer`, autowired by type, and it is seeded from
`kernel.secret`. Rotating `APP_SECRET` invalidates every outstanding signed URL — which
is a feature when a link leaks, and an outage when nobody expected it.

## Pre-signed URLs on object storage

When the file is in a bucket, the cheapest protected download is one the application
never touches: a URL S3 will honour for a few minutes.

```php
// #[Autowire(service: 'reading_exports.storage')] private Filesystem $exports
$url = $this->exports->temporaryUrl($export->getStorageKey(), new \DateTimeImmutable('+10 minutes'));
```

Verified against `league/flysystem-async-aws-s3`: the result is a
`…?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires=600&…&X-Amz-Signature=…` URL, and the
signature is computed locally — no network round-trip, so this is testable offline.

`temporaryUrl()` is on the concrete `Filesystem` and needs an adapter implementing
`TemporaryUrlGenerator`. `AsyncAwsS3Adapter` does; the local adapter does not and throws
`UnableToGenerateTemporaryUrl: … No generator was configured`. That asymmetry is the
argument for keeping the streaming controller as the one code path, and reaching for
pre-signed URLs only where the volume justifies a second one.

**Authorise before minting the URL.** The controller that generates it still runs the
Voter; what the pre-signed URL removes is the bytes flowing through PHP, not the check.

## Cache headers

| Situation | Header | Why |
|---|---|---|
| Public cover behind a CDN | `public, max-age=…, immutable` | The key contains random bytes, so the content at a URL never changes |
| Anything protected | `private` (and `no-store` if it must not touch disk) | A shared cache holding one user's file will serve it to another |

`#[Cache(public: true)]` on a download action is a leak waiting for a proxy. Note also
the interaction measured in `symfony-performance`: once a session has been started,
`SessionListener` rewrites the response as `private` regardless. Convenient, but never
the protection you rely on.

## Symptom table

| Symptom | Cause |
|---|---|
| Download served as `text/plain` or `application/octet-stream` | `Content-Type` not set; `BinaryFileResponse` sniffed the bytes |
| Filename in the browser is mangled or truncated at a space | Hand-built `Content-Disposition`. Use `HeaderUtils::makeDisposition()` |
| `InvalidArgumentException: The filename fallback must only contain ASCII characters` | `makeDisposition()` with a non-ASCII name and no third argument |
| One user receives another's file | A shared cache stored a `public` response — `BinaryFileResponse` defaults to `public: true` |
| PHP workers saturated during downloads | Streaming through PHP. `X-Sendfile` locally, pre-signed URLs on S3 |
| `X-Sendfile` configured but the body is still sent | `trust_x_sendfile_type_header` off, or the web server is not sending `X-Sendfile-Type` |
| `LogicException: The "X-Accel-Mapping" header must be set` | nginx `X-Accel-Redirect` without the mapping header |
| Every signed URL suddenly 404s | `APP_SECRET` changed |
| Signed URL returns 403 | Correct signature, expired — regenerate it, do not lengthen the window by default |
| Signed URL works for the wrong person | Expected. A signature authenticates the link, not the holder |
| `UnableToGeneratePublicUrl … No generator was configured` | No `public_url:` on the storage, and the adapter cannot produce one |
