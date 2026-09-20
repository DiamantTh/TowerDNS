# Optional development image for the database integration environment.
# TowerDNS does not require Docker or Podman at runtime.

FROM node:22-trixie-slim AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY themes ./themes
COPY vite.config.ts svelte.config.js tsconfig.json ./
RUN npm run build

FROM docker.io/library/composer:2 AS composer-bin

FROM php:8.4-cli-trixie AS dependencies
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libonig-dev libpq-dev libsqlite3-dev unzip \
    && docker-php-ext-install intl mbstring pdo_mysql pdo_pgsql pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.4-apache-trixie
ENV APACHE_DOCUMENT_ROOT=/var/www/html/httpdocs
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libonig-dev libpq-dev libsqlite3-dev \
    && docker-php-ext-install intl mbstring pdo_mysql pdo_pgsql pdo_sqlite \
    && a2enmod rewrite headers \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

COPY . /var/www/html/
COPY --from=dependencies /app/vendor /var/www/html/vendor
COPY --from=frontend /build/httpdocs/assets /var/www/html/httpdocs/assets
COPY docker/towerdns-dev-entrypoint.sh /usr/local/bin/towerdns-dev-entrypoint

RUN chmod 755 /usr/local/bin/towerdns-dev-entrypoint \
    && mkdir -p configs data logs cache install \
    && chown -R www-data:www-data configs data logs cache install \
    && chmod 755 httpdocs

ENTRYPOINT ["towerdns-dev-entrypoint"]
