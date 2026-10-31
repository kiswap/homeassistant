# Deploying the ThermoBeacon server to krpi

These steps install the server on krpi, the Raspberry Pi described in
`../../krpi.md`. You run them by hand. At the end there is a list of checks;
please report their results back.

## What you need

- This `server/` folder from the build PC.
- `server/config/settings.json`, the real settings file. It is not in git.
  It holds the API key for the tablet. It was generated on the build PC
  with a random value.
- A folder on the USB stick for the database, for example
  `/mnt/usb/thermobeacon`. The exact mount path on krpi is not known yet.
- A free port on krpi, for example 8080.

## 1. Copy the files

From the build PC, copy the folder to krpi. Replace `pi` and the address
with your SSH user and krpi's address:

```sh
rsync -av --exclude data/ --exclude tests/ --exclude node_modules/ \
  server/ pi@<krpi-ip>:~/thermobeacon/
```

`rsync` copies `config/settings.json` too. If you use `scp -r` instead,
check that `config/settings.json` arrived.

## 2. Create the data folder on the USB stick

On krpi:

```sh
sudo mkdir -p /mnt/usb/thermobeacon
```

## 3. Write the `.env` file

On krpi, in `~/thermobeacon/`, copy `.env.example` to `.env` and set the
two values:

```sh
PORT=8080
DATA_DIR=/mnt/usb/thermobeacon
```

Pick a port that no other service on krpi uses. `ss -ltn` lists the ports
that are already taken. The server is published on all of krpi's
addresses. The home network can use `http://<krpi-ip>:<PORT>/` directly,
and nginx forwards to `127.0.0.1:<PORT>` (see "Access through nginx"
below). The router must not forward `<PORT>`.

## 4. Start the server

On krpi, in `~/thermobeacon/`:

```sh
docker compose up -d
```

If krpi has the older standalone tool, the command is `docker-compose up -d`.
The first start downloads the `php:8.5-cli-alpine` image, which takes a few
minutes on the Pi. The container restarts by itself after a reboot.

## 5. Point the tablet at krpi

- In the ThermoBeacon app on the tablet, set the server URL to
  `http://<krpi-ip>/thermobeacon` (nginx's address, without a slash at
  the end) and enter the API key from `settings.json` (`api_key`). Tap
  "Save and upload now".
- In Fully Kiosk Browser, set the start URL to
  `http://<krpi-ip>/thermobeacon/`, with the slash at the end
  (Fully Kiosk settings → Web Content Settings → Start URL).

krpi should have a fixed IP address (a DHCP reservation on the router), so
these URLs keep working.

## 6. Checks to run and report back

Run these on krpi unless stated otherwise:

1. `docker compose ps` shows the `thermobeacon` service as running.
2. `docker compose exec thermobeacon php -m | grep -i sqlite` lists
   `pdo_sqlite`. (Done on 2026-10-31: the server runs on krpi.)
3. `curl -s http://127.0.0.1:<PORT>/api/config` prints JSON that starts with
   `{"refresh_seconds":60`.
4. `ls -l /mnt/usb/thermobeacon` shows `thermobeacon.sqlite` (it appears after
   the first request).
5. Within two minutes after step 5, the tablet app shows "error: -" and a
   time after "uploaded:". Then
   `curl -s http://127.0.0.1:<PORT>/api/sensors` shows a recent `ts` for all
   three sensors.
6. From a PC or phone on the home network (Wi-Fi),
   `http://<krpi-ip>/thermobeacon/` opens the dashboard without asking for
   a password.
7. Speed: on the dashboard, tap `1w` after a few days of data. Report how
   long the graph takes to appear.

## Access through nginx: read this first

The server itself asks for no password. Access from outside reaches it
only through the nginx reverse proxy on krpi, because the router forwards
to nginx and not to `<PORT>`. The tablet also goes through nginx. nginx's
config for it:

```nginx
location /thermobeacon/ {
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
- The trailing slashes in `location /thermobeacon/` and in `proxy_pass`
  matter: together they strip `/thermobeacon` from the path. The dashboard
  uses relative paths, so it works under `/thermobeacon/`.

The tablet sends its API key without HTTPS, but only inside the home
network. The key only allows writing readings. So someone on your Wi-Fi
could read the key and send fake readings; nothing else is exposed.

`settings.json` files from the first deploy still contain `home_networks`
and `basic_auth`. The server ignores both keys now, so the file does not
need to change.

## Changing settings later

- Sensor names, icons, the dashboard layout, and the API key are in
  `config/settings.json`. The server reads the file on every request,
  so a change takes effect at once. A new API key must also be entered in the
  tablet app.
- To resize or move a widget, change its `col`, `row`, `colSpan`, or
  `rowSpan` in `layout.widgets`. `layout.columns` and `layout.rows` set the
  grid.

## Backup

The whole history is one file. Copy it while the server runs with SQLite's
own backup command, so the copy is consistent:

```sh
docker compose exec thermobeacon php -r '
  $db = new SQLite3("/data/thermobeacon.sqlite");
  $db->exec("VACUUM INTO \"/data/backup-" . date("Ymd") . ".sqlite\"");'
```

Then copy `backup-YYYYMMDD.sqlite` from the USB stick to another machine.

## Logs

`docker compose logs --tail 50` shows errors. The log is limited to two
files of 1 MB each, and the server writes no line per request, so the SD
card is not worn out.
