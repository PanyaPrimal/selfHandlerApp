import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import {
  chooseOption,
  chooseSegment,
  expectNoHorizontalOverflow,
  gotoDestination,
  pickDate,
  setTime,
  toggleOption,
} from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

const today = '2026-08-13'

async function createStrengthProgram(page: Page, name = 'Strength A'): Promise<void> {
  const form = page.getByRole('form', { name: 'Create workout program' })
  await form.getByLabel('Program name').fill(name)
  await chooseOption(form, 'Workout type', 'Strength')
  await chooseOption(form, 'Intensity', 'Moderate')
  await chooseSegment(form, 'Schedule', 'By weekdays')
  await toggleOption(form, 'Weekdays', 'Thu')
  await setTime(form, 'Preferred time', '18:00')
  await form.getByRole('button', { name: 'Create workout program' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Workout program created.' })).toBeVisible()

  const card = page.getByRole('listitem', { name })
  await card.getByRole('button', { name: `Edit exercises for ${name}` }).click()
  const prescription = page.getByRole('form', { name: `Exercises for ${name}` })
  await prescription.getByRole('button', { name: 'Add exercise' }).click()
  await chooseOption(prescription, 'Exercise 1', 'Squat')
  await prescription.getByLabel('Exercise 1 starting weight').fill('50')
  await prescription.getByRole('button', { name: 'Save exercises' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Exercises saved.' })).toBeVisible()
}

test('catalogue and recurring strength program survive reload and lifecycle changes', async ({ page }, testInfo) => {
  test.setTimeout(90_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'WorkoutProgram'), { redirectTo: '/workouts' })
  const issues = collectRuntimeIssues(page)
  await expect(page.getByRole('heading', { name: 'Workouts' })).toBeVisible()
  await expect(page.getByRole('list', { name: 'Exercise catalogue' })).toContainText('Squat')

  const exercise = page.getByRole('form', { name: 'Create exercise' })
  await exercise.getByLabel('Exercise name').fill('Landmine press')
  await exercise.getByLabel('Muscle group').fill('Shoulders')
  await exercise.getByLabel('Equipment').fill('Barbell')
  await exercise.getByRole('button', { name: 'Create exercise' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Exercise created.' })).toBeVisible()
  const customExercise = page.getByRole('listitem', { name: 'Landmine press' })
  await customExercise.getByRole('button', { name: 'Edit exercise Landmine press' }).click()
  const exerciseEditor = page.getByRole('form', { name: 'Edit exercise Landmine press' })
  await exerciseEditor.getByLabel('Exercise name').fill('Half-kneeling press')
  await exerciseEditor.getByRole('button', { name: 'Save exercise' }).click()
  await expect(page.getByRole('listitem', { name: 'Half-kneeling press' })).toBeVisible()
  await page.getByRole('listitem', { name: 'Half-kneeling press' }).getByRole('button', { name: 'Archive Half-kneeling press' }).click()
  await page.getByRole('radio', { name: 'Archived exercises' }).click()
  await page.getByRole('listitem', { name: 'Half-kneeling press' }).getByRole('button', { name: 'Restore Half-kneeling press' }).click()
  await page.getByRole('radio', { name: 'Active exercises' }).click()
  await expect(page.getByRole('listitem', { name: 'Half-kneeling press' })).toBeVisible()

  await createStrengthProgram(page)
  let card = page.getByRole('list', { name: 'Scheduled programs' }).getByRole('listitem', { name: 'Strength A', exact: true })
  await expect(card).toContainText('Squat')
  await expect(card).toContainText('50 kg')
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Strength A' })).toContainText('18:00')

  await card.getByRole('button', { name: 'Edit workout program Strength A' }).click()
  const programEditor = page.getByRole('form', { name: 'Edit workout program Strength A' })
  await programEditor.getByLabel('Program name').fill('Strength B')
  await setTime(programEditor, 'Preferred time', '19:00')
  await programEditor.getByRole('button', { name: 'Save program' }).click()
  card = page.getByRole('listitem', { name: 'Strength B' })
  await expect(card).toContainText('Thu')
  await expect(card).toContainText('19:00')

  await card.getByRole('button', { name: 'Pause Strength B' }).click()
  await expect(card).toBeHidden()
  await page.getByRole('radio', { name: 'Paused programs' }).click()
  card = page.getByRole('listitem', { name: 'Strength B' })
  await card.getByRole('button', { name: 'Restore Strength B' }).click()
  await page.getByRole('radio', { name: 'Active programs' }).click()
  card = page.getByRole('listitem', { name: 'Strength B' })
  await card.getByRole('button', { name: 'Archive Strength B' }).click()
  await page.getByRole('radio', { name: 'Archived programs' }).click()
  card = page.getByRole('listitem', { name: 'Strength B' })
  await card.getByRole('button', { name: 'Restore Strength B' }).click()
  await page.getByRole('radio', { name: 'Active programs' }).click()
  await expect(page.getByRole('listitem', { name: 'Strength B' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('planned strength, manual run, records and training goal recalculate after correction', async ({ page }, testInfo) => {
  test.setTimeout(90_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'WorkoutFacts'), { redirectTo: '/workouts' })
  await createStrengthProgram(page)
  const card = page.getByRole('list', { name: 'Scheduled programs' }).getByRole('listitem', { name: 'Strength A', exact: true })
  const planned = card.getByRole('form', { name: 'Record Strength A' })
  await planned.getByLabel('Exercise 1 set 1 weight').fill('50')
  await planned.getByLabel('Exercise 1 set 1 reps').fill('5')
  await planned.getByRole('button', { name: 'Complete workout' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Workout recorded.' })).toBeVisible()
  await expect(card).toContainText('Completed')

  await page.getByRole('button', { name: 'Edit Strength A' }).click()
  const strengthCorrection = page.getByRole('form', { name: 'Edit Strength A' })
  await strengthCorrection.getByLabel('Exercise 1 set 1 weight').fill('52.5')
  await strengthCorrection.getByRole('button', { name: 'Save workout' }).click()
  await expect(page.getByRole('region', { name: 'Workout records' })).toContainText('52.500')

  const manual = page.getByRole('form', { name: 'Record unplanned workout' })
  await manual.getByLabel('Workout name').fill('Easy 5K')
  await chooseOption(manual, 'Workout type', 'Cardio')
  await pickDate(page, 'Workout date', today)
  await manual.getByLabel('Distance').fill('5')
  await manual.getByLabel('Duration').fill('30')
  await manual.getByRole('button', { name: 'Record unplanned workout' }).click()
  await expect(page.getByRole('region', { name: 'Workout records' })).toContainText('6:00 /km')
  await expect(page.getByRole('status').filter({ hasText: 'Workout recorded.' })).toBeVisible()

  const goal = page.getByRole('form', { name: 'Create training goal' })
  await goal.getByLabel('Goal name').fill('Run 10K')
  await chooseOption(goal, 'Goal kind', 'Distance')
  await goal.getByLabel('Target distance').fill('10')
  await goal.getByRole('button', { name: 'Create training goal' }).click()
  const goalCard = page.getByRole('listitem', { name: 'Run 10K' })
  await expect(goalCard).toContainText('5 km of 10 km')
  await expect(goalCard).toContainText('0%')

  await page.getByRole('button', { name: 'Edit Easy 5K' }).click()
  const correction = page.getByRole('form', { name: 'Edit Easy 5K' })
  await correction.getByLabel('Distance').fill('8')
  await correction.getByRole('button', { name: 'Save workout' }).click()
  await expect(goalCard).toContainText('8 km of 10 km')
  await expect(goalCard).toContainText('60%')

  await goalCard.getByRole('button', { name: 'Complete goal' }).click()
  await expect(goalCard).toContainText('Completed')
  await goalCard.getByRole('button', { name: 'Reactivate goal' }).click()
  await goalCard.getByRole('button', { name: 'Abandon goal' }).click()
  await expect(goalCard).toContainText('Abandoned')
  await goalCard.getByRole('button', { name: 'Reactivate goal' }).click()
  await goalCard.getByRole('button', { name: 'Archive goal' }).click()
  await page.getByRole('radio', { name: 'Archived training goals' }).click()
  await page.getByRole('listitem', { name: 'Run 10K' }).getByRole('button', { name: 'Restore goal' }).click()
  await page.getByRole('radio', { name: 'Current training goals' }).click()
  await expect(page.getByRole('listitem', { name: 'Run 10K' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})

test('Today Planner Review and notification settings share workout state and deep links', async ({ page }, testInfo) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'WorkoutSurfaces'), { redirectTo: '/workouts' })
  await createStrengthProgram(page, 'Evening strength')

  await gotoDestination(page, 'Today')
  await pickDate(page, 'Date', today)
  await expect(page.getByRole('region', { name: 'Workout summary' })).toContainText('1 planned')

  await gotoDestination(page, 'Planner')
  await pickDate(page, 'Day', today)
  const entry = page.getByRole('listitem', { name: 'Evening strength' })
  await expect(entry).toContainText('18:00')
  await entry.getByRole('link', { name: 'Open workout' }).click()
  await expect(page).toHaveURL(new RegExp('/workouts\\?date=2026-08-13&program='))
  await expect(page.getByRole('listitem', { name: 'Evening strength' })).toBeVisible()

  await page.goto(`/review/${today}`)
  await expect(page.getByRole('region', { name: 'Workout summary' })).toContainText('1 planned')
  await page.goto('/notifications')
  await expect(page.getByRole('switch', { name: 'Workout reminders' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})

test('workspace localizes in RU and UK and keeps rollback and mobile controls accessible', async ({ page }, testInfo) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'WorkoutLocales'))
  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await page.goto('/workouts')
  await expect(page.getByRole('heading', { name: 'Тренировки' })).toBeVisible()
  await expect(page.getByRole('form', { name: 'Создать программу тренировок' })).toBeVisible()

  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Тренування', exact: true })).toBeVisible()
  const name = page.getByRole('form', { name: 'Створити програму тренувань' }).getByLabel('Назва програми')
  await name.focus()
  await expect(name).toBeFocused()

  await page.getByRole('button', { name: 'EN', exact: true }).click()
  await page.route('**/api/workout-programs', async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({
        message: 'Workout program could not be saved.',
        errors: { name: ['Workout program could not be saved.'] },
      }) })
      return
    }
    await route.continue()
  })
  const form = page.getByRole('form', { name: 'Create workout program' })
  await form.getByLabel('Program name').fill('Must rollback')
  await form.getByRole('button', { name: 'Create workout program' }).click()
  await expect(page.locator('p[role="alert"]').filter({ hasText: 'Workout program could not be saved.' })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Must rollback' })).toBeHidden()

  await page.getByTestId('quick-theme-toggle').click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  if (testInfo.project.name === 'mobile') {
    const button = form.getByRole('button', { name: 'Create workout program' })
    await button.scrollIntoViewIfNeeded()
    const box = await button.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44)
  }
  await expectNoHorizontalOverflow(page)
})
