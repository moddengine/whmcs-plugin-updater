#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
compose='docker compose -f integration/compose.yaml'

test -s whmcs-dist/licence.key || { echo 'Missing whmcs-dist/licence.key' >&2; exit 1; }
case "$(stat -c '%a' whmcs-dist/licence.key)" in
    400|600) ;;
    *) echo 'whmcs-dist/licence.key must have mode 0400 or 0600' >&2; exit 1 ;;
esac
git check-ignore -q whmcs-dist/licence.key || { echo 'whmcs-dist must remain ignored by Git' >&2; exit 1; }

for version in ${WHMCS_VERSIONS:-8.13.7 9.0.8}; do
    case "$version" in
        8.13.7) WHMCS_ZIP=whmcs_v8137_full.zip ;;
        9.0.8) WHMCS_ZIP=whmcs_v908_full.zip ;;
        *) echo "Unsupported WHMCS version: $version" >&2; exit 1 ;;
    esac
    test -s "whmcs-dist/$WHMCS_ZIP" || { echo "Missing whmcs-dist/$WHMCS_ZIP" >&2; exit 1; }
    WHMCS_EXPECTED_VERSION=$version
    export WHMCS_ZIP WHMCS_EXPECTED_VERSION

    $compose down -v --remove-orphans
    $compose up -d --build
    attempts=0
    until $compose exec -T web test -f /var/www/html/.pluginupdater-container-ready; do
        attempts=$((attempts + 1))
        [ "$attempts" -lt 120 ] || { echo 'WHMCS container initialization timed out' >&2; exit 1; }
        sleep 1
    done
    $compose exec -T --user www-data web php /source/integration/install-whmcs.php
    # Expansion belongs to the container shell.
    # shellcheck disable=SC2016
    $compose exec -T web sh -eu -c 'if [ -d /var/www/html/install ]; then mv /var/www/html/install "/tmp/whmcs-install-$(date +%s)"; fi'
    $compose exec -T --user www-data web php -d max_execution_time=60 -d disable_functions=set_time_limit /source/integration/timeout-check.php
    $compose exec -T --user www-data web php /source/integration/run.php
    curl -fsS -o /dev/null "http://127.0.0.1:${WHMCS_PORT:-18080}/"
    echo "Licensed WHMCS $version integration harness passed."
done
