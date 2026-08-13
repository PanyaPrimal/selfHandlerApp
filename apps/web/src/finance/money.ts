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
