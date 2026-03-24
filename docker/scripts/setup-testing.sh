#!/usr/bin/env bash
# =============================================================================
# LeanAutoLinks Testing Environment Setup Script
# =============================================================================
# Run inside the wp-cli container:
#   docker compose run --rm wp-cli bash /scripts/setup-testing.sh
#
# NOTE: Make this file executable with: chmod +x docker/scripts/setup-testing.sh
# =============================================================================

set -euo pipefail

echo "============================================"
echo "  LeanAutoLinks Testing Environment Setup"
echo "============================================"
echo ""

WP_URL="http://wordpress"

# ---------------------------------------------------------------------------
# Wait for WordPress to be ready
# ---------------------------------------------------------------------------
echo "[1/8] Waiting for WordPress to be ready..."
MAX_RETRIES=30
RETRY_COUNT=0
until wp core is-installed --allow-root 2>/dev/null; do
    RETRY_COUNT=$((RETRY_COUNT + 1))
    if [ "$RETRY_COUNT" -ge "$MAX_RETRIES" ]; then
        echo "  WordPress not ready after $MAX_RETRIES attempts."
        echo "  Attempting fresh installation..."
        break
    fi
    echo "  Waiting... ($RETRY_COUNT/$MAX_RETRIES)"
    sleep 2
done

# ---------------------------------------------------------------------------
# Install WordPress if not already installed
# ---------------------------------------------------------------------------
echo "[2/8] Checking WordPress installation..."
if ! wp core is-installed --allow-root 2>/dev/null; then
    echo "  Installing WordPress..."
    wp core install \
        --url="http://localhost:8080" \
        --title="LeanAutoLinks Test Site" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@leanautolinks.test \
        --skip-email \
        --allow-root
    echo "  WordPress installed successfully."
else
    echo "  WordPress already installed."
fi

# ---------------------------------------------------------------------------
# Configure WordPress for testing
# ---------------------------------------------------------------------------
echo "[3/8] Configuring WordPress for testing..."

# Set permalink structure (important for REST API and testing)
wp rewrite structure '/%postname%/' --allow-root
wp rewrite flush --allow-root

# Set timezone
wp option update timezone_string 'America/New_York' --allow-root

# Increase memory limits via options (already set in wp-config via docker-compose)
wp option update posts_per_page 10 --allow-root

# Disable auto-updates during testing
wp config set AUTOMATIC_UPDATER_DISABLED true --raw --allow-root 2>/dev/null || true

# Disable cron for controlled testing (we trigger manually)
wp config set DISABLE_WP_CRON true --raw --allow-root 2>/dev/null || true

echo "  WordPress configured for testing."

# ---------------------------------------------------------------------------
# Install Query Monitor plugin
# ---------------------------------------------------------------------------
echo "[4/8] Installing Query Monitor plugin..."
if wp plugin is-installed query-monitor --allow-root 2>/dev/null; then
    echo "  Query Monitor already installed."
    wp plugin activate query-monitor --allow-root 2>/dev/null || true
else
    wp plugin install query-monitor --activate --allow-root
    echo "  Query Monitor installed and activated."
fi

# ---------------------------------------------------------------------------
# Install and configure other useful testing plugins
# ---------------------------------------------------------------------------
echo "[5/8] Installing additional testing tools..."

# Debug Bar (complements Query Monitor)
if ! wp plugin is-installed debug-bar --allow-root 2>/dev/null; then
    wp plugin install debug-bar --activate --allow-root
    echo "  Debug Bar installed."
else
    wp plugin activate debug-bar --allow-root 2>/dev/null || true
    echo "  Debug Bar already installed."
fi

# ---------------------------------------------------------------------------
# Activate LeanAutoLinks plugin if present
# ---------------------------------------------------------------------------
echo "[6/8] Checking LeanAutoLinks plugin..."
if [ -f /var/www/html/wp-content/plugins/leanautolinks/leanautolinks.php ]; then
    wp plugin activate leanautolinks --allow-root 2>/dev/null && \
        echo "  LeanAutoLinks plugin activated." || \
        echo "  LeanAutoLinks plugin found but could not be activated (may need dependencies)."
else
    echo "  LeanAutoLinks plugin not yet built. Skipping activation."
fi

# ---------------------------------------------------------------------------
# Set file permissions
# ---------------------------------------------------------------------------
echo "[7/8] Setting file permissions..."

# Ensure wp-content is writable for uploads and plugin operations
# Note: Running as www-data (uid 33) in the container
chmod -R 775 /var/www/html/wp-content/uploads 2>/dev/null || true
chmod -R 775 /var/www/html/wp-content/plugins 2>/dev/null || true

echo "  Permissions configured."

# ---------------------------------------------------------------------------
# Verify setup
# ---------------------------------------------------------------------------
echo "[8/8] Verifying setup..."
echo ""
echo "  WordPress version: $(wp core version --allow-root)"
echo "  PHP version: $(php -r 'echo PHP_VERSION;')"
echo "  MySQL status: $(wp db check --allow-root 2>&1 | tail -1)"
echo ""
echo "  Active plugins:"
wp plugin list --status=active --format=table --allow-root
echo ""
echo "  Site URL: http://localhost:8080"
echo "  Admin URL: http://localhost:8080/wp-admin/"
echo "  Admin user: admin"
echo "  Admin password: admin"
echo "  phpMyAdmin: http://localhost:8081"
echo ""
echo "============================================"
echo "  Setup Complete"
echo "============================================"
echo ""
echo "  Next steps:"
echo "    1. Seed test data: docker compose run --rm wp-cli wp leanautolinks seed --posts=15000"
echo "    2. Run benchmarks: ./bin/benchmark.sh"
echo "    3. View Query Monitor: Log in to wp-admin and check the admin bar"
echo ""
