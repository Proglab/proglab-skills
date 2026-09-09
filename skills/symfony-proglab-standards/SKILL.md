---
name: symfony-proglab-standards
description: >-
  Point d'entrée pour écrire du code dans un projet Symfony : détecte les versions et
  conventions du projet, énonce les cinq règles qui s'appliquent à tout, et route vers le
  skill spécialisé adapté à la tâche. Charger ce skill EN PREMIER dès qu'une demande
  touche à un codebase Symfony et que le skill spécialisé adéquat n'est pas évident — une
  fonctionnalité décrite en langage courant (« permettre de noter un livre », « faire un
  CRUD », « ajouter ça à l'admin »), une instruction vague (« refactore ça », « nettoie
  ça », « corrige ce bug », « termine ça »), une tâche touchant plusieurs domaines à la
  fois, ou le tout premier changement sur un projet inconnu. Le charger aussi avant de
  commencer quoi que ce soit en cas de doute sur l'applicabilité de
  symfony-proglab-architecture, symfony-proglab-http, symfony-proglab-doctrine, symfony-proglab-testing,
  symfony-proglab-security, symfony-proglab-frontend, symfony-proglab-async, symfony-proglab-console,
  symfony-proglab-performance, symfony-proglab-quality, symfony-proglab-local-dev, symfony-proglab-deployment,
  symfony-proglab-observability, symfony-proglab-storage ou symfony-proglab-upgrade.
  Quand la tâche relève clairement de l'un d'eux, charger directement celui-là.
---

# Standards Symfony — commencer ici

Volontairement court. Détecter le projet, appliquer les cinq règles, charger le skill qui
correspond au travail.

## Étape 0 — lire le projet avant d'y écrire

Ne jamais présumer des versions ou des conventions. Une fois par session :

```bash
php -v | head -1
php -r '$l=json_decode(file_get_contents("composer.lock"),true);
foreach(array_merge($l["packages"],$l["packages-dev"]??[]) as $p)
  if(preg_match("#^(symfony/(framework-bundle|serializer|form|security-bundle|object-mapper|messenger|scheduler|lock|rate-limiter|asset-mapper|ux-live-component)|doctrine/orm|doctrine/doctrine-fixtures-bundle|phpunit/phpunit|twig/twig)$#",$p["name"]))
    echo str_pad($p["name"],40)." ".$p["version"]."\n";'
ls src/ config/packages/
```

Ce que cela indique :

- **Versions de Symfony et de PHP** → quels attributs existent. Chaque skill précise quoi
  écrire à la place quand l'un d'eux n'est pas disponible ; la règle ne disparaît jamais,
  seul le moyen change.
- **Quels composants optionnels sont installés** → sur quoi s'appuyer sans avoir à
  demander. Tout ce qui manque est *proposé*, jamais installé en silence.
- **La structure existante de `src/`** → les conventions à suivre. Si le projet regroupe
  déjà le code par domaine, ou place les DTOs ailleurs, suivre cela. Les frontières entre
  couches ne sont pas négociables ; les noms de dossiers le sont.

**`vendor/` prime sur tout ce qui est écrit dans ces skills.** Lire la source d'un attribut
prend une seconde et n'est jamais faux ; la mémoire et les articles de blog le sont
souvent.

## Les cinq règles

Elles s'appliquent partout, quelle que soit la tâche.

**1. Écrire le test d'abord, et le voir échouer.** Un test qui n'a jamais été rouge peut
être vert pour la mauvaise raison — assertion inversée, mock trop permissif, jamais
exécuté du tout. Quand le rouge d'abord est impossible, casser le code et vérifier que le
test le remarque.

**2. Un contrôleur traduit, il ne décide pas.** HTTP en entrée, appel de service, réponse
en sortie. Aucune règle métier, aucune persistance.

**3. Aucun DQL, QueryBuilder ou SQL en dehors d'un repository.** Les requêtes portent le
nom de l'intention de l'appelant, pas du mécanisme. Un service qui construit une requête
ne peut pas être testé sans base de données.

**4. Des DTOs aux deux extrémités.** Les DTOs d'entrée portent la validation ; les DTOs de
sortie portent le contrat. Une entité n'atteint jamais un template ni une réponse JSON.

**5. Toute règle métier vit dans un service.** Les entités portent les données et le
mapping ; elles ne portent aucune règle. C'est la conséquence directe de l'usage
d'entités au format maker, et c'est voulu.

Si un changement paraît trop petit pour mériter un test, il reste couvert par la règle 1.
Si une règle semble inadaptée au cas présent, le dire et l'expliquer plutôt que de la
contourner en silence.

## Deux niveaux : le socle, et ce que le projet doit mériter

Les cinq règles ci-dessus constituent l'intégralité du **socle**. Elles suppriment des
décisions et n'ajoutent aucun artefact : un projet qui ne garde qu'elles suit déjà ce
standard. Tout le reste de la suite est **à la demande** — cela ajoute un fichier, un
outil ou un rituel par fonctionnalité, et cela ne s'active que sur un besoin réel, jamais
par anticipation. Chaque skill indique son niveau, juste sous son titre.

| Niveau | Ce qu'il contient | Activé par |
|---|---|---|
| **Socle** | Les cinq règles ; le contrat de couches ; les DTOs d'entrée et de sortie ; `dama/doctrine-test-bundle` ; PHPStan et php-cs-fixer ; la boucle de développement local | Rien — s'applique à tout projet Symfony que cette suite touche |
| **À la demande** | Tests de comptage de requêtes ; deptrac ; Messenger, Scheduler et files priorisées ; caches applicatif et HTTP ; identifiants de corrélation, health checks, sinks d'alerte ; URLs signées et stockage d'objets ; Live Components et Mercure | Une lenteur mesurée, un second worker, une vraie file, une vraie cible de déploiement, un vrai upload, un vrai second développeur |

Le test pour toute règle qu'on s'apprête à appliquer ou à ajouter : **supprime-t-elle une
décision, ou ajoute-t-elle un artefact ?** Le premier type est gratuit et il peut y en
avoir cent. Le second type se paie à chaque fonctionnalité, et il faut le déclencheur de
la colonne de droite pour qu'il en vaille la peine.

## Ce que les règles n'exigent pas

Les cinq règles sont lues strictement sur les frontières et avec largesse sur le
cérémonial. Aucun des points suivants ne contourne une règle ; chacun est la règle
appliquée avec discernement :

- **Un GET qui ne comporte que des paramètres de route n'a besoin d'aucun DTO d'entrée.**
  `#[MapQueryString]` sert pour une query string qui doit être validée, pas pour
  `show(int $id)`.
- **Une projection `SELECT NEW` qui correspond déjà au contrat *est* le DTO de sortie.**
  La couche Read existe pour le cas où la forme de la requête et la forme de l'API
  diffèrent ; quand ce n'est pas le cas, il y a une seule classe, dans `src/Dto/Output/`.
- **Un DTO de sortie par forme, pas par endpoint.** Une ligne de liste et une vue détail
  partagent la classe quand leurs champs sont identiques.
- **Une exception métier n'existe que pour un échec qu'un appelant peut réellement
  rencontrer.** Une entrée invalide est un 422 produit par les contraintes du DTO, pas
  une classe d'exception.
- **Une classe sans règle n'a droit à aucun test unitaire propre.** Un DTO sans
  normalisation, un contrôleur, une commande, un getter : le test fonctionnel ou de
  fumée qui prouve le câblage est toute la couverture dont ils ont besoin. La règle 1
  porte sur le comportement, pas sur les fichiers.
- **Un service de lecture peut porter toutes les lectures d'une ressource.**
  `ShelfReader::shelf()` et `ShelfReader::book()` dans une seule classe, c'est
  l'intention ; une classe par requête ne l'est pas.

## Où aller ensuite

| Le travail porte sur | Charger |
|---|---|
| Où va une classe, les couches, DTOs, services, DI, patterns, pourquoi une règle existe | `symfony-proglab-architecture` |
| Écrire ou corriger des tests, fixtures, mocks, la boucle TDD | `symfony-proglab-testing` |
| Contrôleurs, routes, payloads de requête, réponses JSON, formulaires, pages Twig | `symfony-proglab-http` |
| Entités, repositories, requêtes, relations, migrations | `symfony-proglab-doctrine` |
| Connexion, permissions, voters, CSRF, tokens, durcissement | `symfony-proglab-security` |
| JavaScript, CSS, Stimulus, Turbo, composants, AssetMapper | `symfony-proglab-frontend` |
| Traitement en arrière-plan, emails, files, jobs planifiés | `symfony-proglab-async` |
| Commandes console, imports, scripts de nettoyage | `symfony-proglab-console` |
| Quelque chose est lent, trop de requêtes, cache | `symfony-proglab-performance` |
| PHPStan, deptrac, style de code, pipeline CI | `symfony-proglab-quality` |
| Faire tourner le projet en local, services Laragon, serveur de dev | `symfony-proglab-local-dev` |
| Déployer en production, migrations au déploiement, réglages serveur | `symfony-proglab-deployment` |
| Logs, alerting, health checks, savoir ce que fait la production | `symfony-proglab-observability` |
| Fichiers uploadés : où ils vont, comment ils sont servis, orphelins | `symfony-proglab-storage` |
| Dépréciations, mise à jour des dépendances, passage à une nouvelle version majeure de Symfony | `symfony-proglab-upgrade` |

La plupart des tâches réelles touchent deux ou trois skills. Une demande de
fonctionnalité signifie typiquement `symfony-proglab-architecture` pour décider où vont
les pièces, `symfony-proglab-testing` pour commencer, puis celui qui correspond à la
surface construite. Les charger au fur et à mesure plutôt que tous d'un coup.

## Avant de rendre la main

- [ ] Les tests ont été écrits d'abord, vus rouges, et passent maintenant — et ils ont
      été exécutés.
- [ ] Le résultat de `vendor/bin/phpunit` est rapporté tel qu'il est réellement, échecs
      inclus.
- [ ] Aucune règle métier dans un contrôleur, aucune requête en dehors d'un repository,
      aucune entité qui franchit une frontière.
- [ ] Tout ce qu'il a fallu décider à la place de l'utilisateur est énoncé clairement,
      pas enfoui.
- [ ] Tout ce qui n'a pas pu être fait est nommé, plutôt que discrètement abandonné.

Les deux derniers points comptent autant que le code. Un changement qui fonctionne mais
cache une décision est un changement que quelqu'un devra rétro-ingénierer plus tard.
