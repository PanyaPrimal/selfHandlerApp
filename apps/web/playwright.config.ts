import { defineConfig, devices } from '@playwright/test'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const webDir = path.dirname(fileURLToPath(import.meta.url))
const apiDir = path.resolve(webDir, '../api')
const apiPort = 18000
const webPort = 15173
const apiUrl = `http://127.0.0.1:${apiPort}`
const webUrl = `http://127.0.0.1:${webPort}`
const e2eDatabase = path.join(apiDir, 'database', 'e2e.sqlite')

const apiEnv = {
  APP_ENV: 'testing',
  APP_KEY: 'base64:8mx6/PHn6hHX2o4bOMOlPxpdrJeWHdxklSX7Z92ro8Q=',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: e2eDatabase,
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
  QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'array',
}

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  fullyParallel: false,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: webUrl,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: [
    {
      command: `php artisan serve --host=127.0.0.1 --port=${apiPort}`,
      cwd: apiDir,
      env: apiEnv,
      url: `${apiUrl}/up`,
      reuseExistingServer: false,
      timeout: 120_000,
    },
    {
      command: `npm run dev -- --host 127.0.0.1 --port ${webPort}`,
      cwd: webDir,
      env: {
        VITE_API_PROXY_TARGET: apiUrl,
      },
      url: webUrl,
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
  projects: [
    {
      name: 'desktop',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1366, height: 900 },
      },
    },
    {
      name: 'mobile',
      use: {
        ...devices['Pixel 7'],
      },
    },
  ],
})
