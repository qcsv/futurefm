#!/usr/bin/bash

echo "========> Setting up server"
sudo apt-get -y mpd apache2 php sqlite3 mpc

# Create MPD database (where?)
# Deploy MPD Config file
# Deploy PHP file
# Start apache2
sudo systemctl enable --now apache2
