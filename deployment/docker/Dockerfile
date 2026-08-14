FROM composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer-source

FROM php:8.4.24-fpm-alpine3.24@sha256:5992f8b7433fe7fa96dfbf67746c86d6c41bc91e686eac38fe531c72a02e40e4 AS php-base

RUN apk add --no-cache freetype libjpeg-turbo libpng libwebp \
    && apk add --no-cache --virtual .php-build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd opcache pdo_mysql \
    && apk del .php-build-deps \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

FROM php-base AS app-build

COPY --from=composer-source /usr/bin/composer /usr/local/bin/composer
WORKDIR /build/apps/api

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY apps/api/ ./
RUN composer dump-autoload \
    --classmap-authoritative \
    --no-dev \
    --no-interaction \
    && mkdir -p /opt/selfhandler/bootstrap-cache \
    && cp -a bootstrap/cache/. /opt/selfhandler/bootstrap-cache/ \
    && rm -f bootstrap/cache/*.php \
    && rm -rf node_modules tests

FROM php-base AS app-runtime

ARG SOURCE_REVISION=0000000000000000000000000000000000000000
LABEL org.opencontainers.image.source="https://github.com/PanyaPrimal/selfHandlerApp" \
      org.opencontainers.image.revision="${SOURCE_REVISION}"

WORKDIR /app
COPY --from=app-build --chown=82:82 /build/apps/api/ /app/
COPY --from=app-build /opt/selfhandler/bootstrap-cache/ /opt/selfhandler/bootstrap-cache/
COPY deployment/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-selfhandler.conf
COPY deployment/docker/app-entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod 0555 /usr/local/bin/app-entrypoint \
    && find /app -type d -exec chmod 0555 {} + \
    && find /app -type f -exec chmod 0444 {} + \
    && chmod 0750 /app/storage/app/private \
    && find /opt/selfhandler/bootstrap-cache -type d -exec chmod 0555 {} + \
    && find /opt/selfhandler/bootstrap-cache -type f -exec chmod 0444 {} +

USER 82:82
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php-fpm", "-F"]

FROM node:22-alpine3.24@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32 AS web-build

WORKDIR /build/apps/web
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci --ignore-scripts
COPY apps/web/ ./
RUN npm run build

FROM nginxinc/nginx-unprivileged:1.29.8-alpine@sha256:0c79d56aee561a1d81c63f00eee5fb5fe29279560cdc55e91425133104c7fbe6 AS web-runtime

ARG SOURCE_REVISION=0000000000000000000000000000000000000000
LABEL org.opencontainers.image.source="https://github.com/PanyaPrimal/selfHandlerApp" \
      org.opencontainers.image.revision="${SOURCE_REVISION}"

COPY deployment/docker/nginx.conf /etc/nginx/nginx.conf
COPY --from=web-build --chown=101:101 /build/apps/web/dist/ /usr/share/nginx/html/

USER 101:101
EXPOSE 8080
ENTRYPOINT []
CMD ["nginx", "-g", "daemon off;"]
