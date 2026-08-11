import { computed, nextTick, onBeforeUnmount, ref, watch, type CSSProperties, type Ref } from 'vue'
import { autoUpdate, flip, offset, shift, size, useFloating, type Placement } from '@floating-ui/vue'

export interface AnchoredSurfaceOptions {
  /** Preferred side; the surface flips away from it when there is no room. */
  placement?: Placement
  /** Distance between the anchor and the surface, in pixels. */
  gap?: number
  /** Make the surface at least as wide as its anchor. */
  matchWidth?: boolean
  /** Upper bound on the surface height, before the viewport bound is applied. */
  maxHeight?: number
  /** Element that receives focus when the surface closes. Defaults to the anchor. */
  focusTarget?: () => HTMLElement | null
  /** Called before closing so a consumer can restore its own committed value. */
  onDismiss?: (reason: 'escape' | 'outside') => void
}

export interface AnchoredSurface {
  anchorRef: Ref<HTMLElement | null>
  surfaceRef: Ref<HTMLElement | null>
  isOpen: Ref<boolean>
  open: () => void
  close: (options?: { restoreFocus?: boolean }) => void
  toggle: () => void
  surfaceStyle: Ref<CSSProperties>
}

/**
 * Shared behaviour for every overlay in the control layer: viewport-aware
 * positioning, bounded height with internal scrolling, dismissal by Escape or
 * outside pointer, and focus return to the control that opened it.
 *
 * It intentionally owns no ARIA. Roles and states belong to the control, because
 * a listbox, a combobox popup and a dialog need different ones.
 */
export function useAnchoredSurface(options: AnchoredSurfaceOptions = {}): AnchoredSurface {
  const anchorRef = ref<HTMLElement | null>(null)
  const surfaceRef = ref<HTMLElement | null>(null)
  const isOpen = ref(false)
  const availableHeight = ref<number | null>(null)
  const anchorWidth = ref<number | null>(null)

  // The mobile shell docks a fixed navigation bar to the bottom of the viewport.
  // Reserving its band keeps surfaces from opening underneath it at 390px.
  const bottomPadding = ref(12)

  function refreshBottomPadding(): void {
    const isCompact = typeof window !== 'undefined' && window.matchMedia('(max-width: 760px)').matches
    bottomPadding.value = isCompact ? 92 : 12
  }

  refreshBottomPadding()

  const padding = computed(() => ({ top: 12, right: 12, bottom: bottomPadding.value, left: 12 }))

  const middleware = computed(() => [
    offset(options.gap ?? 6),
    flip({ padding: padding.value }),
    shift({ padding: padding.value }),
    size({
      padding: padding.value,
      apply({ availableHeight: available, rects }) {
        availableHeight.value = available
        anchorWidth.value = rects.reference.width
      },
    }),
  ])

  const { floatingStyles } = useFloating(anchorRef, surfaceRef, {
    open: isOpen,
    placement: options.placement ?? 'bottom-start',
    strategy: 'fixed',
    whileElementsMounted: autoUpdate,
    middleware,
  })

  const surfaceStyle = computed<CSSProperties>(() => {
    const style: CSSProperties = { ...floatingStyles.value }
    const ceiling = options.maxHeight ?? 320
    const bounded = availableHeight.value === null
      ? ceiling
      : Math.max(120, Math.min(ceiling, Math.floor(availableHeight.value)))

    style.maxHeight = `${bounded}px`

    if (options.matchWidth && anchorWidth.value !== null) {
      style.minWidth = `${Math.round(anchorWidth.value)}px`
    }

    return style
  })

  function focusBack(): void {
    const target = options.focusTarget?.() ?? anchorRef.value

    target?.focus()
  }

  function open(): void {
    if (isOpen.value) {
      return
    }

    refreshBottomPadding()
    isOpen.value = true
  }

  function close({ restoreFocus = true }: { restoreFocus?: boolean } = {}): void {
    if (!isOpen.value) {
      return
    }

    isOpen.value = false

    if (restoreFocus) {
      void nextTick(focusBack)
    }
  }

  function toggle(): void {
    if (isOpen.value) {
      close()
    } else {
      open()
    }
  }

  function onPointerDown(event: PointerEvent): void {
    const target = event.target as Node | null

    if (!target) {
      return
    }

    if (anchorRef.value?.contains(target) || surfaceRef.value?.contains(target)) {
      return
    }

    options.onDismiss?.('outside')
    // The pointer is already moving focus somewhere else, so do not fight it.
    close({ restoreFocus: false })
  }

  function onKeyDown(event: KeyboardEvent): void {
    if (event.key !== 'Escape') {
      return
    }

    event.stopPropagation()
    options.onDismiss?.('escape')
    close()
  }

  watch(isOpen, (openNow) => {
    if (typeof document === 'undefined') {
      return
    }

    if (openNow) {
      document.addEventListener('pointerdown', onPointerDown, true)
      document.addEventListener('keydown', onKeyDown, true)
      window.addEventListener('resize', refreshBottomPadding)
    } else {
      document.removeEventListener('pointerdown', onPointerDown, true)
      document.removeEventListener('keydown', onKeyDown, true)
      window.removeEventListener('resize', refreshBottomPadding)
      availableHeight.value = null
    }
  })

  onBeforeUnmount(() => {
    if (typeof document === 'undefined') {
      return
    }

    document.removeEventListener('pointerdown', onPointerDown, true)
    document.removeEventListener('keydown', onKeyDown, true)
    window.removeEventListener('resize', refreshBottomPadding)
  })

  return { anchorRef, surfaceRef, isOpen, open, close, toggle, surfaceStyle }
}
