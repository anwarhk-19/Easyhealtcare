@echo off
rem Runs EasyHealthcare with PHP only (no Node/npm). Requires PHP 8.3+ and Composer.
cd /d "%~dp0backend"
if not exist vendor call composer install --no-interaction
if not exist .env (copy .env.example .env && php artisan key:generate)
if not exist database\database.sqlite (type nul > database\database.sqlite && php artisan migrate --seed --force)
echo Open http://localhost:8000  (login: owner@demo.clinic / password)
php artisan serve --port=8000
