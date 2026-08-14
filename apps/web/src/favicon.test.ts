// @vitest-environment jsdom

import { beforeEach, describe, expect, it } from 'vitest'
import { compassFaviconSvg, faviconPalette, initializeFavicon, updateFavicon } from './favicon'

describe('theme-aware compass favicon', () => {
  beforeEach(() => {
    document.head.innerHTML = '<link rel="icon" type="image/svg+xml" href="/favicon.svg">'
    document.documentElement.removeAttribute('style')
    document.documentElement.dataset.theme = 'light'
  })

  it('uses the selected light theme background and accent tokens', () => {
    const root = document.documentElement
    root.style.setProperty('--ink', '#20272b')
    root.style.setProperty('--surface', '#fcfeff')
    root.style.setProperty('--accent', '#4f6d8a')

    expect(faviconPalette(root)).toEqual({
      shell: '#20272b',
      foreground: '#fcfeff',
      accent: '#4f6d8a',
    })
  })

  it('switches to dark surface tokens and preserves a custom accent', () => {
    const root = document.documentElement
    root.dataset.theme = 'dark'
    root.style.setProperty('--surface', '#20292e')
    root.style.setProperty('--ink', '#edf2f4')
    root.style.setProperty('--accent', '#d16c9e')

    updateFavicon(root)

    const link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]')
    const svg = decodeURIComponent(link?.href.split(',')[1] ?? '')
    expect(svg).toContain('fill="#20292e"')
    expect(svg).toContain('stroke="#edf2f4"')
    expect(svg).toContain('fill="#d16c9e"')
  })

  it('refreshes automatically when the live theme tokens change', async () => {
    const root = document.documentElement
    root.style.setProperty('--ink', '#232220')
    root.style.setProperty('--surface', '#ffffff')
    root.style.setProperty('--accent', '#3d6b4e')
    initializeFavicon()

    root.style.setProperty('--accent', '#9a553f')
    await new Promise((resolve) => setTimeout(resolve, 0))

    const link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]')
    const svg = decodeURIComponent(link?.href.split(',')[1] ?? '')
    expect(svg).toContain('fill="#9a553f"')
    expect(svg).not.toContain('fill="#3d6b4e"')
  })

  it('rejects values that could escape SVG colour attributes', () => {
    const svg = compassFaviconSvg({
      shell: 'red"/><script>alert(1)</script>',
      foreground: '#fffaf2',
      accent: '#b57a2f',
    })

    expect(svg).not.toContain('<script>')
    expect(svg).toContain('fill="#27334d"')
  })
})
