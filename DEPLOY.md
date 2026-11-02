# Deploying the home hub to krpi

These steps install and update the hub on krpi, the Raspberry Pi
described in `../krpi.md`. You run them by hand.

The code reaches krpi only inside a Docker image. The build PC builds the
image for krpi's ARM CPU. krpi keeps only three things as files in
`/var/www/html/homeassistant/`: `docker-compose.yml`, the environment file
`.env`, and `config/settings.json`.

The commands below use `krpi` as the SSH host name (an alias in your SSH
config). On krpi, `docker` runs with `sudo`.

## What you need

- `config/settings.json`, the real settings file. It is not in git. It
  holds the API key of the thermobeacon collector app on the tablet.
  `config/settings.example.json` shows its shape.
- A folder on the USB stick for the data, for example
  `/mnt/usb/thermobeacon`.
- A free port on krpi. On krpi this is 8081.

## Building and deploying an image

Every install and update starts here, on the build PC, in this repo:

1. Commit your changes first, so the image tag names a real commit. (With
   uncommitted changes the tag ends in `-dirty`.)
2. Build and deploy the image:

   ```sh
   bash tools/build-image.sh
   ```

   `<tag>` is the commit, for example `4c97986`. The script builds
   `homeassistant:<tag>` for krpi. Then it starts a Docker registry on the
   build PC (the container `homeassistant-registry`, with its data in the
   volume `homeassistant-registry`) and pushes the image to it. krpi pulls
   the image through an SSH tunnel, so krpi downloads only the layers it
   does not have yet. A code change is about 100 kB; the PHP base image
   (about 40 MB) goes over only on the first deploy. On krpi the script
   tags the image `homeassistant:<tag>` and `homeassistant:latest` and runs
   `sudo docker compose up -d` in `/var/www/html/homeassistant`. Then it
   runs `tools/clean-krpi.sh` (see "Removing old images from krpi"). At
   the end it stops the registry, also when a step fails.

   The registry uses port 5000 on the build PC and on krpi. If that port is
   taken on either side, set another one: `REGISTRY_PORT=5055 bash
   tools/build-image.sh`.

3. To get the whole image as a file as well, run
   `bash tools/build-image.sh --save`. It also writes
   `dist/nobackup/homeassistant-<tag>.tar.gz` (about 40 MB). On krpi,
   `sudo docker load -i homeassistant-<tag>.tar.gz` loads it with both tags,
   without the registry.

## Removing old images from krpi

`tools/build-image.sh` runs this cleanup after every deploy. To run it by
hand, for example with another `KEEP`, run on the build PC:

```sh
bash tools/clean-krpi.sh --dry-run   # only lists what it would remove
bash tools/clean-krpi.sh
```

It keeps the image that `latest` points to, the image the running
container uses, and the three newest other versions, so `TAG=<old tag>`
still works for them. `KEEP=5 bash tools/clean-krpi.sh` keeps five. Then it
removes all dangling images (images with no tag) on krpi, also those of
other projects. The PHP base image stays, because the kept images use it.

## Fresh install

1. Build and deploy an image as above. The script's last step,
   `docker compose up`, fails until steps 2 to 4 are done; run it by hand
   in step 5.
2. On krpi, create the folders:
   `mkdir -p /var/www/html/homeassistant/config` and
   `sudo mkdir -p /mnt/usb/thermobeacon`.
3. From the build PC, copy the compose file, the env template, and the
   settings file:

   ```sh
   scp docker-compose.yml .env.example krpi:/var/www/html/homeassistant/
   scp config/settings.json krpi:/var/www/html/homeassistant/config/
   ```

4. On krpi, in `/var/www/html/homeassistant/`, copy `.env.example` to
   `.env` and set the values:

   ```sh
   PORT=8081
   DATA_DIR=/mnt/usb/thermobeacon
   ```

   Leave `TAG` unset, so the hub runs `homeassistant:latest`.

   The hub is published on all of krpi's addresses. The home network can
   use `http://<krpi-ip>:<PORT>/` directly, and nginx forwards to
   `127.0.0.1:<PORT>` (see "Access through nginx" below). The router must
   not forward `<PORT>`.

5. On krpi, in `/var/www/html/homeassistant/`, run
   `sudo docker compose up -d`. The service is named `homeassistant`.
   The container restarts by itself after a reboot.

6. Point the tablet at the hub:
   - In the ThermoBeacon app, set the server URL to
     `http://<krpi-ip>/homeassistant` (no slash at the end) and enter the
     API key from `settings.json` (`plugins.thermobeacon.api_key`). Tap
     "Save and upload now".
   - In Fully Kiosk Browser, set the start URL to
     `http://<krpi-ip>/homeassistant/`, with the slash at the end
     (Fully Kiosk settings → Web Content Settings → Start URL).

## Updating to a new version

1. If `docker-compose.yml` changed in git, copy it to krpi first.
2. Build and deploy the new image as above. Compose sees that `latest`
   points to a new image and replaces the container. The data and the
   settings stay.

To go back to an older version, set `TAG=<old tag>` in `.env` and run
`sudo docker compose up -d`. Remove the `TAG` line again to return to
`latest`. `sudo docker images homeassistant` lists the versions krpi has.

## Checks to run and report back

These checks passed on krpi on 2026-10-31, after the move from the old
thermobeacon server. Run them again after a fresh install. Run them on
krpi unless stated otherwise:

1. `sudo docker compose ps` shows the `homeassistant` service as running.
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

- The hub reads `config/settings.json` on every request, so an edit on
  krpi takes effect at once, with no new image.
- `refresh_seconds` and `layout` belong to the hub. Each plugin has its
  own section under `plugins`. The thermobeacon section holds the API
  key, the database path, and the sensor names and icons. A new API key
  must also be entered in the tablet app.
- The weather section names the place (`place`, `latitude`, `longitude`)
  and the radar area around it (`radius_km`). The weather plugin needs
  internet access from krpi: it fetches the forecast from Open-Meteo and
  the radar from HungaroMet's open data portal, and caches both under
  `/data/weather`.
- To resize or move a widget, change its `col`, `row`, `colSpan`, or
  `rowSpan` in `layout.widgets`. `layout.columns` and `layout.rows` set the
  grid. A widget type is `<plugin>.<widget>`, for example
  `thermobeacon.graph`.

## Backup

The thermobeacon history is one file. Copy it while the hub runs with
SQLite's own backup command, so the copy is consistent:

```sh
sudo docker compose exec homeassistant php -r '
  $db = new SQLite3("/data/thermobeacon.sqlite");
  $db->exec("VACUUM INTO \"/data/backup-" . date("Ymd") . ".sqlite\"");'
```

Then copy `backup-YYYYMMDD.sqlite` from the USB stick to another machine.

## Logs

`sudo docker compose logs --tail 50` shows errors. The log is limited to two
files of 1 MB each, and the server writes no line per request, so the SD
card is not worn out.
