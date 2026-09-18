import { expect, test } from '@playwright/test'
import { loginViaUi, logoutViaUi, registerViaUi, uniqueCredentials } from './support/auth'

test.use({ launchOptions: { args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] } })

test('microphone audio survives an offline reload and stays private across account switches', async ({ page, context }, info) => {
  await context.grantPermissions(['microphone'])
  const first = uniqueCredentials(info, 'VoiceOwner')
  await registerViaUi(page, first, { redirectTo: '/mentor' })
  await expect(page.getByLabel('Message or transcript')).toBeEnabled()
  await page.route(/^https?:\/\/[^/]+\/api\//, route => route.abort('internetdisconnected'))
  await page.getByRole('button', { name: 'Record voice', exact: true }).click()
  await expect(page.getByRole('button', { name: /^Stop ·/ })).toBeVisible()
  await expect.poll(() => page.evaluate(async () => {
    const databasePath = '/src/offline/database.ts'
    const workspacePath = '/src/offline/workspace.ts'
    const { localRead } = await import(/* @vite-ignore */ databasePath)
    const { workspaceState } = await import(/* @vite-ignore */ workspacePath)
    return (await localRead(`account:${workspaceState.owner}:mentor-draft`))?.audio?.size ?? 0
  })).toBeGreaterThan(0)
  await page.getByRole('button', { name: /^Stop ·/ }).click()
  await expect(page.locator('audio')).toBeVisible()
  await page.reload()
  await expect(page.locator('audio')).toBeVisible()
  await expect.poll(() => page.locator('audio').evaluate((audio: HTMLAudioElement) => audio.readyState)).toBeGreaterThanOrEqual(1)
  await page.unroute(/^https?:\/\/[^/]+\/api\//)
  await logoutViaUi(page)
  await registerViaUi(page, uniqueCredentials(info, 'VoiceOther'), { redirectTo: '/mentor' })
  await expect(page.getByLabel('Message or transcript')).toBeEnabled()
  await expect(page.locator('audio')).toHaveCount(0)
  await logoutViaUi(page)
  await loginViaUi(page, first, '/mentor')
  await expect(page.locator('audio')).toBeVisible()
})
