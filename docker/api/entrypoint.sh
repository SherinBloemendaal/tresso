#!/bin/sh
set -e

# COMPOSER=composer.local.json selects an add-on install (own lock file); the default is composer.json.
COMPOSER_FILE="${COMPOSER:-composer.json}"
COMPOSER_LOCK="${COMPOSER_FILE%.json}.lock"
if [ ! -f vendor/autoload_runtime.php ] || [ ! -f "$COMPOSER_LOCK" ] || [ "$COMPOSER_LOCK" -nt vendor/composer/installed.json ] || [ "$COMPOSER_FILE" -nt "$COMPOSER_LOCK" ]; then
    composer install --prefer-dist --no-progress --no-interaction
fi

# OAuth2 signing keys are generated once per environment and never committed.
if [ ! -f config/jwt/private.pem ]; then
    # Resolve the passphrase the same way Symfony does (real env first, then the .env files).
    OAUTH_PASSPHRASE="${OAUTH_PASSPHRASE:-$(php -r 'require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env"); echo $_SERVER["OAUTH_PASSPHRASE"];')}"
    export OAUTH_PASSPHRASE
    mkdir -p config/jwt
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 \
        -aes-256-cbc -pass env:OAUTH_PASSPHRASE -out config/jwt/private.pem
    openssl pkey -in config/jwt/private.pem -passin env:OAUTH_PASSPHRASE -pubout -out config/jwt/public.pem
fi

if [ "$1" = "frankenphp" ] && [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console app:oauth:bootstrap-clients --no-interaction
fi

exec docker-php-entrypoint "$@"
