# Splitting the thermobeacon repo into a collector repo and a home hub

Date: 2026-10-31. Status: draft for approval.

Background documents:

- `../../../thermobeacon/AGENTS.md` describes the sensors, the Android app,
  and the current server.
- `../../../thermobeacon/docs/2026-10-30-system-design.md` is the design of
  the current system.
- `../../../krpi.md` describes krpi, the Raspberry Pi that runs the server.

## Intent

The repo `tab3/thermobeacon` holds three things: the Android collector app,
the PHP server that receives the readings, and the dashboard. The user
realized that the dashboard is bigger than the thermobeacon sensors. It
will become a home dashboard that shows data from several sources. The
thermobeacon sensors are only the first source. The second known source
is locsoló, the user's irrigation controller (`/home/kiswap/Projects/locsolo`).

So the code is split into two git repos:

- `tab3/thermobeacon` keeps only the collector app, which is the source of
  the sensor data.
- `tab3/homeassistant` is the new hub. It holds the server core, the
  dashboard, and the plugins. Each data source is a plugin. The first
  plugin is the thermobeacon receiver, which is the current server code.

The name "homeassistant" is the user's choice. The code will not be
published, so the clash with the Home Assistant project does not matter.

## Goals

- Two repos. Each one starts with one fresh commit that contains only its
  own code. The user chose this, and it is fine that each repo has only
  one starting commit.
- The hub defines the plugin interface. Plugins live in the hub repo,
  under `plugins/<name>/`.
- After the split, krpi runs the hub instead of the old thermobeacon
  server. The dashboard looks and works the same as before.
- The readings already stored on krpi stay usable without a migration.
- The Android app code does not change. Only its server URL setting on
  the tablet changes.

## Non-goals

- No shared data model for all sources. The user does not know yet which
  sources will come, or how their data will arrive. Each plugin keeps its
  own data, or none.
- No scheduler for pulled data yet. The first pull source, locsoló, will be
  read at the moment the dashboard asks for it.
- No locsoló plugin in this work. It is the next project, after the split.
  This design only makes sure the interface can serve it.
- No new dashboard features.

## Anti-goals

- Losing stored readings, or the real `settings.json` with the API key.
- Deleting old commits or branches. They stay under backup names until
  the user says they can be removed.
- An interface so general that the thermobeacon plugin becomes harder to
  read than the current code.

## Constraints

These come from the current system and still apply:

- krpi is a Raspberry Pi Model B Rev 2 (armv6, 32-bit, 512 MB). PHP there
  is 32-bit, so integers above 2147483647 become floats.
- The server runs as `php -S` in the `php:8.5-cli-alpine` container. That
  server handles one request at a time.
- The dashboard must work in Chromium 90 (Fully Kiosk Browser on the
  tablet). Plain HTML, CSS, and ES modules, with no build step and no
  libraries.
- nginx on krpi serves the hub under a path prefix. So the dashboard must
  use relative paths.
- The PHP and JS code uses no libraries. Tests use the existing small test
  runner (`tests/run.php`) and `node --test`.

## Decisions made with the user

- Plugins live in the hub repo, because the hub defines the interface.
- Each plugin owns its storage, its API routes, and its widgets.
- The hub's name, on krpi and in the URL, is `homeassistant`.
- Both repos start with one fresh commit.
- Locsoló data will be pulled from the locsoló server, which keeps its own
  storage. The dashboard will redraw the locsoló graph with the hub's own
  chart code, from raw JSON data. That work comes after the split.
- Locsoló will later move to Docker behind the same nginx, the same way
  as the hub.

## The thermobeacon repo after the split

Contents:

- `android/`, unchanged.
- `AGENTS.md`, cut down to the sensors, the protocol, the app, and the
  build and adb notes. It points to `docs/upload-format.md` and to the
  hub repo for the server.
- `CLAUDE.md`, unchanged (`@AGENTS.md`).
- `docs/upload-format.md`, new. It describes what the app sends:
  `POST <server URL>/api/readings`, the `X-Api-Key` header, the JSON body
  `{"readings":[...]}` with its fields, and the answers the app expects
  (200 with counts, 401, 400, 413, and 503 when the server clock is
  behind). The text comes from the current `Api.php` and the old design
  doc.
- `docs/2026-10-30-system-design.md` and
  `docs/2026-10-30-implementation-plan.md` stay as history. Each gets one
  note at the top saying that the server and the dashboard moved to the
  `homeassistant` repo on 2026-10-31.

Git steps:

- Rename `main` to `backup/old-main` and
  `feature/collector-server-dashboard` to `backup/collector-server-dashboard`.
  Renaming keeps every commit.
- Create a new orphan branch `main` with one commit that contains the
  files above. The commit is built from the tree of
  `feature/collector-server-dashboard`, because the old `main` is an
  ancestor of that branch and has nothing the branch lacks. `server/` is
  removed from the index only (`git rm -r --cached server`), so its files
  stay on disk.
- `server/` is not in the new commit. Git-ignored files under `server/`
  stay on disk after the checkout, including the real
  `server/config/settings.json`. The builder copies that file to the hub
  and leaves the original in place. The user decides later whether to
  delete the leftovers.

## The hub repo

The hub is a new git repo created with `git init` in `tab3/homeassistant/`.
`tab3/` itself is not a repo. The hub has no remote. Its first commit
includes this document.

### Layout

```
homeassistant/
  AGENTS.md            hub notes for agents (the server parts of today's thermobeacon AGENTS.md)
  CLAUDE.md            @AGENTS.md
  DEPLOY.md            deployment to krpi (today's server/DEPLOY.md, renamed)
  docker-compose.yml
  .env.example
  package.json
  router.php
  core/                PHP core: Http.php, Db.php, Settings.php, Plugin.php, Hub.php
  public/              index.html, css/, js/ (main.js, api.js, bus.js, prefs.js, format.js, chart.js),
                       js/widgets/placeholder.js
  plugins/
    thermobeacon/
      src/             ThermobeaconPlugin.php, ReadingStore.php
      web/             widgets/sensor.js, widgets/graph.js, status.js
      tests/           PHP and JS tests of the plugin
  config/settings.example.json
  tests/               core tests, run.php, smoke.sh, js/
  tools/seed.php
  docs/specs/2026-10-31-hub-split-design.md   this document
```

`chart.js` and `format.js` stay in the core, because other plugins will
reuse them. The locsoló graph is the first planned reuse.

### PHP plugin interface

`core/Plugin.php` defines the interface. My proposal, which the builder
may adjust in details:

```php
interface Plugin
{
    /**
     * API routes of the plugin: path => handler. A handler is
     * fn(Request $req, int $now): Response.
     */
    public function routes(): array;

    /** Data the dashboard may see, sent as part of /api/config. */
    public function publicConfig(): array;
}
```

- The hub creates each plugin with its own settings section, for example
  `new ThermobeaconPlugin($settings['plugins']['thermobeacon'])`.
- Plugin routes use the prefix `/api/<plugin>/`, for example
  `/api/thermobeacon/sensors`.
- One exception: the thermobeacon plugin also answers `POST /api/readings`,
  because the app sends its readings there and the app does not change.
  The plugin also answers the same request at `/api/thermobeacon/readings`.
- The hub itself answers `GET /api/config`. The answer contains
  `refresh_seconds`, the `layout`, and one entry per plugin with its
  `publicConfig()`.
- A plugin that needs storage opens its own SQLite file with the core's
  `Db` helper. The thermobeacon plugin keeps using
  `/data/thermobeacon.sqlite` with the same tables, so the stored
  readings work without a migration.
- Two plugins must not register the same route. `php -S` has no startup
  step, because `router.php` runs for each request. So the hub checks on
  every API request, and a duplicate route gives a 500 answer and a log
  line that names both plugins. (My call.)
- The old dashboard routes `/api/sensors` and `/api/history` are not kept
  as aliases. The URL prefix changes at the cutover anyway, so the old
  page stops working at that moment in any case. (My call.)

Locsoló will fit this interface later: its plugin will register a route
such as `/api/locsolo/graph` that fetches JSON from the locsoló server and
passes it on. It needs no storage. If a future source needs a timer, the
interface gets a new method then. A timer is not built now.

### Loading plugins

- The `plugins` object in the settings file lists the enabled plugins.
  Each key is a plugin name, which is also the folder name under
  `plugins/`.
- `router.php` loads `core/*.php`, then `plugins/<name>/src/*.php` for
  each enabled plugin. The plugin class name follows the folder name:
  `thermobeacon` becomes `ThermobeaconPlugin`.
- `router.php` also serves the plugins' browser files. A request for
  `/plugins/<name>/<file>` returns `plugins/<name>/web/<file>`, but only
  for an enabled plugin and only for files inside its `web/` folder.
  Paths with `..` are refused. Everything else under `public/` is served
  as today.

### Settings file

The new shape (the real values come from today's `settings.json` on krpi):

```json
{
  "refresh_seconds": 60,
  "plugins": {
    "thermobeacon": {
      "api_key": "CHANGE_ME",
      "db_path": "/data/thermobeacon.sqlite",
      "stale_after_seconds": 600,
      "sensors": [
        {"id": "utazo",  "name": "utazó",  "icon": "room",    "address": "70:F7:00:00:12:DC"},
        {"id": "konyha", "name": "konyha", "icon": "kitchen", "address": "70:F7:00:00:14:63"},
        {"id": "kinti",  "name": "kinti",  "icon": "tree",    "address": "70:F7:00:00:12:3A"}
      ]
    }
  },
  "layout": {
    "columns": 4,
    "rows": ["1fr", "1.7fr"],
    "widgets": [
      {"type": "thermobeacon.sensor", "sensor": "utazo",  "col": 1, "row": 1},
      {"type": "thermobeacon.sensor", "sensor": "konyha", "col": 2, "row": 1},
      {"type": "thermobeacon.sensor", "sensor": "kinti",  "col": 3, "row": 1},
      {"type": "placeholder", "col": 4, "row": 1},
      {"type": "thermobeacon.graph", "sensors": ["konyha", "kinti"], "col": 1, "row": 2, "colSpan": 3},
      {"type": "placeholder", "col": 4, "row": 2}
    ]
  }
}
```

- A widget type is `<plugin>.<widget>`. A type without a dot, such as
  `placeholder`, belongs to the core.
- The hub does not read the old settings format. The builder cannot read
  krpi's file, and the local copy may differ from it. So the hub gets a
  small script, `tools/convert-settings.php`, that reads an old
  `settings.json` and prints the new shape. The user runs it on krpi
  against the old file, so the API key, sensors, and layout come from the
  file that is actually in use. Old keys that the server already ignores
  (`home_networks`, `basic_auth`) are dropped. The script has tests. (My
  call.)
- The real `settings.json` stays git-ignored, as today.

### Dashboard

- `main.js` reads `/api/config`. For each widget type `<plugin>.<widget>`
  it loads `plugins/<plugin>/widgets/<widget>.js` with a dynamic
  `import()`, which works in Chromium 90.
- The widget contract stays as today:
  `create(el, cfg, ctx)` returns `{ refresh(): Promise<void> }`.
  `ctx.config` becomes the plugin's own `publicConfig()`, so the
  thermobeacon widgets still find `sensors` and `stale_after_seconds`
  there. `ctx.prefs` and `ctx.bus` stay shared.
- Plugin widgets import core helpers with relative paths to the core,
  for example `../../../js/chart.js`. (My call. The builder may choose
  another way, but it must work under the `/homeassistant/` prefix.)
- The line at the bottom, "collector: 2 min ago", belongs to the
  thermobeacon plugin. A plugin may provide an optional `web/status.js`
  with a `status(el, ctx)` function. The core calls it after each
  refresh, so the footer shows each plugin's status. Today only
  thermobeacon has one.
- The graph range and the selected sensors are stored in `localStorage`
  as today. The stored keys may keep their current names, so the tablet
  keeps its choices.
- The page title changes from "ThermoBeacon" to "Home".

### Tests

- The existing PHP and JS tests move with the code. Core tests stay in
  `tests/`. Thermobeacon tests move to `plugins/thermobeacon/tests/`.
- `tests/run.php` runs the core tests and every plugin's tests.
  `npm test` runs every `*.test.js` under `tests/js/` and
  `plugins/*/tests/`.
- New core tests cover: loading plugins from the settings file, the
  duplicate-route error, `/api/config` with plugin entries, and serving a
  plugin's web file (including refusing `..`).
- The existing API tests keep their expectations, with the new paths. The
  test of `POST /api/readings` stays unchanged, because the app depends on
  that path.
- `smoke.sh` checks the same things as today against the new paths, plus
  that a plugin widget file is served.
- No test may be removed to make a suite pass.

## Moving krpi to the hub

`DEPLOY.md` in the hub describes these steps, and the user runs them:

1. Copy the hub repo to `~/homeassistant/` on krpi. Write
   `~/homeassistant/config/settings.json` with
   `tools/convert-settings.php` from `~/thermobeacon/config/settings.json`.
2. Write `~/homeassistant/.env` with the same `PORT` (8081 on krpi) and the
   same `DATA_DIR` as the old `~/thermobeacon/.env`.
3. In `~/thermobeacon/`, run `docker compose down`. Then in
   `~/homeassistant/`, run `docker compose up -d`. The compose service is
   named `homeassistant`.
4. In nginx, rename the `location /thermobeacon/` block to
   `location /homeassistant/`. Everything else in the block stays.
   Reload nginx.
5. On the tablet, set the app's server URL to
   `http://<krpi-ip>/homeassistant` and the Fully Kiosk start URL to
   `http://<krpi-ip>/homeassistant/`.
6. Check: the dashboard shows the old history, and new readings arrive
   within two minutes.

Steps 3 to 5 are one short window. During it the tablet cannot upload,
but the app keeps its readings in its queue for up to 7 days, so nothing
is lost. My assumption is that no other device or bookmark needs the old
`/thermobeacon/` URL after the cutover.

The old `~/thermobeacon/` folder on krpi stays until the user deletes it.
If something fails, step 3 can be reversed, and the old block restored
in nginx, to go back to the old server.

## Left to the builder

- Exact class and file names inside `core/`, as long as the layout above
  stays recognizable.
- How the router matches routes, and how the test runner finds plugin
  tests.
- The exact wording of `upload-format.md`, the two `AGENTS.md` files, and
  `DEPLOY.md`.
- Updating `tab3/AGENTS.md` (the device-level notes) to mention the
  `homeassistant/` folder.

## After this work

The next project is the locsoló plugin. It needs a JSON endpoint in the
locsoló repo, a hub plugin that pulls from it, and a step-line mode in
`chart.js`. Locsoló will move to Docker behind the same nginx first, so
the hub can reach it without the nginx password problem.
