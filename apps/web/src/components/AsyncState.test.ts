// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h, nextTick, onUnmounted, reactive, type App } from 'vue'
import AsyncState from './AsyncState.vue'

vi.mock('../i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

let app: App | undefined
afterEach(() => { app?.unmount(); document.body.replaceChildren() })

function screen(initial = { loading: true, error: null as string | null, empty: false }) {
  const state = reactive({ ...initial, key: 'day-one' })
  const removed = vi.fn()
  const retry = vi.fn()
  const Child = defineComponent({ setup() {
    onUnmounted(removed)
    return () => h('details', { open: true }, [h('summary', 'Details'), h('input', { 'aria-label': 'Draft' })])
  } })
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp(() => h(AsyncState, { ...state, onRetry: retry }, { default: () => h(Child) }))
  app.mount(root)
  return { state, root, removed, retry }
}

describe('async screen continuity', () => {
  it('keeps the focused input, draft and disclosure mounted through refresh and retry', async () => {
    const { state, root, removed, retry } = screen()
    expect(root.querySelector('input')).toBeNull()
    state.loading = false
    await nextTick()
    const input = root.querySelector('input')!
    input.value = 'Unsaved draft'
    input.focus()
    state.loading = true
    await nextTick()
    expect(root.querySelector('input')).toBe(input)
    expect(document.activeElement).toBe(input)
    expect(root.querySelector('details')!.open).toBe(true)
    state.loading = false
    state.error = 'Refresh failed'
    await nextTick()
    expect(root.querySelector('[role="alert"]')?.textContent).toContain('Refresh failed')
    expect(root.querySelector('input')!.value).toBe('Unsaved draft')
    expect(document.activeElement).toBe(input)
    root.querySelector('button')!.click()
    expect(retry).toHaveBeenCalledOnce()
    state.error = null
    state.loading = true
    await nextTick()
    state.loading = false
    await nextTick()
    expect(root.querySelector('input')).toBe(input)
    expect(removed).not.toHaveBeenCalled()
  })

  it('keeps an initial failure separate from available content', async () => {
    const { state, root } = screen()
    state.loading = false
    state.error = 'Initial load failed'
    await nextTick()
    await nextTick()
    expect(root.querySelector('input')).toBeNull()
    expect(document.activeElement).toBe(root.querySelector('[role="alert"]'))
    state.loading = true
    state.error = null
    await nextTick()
    expect(root.querySelector('input')).toBeNull()
    state.loading = false
    await nextTick()
    expect(root.querySelector('input')).not.toBeNull()
  })

  it('does not keep an old day interactive after the context changes', async () => {
    const { state, root, removed } = screen({ loading: false, error: null, empty: false })
    const old = root.querySelector('input')!
    state.loading = true
    state.key = 'day-two'
    await nextTick()
    expect(root.querySelector('input')).toBeNull()
    expect(removed).toHaveBeenCalledOnce()
    state.loading = false
    await nextTick()
    expect(root.querySelector('input')).not.toBe(old)
  })

  it('keeps the empty state during refresh and updates it on success', async () => {
    const { state, root } = screen({ loading: false, error: null, empty: true })
    state.loading = true
    state.empty = false
    await nextTick()
    expect(root.querySelector('input')).toBeNull()
    expect(root.textContent).toContain('common.nothingHere')
    state.loading = false
    await nextTick()
    expect(root.querySelector('input')).not.toBeNull()
    state.empty = true
    await nextTick()
    expect(root.querySelector('input')).toBeNull()
  })
})
