export function safeRedirect(value: unknown, fallback = '/'): string {
  const candidate = Array.isArray(value) ? value[0] : value

  if (typeof candidate !== 'string' || !candidate.startsWith('/') || candidate.startsWith('//')) {
    return fallback
  }

  try {
    const target = new URL(candidate, window.location.origin)

    if (target.origin !== window.location.origin || ['/login', '/register'].includes(target.pathname)) {
      return fallback
    }

    return `${target.pathname}${target.search}${target.hash}`
  } catch {
    return fallback
  }
}
