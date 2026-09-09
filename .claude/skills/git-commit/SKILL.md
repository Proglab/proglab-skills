---
name: git-commit
description: >-
  Prépare et rédige un commit git avec un message préfixé par un gitmoji (🐛, ✨, 📝,
  ♻️, 🚚, 🌐...) plutôt qu'un préfixe texte façon Conventional Commits, et scanne les
  changements stagés à la recherche de secrets (clés API, tokens, fichiers .env,
  clés privées) avant de committer. Utilise ce skill à chaque fois que l'utilisateur
  demande de committer, de créer un commit, de "commit ça", ou que le travail en
  cours arrive à un point où un commit est naturel — même si l'utilisateur ne
  mentionne pas explicitement "gitmoji". Ne remplace pas les règles de sécurité git
  générales (ne jamais committer sans autorisation explicite, ne jamais --no-verify) :
  ce skill s'ajoute par-dessus, il ne les assouplit pas.
---

# Commit avec gitmoji

Deux choses que ce skill ajoute au commit git standard : un message préfixé par un
gitmoji au lieu d'un préfixe texte, et un scan des changements stagés pour repérer un
secret qui s'y serait glissé. Tout le reste — ne committer que sur demande explicite,
ne jamais utiliser `--no-verify`, préférer un nouveau commit à un `--amend`, ne jamais
committer un fichier suspect sans l'avoir vraiment inspecté — reste les règles
générales déjà en vigueur ; ce skill ne les remplace pas.

## La séquence

1. **`git status` et `git diff` (staged + unstaged)**, comme pour tout commit —
   c'est ce qui permet de choisir le bon gitmoji et d'écrire un message qui reflète
   ce qui a réellement changé, pas ce qu'on suppose avoir changé.
2. **Stage les fichiers pertinents explicitement** (jamais `git add -A`/`git add .`
   à l'aveugle).
3. **Lance le scan de secrets** sur ce qui est stagé :
   ```bash
   python "<répertoire-de-ce-skill>/scripts/check_secrets.py"
   ```
   S'il ne trouve rien, il l'affiche clairement et sort en code 0 — ne saute pas
   cette étape parce qu'elle "ne sert probablement à rien cette fois", c'est
   justement le genre de commit anodin où une clé traîne dans un fichier de config
   copié-collé.
4. **S'il y a des résultats**, ne les ignore pas et ne les contourne pas
   automatiquement. Montre-les à l'utilisateur. Un résultat n'est pas forcément un
   vrai secret — un exemple de DSN dans une doc, une valeur de test — mais c'est à
   l'utilisateur de le confirmer, pas au script ni à toi de trancher seul. Si c'est
   un vrai secret : ne committe pas ce fichier, propose de le retirer du staging ou
   de l'ajouter à `.gitignore`.
5. **Choisis le gitmoji** qui correspond le mieux à *l'intention* du changement (pas
   au type de fichier touché) dans le tableau ci-dessous, puis écris le message.

## Format du message

```
<emoji> <résumé court, à l'impératif, minuscule après l'emoji>

<paragraphe optionnel : le pourquoi, pas le quoi — le diff dit déjà le quoi>
```

Exemples :

```
🚚 rename the skills to symfony-proglab-*

Fork personnalisé : le préfixe yoandev n'a plus de sens hors du dépôt d'origine.
```

```
🐛 fix broken TOC anchor in components.md

L'ancre pointait vers l'ancienne forme du titre après la conversion tu/vous.
```

```
🌐 translate the skill suite to French
```

Un seul gitmoji en tête de ligne suffit — ne pas en empiler plusieurs pour un même
commit. Si un commit touche à la fois, disons, de la doc et un correctif de bug,
c'est un signal qu'il devrait probablement être scindé en deux commits plutôt que
de choisir entre deux emojis.

## Les gitmoji les plus utiles ici

| | Emoji | Quand |
|---|---|---|
| Fonctionnalité | ✨ | Nouveau skill, nouvelle capacité, nouveau fichier de contenu |
| Bug | 🐛 | Corriger quelque chose de cassé (lien mort, commande fausse, logique erronée) |
| Doc | 📝 | Ajouter ou mettre à jour de la documentation/du contenu explicatif |
| Refactor | ♻️ | Réorganiser sans changer le comportement |
| Renommage/déplacement | 🚚 | Renommer ou déplacer des fichiers, dossiers, routes |
| Suppression | 🔥 | Retirer du code ou des fichiers |
| i18n | 🌐 | Traduction, localisation |
| Config | 🔧 | Fichiers de configuration (CI, linters, `.env.example`...) |
| Dépendances | ➕ / ➖ / ⬆️ / ⬇️ | Ajouter, retirer, monter ou descendre une dépendance |
| Tests | ✅ | Ajouter ou corriger des tests |
| Sécurité | 🔒️ | Corriger une faille ; **jamais** pour un commit qui introduit un secret |
| CI | 👷 / 💚 | Ajouter/modifier la CI, ou la réparer après un échec |
| Style | 🎨 | Formatage, structure, sans changement fonctionnel |
| Typo | ✏️ | Faute de frappe |
| WIP | 🚧 | Travail volontairement incomplet (rare en pratique — un commit devrait normalement être fini) |

La liste complète (une soixantaine d'entrées, pour les cas plus rares — breaking
change, health check, accessibilité, feature flags...) : `references/gitmoji-full-list.md`.

## Le scan de secrets, en détail

`scripts/check_secrets.py` regarde deux choses sur ce qui est **stagé** (`git diff
--cached`), jamais sur tout le dépôt :

- des **noms de fichiers** conventionnellement sensibles (`.env`, `id_rsa`, `*.pem`,
  `credentials.json`...) — flagués qu'importe leur contenu ;
- des **lignes ajoutées** qui ressemblent à une clé AWS/Google, un token
  Slack/GitHub, une clé Stripe live, un en-tête de clé privée, un JWT, ou une
  affectation générique `api_key = "..."` / `token: "..."` avec une valeur qui n'a
  pas l'air d'un placeholder.

C'est une détection heuristique, pas une garantie. Elle peut rater un secret qui ne
ressemble à aucun de ces motifs, et elle peut signaler un faux positif (une valeur
d'exemple assez longue et aléatoire pour matcher). Dans les deux sens, la décision
finale revient à qui lit le diff, pas au script — ne jamais relancer avec
`--no-verify` ou committer en ignorant un résultat sans l'avoir vraiment regardé, et
ne jamais non plus traiter un run "clean" comme une preuve qu'il n'y a rien à
vérifier soi-même.
