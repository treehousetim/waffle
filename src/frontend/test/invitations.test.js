import assert from 'node:assert/strict'
import { test } from 'node:test'

import { invitationStatusLabel } from '../src/utils/invitations.js'

test('labels the statuses, and passes others through', () => {
  assert.equal(invitationStatusLabel('pending'), 'Waiting')
  assert.equal(invitationStatusLabel('accepted'), 'Accepted')
  assert.equal(invitationStatusLabel('revoked'), 'Revoked')
  assert.equal(invitationStatusLabel('expired'), 'Expired')
  assert.equal(invitationStatusLabel('other'), 'other')
})
