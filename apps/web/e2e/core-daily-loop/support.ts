import { expect, type Page, type Request } from '@playwright/test'

/**
 * Start recording browser problems that a passing assertion would otherwise
 * hide: console warnings/errors, uncaught page errors, failed requests, and
 * API responses that came back as an error.
 *
 * Returns the growing list so a spec can assert on it at the end of a journey.
 */
export function collectRuntimeIssues(page: Page): string[] {
  const issues: string[] = []
  const successfulNoContentRequests = new WeakSet<Request>()

  page.on('console', (message) => {
    const text = message.text()

    if (text.includes('[vite]')) {
      return
    }

    if (message.type() === 'warning' || message.type() === 'error') {
      issues.push(`[console:${message.type()}] ${text}`)
    }
  })

  page.on('pageerror', (error) => {
    issues.push(`[pageerror] ${error.message}`)
  })

  page.on('requestfailed', (request) => {
    // Chromium can report net::ERR_ABORTED after a fully received 204 from the
    // PHP development server. A paired successful response is not a runtime
    // failure and the calling fetch has already resolved normally.
    if (successfulNoContentRequests.has(request)) {
      return
    }

    // A hard navigation legitimately cancels read-only fetches owned by the
    // document being replaced. Mutating requests remain reportable so an
    // interrupted save can never be hidden by this navigation allowance.
    if (request.method() === 'GET' && request.failure()?.errorText === 'net::ERR_ABORTED') {
      return
    }

    issues.push(`[requestfailed] ${request.method()} ${request.url()} ${request.failure()?.errorText}`)
  })

  page.on('response', (response) => {
    const url = new URL(response.url())

    if (response.status() === 204) {
      successfulNoContentRequests.add(response.request())
    }

    // An anonymous session probe answering 401 is the expected guest path.
    if (response.status() === 401 && url.pathname === '/api/auth/user') {
      return
    }

    if (response.url().includes('/api/') && response.status() >= 400) {
      issues.push(`[response] ${response.status()} ${response.request().method()} ${response.url()}`)
    }
  })

  return issues
}

export function expectNoRuntimeIssues(issues: string[]): void {
  expect(issues).toEqual([])
}
