import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { relative, resolve } from 'node:path'
import { configuredApiOrigin } from './mobile-config.mjs'

const mobile = resolve(import.meta.dirname, '..')
const repository = resolve(mobile, '../..')
const android = resolve(mobile, 'android')
const dist = resolve(mobile, '../web/dist')
const synced = resolve(android, 'app/src/main/assets/public')

function read(path) {
  assert.ok(existsSync(path), `Missing required file: ${relative(repository, path)}`)
  return readFileSync(path, 'utf8')
}

function files(root) {
  return readdirSync(root, { withFileTypes: true })
    .flatMap((entry) => entry.isDirectory()
      ? files(resolve(root, entry.name))
      : [resolve(root, entry.name)])
    .sort()
}

function treeDigest(root) {
  const hash = createHash('sha256')
  for (const file of files(root)) {
    hash.update(relative(root, file).replaceAll('\\', '/'))
    hash.update(readFileSync(file))
  }
  return hash.digest('hex')
}

const config = read(resolve(mobile, 'capacitor.config.ts'))
assert.match(config, /appId:\s*['"]app\.selfhandler\.mobile['"]/, 'Stable Android application id is missing.')
assert.match(config, /webDir:\s*['"]\.\.\/web\/dist['"]/, 'Capacitor must package the shared web build.')
assert.doesNotMatch(config, /\bserver\s*:/, 'A remote Capacitor server URL is forbidden.')

const packageJson = JSON.parse(read(resolve(mobile, 'package.json')))
for (const [name, version] of Object.entries({
  '@capacitor/android': '8.5.0',
  '@capacitor/core': '8.5.0',
  '@capacitor/app': '8.1.1',
  '@capacitor/device': '8.0.3',
  '@capacitor/keyboard': '8.0.5',
  '@capacitor/local-notifications': '8.2.1',
})) {
  assert.equal(packageJson.dependencies[name], version, `${name} version drifted.`)
}

const manifest = read(resolve(android, 'app/src/main/AndroidManifest.xml'))
assert.match(manifest, /android:allowBackup="false"/)
assert.match(manifest, /android:usesCleartextTraffic="false"/)
assert.match(manifest, /android:windowSoftInputMode="adjustResize"/)
assert.match(manifest, /android\.permission\.POST_NOTIFICATIONS/)
assert.doesNotMatch(manifest, /SCHEDULE_EXACT_ALARM|USE_EXACT_ALARM/)

const activity = read(resolve(android, 'app/src/main/java/app/selfhandler/mobile/MainActivity.java'))
const vault = read(resolve(android, 'app/src/main/java/app/selfhandler/mobile/MobileCredentialVaultPlugin.java'))
assert.match(activity, /registerPlugin\(MobileCredentialVaultPlugin\.class\)/)
assert.match(vault, /AndroidKeyStore/)
assert.match(vault, /AES\/GCM\/NoPadding/)
assert.match(vault, /Context\.MODE_PRIVATE/)
assert.doesNotMatch(vault, /localStorage|sessionStorage|\bLog\./)
assert.match(read(resolve(android, 'app/build.gradle')), /versionName\s+"0\.1\.0"/)
assert.match(read(resolve(android, 'app/build.gradle')), /keystore\.properties/)

assert.match(read(resolve(mobile, 'assets/icon-only.svg')), /width="1024" height="1024"/)
assert.match(read(resolve(mobile, 'assets/splash.svg')), /width="2732" height="2732"/)
assert.match(read(resolve(mobile, 'assets/splash-dark.svg')), /width="2732" height="2732"/)

for (const required of [
  'gradlew',
  'gradlew.bat',
  'gradle/wrapper/gradle-wrapper.jar',
  'gradle/wrapper/gradle-wrapper.properties',
  'keystore.properties.example',
  'app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml',
  'app/src/main/res/mipmap-anydpi-v33/ic_launcher.xml',
  'app/src/main/res/drawable/ic_stat_selfhandler.xml',
  'app/src/main/res/drawable/splash.png',
  'app/src/main/res/drawable-night/splash.png',
]) assert.ok(existsSync(resolve(android, required)), `Missing Android project input: ${required}`)

const rootIgnore = read(resolve(repository, '.gitignore'))
for (const ignored of ['*.jks', '*.keystore', '*.apk', '*.aab', 'apps/mobile/android/keystore.properties']) {
  assert.ok(rootIgnore.includes(ignored), `Missing ignore rule for ${ignored}`)
}

for (const artifact of [
  resolve(android, 'keystore.properties'),
  resolve(android, 'app/build/outputs/apk'),
  resolve(android, 'app/build/outputs/bundle'),
]) assert.equal(existsSync(artifact), false, `Generated or secret material must not be committed: ${artifact}`)

assert.ok(existsSync(dist) && statSync(dist).isDirectory(), 'Build apps/web before validation.')
assert.ok(existsSync(synced) && statSync(synced).isDirectory(), 'Run cap sync android before validation.')
const distFiles = files(dist)
const syncedFiles = files(synced)
for (const source of distFiles) {
  const path = relative(dist, source)
  const target = resolve(synced, path)
  assert.ok(existsSync(target), `The synchronized Android bundle is missing ${path}.`)
  assert.deepEqual(readFileSync(target), readFileSync(source), `The synchronized copy of ${path} drifted.`)
}
const expectedGeneratedFiles = new Set(['cordova.js', 'cordova_plugins.js'])
for (const target of syncedFiles) {
  const path = relative(synced, target).replaceAll('\\', '/')
  assert.ok(
    existsSync(resolve(dist, path)) || expectedGeneratedFiles.has(path),
    `Unexpected file in synchronized bundle: ${path}`,
  )
}

const generatedConfig = JSON.parse(read(resolve(android, 'app/src/main/assets/capacitor.config.json')))
assert.equal(generatedConfig.server, undefined, 'Generated Capacitor config must not include server.url.')
assert.equal(generatedConfig.appId, 'app.selfhandler.mobile')

const generatedPlugins = read(resolve(android, 'app/src/main/assets/capacitor.plugins.json'))
for (const plugin of ['App', 'Device', 'Keyboard', 'LocalNotifications']) {
  assert.ok(generatedPlugins.includes(plugin), `Synchronized plugin metadata is missing ${plugin}.`)
}

const expectedOrigin = configuredApiOrigin()
assert.ok(
  files(dist).some((file) => file.endsWith('.js') && readFileSync(file, 'utf8').includes(expectedOrigin)),
  'The production bundle does not contain the validated public API origin.',
)

const secretPatterns = [
  /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,
  /(?:storePassword|keyPassword)\s*=\s*(?!replace-locally)[^\s]+/i,
  /Bearer\s+[A-Za-z0-9._~-]{20,}/,
]
const auditRoots = [resolve(mobile, 'scripts'), resolve(mobile, 'android/app/src/main'), resolve(mobile, 'assets')]
for (const file of auditRoots.flatMap(files)) {
  const content = readFileSync(file, 'utf8')
  for (const pattern of secretPatterns) assert.doesNotMatch(content, pattern, `Possible secret in ${file}`)
}

console.log(`Mobile validation passed; synchronized bundle ${treeDigest(dist).slice(0, 12)}.`)
