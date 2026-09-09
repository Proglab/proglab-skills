# Symfony Skills

Un ensemble de skills d'agent opinionés pour écrire des applications Symfony.

La plupart des agents de codage connaissent Symfony. Ce qu'ils ne savent pas, c'est
*quel* Symfony tu veux : ils placeront volontiers un `QueryBuilder` dans un service, te
rendront une entité sérialisée directement en JSON, et écriront les tests après coup —
si tant est qu'ils les écrivent. Ces skills comblent cet écart en prenant les décisions
en amont, et en expliquant *pourquoi* chacune a été prise, pour que l'agent puisse
appliquer ce raisonnement aux cas que les skills n'ont jamais anticipés.

```bash
npx skills add Proglab/proglab-skills
```

Installer un seul skill à la place :

```bash
npx skills add https://github.com/Proglab/proglab-skills/tree/main/skills/symfony-proglab-testing
```

Ou en essayer un sans rien installer :

```bash
npx skills use Proglab/proglab-skills@symfony-proglab-architecture
```

## Les skills

Commencer par `symfony-proglab-standards`. Il est volontairement court : il détecte le
projet, énonce les cinq règles, trace la limite entre le socle et ce qu'un projet doit
mériter, et pointe vers le skill ci-dessous pertinent. Les autres tiennent seuls et se
déclenchent directement quand une tâche relève clairement de leur domaine, et chacun
indique sous son titre à quel niveau il appartient.

| Skill | Couvre |
|---|---|
| **symfony-proglab-standards** | Point d'entrée : détection du projet, les cinq règles, routage vers le reste |
| **symfony-proglab-architecture** | Contrat de couches, DTOs, services, événements, configuration, patterns |
| **symfony-proglab-testing** | Boucle TDD, unitaire vs intégration vs fonctionnel, fixtures, test doubles |
| **symfony-proglab-http** | Contrôleurs, routing, payloads de requête, réponses, formulaires, erreurs |
| **symfony-proglab-doctrine** | Entités, repositories, requêtes, relations, migrations |
| **symfony-proglab-security** | Authentification, autorisation, voters, CSRF, durcissement |
| **symfony-proglab-frontend** | AssetMapper, Stimulus, Turbo, Twig et Live Components, Tailwind |
| **symfony-proglab-ui** | symfony/ux-toolkit et le kit Shadcn : composants Twig prêts à l'emploi, installation, thème |
| **symfony-proglab-async** | Messenger, Scheduler, Mailer, retries et gestion des échecs |
| **symfony-proglab-console** | Familles de commandes et commandes invocables, opérations destructives, verrouillage |
| **symfony-proglab-performance** | Requêtes N+1, cache HTTP et applicatif, profiling |
| **symfony-proglab-quality** | PHPStan et php-cs-fixer comme socle, deptrac à la demande, la pipeline CI — avec une config prête à committer |
| **symfony-proglab-local-dev** | Services natifs de Laragon, serveur de dev de la CLI Symfony, Mailpit, vérifications de type production |
| **symfony-proglab-deployment** | Séquence de déploiement, migrations, réglages de production |
| **symfony-proglab-observability** | Canaux Monolog, identifiants de corrélation, ce qu'il ne faut jamais logger, alerting, health checks |
| **symfony-proglab-storage** | Où vont les fichiers uploadés, public vs protégé, les servir, les orphelins |
| **symfony-proglab-upgrade** | Dépréciations, dérive des recipes Flex, Rector, passage à une nouvelle version majeure |

## Ce que ces skills décident

Opinioné signifie que certaines portes sont fermées volontairement. Les principales,
pour que tu puisses juger d'un coup d'œil si cette suite correspond à ta façon de
travailler :

- **Le test d'abord, et le voir échouer.** Un test qui n'a jamais été rouge ne prouve
  rien. Quand le rouge d'abord est impossible, casser le code et vérifier que le test le
  remarque.
- **Rien d'autre que de la traduction dans un contrôleur.** Aucune règle métier, aucune
  persistance.
- **Aucun DQL, QueryBuilder ou SQL en dehors d'un repository.** Les requêtes portent le
  nom de l'intention, pas du mécanisme.
- **Des DTOs des deux côtés.** Une entité n'atteint jamais un template ni une réponse
  JSON.
- **AssetMapper, aucun bundler.** Ce qui veut dire que le front reste Twig, Stimulus et
  Symfony UX — pas de JSX, pas de composants monofichiers.
- **Messenger uniquement pour le travail asynchrone.** Pas comme bus de commandes
  in-process.
- **Deux niveaux.** Cinq règles socles qui suppriment des décisions et ne coûtent rien
  par fonctionnalité ; tout le reste — deptrac, tests de comptage de requêtes,
  Messenger, caches, health checks — s'active sur un besoin réel, jamais par
  anticipation. `symfony-proglab-standards` trace la limite.
- **Une API JSON écrite à la main, jusqu'à un certain point.** Passé une poignée de
  ressources — ou dès qu'il faut un contrat OpenAPI documenté — la suite recommande
  d'utiliser API Platform à la place, et ne le couvre pas.
- **MySQL et le serveur web natifs via Laragon, aucun conteneur.** La CLI Symfony
  ajoute HTTPS et HTTP/2 par-dessus ; un git worktree jetable tient lieu de conteneur
  pour vérifier les conditions de production avant de livrer.

Le raisonnement complet vit dans chaque skill ; `symfony-proglab-architecture` porte le
résumé de chaque rejet délibéré et pourquoi.

## Versions

Les skills ciblent Symfony moderne (6.4 à 8.x) sur PHP 8.2 ou plus récent. Ils ne
présument d'aucune version : chaque skill commence par lire `composer.lock` et s'adapte,
et chacun liste quoi écrire à la place quand un attribut ou un composant donné n'est pas
disponible.

`vendor/` est toujours traité comme la source de vérité au-dessus de tout ce qui est
écrit ici — c'est aussi la règle que les skills s'appliquent à eux-mêmes.

## Les contributions ne sont pas acceptées

Cette suite est ma propre vision opinionée de Symfony. Sa valeur vient du fait qu'elle
est cohérente et tranchée, pas d'être un consensus — et un standard assemblé par
comité cesse d'être un standard.

Donc : pas de pull requests, et les issues proposant des choix par défaut différents
seront fermées. Rien de personnel, et aucun jugement sur les alternatives — plusieurs
options rejetées sont parfaitement défendables, ce qui explique précisément pourquoi
elles sont documentées comme des rejets délibérés plutôt que des oublis.

Si tu n'es pas d'accord, **fork it**. C'est la bonne réponse ici : les skills sont du
Markdown brut, chaque décision est écrite avec son raisonnement, et en changer une est
une simple question d'édition de fichier. Tu obtiendras ta propre suite opinionée, qui
vaudra plus pour toi que la mienne.

Les rapports de bugs — une commande cassée, un mauvais namespace, une API qui n'existe
plus — sont les bienvenus en issues. Ce sont des faits, pas des opinions.

## Licence

MIT. Voir [LICENSE](LICENSE).
