const POUNDS_PER_KILOGRAM = 2.2046226218
const INCHES_PER_METER = 39.37007874

export function metersToCentimeters(meters: number | null): number | null {
  return meters === null ? null : round(meters * 100, 1)
}

export function centimetersToMeters(centimeters: number | null): number | null {
  return centimeters === null ? null : round(centimeters / 100, 3)
}

export function metersToFeetInches(meters: number | null): { feet: number | null, inches: number | null } {
  if (meters === null) return { feet: null, inches: null }
  const totalInches = meters * INCHES_PER_METER
  const feet = Math.floor(totalInches / 12)
  return { feet, inches: round(totalInches - feet * 12, 1) }
}

export function feetInchesToMeters(feet: number | null, inches: number | null): number | null {
  if (feet === null && inches === null) return null
  return round((((feet ?? 0) * 12) + (inches ?? 0)) / INCHES_PER_METER, 3)
}

export function gramsToKilograms(grams: number | null): number | null {
  return grams === null ? null : round(grams / 1000, 2)
}

export function kilogramsToGrams(kilograms: number | null): number | null {
  return kilograms === null ? null : Math.round(kilograms * 1000)
}

export function gramsToPounds(grams: number | null): number | null {
  return grams === null ? null : round((grams / 1000) * POUNDS_PER_KILOGRAM, 1)
}

export function poundsToGrams(pounds: number | null): number | null {
  return pounds === null ? null : Math.round((pounds / POUNDS_PER_KILOGRAM) * 1000)
}

function round(value: number, precision: number): number {
  const factor = 10 ** precision
  return Math.round((value + Number.EPSILON) * factor) / factor
}
