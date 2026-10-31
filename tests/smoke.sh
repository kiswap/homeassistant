#!/usr/bin/env bash
# Starts the real container on this machine and checks the HTTP API with curl.
# Usage: bash tests/smoke.sh   (from anywhere)
# Leaves its temp directory in /tmp for inspection.
set -euo pipefail

cd "$(dirname "$0")/.."
PORT="${SMOKE_PORT:-18080}"
TMP="$(mktemp -d /tmp/tb-smoke-XXXXXX)"
KEY="smoke-key"
BASE="http://127.0.0.1:$PORT"

# If another server already listens on the port, the test would silently
# talk to that one instead.
if curl -s -o /dev/null "$BASE/"; then
  echo "FAIL port $PORT is already in use; set SMOKE_PORT to a free port"
  exit 1
fi

python3 -I - "$TMP/settings.json" "$KEY" <<'EOF'
import json, sys
s = json.load(open("config/settings.example.json", encoding="utf-8"))
tb = s["plugins"]["thermobeacon"]
tb["api_key"] = sys.argv[2]
tb["db_path"] = "/data/thermobeacon.sqlite"
json.dump(s, open(sys.argv[1], "w", encoding="utf-8"), ensure_ascii=False)
EOF

export PORT DATA_DIR="$TMP" SETTINGS_PATH=/data/settings.json
compose() { docker compose -p hasmoke "$@"; }
compose up -d >/dev/null 2>&1
trap 'compose down >/dev/null 2>&1' EXIT

for _ in $(seq 1 30); do
  curl -s -o /dev/null "$BASE/api/config" && break
  sleep 0.5
done

fail=0
check() { # name expected actual
  if [[ "$3" == *"$2"* ]]; then echo "OK   $1"; else echo "FAIL $1: expected '$2' in '$3'"; fail=1; fi
}
NOW=$(date +%s)
BODY="{\"readings\":[{\"address\":\"70:F7:00:00:14:63\",\"ts\":$NOW,\"temperature_c\":22.5,\"humidity_pct\":63.9,\"voltage_mv\":2930,\"battery_pct\":93}]}"

check "config 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/config")"
check "post without key 401" "401" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "$BODY" "$BASE/api/readings")"
check "post with key stores" '"stored":1' "$(curl -s -X POST -H "X-Api-Key: $KEY" -d "$BODY" "$BASE/api/readings")"
check "sensor shows reading" "\"ts\":$NOW" "$(curl -s "$BASE/api/thermobeacon/sensors?id=konyha")"
check "index served" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")"
check "plugin js served" "application/javascript" "$(curl -sI "$BASE/plugins/thermobeacon/widgets/sensor.js")"
FUTURE="{\"readings\":[{\"address\":\"70:F7:00:00:14:63\",\"ts\":$((NOW + 3600)),\"temperature_c\":22.5,\"humidity_pct\":63.9,\"voltage_mv\":2930,\"battery_pct\":93}]}"
check "future reading 503" "503" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Api-Key: $KEY" -d "$FUTURE" "$BASE/api/readings")"
sleep 0.5
check "error_log reaches docker logs" "reading from the future" "$(compose logs 2>&1)"
check "db on data dir" "thermobeacon.sqlite" "$(ls "$TMP")"
LAN_IP="$(hostname -I | awk '{print $1}')"
check "published on $LAN_IP" "200" "$(curl -s -m 3 -o /dev/null -w '%{http_code}' "http://$LAN_IP:$PORT/" || true)"

exit $fail
