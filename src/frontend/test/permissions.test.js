import { test } from 'node:test'
import assert from 'node:assert/strict'

import { canSee, safeNext } from '../src/utils/permissions.js'

test('shows a route with no permission to anyone signed in', () => {
  assert.equal(canSee({ meta: {} }, []), true)
  assert.equal(canSee({}, []), true)
})

test('shows a route only to somebody holding its permission', () => {
  const route = { meta: { permission: 'settings-view' } }

  assert.equal(canSee(route, ['home', 'settings-view']), true)
  assert.equal(canSee(route, ['home']), false)
})

test('reads an array of permissions as any of them', () => {
  const route = { meta: { permission: ['settings-view', 'settings-edit'] } }

  assert.equal(canSee(route, ['settings-edit']), true)
  assert.equal(canSee(route, ['home']), false)
})

test('sends a person back only to a path inside the app', () => {
  assert.equal(safeNext('/settings?tab=display'), '/settings?tab=display')
  assert.equal(safeNext('https://evil.example/'), '/')
  assert.equal(safeNext('//evil.example/'), '/')
  assert.equal(safeNext('/\\evil.example'), '/')
  assert.equal(safeNext(['/settings']), '/')
  assert.equal(safeNext(undefined), '/')
})
