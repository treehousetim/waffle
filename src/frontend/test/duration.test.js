import assert from 'node:assert/strict'
import { test } from 'node:test'

import { duration } from '../src/utils/duration.js'

test('seconds under a minute', () => {
  assert.equal(duration(0), '0s')
  assert.equal(duration(45), '45s')
})

test('minutes under an hour', () => {
  assert.equal(duration(60), '1 min')
  assert.equal(duration(3599), '59 min')
})

test('hours, with minutes when there are any', () => {
  assert.equal(duration(3600), '1 h')
  assert.equal(duration(3600 + 5 * 60), '1 h 5 min')
})

test('days', () => {
  assert.equal(duration(86400), '1 day')
  assert.equal(duration(3 * 86400 + 10), '3 days')
})

test('nonsense reads as nothing waited', () => {
  assert.equal(duration(-5), '0s')
  assert.equal(duration('abc'), '0s')
  assert.equal(duration(undefined), '0s')
})
