#!/bin/sh
set -e

# Named volumes mount after the image layers, so the chown in the Dockerfile
# doesn't apply to them. Fix ownership on every container start.
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

exec "$@"
