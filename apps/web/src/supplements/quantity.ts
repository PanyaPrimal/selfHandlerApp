import type { SupplementDisplayUnit } from '../api/types'

function trimDecimal(value: string): string {
  return value.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '')
}

/** Convert the API's exact canonical decimal to the user-selected display unit. */
export function supplementDisplayQuantity(canonical: string, unit: SupplementDisplayUnit): string {
  if (unit !== 'mg') return trimDecimal(canonical)

  const negative = canonical.startsWith('-')
  const unsigned = canonical.replace(/^[+-]/, '')
  const [whole = '0', fraction = ''] = unsigned.split('.')
  const micros = BigInt(whole || '0') * 1_000_000n + BigInt((fraction + '000000').slice(0, 6))
  const milligramsWhole = micros / 1_000n
  const milligramsFraction = String(micros % 1_000n).padStart(3, '0')
  const display = trimDecimal(`${milligramsWhole}.${milligramsFraction}`)

  return negative && display !== '0' ? `-${display}` : display
}
