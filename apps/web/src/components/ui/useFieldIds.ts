import { computed, type ComputedRef } from 'vue'

export interface FieldIds {
  /** `id` of the interactive element and target of the label's `for`. */
  controlId: string
  helperId: string
  errorId: string
  /** Space-joined list of the description ids that actually exist. */
  describedBy: ComputedRef<string | undefined>
}

let sequence = 0

/**
 * Assemble the identifiers a control shares with its field wrapper.
 *
 * This is the single place `aria-describedby` is built, so helper and error text
 * can never drift out of association with the control they belong to.
 */
export function useFieldIds(
  name: string,
  hasHelper: () => boolean,
  hasError: () => boolean,
): FieldIds {
  sequence += 1
  const base = `ui-${name.replace(/[^a-zA-Z0-9_-]/g, '-')}-${sequence}`
  const helperId = `${base}-helper`
  const errorId = `${base}-error`

  return {
    controlId: `${base}-control`,
    helperId,
    errorId,
    describedBy: computed(() => {
      const ids: string[] = []

      if (hasHelper()) {
        ids.push(helperId)
      }

      if (hasError()) {
        ids.push(errorId)
      }

      return ids.length > 0 ? ids.join(' ') : undefined
    }),
  }
}
