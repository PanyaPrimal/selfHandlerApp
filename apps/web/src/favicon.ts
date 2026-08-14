interface CompassFaviconPalette {
  shell: string
  foreground: string
  accent: string
}

const FALLBACK_PALETTE: CompassFaviconPalette = {
  shell: '#27334d',
  foreground: '#f6f0e4',
  accent: '#e5a84e',
}

const CSS_COLOUR = /^(?:#[0-9a-f]{3,8}|(?:rgb|hsl)a?\([0-9.,%\s/]+\))$/i

let observer: MutationObserver | null = null

function safeColour(value: string, fallback: string): string {
  const candidate = value.trim()
  return CSS_COLOUR.test(candidate) ? candidate : fallback
}

function token(styles: CSSStyleDeclaration, name: string, fallback: string): string {
  return safeColour(styles.getPropertyValue(name), fallback)
}

export function compassFaviconSvg(palette: CompassFaviconPalette): string {
  const shell = safeColour(palette.shell, FALLBACK_PALETTE.shell)
  const foreground = safeColour(palette.foreground, FALLBACK_PALETTE.foreground)
  const accent = safeColour(palette.accent, FALLBACK_PALETTE.accent)

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>SelfHandler — Compass</title><rect x="2" y="2" width="60" height="60" rx="17" fill="${shell}"/><circle cx="32" cy="32" r="19" fill="none" stroke="${foreground}" stroke-width="3" opacity=".32"/><path d="M32 9.5 39.5 31 32 27.5 24.5 31Z" fill="${accent}" stroke="${foreground}" stroke-width="1.25" stroke-linejoin="round"/><path d="M32 54.5 24.5 33 32 36.5 39.5 33Z" fill="${foreground}"/><circle cx="32" cy="32" r="4.3" fill="${foreground}" stroke="${shell}" stroke-width="2"/></svg>`
}

export function faviconPalette(root: HTMLElement = document.documentElement): CompassFaviconPalette {
  const styles = getComputedStyle(root)
  const dark = root.dataset.theme === 'dark'

  return {
    shell: token(styles, dark ? '--surface' : '--ink', FALLBACK_PALETTE.shell),
    foreground: token(styles, dark ? '--ink' : '--surface', FALLBACK_PALETTE.foreground),
    accent: token(styles, '--accent', FALLBACK_PALETTE.accent),
  }
}

export function updateFavicon(root: HTMLElement = document.documentElement): void {
  const link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]')
  if (!link) return

  link.type = 'image/svg+xml'
  link.href = `data:image/svg+xml,${encodeURIComponent(compassFaviconSvg(faviconPalette(root)))}`
}

export function initializeFavicon(): void {
  observer?.disconnect()
  updateFavicon()

  observer = new MutationObserver(() => updateFavicon())
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme', 'data-accent', 'data-background', 'style'],
  })
}
