# Build from the repository root: docker build -f shops/laravel/Dockerfile .
FROM node:22-alpine AS widget-build
WORKDIR /repo/packages/storefront-price-widget
COPY packages/storefront-price-widget/package.json ./package.json
COPY packages/storefront-price-widget/package-lock.json ./package-lock.json
RUN npm ci --no-audit --no-fund
COPY packages/storefront-price-widget/ ./
COPY services/core/data/translations/storefront-source/ /repo/services/core/data/translations/storefront-source/
RUN npm run build

FROM composer:2 AS php-dependencies
WORKDIR /var/www
COPY shops/laravel/composer.json shops/laravel/composer.lock ./shops/laravel/
COPY packages/api-client-php/ ./packages/api-client-php/
WORKDIR /var/www/shops/laravel
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

FROM php:8.4-cli-alpine
# The official PHP image already provides the required SDK/Laravel extensions.
RUN php -r 'foreach (["curl", "mbstring", "openssl", "fileinfo", "pdo", "session", "tokenizer", "xml", "dom"] as $extension) { if (!extension_loaded($extension)) { exit(1); } }'
WORKDIR /var/www/app
COPY shops/laravel/composer.json shops/laravel/composer.lock ./
COPY shops/laravel/artisan ./artisan
COPY shops/laravel/app/ ./app/
COPY shops/laravel/bootstrap/app.php ./bootstrap/app.php
COPY shops/laravel/bootstrap/providers.php ./bootstrap/providers.php
COPY shops/laravel/config/ ./config/
COPY shops/laravel/public/ ./public/
COPY shops/laravel/resources/ ./resources/
COPY shops/laravel/routes/ ./routes/
COPY --from=php-dependencies /var/www/shops/laravel/vendor ./vendor
COPY --from=widget-build /repo/packages/storefront-price-widget/dist/rs-storefront-widget.js ./public/assets/rs-storefront-widget.js
COPY --from=widget-build /repo/packages/storefront-price-widget/theme/default.css ./public/assets/storefront-theme.css
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
ENV APP_ENV=production APP_DEBUG=false CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync
EXPOSE 8080
USER www-data
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
