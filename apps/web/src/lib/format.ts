const calendarDateFormat = new Intl.DateTimeFormat('en-GB', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
})

/**
 * Render a calendar date for reading.
 *
 * The API sends calendar dates as `YYYY-MM-DD`, which are days rather than
 * instants, so they are built in local time: parsing them as UTC would show the
 * previous day for anyone west of Greenwich. A full timestamp is accepted too
 * and reduced to its date part.
 */
export function formatCalendarDate(value: string | null | undefined): string {
  if (!value) {
    return ''
  }

  const [year, month, day] = value.slice(0, 10).split('-').map(Number)

  if (!year || !month || !day) {
    return value
  }

  return calendarDateFormat.format(new Date(year, month - 1, day))
}
