import assert from 'node:assert/strict'
import { test } from 'node:test'

import { fitWithin } from '../src/utils/images.js'

test('shrinks an image to fit, keeping its shape, and never enlarges one', () => {
  assert.deepEqual(fitWithin(4800, 2400), { width: 2400, height: 1200 })
  assert.deepEqual(fitWithin(1000, 3000, 1500), { width: 500, height: 1500 })
  assert.deepEqual(fitWithin(300, 200), { width: 300, height: 200 })
  assert.deepEqual(fitWithin('x', 0), { width: 1, height: 1 })
})
