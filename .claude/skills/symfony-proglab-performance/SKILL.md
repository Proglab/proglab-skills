---
name: symfony-proglab-performance
description: >-
  Rendre une page Symfony lente rapide et prouver qu'elle le reste : mesurer avec le
  profiler et Stopwatch avant de toucher à quoi que ce soit, éliminer les requêtes N+1
  avec des jointures explicites et des projections SELECT NEW dans des DTO de lecture, et
  verrouiller le résultat derrière un test d'intégration qui compte les requêtes SQL
  exécutées par un endpoint. Couvre aussi le cache HTTP avec #[Cache] (expiration vs
  validation, et pourquoi public:true sur une page contenant des données personnelles les
  fait fuiter via le cache partagé), le cache applicatif avec invalidation par tag placée
  dans un décorateur #[AsDecorator] plutôt que dans le service qui porte la règle, et les
  caches de requête et de résultat de Doctrine. Utilise ce skill dès qu'on dit qu'une page
  est lente, « ça met une éternité », « ça time out », « pourquoi ça met autant de temps »,
  « trop de requêtes », « l'étagère met quatre secondes à charger », demande d'ajouter du
  cache ou une couche de cache, pose une question sur le N+1, le lazy loading ou l'eager
  loading, veut profiler ou benchmarker un endpoint, veut vérifier un nombre de requêtes
  dans un test, doit itérer sur des dizaines de milliers de lignes sans épuiser la mémoire,
  ou demande pourquoi une page mise en cache continue d'afficher des données périmées. Pas
  pour le réglage serveur — OPcache, preload, autoloader et paramètres du conteneur
  relèvent de symfony-proglab-deployment.
---

# Performance

> **Niveau : à la demande** — chaque règle ici découle d'une mesure. Rien dans ce skill
> n'est fait de manière préventive, y compris le test de comptage de requêtes, qui est
> écrit pour une liste dont on a constaté la croissance.

Mesurer, corriger la requête, puis écrire le test qui empêche le problème de revenir.

Tout dans ce skill présuppose cet ordre. Un cache ajouté avant une mesure ne rend pas une
application rapide ; il la rend *rapide et fausse*, parce que la requête lente est
toujours là et qu'elle sert désormais aussi des données périmées. Le cache est le dernier
recours, pas le premier.

## Mesurer d'abord

Le profiler est toute la boîte à outils. Charge la page, ouvre la barre d'outils, clique
sur le panneau **Doctrine** : nombre de requêtes, temps par requête, le SQL lui-même, et —
parce que `profiling_collect_backtrace` est activé en mode debug — la ligne de code qui a
déclenché chacune.

Lis-le dans cet ordre, parce que la réponse est presque toujours sur la première ligne :

1. **Combien de requêtes ?** 3, c'est une page. 60, c'est un N+1.
2. **Sont-ce les mêmes requêtes avec des paramètres différents ?** C'est la signature.
3. **Seulement ensuite, combien de temps prend une requête ?** Un index manquant est un
   vrai problème, mais il est plus rare que la boucle au-dessus.

Pour du code lent sans être lié au SQL, `symfony/stopwatch` permet d'isoler le coupable :

```php
$this->stopwatch->start('isbn_lookup', 'shelf');
$data = $this->isbnClient->fetch($isbn);
$this->stopwatch->stop('isbn_lookup');
```

Les événements nommés apparaissent dans la timeline du profiler. Un point à connaître
avant de l'injecter : **`symfony/stopwatch` est un paquet `require-dev` dans le squelette
Symfony**, et FrameworkBundle n'enregistre `debug.stopwatch` que quand la classe existe.
Un service qui type-hinte `Stopwatch` échoue donc à compiler le conteneur avec
`composer install --no-dev`. Instrumente, mesure, retire — ou déplace le paquet vers
`require` si l'instrumentation est censée rester.

## Le test de comptage de requêtes

Chaque endpoint de liste reçoit un test d'intégration qui compte les requêtes SQL qu'il
exécute. `symfony-proglab-doctrine` ne rend une relation bidirectionnelle que là où le côté
inverse est réellement lu — et là où elle existe, le N+1 n'est qu'à un point de distance :
un template qui touche `book.reviews` suffit à le réintroduire par accident. Une règle non
testée n'est qu'un vœu pieux, donc la règle reçoit un test.

```php
$this->seed(1);
$small = $this->countQueriesFor($this->client, 'GET', '/livres');

$this->seed(9);
$large = $this->countQueriesFor($this->client, 'GET', '/livres');

self::assertSame(2, $small, 'shelf with one book:'.$this->queryLog());
self::assertSame($small, $large, 'the query count grew with the row count:'.$this->queryLog());
```

**Vérifie à la fois un nombre exact et l'absence de croissance.** Le nombre exact fixe le
coût d'aujourd'hui ; la seconde assertion est la définition réelle du N+1 et elle survit à
quelqu'un ajoutant légitimement une troisième requête plus tard. Une simple borne
supérieure (`assertLessThan(10, …)`) est la forme la plus faible et a un mode d'échec
vicieux décrit plus bas.

Le comptage lui-même fait l'objet de `references/query-counting.md` — à lire avant
d'écrire le test, parce que deux des quatre rouages ne sont pas évidents et que l'un
d'eux fait passer un test cassé silencieusement.

Deux faits à retenir, tous deux vérifiés avec Symfony 8.1.5 / doctrine-bundle 3.3.1 /
DBAL 4.4.4 :

- `DebugStack` a disparu de DBAL 4. La collecte des requêtes passe désormais par un
  `Driver\Middleware` DBAL qui alimente `doctrine.debug_data_holder`.
- `$client->getProfile()->getCollector('db')->getQueryCount()` **existe** bel et bien et
  fonctionne. Elle réside sur
  `Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector`, que le collector de
  doctrine-bundle étend.

## N+1 : trois règles

| Règle | Pourquoi |
|---|---|
| **Ne jamais itérer une collection d'entités en dehors d'un repository.** `foreach ($book->getReviews() as …)` dans un service ou un template déclenche un lazy load par livre. | La collection est une `PersistentCollection` ; la toucher émet un `SELECT` que tu n'as jamais écrit et que tu ne peux pas voir depuis le point d'appel. |
| **Les requêtes de liste joignent explicitement.** `->leftJoin('b.reviews', 'r')->addSelect('r')` dans la méthode du repository, pas un lazy loading au moment du rendu. | Une requête avec une jointure coûte un aller-retour. Les mêmes données chargées paresseusement coûtent un aller-retour par ligne, et le coût est invisible tant que la production n'a pas de lignes. |
| **Ne jamais faire `count($book->getRatings())`.** Ajoute `countForBook(Book $book): int` au repository. | `PersistentCollection::count()` n'émet une requête `COUNT` que quand l'association est mappée `fetch: 'EXTRA_LAZY'`. Sinon, elle retombe sur `Collection::count()`, qui **initialise toute la collection** — tu hydrates 4 000 entités pour afficher le nombre 4 000. |

`EXTRA_LAZY` est l'échappatoire que Doctrine propose pour la troisième règle, et elle
fonctionne. Elle est rejetée ici pour la même raison que la charte place tout le DQL dans
les repositories : elle cache une requête SQL derrière un appel `count()` dans un
template Twig, là où ni un lecteur ni un analyseur statique ne la trouveront. La méthode
de repository est une ligne plus longue et dit ce qu'elle fait.

Diagnostiquer un N+1 précis, choisir entre `leftJoin` et `WITH`, et l'astuce
pagination-puis-jointure qui évite le problème des lignes dupliquées :
`references/queries.md`.

## Hydrater moins : SELECT NEW dans un DTO de lecture

Une liste qui affiche trois colonnes n'a aucune raison d'hydrater des entités complètes,
chacune suivie par l'UnitOfWork pour le reste de la requête.

```php
public function findShelfRows(): array
{
    return $this->createQueryBuilder('b')
        ->select(\sprintf('NEW %s(b.id, b.title, b.status, b.lastReadAt)', ShelfRow::class))
        ->orderBy('b.title', 'ASC')
        ->getQuery()
        ->getResult();
}
```

`ShelfRow` est une classe `final readonly` dans `src/Dto/Read/` avec des propriétés de
constructeur promues. Vérifié : `SELECT NEW` applique la conversion de type DBAL, donc
une colonne mappée avec `enumType:` arrive sous forme d'enum et une colonne
`date_immutable` sous forme de `\DateTimeImmutable` — et l'identity map reste vide. Le
service normalise ensuite Read → Output ; si la projection correspond déjà au contrat de
l'API, projette directement dans le DTO Output et saute cette couche.

Pour des jeux de résultats trop volumineux pour tenir en mémoire, `toIterable()` plus un
`$em->clear()` périodique : `references/queries.md`.

## Cache HTTP : rien par défaut

`#[Cache]` se pose sur une page dont la lenteur a été *mesurée*, et seulement alors.

Un cache HTTP prématuré est pire que pas de cache du tout, pour deux raisons qui se
combinent. Il masque le vrai problème — la page est toujours lente, tu as juste arrêté de
la regarder — et il introduit des bugs de contenu périmé, la classe de bug la plus dure à
reproduire parce que la reproduction dépend d'une minuterie invisible.

Quand il est justifié, les deux mécanismes répondent à des questions différentes :

- **Expiration** (`smaxage`, `maxage`) — « ne me redemande pas avant une heure ». Aucune
  requête n'atteint PHP. Le plus rapide, et faux jusqu'à l'expiration du TTL.
- **Validation** (`etag`, `lastModified`) — « demande-moi, je répondrai 304 si rien n'a
  changé ». Vérifié : l'attribut évalue l'ETag sur `kernel.controller_arguments` et
  court-circuite **avant l'exécution du contrôleur**, donc un 304 ne coûte qu'un passage
  de routage et rien d'autre. C'est ce qui la rend utile sur une page lente à *rendre*.

Deux points qui mordent :

- **`public: true` sur une réponse contenant des données personnelles est une fuite.** Un
  cache partagé servira la page d'un utilisateur au visiteur suivant. La règle n'a pas
  d'exception : si la réponse varie selon l'utilisateur, elle est `private`.
- **Toucher la session rend la réponse privée de toute façon.** Vérifié : tout usage de la
  session — un flash message, le token de sécurité, `_csrf` — fait forcer par
  `SessionListener` (qui s'exécute à la priorité `-1000`, après l'attribut `#[Cache]`)
  `private, max-age=0, must-revalidate`. `#[Cache(public: true, smaxage: 3600)]` sur une
  telle page émet des en-têtes contradictoires et ne met rien en cache. Silencieux, et
  une perte d'après-midi classique.

Traitement complet, incluant `Vary`, `stale-while-revalidate` et la question du reverse
proxy : `references/caching.md`.

## Cache applicatif : dans un décorateur, invalidé par tags

Deux contraintes, de nature architecturale plutôt que technique.

**Le cache ne va pas dans le service qui porte la règle.** Il va dans un décorateur. Le
service métier reste testable unitairement sans aucun cache en vue, et le cache peut être
supprimé en retirant une seule classe — au lieu d'être décousu du milieu d'une méthode que
personne ne veut toucher.

```php
#[AsDecorator(ShelfReading::class)]
final readonly class CachedShelfReader implements ShelfReading
{
    public function __construct(
        #[AutowireDecorated] private ShelfReading $inner,
        private TagAwareCacheInterface $cache,
    ) {
    }
}
```

`TagAwareCacheInterface` s'autowire vers `cache.app.taggable` sans aucune configuration.
Conséquence vérifiée de la règle « les services sont `final` » de cette suite : **on ne
peut pas décorer une classe concrète final.** `lint:container` le rejette purement et
simplement — *argument 1 of `BookController::__construct()` accepts `ShelfReader`,
`CachedShelfReader` passed*. Introduire le cache est donc aussi le moment où l'on extrait
une interface et où l'on repointe les consommateurs vers elle. C'est un coût réel ; c'est
aussi le coût honnête.

**Invalide par tag, pas par TTL.** Un TTL dit « ceci peut être faux pendant jusqu'à une
heure ». Un tag dit « c'est faux maintenant » — le service qui a modifié le livre appelle
`$this->cache->invalidateTags(['book_'.$book->getId()])` et l'entrée disparaît. Garde
quand même un TTL, comme filet de sécurité pour l'invalidation que tu as oublié d'écrire.

Nommage des clés, protection contre le stampede, et ce qui relève de `cache.app` par
rapport à `cache.system` : `references/caching.md`.

## Les caches propres à Doctrine

Quatre caches distincts, à des valeurs très différentes :

| Cache | Verdict |
|---|---|
| **Métadonnées** | Déjà actif. Rien à faire. |
| **Cache de requête** (parsing DQL → SQL) | Configure-le en `prod`. Ça ne coûte rien et ça retire un parsing à chaque requête. Pool : `cache.system`, car il est invalidé par un déploiement, pas par les données. |
| **Cache de résultat** | Opt-in par requête, `->enableResultCache(3600, 'shelf_rows')`. Uniquement pour une requête réellement coûteuse et réellement tolérante à la péremption. Pool : `cache.app`, car il contient des données. |
| **Cache de second niveau** | Non utilisé. Il est opt-in par entité, subtil à invalider, et chaque problème qu'il résout est mieux résolu en corrigeant la requête. |

Configuration et le piège de mettre en cache une requête dont on modifie ensuite le
résultat : `references/caching.md`.

## Hors de ce skill

- **OPcache, `opcache.preload`, `--optimize-autoloader`, inlining du conteneur** →
  `symfony-proglab-deployment`. Ce sont des réglages de déploiement, pas du code.
- **Écrire des méthodes de repository en général** → `symfony-proglab-doctrine`.
- **Logs, alerting, health checks** → `symfony-proglab-observability`. Le profiler et
  Stopwatch sont des instruments de développement et relèvent d'ici ; découvrir *en
  production* que quelque chose a cassé ou s'est bloqué relève de cet autre skill.
- **Structure des tests, fixtures, isolation** → `symfony-proglab-testing`. Ce skill
  n'ajoute que l'assertion de comptage de requêtes.
- **AssetMapper et HTTP/2** → `symfony-proglab-frontend`. HTTP/2 y est un prérequis, pas
  une optimisation ici.

## Symptôme → cause

| Symptôme | Cause |
|---|---|
| Le panneau Doctrine affiche 60 `SELECT` quasi identiques | Une collection d'entités est itérée en dehors d'un repository, généralement dans un template Twig |
| Une page est correcte avec 10 lignes, inutilisable avec 1 000 | N+1. Le nombre de requêtes est proportionnel aux lignes — l'assertion de croissance le détecte, un seuil fixe non |
| Un test de comptage de requêtes passe mais la page est manifestement lente | `APP_DEBUG=0` dans l'exécution du test. Le profiling est désactivé, le collector retourne **0**, et `assertLessThan()` passe pour la mauvaise raison. Voir `references/query-counting.md` |
| Le nombre est élevé sur le premier test d'un fichier et correct ensuite | Le noyau n'est pas redémarré avant la *première* requête, donc les requêtes de la fixture du test elle-même sont comptées. Réinitialise le data holder avant de mesurer |
| `Cache-Control: max-age=0, must-revalidate, private, s-maxage=3600` | La session a été touchée. Le cache partagé est désactivé ; `public: true` n'a servi à rien |
| Une page mise en cache affiche d'anciennes données après une modification | Invalidation par TTL seul. Tague l'entrée et invalide-la depuis le service qui écrit |
| Rupture de mémoire lors de l'export d'une grande table | `getResult()` hydrate tout. `toIterable()` + `$em->clear()` |
| PHPStan n'arrive pas à typer `$profile->getCollector('db')` | Elle retourne `DataCollectorInterface`. Restreins-la avec `assertInstanceOf` |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/query-counting.md` | Pour écrire ou corriger un test qui vérifie un nombre de requêtes — à lire avant, pas après |
| `references/queries.md` | Pour diagnostiquer un N+1, écrire une fetch join, projeter dans un DTO de lecture, itérer un grand jeu de résultats |
| `references/caching.md` | Pour ajouter n'importe quel cache : HTTP, applicatif, ou cache de résultat Doctrine |
