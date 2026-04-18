# ── Stage 1: PHP dependencies ─────────────────────────────────────────────────
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ── Stage 2: frontend assets ──────────────────────────────────────────────────
FROM node:22-alpine AS assets

WORKDIR /app

COPY package*.json ./
RUN npm ci

# Vendor must be present so Vite can resolve vendor CSS (e.g. livewire/flux)
COPY --from=vendor /app/vendor ./vendor

COPY . .
RUN npm run build

# ── Stage 3: production image ─────────────────────────────────────────────────
FROM php:8.4-fpm-alpine AS production

LABEL maintainer="Coupon Processing System"

# System deps
RUN apk add --no-cache \
    bash \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    linux-headers \
    autoconf \
    g++ \
    make

# PHP extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache

# phpredis
RUN pecl install redis \
 && docker-php-ext-enable redis \
 && apk del autoconf g++ make linux-headers

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/app.ini"
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Copy vendored PHP deps
COPY --from=vendor /app/vendor ./vendor

# Copy built frontend assets
COPY --from=assets /app/public/build ./public/build

# Copy application source
COPY . .

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
 && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
