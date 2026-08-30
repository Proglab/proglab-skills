# Storing a file

Configuring Flysystem, naming what goes on disk, what the entity holds, deleting
without leaving orphans, and testing all of it without touching real storage. Checked
against `league/flysystem` 3.35.3, `league/flysystem-bundle` 3.7.1 and
`league/flysystem-async-aws-s3` 3.31.0 next to Symfony 8.1.5 / PHP 8.4 — Flysystem
follows its own versioning, not Symfony's. **`vendor/` is the source of truth over
this file.**

## Configuration: one storage per purpose

```yaml
# config/packages/flysystem.yaml
flysystem:
    storages:
        book_covers.storage:
            public_url: '%env(COVERS_PUBLIC_URL)%'
            local:
                directory: '%kernel.project_dir%/public/uploads/covers'

        reading_exports.storage:
            visibility: private
            local:
                directory: '%kernel.project_dir%/var/storage/exports'

when@prod:
    flysystem:
        storages:
            book_covers.storage:
                asyncaws: { client: 'app.s3_client', bucket: '%env(S3_COVERS_BUCKET)%' }
            reading_exports.storage:
                visibility: private
                asyncaws: { client: 'app.s3_client', bucket: '%env(S3_EXPORTS_BUCKET)%', prefix: 'exports' }

when@test:
    flysystem:
        storages:
            book_covers.storage: { memory: ~ }
            reading_exports.storage: { memory: ~ }
```

Two storages rather than one with subdirectories: the *public or protected* decision is
per purpose, and this file is where a reviewer should be able to see which bucket is
world-readable.

The S3 client is a service you declare — `league/flysystem-bundle` does not create one:

```yaml
# config/services.yaml
services:
    app.s3_client:
        class: AsyncAws\S3\S3Client
        arguments:
            - region: '%env(S3_REGION)%'
              endpoint: '%env(S3_ENDPOINT)%'
              accessKeyId: '%env(S3_KEY)%'
              accessKeySecret: '%env(S3_SECRET)%'
```

`endpoint` is what makes this work against MinIO, Scaleway, OVH or Cloudflare R2 — same
adapter, different URL. Those five values are environment variables because they vary
per machine; the secrets live in `.env.local` and in the server environment, not in the
vault. The recipe's generated file uses the older `adapter:` / `options:` shape, which
still works but is marked `DEPRECATED` since bundle 3.5.

### Injecting a storage

`registerAliasForArgument()` gives each storage a named autowiring alias, so both of
these resolve, and both pass `lint:container`:

```php
public function __construct(
    private FilesystemOperator $bookCoversStorage,                 // from "book_covers.storage"
    #[Target('reading_exports.storage')] private FilesystemOperator $exports,
) {
}
```

Either form works, and `#[Target]` is the one worth preferring because it is checked: a
`#[Target]` naming a storage that does not exist **fails the build**, with a message
listing the targets that do exist, so `lint:container` catches the typo. (The argument-name
form fails too, but the name is invisible — rename the variable and the storage silently
changes.) The one case where a bad `#[Target]` survives compilation is a consumer reached
only through a lazy service locator, which a storage-using service normally is not; the
mechanism is in `symfony-architecture`, `references/dependency-injection.md`.

**`publicUrl()` and `temporaryUrl()` are not on `FilesystemOperator`** — that interface
is only `FilesystemReader` + `FilesystemWriter`. They are declared on the concrete
`League\Flysystem\Filesystem`, so calling them means
`#[Autowire(service: 'book_covers.storage')] private Filesystem $covers`.

### `visibility` is not decoration

Verified on the local adapter:

| Configuration | What is written |
|---|---|
| no `visibility` | **no `chmod` at all** — the file gets whatever the process umask produces |
| `visibility: private` | the adapter chmods the file to `0600` |

And a trap in the other direction: `PortableVisibilityConverter::inverseForFile()`
returns `Visibility::PUBLIC` for *any* permission mode it does not recognise. So
`$storage->visibility($key) === 'public'` is a default, not a measurement — do not use
it as a security check. The security check is which storage the file is in.

## Naming the stored file

Never write the client-supplied name. Not because of traversal — `UploadedFile::move()`
basenames the argument and Flysystem throws `PathTraversalDetected` — but because the
extension survives, names collide, and a predictable name is a guessable URL.

```php
final readonly class ReadingExportStorage
{
    public function __construct(
        private FilesystemOperator $readingExportsStorage,
        private SluggerInterface $slugger,
    ) {
    }

    public function store(UploadedFile $file): string
    {
        $key = \sprintf(
            '%s/%s-%s.%s',
            (new \DateTimeImmutable())->format('Y/m'),
            $this->slugger->slug(pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME))
                ->lower()
                ->truncate(60),
            bin2hex(random_bytes(8)),
            $file->guessExtension() ?? 'bin',
        );

        $stream = fopen($file->getPathname(), 'r');

        try {
            $this->readingExportsStorage->writeStream($key, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $key;
    }
}
```

What each part is doing:

- **`Y/m` prefix** — keeps a single directory from reaching the tens of thousands of
  entries where a local filesystem, and `listContents()`, get slow.
- **slug of the original stem** — readable in a bucket listing and in logs. A
  convenience; nothing reads it back.
- **`random_bytes(8)`** — the part that matters. It removes collisions *and* makes the
  key unguessable, which is what stops one leaked URL from being a directory of
  everyone else's files. `uniqid()` is time-based, therefore predictable; not a
  substitute.
- **`guessExtension()`, not `getClientOriginalExtension()`** — the first derives the
  extension from the sniffed MIME type, the second echoes the client. It returns `null`
  for a type it does not know (verified: `null` for `text/x-php`), hence `?? 'bin'` —
  without a fallback a hostile upload produces a key ending in a bare dot.
- **`writeStream()`, not `write()`** — `write()` loads the whole file into a PHP string,
  so a 200 MB upload needs 200 MB of `memory_limit`.

`getPathname()` is the temporary upload path, which PHP removes at the end of the
request: this is a copy, and nothing needs cleaning up afterwards.

## What goes in the database

```php
#[ORM\ManyToOne(inversedBy: 'readingExports')]
#[ORM\JoinColumn(nullable: false)]
private ?User $owner = null;

#[ORM\Column(length: 255, unique: true)]
private string $storageKey;

#[ORM\Column(length: 255)]
private string $originalName;

#[ORM\Column(length: 100)]
private string $mimeType;

#[ORM\Column]
private int $sizeInBytes;

#[ORM\Column]
private \DateTimeImmutable $uploadedAt;
```

Notes that are not obvious:

- **`unique: true` on `storageKey`.** The random suffix makes a collision improbable,
  not impossible, and the index turns "two rows silently share a file" into an error.
- **No `nullable` argument on `#[ORM\ManyToOne]`** — it does not exist in ORM 3;
  nullability belongs on `#[ORM\JoinColumn]`. Writing it on the `ManyToOne` is a fatal
  unknown-named-parameter error.
- **Store `mimeType` from `getMimeType()`** (sniffed), not `getClientMimeType()` — it is
  what the download will send, so it must be the type the bytes have.
- **No path, no URL, no bucket name.** The key is relative to a storage whose location
  is configuration. Storing `https://bucket.s3.../x.pdf` bakes today's infrastructure
  into every row.

## Deleting without leaving orphans

```php
public function delete(ReadingExport $export): void
{
    $key = $export->getStorageKey();

    $this->exports->remove($export);
    $this->entityManager->flush();

    try {
        $this->readingExportsStorage->delete($key);
    } catch (FilesystemException $e) {
        $this->logger->error('Orphan file left behind', ['key' => $key, 'exception' => $e]);
    }
}
```

The order is the whole point. **Row first, file second:** a failing `flush()` deletes
nothing, and a failing file deletion leaves an orphan — invisible, costing storage,
harmless. The reverse order produces a row pointing at a file that no longer exists,
which breaks every page rendering it. **The failure is logged, not rethrown:** the use
case succeeded, and failing the request because a bucket blipped would leave the user
unable to delete anything at all.

A per-request retry is not the answer; a sweep is. A command lists what the storage
holds, subtracts the keys the database knows, and deletes what is left — reporting by
default and acting only with `--force`:

```php
// Repository: SELECT e.storageKey FROM … — one column, no hydration.
$known = array_flip($this->exports->findAllStorageKeys());
$cutoff = $this->clock->now()->modify('-1 day')->getTimestamp();

foreach ($this->readingExportsStorage->listContents('', FilesystemReader::LIST_DEEP) as $item) {
    if (!$item->isFile() || isset($known[$item->path()])) {
        continue;
    }

    if (null === $item->lastModified() || $item->lastModified() >= $cutoff) {
        continue;   // too recent, or unknown age — leave it alone
    }

    // report, and delete only when --force was given
}
```

Three things that make it safe rather than dangerous:

- **The `lastModified()` cutoff** excludes files written between the moment the key
  list was read and now. Without it the sweep deletes uploads that are mid-flight.
- **`lastModified()` is `?int`.** An adapter that cannot report an age returns `null`,
  and `null < $cutoff` is `true` in PHP — treating unknown as old is how a sweep
  deletes a whole bucket. Skip it explicitly.
- **The key list is a `SELECT` of one column** flipped into a lookup table, not
  `findAll()` hydrating every entity, and — per the charter — written as a repository
  method named after the intent.

Command mechanics (dry run by default, `LockableTrait` and its multi-host caveat, the
smoke test) are `symfony-console`'s; making it periodic is `symfony-async`'s.

## Testing without touching real storage

**Functional and integration tests: swap the adapter, not the service.** The `when@test`
block above points both storages at `league/flysystem-memory`. The service under test is
unchanged and nothing reaches the disk:

```php
$file = new UploadedFile(__DIR__.'/../Fixtures/export.pdf', 'Rapport (final).pdf', test: true);
$key = $storage->store($file);

self::assertSame(file_get_contents($file->getPathname()), stream_get_contents($storage->readStream($key)));
```

The fifth `UploadedFile` argument, `$test`, is what makes this possible: it turns off
the `is_uploaded_file()` check, so a fixture file behaves like a real upload. Without it
every upload test fails with "The file … was not uploaded due to an unknown error."

Because object storage is a genuine external boundary, `$container->set()` would also be
legitimate here — but there is no reason to: the configuration override needs no code
and survives the container reboot `KernelBrowser` performs between requests.

**Unit tests: double the interface.** `FilesystemOperator` is an interface, so the
service is testable with no container at all:

```php
$fs = $this->createMock(FilesystemOperator::class);
$fs->expects(self::once())->method('writeStream');

$key = (new ReadingExportStorage($fs, new AsciiSlugger()))->store($file);

self::assertMatchesRegularExpression('#^\d{4}/\d{2}/rapport-final-[0-9a-f]{16}\.pdf$#', $key);
```

`createMock()` because there is an `expects()`; `createStub()` where there is not — a
mock with no expectation emits a PHPUnit notice, and `phpunit.dist.xml` needs
`failOnPhpunitNotice="true"` to make that red (`symfony-quality`).

Assert the key *format*, not just that a key came back. That regex is the rule keeping
the client's filename off the disk, and it is exactly what a refactor loosens quietly.
