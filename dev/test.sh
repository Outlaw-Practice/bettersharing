#!/usr/bin/env bash
# Runs the PHPUnit suite inside the dev container (dev/compose.yaml). Local only.
set -euo pipefail
docker exec -u www-data -w /var/www/html/custom_apps/bettersharing bettersharing-dev-nextcloud-1 \
  php vendor/bin/phpunit "$@"
