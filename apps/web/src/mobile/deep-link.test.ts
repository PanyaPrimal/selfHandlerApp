import { describe, expect, it } from 'vitest'
import { widgetRoute } from './deep-link'

describe('widgetRoute', () => {
  it('opens Storage focused on a widget task', () => {
    expect(widgetRoute('selfhandler://storage?item=42')).toBe('/storage?item=42')
  })

  it('opens Storage without a valid task id', () => {
    expect(widgetRoute('selfhandler://storage')).toBe('/storage')
    expect(widgetRoute('selfhandler://storage?item=0')).toBe('/storage')
    expect(widgetRoute('selfhandler://storage?item=abc')).toBe('/storage')
  })

  it('rejects unknown schemes, destinations and malformed input', () => {
    expect(widgetRoute(null)).toBeNull()
    expect(widgetRoute('')).toBeNull()
    expect(widgetRoute('not a url')).toBeNull()
    expect(widgetRoute('https://example.test/storage?item=1')).toBeNull()
    expect(widgetRoute('selfhandler://settings')).toBeNull()
    expect(widgetRoute('selfhandler://storage/../settings')).toBeNull()
  })
})
