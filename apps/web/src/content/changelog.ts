import type { MessageKey } from '../i18n/locales/en'

/** Static, versioned changelog metadata. All user-facing copy lives in i18n catalogs. */
export interface ChangelogLink {
  readonly labelKey: MessageKey
  readonly to: string
}

export interface ChangelogEntry {
  readonly id: string
  readonly date: string
  readonly feature: string
  readonly titleKey: MessageKey
  readonly summaryKey: MessageKey
  readonly testKey: MessageKey
  readonly links?: readonly ChangelogLink[]
  readonly limitationKeys?: readonly MessageKey[]
}

const entries: readonly ChangelogEntry[] = [
  {
    id: 'debts-funds-financial-goals', date: '2026-08-13', feature: '020-debts-funds-financial-goals',
    titleKey: 'changelog.entry.financeCommitments.title', summaryKey: 'changelog.entry.financeCommitments.summary',
    testKey: 'changelog.entry.financeCommitments.test', links: [
      { labelKey: 'nav.finance', to: '/finance?tab=debts' },
      { labelKey: 'nav.storage', to: '/storage' },
      { labelKey: 'nav.supplements', to: '/supplements' },
    ],
    limitationKeys: ['changelog.entry.financeCommitments.limit'],
  },
  {
    id: 'budget-recurring-cash-flow', date: '2026-08-13', feature: '019-budget-recurring-cash-flow',
    titleKey: 'changelog.entry.financePlanning.title', summaryKey: 'changelog.entry.financePlanning.summary',
    testKey: 'changelog.entry.financePlanning.test', links: [{ labelKey: 'nav.finance', to: '/finance?tab=plans' }],
    limitationKeys: ['changelog.entry.financePlanning.limit'],
  },
  {
    id: 'finance-ledger-foundation', date: '2026-08-13', feature: '018-finance-ledger-foundation',
    titleKey: 'changelog.entry.finance.title', summaryKey: 'changelog.entry.finance.summary',
    testKey: 'changelog.entry.finance.test', links: [{ labelKey: 'nav.finance', to: '/finance' }],
    limitationKeys: ['changelog.entry.finance.limit'],
  },
  {
    id: 'supplements-courses-intake-stock', date: '2026-08-13', feature: '017-supplements-courses-intake-stock',
    titleKey: 'changelog.entry.supplements.title', summaryKey: 'changelog.entry.supplements.summary',
    testKey: 'changelog.entry.supplements.test', links: [{ labelKey: 'nav.supplements', to: '/supplements' }],
    limitationKeys: ['changelog.entry.supplements.limit'],
  },
  {
    id: 'nutrition-meals-hydration-targets', date: '2026-08-13', feature: '016-nutrition-meals-hydration-targets',
    titleKey: 'changelog.entry.nutrition.title', summaryKey: 'changelog.entry.nutrition.summary',
    testKey: 'changelog.entry.nutrition.test', links: [{ labelKey: 'nav.nutrition', to: '/nutrition' }],
    limitationKeys: ['changelog.entry.nutrition.limit'],
  },
  {
    id: 'workouts-and-training-goals', date: '2026-08-13', feature: '015-workouts-training-goals',
    titleKey: 'changelog.entry.workouts.title', summaryKey: 'changelog.entry.workouts.summary',
    testKey: 'changelog.entry.workouts.test', links: [{ labelKey: 'nav.workouts', to: '/workouts' }],
    limitationKeys: ['changelog.entry.workouts.limit'],
  },
  {
    id: 'sleep-and-rich-routines', date: '2026-08-13', feature: '014-sleep-routine-templates',
    titleKey: 'changelog.entry.sleepRoutines.title', summaryKey: 'changelog.entry.sleepRoutines.summary',
    testKey: 'changelog.entry.sleepRoutines.test', links: [{ labelKey: 'nav.routines', to: '/routines' }],
    limitationKeys: ['changelog.entry.sleepRoutines.limit'],
  },
  {
    id: 'habits-and-anti-habits', date: '2026-08-13', feature: '013-habits-anti-habits',
    titleKey: 'changelog.entry.habits.title', summaryKey: 'changelog.entry.habits.summary',
    testKey: 'changelog.entry.habits.test', links: [{ labelKey: 'nav.habits', to: '/habits' }],
    limitationKeys: ['changelog.entry.habits.limit'],
  },
  {
    id: 'android-capacitor-shell', date: '2026-08-13', feature: '012-android-capacitor-shell',
    titleKey: 'changelog.entry.android.title', summaryKey: 'changelog.entry.android.summary',
    testKey: 'changelog.entry.android.test', links: [{ labelKey: 'nav.notifications', to: '/notifications' }],
    limitationKeys: ['changelog.entry.android.limit'],
  },
  {
    id: 'in-app-notifications', date: '2026-08-13', feature: '011-in-app-notifications',
    titleKey: 'changelog.entry.notifications.title', summaryKey: 'changelog.entry.notifications.summary',
    testKey: 'changelog.entry.notifications.test',
    links: [{ labelKey: 'nav.notifications', to: '/notifications' }],
  },
  {
    id: 'interface-personalization', date: '2026-08-13', feature: '010-interface-personalization',
    titleKey: 'changelog.entry.personalization.title', summaryKey: 'changelog.entry.personalization.summary',
    testKey: 'changelog.entry.personalization.test',
    links: [{ labelKey: 'nav.settings', to: '/settings/appearance' }, { labelKey: 'nav.account', to: '/account' }],
  },
  {
    id: 'planner-day', date: '2026-08-12', feature: '009-planner-day',
    titleKey: 'changelog.entry.planner.title', summaryKey: 'changelog.entry.planner.summary',
    testKey: 'changelog.entry.planner.test', links: [{ labelKey: 'nav.planner', to: '/planner' }],
    limitationKeys: ['changelog.entry.planner.limit'],
  },
  {
    id: 'storage-inbox', date: '2026-08-12', feature: '008-storage-inbox',
    titleKey: 'changelog.entry.storage.title', summaryKey: 'changelog.entry.storage.summary',
    testKey: 'changelog.entry.storage.test', links: [{ labelKey: 'nav.storage', to: '/storage' }],
    limitationKeys: ['changelog.entry.storage.limit'],
  },
  {
    id: 'body-measurements', date: '2026-08-12', feature: '007-body-measurements',
    titleKey: 'changelog.entry.body.title', summaryKey: 'changelog.entry.body.summary',
    testKey: 'changelog.entry.body.test', links: [{ labelKey: 'nav.body', to: '/body' }],
    limitationKeys: ['changelog.entry.body.limit'],
  },
  {
    id: 'unified-recurrence', date: '2026-08-12', feature: '006-unified-recurrence',
    titleKey: 'changelog.entry.recurrence.title', summaryKey: 'changelog.entry.recurrence.summary',
    testKey: 'changelog.entry.recurrence.test', links: [{ labelKey: 'nav.routines', to: '/routines' }],
  },
  {
    id: 'interface-foundation', date: '2026-08-12', feature: '005-interface-foundation',
    titleKey: 'changelog.entry.interface.title', summaryKey: 'changelog.entry.interface.summary',
    testKey: 'changelog.entry.interface.test', links: [{ labelKey: 'nav.account', to: '/account' }],
  },
  {
    id: 'profile-and-settings', date: '2026-08-12', feature: '004-profile-settings',
    titleKey: 'changelog.entry.profile.title', summaryKey: 'changelog.entry.profile.summary',
    testKey: 'changelog.entry.profile.test', links: [{ labelKey: 'nav.account', to: '/account' }],
  },
  {
    id: 'goals', date: '2026-08-11', feature: '001-core-daily-loop',
    titleKey: 'changelog.entry.goals.title', summaryKey: 'changelog.entry.goals.summary',
    testKey: 'changelog.entry.goals.test', links: [{ labelKey: 'nav.goals', to: '/goals' }],
  },
  {
    id: 'progress-and-streaks', date: '2026-08-11', feature: '001-core-daily-loop',
    titleKey: 'changelog.entry.progress.title', summaryKey: 'changelog.entry.progress.summary',
    testKey: 'changelog.entry.progress.test', links: [{ labelKey: 'nav.today', to: '/' }],
  },
  {
    id: 'routines-and-today', date: '2026-08-10', feature: '001-core-daily-loop',
    titleKey: 'changelog.entry.routines.title', summaryKey: 'changelog.entry.routines.summary',
    testKey: 'changelog.entry.routines.test', links: [{ labelKey: 'nav.routines', to: '/routines' }, { labelKey: 'nav.today', to: '/' }],
  },
  {
    id: 'daily-review', date: '2026-08-10', feature: '001-core-daily-loop',
    titleKey: 'changelog.entry.review.title', summaryKey: 'changelog.entry.review.summary',
    testKey: 'changelog.entry.review.test', links: [{ labelKey: 'nav.review', to: '/review' }],
  },
  {
    id: 'multi-user-auth', date: '2026-08-09', feature: '003-multi-user-auth',
    titleKey: 'changelog.entry.auth.title', summaryKey: 'changelog.entry.auth.summary',
    testKey: 'changelog.entry.auth.test', links: [{ labelKey: 'nav.account', to: '/account' }],
  },
]

export const changelogEntries: readonly ChangelogEntry[] = [...entries].sort((left, right) => {
  if (left.date !== right.date) return left.date < right.date ? 1 : -1
  return left.id < right.id ? 1 : -1
})
