/**
 * A number of seconds as a person reads it: "45s", "12 min", "3 h 5 min",
 * "2 days". Used by the Queue page for how long the oldest job has waited.
 *
 * Pure, for `node --test` (test/duration.test.js).
 *
 * @param {number} seconds
 * @returns {string}
 */
export function duration(seconds) {
  const s = Math.max(0, Math.floor(Number(seconds) || 0))

  if (s < 60) {
    return `${s}s`
  }

  if (s < 3600) {
    return `${Math.floor(s / 60)} min`
  }

  if (s < 86400) {
    const hours = Math.floor(s / 3600)
    const minutes = Math.floor((s % 3600) / 60)

    return minutes === 0 ? `${hours} h` : `${hours} h ${minutes} min`
  }

  const days = Math.floor(s / 86400)

  return days === 1 ? '1 day' : `${days} days`
}
