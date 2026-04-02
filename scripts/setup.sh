#!/bin/bash
set -e
 
# futureradio.net setup script
# Run from the repo root as a user with sudo privileges
# e.g. /home/generic/futurefm/scripts/setup.sh
 
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP_DIR="/opt/radio"
WEB_ROOT="/var/www/futureradio"
VHOST_CONF="/etc/apache2/sites-available/futureradio.conf"
 
echo "========================================================"
echo "  futureradio.net setup"
echo "  repo: $REPO_DIR"
echo "========================================================"
 
# ── 1. Packages ──────────────────────────────────────────────────────────────
 
echo ""
echo "====> Installing packages"
sudo apt-get update -qq
sudo apt-get install -y \
    mpd \
    mpc \
    apache2 \
    php8.3 \
    php8.3-sqlite3 \
    php8.3-mbstring \
    libapache2-mod-php8.3 \
    sqlite3
 
# ── 2. Directory structure ────────────────────────────────────────────────────
 
echo ""
echo "====> Creating directory structure"
sudo mkdir -p "$APP_DIR/data"
sudo mkdir -p "$APP_DIR/logs"
sudo mkdir -p "$WEB_ROOT"
 
# ── 3. Copy files ─────────────────────────────────────────────────────────────
 
echo ""
echo "====> Deploying application files"
 
# PHP source
sudo cp -r "$REPO_DIR/src" "$APP_DIR/src"
 
# Public web root
sudo cp -r "$REPO_DIR/public/." "$WEB_ROOT/"
 
# Schema
sudo cp "$REPO_DIR/data/schema.sql" "$APP_DIR/data/schema.sql"
 
# MPD config
sudo cp "$REPO_DIR/config/mpd.conf" /etc/mpd.conf
 
# ── 4. Permissions ────────────────────────────────────────────────────────────
 
echo ""
echo "====> Setting permissions"
 
# Web root owned by www-data
sudo chown -R www-data:www-data "$WEB_ROOT"
sudo chmod -R 755 "$WEB_ROOT"
 
# App src owned by www-data (PHP needs to read it)
sudo chown -R www-data:www-data "$APP_DIR/src"
sudo chmod -R 755 "$APP_DIR/src"
 
# Data dir owned by www-data (PHP needs to write the DB)
sudo chown -R www-data:www-data "$APP_DIR/data"
sudo chmod -R 750 "$APP_DIR/data"
 
# Logs owned by www-data
sudo chown -R www-data:www-data "$APP_DIR/logs"
sudo chmod -R 750 "$APP_DIR/logs"
 
# ── 5. Initialize SQLite database ────────────────────────────────────────────
 
echo ""
echo "====> Initializing database"
 
DB_FILE="$APP_DIR/data/radio.db"
 
if [ -f "$DB_FILE" ]; then
    echo "     WARNING: $DB_FILE already exists, skipping initialization"
    echo "     Delete it manually and re-run if you want a fresh database"
else
    sudo -u www-data sqlite3 "$DB_FILE" < "$APP_DIR/data/schema.sql"
    echo "     Created $DB_FILE"
fi
 
# ── 6. Apache modules ─────────────────────────────────────────────────────────
 
echo ""
echo "====> Enabling Apache modules"
sudo a2enmod rewrite
sudo a2enmod proxy
sudo a2enmod php8.3
sudo a2enmod proxy_http
 
# ── 7. Apache vhost ───────────────────────────────────────────────────────────
 
echo ""
echo "====> Writing Apache vhost config"
 
sudo tee "$VHOST_CONF" > /dev/null <<'EOF'
<VirtualHost *:80>
    ServerName futureradio.net
    ServerAlias www.futureradio.net
    DocumentRoot /var/www/futureradio
 
    # Proxy /stream to MPD's built-in HTTP output
    ProxyPass        /stream http://127.0.0.1:8000/
    ProxyPassReverse /stream http://127.0.0.1:8000/
 
    <Directory /var/www/futureradio>
        AllowOverride All
        Require all granted
    </Directory>
 
    # Keep PHP source out of the web root
    <Directory /opt/radio/src>
        Require all denied
    </Directory>
 
    ErrorLog  /opt/radio/logs/error.log
    CustomLog /opt/radio/logs/access.log combined
</VirtualHost>
EOF
 
# ── 8. Enable vhost, disable default ─────────────────────────────────────────
 
echo ""
echo "====> Enabling vhost"
sudo a2dissite 000-default.conf 2>/dev/null || true
sudo a2ensite futureradio.conf
 
# ── 9. Write .htaccess for front controller ───────────────────────────────────
 
echo ""
echo "====> Writing .htaccess"
 
sudo tee "$WEB_ROOT/.htaccess" > /dev/null <<'EOF'
RewriteEngine On
 
# Don't rewrite real files or directories (CSS, images, etc.)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
 
# Don't rewrite the stream proxy
RewriteCond %{REQUEST_URI} !^/stream
 
# Everything else goes to the front controller
RewriteRule ^ index.php [QSA,L]
EOF
 
# ── 10. Start services ────────────────────────────────────────────────────────
 
echo ""
echo "====> Starting services"
sudo systemctl enable --now mpd
sudo systemctl enable --now apache2
sudo systemctl restart apache2
 
echo ""
echo "========================================================"
echo "  Setup complete."
echo "  Site:   http://futureradio.net"
echo "  Stream: http://futureradio.net/stream"
echo "  DB:     $APP_DIR/data/radio.db"
echo "========================================================"
