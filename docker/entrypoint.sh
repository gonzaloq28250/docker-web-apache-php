#!/bin/bash
set -e

if [ ! -f /etc/apache2/ssl/certificate.crt ] || [ ! -f /etc/apache2/ssl/private.key ]; then
    echo "Generating self-signed SSL certificate for *.test domains..."

    mkdir -p /etc/apache2/ssl

    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout /etc/apache2/ssl/private.key \
        -out /etc/apache2/ssl/certificate.crt \
        -subj "/C=CO/ST=Local/L=Dev/O=Local Development/CN=*.test" \
        -addext "subjectAltName=DNS:*.test,DNS:localhost,DNS:*.localhost,IP:127.0.0.1"

    chmod 600 /etc/apache2/ssl/private.key

    echo "Self-signed certificate generated for *.test"
fi

exec "$@"
