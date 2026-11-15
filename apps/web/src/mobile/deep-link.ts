/**
 * Maps a native link from the home-screen widget (`selfhandler://storage?item=42`) to an app route.
 * Only known destinations are accepted, so an arbitrary intent cannot steer the router.
 */
export function widgetRoute(url: string | null | undefined): string | null {
  if (!url) return null
  let parsed: URL
  try {
    parsed = new URL(url)
  } catch {
    return null
  }
  if (parsed.protocol !== 'selfhandler:') return null

  // `selfhandler://storage` parses the destination as the host.
  const destination = `${parsed.host}${parsed.pathname}`.replace(/\/+$/, '')
  const item = parsed.searchParams.get('item')
  const query = item && /^[1-9]\d{0,17}$/.test(item) ? `?item=${item}` : ''
  if (destination === 'storage') return `/storage${query}`

  // `selfhandler://lists/general` or `selfhandler://lists/12`: one checklist.
  const list = /^lists\/(general|[1-9]\d{0,17})$/.exec(destination)
  return list ? `/lists/${list[1]}${query}` : null
}
