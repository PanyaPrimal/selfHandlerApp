import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const webDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const apiDir = path.resolve(webDir, '../api')
const e2eDatabase = path.join(apiDir, 'database', 'e2e.sqlite')

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
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: e2eDatabase,
      CACHE_STORE: 'array',
      SESSION_DRIVER: 'array',
      QUEUE_CONNECTION: 'sync',
      MAIL_MAILER: 'array',
    },
  })
}
