#!/usr/bin/env bash
# Builds the hub's image for krpi (Raspberry Pi Model B, ARMv6) and saves it
# to dist/homeassistant-<tag>.tar.gz for copying to krpi. The tag is the git
# commit, with "-dirty" when there are uncommitted changes.
# Usage: bash tools/build-image.sh
set -euo pipefail

cd "$(dirname "$0")/.."
TAG="$(git rev-parse --short HEAD)"
if [[ -n "$(git status --porcelain)" ]]; then
  TAG="$TAG-dirty"
fi
OUT="dist/homeassistant-$TAG.tar.gz"

mkdir -p dist
docker buildx build --platform linux/arm/v6 --load -t "homeassistant:$TAG" .
# docker load reads gzip directly.
docker save "homeassistant:$TAG" | gzip > "$OUT"
echo "built $OUT ($(du -h "$OUT" | cut -f1))"
echo "on krpi: docker load -i homeassistant-$TAG.tar.gz, then set TAG=$TAG"
