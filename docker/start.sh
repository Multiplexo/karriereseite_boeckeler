#!/bin/sh
# Startskript: Port setzen, Speicher anlegen, Warteschlangen-Worker im Hintergrund, Apache im Vordergrund.
set -e
PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

STORAGE_DIR="${STORAGE_DIR:-/var/data/karriere}"
mkdir -p "$STORAGE_DIR"
chown -R www-data:www-data "$STORAGE_DIR"
chmod 700 "$STORAGE_DIR"

# Fehlgeschlagene Zustellungen alle 15 Minuten erneut versuchen, abgelaufene Download-Dateien löschen.
(
  sleep 60
  while true; do
    runuser -u www-data -- php /var/www/html/api/queue-worker.php || true
    sleep 900
  done
) &

exec apache2-foreground
