import { expect, test, type Page } from '@playwright/test'
import {
  createInviteCode,
  loginViaUi,
  logoutViaUi,
  registerViaUi,
  uniqueCredentials,
  xsrfHeader,
} from './support/auth'
import { gotoDestination } from './interface/support'

async function expectRedirect(page: Page, path: string, redirect: string): Promise<void> {
  await expect(page).toHaveURL((url) => url.pathname === path && url.searchParams.get('redirect') === redirect)
}

test('registration normalizes identity, restores the session, and rejects a duplicate', async ({ page }, testInfo) => {
  const credentials = uniqueCredentials(testInfo, 'Registration')
  const mixedEmail = `  ${credentials.email.toUpperCase()}  `

  await registerViaUi(page, credentials, { emailInput: mixedEmail })
  await expect(page.getByRole('heading', { name: new RegExp(credentials.name) })).toBeVisible()

  await page.getByRole('link', { name: 'Routines', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'No routines yet' })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Repeatable actions' })).toBeVisible()

  await gotoDestination(page, 'Account')
  await expect(page.locator('.content-shell').getByText(credentials.email)).toBeVisible()
  await logoutViaUi(page)

  await page.goto('/register')
  await page.getByLabel('Invite code').fill(createInviteCode())
  await page.getByLabel('Display name').fill('Duplicate account')
  await page.getByLabel('Email').fill(mixedEmail)
  await page.getByLabel('Password', { exact: true }).fill(credentials.password)
  await page.getByLabel('Confirm password').fill(credentials.password)
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page.getByLabel('Email')).toHaveAttribute('aria-invalid', 'true')
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('')
  await expect(page.getByLabel('Confirm password')).toHaveValue('')
})

test('protected deep links support generic login, reload restoration, and invalidating logout', async ({ page }, testInfo) => {
  const credentials = uniqueCredentials(testInfo, 'Lifecycle')

  await page.goto('/goals')
  await expectRedirect(page, '/login', '/goals')
  await page.getByRole('link', { name: 'Create account' }).click()
  await expectRedirect(page, '/register', '/goals')
  await page.getByLabel('Invite code').fill(createInviteCode())
  await page.getByLabel('Display name').fill(credentials.name)
  await page.getByLabel('Email').fill(credentials.email)
  await page.getByLabel('Password', { exact: true }).fill(credentials.password)
  await page.getByLabel('Confirm password').fill(credentials.password)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL('/goals')

  await logoutViaUi(page)
  await page.goto('/login?redirect=%2Freview')
  await page.getByLabel('Email').fill(credentials.email)
  await page.getByLabel('Password').fill('incorrect password value')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByText('The email or password is incorrect.')).toBeVisible()
  await expect(page.getByText(/does not exist|not registered/i)).toHaveCount(0)
  await expect(page.getByLabel('Password')).toHaveValue('')

  await page.getByLabel('Password').fill(credentials.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/review')
  await page.reload()
  await expect(page.getByRole('heading', { name: /\d{1,2} [A-Z][a-z]{2} \d{4}/ })).toBeVisible()

  await logoutViaUi(page)
  const oldSessionResponse = await page.request.get('/api/today', {
    headers: { Accept: 'application/json' },
  })
  expect(oldSessionResponse.status()).toBe(401)
  await page.goto('/routines')
  await expectRedirect(page, '/login', '/routines')
})

test('two accounts stay isolated in simultaneous contexts and after account switching', async ({
  baseURL,
  browser,
  page,
}, testInfo) => {
  const accountA = uniqueCredentials(testInfo, 'AccountA')
  const accountB = uniqueCredentials(testInfo, 'AccountB')
  const routineA = `Private A routine ${Date.now()}`
  const routineB = `Private B routine ${Date.now()}`

  await registerViaUi(page, accountA, { redirectTo: '/routines' })
  await page.getByLabel('Name').fill(routineA)
  await page.getByRole('button', { name: 'Create' }).click()
  await expect(page.getByText(routineA)).toBeVisible()

  const routinesResponse = await page.request.get('/api/routines', {
    headers: {
      Accept: 'application/json',
      Origin: new URL(page.url()).origin,
      Referer: `${new URL(page.url()).origin}/`,
    },
  })
  expect(routinesResponse.status()).toBe(200)
  const routinesPayload = await routinesResponse.json() as { data: Array<{ id: number; name: string }> }
  const routineAId = routinesPayload.data.find(({ name }) => name === routineA)?.id
  expect(routineAId).toBeTruthy()

  const contextB = await browser.newContext({ baseURL })
  const pageB = await contextB.newPage()

  try {
    await registerViaUi(pageB, accountB, { redirectTo: '/routines' })
    await expect(pageB.getByText(routineA)).toHaveCount(0)
    await expect(pageB.getByRole('heading', { name: 'No routines yet' })).toBeVisible()
    await pageB.getByLabel('Name').fill(routineB)
    await pageB.getByRole('button', { name: 'Create' }).click()
    await expect(pageB.getByText(routineB)).toBeVisible()

    await page.reload()
    await expect(page.getByText(routineA)).toBeVisible()
    await expect(page.getByText(routineB)).toHaveCount(0)

    const foreignResponse = await pageB.request.patch(`/api/routines/${routineAId}`, {
      headers: await xsrfHeader(pageB),
      data: {
        name: 'Cross-account mutation',
        user_id: 999999,
      },
    })
    expect(foreignResponse.status()).toBe(404)

    await logoutViaUi(page)
    await loginViaUi(page, accountB, '/routines')
    await expect(page.getByText(routineB)).toBeVisible()
    await expect(page.getByText(routineA)).toHaveCount(0)

    await logoutViaUi(page)
    await loginViaUi(page, accountA, '/routines')
    await expect(page.getByText(routineA)).toBeVisible()
    await expect(page.getByText(routineB)).toHaveCount(0)
  } finally {
    await contextB.close()
  }
})

test('CSRF, validation, rate limits, and unavailable bootstrap are recoverable', async ({
  baseURL,
  browser,
  page,
  request,
}, testInfo) => {
  const credentials = uniqueCredentials(testInfo, 'Failures')

  const csrfRejected = await request.post('/api/auth/register', {
    headers: { Accept: 'application/json' },
    data: {
      name: credentials.name,
      email: credentials.email,
      password: credentials.password,
      password_confirmation: credentials.password,
    },
  })
  expect(csrfRejected.status()).toBe(419)

  await page.goto('/register')
  await page.getByLabel('Display name').fill('A')
  await page.getByLabel('Email').fill('not-an-email')
  await page.getByLabel('Password', { exact: true }).fill('short')
  await page.getByLabel('Confirm password').fill('different')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByLabel('Email')).toHaveAttribute('aria-invalid', 'true')
  await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('aria-invalid', 'true')
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('')
  await expect(page.getByLabel('Confirm password')).toHaveValue('')

  await page.goto('/login')
  await page.getByLabel('Email').fill(credentials.email)
  let rateLimited = false

  for (let attempt = 0; attempt < 12; attempt += 1) {
    await page.getByLabel('Password').fill(`wrong password ${attempt}`)
    const responsePromise = page.waitForResponse((response) => (
      response.url().endsWith('/api/auth/login') && response.request().method() === 'POST'
    ))
    await page.getByRole('button', { name: 'Sign in' }).click()
    const response = await responsePromise

    if (response.status() === 429) {
      rateLimited = true
      break
    }

    expect(response.status()).toBe(422)
    await expect(page.getByLabel('Password')).toHaveValue('')
  }

  expect(rateLimited).toBe(true)
  await expect(page.getByText(/Too many attempts/i)).toBeVisible()
  await expect(page.getByLabel('Password')).toHaveValue('')

  const unavailableContext = await browser.newContext({ baseURL })
  const unavailablePage = await unavailableContext.newPage()

  try {
    await unavailablePage.route('**/api/auth/user', (route) => route.abort('connectionrefused'))
    await unavailablePage.goto('/routines')
    await expect(unavailablePage.getByRole('heading', { name: 'SelfHandler is unavailable' })).toBeVisible()
    await unavailablePage.unroute('**/api/auth/user')
    await unavailablePage.getByRole('button', { name: 'Retry' }).click()
    await expectRedirect(unavailablePage, '/login', '/routines')
  } finally {
    await unavailableContext.close()
  }
})
