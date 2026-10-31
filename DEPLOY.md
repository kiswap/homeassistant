# Deploying the home hub to krpi

These steps install the hub on krpi, the Raspberry Pi described in
`../krpi.md`. You run them by hand. If krpi still runs the old
thermobeacon server, follow "Moving from the old thermobeacon server"
instead of the fresh install.

## What you need

- This repo's files from the build PC.
- `config/settings.json`, the real settings file. It is not in git. It
  holds the API key of the thermobeacon collector app on the tablet.
  `config/settings.example.json` shows its shape.
- A folder on the USB stick for the data, for example
  `/mnt/usb/thermobeacon`.
- A free port on krpi. On krpi this is 8081.

## Fresh install

1. Copy the files from the build PC to krpi. Replace `pi` and the address
   with your SSH user and krpi's address:

   ```sh
   rsync -av --exclude data/ --exclude tests/ --exclude node_modules/ \
     --exclude .git/ --exclude .superpowers/ --exclude docs/ \
     ./ pi@<krpi-ip>:~/homeassistant/
   ```

   `rsync` copies `config/settings.json` too. If you use `scp -r` instead,
   check that `config/settings.json` arrived.

2. On krpi, create the data folder: `sudo mkdir -p /mnt/usb/thermobeacon`.

3. On krpi, in `~/homeassistant/`, copy `.env.example` to `.env` and set
   the two values:

   ```sh
   PORT=8081
   DATA_DIR=/mnt/usb/thermobeacon
   ```

   The hub is published on all of krpi's addresses. The home network can
   use `http://<krpi-ip>:<PORT>/` directly, and nginx forwards to
   `127.0.0.1:<PORT>` (see "Access through nginx" below). The router must
   not forward `<PORT>`.

4. On krpi, in `~/homeassistant/`, run `docker compose up -d`. The service
   is named `homeassistant`. The container restarts by itself after a
   reboot.

5. Point the tablet at the hub:
   - In the ThermoBeacon app, set the server URL to
     `http://<krpi-ip>/homeassistant` (no slash at the end) and enter the
     API key from `settings.json` (`plugins.thermobeacon.api_key`). Tap
     "Save and upload now".
   - In Fully Kiosk Browser, set the start URL to
     `http://<krpi-ip>/homeassistant/`, with the slash at the end
     (Fully Kiosk settings → Web Content Settings → Start URL).

## Moving from the old thermobeacon server

The old server runs from `~/thermobeacon/` and nginx serves it under
`/thermobeacon/`. The hub uses the same port, the same data folder, and
the same database file, so the stored readings stay.

1. Copy the files to `~/homeassistant/` as in step 1 of the fresh install,
   but without a `config/settings.json`.
2. On krpi, write the hub's settings file from the old one:

   ```sh
   cd ~/homeassistant
   docker run --rm -v "$HOME:/h" -w /h/homeassistant php:8.5-cli-alpine \
     php tools/convert-settings.php /h/thermobeacon/config/settings.json \
     > config/settings.json
   ```

   The script keeps the API key, the sensors, and the layout. It drops
   `home_networks` and `basic_auth`, which nginx replaced.
3. Copy `~/thermobeacon/.env` to `~/homeassistant/.env`. The `PORT` and
   `DATA_DIR` values stay the same.
4. Switch the containers. The tablet cannot upload between this step and
   step 6, but the app keeps its readings in its queue for up to 7 days,
   so nothing is lost.

   ```sh
   cd ~/thermobeacon && docker compose down
   cd ~/homeassistant && docker compose up -d
   ```

5. In the nginx config, rename `location /thermobeacon/` to
   `location /homeassistant/`. Everything else in the block stays. Then
   run `sudo nginx -t && sudo systemctl reload nginx`.
6. On the tablet, set the app's server URL to
   `http://<krpi-ip>/homeassistant` and the Fully Kiosk start URL to
   `http://<krpi-ip>/homeassistant/`.
7. Check that the dashboard shows the old history, and that new readings
   arrive within two minutes (see the checks below).

To go back: in `~/homeassistant/` run `docker compose down`, in
`~/thermobeacon/` run `docker compose up -d`, restore the nginx block's
old name, and set the old URLs on the tablet. The old `~/thermobeacon/`
folder stays on krpi until you delete it.

## Checks to run and report back

Run these on krpi unless stated otherwise:

1. `docker compose ps` shows the `homeassistant` service as running.
2. `curl -s http://127.0.0.1:<PORT>/api/config` prints JSON that starts
   with `{"refresh_seconds":60`.
3. `ls -l /mnt/usb/thermobeacon` shows `thermobeacon.sqlite`.
4. Within two minutes after pointing the tablet at the hub, the tablet app
   shows "error: -" and a time after "uploaded:". Then
   `curl -s http://127.0.0.1:<PORT>/api/thermobeacon/sensors` shows a
   recent `ts` for all three sensors.
5. From a PC or phone on the home network,
   `http://<krpi-ip>/homeassistant/` opens the dashboard without asking
   for a password, and the footer shows "collector: … ago".

## Access through nginx: read this first

The hub itself asks for no password. Access from outside reaches it only
through the nginx reverse proxy on krpi, because the router forwards to
nginx and not to `<PORT>`. The tablet also goes through nginx. nginx's
config for it:

```nginx
location /homeassistant/ {
    proxy_pass http://127.0.0.1:<PORT>/;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    satisfy any;
    allow 192.168.0.0/24;
    auth_basic "Restricted Area";
    auth_basic_user_file /etc/nginx/.htpasswd;
    deny all;
}
```

- Requests from `192.168.0.0/24` need no password. Other requests need
  the nginx password.
- The tablet app cannot answer a password prompt. It works because the
  tablet is inside `192.168.0.0/24`. If that range changes, the tablet must
  stay inside it.
- The trailing slashes in `location /homeassistant/` and in `proxy_pass`
  matter: together they strip `/homeassistant` from the path. The
  dashboard uses relative paths, so it works under `/homeassistant/`.

The tablet sends its API key without HTTPS, but only inside the home
network. The key only allows writing readings. So someone on your Wi-Fi
could read the key and send fake readings; nothing else is exposed.

## Changing settings later

- The hub reads `config/settings.json` on every request, so a change takes
  effect at once.
- `refresh_seconds` and `layout` belong to the hub. Each plugin has its
  own section under `plugins`. The thermobeacon section holds the API
  key, the database path, and the sensor names and icons. A new API key
  must also be entered in the tablet app.
- To resize or move a widget, change its `col`, `row`, `colSpan`, or
  `rowSpan` in `layout.widgets`. `layout.columns` and `layout.rows` set the
  grid. A widget type is `<plugin>.<widget>`, for example
  `thermobeacon.graph`.

## Backup

The thermobeacon history is one file. Copy it while the hub runs with
SQLite's own backup command, so the copy is consistent:

```sh
docker compose exec homeassistant php -r '
  $db = new SQLite3("/data/thermobeacon.sqlite");
  $db->exec("VACUUM INTO \"/data/backup-" . date("Ymd") . ".sqlite\"");'
```

Then copy `backup-YYYYMMDD.sqlite` from the USB stick to another machine.

## Logs

`docker compose logs --tail 50` shows errors. The log is limited to two
files of 1 MB each, and the server writes no line per request, so the SD
card is not worn out.
