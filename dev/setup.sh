#!/usr/bin/env bash
# Installs and seeds the local test instance (dev/compose.yaml). Local only.
# Test passwords are generated on first run and kept in dev/seed.env (git-ignored).
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
seed="$here/seed.env"
nc=bettersharing-dev-nextcloud-1

if [[ ! -f "$seed" ]]; then
  {
    echo "ADMIN_PASSWORD=$(openssl rand -hex 12)"
    echo "USER_PASSWORD=$(openssl rand -hex 12)"
  } > "$seed"
  chmod 600 "$seed"
fi
# shellcheck disable=SC1090
source "$seed"

occ() { docker exec -u www-data "$nc" php occ "$@"; }

# Development only: re-read changed PHP files on every request.
docker exec "$nc" sh -c 'echo "opcache.revalidate_freq=0" > /usr/local/etc/php/conf.d/zz-bettersharing-dev.ini && apache2ctl -k graceful' >/dev/null 2>&1 || true

for _ in $(seq 1 40); do
  docker exec "$nc" test -f /var/www/html/version.php && break
  sleep 3
done

if ! occ status --output=json | grep -q '"installed":true'; then
  docker exec -u www-data -e NC_PASS="$ADMIN_PASSWORD" "$nc" sh -c \
    'php occ maintenance:install --database=mysql --database-host=db --database-name=nextcloud \
       --database-user=nextcloud --database-pass=dev-db-only --admin-user=admin --admin-pass="$NC_PASS"'
fi

occ config:system:set trusted_domains 1 --value=localhost
occ config:system:set trusted_domains 2 --value=nextcloud
occ config:system:set overwrite.cli.url --value=http://127.0.0.1:8180
occ config:system:set mail_smtpmode --value=smtp
occ config:system:set mail_smtphost --value=mail
occ config:system:set mail_smtpport --value=1025 --type=integer
occ config:system:set mail_from_address --value=cloud
occ config:system:set mail_domain --value=outlaw.app
occ config:system:set debug --value=true --type=boolean

# Match N5's sharing policy, plus the core firm-isolation prerequisite.
occ config:app:set core shareapi_enforce_links_password --value=yes
occ config:app:set core shareapi_only_share_with_group_members --value=yes
occ config:app:set core shareapi_only_share_with_group_members_exclude_group_list --value='["admin"]'

# Two firms. Group IDs are dev placeholders until the real naming scheme is decided.
for g in firm-a firm-a-paralegals firm-b; do occ group:add "$g" >/dev/null 2>&1 || true; done
add_user() { # id, display name, groups...
  local id=$1 name=$2; shift 2
  if ! occ user:info "$id" >/dev/null 2>&1; then
    docker exec -u www-data -e OC_PASS="$USER_PASSWORD" "$nc" \
      php occ user:add --password-from-env --display-name="$name" "$id" >/dev/null
  fi
  for g in "$@"; do occ group:adduser "$g" "$id" >/dev/null 2>&1 || true; done
  occ user:setting "$id" settings email "$id@firm.test" >/dev/null
}
add_user alice "Alice Ames" firm-a firm-a-paralegals
add_user bob "Bob Barnes" firm-a
add_user carol "Carol Cole" firm-b
# alice administers firm A (subadmin), which lets her change firm A's presets.
docker exec -e NC_PASS="$ADMIN_PASSWORD" "$nc" sh -c \
  'curl -s -o /dev/null -u "admin:$NC_PASS" -H "OCS-APIRequest: true" -X POST \
     -d groupid=firm-a http://localhost/ocs/v2.php/cloud/users/alice/subadmins'

occ app:enable bettersharing >/dev/null
occ app:disable firstrunwizard >/dev/null 2>&1 || true
occ config:app:set bettersharing firm_groups --value='["firm-a","firm-b"]' >/dev/null

echo "Test instance ready: http://127.0.0.1:8180 (admin and users alice, bob, carol; passwords in dev/seed.env)"
