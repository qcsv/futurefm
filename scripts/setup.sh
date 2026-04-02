#!/usr/bin/bash

echo "========> Setting up server"
sudo apt-get -y install mpd apache2 php sqlite3 mpc



echo "=====> Setting up user"
#sudo adduser futurefm --home /home/futurefm --disabled-password --comment "futurefm"
#echo "====> Setting up group"
#sudo addgroup music-players



echo "===> Set up config files"
#  Deploy MPD Config file
 cp config/mpd.conf /etc/mpd.conf

#Deploy files for apache
 

 #setup schema
sqlite3 /opt/radio/data/radio.db < /opt/radio/data/schema.sql

# Deploy PHP file
#cp www/index.php /var/www/htdocs/index.php

# Start apache2
#sudo systemctl enable --now apache2

