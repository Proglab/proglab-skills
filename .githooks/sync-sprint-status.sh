#!/usr/bin/env bash
#
# Fait passer à `done` les stories dont le travail a atterri sur la branche principale.
#
# bmad-build possède déjà deux transitions du suivi : `in-progress` quand il commence à
# implémenter, `review` quand il présente. Il s'arrête là volontairement — « relu » n'est
# pas « livré », et le skill ne peut pas savoir si la branche a été mergée. `done` est
# donc la seule transition que personne ne possède, et elle dérivait à chaque story : la
# revue de la 1.3, de la 1.4 puis de la 1.5 a signalé le même décalage, rejeté trois fois
# comme n'appartenant pas à la relecture. Ce script en est le propriétaire manquant.
#
# Le déclencheur n'est pas le nom de la branche mergée — une convention de nommage se
# perd au premier squash-merge GitHub, qui réécrit le message de commit. Le déclencheur
# est le fichier de spec lui-même : bmad-build écrit `status: 'done'` dans son
# frontmatter en fin de course, et ce fichier n'entre dans l'arbre de la branche
# principale qu'une fois la branche mergée. « Spec à done » + « spec dans cet arbre » =
# livré, quel que soit le style de merge.
#
# « Dans cet arbre » se lit sur HEAD, jamais sur le disque. Un parcours du répertoire
# compterait aussi les fichiers non suivis et ceux qu'une autre branche a laissés dans la
# copie de travail : deux sessions partageant un même dossier suffisent alors à faire
# passer à « done » une story dont rien n'a été mergé. `git ls-tree` ne voit que ce qui
# est réellement committé ici.
#
# Le script ne recule jamais : une clé déjà à `done` n'est pas touchée, et aucune clé
# sans spec correspondante n'est modifiée. C'est la même règle monotone que
# bmad-build/sync-sprint-status.md.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

artifacts='_bmad-output/implementation-artifacts'
status_file="$artifacts/sprint-status.yaml"
epics_file='_bmad-output/planning-artifacts/epics.md'
planner='.claude/skills/bmad-sprint-planning/scripts/sprint_plan.py'

[ -f "$status_file" ] || exit 0
[ -f "$epics_file" ] || exit 0
git rev-parse --verify -q HEAD > /dev/null || exit 0

# `uv` porte les dépendances du script de planification (bloc PEP 723 en tête de
# sprint_plan.py). Absent, on le dit et on sort en 0 : un hook ne casse pas un merge.
if ! command -v uv > /dev/null 2>&1; then
  echo "sync-sprint-status : uv est absent, le suivi n'a pas été mis à jour."
  exit 0
fi

sets=()
names=()

while IFS= read -r spec; do
  # `spec-1-10-...` donne `1-10`, jamais `1-1` : le tiret final fait partie du motif, donc
  # aucun numéro n'est le préfixe d'un autre.
  num=$(printf '%s' "${spec##*/}" | sed -nE 's/^spec-([0-9]+)-([0-9]+)-.*/\1-\2/p')
  [ -n "$num" ] || continue

  spec_status=$(git show "HEAD:$spec" | sed -n '1,30p' | sed -nE "s/^status: *'?([a-z-]+)'?.*/\1/p" | head -1)
  [ "$spec_status" = 'done' ] || continue

  # La clé du suivi porte les accents que le nom de fichier a repliés en ASCII : on la
  # relit dans le fichier plutôt que de la reconstruire.
  key=$(sed -nE "s/^  ($num-[^:]*): *[a-z-]+ *$/\1/p" "$status_file" | head -1)
  [ -n "$key" ] || continue

  current=$(sed -nE "s/^  $key: *([a-z-]+) *$/\1/p" "$status_file" | head -1)
  [ "$current" != 'done' ] || continue

  sets+=(--set "$key=done")
  names+=("$key ($current -> done)")
done < <(git ls-tree --name-only -r HEAD -- "$artifacts" | grep -E '/spec-[0-9]+-[0-9]+-.*\.md$' || true)

if [ ${#sets[@]} -eq 0 ]; then
  exit 0
fi

uv run "$planner" generate \
  --epic-file "$epics_file" \
  --status-file "$status_file" \
  --stories-dir "$artifacts" \
  --project proglab-skills \
  --date "$(date +'%m-%d-%Y %H:%M')" \
  "${sets[@]}" > /dev/null

echo "sync-sprint-status : suivi mis à jour."
for name in "${names[@]}"; do
  echo "  $name"
done
echo "Le fichier est modifié mais non indexé — à committer avec le reste."
