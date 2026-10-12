import { test } from 'node:test'
import assert from 'node:assert/strict'

import { apiErrorMessage, isAuthEndpoint } from '../src/utils/errors.js'

test('reads the message out of the API error envelope', () => {
  const error = { response: { data: { success: false, error: 'Rows per page must be a whole number.' } } }

  assert.equal(apiErrorMessage(error), 'Rows per page must be a whole number.')
})

test('falls back when the envelope carries no usable message', () => {
  assert.equal(apiErrorMessage({ response: { data: { error: '   ' } } }, 'Nope.'), 'Nope.')
  assert.equal(apiErrorMessage({ response: { status: 500, data: '<html>' } }, 'Nope.'), 'Nope.')
  assert.equal(apiErrorMessage(undefined), 'Something went wrong. Please try again.')
})

test('says the server could not be reached when there was no response at all', () => {
  assert.equal(
    apiErrorMessage({ request: {} }),
    'Could not reach the server. Check your connection and try again.'
  )
})

test('knows which endpoints answer 401 as part of their contract', () => {
  assert.equal(isAuthEndpoint('/auth/login'), true)
  assert.equal(isAuthEndpoint('/auth/me?x=1'), true)
  assert.equal(isAuthEndpoint('http://localhost/api/auth/logout/'), true)
  assert.equal(isAuthEndpoint('/hello'), false)
  assert.equal(isAuthEndpoint('/settings'), false)
  assert.equal(isAuthEndpoint(undefined), false)
})
