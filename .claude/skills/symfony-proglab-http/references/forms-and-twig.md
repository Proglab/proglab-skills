# Formulaires, réponses HTML et templates

La moitié HTML de la couche HTTP. Vérifié par rapport à `vendor/symfony/form/`,
`twig-bridge/`, `security-http/` et `vendor/twig/twig/src/Attribute/` sur Symfony 8.1.
**`vendor/` fait foi devant ce fichier.**

## Le FormType

`src/Form/`, `final`, `data_class` réglé sur un **DTO d'entrée** de `src/Dto/Input/`.

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

### `data_class` n'est jamais une entité

Lier un formulaire à une entité laisse une requête non validée écrire directement dans un
objet managé. Tout `flush()` plus tard dans la même requête — dans un service sans
rapport, dans un listener — la persiste. Le DTO supprime cette possibilité au lieu de
compter sur le fait que tout le monde s'en souvienne.

Ça fait aussi converger les deux points d'entrée vers un seul jeu de règles : le même
`AddBookInput`, avec les mêmes contraintes, est ce que `#[MapRequestPayload]` hydrate sur
la route `api_` et ce que le formulaire hydrate sur la route HTML. Une seule définition
de « un livre valide », deux transports.

### Aucun bouton dans le FormType

`$builder->add('submit', SubmitType::class)` déplace un libellé et une classe CSS dans
une classe qui n'a aucune idée si elle rend une page de création, une modale ou une
édition en ligne. Le bouton appartient au template :

```twig
{{ form_start(form) }}
    {{ form_row(form.title) }}
    …
    <button type="submit" class="button">{{ 'book.form.submit'|trans }}</button>
{{ form_end(form) }}
```

Le même raisonnement s'applique aux attributs de `form_start` et à la mise en page : le
FormType déclare les champs et leurs types, le template décide de leur apparence.

### Aucune contrainte dans le FormType

Ni `'constraints' => [new NotBlank()]` sur un champ, ni `constraints` sur le formulaire.
Les contraintes sont des attributs sur le DTO. Deux endroits pour déclarer la validation
signifie que la route API et la route HTML peuvent être en désaccord, et que ce
désaccord est découvert par un utilisateur.

`#[UniqueEntity]` va lui aussi sur le DTO, avec `entityClass:` — les détails, y compris
pourquoi `errorPath` et `identifierFieldNames` comptent, sont dans
`references/input-mapping.md`.

## `empty_data`, et les DTO en lecture seule

Un champ texte non rempli soumet `''`, et le composant Form mappe une valeur manquante à
`null`. Écrite dans une propriété typée non nullable, c'est un `TypeError` avant même que
le validateur ne s'exécute — le symptôme est un 500 sur un formulaire vide plutôt que le
« ce champ est requis » attendu.

**DTO mutable** (propriétés publiques avec valeurs par défaut, hydratées propriété par
propriété) : règle `empty_data` par champ à la valeur neutre du type de ce champ, et
laisse la contrainte signaler le vide.

```php
public string $title = '';
public int $pageCount = 0;
```

```php
->add('title', TextType::class, ['empty_data' => ''])
->add('pageCount', IntegerType::class, ['empty_data' => '0'])   // une chaîne : la valeur de vue
```

`empty_data` au niveau du champ est une valeur de *vue* — elle passe par le transformateur
de données du champ — c'est pourquoi `IntegerType` prend `'0'` et non `0`.

**DTO en lecture seule** avec un constructeur à propriétés promues : le composant Form ne
peut pas écrire les propriétés, il a donc besoin d'un callable `empty_data` au niveau du
formulaire qui construit l'objet à partir des enfants soumis.

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

Vérifié : soumettre `['body' => 'Great', 'rating' => '5']` avec ceci produit un
formulaire valide dont le `getData()` est un `ReviewInput`. Remarque les casts — les
enfants continuent de fournir `null` pour les entrées vides, et le constructeur est typé.

Le lecture seule est la meilleure forme pour un DTO, mais elle coûte ce callable, qui
doit rester synchronisé avec le constructeur. Sur un formulaire à quinze champs, un DTO
mutable avec des valeurs par défaut est le compromis honnête.

## Une seule action rend et traite

GET et POST sur la même route. Deux actions signifient deux noms de route, une
construction de formulaire dupliquée, et une redirection entre les deux qui perd les
données soumises en cas d'erreur.

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

Trois détails qui ne sont pas de la décoration :

- **`$form->getData()` est le DTO**, et il va directement au service. Le contrôleur ne
  lit pas les champs individuellement.
- **`Response::HTTP_SEE_OTHER` (303)** sur la redirection, pas le 302 par défaut. Turbo
  et l'API Fetch suivent un 302 après un POST en réémettant un POST ; 303 force le GET
  que Post/Redirect/Get est censé produire.
- **Le chemin invalide retourne le tableau**, qui se rend en **422**, pas 200 — voir
  ci-dessous.

## `#[Template]`

```php
#[Template('book/index.html.twig')]
public function index(): array
```

L'action retourne des variables de template ; une `Response` retournée à la place est
transmise sans y toucher, car Symfony ne déclenche l'événement de vue que si le
contrôleur n'en a pas retourné une. C'est tout l'idiome `array|Response`.

D'après `vendor/symfony/twig-bridge/EventListener/TemplateAttributeListener.php` :

| Comportement | Conséquence |
|---|---|
| Une `FormInterface` dans le tableau est convertie en `FormView` | Passe `$form`, jamais `$form->createView()` |
| Un formulaire soumis et invalide règle le statut à **422** | Turbo re-rend la page avec ses erreurs au lieu d'ignorer la réponse |
| Retourner `null`/rien utilise les arguments nommés du contrôleur comme variables | `#[Template(vars: ['book'])]` les restreint ; `vars: []` n'en passe aucune |
| `stream: true` retourne une `StreamedResponse` ; `block: 'x'` rend un seul bloc | Streaming HTTP et rendu de bloc — sans rapport avec les Turbo Streams, malgré le mot |

`AbstractController::render()` fait la même conversion en `FormView` et le même 422, donc
les deux sont interchangeables. `#[Template]` est préféré car il garde l'action en train
de retourner des données, ce qui la rend testable unitairement et symétrique avec
`#[Serialize]`.

`#[Template]` vit dans `Symfony\Bridge\Twig\Attribute` — pas dans `HttpKernel\Attribute`
où se trouvent les autres attributs de réponse. Il existe depuis Symfony 6.2.

## Répondre à une soumission par un Turbo Stream

Même action, une branche supplémentaire, quand la soumission doit mettre à jour
plusieurs zones disjointes de la page en même temps au lieu de naviguer :

```php
use Symfony\UX\Turbo\TurboBundle;

#[Route('/books', name: 'book_add', methods: ['POST'])]
public function add(Request $request): Response
{
    $form = $this->createForm(AddBookType::class);
    $form->handleRequest($request);

    if (!$form->isSubmitted() || !$form->isValid()) {
        return $this->render('book/new.html.twig', ['form' => $form]);   // 422, inchangé
    }

    $book = $this->creator->create($form->getData());

    if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('book/add.stream.html.twig', ['book' => $book]);
    }

    return $this->redirectToRoute('book_index', status: Response::HTTP_SEE_OTHER);
}
```

Ce qui relève de ce skill, vérifié sur `ux-turbo` 3.4 :

- **`setRequestFormat()` est ce qui règle le content type.** `render()` retourne une
  `Response` sans en avoir, et `Response::prepare()` le remplit à partir du format de la
  requête — `text/vnd.turbo-stream.html`. Sans cet appel, le corps part en `text/html`,
  que Turbo ignore.
- **`#[Template]` se comporte de façon identique** — il rend dans une `Response` de la
  même manière, donc une action qui règle le format et retourne un tableau revient quand
  même au format stream. L'attribut ne sait rien de Turbo et n'interfère pas.
- **Garde le garde-fou `getPreferredFormat()` et le repli en 303.** Vérifié : sans
  stream dans `Accept`, le même POST redirige, donc l'action fonctionne toujours depuis
  curl, depuis un client de test et avec JavaScript désactivé. Une action qui répond
  *uniquement* en format stream est cassée pour tous les autres.
- **La branche invalide ne change pas.** C'est toujours le rendu HTML en 422 ci-dessus,
  même quand la requête a accepté un stream — c'est de là que Turbo re-rend les erreurs
  du formulaire.

**Le fait qu'une interaction donnée doive être une réponse en stream, un Live Component
ou un stream poussé est arbitré dans `symfony-proglab-frontend`**, sur une seule question :
y a-t-il un état à mémoriser entre deux interactions ? Ce skill ne possède que la moitié
contrôleur.

## Actions qui modifient l'état sans formulaire

Un formulaire porte automatiquement un jeton CSRF. Un POST venant d'un simple
`<button>` — « marquer comme lu », « retirer de l'étagère » — n'en porte pas, donc
déclare-le :

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

Depuis Symfony 7.1. `tokenKey:` renomme le champ, `methods:` restreint les verbes
vérifiés, et `tokenSource:` (7.4) lit le jeton depuis la query string ou un header au
lieu du payload — `IsCsrfTokenValid::SOURCE_HEADER` est ce que veut un appel `fetch()`.
Avant 7.1, `$this->isCsrfTokenValid('mark_read',
$request->request->getString('_token'))` dans l'action ; la règle ne change pas, seul
l'emplacement change.

`#[IsSignatureValid]` (7.4) couvre l'autre cas : une URL qui doit être infalsifiable sans
session — un lien de désabonnement, un téléchargement à usage unique. `UriSigner::sign()`
la construit, l'attribut la vérifie.

```php
#[Route('/unsubscribe/{id}', name: 'unsubscribe', methods: ['GET'])]
#[IsSignatureValid]
public function unsubscribe(int $id): Response
```

Les statuts sont déjà corrects, tu n'as rien à mapper :
`SignedUriException` porte `#[WithHttpStatus(404)]` et `ExpiredSignedUriException`
en hérite avec `#[WithHttpStatus(403)]`. Mesuré avec un `framework.exceptions` vide : une
URL non signée donne **404**, une falsifiée **404**, une expirée **403**.

404 plutôt que 403 sur une mauvaise signature est délibéré : une URL signée qui ne se
vérifie pas devrait avoir l'air de n'avoir jamais existé, plutôt que de confirmer que
quelque chose se trouve derrière une mauvaise signature.

Ne surcharge les niveaux que si tu veux les journaliser différemment :

```yaml
# config/packages/framework.yaml
framework:
    exceptions:
        Symfony\Component\HttpFoundation\Exception\SignedUriException:
            log_level: warning
```

**En dessous de 7.4 l'attribut n'existe pas.** La règle survit, le moyen change :
injecte `Symfony\Component\HttpFoundation\UriSigner` (6.4+) et vérifie en haut de
l'action, en levant le même statut toi-même.

```php
public function unsubscribe(Request $request, int $id, UriSigner $signer): Response
{
    if (!$signer->checkRequest($request)) {
        throw $this->createNotFoundException();      // 404, pour la raison ci-dessus
    }
    // …
}
```

`checkRequest()` ne distingue pas « expiré » de « falsifié », donc sur ces versions les
deux répondent 404 — la direction prudente, et la même que celle choisie par l'attribut
pour une mauvaise signature.

`#[Cache]` vit lui aussi sur les actions, mais l'ajouter est une décision de performance
qui a besoin d'une mesure au préalable — `symfony-proglab-performance` couvre quand c'est
justifié et pourquoi `public: true` sur une réponse personnalisée est une fuite de
données.

## Conventions Twig

- **Noms de fichiers en snake_case**, un répertoire par contrôleur :
  `templates/book/index.html.twig`.
- **Fragments préfixés `_`** : `_book_card.html.twig`, `_form_errors.html.twig`. Le
  préfixe indique d'un coup d'œil qu'un fichier est inclus et non rendu par une route, ce
  qui est la seule chose à savoir en parcourant un répertoire.
- **Les clés de traduction nomment l'intention, pas le texte** : `book.flash.added`, pas
  `Book added!`. Une clé qui reprend son texte anglais doit être renommée quand un
  rédacteur change un mot, et le renommage est invisible pour le compilateur. Catalogues
  XLIFF.
- **Aucune logique métier dans un template.** `{% if book.rating > 3 %}` est une règle
  que rien ne teste. Calcule-la dans le service, expose-la sur le DTO de sortie sous
  `book.recommended`, et laisse le template poser une question à laquelle il peut
  répondre.
- **Aucun `<script>` inline.** Le comportement va dans un contrôleur Stimulus, et aucune
  règle métier ne va dans JavaScript du tout — voir `symfony-proglab-frontend`.
- **Filtres et fonctions Twig via `Twig\Attribute\AsTwigFilter` et apparentés**, jamais
  une classe `AbstractExtension`. `symfony-proglab-frontend` possède cette règle, avec les
  précisions de paquet et de version ; ne la répète pas ici.

Les thèmes de formulaire sont déclarés une fois dans `config/packages/twig.yaml`, jamais
avec `{% form_theme %}` dans un template ; le thème Tailwind et le reste du standard
front-end sont dans `symfony-proglab-frontend`.
