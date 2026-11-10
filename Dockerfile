FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.4-cli-alpine
RUN apk add --no-cache --virtual .build $PHPIZE_DEPS openssl-dev \
 && pecl install mongodb && docker-php-ext-enable mongodb \
 && apk del .build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs \
 && chmod +x docker-entrypoint.sh
ENV APP_ENV=production APP_DEBUG=false PHP_CLI_SERVER_WORKERS=8
EXPOSE 8000
ENTRYPOINT ["./docker-entrypoint.sh"]
