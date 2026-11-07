/**
 * Pure helpers for invitations (docs/invitations.md). No imports, so
 * `node --test` can run them (test/invitations.test.js).
 */

const STATUS_LABELS = { pending: 'Waiting', accepted: 'Accepted', revoked: 'Revoked', expired: 'Expired' }

/**
 * @param {string} status
 * @returns {string}
 */
export function invitationStatusLabel(status) {
  return STATUS_LABELS[status] ?? status
}
