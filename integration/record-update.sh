#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
compose='docker compose -f integration/compose.yaml -f integration/compose.video.yaml'

test -s whmcs-dist/licence.key || { echo 'Missing whmcs-dist/licence.key' >&2; exit 1; }
case "$(stat -c '%a' whmcs-dist/licence.key)" in
    400|600) ;;
    *) echo 'whmcs-dist/licence.key must have mode 0400 or 0600' >&2; exit 1 ;;
esac
git check-ignore -q whmcs-dist/licence.key || { echo 'whmcs-dist must remain ignored by Git' >&2; exit 1; }

mkdir -p integration/artifacts
for version in ${WHMCS_VERSIONS:-8.13.7 9.0.8}; do
    case "$version" in
        8.13.7) WHMCS_ZIP=whmcs_v8137_full.zip; WHMCS_ARTIFACT_TAG=8137 ;;
        9.0.8) WHMCS_ZIP=whmcs_v908_full.zip; WHMCS_ARTIFACT_TAG=908 ;;
        *) echo "Unsupported WHMCS version: $version" >&2; exit 1 ;;
    esac
    test -s "whmcs-dist/$WHMCS_ZIP" || { echo "Missing whmcs-dist/$WHMCS_ZIP" >&2; exit 1; }
    WHMCS_EXPECTED_VERSION=$version
    export WHMCS_ZIP WHMCS_EXPECTED_VERSION WHMCS_ARTIFACT_TAG

    $compose down -v --remove-orphans
    $compose up -d --build db mock-github web
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
    $compose exec -T --user www-data web php /source/integration/video-prepare.php

    rm -f "integration/artifacts/whmcs-plugin-update-demo-$WHMCS_ARTIFACT_TAG.webm"
    LOCAL_UID="$(id -u)" LOCAL_GID="$(id -g)" $compose build playwright
    LOCAL_UID="$(id -u)" LOCAL_GID="$(id -g)" $compose run --rm --no-deps playwright
    test -s "integration/artifacts/whmcs-plugin-update-demo-$WHMCS_ARTIFACT_TAG.webm"
    echo "Recorded integration/artifacts/whmcs-plugin-update-demo-$WHMCS_ARTIFACT_TAG.webm for WHMCS $version."
done
