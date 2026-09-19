import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../e2e/support/auth'

test('installed production shell cold-restarts without network and keeps private API data out of CacheStorage', async ({ page, context }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'BuiltOffline'), { redirectTo: '/storage' })
  await expect(page.getByText(/Anything you capture lands here/)).toBeVisible()
  await page.evaluate(async () => { await navigator.serviceWorker.ready })
  await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller))).toBeTruthy()
  // Fetches have completed when Storage's empty state is rendered.
  await context.setOffline(true)
  await page.reload()
  await expect(page.getByRole('form', { name: 'Capture an item' })).toBeVisible()
  const form = page.getByRole('form', { name: 'Capture an item' })
  await form.getByLabel('What is on your mind?').fill('Airplane mode task')
  await form.getByRole('button', { name: 'Capture', exact: true }).click()
  await expect(page.getByText(/^Saved on this device, awaiting synchronization/)).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Airplane mode task', exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByText('Offline · pending changes: 1', { exact: true })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Airplane mode task', exact: true })).toBeVisible()
  const cached = await page.evaluate(async () => {
    const names = await caches.keys()
    return (await Promise.all(names.map(async name => (await (await caches.open(name)).keys()).map(request => request.url)))).flat()
  })
  expect(cached.some(url => url.includes('/api/'))).toBeFalsy()
  await context.setOffline(false)
  await expect(page.getByText(/pending changes: 1/i)).toHaveCount(0, { timeout: 15000 })
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Airplane mode task', exact: true })).toBeVisible()
})
