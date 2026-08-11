import { expect, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const apiDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../api')
const e2eDatabase = path.join(apiDir, 'database', 'e2e.sqlite')

export interface TestCredentials {
  name: string
  email: string
  password: string
}

/**
 * Create a single-use invite code directly in the e2e database so a UI
 * registration can consume it. Registration is invite-only.
 */
export function createInviteCode(): string {
  const code = `E2E-${randomUUID().slice(0, 4).toUpperCase()}-${randomUUID().slice(0, 4).toUpperCase()}`

  execFileSync(
    'php',
    ['artisan', 'tinker', '--execute', `\\App\\Models\\Invitation::create(['code' => '${code}']);`],
    {
      cwd: apiDir,
      stdio: 'ignore',
      env: {
        ...process.env,
        APP_ENV: 'testing',
        APP_KEY: 'base64:8mx6/PHn6hHX2o4bOMOlPxpdrJeWHdxklSX7Z92ro8Q=',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: e2eDatabase,
      },
    },
  )

  return code
}

export function uniqueCredentials(testInfo: TestInfo, label: string): TestCredentials {
  const project = testInfo.project.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')
  const suffix = randomUUID().slice(0, 12)

  return {
    name: `${label} ${project}`,
    email: `${label.toLowerCase()}-${project}-${suffix}@example.test`,
    password: `SelfHandler ${suffix} passphrase!`,
  }
}

export async function registerViaUi(
  page: Page,
  credentials: TestCredentials,
  options: { emailInput?: string; redirectTo?: string } = {},
): Promise<void> {
  const registerUrl = options.redirectTo
    ? `/register?redirect=${encodeURIComponent(options.redirectTo)}`
    : '/register'

  const inviteCode = createInviteCode()

  await page.goto(registerUrl)
  await expect(page.getByRole('heading', { name: 'Create your account' })).toBeVisible()
  await page.getByLabel('Invite code').fill(inviteCode)
  await page.getByLabel('Display name').fill(credentials.name)
  await page.getByLabel('Email').fill(options.emailInput ?? credentials.email)
  await page.getByLabel('Password', { exact: true }).fill(credentials.password)
  await page.getByLabel('Confirm password').fill(credentials.password)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(options.redirectTo ?? '/')
}

export async function loginViaUi(
  page: Page,
  credentials: Pick<TestCredentials, 'email' | 'password'>,
  redirectTo = '/',
): Promise<void> {
  await page.goto(`/login?redirect=${encodeURIComponent(redirectTo)}`)
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible()
  await page.getByLabel('Email').fill(credentials.email)
  await page.getByLabel('Password').fill(credentials.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(redirectTo)
}

export async function logoutViaUi(page: Page): Promise<void> {
  await page.goto('/account')
  await expect(page.getByRole('heading', { name: 'Your personal baseline' })).toBeVisible()
  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page).toHaveURL('/login')
}

export async function xsrfHeader(page: Page): Promise<Record<string, string>> {
  await page.request.get('/sanctum/csrf-cookie', {
    headers: { Accept: 'application/json' },
  })

  const cookies = await page.context().cookies()
  const cookie = cookies.find(({ name }) => name === 'XSRF-TOKEN')

  if (!cookie) {
    throw new Error('XSRF-TOKEN cookie was not initialized')
  }

  let token = cookie.value

  try {
    token = decodeURIComponent(token)
  } catch {
    // Playwright may already expose the decoded value.
  }

  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Origin: new URL(page.url()).origin,
    Referer: `${new URL(page.url()).origin}/`,
    'X-XSRF-TOKEN': token,
  }
}
