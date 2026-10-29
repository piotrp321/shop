# syntax=docker/dockerfile:1

# Pinned: FrankenPHP 1.13.4 on PHP 8.5.12 (ZTS build, Debian trixie).
FROM dunglas/frankenphp:1.13.4-php8.5.12-trixie AS base

# Composer is a tool here, not a project dependency.
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql amqp redis intl opcache pcntl

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /app
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

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
