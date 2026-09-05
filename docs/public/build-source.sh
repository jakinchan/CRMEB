#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
for file in source/crmeb/composer.json source/crmeb/composer.lock source/template/admin/package-lock.json source/template/admin/package.json; do
  test -f "$file" || { echo "Missing: $file" >&2; exit 1; }
done
test -f .env || { echo 'Create the deployment .env first, following the guide.' >&2; exit 1; }
sudo docker compose --profile jobs config --quiet
if [ -n "$(sudo docker compose --profile jobs ps --status running -q)" ]; then
  echo 'Stop this deployment before building: source files are bind-mounted.' >&2
  exit 1
fi
sudo docker compose build php
# Use host UID to avoid root-owned vendor/build output in the source checkout.
sudo docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD/source/crmeb:/var/www" -w /var/www \
  crmeb-minipc-php:7.4.33 \
  composer install --no-dev --prefer-dist --no-interaction --no-scripts
sudo docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD/source/crmeb:/var/www" -w /var/www \
  crmeb-minipc-php:7.4.33 composer check-platform-reqs --no-dev
sudo docker run --rm --user "$(id -u):$(id -g)" \
  -e npm_config_cache=/tmp/npm-cache \
  -e NODE_OPTIONS=--openssl-legacy-provider \
  -v "$PWD/source/template/admin:/app" -w /app \
  node:22-bookworm sh -ec 'npm ci && npm run build'
test -s source/template/admin/dist/index.html
if [ -e source/crmeb/public/admin ]; then
  mkdir -p build-backups
  saved=$(mktemp -d "$PWD/build-backups/admin-XXXXXXXX")
  mv source/crmeb/public/admin "$saved/admin"
fi
cp -a source/template/admin/dist source/crmeb/public/admin
sudo docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD/source/crmeb:/var/www" -w /var/www \
  crmeb-minipc-php:7.4.33 php think service:discover
printf 'Build completed. Source commit: '
git -C source rev-parse HEAD
echo 'Continue with ownership setup and docker compose up in the guide.'
