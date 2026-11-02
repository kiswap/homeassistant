#!/usr/bin/env bash
# Removes old hub images from krpi to free space on its SD card. It keeps
# the image that homeassistant:latest points to, the image the running
# container uses, and the newest KEEP other versions, so you can still go
# back to them with TAG=<old tag> (see DEPLOY.md). Then it removes dangling
# images (images with no tag). Layers that a kept image still uses stay, so
# the PHP base image is not removed.
#
# Usage: bash tools/clean-krpi.sh [--dry-run]
#   --dry-run  only lists what would be removed.
# KEEP (default 3) is how many older versions stay.
set -euo pipefail

DRY_RUN=0
if [[ "${1:-}" == "--dry-run" ]]; then
  DRY_RUN=1
elif [[ -n "${1:-}" ]]; then
  echo "usage: bash tools/clean-krpi.sh [--dry-run]" >&2
  exit 2
fi
KEEP="${KEEP:-3}"

# The script runs on krpi; KEEP and DRY_RUN are passed as arguments.
ssh krpi "bash -s -- $KEEP $DRY_RUN" <<'EOF'
set -euo pipefail
keep="$1"
dry_run="$2"

latest="$(sudo docker image inspect -f '{{.Id}}' homeassistant:latest 2>/dev/null || true)"
running="$(cd /var/www/html/homeassistant && sudo docker compose ps -q homeassistant \
  | xargs -r sudo docker inspect -f '{{.Image}}')"

# docker images lists the newest first. One image can carry several tags
# (homeassistant:latest and homeassistant:<commit>), so count image IDs,
# not tags.
seen=" "
kept=0
while read -r tag id; do
  [[ "$tag" == "latest" || "$tag" == "<none>" ]] && continue
  full="$(sudo docker image inspect -f '{{.Id}}' "homeassistant:$tag")"
  if [[ "$full" == "$latest" || "$full" == "$running" ]]; then
    echo "keep   homeassistant:$tag (in use)"
    continue
  fi
  if [[ "$seen" != *" $id "* ]]; then
    seen="$seen$id "
    kept=$((kept + 1))
  fi
  if (( kept <= keep )); then
    echo "keep   homeassistant:$tag"
  elif [[ "$dry_run" == 1 ]]; then
    echo "remove homeassistant:$tag (dry run)"
  else
    sudo docker rmi "homeassistant:$tag" > /dev/null
    echo "remove homeassistant:$tag"
  fi
done < <(sudo docker images homeassistant --format '{{.Tag}} {{.ID}}')

if [[ "$dry_run" == 1 ]]; then
  echo "dangling images to remove: $(sudo docker images -q --filter dangling=true | wc -l) (dry run)"
else
  sudo docker image prune -f
  df -h / | tail -1
fi
EOF
