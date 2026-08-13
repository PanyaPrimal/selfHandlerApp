export function nutritionProgressPercent(consumed: number, target: number | null): string | null {
  if (target === null || target <= 0) return null
  return ((consumed / target) * 100).toFixed(2)
}

export function nutritionTargetCopyKey(status: 'ready' | 'incomplete'):
  'nutrition.target.ready' | 'nutrition.target.incomplete' {
  return status === 'ready' ? 'nutrition.target.ready' : 'nutrition.target.incomplete'
}

export function createNutritionMutationQueue() {
  let tail: Promise<unknown> = Promise.resolve()

  return function enqueue<T>(mutation: () => Promise<T>): Promise<T> {
    const accepted = tail.then(mutation, mutation)
    tail = accepted.then(() => undefined, () => undefined)
    return accepted
  }
}
