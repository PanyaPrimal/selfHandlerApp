import type { BodyMetricOption, UnitSystem } from '../api/types'

/**
 * Display conversion for body measurements.
 *
 * Values cross the API in the metric's canonical base unit — grams, metres or
 * percent — and are converted here for reading and entry only. The conversion is
 * a division or multiplication by an exact factor and is applied once per
 * direction, so a value entered, displayed and read back is the same quantity.
 */

const FACTORS: Record<string, Record<UnitSystem, number>> = {
  // canonical -> display
  gram: { metric: 1000, imperial: 453.59237 },
  metre: { metric: 0.01, imperial: 0.0254 },
  percent: { metric: 1, imperial: 1 },
}

function factorFor(metric: BodyMetricOption, unitSystem: UnitSystem): number {
  return FACTORS[metric.canonical_unit]?.[unitSystem] ?? 1
}

export function displayUnit(metric: BodyMetricOption, unitSystem: UnitSystem): string {
  return metric.display_unit[unitSystem]
}

/** Canonical value (as stored) to the number the user reads. */
export function toDisplay(
  canonical: string | number | null,
  metric: BodyMetricOption,
  unitSystem: UnitSystem,
  precision = 2,
): number | null {
  if (canonical === null || canonical === '') {
    return null
  }

  const value = Number(canonical)

  if (!Number.isFinite(value)) {
    return null
  }

  return round(value / factorFor(metric, unitSystem), precision)
}

/** The number the user typed back to the canonical value the API expects. */
export function toCanonical(
  display: number | null,
  metric: BodyMetricOption,
  unitSystem: UnitSystem,
): number | null {
  if (display === null || !Number.isFinite(display)) {
    return null
  }

  return round(display * factorFor(metric, unitSystem), 4)
}

function round(value: number, precision: number): number {
  const factor = 10 ** precision

  return Math.round((value + Number.EPSILON) * factor) / factor
}
