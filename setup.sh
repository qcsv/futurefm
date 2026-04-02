#!/usr/bin/bash

echo "========> Setting up server"
sudo apt-get -y mpd apache2 php sqlite3 mpc


adduser futurefum
addgroup music-players
sudo mkdir /opt/radio
sudo chown futurefm /opt/radio


# Deploy MPD Config file
cp config/mpd.conf /etc/mpd.conf
# Deploy PHP file
cp www/index.php /var/www/htdocs/index.php
# Start apache2
sudo systemctl enable --now apache2

