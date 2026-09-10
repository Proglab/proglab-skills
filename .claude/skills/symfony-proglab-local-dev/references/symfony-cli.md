# La CLI Symfony sur une configuration sans Docker

## Sommaire

- [Ce qu'elle fait réellement ici](#ce-quelle-fait-réellement-ici)
- [Workers et processus de longue durée](#workers-et-processus-de-longue-durée)
- [Configuration par projet](#configuration-par-projet)
- [Choisir la version de PHP](#choisir-la-version-de-php)

## Ce qu'elle fait réellement ici

Sans conteneurs à inspecter, le rôle de la CLI se réduit à trois choses : servir
l'application en HTTPS avec HTTP/2, superviser des workers en arrière-plan, et afficher
les logs dans un seul flux. Elle **n'**injecte **ni** n'écrase aucune variable
d'environnement — `DATABASE_URL`, `MAILER_DSN` et tout le reste proviennent de `.env` /
`.env.local` / `.env.test` exactement comme ce serait le cas sans la CLI du tout.

```bash
symfony server:ca:install     # une fois par machine — installe une autorité de certification locale
symfony server:start -d       # HTTPS, HTTP/2
symfony server:log            # logs du serveur, de PHP et de l'application ensemble
symfony server:status         # ce qui tourne
symfony var:export --debug    # la valeur finale de chaque variable, et le fichier qui l'a définie
```

Comme rien n'est injecté, `symfony console` et `php bin/console` sont interchangeables
sur cette configuration — il n'existe pas de version du bug « la suite de tests a tapé
dans la base de dev » dont un projet basé sur Docker doit se prémunir, puisqu'il n'y a
pas de couche de service connecté susceptible d'écraser `--env`.

## Workers et processus de longue durée

La CLI supervise les processus en arrière-plan et conserve leurs logs dans le même flux
que le serveur :

```bash
symfony run -d --watch=config,src,templates \
    symfony console messenger:consume async

symfony server:log      # logs du serveur, de PHP, de l'application et des workers ensemble
symfony server:status   # ce qui tourne
```

`--watch` redémarre le worker quand les répertoires listés changent. Sans cette option,
un worker démarré avant ta modification continue de faire tourner l'ancien code, et tu
passes vingt minutes à te demander pourquoi le correctif n'a rien changé.

## Configuration par projet

`.symfony.local.yaml`, committé :

```yaml
http:
    document_root: public/
    passthru: index.php

workers:
    # Démarré automatiquement avec le serveur. Pratique sur un projet où rien ne
    # fonctionne tant que le worker messenger ne tourne pas — les nouveaux arrivants
    # l'ont gratuitement.
    messenger:
        cmd: ['symfony', 'console', 'messenger:consume', 'async', '-vv']
        watch: ['config', 'src']
```

## Choisir la version de PHP

La CLI peut gérer plusieurs versions de PHP indépendamment de ce que Laragon a dans le
`PATH` :

```bash
symfony local:php:list          # versions que la CLI connaît
echo "8.4" > .php-version        # committé — fixe la version du projet indépendamment de la machine hôte
symfony php -v                  # la version que la CLI utiliserait réellement ici
```

Utile le jour où le PHP de Laragon lui-même est mis à jour avant qu'un projet ne le
soit, ou quand deux projets sur la même machine ont besoin de versions de PHP
différentes en parallèle.
