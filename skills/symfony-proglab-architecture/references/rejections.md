# Rejets délibérés

Chaque alternative que ce standard a écartée, et pourquoi. Ce fichier existe parce que
toute la suite a besoin d'un seul endroit vers lequel pointer.

Un rejet qui n'est pas écrit se voit réintroduit par la personne suivante qui essaie
d'être utile — généralement avec un bon argument, parce que la plupart de ces
alternatives sont défendables. Plusieurs sont ce que d'autres équipes font, à raison,
dans leur contexte. La valeur ne tient pas à ce que ces choix soient objectivement
justes ; elle tient à ce qu'ils soient *tranchés*, pour que personne ne les remette en
procès dans une pull request à 18h.

**Comment s'en servir.** Si quelqu'un propose l'une de ces alternatives, n'argumentez
pas depuis l'autorité : nommez le rejet, donnez la raison ci-dessous, et dites ce que ce
standard fait à la place. Si la raison ne s'applique vraiment pas au projet en cours,
c'est un fork, pas un bug — voir le README de la suite.

**Ce fichier est un index, pas la source.** Chaque section ci-dessous reformule une
décision qui appartient à un autre skill — les lignes de Testing à
`symfony-proglab-testing`, les lignes de HTTP à `symfony-proglab-http`, et ainsi de
suite. Quand une ligne et le skill qui la porte se contredisent, le skill qui la porte
a raison, et c'est ce fichier qu'il faut corriger.

## Les cinq qui portent le reste

La majeure partie du tableau ci-dessous est du détail. Cinq rejets engendrent la forme
de tout le standard, et si un projet n'en garde que ceux-là, il le suit encore de
manière reconnaissable :

1. **Pas d'événements de domaine pour les conséquences métier.** Les conséquences sont
   des appels directs, si bien qu'un cas d'usage se lit dans une seule méthode et se
   teste avec une seule assertion.
2. **Pas de règles sur les entités.** Une règle sans exception est facile à tenir, et
   les entités au format maker gardent tous les outils fonctionnels ; les règles vivent
   donc dans les services — ce qui explique aussi pourquoi les tests pointent vers les
   services. Le prix est énoncé, pas caché : la garantie repose sur le fait que chaque
   écriture passe par le service.
3. **Pas de requêtes en dehors des repositories.** Nommées d'après l'intention, si bien
   que la même requête n'existe qu'une fois et ne peut être optimisée qu'une fois.
4. **Pas d'entités qui traversent la frontière du service.** Des DTO Output, si bien
   qu'un changement de mapping n'est pas un changement d'API.
5. **Pas de bundler.** AssetMapper, si bien que le front-end reste Twig, Stimulus et
   Symfony UX — avec le prix énoncé plutôt que découvert.

## Architecture

| Rejeté | À la place | Pourquoi |
|---|---|---|
| Des événements de domaine émis pour des conséquences métier | Le service appelle directement son collaborateur | « Que se passe-t-il quand X ? » devient une recherche à l'échelle du projet, et l'ordre dépend de priorités de listener invisibles |
| `EventSubscriberInterface` | `#[AsEventListener]` sur la classe | Une méthode statique `getSubscribedEvents()` à garder synchronisée, pour aucun gain |
| Des entités riches avec des méthodes façon `publish()` | Les règles dans les services, les entités au format maker | Simplicité et outillage : une règle sans exception, et `make:entity`, Form, les fixtures et EasyAdmin continuent de fonctionner. Le prix est que la garantie repose sur le passage par le service — bornée par le DTO Input à la frontière HTTP, et par `#[Assert]` sur l'entité là où une surface d'admin contourne les services |
| Les listeners d'événements kernel comme premier réflexe | Le mécanisme dédié : `#[WithHttpStatus]`, un ValueResolver, `#[Cache]`, un Voter | Un listener de kernel s'exécute sur tout, loin du code qu'il affecte, et se débogue mal |
| Le coffre de secrets Symfony par-dessus le magasin de secrets d'une plateforme de conteneurs | Le magasin de la plateforme seul, sous forme de variables d'environnement | La clé de déchiffrement du coffre serait elle-même un secret de plateforme : un second magasin alimenté par le premier. Le coffre n'est pas rejeté sur le fond — c'est le standard partout où il n'y a pas de magasin de plateforme ou où les secrets doivent être versionnés (`symfony-proglab-deployment`). Ce qui est rejeté purement et simplement, c'est `.env.local` sur un hôte de production |
| L'injection par setter `#[Required]` | L'injection par constructeur | Donne à une dépendance une apparence optionnelle et rend le service mutable |
| `ServiceSubscriberInterface` / `#[SubscribedService]` | `#[AutowireLocator]` | Même paresse, dépendance visible dans le constructeur, pas de méthode statique |
| `#[AutowireInline]` | Une définition de service normale | Un service défini à l'intérieur de l'attribut d'une autre classe est introuvable |
| Un seul DTO pour l'entrée et la sortie | `src/Dto/Input/`, `Read/`, `Output/` | La classe fusionnée accumule `#[Groups]` et propriétés nullables jusqu'à ne plus décrire ni l'un ni l'autre côté |

## Testing

| Rejeté | À la place | Pourquoi |
|---|---|---|
| `beginTransaction()` / `rollBack()` manuels dans `setUp()` / `tearDown()` | `dama/doctrine-test-bundle` | **Celui-ci est un revirement.** Une transaction ouverte dans `setUp()` meurt à la deuxième requête d'un `WebTestCase` : le kernel redémarre, la connexion est recréée, `rollBack()` lève `NoActiveTransaction` — et les lignes restent commitées. DAMA s'accroche au niveau du driver depuis une extension PHPUnit, en dehors du cycle de vie du kernel, donc ça ne lui arrive pas (`symfony-proglab-testing`) |
| Un objectif de couverture chiffré | Une couverture comportementale : nominal, limites, erreurs, sécurité, effets de bord | Un pourcentage se satisfait en testant des getters |
| `findAll()[0]` dans un test utilisant des fixtures | `getReference()` avec des références nommées, et des groupes de fixtures | L'ordre n'est pas un contrat ; le test casse dès qu'une fixture sans rapport est ajoutée |
| `$container->set()` pour remplacer ses propres services | Tester la règle unitairement à la place | Mocker son propre service dans un test fonctionnel signifie que la règle est dans la mauvaise couche |
| Des mocks écrits à la main pour les frontières du framework | Des doubles natifs : mailer en mémoire, `MockHttpClient`, transport Messenger en mémoire, `MockClock` | Ils vérifient un comportement réel et survivent aux montées de version du framework |
| Les annotations docblock de PHPUnit | Les attributs : `#[Test]`, `#[DataProvider]`, `#[CoversClass]` | Les annotations sont dépréciées ; les attributs sont vérifiés par le parseur |
| `createMock()` partout | `createStub()` sauf s'il y a un `expects()` | Depuis PHPUnit 11, un mock sans attente émet une notice *PHPUnit*, et `failOnPhpunitNotice="true"` fait passer la suite au rouge — le `failOnNotice` de la recette ne couvre que les notices PHP et ne l'attrape pas (`symfony-proglab-testing`) |

## HTTP

| Rejeté | À la place | Pourquoi |
|---|---|---|
| Une classe de contrôleur invocable par action | Une classe de contrôleur par ressource, préfixe de route au niveau de la classe | Cinq fichiers qui partagent un préfixe et un service, pour aucun gain d'isolation |
| Une entité dans une réponse JSON ou un template | Un DTO Output | La charge utile suit silencieusement tout ce que Doctrine a hydraté |
| Un tableau nu avec la pagination dans les en-têtes HTTP | Une enveloppe `items` + `meta` | Les en-têtes sont invisibles dans la plupart des clients et se perdent à travers les proxys |
| La pagination par curseur | Page / perPage / total / pages | Son coût réel ne se rentabilise qu'à une échelle que ce standard ne suppose pas ; elle interdit « aller à la page 7 » |
| Pagerfanta | L'enveloppe ci-dessus, construite dans le service | Une dépendance pour exposer quatre entiers |
| `#[UniqueEntity]` sur l'entité | Sur le DTO Input avec `entityClass:` | Ce n'est pas l'entité que valide la frontière HTTP, donc là la contrainte ne se déclenche jamais. Ne l'ajouter aussi sur l'entité que lorsqu'une surface d'admin modifie ce champ (`symfony-proglab-doctrine`) |
| Des listeners d'exception personnalisés pour les erreurs d'API | `#[WithHttpStatus]` plus les RFC 7807 Problem Details | Le statut a sa place à côté du sens de l'exception |
| API Platform, pour une petite API écrite à la main | Les DTO Input/Output, `#[Serialize]`, l'enveloppe ci-dessus | Contrôle total sur un contrat que l'on possède de bout en bout. **C'est une décision de périmètre, pas un jugement** — au-delà d'une poignée de ressources, ou dès qu'un contrat OpenAPI documenté est nécessaire, réorienter vers API Platform plutôt que de le réimplémenter. La suite ne le couvre pas (`symfony-proglab-http`) |

## Doctrine

| Rejeté | À la place | Pourquoi |
|---|---|---|
| UUIDv7 / ULID / un id interne plus un id public | Des identifiants entiers auto-incrémentés | Deux identifiants à garder cohérents, des index plus larges, et aucune exigence ici qui les réclame |
| DQL, QueryBuilder ou SQL en dehors d'un repository | Des méthodes de repository nommées d'après l'intention de l'appelant | Sinon la même requête existe à quatre endroits et aucun d'eux ne peut être optimisé |
| Des repositories `final` | Les repositories restent non-`final` | Ils sont doublés dans les tests unitaires de service. Tout le reste est `final` |
| `flush()` à l'intérieur d'une méthode de repository | Le service flush, une fois par cas d'usage | La frontière de transaction doit être visible dans la méthode qui porte l'opération |
| `count($book->getRatings())` | Une méthode de comptage du repository | Hydrate toute la collection pour produire un seul entier |
| `schema:update` en production | Des migrations générées, relues et corrigées à la main | Le générateur ne connaît pas les données existantes et émet DROP/ADD là où un RENAME était nécessaire |
| `\DateTime` | `\DateTimeImmutable` | Une date mutable passée à deux services est un bug partagé |
| Des constantes de chaîne pour des ensembles de valeurs fermés | Des enums natifs avec `enumType:` | La base de données et PHP sont d'accord, et l'IDE connaît les cas |
| Les contraintes `#[Assert]` sur les entités comme règle générale | Les contraintes sur le DTO Input ; sur l'entité seulement pour une règle locale qui doit tenir sur une surface d'admin comme EasyAdmin, en relisant les mêmes constantes | Ce n'est pas l'entité que valide la frontière HTTP. Un admin qui écrit sans passer par un service est une surface de confiance, et une contrainte sur l'entité est le seul garde-fou qu'il applique |

## Sécurité

| Rejeté | À la place | Pourquoi |
|---|---|---|
| LexikJWTAuthenticationBundle | L'`access_token` natif de Symfony avec un `AccessTokenHandler` | Le framework le couvre ; un bundle ajoute une gestion de clés et une surface de montée de version |
| Partager la session entre l'API et l'application web | Un firewall stateless avec `Authorization: Bearer` | Couple deux cycles de vie et casse dès que l'API est appelée depuis ailleurs |
| Des rôles pour des décisions au niveau objet | Des rôles pour les zones, des Voters pour les objets | `ROLE_EDITOR` ne peut pas exprimer « l'auteur de cette critique » |
| `access_control` seul, ou `#[IsGranted]` seul | Les deux | `access_control` est le filet que personne n'oublie ; `#[IsGranted]` est la précision |

## Frontend

| Rejeté | À la place | Pourquoi |
|---|---|---|
| Webpack Encore, Vite, tout bundler | AssetMapper uniquement | Énoncé avec son prix : pas de JSX, pas de composants monofichiers, pas de transpilation avancée. Si un besoin exige vraiment une étape de build, il sort du périmètre |
| Tailwind via un build Node | `symfonycasts/tailwind-bundle` | Garde Node entièrement hors de la chaîne d'outils |
| Un Live Component pour une soumission de formulaire sans état | Un Turbo Stream renvoyé comme réponse au POST | Une classe de composant et un cycle de vie achetés pour un état qui n'a jamais existé. Une seule question tranche : y a-t-il quelque chose à retenir entre deux interactions ? |
| Un Turbo Stream là où l'interaction a un état à retenir — un filtre, une recherche live, un assistant en plusieurs étapes | Un Live Component | Le `LiveProp` sérialisé est ce qui porte cette valeur à travers l'aller-retour ; un stream laisse maintenir des ids et des fragments pour le simuler |
| Une entité, ou tout objet coûteux, dans un `LiveProp` | Un DTO, et une requête paginée | Chaque interaction remonte le composant et réhydrate la prop à travers l'ORM |
| Un `#[LiveAction]` sans `#[IsGranted]` | La même vérification que porterait une action de contrôleur | `ux_live_component` est une route publique ; cacher le bouton ne protège rien |
| Un `<script>` inline dans les templates | Des contrôleurs Stimulus | Introuvable, intestable, et bloqué par toute CSP digne de ce nom |
| Des règles métier en JavaScript | Des règles dans les services | Une règle imposée dans le navigateur est une règle non imposée |
| Une classe `AbstractExtension` de Twig | `Twig\Attribute\AsTwigFilter` et consorts (paquet `twig/twig`, pas un espace de noms Symfony) | Moins de cérémonie, et le filtre se trouve à côté du code qui l'utilise |

## Asynchrone

| Rejeté | À la place | Pourquoi |
|---|---|---|
| Redis ou AMQP comme transport Messenger | Le transport Doctrine, sur la base de données existante | Un service de moins à faire tourner, sauvegarder et surveiller. À revoir seulement sous charge mesurée |
| Messenger comme bus de commandes en process (façon CQRS) | Un contrôleur appelle directement un service | Tout envoyer via dispatch achète de l'indirection et perd la stack trace |
| Une entité à l'intérieur d'un message | Des identifiants ; le handler recharge | Une entité sérialisée est un instantané, et la base de données a évolué le temps que le handler s'exécute |
| Une entité Doctrine dans le contexte d'un `TemplatedEmail` | Des scalaires, ou rendre le corps avant l'envoi | Le contexte doit être sérialisable ; une entité y échoue au moment de la mise en file, pas au moment de l'écriture |
| Une entrée crontab | Scheduler (`#[AsCronTask]`, `#[AsPeriodicTask]`) | Versionné avec le code qu'il exécute. Il a besoin d'un worker en cours d'exécution — à préciser avant de l'installer |
| Une file d'échecs que personne ne surveille | Une alerte dès qu'un message atteint `failed` | Sinon la file devient un tampon de perte de données silencieux |

## Console

| Rejeté | À la place | Pourquoi |
|---|---|---|
| Une commande destructrice qui agit par défaut | Dry run par défaut, `--force` pour agir, comptes affichés avant et après | L'écart entre les deux comptes révèle les suppressions partielles silencieuses |
| Des commandes longues ou planifiées sans verrou | `symfony/lock` / `LockableTrait` | Une tâche de 6 minutes planifiée toutes les 5 minutes s'empile jusqu'à faire tomber le serveur |
| De la logique métier à l'intérieur d'une commande | Le service la porte ; la commande est une couche de traduction avec un smoke test | Même règle qu'un contrôleur |

## Performance

| Rejeté | À la place | Pourquoi |
|---|---|---|
| Le cache HTTP par défaut | `#[Cache]` uniquement là où une lenteur a été mesurée | Un cache ajouté trop tôt masque le vrai problème et produit des bugs de contenu périmé difficiles à diagnostiquer |
| `public: true` sur une réponse contenant des données personnelles | Jamais | C'est une fuite à travers le cache partagé |
| Un cache applicatif uniquement basé sur un TTL | `cache.app.taggable` avec invalidation par tag | Un TTL n'est qu'une estimation du moment où les données ont changé |
| Faire confiance à un endpoint de liste pour l'absence de N+1 | Un nombre de requêtes vérifié dans un test d'intégration | Une règle non testée est un vœu pieux |
| `DebugStack` pour compter les requêtes | Le collecteur `db` du profiler, ou `doctrine.debug_data_holder` directement | `DebugStack` a été supprimé dans DBAL 4. `DoctrineDataCollector::getQueryCount()` existe toujours et est la voie recommandée — voir `symfony-proglab-performance` |

## Qualité, déploiement, développement local

| Rejeté | À la place | Pourquoi |
|---|---|---|
| PHP_CodeSniffer en plus de php-cs-fixer | php-cs-fixer comme seul outil de style | Deux formateurs en désaccord se corrigent mutuellement en boucle |
| `local-php-security-checker` | `composer audit` | Archivé ; son propre dépôt pointe vers le remplaçant |
| `symfony check:security` | `composer audit` | Fonctionne, mais duplique une vérification intégrée à Composer depuis la 2.4 |
| `friendsofphp/php-cs-fixer` directement dans `require-dev` | `php-cs-fixer/shim` | La contrainte du vrai paquet sur `symfony/console` et consorts peut bloquer l'application le jour d'une montée de version Symfony ; le shim isole ses dépendances à la place (`symfony-proglab-quality`) |
| À la fois un Makefile et un `castor.php` | `castor.php`, et le Makefile seulement là où Castor ne peut vraiment pas être installé — l'un des deux, jamais les deux | Deux exécuteurs de tâches divergent, et plus personne ne sait lequel la CI exécute. Castor est le défaut parce que les tâches sont du vrai PHP ; le Makefile est un repli, pas une option équivalente (`symfony-proglab-quality`) |
| PHPStan niveau 5 en écrivant du code entièrement typé | Niveau max, avec les extensions Symfony et Doctrine | Payer le coût des annotations sans en récolter le bénéfice |
| Tester sur SQLite, livrer sur MySQL | Un service de base de données CI correspondant à la production | Les différences remontent alors en production plutôt qu'en CI |
| Les migrations rétrocompatibles comme contrainte dure | Les migrations exécutées automatiquement au déploiement | Une courte interruption coûte moins cher que de concevoir chaque changement de schéma pour deux versions de code à la fois. La seule conséquence à assumer : une release dont la migration supprime des données n'a pas de rollback, et `symfony-proglab-deployment` le dit avant le déploiement plutôt que de fractionner chaque changement |
| Le développement quotidien à l'intérieur de la vérification proche de la production | Un `git worktree` jetable, utilisé avant de livrer, puis supprimé | Pas de rechargement instantané et chaque changement implique de répéter `composer install --no-dev`, le même raisonnement que donnerait un conteneur — simplement sans conteneur (`symfony-proglab-local-dev`) |
| Docker pour les services locaux | MySQL et le serveur web tournant nativement via Laragon | Aucun daemon à maintenir, aucun conteneur à expliquer sur une nouvelle machine — la contrepartie est de perdre l'isolation de service par projet qu'un conteneur offre gratuitement, compensée par une base de données par projet et les hôtes virtuels automatiques `<dossier>.test` de Laragon |

## Quand un rejet cesse de s'appliquer

Certains de ces rejets sont contextuels, et prétendre le contraire transforme un
standard en dogme. Les déclencheurs honnêtes pour en revoir un :

| Rejet | À revoir quand |
|---|---|
| Le transport Doctrine de Messenger | La table de file devient un point de contention sous charge mesurée, ou un fan-out vers plusieurs consommateurs est nécessaire |
| Les identifiants auto-incrémentés | Les identifiants doivent être générés hors ligne ou par un client, ou la fuite du nombre de lignes est une vraie préoccupation |
| Pas de cache HTTP | La lenteur d'une page publique a été mesurée, et la réponse ne contient rien de personnel |
| La pagination Page/perPage | Une liste dépasse le point où `OFFSET` est bon marché, et les pages profondes sont réellement demandées |
| Pas de bundler | Le produit a réellement besoin d'un framework JS avec une étape de build — dans ce cas, dire que c'est hors périmètre pour cette suite plutôt que d'en glisser un en douce |
| Le magasin de la plateforme plutôt que le coffre | Il n'y a pas de magasin de plateforme (serveur nu, déploiement rsync), ou les secrets doivent être versionnés et relus dans le dépôt — alors le coffre, entièrement |

Trois qui n'ont pas de déclencheur, parce que la raison ne s'affaiblit pas avec
l'échelle : les règles sur les entités, les requêtes en dehors des repositories, et les
entités qui traversent la frontière du service. Ceux-là empirent à mesure qu'un projet
grandit, jamais l'inverse.

## Rejets qui sont en réalité des reports

`#[Required]`, `EventSubscriberInterface`, `#[SubscribedService]` et Pagerfanta
fonctionnent tous. Si un projet en utilise déjà un, laissez-le : migrer une
configuration qui fonctionne n'achète rien et coûte un diff que personne ne voulait
relire. La règle porte sur le code écrit à partir de maintenant.

La transaction de test manuelle est l'exception à cette exception. Ce n'est pas une
préférence qui a perdu — elle est cassée d'une façon invisible jusqu'à ce qu'elle coûte
un après-midi de débogage, donc un projet qui l'a devrait migrer plutôt que la garder.

L'exception est tout ce que le tableau marque comme archivé ou supprimé —
`local-php-security-checker`, `DebugStack`, `#[TaggedIterator]`, `#[MapDecorated]`, les
annotations docblock de PHPUnit. Ce ne sont pas des préférences ; le code est sur une
trajectoire de casse, et le signaler est un rapport de bug, pas une opinion.
