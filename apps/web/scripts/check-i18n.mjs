import { readFileSync, readdirSync } from 'node:fs'
import { extname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const srcRoot = join(root, 'src')
const localeFiles = {
  en: join(srcRoot, 'i18n', 'locales', 'en.ts'),
  ru: join(srcRoot, 'i18n', 'locales', 'ru.ts'),
  uk: join(srcRoot, 'i18n', 'locales', 'uk.ts'),
}

function sourceFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) return sourceFiles(path)
    return ['.ts', '.vue'].includes(extname(entry.name)) ? [path] : []
  })
}

function catalog(path) {
  const messages = new Map()
  for (const line of readFileSync(path, 'utf8').split(/\r?\n/)) {
    const match = line.match(/^\s*'([^']+)'\s*:\s*(['"])(.*)\2,\s*$/)
    if (match) messages.set(match[1], match[3])
  }
  return messages
}

function parityErrors(catalogs) {
  const canonical = new Set(catalogs.en.keys())
  const errors = []
  for (const [locale, messages] of Object.entries(catalogs)) {
    for (const key of canonical) if (!messages.has(key)) errors.push(`${locale}: missing key ${key}`)
    for (const key of messages.keys()) if (!canonical.has(key)) errors.push(`${locale}: extra key ${key}`)
    for (const [key, value] of messages) if (value.trim() === '') errors.push(`${locale}: blank value ${key}`)
  }
  return errors
}

const allowedVisibleText = [
  /^SELFHANDLER$/,
  /^data-theme$/,
  /^--[a-z-]+$/,
  /^12d$/,
  /^PATCH \/api\/profile · preferences\.theme$/,
]
const dynamicKeys = new Set([
  'appearance.accent.slate', 'appearance.accent.gold', 'appearance.accent.brick',
  'appearance.background.sand', 'appearance.background.mist', 'appearance.background.sage',
  'today.state.skipped', 'today.state.pending',
  'weekday.TU', 'weekday.WE', 'weekday.TH', 'weekday.FR', 'weekday.SA', 'weekday.SU',
  'goal.status.completed', 'goal.status.abandoned',
  'sleep.empty.active', 'sleep.empty.paused', 'sleep.empty.archived',
  'sleep.paused', 'sleep.resumed', 'sleep.archived', 'sleep.restored',
  'today.parentState.done', 'today.parentState.skipped',
  'workouts.state.paused', 'workouts.state.archived',
  'workouts.occurrence.skipped', 'workouts.occurrence.rescheduled',
  'workouts.activity.cycling', 'workouts.activity.walking', 'workouts.activity.swimming',
  'workouts.activity.other', 'workouts.runType.tempo', 'workouts.runType.intervals',
  'workouts.runType.long', 'workouts.goalStatus.completed', 'workouts.goalStatus.abandoned',
  'workouts.exercise.bench_press', 'workouts.exercise.deadlift',
  'workouts.exercise.overhead_press', 'workouts.exercise.row', 'workouts.exercise.pull_up',
  'supplements.tab.day', 'supplements.tab.catalogue', 'supplements.tab.courses', 'supplements.tab.stock',
  'supplements.category.vitamin', 'supplements.category.sports_nutrition', 'supplements.category.nootropic',
  'supplements.category.medication', 'supplements.category.other',
  'supplements.form.capsule', 'supplements.form.tablet', 'supplements.form.powder',
  'supplements.form.liquid', 'supplements.form.injection', 'supplements.form.other',
  'supplements.unit.gram', 'supplements.unit.millilitre', 'supplements.unit.piece',
  'supplements.unit.mg', 'supplements.unit.g', 'supplements.unit.ml',
  'supplements.context.unspecified', 'supplements.context.with_food', 'supplements.context.empty_stomach',
  'supplements.forecast.ready', 'supplements.forecast.already_depleted', 'supplements.forecast.no_stock',
  'supplements.forecast.no_active_course', 'supplements.forecast.no_consumption',
  'supplements.forecast.course_ends_with_stock', 'supplements.forecast.beyond_horizon',
  'supplements.status.planned', 'supplements.status.done', 'supplements.status.skipped',
])

function isAllowedText(value) {
  const normalized = value.replace(/\s+/g, ' ').trim()
  return normalized === '' || allowedVisibleText.some((pattern) => pattern.test(normalized))
}

function hardcodedErrors(path, source) {
  if (extname(path) !== '.vue') return []
  const errors = []
  const template = source.includes('<template>') ? source.slice(source.indexOf('<template>')) : source
  const textPattern = />([^<>{}]*[A-Za-zА-Яа-яІіЇїЄє][^<>{}]*)</g
  const attributePattern = /(?<![:\w-])(?:aria-label|title|placeholder|label|helper|empty-title|empty-description|loading-title|loading-description)="([^"]*[A-Za-zА-Яа-яІіЇїЄє][^"]*)"/g
  const assignmentPattern = /\b(?:loadError|saveError|error|feedback|success)\.value\s*=\s*(['"])([^'"\n]+)\1/g

  for (const match of template.matchAll(textPattern)) {
    if (!isAllowedText(match[1])) errors.push(`hardcoded visible text: ${match[1].replace(/\s+/g, ' ').trim()}`)
  }
  for (const match of template.matchAll(attributePattern)) {
    if (!isAllowedText(match[1])) errors.push(`hardcoded attribute text: ${match[1].trim()}`)
  }
  for (const match of source.matchAll(assignmentPattern)) {
    if (!isAllowedText(match[2])) errors.push(`hardcoded feedback text: ${match[2].trim()}`)
  }

  return errors.map((error) => `${relative(root, path)}: ${error}`)
}

function usageErrorsFromSources(keys, sources) {
  const errors = []
  const combined = sources
    .filter(([path]) => !Object.values(localeFiles).includes(path))
    .map(([, source]) => source)
    .join('\n')

  for (const [path, source] of sources) {
    if (Object.values(localeFiles).includes(path)) continue
    const callPattern = /\b(?:t|translate)\(\s*(['"])([a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9]+)+)\1/g
    for (const match of source.matchAll(callPattern)) {
      if (!keys.has(match[2])) errors.push(`${relative(root, path)}: unknown key ${match[2]}`)
    }
  }

  for (const key of keys) {
    if (!dynamicKeys.has(key) && !combined.includes(`'${key}'`) && !combined.includes(`"${key}"`)) errors.push(`unused key ${key}`)
  }
  return errors
}

function usageErrors(keys, files) {
  return usageErrorsFromSources(keys, files.map((path) => [path, readFileSync(path, 'utf8')]))
}

function selfTest() {
  const parity = parityErrors({ en: new Map([['a', 'A']]), ru: new Map(), uk: new Map([['a', '']]) })
  if (!parity.some((error) => error.includes('missing key')) || !parity.some((error) => error.includes('blank value'))) {
    throw new Error('i18n guard self-test failed: invalid catalogs were not rejected')
  }
  const hardcoded = hardcodedErrors(join(srcRoot, 'Fixture.vue'), '<template><button aria-label="Save item">Save</button></template>')
  if (hardcoded.length !== 2) throw new Error('i18n guard self-test failed: hardcoded product copy was not rejected')
  const usage = usageErrorsFromSources(new Set(['used.key', 'unused.key']), [
    [join(srcRoot, 'Fixture.vue'), "t('used.key'); t('unknown.key')"],
  ])
  if (!usage.some((error) => error.includes('unknown key')) || !usage.some((error) => error.includes('unused key'))) {
    throw new Error('i18n guard self-test failed: unknown or unused keys were not rejected')
  }
}

selfTest()
const catalogs = Object.fromEntries(Object.entries(localeFiles).map(([locale, path]) => [locale, catalog(path)]))
const files = sourceFiles(srcRoot)
const errors = [
  ...parityErrors(catalogs),
  ...usageErrors(new Set(catalogs.en.keys()), files),
  ...files.flatMap((path) => hardcodedErrors(path, readFileSync(path, 'utf8'))),
]

if (errors.length > 0) {
  console.error(`i18n guard failed (${errors.length}):`)
  for (const error of errors) console.error(`- ${error}`)
  process.exitCode = 1
} else {
  console.log(`i18n guard passed: ${catalogs.en.size} keys across en/ru/uk; ${files.length} source files checked.`)
}
