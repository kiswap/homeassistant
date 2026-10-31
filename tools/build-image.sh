#!/usr/bin/env bash
# Builds the hub's image for krpi (Raspberry Pi Model B, ARMv6), saves it
# to dist/nobackup/homeassistant-<tag>.tar.gz, copies it to krpi, loads it
# there, and restarts the hub in /var/www/html/homeassistant. The tag is
# the git commit, with "-dirty" when there are uncommitted changes. The file
# also carries the tag "latest", so after docker load on krpi the compose
# file runs this version without a change to the env file.
# Usage: bash tools/build-image.sh
set -euo pipefail

cd "$(dirname "$0")/.."
TAG="$(git rev-parse --short HEAD)"
if [[ -n "$(git status --porcelain)" ]]; then
  TAG="$TAG-dirty"
fi
OUT="dist/nobackup/homeassistant-$TAG.tar.gz"

mkdir -p dist/nobackup
docker buildx build --platform linux/arm/v6 --load -t "homeassistant:$TAG" -t homeassistant:latest .
# docker load reads gzip directly, and it restores both tags.
docker save "homeassistant:$TAG" homeassistant:latest | gzip > "$OUT"
echo "built $OUT ($(du -h "$OUT" | cut -f1))"
#echo "on krpi: docker load -i homeassistant-$TAG.tar.gz, then set TAG=$TAG"
echo "Copying to krpi..."
scp "$OUT" krpi:/tmp
echo "Loading in krpi..."
# Double quotes: $TAG is filled in here, not on krpi.
ssh krpi "sudo docker load -i /tmp/homeassistant-$TAG.tar.gz && rm /tmp/homeassistant-$TAG.tar.gz && cd /var/www/html/homeassistant && sudo docker compose up -d"

