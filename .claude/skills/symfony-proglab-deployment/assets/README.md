# Assets

Deux façons de garder les workers Messenger en fonctionnement. **Choisis-en une.**
Deux gestionnaires de processus supervisant la même commande produisent deux fois plus
de consommateurs et une file qui semble à moitié consommée pour chacun d'eux.

| Fichier | À installer comme | Pour |
|---|---|---|
| `messenger-worker@.service` | `/etc/systemd/system/messenger-worker@.service` | Le cas par défaut : l'hôte Apache/PHP-FPM ciblé par cette suite fait déjà tourner systemd |
| `supervisor-messenger.conf` | `/etc/supervisor/conf.d/messenger-worker.conf` | Un hôte sans systemd |

Les deux pointent vers `/var/www/app/current`, le symlink que Deployer bascule à chaque
déploiement — pas vers un répertoire `releases/<n>/` spécifique. C'est délibéré : voir
le commentaire dans l'unité systemd pour comprendre pourquoi pointer vers une release
fixe figerait silencieusement le worker sur le code qui était en ligne le jour où
l'unité a été écrite.

## Ce qu'il faut adapter dans les deux

| Valeur | Défaut ici | Comment bien la définir |
|---|---|---|
| Binaire PHP | `/usr/bin/php` | `which php` sur l'hôte cible |
| Répertoire du projet | `/var/www/app/current` | `deploy_path` dans `deploy.php`, plus `/current` |
| Utilisateur | `www-data` | L'utilisateur qui possède `var/`. Jamais root — un worker qui écrit des fichiers de cache en tant que root casse la prochaine requête web |
| Noms des transports | `async` | Les clés sous `framework.messenger.transports`, dans l'ordre de priorité. `messenger:consume async failed` viderait aussi la file d'échecs, ce qui n'est presque jamais ce que tu veux |
| `APP_ENV`, `APP_DEBUG` | `prod`, `0` | Nécessaires seulement pour les valeurs que `.env.local.php` ne porte pas déjà |
| Concurrence | 2 | Instances activées (systemd) ou `numprocs` (supervisor) |

## Les deux réglages qu'on se trompe le plus souvent

**Redémarrer sur une sortie propre.** `--time-limit` et `--memory-limit` font sortir le
worker avec le statut 0, délibérément, entre deux messages. systemd a besoin de
`Restart=always` et supervisor de `autorestart=true` — pas `on-failure`, pas
`unexpected`. Sans ça, le worker s'arrête au bout d'une heure et plus rien ne le
ramène ; la file grossit et le seul symptôme est la latence.

**Un délai d'arrêt plus long que le handler le plus lent.** `TimeoutStopSec` /
`stopwaitsecs` détermine combien de temps le gestionnaire attend après le SIGTERM avant
d'envoyer un SIGKILL. Un handler tué en plein traitement laisse son message ni acquitté
ni rejeté, et ce qui lui arrive ensuite est décidé par le visibility timeout du
transport plutôt que par toi. 120 secondes est un point de départ ; mesure ton handler
le plus lent.

## Où ça s'insère dans un déploiement

```bash
php bin/console messenger:stop-workers    # une fois le nouveau code en ligne
```

Ça écrit un timestamp dans le pool `cache.messenger.restart_workers_signal` — un enfant
de `cache.app`, ce pour quoi la règle de partage ci-dessous porte sur `cache.app`. Ça
n'envoie aucun signal à quelque processus que ce soit. Les workers lisent le timestamp
entre deux messages, terminent ce qu'ils détiennent, puis s'arrêtent — et les fichiers
d'unité ci-dessus les redémarrent sur le nouveau code. La commande d'arrêt et le
gestionnaire de processus sont les deux moitiés d'un seul mécanisme : sans le
gestionnaire, `messenger:stop-workers` ne fait qu'arrêter tes workers.

Le job de déploiement doit écrire dans le **même** backend `cache.app` que celui que
lisent les workers, et `cache.app` a besoin d'un `prefix_seed` fixe
(`../references/production-config.md`) — sans ça, ses clés sont dérivées de
`kernel.project_dir`, un nouveau chemin `releases/<n>/` à chaque déploiement, et le
signal est perdu silencieusement même sur un seul hôte. Sur plus d'un serveur
applicatif, un pool reposant sur le filesystem est propre à chaque hôte quel que soit
le seed ; pointe `cache.app` vers Redis, Valkey ou la base de données. Voir
`../references/deploy-sequence.md`.

## Commandes verrouillées et planifiées

Les tâches du scheduler (`#[AsCronTask]`, `#[AsPeriodicTask]`) ont elles aussi besoin
d'un worker en fonctionnement — la même unité avec le transport scheduler dans
`command`. Les commandes console longues ou périodiques ont besoin de `symfony/lock`
quel que soit le gestionnaire de processus : une tâche de six minutes lancée toutes les
cinq minutes s'empile jusqu'à ce que l'hôte meure. Cette règle appartient à
`symfony-proglab-console` et `symfony-proglab-async` ; elle est répétée ici parce qu'un
gestionnaire de processus est précisément ce qui transforme ce risque en panne.
