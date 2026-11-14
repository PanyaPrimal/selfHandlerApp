import type { SupplementCourse, SupplementCourseInput } from '../api/types'

/** Unchanged historical starts must not be submitted as a new, past start. */
export function courseUpdate(course: SupplementCourse, payload: SupplementCourseInput): Partial<Omit<SupplementCourseInput, 'supplement_id'>> {
  const { supplement_id: _reference, ...update } = payload
  const result: Partial<typeof update> = update
  if (result.starts_on === course.starts_on) delete result.starts_on
  return result
}
