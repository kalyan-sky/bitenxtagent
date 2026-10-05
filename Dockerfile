# --- dependencies ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

# --- runtime ---
FROM php:8.3-apache

# Cloud Run sends traffic to $PORT (8080 by default).
ENV PORT=8080 \
    APACHE_DOCUMENT_ROOT=/var/www/app/public \
    STORAGE_BACKEND=firestore \
    LOG_TARGET=stderr \
    TRUSTED_PROXY_HOPS=1 \
    STORAGE_DIR=/tmp/support-agent

# APCu: shared in-memory cache for verified logins, the catalog and the GCP token.
RUN pecl install apcu && docker-php-ext-enable apcu

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-support-agent.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && a2enmod headers \
    && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/security.conf \
    && echo 'ServerSignature Off' >> /etc/apache2/conf-available/security.conf

WORKDIR /var/www/app
COPY --from=vendor /app/vendor ./vendor
COPY public ./public
COPY src ./src
COPY knowledge ./knowledge
COPY composer.json ./

USER www-data
EXPOSE 8080
