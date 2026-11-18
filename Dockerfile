# Karriereseite Laurentius Apotheke – ein Container für Render (oder jeden Docker-Host):
# statische Astro-Seite + PHP-Endpunkt hinter Apache.

# 1) PHP-Abhängigkeiten (PHPMailer)
FROM composer:2.8 AS vendor
WORKDIR /app/api
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --ignore-platform-reqs

# 2) Seite bauen
FROM node:22.23-bookworm-slim AS build
WORKDIR /app
# Öffentliche URL und Pfad – bei Render als Umgebungsvariablen gesetzt und beim Build übergeben.
ARG SITE_URL=https://karriere.laurentius-apotheke-erwitte.de
ARG BASE_PATH=/
ENV SITE_URL=$SITE_URL BASE_PATH=$BASE_PATH HTTPS_REDIRECT=0
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/api/vendor ./api/vendor
RUN npm run build

# 3) Laufzeit: PHP 8.3 + Apache
FROM php:8.3-apache-bookworm
RUN a2enmod rewrite headers deflate remoteip \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/zz-security.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-karriere.ini
COPY --from=build /app/dist /var/www/html
COPY api/config.env.php /var/www/html/api/config.php
COPY docker/start.sh /usr/local/bin/start-karriere
RUN chmod +x /usr/local/bin/start-karriere && chown -R root:root /var/www/html
ENV STORAGE_DIR=/var/data/karriere PORT=10000
EXPOSE 10000
CMD ["start-karriere"]
