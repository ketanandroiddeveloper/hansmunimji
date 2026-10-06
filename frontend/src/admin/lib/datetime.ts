/**
 * Conversions between the API's UTC values and `<input type="datetime-local">` values interpreted
 * in a specific IANA time zone (e.g. an event's own zone, not the editor's browser zone).
 */

function partsIn(ms: number, timeZone: string): Record<string, number> {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(new Date(ms))
  const out: Record<string, number> = {}
  for (const p of parts) if (p.type !== 'literal') out[p.type] = Number(p.value)
  return out
}

function offsetMs(ms: number, timeZone: string): number {
  const p = partsIn(ms, timeZone)
  return Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second) - Math.floor(ms / 1000) * 1000
}

/** Parses "YYYY-MM-DD HH:MM:SS" (UTC, as stored) or any ISO string into epoch milliseconds. */
export function parseUtc(value: string): number {
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(value) ? `${value.replace(' ', 'T')}Z` : value
  return new Date(iso).getTime()
}

/** UTC value → "YYYY-MM-DDTHH:MM" wall-clock time in `timeZone`. */
export function utcToZonedInput(value: string | null | undefined, timeZone: string): string {
  if (!value) return ''
  const ms = parseUtc(value)
  if (Number.isNaN(ms)) return ''
  const p = partsIn(ms, timeZone)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${p.year}-${pad(p.month)}-${pad(p.day)}T${pad(p.hour)}:${pad(p.minute)}`
}

/** "YYYY-MM-DDTHH:MM" wall-clock time in `timeZone` → ISO-8601 UTC string. */
export function zonedInputToIso(local: string, timeZone: string): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(local)
  if (!m) return null
  const asUtc = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5])
  let ms = asUtc - offsetMs(asUtc, timeZone)
  ms = asUtc - offsetMs(ms, timeZone)
  return new Date(ms).toISOString()
}

export function formatAdminDateTime(value: string | null | undefined, timeZone?: string): string {
  if (!value) return '—'
  const ms = parseUtc(value)
  if (Number.isNaN(ms)) return '—'
  return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(ms))
}

export function formatAdminDate(value: string | null | undefined): string {
  if (!value) return '—'
  const ms = parseUtc(value.length === 10 ? `${value}T00:00:00Z` : value)
  return Number.isNaN(ms) ? '—' : new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: value.length === 10 ? 'UTC' : undefined }).format(new Date(ms))
}
