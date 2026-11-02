#!/usr/bin/env bash
# Builds the hub's image for krpi (Raspberry Pi Model B, ARMv6) and deploys
# it there. The tag is the git commit, with "-dirty" when there are
# uncommitted changes.
#
# krpi gets the image from a Docker registry that runs on this PC only
# during the deploy. krpi reaches it through an SSH tunnel and pulls only
# the layers it does not have yet, so a code change sends about 100 kB
# instead of the whole PHP image. On krpi the image is tagged
# homeassistant:<tag> and homeassistant:latest, so the compose file runs
# this version without a change to the env file. Then the script restarts
# the hub in /var/www/html/homeassistant and runs tools/clean-krpi.sh,
# which removes old images from krpi.
#
# Usage: bash tools/build-image.sh [--save]
#   --save  also writes the whole image to
#           dist/nobackup/homeassistant-<tag>.tar.gz (for docker load).
# REGISTRY_PORT (default 5000) is the registry's port, on this PC and on
# krpi's side of the tunnel.
set -euo pipefail

cd "$(dirname "$0")/.."
SAVE=0
if [[ "${1:-}" == "--save" ]]; then
  SAVE=1
elif [[ -n "${1:-}" ]]; then
  echo "usage: bash tools/build-image.sh [--save]" >&2
  exit 2
fi

TAG="$(git rev-parse --short HEAD)"
if [[ -n "$(git status --porcelain)" ]]; then
  TAG="$TAG-dirty"
fi
PORT="${REGISTRY_PORT:-5000}"
REMOTE_IMAGE="localhost:$PORT/homeassistant:$TAG"
REGISTRY=homeassistant-registry

docker buildx build --platform linux/arm/v6 --load -t "homeassistant:$TAG" -t homeassistant:latest .

if [[ "$SAVE" == 1 ]]; then
  OUT="dist/nobackup/homeassistant-$TAG.tar.gz"
  mkdir -p dist/nobackup
  # docker load reads gzip directly, and it restores both tags.
  docker save "homeassistant:$TAG" homeassistant:latest | gzip > "$OUT"
  echo "saved $OUT ($(du -h "$OUT" | cut -f1))"
fi

# The registry keeps its layers in a named volume, so the next deploy does
# not push the base image to it again. --rm removes only the container.
stop_registry() {
  docker stop "$REGISTRY" > /dev/null 2>&1 || true
}
trap stop_registry EXIT
stop_registry
docker run -d --rm --name "$REGISTRY" -p "127.0.0.1:$PORT:5000" \
  -v homeassistant-registry:/var/lib/registry registry:2 > /dev/null
for _ in $(seq 1 30); do
  if curl -fs "http://127.0.0.1:$PORT/v2/" > /dev/null; then
    break
  fi
  sleep 0.5
done

docker tag "homeassistant:$TAG" "$REMOTE_IMAGE"
docker push -q "$REMOTE_IMAGE"
docker rmi "$REMOTE_IMAGE" > /dev/null

echo "Pulling on krpi..."
# -R makes localhost:$PORT on krpi reach the registry on this PC while the
# command runs. Docker accepts a registry on localhost without HTTPS.
# Double quotes: the variables are filled in here, not on krpi.
ssh -o ExitOnForwardFailure=yes -R "$PORT:127.0.0.1:$PORT" krpi "
  set -e
  sudo docker pull -q $REMOTE_IMAGE
  sudo docker tag $REMOTE_IMAGE homeassistant:$TAG
  sudo docker tag $REMOTE_IMAGE homeassistant:latest
  sudo docker rmi $REMOTE_IMAGE > /dev/null
  cd /var/www/html/homeassistant && sudo docker compose up -d
"
echo "deployed homeassistant:$TAG"

echo "Removing old images on krpi..."
bash tools/clean-krpi.sh
