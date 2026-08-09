# Open Server Workflow

## Why We Use It

The backend is intentionally developed through Open Server so the project doubles as hands-on Laravel and PHP learning on Windows.

## Local Setup Model

- Repo code stays in `C:\Code\PET\selfHandlerApp`
- Laravel app lives in `apps/api`
- Open Server provides PHP, web server, and MySQL
- Vue frontend continues to run as a separate Vite app from `apps/web`

## Expected Backend Shape

- Project type: PHP project in Open Server
- App root: `C:\Code\PET\selfHandlerApp\apps\api`
- Web root: `C:\Code\PET\selfHandlerApp\apps\api\public`
- Suggested local domain: `selfhandler-api.local`

## Commands

Install dependencies:

```bat
php --version
composer install
```

Initial Laravel setup:

```bat
copy .env.example .env
php artisan key:generate
php artisan migrate
```

## Notes

- PHP 8.4 is available on this machine and matches the production runtime.
- Select PHP 8.4 in Open Server and run Composer from a shell whose `php` command resolves that runtime.
- Redis can stay optional for the first MVP.
- Docker is not the default local backend path for this project right now.
