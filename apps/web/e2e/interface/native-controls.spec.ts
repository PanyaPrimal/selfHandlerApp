import { expect, test } from '@playwright/test'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const srcDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../src')

/**
 * Application screens must not contain a default browser control any more. The
 * owned control layer is exempt: `UiCheckbox` deliberately keeps a real
 * `input[type="checkbox"]` beneath a drawn mark so checkbox semantics are
 * preserved rather than re-implemented, and it never shows the native appearance.
 */
const bannedPatterns: Array<[string, RegExp]> = [
  ['native select', /<select\b/],
  ['native date input', /type="date"/],
  ['native time input', /type="time"/],
  ['native checkbox', /type="checkbox"/],
]

function vueFilesIn(directory: string): string[] {
  return readdirSync(directory)
    .map((entry) => path.join(directory, entry))
    .flatMap((entry) => (statSync(entry).isDirectory() ? vueFilesIn(entry) : [entry]))
    .filter((entry) => entry.endsWith('.vue'))
}

test('no default browser control remains in application screens', async () => {
  const files = [
    ...vueFilesIn(path.join(srcDir, 'views')),
    ...vueFilesIn(path.join(srcDir, 'layouts')),
  ]

  expect(files.length).toBeGreaterThan(0)

  const findings: string[] = []

  for (const file of files) {
    const contents = readFileSync(file, 'utf8')

    for (const [description, pattern] of bannedPatterns) {
      if (pattern.test(contents)) {
        findings.push(`${path.relative(srcDir, file)}: ${description}`)
      }
    }
  }

  expect(findings).toEqual([])
})

test('the accepted deviation is limited to the review sliders', async () => {
  const review = readFileSync(path.join(srcDir, 'views', 'ReviewView.vue'), 'utf8')

  // AD-1: input[type="range"] has no operating-system surface and is natively
  // accessible, so it is kept on purpose.
  expect(review).toContain('type="range"')

  const rangeUsers = vueFilesIn(path.join(srcDir, 'views'))
    .filter((file) => readFileSync(file, 'utf8').includes('type="range"'))
    .map((file) => path.basename(file))

  expect(rangeUsers).toEqual(['ReviewView.vue'])
})
