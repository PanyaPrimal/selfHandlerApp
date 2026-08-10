import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const webDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const apiDir = path.resolve(webDir, '../api')
const e2eDatabase = path.join(apiDir, 'database', 'e2e.sqlite')
const apiUrl = 'http://127.0.0.1:18110'
const webOrigin = '127.0.0.1:15183'

export default function globalSetup(): void {
  fs.mkdirSync(path.dirname(e2eDatabase), { recursive: true })
  fs.closeSync(fs.openSync(e2eDatabase, 'w'))

  execFileSync('php', ['artisan', 'migrate:fresh', '--force'], {
    cwd: apiDir,
    stdio: 'inherit',
    env: {
      ...process.env,
      APP_ENV: 'testing',
      APP_KEY: 'base64:8mx6/PHn6hHX2o4bOMOlPxpdrJeWHdxklSX7Z92ro8Q=',
      APP_URL: apiUrl,
      APP_TIMEZONE: 'UTC',
      SELFHANDLER_TIMEZONE: 'UTC',
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: e2eDatabase,
      CACHE_STORE: 'database',
      SESSION_DRIVER: 'database',
      SESSION_COOKIE: 'selfhandler_session',
      SESSION_SECURE_COOKIE: 'false',
      SESSION_HTTP_ONLY: 'true',
      SESSION_SAME_SITE: 'lax',
      SANCTUM_STATEFUL_DOMAINS: webOrigin,
      AUTH_REGISTRATION_ATTEMPTS: '100',
      QUEUE_CONNECTION: 'sync',
      MAIL_MAILER: 'array',
    },
  })
}
