/**
 * Whether a person may see a route, from its `meta.permission`.
 *
 * The same answer drives the router guard and the navigation, so a link and
 * its page can never disagree. A route with no permission is open to anyone
 * signed in; an array means "any of these". The server enforces the same grid
 * (docs/access-levels.md) -- this only decides what is shown.
 *
 * Pure, for `node --test` (test/permissions.test.js).
 *
 * @param {{ meta?: { permission?: string | string[] } }} route
 * @param {Iterable<string>} held the person's permission values
 * @returns {boolean}
 */
export function canSee(route, held) {
  const needed = route?.meta?.permission

  if (needed === undefined || needed === null) {
    return true
  }

  const permissions = new Set(held)
  const anyOf = Array.isArray(needed) ? needed : [needed]

  return anyOf.some((permission) => permissions.has(permission))
}

/**
 * Where to go after signing in: the `next` the guard recorded, but only a path
 * inside this app. Anything else -- another origin, a protocol-relative
 * `//host`, a backslash trick -- goes home instead.
 *
 * @param {unknown} next
 * @returns {string}
 */
export function safeNext(next) {
  if (typeof next !== 'string' || !next.startsWith('/') || next.startsWith('//') || next.includes('\\')) {
    return '/'
  }

  return next
}
