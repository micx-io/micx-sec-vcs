FROM php:8.3-cli-alpine AS base
RUN apk add --no-cache git openssh-client yaml \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS yaml-dev \
 && pecl install yaml-2.2.4 \
 && docker-php-ext-enable yaml \
 && docker-php-ext-install bcmath sockets \
 && apk del .build-deps

FROM base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction --optimize-autoloader

FROM base
RUN addgroup -g 10001 micx && adduser -D -u 10001 -G micx micx \
 && mkdir -p /data /state /run/micx /app \
 && chown micx:micx /data /state /run/micx \
 && chmod 700 /state /run/micx
WORKDIR /app
COPY --from=dependencies /app/vendor ./vendor
COPY src ./src
COPY docker/entrypoint.sh /app/entrypoint.sh
USER 10001:10001
ENTRYPOINT ["sh", "/app/entrypoint.sh"]
