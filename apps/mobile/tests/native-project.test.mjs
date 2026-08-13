import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import test from 'node:test'

const mobile = resolve(import.meta.dirname, '..')

test('Capacitor config embeds the shared build and never a remote server URL', () => {
  const config = readFileSync(resolve(mobile, 'capacitor.config.ts'), 'utf8')
  assert.match(config, /webDir:\s*['"]\.\.\/web\/dist['"]/)
  assert.doesNotMatch(config, /server\s*:/)
  assert.match(config, /appId:\s*['"]app\.selfhandler\.mobile['"]/)
})

test('Android vault, manifest security, plugins, resources, and Gradle wrapper are versioned', () => {
  const activity = readFileSync(resolve(
    mobile,
    'android/app/src/main/java/app/selfhandler/mobile/MainActivity.java',
  ), 'utf8')
  const vault = readFileSync(resolve(
    mobile,
    'android/app/src/main/java/app/selfhandler/mobile/MobileCredentialVaultPlugin.java',
  ), 'utf8')
  const manifest = readFileSync(resolve(mobile, 'android/app/src/main/AndroidManifest.xml'), 'utf8')
  const capacitorSettings = readFileSync(resolve(mobile, 'android/capacitor.settings.gradle'), 'utf8')

  assert.match(activity, /registerPlugin\(MobileCredentialVaultPlugin\.class\)/)
  assert.match(vault, /AndroidKeyStore/)
  assert.match(vault, /AES\/GCM\/NoPadding/)
  assert.doesNotMatch(vault, /localStorage|sessionStorage|Log\./)
  assert.match(manifest, /android:allowBackup="false"/)
  assert.match(manifest, /android:windowSoftInputMode="adjustResize"/)
  assert.doesNotMatch(manifest, /SCHEDULE_EXACT_ALARM/)
  assert.doesNotMatch(manifest, /READ_EXTERNAL_STORAGE|WRITE_EXTERNAL_STORAGE|READ_MEDIA_IMAGES/)
  for (const plugin of ['capacitor-camera', 'capacitor-file-transfer', 'capacitor-filesystem']) {
    assert.match(capacitorSettings, new RegExp(`include ':${plugin}'`))
  }
  assert.ok(existsSync(resolve(mobile, 'android/gradlew.bat')))
  assert.ok(existsSync(resolve(mobile, 'android/app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml')))
  assert.ok(existsSync(resolve(mobile, 'android/keystore.properties.example')))
})
