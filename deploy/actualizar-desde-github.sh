#!/usr/bin/env bash
# Ejecutar en el clon de producción con un usuario que tenga acceso a Git y Docker.
set -euo pipefail
cd "$(dirname "$0")/.."
metadata=$(git rev-parse --absolute-git-dir)
exec 9>"$metadata/barberia-deploy.lock"
flock -n 9 || exit 0

branch=$(git symbolic-ref --short HEAD)
git fetch --quiet origin "refs/heads/$branch"
target=$(git rev-parse FETCH_HEAD)
if [[ -n $(git status --porcelain --untracked-files=normal) ]]; then
  echo "Hay cambios locales en el VPS. Revísalos antes de actualizar." >&2
  exit 1
fi
if ! git merge-base --is-ancestor HEAD "$target"; then
  echo "La rama del VPS y la de GitHub tienen historiales distintos. Revisa el clon." >&2
  exit 1
fi
marker="$metadata/barberia-deployed"
if [[ $(git rev-parse HEAD) == "$target" && -f "$marker" && $(cat "$marker") == "$target" ]]; then
  exit 0
fi

git merge --ff-only "$target"
bash deploy/deploy.sh
printf '%s\n' "$target" > "$marker"
