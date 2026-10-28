#!/bin/sh
# Installs Composer dependencies when composer.lock changed since the last install.
set -eu

cd /app

if [ -f composer.lock ]; then
  hash="$(sha256sum composer.lock | cut -d' ' -f1)"
  stamp=vendor/.composer-lock-hash
  if [ ! -f vendor/autoload.php ] || [ ! -f "$stamp" ] || [ "$(cat "$stamp")" != "$hash" ]; then
    composer install --no-interaction --prefer-dist --no-progress
    echo "$hash" > "$stamp"
  fi
fi

exec docker-php-entrypoint "$@"
