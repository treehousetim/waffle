/**
 * Pure helpers for the block editor (docs/content.md, "Blocks"). No imports,
 * so `node --test` can run them (test/blocks.test.js).
 */

/** The block types staff can add, in the order the editor offers them. */
export const BLOCK_TYPES = [
  { type: 'heading', label: 'Heading' },
  { type: 'text', label: 'Text' },
  { type: 'quote', label: 'Quote' },
  { type: 'callout', label: 'Callout' },
  { type: 'faq', label: 'Questions and answers' },
  { type: 'cta', label: 'Call to action' },
  { type: 'table', label: 'Table' }
]

/**
 * A new, empty block of a type.
 *
 * @param {string} type
 * @returns {Record<string, unknown>}
 */
export function newBlock(type) {
  switch (type) {
    case 'heading':
      return { type, level: 2, text: '' }
    case 'quote':
      return { type, text: '', cite: '' }
    case 'callout':
      return { type, tone: 'note', markdown: '' }
    case 'faq':
      return { type, items: [{ question: '', answer: '' }] }
    case 'cta':
      return { type, label: '', path: '/', note: '' }
    case 'table':
      return { type, columns: [''], rows: [] }
    default:
      return { type: 'text', markdown: '' }
  }
}

/**
 * A copy of the list with one block moved by `by` places, staying in range.
 *
 * @template T
 * @param {T[]} blocks
 * @param {number} index
 * @param {number} by
 * @returns {T[]}
 */
export function moveBlock(blocks, index, by) {
  const to = Math.min(blocks.length - 1, Math.max(0, index + by))
  const moved = [...blocks]
  const [block] = moved.splice(index, 1)
  moved.splice(to, 0, block)
  return moved
}

/**
 * A table as text to edit: the columns on the first line and a row on each
 * line after, cells separated by " | ".
 *
 * @param {{ columns: string[], rows: string[][] }} table
 * @returns {string}
 */
export function tableToText({ columns, rows }) {
  return [columns, ...rows].map((cells) => cells.join(' | ')).join('\n')
}

/**
 * The text back as columns and rows. Blank lines are skipped; a row short of
 * cells is filled with empty ones, so the app sees every row the right width.
 *
 * @param {string} text
 * @returns {{ columns: string[], rows: string[][] }}
 */
export function textToTable(text) {
  const lines = text.split('\n').filter((line) => line.trim() !== '')
  const [header = '', ...body] = lines.map((line) => line.split('|').map((cell) => cell.trim()))
  const columns = Array.isArray(header) ? header : ['']
  const rows = body.map((cells) => columns.map((_, index) => cells[index] ?? ''))
  return { columns, rows }
}
