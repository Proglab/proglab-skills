# Cache : HTTP, applicatif, Doctrine

Vérifié avec **Symfony 8.1.5 / doctrine-bundle 3.3.1 / doctrine/orm 3.6.8**.
L'ordre de ce fichier est l'ordre de la décision : ne l'ouvre pas tant qu'une mesure n'a
pas désigné une page précise ou un appel précis comme lent.

## La règle qui vient en premier

**Pas de cache sans un chiffre.** Pas une impression que la page est lente — une mesure
du profiler nommant la requête ou l'appel qui coûte le temps.

La raison n'est pas la pureté. Un cache ajouté avant la mesure fait deux choses à la
fois : il masque le vrai problème, donc plus personne ne le regarde à nouveau, et il
introduit toute une classe de bugs dont la reproduction dépend d'une minuterie. « Ça
marchait quand j'ai testé » est le rapport habituel, et l'investigation part de zéro parce
que le chemin rapide et le chemin lent produisent des chemins de code identiques. Corrige
d'abord la requête ; mets en cache ce qui reste.

---

# Cache HTTP

## L'attribut

`Symfony\Component\HttpKernel\Attribute\Cache` — répétable, et utilisable sur la classe ou
la méthode. Surface du constructeur vérifiée en 8.1 :

```php
#[Cache(
    expires: null,               // chaîne compatible strtotime()
    maxage: null,                // navigateur / cache privé, secondes ou "1 hour"
    smaxage: null,                // cache partagé (reverse proxy)
    public: null,                // true → public, false → private, null → inchangé
    mustRevalidate: false,
    vary: [],                    // string[]
    lastModified: null,          // \DateTimeInterface|string|Expression|\Closure
    etag: null,                  // string|Expression|\Closure
    maxStale: null,
    staleWhileRevalidate: null,
    staleIfError: null,
    noStore: null,
    if: true,                    // bool|string|Expression|\Closure — application conditionnelle
)]
```

Les branches plus anciennes ont moins d'arguments, et trois des éléments ci-dessus sont
propres à **Symfony 8.1** : la condition `if:`, les formes `\Closure` de `etag` /
`lastModified` / `if`, et les variables `request`, `args` et `this` à l'intérieur d'une
expression (CHANGELOG http-kernel 8.1). Ce qu'il faut écrire à la place sur 6.4–8.0 : mets
la condition dans le contrôleur sous forme de retour anticipé, et limite les expressions
aux attributs de la requête et aux arguments résolus du contrôleur, que
`CacheAttributeListener` fusionne dans le scope de premier niveau à chaque version — c'est
pourquoi le simple `book` ci-dessous fonctionne. Les formes `\Closure` sont inutilisables
en dessous de **PHP 8.5** dans tous les cas, puisqu'une closure dans un attribut est une
erreur fatale à la compilation, donc la chaîne ExpressionLanguage est la forme portable
partout. Ouvre `vendor/symfony/http-kernel/Attribute/Cache.php` plutôt que de supposer.

## Expiration vs validation

Elles répondent à des questions différentes, et une seule aide une page lente à rendre.

**Expiration** — « ne me redemande pas avant une heure ».

```php
#[Route('/catalogue', name: 'catalog_index', methods: ['GET'])]
#[Cache(smaxage: 3600, public: true)]
```

Aucune requête n'atteint PHP tant que l'entrée est fraîche. Résultat le plus rapide
possible, et le contenu est faux pendant jusqu'à une heure, par conception. Uniquement
pour des pages où c'est acceptable et assumé.

**Validation** — « demande-moi, je répondrai 304 si rien n'a changé ».

```php
#[Route('/livres/{id}', name: 'book_show', methods: ['GET'], requirements: ['id' => '\d+'])]
#[Cache(etag: 'book.getUpdatedAt().getTimestamp()', public: false)]
public function show(Book $book): Response
```

Vérifié dans `CacheAttributeListener` : `etag` et `lastModified` sont évalués sur
`kernel.controller_arguments` et, quand le `If-None-Match` / `If-Modified-Since` de la
requête correspond, le listener **remplace le contrôleur par un renvoyant le 304** et
arrête la propagation. Observé : l'action ne s'exécute jamais et le corps de la réponse
fait zéro octet. C'est ce qui fait de la validation le bon outil pour une page coûteuse —
l'économie porte sur le rendu, pas sur les octets.

La chaîne est une **expression ExpressionLanguage**, pas un littéral : elle est évaluée
contre les attributs de la requête et les arguments résolus du contrôleur (plus
`request`, `args` et `this`), puis hashée avec `sha256`. Donc `etag: 'ref'` sur une action
prenant `string $ref` produit un ETag dérivé de la valeur de cet argument. Ça nécessite
`symfony/expression-language`, et une coquille est une erreur d'exécution sur la page même
que tu essayais d'accélérer — teste le chemin 304, ne le vérifie pas à l'œil.

La signature accepte aussi une `\Closure` recevant
`(array $args, Request $request, ?object $controller)`, ce qui serait plus agréable.
Vérifié : **on ne peut pas l'utiliser depuis un attribut avant PHP 8.5** — les closures ne
sont pas des expressions constantes, et PHP 8.4 échoue avec *Constant expression contains
invalid operations* au moment du parsing. Sur PHP 8.2–8.4, la chaîne d'expression est la
seule forme disponible.

## Deux façons de fuiter ou de gaspiller

### `public: true` sur des données personnelles est une fuite

Un cache partagé stocke une réponse et la sert à tout le monde. Si la page contient un
nom, un e-mail, une étagère, une commande — tout ce qui varie selon l'utilisateur —
`public: true` le donne au visiteur suivant. Aucune configuration ne rend cela sûr ;
`Vary` sur un cookie n'est pas une défense, parce que le cookie n'est pas ce qui identifie
la sensibilité.

La règle : une réponse qui varie selon l'utilisateur est `private`, point final. `public`
est réservé aux pages qui seraient identiques rendues pour un inconnu.

### Toucher la session tue silencieusement `public`

Vérifié : `AbstractSessionListener::onKernelResponse()` s'exécute à la priorité **-1000**
— après l'application de l'attribut `#[Cache]` — et quand la session a été utilisée d'une
quelconque façon, elle force

```
Cache-Control: max-age=0, must-revalidate, private
```

« Utilisée d'une quelconque façon » signifie `Session::getUsageIndex() > 0` : un flash
message, une lecture du token de sécurité, un token CSRF, un firewall authentifié. Sortie
observée pour un contrôleur portant `#[Cache(smaxage: 3600, public: true)]` qui a lu une
seule clé de session :

```
Cache-Control: max-age=0, must-revalidate, private, s-maxage=3600
```

Contradictoire, et rien n'est mis en cache. C'est une bonne chose — c'est le framework qui
empêche la fuite ci-dessus — mais c'est silencieux, donc ça coûte un après-midi la
première fois.

Si une page doit vraiment être cacheable publiquement, elle ne doit toucher la session
d'aucune manière : pas de `addFlash`, pas de `#[IsGranted]`, pas de formulaire protégé par
CSRF dessus. Symfony offre une échappatoire,
`AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER` sur la réponse, qui supprime la
correction automatique. N'y recours que quand tu as personnellement vérifié qu'aucune
donnée spécifique à l'utilisateur n'est dans le corps.

## `Vary`

`#[Cache(vary: ['Accept-Language'])]` dit au cache partagé de stocker une entrée par
valeur de cet en-tête. Chaque entrée ajoutée à `Vary` multiplie les entrées du cache, donc
un `Vary` sur `User-Agent` désactive en pratique le cache. Limite-le aux en-têtes qui
changent réellement le corps.

## Où vit le cache partagé

`smaxage` ne signifie quelque chose que si quelque chose met effectivement en cache : un
véritable reverse proxy (Varnish, ou le CDN devant l'application — la réponse en
production), ou le propre `framework.http_cache.enabled: true` de Symfony, dont le
`trace_level: short` ajoute un en-tête `X-Symfony-Cache` montrant hit/miss et permet de
vérifier que les en-têtes font ce que tu penses. Sans l'un ou l'autre,
`#[Cache(smaxage: …)]` est un en-tête que personne ne lit.

---

# Cache applicatif

## Il va dans un décorateur, pas dans le service

```php
#[AsDecorator(ShelfReading::class)]
final readonly class CachedShelfReader implements ShelfReading
{
    public function __construct(
        #[AutowireDecorated] private ShelfReading $inner,
        private TagAwareCacheInterface $cache,
    ) {
    }

    /** @return list<ShelfRow> */
    public function shelf(int $userId): array
    {
        return $this->cache->get(
            'shelf.'.$userId,
            function (ItemInterface $item) use ($userId): array {
                $item->tag(['shelf', 'shelf.'.$userId]);
                $item->expiresAfter(3600);

                return $this->inner->shelf($userId);
            },
        );
    }
}
```

Pourquoi un décorateur plutôt que trois lignes dans `ShelfReader` :

- Le service métier reste testable unitairement sans aucun cache à proximité, ce qui est
  ce qui garde ses tests rapides et ses échecs lisibles.
- Le cache est retirable en supprimant une seule classe. Du code de cache tissé dans une
  méthode n'est jamais retiré, parce que personne ne peut dire ce qui en dépend encore.
- Le décorateur est lui-même testable : donne-lui un stub `ShelfReading` et un
  `ArrayAdapter`, vérifie que le service interne a été appelé une fois pour deux lectures.

**Le coût, et il est réel.** Cette suite rend les services `final`, et on ne peut pas
décorer une classe concrète final. Vérifié — `lint:container` le refuse purement et
simplement :

```
[ERROR] Invalid definition for service "App\Controller\BookController":
        argument 1 of "App\Controller\BookController::__construct()" accepts
        "App\Service\ShelfReader", "App\Service\CachedShelfReader" passed.
```

Donc introduire un cache est aussi le moment où l'on extrait une interface
(`ShelfReading`), fait implémenter `ShelfReader` à cette interface, aliase l'interface, et
repointe les consommateurs. Fais-le délibérément ; ne « résous » pas le problème en
retirant `final`.

```yaml
# config/services.yaml — nécessaire dès qu'il y a deux implémentations
services:
    App\Service\ShelfReading: '@App\Service\ShelfReader'
```

`#[AutowireDecorated]` nécessite Symfony **6.3+** (`#[AsDecorator]` lui-même date de 6.1).
Sur 6.1–6.2, c'était `#[MapDecorated]`, retiré en 7.0 ; sur tout ce qui est plus ancien,
déclare la décoration en YAML avec `decorates:` et injecte `'@.inner'`.

## Tags, pas seulement le TTL

`TagAwareCacheInterface` s'autowire vers `cache.app.taggable` sans aucune configuration —
vérifié à la fois dans les conteneurs `test` et `prod`.

Un TTL dit « ceci peut être faux pendant jusqu'à une heure ». Un tag dit « c'est faux
maintenant » :

```php
// dans le service qui écrit
$this->books->add($book);
$this->em->flush();
$this->cache->invalidateTags(['book.'.$book->getId(), 'shelf']);
```

Garde quand même un TTL. C'est le filet de sécurité pour l'appel d'invalidation que tu
oublieras d'ajouter au troisième chemin d'écriture.

Notes pratiques :

- **Caractères des clés.** `ItemInterface::RESERVED_CHARACTERS` vaut `` {}()/\@: `` — une
  clé contenant l'un d'eux lève une exception. Construis les clés à partir d'identifiants
  et de slugs, jamais à partir d'une entrée utilisateur brute ou d'une URL.
- **Mets en cache des DTO, pas des entités.** Une entité sérialisée est un instantané
  détaché ; la remettre devant Doctrine provoque des erreurs de lazy loading et des
  relations périmées.
- **La protection contre le stampede est activée par défaut** via `$beta` dans `get()` :
  les entrées sont recalculées légèrement avant expiration par une seule requête plutôt
  que par toutes en même temps. Laisse-la telle quelle sauf si le callback est assez
  coûteux pour justifier un réglage.
- **`cache.app` contient des données** ; **`cache.system` est dérivé du code** (métadonnées,
  parsing DQL) et est vidé par un déploiement. Ne mets jamais de données dans
  `cache.system`.

---

# Les caches propres à Doctrine

## Cache de requête — oui, en prod

Met en cache le parsing DQL → SQL. Ça ne coûte rien et c'est invalidé par un déploiement,
donc son pool est `cache.system`.

```yaml
when@prod:
    doctrine:
        orm:
            query_cache_driver:
                type: pool
                pool: doctrine.system_cache_pool

    framework:
        cache:
            pools:
                doctrine.system_cache_pool:
                    adapter: cache.system
```

## Cache de résultat — par requête, opt-in

```php
return $this->createQueryBuilder('b')
    ->select(\sprintf('NEW %s(b.id, b.title, b.status, b.lastReadAt)', ShelfRow::class))
    ->getQuery()
    ->enableResultCache(3600, 'shelf_rows')      // durée de vie, id de cache explicite
    ->getResult();
```

Le pool est `cache.app`, car ceci contient des données :

```yaml
when@prod:
    doctrine:
        orm:
            result_cache_driver: { type: pool, pool: doctrine.result_cache_pool }
    framework:
        cache:
            pools:
                doctrine.result_cache_pool: { adapter: cache.app }
```

Deux avertissements. Passer un id explicite est ce qui permet de supprimer l'entrée plus
tard — avec le hash généré automatiquement, rien n'est invalidable. Et un cache de
résultat retournant des **entités** donne des objets managés reconstruits à partir de
lignes mises en cache ; si un service les modifie ensuite et flush, le comportement
devient subtil. Des projections en cache de résultat, pas des entités.

## Cache de second niveau — non utilisé

Le cache de second niveau de Doctrine met en cache les entités elles-mêmes, par entité,
avec sa propre configuration de région et sa propre sémantique d'invalidation. Il est
rejeté ici : chaque problème qu'il résout est mieux résolu en corrigeant la requête ou par
un cache applicatif explicite dans un décorateur, et son mode d'échec — une entité
silencieusement périmée à l'intérieur de l'ORM — est bien plus difficile à diagnostiquer
qu'un DTO périmé dans `cache.app`.

## Cache de métadonnées

Déjà actif sur `cache.system` depuis la recette. Rien à faire.
