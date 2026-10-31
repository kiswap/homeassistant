# Home hub

This repo is the home dashboard that the Galaxy Tab 3 shows on the wall
(see `../AGENTS.md` for the tablet). It runs on krpi, the user's Raspberry
Pi (see `../krpi.md`). The hub shows data from several sources. Each
source is a plugin.

- The first plugin, `thermobeacon`, receives the readings of three ORIA
  BLE sensors. The collector app on the tablet uploads them. That app is
  in `../thermobeacon/`, and its request format is in
  `../thermobeacon/docs/upload-format.md`.
- The `weather` plugin shows Budapest's weather. Its forecast widget
  takes the next 24 hours from Open-Meteo, in 3-hour blocks. Its radar
  widget shows HungaroMet's rain radar of the last hour, looped on an
  OpenStreetMap base map. The radar files are netCDF grids in a zip;
  `plugins/weather/src/RadarFile.php` reads them in plain PHP and cuts
  out the area around the place. Both answers are cached as files under
  `/data/weather`.
- The next planned plugin is `locsolo`, the user's irrigation controller
  (`/home/kiswap/Projects/locsolo`). It will pull its data from the
  locsoló server and store nothing.

The design of the split is in
`docs/specs/2026-10-31-hub-split-design.md`.

## Layout

- `router.php` is the router for PHP's built-in server. It answers
  `/api/...` through the hub, serves `/plugins/<name>/<file>` from
  `plugins/<name>/web/`, and leaves everything else to `public/`.
- `core/` is the PHP core: `Hub.php` (routing, `/api/config`, loading
  plugins, plugin files), `Plugin.php` (the interface), `Settings.php`,
  `Http.php`, and `Db.php` (opens SQLite with WAL).
- `public/` is the dashboard core: `index.html`, `css/`, and `js/`
  (`main.js` builds the grid; `chart.js` is the hand-written SVG chart;
  `format.js`, `api.js`, `bus.js`, and `widgets.js` are helpers).
- `plugins/<name>/` holds one plugin: `src/` (PHP), `web/` (browser
  files), and `tests/` (PHP tests, and JS tests in `tests/js/`).
- `config/settings.json` is the real settings file (git-ignored, holds the
  API key). `config/settings.example.json` is the committed template.
- `tools/seed.php` fills a database with fake thermobeacon history.
  `tools/convert-settings.php` converts a settings file from before the
  hub split (krpi no longer needs it; it is kept for an old backup).

## Plugins

- A plugin is the class `<Name>Plugin` (for `thermobeacon`:
  `ThermobeaconPlugin`) in `plugins/<name>/src/`. It implements `Plugin`:
  `routes()` returns exact paths mapped to `fn(Request, int $now): Response`,
  and `publicConfig()` returns what the dashboard may see.
- The hub creates each plugin listed under `plugins` in the settings file
  and passes it its own section.
- Plugin routes start with `/api/<name>/`. The one exception is
  `POST /api/readings`, which the thermobeacon app uses. Two plugins must
  not register the same path; the hub answers 500 and logs both names.
- A plugin keeps its own data. The thermobeacon plugin uses
  `/data/thermobeacon.sqlite` with the tables `readings` and `meta`.
  There is no shared data model yet, on purpose.
- Widgets: a layout widget of type `<plugin>.<widget>` loads
  `plugins/<plugin>/web/widgets/<widget>.js`. It exports
  `create(el, cfg, ctx)`, which returns `{ refresh(): Promise<void> }`.
  `ctx` is `{ config, layout, bus }`, where `config` is the plugin's
  `publicConfig()`. An unknown type shows "unknown widget: <type>".
- A plugin may have `web/status.js` exporting `status(el, ctx)`. Its text
  goes into the footer. The thermobeacon one shows "collector: … ago".
- Plugin browser files import core files with relative paths, such as
  `../../../js/chart.js`, and call relative API paths, such as
  `api/thermobeacon/sensors`.

## Rules that matter

- nginx on krpi serves the hub under `/homeassistant/`, so every browser
  path must be relative (`api/config`, not `/api/config`).
- The server has no password check. Its port is open on the home network
  but not forwarded by the router. nginx asks for the password outside
  `192.168.0.0/24`.
- krpi runs 32-bit PHP, so integers above 2147483647 become floats there.
  Code like `0xFFFFFFFF` breaks on krpi but works on the 64-bit build PC.
- `php -S` handles one request at a time, and it has no startup step:
  `router.php` runs for each request. The dashboard refreshes its widgets
  one after another.
- The dashboard must work in Chromium 90 (Fully Kiosk Browser). Plain
  HTML, CSS, and ES modules; no libraries and no build step.
- The container runs PHP with `display_errors=0`. Printed warnings would
  come before the headers and turn every answer into "200 OK".

## Tests and dev server

Run from this folder:

- PHP: `docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app php:8.5-cli-alpine php tests/run.php`
  (runs `tests/*Test.php` and `plugins/*/tests/*Test.php`).
- JS: `npm test` (Node 24).
- Smoke: `SMOKE_PORT=18090 bash tests/smoke.sh` starts the real container.
- Dev server with fake history, running the source folder (no image
  build): put a `settings.json` (with `db_path`
  `/data/thermobeacon.sqlite`) in `/tmp/ha-dev`, then
  `TAG=dev PORT=18082 DATA_DIR=/tmp/ha-dev SETTINGS_PATH=/data/settings.json docker compose -f docker-compose.yml -f docker-compose.dev.yml -p hadev up -d`
  and `docker compose -p hadev exec homeassistant php tools/seed.php /data/settings.json 3`.

## Image

- krpi runs the hub from an image; only `docker-compose.yml`, the
  environment file, and `config/settings.json` are files there.
  `docker-compose.yml` is that production file. `docker-compose.dev.yml`
  is an override that mounts the source instead.
- `Dockerfile` copies `router.php`, `core/`, `public/`, `plugins/`, and
  `tools/` onto `php:8.5-cli-alpine`. `.dockerignore` keeps tests,
  settings, data, and docs out. The Dockerfile must stay free of `RUN`
  steps: then the laptop builds the ARMv6 image without ARM emulation,
  which is not installed.
- `tools/build-image.sh` builds `homeassistant:<git hash>` and
  `homeassistant:latest` for `linux/arm/v6` and saves both tags, gzipped,
  to `dist/nobackup/` (git-ignored, about 40 MB). After `docker load` on
  krpi, the compose file runs `latest`. `TAG` in krpi's environment file
  is set only to go back to an older version.
- The smoke test builds the image for this PC and runs it with the
  production compose file, so it covers the Dockerfile too.

Deployment to krpi: `DEPLOY.md`.
