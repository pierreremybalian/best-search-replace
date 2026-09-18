#!/usr/bin/env bash
# Build (or rebuild) the throwaway single-site WordPress with edge-case fixtures.
#
#   ./bootstrap.sh              install at http://besr.localhost:8081
#   ./bootstrap.sh --reset      wipe volumes first
set -euo pipefail
cd "$(dirname "$0")"

if docker compose version >/dev/null 2>&1; then COMPOSE="docker compose"; else COMPOSE="docker-compose"; fi

HOST="${HOST:-besr.localhost:8081}"
ADMIN_EMAIL="admin@example.com"

wp() { $COMPOSE run --rm -T wpcli "$@" 2>&1 | grep -v -e "^Warning: Some code is trying to do a URL rewrite" || true; }
wpq() { $COMPOSE run --rm -T wpcli "$@" 2>/dev/null; }

if [[ "${1:-}" == "--reset" ]]; then
  echo "» Removing containers and volumes"
  $COMPOSE down -v --remove-orphans
fi

echo "» Starting containers"
$COMPOSE up -d

echo "» Waiting for WordPress files"
for i in $(seq 1 60); do
  if $COMPOSE exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then break; fi
  sleep 2
done
$COMPOSE exec -T wordpress test -f /var/www/html/wp-config.php || { echo "wp-config.php never appeared"; exit 1; }

if ! wpq core is-installed >/dev/null; then
  echo "» Installing WordPress"
  wp core install --url="http://$HOST" --title="BESR Test" \
    --admin_user=admin --admin_password=admin --admin_email="$ADMIN_EMAIL" --skip-email
fi

echo "» Writing .htaccess"
$COMPOSE exec -T wordpress sh -c 'cat > /var/www/html/.htaccess' <<'HT'
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
HT
$COMPOSE exec -T wordpress sh -c 'mkdir -p /var/www/html/wp-content/uploads && chown -R www-data:www-data /var/www/html/wp-content'

echo "» Activating plugin"
wp plugin activate best-search-replace
wp option update permalink_structure "/%postname%/"

if [[ "$(wpq option get besr_fixtures_seeded || true)" != "1" ]]; then
  echo "» Generating posts"
  wp post generate --count=200
fi

echo "» Seeding fixtures"
wp eval-file /fixtures/seed.php

echo
echo "Ready."
echo "  Admin: http://$HOST/wp-admin/tools.php?page=best-search-replace   (admin / admin)"
echo "  Tests: $COMPOSE run --rm -T wpcli eval-file /tests/replacer-cases.php"
echo "         $COMPOSE run --rm -T wpcli eval-file /tests/engine-smoke.php"
