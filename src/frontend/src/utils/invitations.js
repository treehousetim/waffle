/**
 * Pure helpers for invitations (docs/invitations.md). No imports, so
 * `node --test` can run them (test/invitations.test.js).
 */

/**
 * The link to send the invited person.
 *
 * @param {string} origin  the app's origin: window.location.origin
 * @param {string} token
 * @returns {string}
 */
export function inviteLink(origin, token) {
  return `${origin.replace(/\/+$/, '')}/invite/${encodeURIComponent(token)}`
}

const STATUS_LABELS = { pending: 'Waiting', accepted: 'Accepted', revoked: 'Revoked', expired: 'Expired' }

/**
 * @param {string} status
 * @returns {string}
 */
export function invitationStatusLabel(status) {
  return STATUS_LABELS[status] ?? status
}
