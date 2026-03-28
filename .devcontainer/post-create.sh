#!/usr/bin/env bash
# postCreateCommand – runs once after the container is first created.
# Keeps the workspace .env untouched; only creates it when absent.
set -e

echo "==> Installing PHP dependencies..."
composer install --no-interaction --prefer-dist

echo "==> Installing Node dependencies..."
npm install

# Create .env only when it does not exist yet.
if [ ! -f .env ]; then
    echo "==> .env not found – copying .env.example..."
    cp .env.example .env
    echo "==> Generating application key..."
    php artisan key:generate --ansi
    echo ""
    echo "NOTE: .env was created from .env.example."
    echo "      The default DB_CONNECTION is 'mysql'. For SQLite-only dev,"
    echo "      set DB_CONNECTION=sqlite and DB_DATABASE=:memory:"
    echo "      (PHPUnit tests already use :memory: SQLite via phpunit.xml)."
fi

echo ""
echo "==> Dev Container ready. Useful commands:"
echo "    php artisan serve              # start Laravel on :8000"
echo "    ./vendor/bin/phpunit           # run tests"
echo "    ./vendor/bin/php-cs-fixer fix  # format PHP code"
echo "    npm run build                  # build frontend assets"
echo "    npm run dev                    # start Vite dev server"
