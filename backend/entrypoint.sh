#!/bin/bash
set -e

# Check if Laravel's artisan file exists
if [ -f "artisan" ]; then
    # Install dependencies if vendor folder doesn't exist
    if [ ! -d "vendor" ] && [ -f "composer.json" ]; then
        echo "🌶️  Installing Composer dependencies..."
        composer install --no-interaction --prefer-dist
    fi

    # Create .env from example if not exists
    if [ ! -f ".env" ] && [ -f ".env.example" ]; then
        echo "🌶️  Creating Laravel .env file..."
        cp .env.example .env
    fi

    # Wait for PostgreSQL database to be online
    echo "🌶️  Checking Postgres connection..."
    until nc -z -v -w5 postgres 5432; do
        echo "🌶️  Waiting for Postgres to start on host 'postgres' port 5432..."
        sleep 2
    done
    echo "🌶️  Postgres is up!"

    # Generate APP_KEY if it is not set
    if [ -f ".env" ]; then
        if ! grep -q "APP_KEY=base64:" ".env"; then
            echo "🌶️  Generating application key..."
            php artisan key:generate
        fi
    fi

    # Laravel's writable dirs aren't in the repo: compiled views (e-mails), file cache, sessions.
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views

    # Run migrations
    echo "🌶️  Running migrations..."
    php artisan migrate --force --seed
else
    echo "⚠️  Laravel codebase not found in /var/www/html."
    echo "👉 To initialize a new Laravel project, please run:"
    echo "   docker compose exec backend composer create-project laravel/laravel ."
    echo ""
    echo "🌶️  Keeping container alive so you can run installation commands..."
    exec tail -f /dev/null
fi

# Execute the container's main command
echo "🌶️  Starting container command: $@"
exec "$@"
