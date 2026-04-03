#!/bin/bash
set -e

# futureradio.net setup script
# Run from the repo root as a user with sudo privileges
# e.g. /home/generic/futurefm/scripts/setup.sh [certbot-email]
#
# Pass your email as the first argument, or set CERTBOT_EMAIL in the
# environment, to obtain a Let's Encrypt TLS certificate automatically.
# If neither is provided the script runs in HTTP-only mode and prints
# the certbot command you can run later.

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP_DIR="/opt/radio"
WEB_ROOT="/var/www/futureradio"
VHOST_HTTP="/etc/apache2/sites-available/futureradio.conf"
VHOST_SSL="/etc/apache2/sites-available/futureradio-ssl.conf"
DOMAIN="futureradio.net"

# Certbot email: CLI arg → env var → empty (HTTP-only mode)
CERTBOT_EMAIL="${1:-${CERTBOT_EMAIL:-}}"

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
    php8.4 \
    php8.4-sqlite3 \
    php8.4-mbstring \
    libapache2-mod-php8.4 \
    sqlite3 \
    certbot \
    python3-certbot-apache

# ── 2. Directory structure ────────────────────────────────────────────────────

echo ""
echo "====> Creating directory structure"
sudo mkdir -p "$APP_DIR/data"
sudo mkdir -p "$APP_DIR/logs"
sudo mkdir -p "$APP_DIR/config"
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

# Views
sudo cp -r "$REPO_DIR/views" "$APP_DIR/views"

# App config
sudo cp "$REPO_DIR/config/config.php" "$APP_DIR/config/config.php"

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

# Views owned by www-data
sudo chown -R www-data:www-data "$APP_DIR/views"
sudo chmod -R 755 "$APP_DIR/views"

# Data dir owned by www-data (PHP needs to write the DB)
sudo chown -R www-data:www-data "$APP_DIR/data"
sudo chmod -R 750 "$APP_DIR/data"

# Config dir — readable by www-data, not world-readable
sudo chown -R www-data:www-data "$APP_DIR/config"
sudo chmod -R 750 "$APP_DIR/config"

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
    sudo -u www-data sh -c "sqlite3 '$DB_FILE' < '$APP_DIR/data/schema.sql'"
    echo "     Created $DB_FILE"
fi

# ── 6. Apache modules ─────────────────────────────────────────────────────────

echo ""
echo "====> Enabling Apache modules"
sudo a2enmod rewrite
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod ssl
sudo a2enmod headers
sudo a2enmod php8.4

# ── 7. Apache HTTP vhost (redirect + ACME passthrough) ───────────────────────

echo ""
echo "====> Writing Apache HTTP vhost (port 80)"

sudo tee "$VHOST_HTTP" > /dev/null <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ServerAlias www.${DOMAIN}
    DocumentRoot ${WEB_ROOT}

    # Serve ACME challenge files for Let's Encrypt cert issuance and renewal
    <Location "/.well-known/acme-challenge">
        Require all granted
    </Location>

    # Redirect all other traffic to HTTPS
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/.well-known/acme-challenge
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

    ErrorLog  ${APP_DIR}/logs/error.log
    CustomLog ${APP_DIR}/logs/access.log combined
</VirtualHost>
EOF

# ── 8. Enable HTTP vhost, disable default ────────────────────────────────────

echo ""
echo "====> Enabling HTTP vhost"
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

# ── 11. TLS certificate via Let's Encrypt ────────────────────────────────────

CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
TLS_ENABLED=false

if [ -n "$CERTBOT_EMAIL" ]; then
    echo ""
    echo "====> Obtaining TLS certificate (certbot)"
    echo "      domain: ${DOMAIN}, www.${DOMAIN}"
    echo "      email:  ${CERTBOT_EMAIL}"

    if sudo certbot certonly \
            --webroot \
            --webroot-path "$WEB_ROOT" \
            --non-interactive \
            --agree-tos \
            --email "$CERTBOT_EMAIL" \
            -d "$DOMAIN" \
            -d "www.${DOMAIN}"; then
        TLS_ENABLED=true
        echo "      Certificate obtained successfully."
    else
        echo "      WARNING: certbot failed (is ${DOMAIN} pointing to this server?)."
        echo "      HTTP-only mode will be used. Run the following once DNS is ready:"
        echo ""
        echo "        sudo certbot certonly --webroot --webroot-path ${WEB_ROOT} \\"
        echo "          --email YOUR_EMAIL --agree-tos \\"
        echo "          -d ${DOMAIN} -d www.${DOMAIN}"
        echo "        sudo a2ensite futureradio-ssl.conf && sudo systemctl reload apache2"
    fi
else
    echo ""
    echo "====> Skipping TLS (no email provided)"
    echo "      Run setup again with your email as the first argument:"
    echo "        $0 you@example.com"
    echo "      Or run certbot manually once DNS is ready:"
    echo ""
    echo "        sudo certbot certonly --webroot --webroot-path ${WEB_ROOT} \\"
    echo "          --email YOUR_EMAIL --agree-tos \\"
    echo "          -d ${DOMAIN} -d www.${DOMAIN}"
    echo "        sudo a2ensite futureradio-ssl.conf && sudo systemctl reload apache2"
fi

# ── 12. Apache HTTPS vhost ───────────────────────────────────────────────────

if [ "$TLS_ENABLED" = true ]; then
    echo ""
    echo "====> Writing Apache HTTPS vhost (port 443)"

    sudo tee "$VHOST_SSL" > /dev/null <<EOF
# OCSP stapling cache (server-wide, must be outside VirtualHost)
SSLStaplingCache shmcb:/var/run/apache2/stapling-cache(150000)

<VirtualHost *:443>
    ServerName ${DOMAIN}
    ServerAlias www.${DOMAIN}
    DocumentRoot ${WEB_ROOT}

    # ── TLS ──────────────────────────────────────────────────────────────────
    SSLEngine on
    SSLCertificateFile    ${CERT_DIR}/fullchain.pem
    SSLCertificateKeyFile ${CERT_DIR}/privkey.pem

    # TLS 1.2 and 1.3 only; disable legacy protocols
    SSLProtocol             all -SSLv3 -TLSv1 -TLSv1.1
    # Modern cipher suite (server does NOT dictate order; client picks best)
    SSLCipherSuite          ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256
    SSLHonorCipherOrder     off
    SSLSessionTickets       off

    # OCSP stapling — reduces latency for certificate validation
    SSLUseStapling on

    # ── Security headers ─────────────────────────────────────────────────────
    # HSTS: force HTTPS for 1 year, including subdomains
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    # Prevent MIME type sniffing
    Header always set X-Content-Type-Options "nosniff"
    # Deny framing (clickjacking protection)
    Header always set X-Frame-Options "DENY"

    # ── Stream proxy ─────────────────────────────────────────────────────────
    # Proxies /stream to MPD's built-in HTTP output on localhost.
    # Clients always hit this over HTTPS; the internal hop stays plain HTTP.
    ProxyPass        /stream http://127.0.0.1:8000/
    ProxyPassReverse /stream http://127.0.0.1:8000/

    # ── Document root ────────────────────────────────────────────────────────
    <Directory ${WEB_ROOT}>
        AllowOverride All
        Require all granted
    </Directory>

    # Keep PHP source and config out of the web root
    <Directory /opt/radio/src>
        Require all denied
    </Directory>

    <Directory /opt/radio/config>
        Require all denied
    </Directory>

    ErrorLog  ${APP_DIR}/logs/error.log
    CustomLog ${APP_DIR}/logs/access.log combined
</VirtualHost>
EOF

    echo "====> Enabling HTTPS vhost"
    sudo a2ensite futureradio-ssl.conf
    sudo systemctl reload apache2
    echo "      HTTPS vhost enabled."
fi

# ── Done ──────────────────────────────────────────────────────────────────────

echo ""
echo "========================================================"
echo "  Setup complete."
if [ "$TLS_ENABLED" = true ]; then
    echo "  Site:   https://${DOMAIN}"
    echo "  Stream: https://${DOMAIN}/stream"
    echo "  HTTP:   redirects to HTTPS automatically"
    echo "  Cert:   ${CERT_DIR}"
    echo "  Renew:  managed by certbot systemd timer (auto)"
else
    echo "  Site:   http://${DOMAIN}  (HTTP only)"
    echo "  Stream: http://${DOMAIN}/stream"
    echo "  HTTPS:  not yet configured (see above)"
fi
echo "  DB:     ${APP_DIR}/data/radio.db"
echo "========================================================"
