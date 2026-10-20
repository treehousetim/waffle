import assert from 'node:assert/strict'
import { test } from 'node:test'

import { draftFrom, emptyDraft, listTime, statusLabel } from '../src/utils/content.js'

test('labels the publish statuses, and passes others through', () => {
  assert.equal(statusLabel('draft'), 'Draft')
  assert.equal(statusLabel('published'), 'Published')
  assert.equal(statusLabel('retired'), 'Retired')
  assert.equal(statusLabel('other'), 'other')
})

test('starts a blank draft with every field, own fields included', () => {
  assert.deepEqual(emptyDraft(), { title: '', slug: '', summary: '', body: '', seo_title: '', seo_description: '' })
  assert.equal(emptyDraft([{ name: 'venue' }]).venue, '')
})

test('fills the form from a record, nulls as empty, extra keys left out', () => {
  const draft = draftFrom({ id: 3, title: 'T', slug: 's', summary: 'S', body: 'B', seo_title: null, venue: 'Hall', starts: 5 }, [
    { name: 'venue' },
    { name: 'starts' }
  ])

  assert.deepEqual(draft, { title: 'T', slug: 's', summary: 'S', body: 'B', seo_title: '', seo_description: '', venue: 'Hall', starts: '5' })
})

test('gives a date-and-time field the local time without its offset', () => {
  const fields = [{ name: 'starts_at', input: 'datetime-local' }, { name: 'ends_at', input: 'datetime-local' }]
  const draft = draftFrom({ starts_at: '2026-11-12T18:30:00-06:00', ends_at: null }, fields)

  assert.equal(draft.starts_at, '2026-11-12T18:30')
  assert.equal(draft.ends_at, '')
})

test('starts a page with no blocks, and copies the blocks it is given', () => {
  assert.deepEqual(emptyDraft([], true).body, [])

  const record = { body: [{ type: 'heading', level: 2, text: 'A' }] }
  const draft = draftFrom(record)
  draft.body[0].text = 'changed'

  assert.equal(record.body[0].text, 'A')
})

test('shows a date and time in a list as it was given, or nothing', () => {
  assert.equal(listTime('2026-11-12T18:30:00-06:00'), '2026-11-12 18:30')
  assert.equal(listTime(null), '')
  assert.equal(listTime('soon'), '')
})
