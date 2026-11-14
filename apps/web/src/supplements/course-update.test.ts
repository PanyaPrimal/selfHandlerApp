import { describe, expect, it } from 'vitest'
import type { SupplementCourse, SupplementCourseInput } from '../api/types'
import { courseUpdate } from './course-update'

const payload = (starts_on: string) => ({ supplement_id: 7, starts_on, ends_on: '2026-12-31' }) as unknown as SupplementCourseInput
const course = { starts_on: '2026-10-01' } as SupplementCourse

describe('courseUpdate', () => {
  it('omits the reference and an unchanged historical start', () => {
    const result = courseUpdate(course, payload('2026-10-01'))
    expect(result).not.toHaveProperty('supplement_id')
    expect(result).not.toHaveProperty('starts_on')
    expect(result).toHaveProperty('ends_on', '2026-12-31')
  })

  it('keeps a changed start for server validation', () => {
    expect(courseUpdate(course, payload('2026-11-20'))).toHaveProperty('starts_on', '2026-11-20')
  })
})
