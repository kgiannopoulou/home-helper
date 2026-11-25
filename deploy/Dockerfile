# One image for the whole household server: the Laravel API + web dashboard, the PWA,
# and HTTPS (FrankenPHP has Caddy built in, which gets Let's Encrypt certificates on its own).
# The same image also runs the queue worker and the scheduler (see compose.yaml).
# Multi-arch: builds on the Oracle Always Free Ampere (ARM64) VM as well as on x86.
#
# Build context = the repository root:  docker compose -f deploy/compose.yaml build

# --- 1. The PWA (Expo web export), pointed at this server's API --------------------------
FROM node:22-bookworm-slim AS pwa
ARG API_URL
WORKDIR /mobile
COPY mobile/package.json mobile/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY mobile/ ./
ENV EXPO_PUBLIC_API_URL=${API_URL} \
    EXPO_PUBLIC_ENABLE_AI=0
RUN npx expo export -p web && node scripts/pwa.mjs

# --- 2. PHP dependencies -------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY server/composer.json server/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

# --- 3. PHP runtime shared by the asset build and the final image ------------------------
FROM dunglas/frankenphp:1-php8.4-bookworm AS base
COPY deploy/php.ini /usr/local/etc/php/conf.d/home-helper.ini
RUN install-php-extensions pdo_mysql redis bcmath intl gd zip pcntl opcache \
 && apt-get update \
 && apt-get install -y --no-install-recommends default-mysql-client tesseract-ocr tesseract-ocr-ell \
 && rm -rf /var/lib/apt/lists/* \
 && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
WORKDIR /app

# --- 4. Dashboard assets. The Wayfinder Vite plugin runs artisan, so this needs PHP and Node 22
FROM base AS assets
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
COPY server/ ./
COPY --from=vendor /app/vendor ./vendor
# A throwaway .env only for this step (an app key, no database)
RUN cp .env.example .env \
 && php artisan package:discover \
 && php artisan key:generate --force \
 && npm ci --no-audit --no-fund \
 && npm run build

# --- 5. The final image -------------------------------------------------------------------
FROM base AS app
COPY server/ ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --from=pwa /mobile/dist /srv/pwa
COPY deploy/Caddyfile /etc/frankenphp/Caddyfile
COPY deploy/entrypoint.sh /usr/local/bin/home-helper-entrypoint
RUN chmod +x /usr/local/bin/home-helper-entrypoint \
 && php artisan package:discover \
 && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["home-helper-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
