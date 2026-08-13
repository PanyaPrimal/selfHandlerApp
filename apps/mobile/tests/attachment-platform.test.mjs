import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const packageJson = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'))
const webPackage = JSON.parse(await readFile(new URL('../../web/package.json', import.meta.url), 'utf8'))

test('camera filesystem and file transfer stay synchronized in web and native packages', () => {
  for (const [dependency, version] of [
    ['@capacitor/camera', /^\^?8\./],
    ['@capacitor/filesystem', /^\^?8\./],
    ['@capacitor/file-transfer', /^\^?2\./],
  ]) {
    assert.match(packageJson.dependencies[dependency], version)
    assert.match(webPackage.dependencies[dependency], version)
  }
})

test('shared attachment transport streams URI files and does not read them into base64', async () => {
  const source = await readFile(new URL('../../web/src/attachments/platform.ts', import.meta.url), 'utf8')
  assert.match(source, /FileTransfer|uploadFile/)
  assert.match(source, /path:\s*source\.uri/)
  assert.doesNotMatch(source, /readFile\s*\(/)
  assert.doesNotMatch(source, /base64String|DataUrl|Base64/)
})

test('native private previews use cache and expose deterministic cleanup', async () => {
  const source = await readFile(new URL('../../web/src/attachments/platform.ts', import.meta.url), 'utf8')
  assert.match(source, /Directory\.Cache/)
  assert.match(source, /downloadFile/)
  assert.match(source, /deleteFile/)
  assert.match(source, /convertFileSrc/)
})

test('restored Camera activities require an explicit foreground upload action', async () => {
  const platform = await readFile(new URL('../../web/src/attachments/platform.ts', import.meta.url), 'utf8')
  const runtime = await readFile(new URL('../../web/src/mobile/android-shell.ts', import.meta.url), 'utf8')
  const uploader = await readFile(new URL('../../web/src/components/attachments/AttachmentUploader.vue', import.meta.url), 'utf8')

  assert.match(runtime, /appRestoredResult/)
  assert.match(platform, /restore\(result\)/)
  assert.match(uploader, /consumeRestoredAttachment/)
  assert.doesNotMatch(platform, /offline[^\n]*(queue|upload)/i)
})
