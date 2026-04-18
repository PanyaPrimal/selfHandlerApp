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
C:\OSPanel\modules\PHP-8.3\php.exe C:\OSPanel\data\PHP-8.3\default\composer\composer.phar install
```

Initial Laravel setup:

```bat
copy .env.example .env
C:\OSPanel\modules\PHP-8.3\php.exe artisan key:generate
C:\OSPanel\modules\PHP-8.3\php.exe artisan migrate
```

## Notes

- Open Server already contains PHP 8.3 on this machine.
- The Open Server `composer.bat` expects PHP to be available in its environment, so the most reliable path is invoking `php.exe` with `composer.phar` directly.
- Redis can stay optional for the first MVP.
- Docker is not the default local backend path for this project right now.
