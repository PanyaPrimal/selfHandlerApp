export function financeInputAmount(draft: string): string | null {
  const trimmed = draft.trim()
  const match = trimmed.match(/^([+-]?)(\d+)(?:\.(\d{1,4}))?$/)
  if (!match) return null
  const sign = match[1] === '-' ? '-' : ''
  const integer = (match[2] ?? '').replace(/^0+(?=\d)/, '')
  if (integer.length > 15) return null
  const fraction = match[3]
  const canonical = `${sign}${integer}${fraction === undefined ? '' : `.${fraction}`}`
  if (/^-?0(?:\.0{1,4})?$/.test(canonical)) return canonical.replace('-', '')
  return canonical
}

export function financeAmount(amount: string, currency: string, locale: string): string {
  const canonical = financeInputAmount(amount)
  if (canonical === null) return amount
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  }).format(Number(canonical))
}

/** Accept visible grouping without floating-point conversion or guessing a comma's scale. */
export function financeDraftAmount(draft: string, locale: string): string | null {
  let value = draft.trim().replace(/[\u00a0\u202f]/g, ' ')
  if (/^[+-]?\d{1,3}(?:,\s+\d{3})+(?:\.\d{1,4})?$/.test(value)) value = value.replace(/,\s+/g, '')
  if (/^[+-]?\d{1,3}(?: \d{3})+(?:[.,]\d{1,4})?$/.test(value)) value = value.replace(/ /g, '')
  if (locale.startsWith('en') && /^[+-]?\d{1,3}(?:,\d{3})+(?:\.\d{1,4})?$/.test(value)) value = value.replace(/,/g, '')
  else if (/^[+-]?\d+,\d{1,4}$/.test(value)) {
    // 15,000 could mean fifteen or fifteen thousand. Require a clear spelling.
    if (/^[+-]?\d{1,3},\d{3}$/.test(value)) return null
    value = value.replace(',', '.')
  }
  return financeInputAmount(value)
}
