---
description: Implémente une story du socle ERP de bout en bout — spec, TDD, revue parallèle, porte de qualité, commit — avec les agents story-*.
argument-hint: <id de story, ex. 1-14> [ou une intention libre]
---

Tu orchestres l'implémentation de : **$ARGUMENTS**

C'est le remplaçant rapide de `bmad-build` : même format d'artefacts, mais une passe par
étape, et tout ce qui peut tourner en parallèle tourne en parallèle. Tu ne fais toi-même
que le routage, le triage et les décisions ; le travail part aux sous-agents.

Parle en français.

## 0. Préparer

- `git status` : arbre propre ? Sinon HALT et demande à Fabrice quoi faire du travail en
  cours.
- Branche : le travail d'une story vit sur `story/<id>-<slug>`, **jamais** sur `main`. Si
  tu es sur `main`, crée la branche avant toute modification. Si tu es sur la branche d'une
  autre story, HALT.
- Lis `_bmad-output/implementation-artifacts/sprint-status.yaml` et retiens la clé exacte
  de la story. Si elle est déjà `done`, HALT et dis-le.

## 1. Spec — agent `story-spec`

Lance `story-spec` avec l'identifiant ou l'intention, et attends-le.

S'il remonte des questions ouvertes, pose-les à Fabrice, numérotées, avec les options et
leurs conséquences. Écris chaque réponse comme une décision **dans** le bloc
`<frozen-after-approval>` du spec et supprime l'entrée correspondante.

### CHECKPOINT — le seul de la pipeline

Présente : le chemin du spec (cliquable), la route, les skills retenus, et ce que le spec
décide. Puis HALT sur trois choix :

- **Approuver et continuer** — passe le spec en `ready-for-dev` et enchaîne.
- **Approuver et s'arrêter** — passe en `ready-for-dev` et rends la main.
- **Revoir** — discute, corrige, reviens au checkpoint.

Relis le spec depuis le disque avant d'agir sur l'approbation : Fabrice a pu l'éditer.

## 2. Implémentation — agent `story-dev`

- Écris `baseline_commit` (HEAD complet) dans le frontmatter du spec s'il n'y est pas déjà
  — ne l'écrase jamais.
- Passe le spec en `status: in-progress`, et la story en `in-progress` dans
  `sprint-status.yaml` (l'epic parent passe de `backlog` à `in-progress` s'il y est
  encore). Mets `last_updated` à jour, préserve les commentaires du fichier.
- Lance `story-dev` avec le seul chemin du spec. Ne lui recopie pas le contexte : le spec
  le porte. Garde-le joignable pour la phase de correction.

## 3. Revue et porte — en parallèle

Écris le diff depuis `baseline_commit`, fichiers non suivis compris, dans un fichier du
répertoire temporaire de session, et retiens son chemin absolu.

Lance **dans un seul message**, donc en parallèle :

- `story-review` lentille `bugs` — avec `{diff_file}` ;
- `story-review` lentille `edge-cases` — avec `{diff_file}` et `{spec_file}` ;
- `story-review` lentille `verification-gap` — avec `{diff_file}` ;
- `story-review` lentille `standards` — avec `{diff_file}` (saute-la si le diff ne touche
  ni `src/`, ni `templates/`, ni `config/`) ;
- `story-qa` — la porte complète.

Attends-les tous avant de traiter un seul résultat. Juge sur le diff, pas sur le rapport de
`story-dev`.

## 4. Triage

Pour chaque constat, va vérifier à l'endroit cité avant de décider. Puis une seule issue :

- **patch** — causé par le changement, la plus petite correction est triviale et n'ajoute
  aucune surface publique → renvoie-le à `story-dev`, en une fois, tous les patchs
  ensemble.
- **bad_spec** — causé par le changement, mais le spec aurait dû l'empêcher → amende les
  sections non gelées, journalise dans `## Spec Change Log`, reviens à l'étape 2.
- **intent_gap** — la racine est dans le bloc gelé → HALT, seule Fabrice peut trancher.
- **defer** — préexistant, ou non causé par cette story → une entrée dans
  `deferred-work.md` (append-only), avec la preuve.
- **rejeté** — tu as vérifié, le mauvais résultat ne se produit pas → écris la réfutation.

Écris une ligne par constat dans `## Review Triage Log` du spec, verdict et preuve — aucun
constat ne disparaît en silence. Au-delà de 5 boucles, HALT.

Quand `story-qa` est rouge, la porte prime : rien ne se commite tant qu'elle n'est pas
verte. Relance-la après les patchs.

## 5. Clore

- Spec en `status: done`, story en `review` dans `sprint-status.yaml`.
- Commit local via le skill `git-commit` (gitmoji, scan de secrets). Jamais de push, jamais
  de PR sans demande explicite.
- Résumé en deux phrases : ce qui a changé, le verdict de la porte et de la revue, ce qui a
  été différé, le hash du commit. Ne liste pas les fichiers, ne raconte pas le processus.
