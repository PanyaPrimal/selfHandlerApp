# API

Laravel API application for SelfHandler.

## Current State

The Laravel skeleton is present.

Local backend development for this app is based on Open Server, not Docker.

## Next Step

1. Use PHP 8.4 to install Composer dependencies.
2. Copy `.env.example` to `.env`.
3. Generate the Laravel app key.
4. Configure a local Open Server project that points the web root to `apps/api/public`.
5. Connect the app to the active Open Server MySQL instance.

## Useful Commands

Select PHP 8.4 in Open Server and ensure the active shell resolves the same runtime:

```bat
php --version
composer install
```

Then:

```bat
php artisan key:generate
php artisan migrate
```
