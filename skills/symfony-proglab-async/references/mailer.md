# Email

Asynchrone par défaut, rendu par le worker — c'est de là que viennent les pièges.

## Sommaire

- [Ce qui se passe réellement quand on appelle `send()`](#ce-qui-se-passe-réellement-quand-on-appelle-send)
- [Le piège de la sérialisation](#le-piège-de-la-sérialisation)
- [Écrire l'email](#écrire-lemail)
- [Inlining du CSS](#inlining-du-css)
- [Configuration par environnement](#configuration-par-environnement)
- [Tester les emails](#tester-les-emails)
- [Quand l'email n'est pas livré](#quand-lemail-nest-pas-livré)

## Ce qui se passe réellement quand on appelle `send()`

Avec Messenger installé, `MailerInterface::send()` n'envoie rien du tout. Elle enveloppe
le message dans un `SendEmailMessage` et le dispatche ; la recipe route ça vers `async`.

Les conséquences méritent d'être détaillées, car c'est de là que viennent la plupart des
surprises liées aux emails :

1. Le contrôleur renvoie une réponse avant même que l'email n'existe sous forme d'octets.
2. `MessageEvent` est dispatché deux fois : une fois au moment du dispatch avec
   `queued = true` sur un **clone** (pour que les listeners puissent l'inspecter), et une
   fois dans le worker avec `queued = false` sur le message réel.
3. **Le rendu Twig a lieu dans le worker**, lors du second événement. `MessageListener` y
   exécute `BodyRenderer`. Le clone rendu au moment du dispatch est jeté.
4. Ce qui transite par la file d'attente est donc le *nom* du template plus le tableau de
   contexte, sérialisé avec `serialize()` de PHP.

Le point 4 est la raison d'être de toute la section suivante.

## Le piège de la sérialisation

```php
// Cassé. Ça peut même passer en local avec un transport synchrone.
$email->context(['review' => $review]);
```

Une entité Doctrine dans le contexte est sérialisée dans la ligne de la file d'attente.
Trois façons que ça tourne mal, dans l'ordre croissant du temps qu'il faut pour le
diagnostiquer :

- **Ça échoue bruyamment** : la propriété était un proxy lazy, et sa désérialisation lève
  `Cannot instantiate proxy` ou entraîne une collection non initialisée.
- **Ça échoue silencieusement** : l'entité se sérialise sans problème, le worker la rend,
  et l'email affiche le titre de la critique tel qu'il était avant que l'utilisateur ne le
  corrige trente secondes plus tôt.
- **Ça échoue plus tard** : quelqu'un ajoute une relation à l'entité, le payload sérialisé
  triple de taille, et la table de la file d'attente grossit pour des raisons que personne
  ne relie à ce commit.

Deux formes correctes. Préfère la première.

```php
// Uniquement des scalaires — le template reçoit exactement ce qu'il affiche.
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
// Ou bien rendre maintenant et mettre en file du HTML brut, quand le contenu doit refléter exactement cet instant.
$email = (new Email())
    ->to($authorEmail)
    ->subject('Your review has been published')
    ->html($this->twig->render('emails/review_published.html.twig', ['review' => $review]));
```

Rendre en avance te coûte l'isolation du worker — une erreur Twig casse alors la requête
au lieu d'un job en arrière-plan — c'est donc l'exception, pas la règle.

La règle des identifiants de `SKILL.md` est la même règle vue sous un autre angle : **un
id plus les scalaires dont le template a besoin**, jamais le graphe d'objets.

Les URLs absolues en font aussi partie. Dans un worker, il n'y a pas de requête, donc un
`path()` relatif dans un template d'email produit un lien vers nulle part. Génère avec
`UrlGeneratorInterface::ABSOLUTE_URL`, ou utilise `url()` dans Twig avec
`framework.router.default_uri` configuré — et configure-le, parce qu'un
`http://localhost` dans un email de production, c'est un ticket support garanti.

## Écrire l'email

`TemplatedEmail` (`Symfony\Bridge\Twig\Mime\TemplatedEmail`) prend `htmlTemplate()` et,
en option, `textTemplate()`. Avec seulement un template HTML, Symfony dérive la partie
texte en retirant les balises, ce qui est généralement suffisant ; écris un template
texte quand le HTML est assez chargé en mise en page pour que ce dépouillement produise
du bruit.

Construire l'email relève d'un service — un `ReviewMailer`, ni le contrôleur ni le
handler. C'est ce service qui est testé unitairement, et c'est vers lui que pointe la
règle d'appel direct de `symfony-proglab-architecture` quand un cas d'usage se termine par
« et notifier l'auteur ».

## Inlining du CSS

Les clients email ignorent les blocs `<style>` de façon inégale, donc le CSS doit finir
dans des attributs `style=`. Symfony fait ça dans Twig :

```bash
composer require twig/cssinliner-extra
```

`twig/extra-bundle` enregistre automatiquement `CssInlinerExtension` dès que le paquet
est présent, ce qui ajoute le filtre `inline_css`. Ensuite :

```twig
{% apply inline_css(source('@styles/email.css')) %}
    <h1>{{ reviewTitle }}</h1>
{% endapply %}
```

Deux choses à savoir : le filtre s'exécute au moment du rendu, ce qui pour un email mis
en file signifie **dans le worker**, donc la feuille de style doit être lisible depuis le
système de fichiers du worker ; et c'est un coût par rendu sur chaque email, donc garde la
feuille de style petite plutôt que d'y faire passer tout le CSS de ton application.

## Configuration par environnement

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
# config/packages/messenger.yaml — la recipe écrit cette ligne avec `async` ; ce
# standard route l'email vers le transport haute priorité, car une confirmation
# d'inscription ne doit pas attendre derrière un export nocturne (voir references/messenger.md).
framework:
    messenger:
        routing:
            Symfony\Component\Mailer\Messenger\SendEmailMessage: async_high
```

`envelope.recipients` réécrit tous les destinataires, ce qui est ce qu'on veut sur un
environnement de staging restauré depuis un dump de production. `allowed_recipients` (une
liste d'expressions régulières, Symfony 7.1+) y perce des trous pour que les vraies
adresses de ton propre domaine passent quand même ; avant 7.1, `recipients` est tout ou
rien.

En local, `MAILER_DSN` pointe vers Mailpit et est injecté par la Symfony CLI — voir
`symfony-proglab-local-dev`. **Rien n'apparaît dans Mailpit tant qu'un worker ne consomme
pas le transport.** C'est de loin le rapport « mes emails sont cassés » le plus fréquent
sur ce standard, et la réponse est `symfony console messenger:consume async -vv`.

## Tester les emails

```php
// Le service qui construit l'email — un test unitaire, sans kernel.
$mailer = $this->createMock(MailerInterface::class);
$mailer->expects(self::once())
    ->method('send')
    ->with(self::callback(static function (TemplatedEmail $email): bool {
        self::assertSame('emails/review_published.html.twig', $email->getHtmlTemplate());
        self::assertArrayNotHasKey('review', $email->getContext());

        return true;
    }));
```

Vérifier que le contexte ne contient aucune entité mérite d'être écrit une fois pour
toutes : c'est la règle qui casse silencieusement, et c'est le seul endroit qui s'en
aperçoit.

```php
// Fonctionnellement, à travers le kernel.
$client->request('POST', '/reviews/12/publish');

self::assertQueuedEmailCount(1);          // pas assertEmailCount()
$email = self::getMailerMessage(0);
self::assertEmailAddressContains($email, 'To', 'author@example.com');
self::assertEmailHtmlBodyContains($email, 'has been published');
```

`assertEmailCount()` compte les événements où `queued` vaut false. Avec le mail routé
vers `async`, il n'y en a aucun, et l'assertion échoue avec « has sent 0 emails » alors
que le mail est bien assis dans le transport — exact, et totalement trompeur si on ne
sait pas pourquoi.

Les assertions sur le corps fonctionnent quand même sur un email mis en file, parce que
le clone capturé par `MessageLoggerListener` au moment du dispatch a été rendu avant
d'être jeté. Utile, mais il faut savoir que c'est un effet de bord plutôt qu'une garantie
sur laquelle construire une suite de tests.

Les deux assertions nécessitent `framework.test: true` et un client démarré ; elles
lisent le service `mailer.message_logger_listener`, et échouent avec un message explicite
si Mailer n'est pas installé.

## Quand l'email n'est pas livré

| Symptôme | Cause |
|---|---|
| Rien dans Mailpit | Aucun worker ne consomme `async` |
| `assertEmailCount()` échoue, `assertQueuedEmailCount()` passe | Fonctionne comme prévu — utilise l'assertion queued |
| `Cannot instantiate proxy` dans le worker | Une entité Doctrine dans le contexte de l'email |
| Les liens pointent vers `http://localhost` | Pas de `framework.router.default_uri`, ou `path()` au lieu de `url()` |
| Styles manquants dans Gmail | `inline_css` non appliqué, ou la feuille de style illisible depuis le worker |
| Envoyé deux fois | Un handler retenté qui envoie avant d'enregistrer qu'il a envoyé — idempotence, règle 2 |
| Correct en dev, vide en prod | `MAILER_DSN` toujours à `null://null` dans l'environnement déployé |
