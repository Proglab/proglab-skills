# deptrac: enforcing the layer contract

`deptrac.yaml` in `assets/` is written for a project laid out by technical role
(`src/Controller`, `src/Service`, `src/Repository`). Most projects are not exactly
that. This file is about adapting it without weakening it.

## Contents

- [What it is really checking](#what-it-is-really-checking)
- [Projects grouped by domain](#projects-grouped-by-domain)
- [What "uncovered" means, and why it does not fail the build](#what-uncovered-means-and-why-it-does-not-fail-the-build)
- [Reading a violation](#reading-a-violation)
- [Introducing it on an existing codebase](#introducing-it-on-an-existing-codebase)
- [The rules you will be tempted to relax](#the-rules-you-will-be-tempted-to-relax)

## What it is really checking

Three statements, and nothing else:

- a controller translates HTTP into a service call, so it has no business touching
  Doctrine;
- a service holds the rule, so it must not build queries and must not know that HTTP
  exists;
- a repository owns the queries, so it is the only layer allowed to see Doctrine.

Every other line in the configuration exists to express those three. When you adapt
the file, adapt the collectors — not the ruleset.

Two openings follow directly from the first statement and are easy to lose when
editing: a controller **may** see `HttpFoundation` (it returns a `Response`), and it may
see entities and voter constants, because the EntityValueResolver and
`#[IsGranted(subject:)]` hand it both. The `Http` layer exists so that a *service* may
not — remove it from the `Controller` ruleset and every action returning a `Response`
becomes a violation.

**Two deliberate carve-outs**, because the standard would otherwise contradict itself.
The service owns the transaction boundary and calls `flush()` once per use case
(`symfony-architecture`), so it must be able to type-hint `EntityManagerInterface`: the
shipped `deptrac.yaml` lifts those two interfaces (`Doctrine\ORM\EntityManagerInterface`,
`Doctrine\Persistence\ObjectManager`) out of the `Doctrine` layer with a negative
lookahead and gives them a layer of their own, `EntityManager`. And an entity is made of
`#[ORM\…]` attributes, `Collection` classes and `Types` constants, so those get a
`DoctrineMapping` layer that `Entity` may see — while the rest of Doctrine stays closed
to it. Keep both that narrow: adding `Doctrine` to the `Service` or `Entity` ruleset
instead would let `QueryBuilder` back in, which is the single thing this file exists to
prevent.

The collectors are `classNameRegex`, and the value is a full regex **with delimiters**
(`'/^Doctrine\\…/'`). The older `className` type no longer exists in the deptrac the
`jakzal/phpqa` image ships, and a pattern without delimiters is rejected as invalid.
Verified on deptrac 4.7.1 (`jakzal/phpqa:1.125.1-php8.4-alpine`) against a sample
project: the shipped file passes clean on a controller, a service, a repository, a
mapped entity with a collection, a form, a handler, a voter and an enum, and reports
`must not depend on Doctrine\ORM\QueryBuilder (Service on Doctrine)` with exit code 1
the moment a service gains one.

## Projects grouped by domain

A project laid out as `src/Billing/…`, `src/Catalog/…` needs collectors that match
the *role* inside each domain rather than a single top-level directory:

```yaml
deptrac:
    paths:
        - ./src

    layers:
        - name: Controller
          collectors:
              - type: directory
                value: src/.*/Controller/.*

        - name: Service
          collectors:
              - type: directory
                value: src/.*/Service/.*

        - name: Repository
          collectors:
              - type: directory
                value: src/.*/Repository/.*
```

If the project names things differently — `UseCase` instead of `Service`, `Query`
instead of `Repository` — rename the layers to match the project. The skill's
vocabulary is not the point; the boundaries are.

### Also enforcing boundaries between domains

Once the technical layers hold, the same tool can stop domains reaching into each
other's internals, which is usually the more valuable check on a large codebase:

```yaml
        - name: Billing
          collectors:
              - type: directory
                value: src/Billing/.*

        - name: Catalog
          collectors:
              - type: directory
                value: src/Catalog/.*

    ruleset:
        Billing: []      # Billing may not reach into Catalog at all
        Catalog: []
```

Then open exactly the doors you mean, usually a public interface or a message:

```yaml
        - name: CatalogApi
          collectors:
              - type: directory
                value: src/Catalog/Api/.*

    ruleset:
        Billing:
            - CatalogApi   # and nothing else of Catalog
```

## What "uncovered" means, and why it does not fail the build

```bash
deptrac analyse --config-file=deptrac.yaml --report-uncovered
```

deptrac only judges dependencies between two layers. A dependency from a layer onto a
class that belongs to **no** layer is *uncovered*: it is neither allowed nor forbidden,
it is simply not a question the ruleset answers. `AbstractController`,
`LoggerInterface`, `ValidatorInterface`, every PHPUnit attribute — most of `vendor/` is
uncovered, by design.

That is why the shipped tasks do **not** pass `--fail-on-uncovered`. The flag fails on
*any* uncovered dependency, vendor included, so the first controller extending
`AbstractController` would turn the gate red. A tool that fails on an empty project is
a tool that gets deleted.

`--report-uncovered` is the useful half. It prints the list, and the thing to look for
in it is a class under `src/`: that is a directory nobody collected, so nothing in it
is checked. Treat it as a question — which layer is this, actually? — and either add
it to an existing collector or name the layer it is. The shipped file leaves
`src/Command`, `src/Doctrine`, `src/EventListener`, `src/Serializer`, `src/Health` and
`src/Scheduler` uncollected on purpose; they are listed in its header comment so the
report's `src/` entries are recognisable.

## Reading a violation

```
App\Service\ReviewPublisher must not depend on Doctrine\ORM\QueryBuilder (Service on Doctrine)
```

That is the standard's central rule catching exactly what it was written for. The fix
is never to add `Doctrine` to the `Service` ruleset. It is to move the query into a
repository method named after the intent:

```php
// before, in the service
$reviews = $this->em->createQueryBuilder()->select('r')/* … */->getResult();

// after
$reviews = $this->reviews->findLatestPublishedForBook($bookId);
```

The service gets shorter, the query becomes reusable, and the service becomes
testable without a database. The tool did not create work — it found work that was
already owed.

## Introducing it on an existing codebase

A codebase written without these boundaries will report a lot. Do not make it
blocking on day one; that is how tools get deleted.

1. Run it. Read the violations as a map of the actual architecture, which is often
   not the one anyone describes, and the uncovered `src/` entries as the directories
   the map forgot.
2. Fix one layer at a time, starting with controllers — usually the fastest wins and
   the most valuable, since a controller touching Doctrine is also the code that is
   hardest to test.
3. Add the task to CI once the violations are gone, in the same commit as the last
   fix. A check that is not in CI is a check that stops running.

deptrac has a baseline too, but prefer fixing over freezing here. Unlike a PHPStan
error, a layer violation is rarely a one-line fix — it is a class in the wrong place,
and leaving it there means the next class copies it.

## The rules you will be tempted to relax

| Temptation | What it actually means |
|---|---|
| Letting `Service` depend on `Doctrine` "just for this one repository call" | The query belongs in a repository method. The `EntityManager` layer already covers the only legitimate case, `flush()` |
| Letting `Controller` depend on `Repository` "it is only a find()" | Go through the service, which takes the id and owns the 404. The EntityValueResolver is for the one case where a voter needs the subject first (`symfony-architecture`) |
| Letting `Entity` depend on `Service` | An entity that calls a service is no longer a model, it is an orchestrator |
| Adding a `Shared` layer that everything may depend on | Legitimate for genuinely shared value objects; a dumping ground the moment it holds anything with behaviour |

The last one is the dangerous one, because it looks reasonable. Keep `Shared` for
things with no dependencies of their own — and check that claim with deptrac itself
by giving it an empty ruleset.
