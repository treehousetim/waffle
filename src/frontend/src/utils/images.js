/**
 * Pure helpers for images in the browser (docs/content.md, "Media"). No
 * imports, so `node --test` can run them (test/images.test.js).
 */

/**
 * The size an image is drawn at before upload: its own, shrunk to fit `max`
 * on its longer side, never enlarged.
 *
 * @param {number} width
 * @param {number} height
 * @param {number} max
 * @returns {{ width: number, height: number }}
 */
export function fitWithin(width, height, max = 2400) {
  const w = Math.max(1, Math.floor(Number(width) || 1))
  const h = Math.max(1, Math.floor(Number(height) || 1))
  const scale = Math.min(1, max / Math.max(w, h))

  return { width: Math.max(1, Math.round(w * scale)), height: Math.max(1, Math.round(h * scale)) }
}
