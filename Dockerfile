# The hub's image: the official PHP image plus the hub's files. The build
# runs no commands inside the image, so this laptop can build it for krpi's
# ARM CPU without ARM emulation (see tools/build-image.sh).
FROM php:8.5-cli-alpine

WORKDIR /app
COPY router.php ./
COPY core/ core/
COPY public/ public/
COPY plugins/ plugins/
COPY tools/ tools/

EXPOSE 8080
# -q: no access log, so the server does not write a line per request.
# display_errors=0: PHP warnings go to the container log only. Printed
# warnings would come before the headers, and every answer would turn
# into "200 OK".
# error_log=/proc/self/fd/2: with -q, php -S drops error_log() lines
# unless they go to a file, so they are sent to stderr explicitly.
CMD ["php", "-q", "-d", "display_errors=0", "-d", "log_errors=1", "-d", "error_log=/proc/self/fd/2", \
     "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/router.php"]
