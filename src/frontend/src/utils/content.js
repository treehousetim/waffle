/**
 * Pure helpers for the content pages (docs/content.md). No imports, so
 * `node --test` can run them (test/content.test.js).
 */

const STATUS_LABELS = { draft: 'Draft', published: 'Published', retired: 'Retired' }

/**
 * @param {string} status
 * @returns {string}
 */
export function statusLabel(status) {
  return STATUS_LABELS[status] ?? status
}

/**
 * A date and time from the API ("2026-11-12T18:30:00-06:00") as the list
 * shows it: "2026-11-12 18:30", in the zone it was given in. Empty for none.
 *
 * @param {unknown} value
 * @returns {string}
 */
export function listTime(value) {
  return typeof value === 'string' && value.length >= 16 ? value.slice(0, 16).replace('T', ' ') : ''
}

/**
 * A blank working copy: the fields every record has, and the type's own. A
 * type whose body is blocks starts with none.
 *
 * @param {Array<{ name: string }>} fields the type's own
 * @param {boolean} blocks whether the body is a list of blocks
 * @returns {Record<string, any>}
 */
export function emptyDraft(fields = [], blocks = false) {
  const draft = { title: '', slug: '', summary: '', body: blocks ? [] : '', seo_title: '', seo_description: '' }

  for (const field of fields) {
    draft[field.name] = ''
  }

  return draft
}

/**
 * The editable part of a record from the API, nulls as empty strings, ready
 * for the form. A date-and-time field takes the API's local time with its
 * offset ("2026-11-12T18:30:00-06:00") as the input wants it
 * ("2026-11-12T18:30"): the app reads it back in the same zone.
 *
 * Blocks are copied, so editing them changes nothing until saved.
 *
 * @param {Record<string, unknown>} record
 * @param {Array<{ name: string, input?: string }>} fields
 * @returns {Record<string, any>}
 */
export function draftFrom(record, fields = []) {
  const draft = emptyDraft(fields)

  for (const key of Object.keys(draft)) {
    draft[key] = Array.isArray(record[key]) ? structuredClone(record[key]) : record[key] == null ? '' : String(record[key])
  }

  for (const field of fields) {
    if (field.input === 'datetime-local') {
      draft[field.name] = draft[field.name].slice(0, 16)
    }
  }

  return draft
}
