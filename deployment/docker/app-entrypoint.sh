#!/bin/sh
set -eu

umask 027

mkdir -p \
    /app/bootstrap/cache \
    /app/storage/framework/cache/data \
    /app/storage/framework/sessions \
    /app/storage/framework/testing \
    /app/storage/framework/views \
    /app/storage/logs

# The package-discovery cache is non-secret build metadata. The runtime tmpfs
# starts empty, so restore only that metadata before caching injected config.
if [ -d /opt/selfhandler/bootstrap-cache ]; then
    for cache_file in /opt/selfhandler/bootstrap-cache/*.php; do
        if [ -f "$cache_file" ]; then
            cp "$cache_file" /app/bootstrap/cache/
        fi
    done
fi

rm -f /app/bootstrap/cache/config.php
php /app/artisan config:cache --no-ansi

exec "$@"
