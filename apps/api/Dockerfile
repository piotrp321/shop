# syntax=docker/dockerfile:1

# Stages: runtime (FrankenPHP and PHP configuration) -> base (+ Composer and the dependency-installing entrypoint) ->
# build (production dependencies and a warmed `prod` cache) -> prod (runtime + the built application). `dev` is the last
# stage, so the Compose build (`target: dev`, and CI) is unchanged; production images are built with `--target prod`.

# Pinned: FrankenPHP 1.13.4 on PHP 8.5.12 (ZTS build, Debian trixie).
FROM dunglas/frankenphp:1.13.4-php8.5.12-trixie AS runtime

# gmp: web-token converts every RSA JWK to PEM with brick/math before openssl signs or verifies; without gmp the
# conversion runs in pure PHP (about 0.2 s per access token verification, 0.6 s per signature).
RUN install-php-extensions pdo_pgsql amqp redis intl opcache pcntl gmp

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# Tools for installing dependencies: the development image and the build stage. Never in production.
FROM runtime AS base

# Composer is a tool here, not a project dependency.
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

# Setting ENTRYPOINT resets the CMD inherited from runtime, so CMD is repeated.
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# Production dependencies (no dev packages, optimized classmap) and the application with a warmed `prod` cache.
# No secrets and no services are needed: the container reads its configuration from the environment at run time, and
# the cache holds only references to the environment variables, which a bogus value here would not change.
FROM base AS build

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
# The warmup builds the Doctrine entity managers, which read the connection parameters; it does not connect. The
# values below exist only for this command (not in the image environment) and are not stored in the cache.
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && SHOP_DB_HOST=warmup SHOP_DB_NAME=warmup SHOP_DB_USER=warmup SHOP_DB_PASSWORD=warmup \
        bin/console cache:warmup --env=prod \
    && rm -rf var/log docker

# Production image: FrankenPHP with the built application and without Composer, git, SPX and dev packages. The
# container starts FrankenPHP directly (no entrypoint installing dependencies). Opcache never checks file timestamps:
# the code in the image does not change.
FROM runtime AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY docker/php/conf.d/prod.ini $PHP_INI_DIR/conf.d/zz-prod.ini
COPY --from=build /app /app

# Development image: adds the SPX profiler, built from a pinned release tag (ZTS-aware).
FROM base AS dev

ARG SPX_VERSION=v0.4.22

RUN apt-get update \
    && apt-get install -y --no-install-recommends zlib1g-dev \
    && rm -rf /var/lib/apt/lists/* \
    && git clone --depth 1 --branch "$SPX_VERSION" https://github.com/NoiseByNorthwest/php-spx.git /tmp/php-spx \
    && cd /tmp/php-spx \
    && phpize \
    && ./configure \
    && make -j"$(nproc)" \
    && make install \
    && rm -rf /tmp/php-spx

COPY docker/php/conf.d/spx.ini $PHP_INI_DIR/conf.d/zz-spx.ini
