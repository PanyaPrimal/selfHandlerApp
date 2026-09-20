import { expect, type Page } from '@playwright/test'

export async function openPendingChanges(page: Page): Promise<void> {
  const trigger = page.getByRole('button', { name: 'Pending changes', exact: true })
  if (await trigger.getAttribute('aria-expanded') !== 'true') await trigger.click()
  await expect(page.getByRole('region', { name: 'Pending changes', exact: true })).toBeVisible()
}
