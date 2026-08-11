/**
 * Calendar-date and time-of-day arithmetic for the control layer.
 *
 * SelfHandler stores calendar dates as `YYYY-MM-DD` days, not as instants. The
 * classic failure is `new Date('2026-08-16')`, which JavaScript parses as a UTC
 * instant and renders as 15 August for anyone west of Greenwich. Nothing here
 * constructs a local `Date`: arithmetic runs on plain integers, and the only
 * `Date` values ever built are UTC ones that are immediately read back in UTC
 * (`getUTCDay`) or formatted with `timeZone: 'UTC'`. The module's public surface
 * is strings and integer tuples.
 *
 * "Today" is deliberately absent: the authoritative current day comes from the
 * user's profile time zone through the API and is passed in by the caller.
 */

export interface CalendarDate {
  readonly year: number
  /** 1-12, not the zero-based month of the `Date` API. */
  readonly month: number
  readonly day: number
}

const DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/
const TIME_PATTERN = /^(\d{1,2}):(\d{2})/

function pad(value: number, length = 2): string {
  return String(value).padStart(length, '0')
}

export function isLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
}

export function daysInMonth(year: number, month: number): number {
  switch (month) {
    case 1:
    case 3:
    case 5:
    case 7:
    case 8:
    case 10:
    case 12:
      return 31
    case 4:
    case 6:
    case 9:
    case 11:
      return 30
    case 2:
      return isLeapYear(year) ? 29 : 28
    default:
      return 0
  }
}

export function isValidCalendarDate(date: CalendarDate | null): date is CalendarDate {
  if (!date) {
    return false
  }

  return (
    Number.isInteger(date.year) &&
    date.year >= 1 &&
    date.year <= 9999 &&
    Number.isInteger(date.month) &&
    date.month >= 1 &&
    date.month <= 12 &&
    Number.isInteger(date.day) &&
    date.day >= 1 &&
    date.day <= daysInMonth(date.year, date.month)
  )
}

/** Parse `YYYY-MM-DD` (a longer timestamp is reduced to its date part). */
export function parseCalendarDate(value: string | null | undefined): CalendarDate | null {
  if (!value) {
    return null
  }

  const match = DATE_PATTERN.exec(value.slice(0, 10))

  if (!match) {
    return null
  }

  const date: CalendarDate = {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
  }

  return isValidCalendarDate(date) ? date : null
}

export function toDateString(date: CalendarDate): string {
  return `${pad(date.year, 4)}-${pad(date.month)}-${pad(date.day)}`
}

export function compareCalendarDates(left: CalendarDate, right: CalendarDate): number {
  if (left.year !== right.year) return left.year - right.year
  if (left.month !== right.month) return left.month - right.month
  return left.day - right.day
}

export function isSameCalendarDate(left: CalendarDate | null, right: CalendarDate | null): boolean {
  if (!left || !right) {
    return false
  }

  return compareCalendarDates(left, right) === 0
}

/** Days since 1970-01-01, computed without leaving UTC. */
function toEpochDay(date: CalendarDate): number {
  return Math.round(Date.UTC(date.year, date.month - 1, date.day) / 86_400_000)
}

function fromEpochDay(epochDay: number): CalendarDate {
  const value = new Date(epochDay * 86_400_000)

  return {
    year: value.getUTCFullYear(),
    month: value.getUTCMonth() + 1,
    day: value.getUTCDate(),
  }
}

export function addDays(date: CalendarDate, amount: number): CalendarDate {
  return fromEpochDay(toEpochDay(date) + amount)
}

/** Add months, clamping the day to the length of the resulting month. */
export function addMonths(date: CalendarDate, amount: number): CalendarDate {
  const total = date.year * 12 + (date.month - 1) + amount
  const year = Math.floor(total / 12)
  const month = (total % 12) + 1

  return { year, month, day: Math.min(date.day, daysInMonth(year, month)) }
}

/** 0 = Sunday … 6 = Saturday. Built in UTC and read in UTC, so it cannot drift. */
export function weekdayIndex(date: CalendarDate): number {
  return new Date(Date.UTC(date.year, date.month - 1, date.day)).getUTCDay()
}

export function isBefore(date: CalendarDate, other: CalendarDate): boolean {
  return compareCalendarDates(date, other) < 0
}

export function isAfter(date: CalendarDate, other: CalendarDate): boolean {
  return compareCalendarDates(date, other) > 0
}

export function isWithinRange(
  date: CalendarDate,
  min: CalendarDate | null,
  max: CalendarDate | null,
): boolean {
  if (min && isBefore(date, min)) return false
  if (max && isAfter(date, max)) return false
  return true
}

export function clampToRange(
  date: CalendarDate,
  min: CalendarDate | null,
  max: CalendarDate | null,
): CalendarDate {
  if (min && isBefore(date, min)) return min
  if (max && isAfter(date, max)) return max
  return date
}

/**
 * First day of the week for a locale, as a `weekdayIndex` value.
 *
 * `Intl.Locale.prototype.getWeekInfo` is not available everywhere yet, so the
 * fallback is Monday, which is correct for every locale this profile supports
 * (`en-GB`, `uk-UA`, `ru-UA`).
 */
export function firstDayOfWeek(locale: string): number {
  try {
    const info = (
      new Intl.Locale(locale) as Intl.Locale & { getWeekInfo?: () => { firstDay: number } }
    ).getWeekInfo?.()

    if (info && Number.isInteger(info.firstDay)) {
      // `getWeekInfo` uses 1 = Monday … 7 = Sunday.
      return info.firstDay % 7
    }
  } catch {
    // Fall through to the Monday default below.
  }

  return 1
}

/**
 * Six weeks of days covering the given month, starting on the locale's first
 * weekday. A fixed six-row grid keeps the popover height stable while paging.
 */
export function buildMonthGrid(year: number, month: number, weekStart: number): CalendarDate[][] {
  const first: CalendarDate = { year, month, day: 1 }
  const lead = (weekdayIndex(first) - weekStart + 7) % 7
  const start = addDays(first, -lead)
  const weeks: CalendarDate[][] = []

  for (let week = 0; week < 6; week += 1) {
    const days: CalendarDate[] = []

    for (let day = 0; day < 7; day += 1) {
      days.push(addDays(start, week * 7 + day))
    }

    weeks.push(days)
  }

  return weeks
}

function utcDate(date: CalendarDate): Date {
  return new Date(Date.UTC(date.year, date.month - 1, date.day))
}

export function formatMonthLabel(year: number, month: number, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    timeZone: 'UTC',
    month: 'long',
    year: 'numeric',
  }).format(utcDate({ year, month, day: 1 }))
}

export interface WeekdayLabel {
  short: string
  long: string
}

export function weekdayLabels(locale: string, weekStart: number): WeekdayLabel[] {
  const shortFormat = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', weekday: 'short' })
  const longFormat = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', weekday: 'long' })
  // 2024-01-07 is a Sunday, so adding the weekday index lands on that weekday.
  const sunday: CalendarDate = { year: 2024, month: 1, day: 7 }

  return Array.from({ length: 7 }, (_unused, offset) => {
    const day = utcDate(addDays(sunday, (weekStart + offset) % 7))

    return { short: shortFormat.format(day), long: longFormat.format(day) }
  })
}

/** Long, unambiguous label for a calendar cell's accessible name. */
export function formatDayLabel(date: CalendarDate, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    timeZone: 'UTC',
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(utcDate(date))
}

/** Short reading form used on a closed date control. */
export function formatDateForDisplay(value: string | null | undefined, locale: string): string {
  const date = parseCalendarDate(value)

  if (!date) {
    return ''
  }

  return new Intl.DateTimeFormat(locale, {
    timeZone: 'UTC',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(utcDate(date))
}

/* ------------------------------------------------------------------ */
/* Time of day                                                         */
/* ------------------------------------------------------------------ */

export interface TimeOfDay {
  readonly hour: number
  readonly minute: number
}

/** Parse `HH:MM` or `H:MM` (a longer `HH:MM:SS` is reduced to hours/minutes). */
export function parseTimeOfDay(value: string | null | undefined): TimeOfDay | null {
  if (!value) {
    return null
  }

  const match = TIME_PATTERN.exec(value.trim())

  if (!match) {
    return null
  }

  const hour = Number(match[1])
  const minute = Number(match[2])

  if (hour < 0 || hour > 23 || minute < 0 || minute > 59) {
    return null
  }

  return { hour, minute }
}

export function toTimeString(time: TimeOfDay): string {
  return `${pad(time.hour)}:${pad(time.minute)}`
}

export function addMinutes(time: TimeOfDay, amount: number): TimeOfDay {
  const total = ((time.hour * 60 + time.minute + amount) % 1440 + 1440) % 1440

  return { hour: Math.floor(total / 60), minute: total % 60 }
}

/** Selectable slots across the day, every `stepMinutes` minutes. */
export function buildTimeSlots(stepMinutes: number): string[] {
  const step = Math.max(1, Math.min(720, Math.round(stepMinutes)))
  const slots: string[] = []

  for (let minutes = 0; minutes < 1440; minutes += step) {
    slots.push(toTimeString({ hour: Math.floor(minutes / 60), minute: minutes % 60 }))
  }

  return slots
}
