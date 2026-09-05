#!/bin/sh
set -eu
cd "$(dirname "$0")"
umask 077
mkdir -p secrets
for name in db_password db_root_password redis_password app_key; do
    if [ ! -e "secrets/$name" ]; then
        openssl rand -hex 32 > "secrets/$name"
    fi
done
# Bind secrets retain host modes; non-root jobs need read access.
chmod 700 secrets
chmod 444 secrets/db_password secrets/redis_password secrets/app_key
chmod 400 secrets/db_root_password
[ -e .env ] || cp .env.example .env
chmod 600 .env
echo 'Secrets prepared; existing values preserved.'
