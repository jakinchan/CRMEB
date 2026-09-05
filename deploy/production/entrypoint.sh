#!/bin/sh
set -eu
export PHP_DATABASE_PASSWORD="$(cat /run/secrets/db_password)"
export PHP_REDIS_REDIS_PASSWORD="$(cat /run/secrets/redis_password)"
export PHP_APP_APP_KEY="$(cat /run/secrets/app_key)"
exec docker-php-entrypoint "$@"
