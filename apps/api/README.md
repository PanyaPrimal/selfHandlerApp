# API

Laravel API application for SelfHandler.

## Current State

The Laravel skeleton is present.

Local backend development for this app is based on Open Server, not Docker.

## Next Step

1. Use Open Server PHP 8.3 to install Composer dependencies.
2. Copy `.env.example` to `.env`.
3. Generate the Laravel app key.
4. Configure a local Open Server project that points the web root to `apps/api/public`.
5. Connect the app to the active Open Server MySQL instance.

## Useful Commands

Use the Open Server PHP executable directly:

```bat
C:\OSPanel\modules\PHP-8.3\php.exe C:\OSPanel\data\PHP-8.3\default\composer\composer.phar install
```

Then:

```bat
C:\OSPanel\modules\PHP-8.3\php.exe artisan key:generate
C:\OSPanel\modules\PHP-8.3\php.exe artisan migrate
```
