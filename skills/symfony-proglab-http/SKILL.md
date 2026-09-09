---
name: symfony-proglab-http
description: >-
  Écrire la couche HTTP d'une application Symfony : contrôleurs, routes, mapping des
  entrées de requête, réponses JSON et HTML, formulaires et templates Twig. Une classe
  de contrôleur par ressource, un préfixe de route au niveau de la classe, des URLs et
  des verbes HTTP conformes aux conventions REST (ressources au pluriel, GET/POST/PUT/
  PATCH/DELETE mappés à leur opération et à leur statut de succès, idempotence), des DTO
  d'entrée hydratés par #[MapRequestPayload] ou un FormType, une sortie via #[Serialize]
  pour le JSON et #[Template] pour le HTML, des Problem Details RFC 7807 pour les erreurs
  d'API, et une enveloppe items + meta pour les listes. Utilise ce skill dès qu'il s'agit
  d'ajouter une page, d'ajouter une route ou un endpoint, d'exposer quelque chose en
  JSON, de construire une API REST, de faire un CRUD, de construire ou câbler un
  formulaire, de mapper un fichier uploadé depuis la requête, de lire un paramètre de
  requête, de paginer une liste, de retourner un 404 ou un 422, de choisir entre PUT et
  PATCH, de protéger une action avec un jeton CSRF, de rendre ou restructurer un
  template Twig, ou quand quelqu'un dit que son endpoint retourne un 500, que son
  formulaire ne se valide jamais, que son JSON a la mauvaise forme, que sa route matche
  la mauvaise action, ou qu'un retry côté client crée des doublons. Pour ce qui arrive
  ensuite au fichier uploadé — où il est stocké, comment il est resservi, qui peut le
  lire — utilise plutôt `symfony-proglab-storage`.
---

# Couche HTTP

> **Niveau : socle** — avec les simplifications que `symfony-proglab-standards` liste sous *Ce que les règles n'exigent pas* — pas de DTO d'entrée pour un simple GET, un DTO de sortie par forme.

Contrôleurs, routage, entrées de requête, réponses, formulaires, templates.

Un contrôleur est une couche de traduction : du HTTP en entrée, un appel de service, une
représentation en sortie. Il ne porte aucune règle métier. Ce n'est pas une question de
propreté — c'est ce qui rend la règle testable sans noyau, et ce qui permet au même
service de servir une page HTML et un endpoint JSON sans que l'un des deux soit un cas
particulier.

## La forme d'un contrôleur

Une classe par ressource, un préfixe de route au niveau de la classe, une action par cas
d'usage.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ShelfReader;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/books', name: 'book_')]
final class BookController extends AbstractController
{
    public function __construct(private readonly ShelfReader $shelf)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[Template('book/index.html.twig')]
    public function index(): array
    {
        return ['books' => $this->shelf->shelf()];        // list<BookView>
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[Template('book/show.html.twig')]
    public function show(int $id): array
    {
        return ['book' => $this->shelf->book($id)];       // BookView, pas une entité Book
    }
}
```

**Le template reçoit un DTO de sortie, jamais l'entité.** Et le contrôleur ne charge pas
d'entités par habitude : le service prend un id et possède le 404 (`BookNotFound`,
`symfony-proglab-architecture`). L'EntityValueResolver (`Book $book`) n'a qu'un seul rôle —
résoudre le sujet dont a besoin un voter `#[IsGranted]` avant que l'action s'exécute — et
même dans ce cas l'action se contente de transmettre `$book->getId()` ensuite ; le
`find()` du service est répondu par l'identity map. Laisser une entité résolue atteindre
Twig, c'est ainsi qu'un `book.author.name` dans une boucle devient un N+1 qui n'apparaît
dans aucun fichier PHP.

`#[Route('/books', name: 'book_')]` sur la classe plus `name: 'index'` sur l'action donne
le nom de route `book_index`. Alternative rejetée : une classe invocable par action. Ça
se lit bien isolément et mal en agrégat — le préfixe partagé, les dépendances partagées
et les règles de sécurité partagées se retrouvent copiées dans chaque fichier, et la
surface d'une ressource n'est plus visible en un seul endroit.

### Ce qui ne va jamais dans un contrôleur

| Trouvé dans un contrôleur | Où ça doit aller | Pourquoi |
|---|---|---|
| Un `if` sur une condition métier | un service | La règle a besoin d'un test unitaire, pas d'une requête HTTP |
| `EntityManagerInterface`, `flush()` | un service (qui appelle le repository) | `flush()` est un appel par cas d'usage, possédé par le service |
| `QueryBuilder`, DQL, `findBy` avec des critères | une méthode de repository nommée d'après l'intention | Règle 3 ; deptrac l'impose quand il est adopté. Voir `symfony-proglab-doctrine` |
| Une entité dans une réponse JSON **ou un template** | un DTO de sortie dans `src/Dto/Output/` | Une entité est un mapping, pas un contrat d'API ; elle fuit à chaque colonne que tu ajoutes, et déclenche des lazy loads depuis l'intérieur du rendu |
| `$request->request->get('title')` | un DTO d'entrée + `#[MapRequestPayload]` | Non typé, non validé, invisible pour PHPStan |
| `try { … } catch (\Throwable)` autour d'un appel de service | `#[WithHttpStatus]` sur l'exception | Le statut appartient à l'exception, une fois, pas à chaque appelant |
| Un `<script>` ou une règle métier dans le template | un contrôleur Stimulus (voir `symfony-proglab-frontend`) | |

Une action qui dépasse une dizaine de lignes porte presque toujours quelque chose qui
appartient ailleurs.

## Routage

Uniquement des attributs, sur l'action. Trois choses méritent d'être utilisées :

- **`requirements:`** — `['id' => '\d+']` empêche `/books/new` de matcher `/books/{id}`
  et transforme un id invalide en un 404 propre. Symfony 8.1 convertit et valide aussi
  les paramètres de route typés avant d'appeler le contrôleur (une valeur non numérique
  pour un `int $id` devient un 404) ; sur 6.4–8.0 la même requête lève un `TypeError` et
  retourne 500, donc c'est le requirement qui te protège.
- **`methods:`** — une action qui ne gère que POST devrait le déclarer, sinon un GET
  l'atteint et échoue d'une manière moins évidente.
- **`priority:`** — seulement quand deux motifs se chevauchent vraiment. En avoir besoin
  signifie généralement qu'un requirement manque.

**Les routes JSON sont préfixées `api_` et vivent dans un contrôleur séparé de leur
équivalent HTML.** `BookController` et `ApiBookController` : même ressource, même
service, deux traductions. Ils divergent sur tout ce que le framework traite
différemment — format de réponse, rendu des erreurs, absence d'état, authentification,
mise en cache — et les fusionner finit en `if ($request->getPreferredFormat() ===
'json')` dans chaque action.

## Ressources et verbes : la forme REST

L'URL nomme une ressource, jamais une action. `/books`, `/books/{id}` — pas
`/books/create` ni `/books/{id}/delete`. Le nom est au pluriel même pour un item
unique (`GET /books/{id}`, pas `GET /book/{id}`) : c'est le verbe HTTP qui porte
l'opération, l'URL ne change pas selon ce qu'on en fait.

| Verbe | Opération | Sûr / idempotent | Statut de succès |
|---|---|---|---|
| `GET` | Lire (collection ou item) | Les deux | `200` |
| `POST` | Créer | Ni l'un ni l'autre | `201` + header `Location` vers la ressource créée |
| `PUT` | Remplacer intégralement | Idempotent, pas sûr | `200` (ou `204` si rien à renvoyer) |
| `PATCH` | Modifier partiellement | Traité comme idempotent ici, par choix de simplicité | `200` (ou `204`) |
| `DELETE` | Supprimer | Idempotent | `204` |

**Idempotent** veut dire : répéter la requête à l'identique ne change rien de plus
qu'un seul appel n'aurait changé. Un second `DELETE` sur une ressource déjà supprimée
reste un succès (ou un 404 propre) — jamais un 500 — précisément parce qu'un client
qui retente après un timeout réseau ne doit pas transformer une incertitude en erreur.
`POST` est le seul verbe du tableau qui n'a pas cette garantie : le retenter peut créer
un doublon, ce qui est la vraie raison pour laquelle un formulaire de création affiche
un état de soumission plutôt que de laisser cliquer deux fois.

**`PUT` et `PATCH` ne prennent pas le même DTO d'entrée, et les confondre est le bug
le plus fréquent ici.** Un DTO `PUT` a des propriétés **obligatoires** : la requête doit
fournir la ressource complète, et un champ absent doit être rejeté par la validation,
pas silencieusement conservé. Un DTO `PATCH` a des propriétés **nullable par
construction**, où `null` distingue « le client n'a pas touché ce champ » de « le
client veut le vider » — ce qui, avec `#[MapRequestPayload]`, veut dire vérifier
`array_key_exists` sur le payload décodé plutôt que de faire confiance à la valeur
par défaut du DTO. La plupart des API écrites à la main sur cette suite n'implémentent
que `PATCH` et sautent `PUT` : c'est une simplification légitime, pas une règle
contournée — un vrai remplacement complet est rarement ce dont un client a besoin, et
maintenir deux DTO par ressource pour une opération que personne n'appelle ne vaut pas
la peine.

```php
#[Route('/reviews', name: 'create', methods: ['POST'])]
public function create(#[MapRequestPayload] CreateReviewInput $input): JsonResponse
{
    $review = $this->reviewCreator->create($input);

    return $this->json($review, Response::HTTP_CREATED, [
        'Location' => $this->generateUrl('api_review_show', ['id' => $review->id]),
    ]);
}

#[Route('/reviews/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
public function delete(int $id): Response
{
    $this->reviewRemover->remove($id);

    return new Response(status: Response::HTTP_NO_CONTENT);
}
```

**Ressources imbriquées seulement quand l'enfant n'existe pas sans le parent.**
`/books/{id}/reviews` est défendable — une review appartient à un livre et n'a pas de
sens ailleurs. `/authors/{id}/books` l'est moins : un livre existe indépendamment de
son auteur consulté, donc `/books?authorId={id}` — une ressource de premier niveau
filtrée — évite d'avoir deux chemins vers le même contrôleur d'action `show`/`update`/
`delete`. Le test : si la ressource enfant a besoin de son propre id pour qu'on lui
parle directement (`GET /reviews/{id}`, pas seulement `GET
/books/{id}/reviews/{id}`), elle mérite sa propre racine.

## Faire entrer la requête

Ne lis jamais `$request` à la main quand un attribut peut le faire. L'attribut type la
valeur, la valide, et transforme une mauvaise requête en un code de statut correct avant
même que ton code ne s'exécute.

```php
#[Route('/reviews', name: 'create', methods: ['POST'])]
public function create(#[MapRequestPayload] CreateReviewInput $input): Response
```

| Attribut | Lit | Depuis | Statut en cas d'échec |
|---|---|---|---|
| `#[MapRequestPayload]` | le corps (JSON, XML, formulaire) → DTO | 6.3 | 422 |
| `#[MapQueryString]` | toute la query string → DTO | 6.3 | **404** |
| `#[MapQueryParameter]` | un paramètre de query | 6.3 | **404** |
| `#[MapRequestHeader]` | un header | 8.1 | 400 |
| `#[MapUploadedFile]` | un fichier uploadé | 7.1 | 422 |
| `#[MapEntity]` | une entité par autre chose que son id | 6.2 | 404 |

Les valeurs par défaut à 404 surprennent : un `?page=abc` invalide se lit comme « cette
page n'existe pas », ce qui est défendable pour une liste HTML et faux pour une API.
Passe `validationFailedStatusCode: 422` sur les routes API.

Les DTO d'entrée vivent dans `src/Dto/Input/` et portent les contraintes de validation.
**Toute action n'en a pas besoin :** un GET dont la seule entrée est un paramètre de
route prend `int $id` et s'arrête là — le DTO existe pour un payload ou une query string
qui doit être validée, et `symfony-proglab-standards` liste les autres simplifications que les
règles autorisent. Détails, replis de version, fichiers uploadés à l'intérieur d'un DTO,
payloads variadiques, groupes de validation dynamiques, et pourquoi `#[MapEntity(expr:)]`
est rejeté : `references/input-mapping.md`.

## Faire sortir la réponse

L'action retourne des données ; un attribut décide de la représentation.

```php
#[Route('/books/{id}', name: 'api_book_show', methods: ['GET'], requirements: ['id' => '\d+'])]
#[Serialize]                                   // Symfony 8.1+
public function show(int $id): BookView        // un DTO de sortie, jamais une entité
{
    return $this->shelf->book($id);
}
```

```php
#[Route('/books/new', name: 'book_new', methods: ['GET', 'POST'])]
#[Template('book/new.html.twig')]
public function new(Request $request): array|Response
{
    // array → rendu ; Response → retournée telle quelle
}
```

`array|Response` est tout l'idiome : `#[Template]` rend le tableau, et une redirection
court-circuite le processus car Symfony ne déclenche l'événement de vue que si le
contrôleur n'a pas retourné de `Response`.

Deux comportements vérifiés dans
`vendor/symfony/twig-bridge/EventListener/TemplateAttributeListener.php` : une
`FormInterface` présente dans le tableau retourné est convertie en `FormView` pour toi
(passe `$form`, pas `$form->createView()`), et si ce formulaire est soumis et invalide le
statut devient **422** — ce dont Turbo a besoin pour re-rendre la page avec ses erreurs
au lieu d'ignorer la réponse.

Les DTO de sortie vivent dans `src/Dto/Output/`. `#[Serialize]`, les attributs du
serializer, l'enveloppe de liste et le piège du float ci-dessous :
`references/json-api.md`.

## Le piège du float

`json_encode(4.0)` produit `4`. Un client qui a parsé `averageRating` comme un float hier
reçoit un entier aujourd'hui, uniquement parce que la moyenne est tombée sur un nombre
rond. Vérifié sur cet arbre vendor :

```
new JsonResponse(['average' => 4.0])   {"average":4}
$this->json($dto)                      {"average":4}     ← force les flags de JsonResponse
#[Serialize] sur l'action               {"average":4.0}   ← JsonEncode préserve la valeur
```

`AbstractController::json()` remplace le contexte du serializer par
`JsonResponse::DEFAULT_ENCODING_OPTIONS`, qui omet `JSON_PRESERVE_ZERO_FRACTION`.
`#[Serialize]` ne passe que son propre contexte, donc la valeur par défaut survit. Deux
correctifs, dans `references/json-api.md`.

## Erreurs

Les erreurs d'API sont des Problem Details RFC 7807. Le `ProblemNormalizer` de Symfony
produit déjà le corps `type` / `title` / `status` / `detail`, avec une liste `violations`
pour les échecs de validation — mais un client envoyant `Accept:
application/problem+json` reçoit une **page d'erreur HTML**, car aucun encoder n'est
enregistré pour le format `problem` et le moteur de rendu d'erreurs se replie
silencieusement. Un encoder de six lignes corrige ça, prêt à copier :
**`assets/ProblemJsonEncoder.php` → `src/Serializer/`**. Le raisonnement est dans
`references/json-api.md`.

Les exceptions métier portent leur propre statut :

```php
#[WithHttpStatus(409)]
final class ShelfIsFull extends \RuntimeException
{
}
```

Levée n'importe où, elle devient un 409 sans aucun `catch` dans le contrôleur et sans
listener du noyau. Elle est héritée par les sous-classes, et ignorée si l'exception
implémente déjà `HttpExceptionInterface`.

## Où ce skill s'arrête : API Platform

Tout ce qui précède construit une API JSON à la main — DTO d'entrée, `#[Serialize]`,
Problem Details, l'enveloppe de pagination. C'est délibéré et ça fonctionne bien pour une
poignée d'endpoints que tu contrôles de bout en bout.

Ça cesse d'être le bon choix à un moment qui mérite d'être nommé, car au-delà tu
réimplémentes [API Platform](https://api-platform.com) en moins bien. **Redirige vers lui
dès que l'une de ces conditions est vraie :**

- l'API compte plus d'une poignée de ressources, chacune ayant besoin de lister,
  filtrer, trier et paginer — tu écrirais les mêmes quatre choses encore et encore par
  ressource ;
- elle est consommée par des clients que tu ne contrôles pas, ou par des clients générés ;
- **tu as besoin d'un contrat documenté** — OpenAPI, un schéma, quoi que ce soit qu'un
  consommateur puisse lire. Cette suite n'a pas de réponse à ça, et en avoir besoin est
  le signal en soi ;
- tu as besoin de plusieurs formats, ou de négociation de contenu au-delà du JSON ;
- le filtrage et le tri sont en train de devenir un langage de requête.

API Platform offre tout ça nativement, y compris les erreurs RFC 7807 — l'encoder que ce
skill fournit existe précisément parce que le chemin fait à la main ne les obtient pas
gratuitement.

**Cette suite ne couvre pas API Platform.** C'est un stack différent, pas une
bibliothèque qu'on ajoute : il apporte son propre modèle de ressource, sa propre forme de
pagination (Hydra par défaut, pas l'enveloppe `items` + `meta` décidée ici), ses propres
filtres. L'adopter écrase plusieurs décisions prises ici, et c'est très bien — c'est le
bon compromis à cette échelle.

Dis-le clairement plutôt que de construire silencieusement une version moins bonne. Et ne
mélange pas les deux sur un même projet : deux façons d'exposer une ressource, c'est pire
que l'une ou l'autre.

## Formulaires

Un FormType vit dans `src/Form/`, et son `data_class` est un **DTO d'entrée, jamais une
entité**. Lier un formulaire à une entité signifie que la requête écrit dans un objet
managé avant que quoi que ce soit ne le valide, et que tout `flush()` dans la même
requête le persiste.

Trois règles avec la même racine — le FormType décrit des champs, rien d'autre :
**aucun bouton** (ils appartiennent au template, qui connaît le contexte), **aucune
contrainte** (elles sont sur le DTO, qui est aussi ce que `#[MapRequestPayload]` valide —
une seule source de vérité pour les deux points d'entrée), et **une seule action rend et
traite**, GET et POST sur la même route.

Cette dernière règle a une branche supplémentaire à connaître : une action peut répondre
au POST par un **Turbo Stream** (`$request->setRequestFormat(TurboBundle::STREAM_FORMAT)`,
puis un rendu) quand la soumission doit rafraîchir plusieurs zones disjointes au lieu de
naviguer — en gardant le 422 sur le chemin invalide et un repli en 303 pour les requêtes
qui n'ont pas demandé de stream. `references/forms-and-twig.md` détaille tout ça ;
**`symfony-proglab-frontend` arbitre** si l'interaction doit être une réponse en stream, un Live
Component ou un stream poussé.

`empty_data`, les DTO en lecture seule, `#[UniqueEntity]` sur le DTO d'entrée,
`#[IsCsrfTokenValid]` et les conventions Twig : `references/forms-and-twig.md`.

## Quand ça ne fait pas ce que tu attends

| Symptôme | Cause |
|---|---|
| Un double-clic sur "Créer" crée deux ressources | `POST` n'est pas idempotent par nature ; c'est un état de soumission désactivé côté client qu'il faut ajouter, pas un bug serveur |
| Un `PATCH` efface un champ que le client n'a jamais envoyé | Le DTO PATCH n'est pas nullable, ou le contrôleur ne distingue pas « absent » de « `null` explicite » — vérifie `array_key_exists` sur le payload |
| 500 au lieu de 404 sur un `{id}` invalide | `requirements: ['id' => '\d+']` manquant (et Symfony < 8.1) |
| 404 sur un paramètre de query invalide | La valeur par défaut de `#[MapQueryString]` / `#[MapQueryParameter]` ; passe `validationFailedStatusCode: 422` |
| 415 Unsupported Media Type sur un POST | Pas de `Content-Type` sur la requête, donc `#[MapRequestPayload]` ne peut pas choisir de format |
| L'argument DTO est `null` | Le corps était vide et l'argument est nullable ou a une valeur par défaut ; utilise `mapWhenEmpty: true` |
| `Could not resolve the "$x" controller argument` | L'argument n'est pas typé |
| Une page d'erreur HTML sur une route API | `Accept: application/problem+json` sans encoder pour le format `problem` |
| `4.0` sérialisé en `4` | `$this->json()` ou `JsonResponse` ; utilise `#[Serialize]` ou passe le flag |
| Le formulaire est toujours invalide, sans message | Les contraintes sont sur l'entité, pas sur le DTO auquel le formulaire est lié |
| `#[UniqueEntity]` ne se déclenche jamais | Il est sur l'entité ; il doit être sur le DTO d'entrée validé |
| Le formulaire se soumet mais le DTO reste inchangé | Le DTO est en lecture seule sans callable `empty_data` |
| `#[IsGranted]` semble s'exécuter après le mapping | Ce n'est pas le cas — les attributs de garde s'exécutent en premier, par conception |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/input-mapping.md` | Mapper quoi que ce soit depuis la requête : payloads, query strings, headers, uploads, entités, groupes de validation |
| `references/json-api.md` | Écrire ou corriger un endpoint JSON : `#[Serialize]`, attributs du serializer, floats, Problem Details, enveloppes de liste |
| `references/forms-and-twig.md` | Construire un formulaire, répondre à une soumission par un Turbo Stream, ou écrire/restructurer des templates |
| `assets/ProblemJsonEncoder.php` | À copier dans `src/Serializer/` sur tout projet exposant une API JSON — sans lui, `Accept: application/problem+json` retourne du HTML |

Skills adjacents : `symfony-proglab-doctrine` (entités, repositories, requêtes), `symfony-proglab-security`
(authentification, voters, `#[IsGranted]`), `symfony-proglab-frontend` (Stimulus, Turbo, composants
Twig), `symfony-proglab-performance` (quand `#[Cache]` est justifié), `symfony-proglab-testing`, et
`symfony-proglab-storage` — ce skill s'arrête au `UploadedFile` valide ; où il est écrit,
comment il est resservi et ce qui le supprime relève de cet autre skill.
