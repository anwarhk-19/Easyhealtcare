#!/usr/bin/env bash
# Runs EasyHealthcare with PHP only (no Node/npm). Requires PHP 8.3+ and Composer.
set -e
cd "$(dirname "$0")/backend"
[ -d vendor ] || composer install --no-interaction
[ -f .env ] || { cp .env.example .env; php artisan key:generate; }
[ -f database/database.sqlite ] || { touch database/database.sqlite; php artisan migrate --seed --force; }
echo "Open http://localhost:8000  (login: owner@demo.clinic / password)"
php artisan serve --port=8000
