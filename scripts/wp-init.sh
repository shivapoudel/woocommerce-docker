#!/usr/bin/env bash
set -euo pipefail

# Ensure WordPress core is present (respect WP_SKIP_COPY)
cd /var/www/html

# Activate required plugins
wp plugin activate redis-cache query-monitor woocommerce --allow-root || true

# Install/activate required theme
wp theme activate twentytwelve --allow-root || true

# Ensure WooCommerce core pages exist and permalinks are flushed
wp wc tool run install_pages --allow-root || true
wp rewrite set "/%postname%/" --hard --allow-root || true
wp rewrite flush --hard --allow-root || true

# Hand off to the default entrypoint (Apache)
exec docker-entrypoint.sh apache2-foreground
