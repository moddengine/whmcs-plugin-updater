#!/bin/sh
set -eu

if [ ! -s /certs/ca.crt ]; then
    openssl req -x509 -newkey rsa:2048 -nodes \
        -keyout /certs/server.key -out /certs/server.crt -days 2 \
        -subj /CN=api.github.com \
        -addext subjectAltName=DNS:api.github.com \
        -addext basicConstraints=critical,CA:TRUE >/dev/null 2>&1
    cp /certs/server.crt /certs/ca.crt
    chmod 0600 /certs/server.key
    chmod 0644 /certs/server.crt /certs/ca.crt
fi

exec python /app/server.py
