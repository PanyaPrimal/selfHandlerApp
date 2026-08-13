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
