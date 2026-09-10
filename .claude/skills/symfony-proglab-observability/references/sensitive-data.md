# Ce qui ne doit jamais atteindre un log

Une ligne de log n'est pas une note privée à soi-même. Elle est copiée dans une sauvegarde
nocturne, envoyée à un agrégateur tiers via internet, collée dans un ticket de support, et
affichée sur un écran d'open space. Tout ce qui y est écrit doit être considéré comme
lisible par plus de monde que la base de données d'où c'est venu — et, contrairement à la
base de données, c'est rarement couvert par une politique de rétention ou une demande de
suppression.

## La liste

| Jamais | Pourquoi c'est pire que ça n'en a l'air |
|---|---|
| Les mots de passe, sous quelque forme que ce soit | Y compris le « mauvais » mot de passe saisi par un utilisateur — c'est généralement son mot de passe pour un autre site |
| Les tokens : identifiants de session, clés d'API, JWT, tokens de réinitialisation et de confirmation, en-têtes `Authorization` | Un token de réinitialisation dans un log est une prise de contrôle de compte fonctionnelle tant qu'il reste valide |
| Numéros de carte, CVV, IBAN | Aussi une question de conformité, pas seulement de sécurité |
| Corps de requête complets et chaînes de requête complètes | Le moyen le plus simple de loguer tout ce qui précède d'un coup |
| Données personnelles au-delà de l'identifiant nécessaire | Un id, pas un nom plus un email plus une adresse |
| Données de santé, religieuses, politiques, biométriques | Catégories spéciales au sens du RGPD. Il n'existe aucune raison acceptable de les avoir dans un log |

Celle sur laquelle tu vas discuter : **l'identifiant utilisateur**. Il est réellement
utile pour le support et la gestion d'incident, et le `TokenProcessor` de Symfony l'ajoute
(généralement une adresse email) à chaque enregistrement. Logue l'id en base de données
quand tu le peux, accepte l'email quand tu dois, et sache dire combien de temps
les logs sont conservés.

## Le mécanisme : un processor de masquage

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

Mesuré sur Symfony 8.1 : avec

```php
$logger->error('signup failed for {email} with {password}', [
    'email' => 'reader@example.com',
    'password' => 'hunter2',
    'payload' => ['token' => 'abc123', 'title' => 'Dune'],
]);
```

l'enregistrement écrit était

```
app.ERROR: signup failed for reader@example.com with [redacted]
{"email":"reader@example.com","password":"[redacted]","payload":{"token":"[redacted]","title":"Dune"}}
```

Deux choses que ce résultat prouve :

- **L'imbrication fonctionne.** `payload.token` a été masqué, donc un DTO ou un tableau de
  formulaire entier déversé dans le contexte est couvert.
- **Le message interpolé a été masqué aussi.** Un processor au niveau du logger s'exécute
  avant le `PsrLogMessageProcessor` au niveau du handler qui étend les `{placeholders}`,
  donc `{password}` est ressorti en `[redacted]`. `priority: -1000` ne fait qu'ordonner ce
  processor par rapport aux autres processors *du logger* — cela ne le pousse pas après
  les handlers.

`priority:` sur l'attribut nécessite Monolog 3.4 et monolog-bundle 3.11 ; en dessous,
utilise le tag YAML avec une clé `priority`, ou omets la priorité (l'ordre ne compte
que par rapport à tes propres autres processors).

### Ce qu'il ne peut pas faire

`$logger->error('login failed for '.$email.' / '.$password)` passe directement à travers.
Un processor voit des clés, pas de la prose. **La règle qui rend le masquage possible
est : la chaîne de message est une constante, et tout ce qui est variable va dans le
tableau de contexte.** C'est la même discipline que demande PSR-3, et c'est pour ça que
les `{placeholders}` existent.

## Où les secrets s'infiltrent sans que personne n'écrive d'appel de log

### Le logging SQL de Doctrine

`Doctrine\DBAL\Logging\Statement::execute()` logue, au niveau `debug` :

```
Executing statement: {sql} (parameters: {params}, types: {types})
```

`params` contient chaque valeur liée : le mot de passe haché à l'inscription, le token de
réinitialisation lors d'une recherche, l'email à chaque connexion. `doctrine.dbal.logging`
vaut `%kernel.debug%` par défaut, donc c'est **désactivé** en production — le risque est
l'après-midi où quelqu'un l'active pour traquer une requête lente et oublie de le
désactiver. À noter l'interaction : `fingers_crossed` à `level: debug` bufférise ces
enregistrements, donc la prochaine erreur déverse d'un coup les paramètres de cinquante
requêtes SQL dans le log.

Si tu dois profiler sur des données de production, fais-le avec un changement
explicite et limité dans le temps, et désactive-le dans la même session.

### Les messages d'exception

`ErrorListener` logue `Uncaught PHP Exception <class>: "<message>"`. Tout ce que tu
mets dans le message d'exception se retrouve dans le log — vérifié : une exception
construite avec `sprintf('No book #%d in the library', $bookId)` apparaît en entier. Les
id ne posent pas de problème. Ne construis pas de messages d'exception à partir de la
valeur qui a échoué à la validation quand cette valeur est un mot de passe, un token ou un
numéro de carte.

### Les URLs

`WebProcessor` enregistre l'URI de la requête, chaîne de requête incluse. Toute
conception qui met un token dans une URL — liens de réinitialisation de mot de passe,
URLs signées, liens de connexion magiques — logue ce token à chaque requête. Préfère les
corps de requête POST ; là où un token en URL est inévitable, restreins le
`$extraFields` de `WebProcessor` et garde le token de courte durée et à usage unique.

### Les messages sérialisés dans la queue

Le transport Messenger Doctrine stocke l'enveloppe sérialisée dans
`messenger_messages.body`, et le logging DBAL écrit alors tout ce blob lors de son
insertion. Un message ne portant que des identifiants — ce que ce standard exige pour
d'autres raisons — est aussi la version qui ne met pas de données personnelles à deux
endroits.

### Les handlers tiers

Les handlers `slackwebhook` et `slack` prennent une option `exclude_fields`
(monolog-bundle 3.11+) listant les chemins pointés à retirer avant l'envoi, par exemple
`['context.exception']`. Utile, mais c'est un correctif par handler : le processor de
masquage est ce qui protège *chaque* destination à la fois.

## Le vérifier

Le contrôle est peu coûteux et mérite d'être automatisé :

```bash
php bin/console debug:container --tag=monolog.processor
```

Le masqueur doit y figurer, et ne doit avoir aucune valeur `channel` ou `handler` —
limité à un seul canal, il cesse silencieusement de couvrir les autres.

Vérifie-le ensuite dans un test comme n'importe quelle autre règle, avec un
`TestHandler` :

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

Vérifier également la *seconde* ligne est essentiel : cela fixe la décision délibérée que
l'identifiant survit, de sorte qu'un futur élargissement de `REDACTED_KEYS` qui
l'engloberait échoue au rouge au lieu de détruire silencieusement ta capacité à
enquêter sur quoi que ce soit.

## La rétention, brièvement

L'endroit où vivent les logs sort du périmètre de ce skill, mais une question appartient
à quiconque écrit l'appel de log : **combien de temps cette ligne survit-elle ?** Si la
réponse est « pour toujours, dans un bucket S3 dont personne n'est propriétaire », alors
l'identifiant que tu étais à l'aise de loguer pendant une semaine devient une décision
différente. Pose la question avant d'ajouter un champ, pas après un audit.
