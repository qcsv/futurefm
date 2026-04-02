#!/bin/bash

# futureradio.net uninstall script
# Removes all installed files, configs, and data.
# Run from anywhere as a user with sudo privileges.
#
# THIS IS DESTRUCTIVE AND IRREVERSIBLE.
# All user accounts, sessions, and invite tokens will be lost.

APP_DIR="/opt/radio"
WEB_ROOT="/var/www/futureradio"
VHOST_CONF="/etc/apache2/sites-available/futureradio.conf"
MPD_CONF="/etc/mpd.conf"

echo "========================================================"
echo "  futureradio.net uninstall"
echo "  THIS WILL DELETE ALL DATA INCLUDING THE DATABASE."
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

# ── 2. Disable Apache vhost ───────────────────────────────────────────────────

echo ""
echo "====> Disabling vhost"
sudo a2dissite futureradio.conf 2>/dev/null || true

# Re-enable the default site so Apache isn't left with nothing
sudo a2ensite 000-default.conf 2>/dev/null || true

# ── 3. Remove vhost config ────────────────────────────────────────────────────

echo ""
echo "====> Removing Apache vhost config"
sudo rm -f "$VHOST_CONF"

# ── 4. Remove web root ────────────────────────────────────────────────────────

echo ""
echo "====> Removing web root"
sudo rm -rf "$WEB_ROOT"

# ── 5. Remove application directory (includes database) ───────────────────────

echo ""
echo "====> Removing application directory and database"
sudo rm -rf "$APP_DIR"

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
echo "  Uninstall complete."
echo ""
echo "  NOTE: Packages (apache2, mpd, php, sqlite3, mpc) were"
echo "  not removed. If you want to remove them run:"
echo "  sudo apt-get remove mpd mpc apache2 php8.3 php8.3-sqlite3 \\"
echo "    php8.3-mbstring libapache2-mod-php8.3 sqlite3"
echo "========================================================"
