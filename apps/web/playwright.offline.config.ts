import { defineConfig } from '@playwright/test'
import base from './playwright.config'

const servers = Array.isArray(base.webServer) ? base.webServer : []
export default defineConfig({
  ...base,
  testDir: './e2e-built',
  webServer: [servers[0]!, { ...servers[1]!, command: 'npm run preview -- --host 127.0.0.1 --port 15183' }],
})
