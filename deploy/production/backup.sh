#!/bin/sh
set -eu
cd "$(dirname "$0")"
umask 077
target="backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$target"
# Call during a maintenance window with app writers and jobs stopped.
# A failed export leaves INCOMPLETE rather than a false success marker.
touch "$target/INCOMPLETE"
docker compose exec -T mysql sh -c 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" exec mysqldump -uroot --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"' > "$target/database.sql"
docker compose run --rm --no-deps -T --entrypoint tar php -czf - -C /var/www/public uploads statics > "$target/files.tar.gz"
docker compose exec -T redis sh -c 'REDISCLI_AUTH="$(cat /run/secrets/redis_password)" redis-cli --rdb /data/migration.rdb >/dev/null'
docker compose cp redis:/data/migration.rdb "$target/redis.rdb"
test -s "$target/database.sql"
test -s "$target/redis.rdb"
tar -tzf "$target/files.tar.gz" >/dev/null
rm "$target/INCOMPLETE"
echo "Backup completed: $target (copy off-host; protect secrets separately)."
