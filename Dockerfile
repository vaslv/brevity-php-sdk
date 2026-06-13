# Test/dev runtime for the Brevity PHP SDK.
#
# PHP 8.4 matches the locked dev toolchain in composer.lock
# (doctrine/instantiator ^8.4, illuminate/support v12, phpunit 9.6).
# The library itself still targets PHP >= 7.1 — this image only runs the suite.
FROM php:8.4-cli

# git + unzip let Composer fetch and extract dist packages without ext-zip.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Composer from its official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /app

# Install dependencies (from the committed lock) and run the tests.
CMD ["sh", "-c", "composer install --no-interaction --prefer-dist && vendor/bin/phpunit"]
