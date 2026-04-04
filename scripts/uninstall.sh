#!/bin/bash

# futureradio.net uninstall script
# Removes installed files, configs, and optionally the database.
# Run from anywhere as a user with sudo privileges.
#
# Usage:
#   uninstall.sh          Full uninstall — removes everything including the database.
#   uninstall.sh --soft   Soft uninstall — removes code and config but KEEPS the database.
#                         Use this before re-running setup.sh on an existing install.

APP_DIR="/opt/radio"
WEB_ROOT="/var/www/futureradio"
VHOST_HTTP="/etc/apache2/sites-available/futureradio.conf"
VHOST_SSL="/etc/apache2/sites-available/futureradio-ssl.conf"
MPD_CONF="/etc/mpd.conf"

SOFT=false
for arg in "$@"; do
    case "$arg" in
        --soft) SOFT=true ;;
        *) echo "Unknown argument: $arg"; exit 1 ;;
    esac
done

echo "========================================================"
if [ "$SOFT" = true ]; then
    echo "  futureradio.net soft uninstall"
    echo "  Code, config, and web files will be removed."
    echo "  The database (${APP_DIR}/data/radio.db) will be KEPT."
else
    echo "  futureradio.net uninstall"
    echo "  THIS WILL DELETE ALL DATA INCLUDING THE DATABASE."
fi
echo "========================================================"
echo ""
read -rp "Are you sure you want to uninstall? Type YES to confirm: " confirm

if [ "$confirm" != "YES" ]; then
    echo "Aborted."
    exit 0
fi

# ── 1. Stop services ──────────────────────────────────────────────────────────

echo ""
echo "====> Stopping services"
sudo systemctl stop mpd    2>/dev/null || true
sudo systemctl stop apache2 2>/dev/null || true

# ── 2. Disable Apache vhosts ──────────────────────────────────────────────────

echo ""
echo "====> Disabling vhosts"
sudo a2dissite futureradio.conf     2>/dev/null || true
sudo a2dissite futureradio-ssl.conf 2>/dev/null || true

# Re-enable the default site so Apache isn't left with nothing
sudo a2ensite 000-default.conf 2>/dev/null || true

# ── 3. Remove vhost configs ───────────────────────────────────────────────────

echo ""
echo "====> Removing Apache vhost configs"
sudo rm -f "$VHOST_HTTP"
sudo rm -f "$VHOST_SSL"

# ── 4. Remove web root ────────────────────────────────────────────────────────

echo ""
echo "====> Removing web root"
sudo rm -rf "$WEB_ROOT"

# ── 5. Remove application directory ──────────────────────────────────────────

echo ""
if [ "$SOFT" = true ]; then
    echo "====> Removing application files (keeping database)"
    # Remove everything under APP_DIR except the data directory
    sudo find "$APP_DIR" -mindepth 1 -maxdepth 1 ! -name data -exec rm -rf {} +
    # Remove everything inside data except the .db file itself
    sudo find "$APP_DIR/data" -mindepth 1 ! -name '*.db' -exec rm -rf {} +
    echo "     Kept: ${APP_DIR}/data/"
else
    echo "====> Removing application directory and database"
    sudo rm -rf "$APP_DIR"
fi

# ── 6. Remove MPD config ──────────────────────────────────────────────────────

echo ""
echo "====> Removing MPD config"
sudo rm -f "$MPD_CONF"

# ── 7. Restart Apache ─────────────────────────────────────────────────────────

echo ""
echo "====> Restarting Apache"
sudo systemctl start apache2 2>/dev/null || true

# ── 8. Restart MPD ────────────────────────────────────────────────────────────

echo ""
echo "====> Restarting MPD"
sudo systemctl start mpd 2>/dev/null || true

echo ""
echo "========================================================"
if [ "$SOFT" = true ]; then
    echo "  Soft uninstall complete."
    echo ""
    echo "  Database preserved at: ${APP_DIR}/data/"
    echo "  Re-run setup.sh to redeploy the application:"
    echo "    scripts/setup.sh [certbot-email]"
else
    echo "  Uninstall complete."
fi
echo ""
echo "  NOTE: Packages (apache2, mpd, php, sqlite3, mpc) were"
echo "  not removed. If you want to remove them run:"
echo "  sudo apt-get remove mpd mpc apache2 php8.4 php8.4-sqlite3 \\"
echo "    php8.4-mbstring libapache2-mod-php8.4 sqlite3"
echo "========================================================"
