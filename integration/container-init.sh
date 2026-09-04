#!/bin/sh
set -eu

if [ ! -f /var/www/html/init.php ]; then
    temporary="$(mktemp -d)"
    # This is PHP source, not shell expansion.
    # shellcheck disable=SC2016
    php -r '$zip = new ZipArchive(); if ($zip->open("/dist/whmcs.zip") !== true || !$zip->extractTo($argv[1])) { exit(1); }' "$temporary"
    cp -a "$temporary/whmcs/." /var/www/html/
    rm -rf "$temporary"
fi

install -d -m 0700 -o www-data -g www-data /var/lib/whmcs-plugin-updater
install -m 0400 -o www-data -g www-data /dist/licence.key /run/whmcs-license
if [ -d /mock-certs ]; then
    attempts=0
    while [ ! -s /mock-certs/ca.crt ]; do
        attempts=$((attempts + 1))
        [ "$attempts" -lt 30 ] || { echo 'Mock GitHub certificate was not created' >&2; exit 1; }
        sleep 1
    done
    install -m 0644 /mock-certs/ca.crt /usr/local/share/ca-certificates/pluginupdater-mock-github.crt
    update-ca-certificates >/dev/null
fi
install -d -m 0755 -o www-data -g www-data /var/www/html/modules/addons
rm -rf /var/www/html/modules/addons/pluginupdater
cp -a /source/modules/addons/pluginupdater /var/www/html/modules/addons/pluginupdater
chown -R www-data:www-data /var/www/html
touch /var/www/html/.pluginupdater-container-ready

exec "$@"
