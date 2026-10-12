/**
 * Pure helpers for API errors. No imports, so `node --test` can run them
 * without a browser or a bundler (test/errors.test.js).
 */

/**
 * The message out of the API's `{ success: false, error: "..." }` envelope,
 * falling back to something readable for network and transport failures.
 *
 * @param {unknown} error
 * @param {string} fallback
 * @returns {string}
 */
export function apiErrorMessage(error, fallback = 'Something went wrong. Please try again.') {
  const message = error?.response?.data?.error

  if (typeof message === 'string' && message.trim() !== '') {
    return message
  }

  if (error?.response === undefined && error?.request !== undefined) {
    return 'Could not reach the server. Check your connection and try again.'
  }

  return fallback
}

/**
 * Whether a request URL is one of the auth endpoints, which answer 401 as part
 * of their contract rather than to say a session has lapsed.
 *
 * @param {unknown} url
 * @returns {boolean}
 */
export function isAuthEndpoint(url) {
  if (!url) {
    return false
  }

  const path = String(url).split('?')[0].replace(/\/+$/, '')

  return path.startsWith('/auth/') || path.includes('/api/auth/')
}
