import assert from 'node:assert/strict'
import { test } from 'node:test'

import { invitationStatusLabel, inviteLink } from '../src/utils/invitations.js'

test('builds the link from the app\'s origin and the token', () => {
  assert.equal(inviteLink('https://app.example.test', 'abc_-1'), 'https://app.example.test/invite/abc_-1')
  assert.equal(inviteLink('http://localhost:8080/', 'x y'), 'http://localhost:8080/invite/x%20y')
})

test('labels the statuses, and passes others through', () => {
  assert.equal(invitationStatusLabel('pending'), 'Waiting')
  assert.equal(invitationStatusLabel('accepted'), 'Accepted')
  assert.equal(invitationStatusLabel('revoked'), 'Revoked')
  assert.equal(invitationStatusLabel('expired'), 'Expired')
  assert.equal(invitationStatusLabel('other'), 'other')
})
