import assert from 'node:assert/strict'
import { test } from 'node:test'

import { BLOCK_TYPES, moveBlock, newBlock, tableToText, textToTable } from '../src/utils/blocks.js'

test('offers a new empty block of every type, and text for anything else', () => {
  for (const { type } of BLOCK_TYPES) {
    assert.equal(newBlock(type).type, type)
  }

  assert.deepEqual(newBlock('heading'), { type: 'heading', level: 2, text: '' })
  assert.deepEqual(newBlock('faq').items, [{ question: '', answer: '' }])
  assert.deepEqual(newBlock('image'), { type: 'image', media_id: null, caption: '' })
  assert.deepEqual(newBlock('gallery'), { type: 'gallery', media_ids: [], caption: '' })
  assert.deepEqual(newBlock('nonsense'), { type: 'text', markdown: '' })
})

test('moves a block up or down, never out of the list', () => {
  assert.deepEqual(moveBlock(['a', 'b', 'c'], 0, 1), ['b', 'a', 'c'])
  assert.deepEqual(moveBlock(['a', 'b', 'c'], 2, -1), ['a', 'c', 'b'])
  assert.deepEqual(moveBlock(['a', 'b', 'c'], 0, -1), ['a', 'b', 'c'])
  assert.deepEqual(moveBlock(['a', 'b', 'c'], 2, 1), ['a', 'b', 'c'])
})

test('edits a table as lines of cells, and reads it back the same width', () => {
  const table = { columns: ['Day', 'Time'], rows: [['Thursday', '6:30 PM']] }

  assert.equal(tableToText(table), 'Day | Time\nThursday | 6:30 PM')
  assert.deepEqual(textToTable('Day | Time\nThursday | 6:30 PM'), table)
  assert.deepEqual(textToTable('Day | Time\n\nFriday'), { columns: ['Day', 'Time'], rows: [['Friday', '']] })
  assert.deepEqual(textToTable(''), { columns: [''], rows: [] })
})
