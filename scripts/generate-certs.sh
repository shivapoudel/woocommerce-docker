#!/bin/bash
#
# Generate self-signed SSL certificates for woocommerce-docker.local
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CERTS_DIR="$(dirname "$SCRIPT_DIR")/certs"

mkdir -p "$CERTS_DIR"
cd "$CERTS_DIR"

echo "Generating SSL certificate for woocommerce-docker.local..."

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout key.pem \
  -out cert.pem \
  -subj "/C=US/ST=Local/L=Local/O=Dev/CN=woocommerce-docker.local" \
  -addext "subjectAltName=DNS:woocommerce-docker.local,DNS:localhost"

echo ""
echo "✅ Certificates generated in: $CERTS_DIR"
ls -la "$CERTS_DIR"

echo ""
echo "To add to macOS Keychain (removes browser SSL warnings):"
echo "  sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain $CERTS_DIR/cert.pem"
echo ""
echo "Then restart Kong:"
echo "  docker-compose restart kong"
