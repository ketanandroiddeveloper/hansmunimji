import { canonicalTimeZone } from '../../lib/format'

/**
 * Calendar maths on plain `YYYY-MM-DD` strings, so dates never shift with the viewer's own
 * offset. "Today" is always evaluated in the time zone the client is booking in.
 */

export type IsoDate = string

const pad = (n: number) => String(n).padStart(2, '0')

export function toIsoDate(year: number, monthIndex: number, day: number): IsoDate {
  const d = new Date(Date.UTC(year, monthIndex, day))
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`
}

export function parseIsoDate(date: IsoDate): { year: number; monthIndex: number; day: number } {
  const [y, m, d] = date.split('-').map(Number)
  return { year: y, monthIndex: m - 1, day: d }
}

export function todayIn(timeZone: string): IsoDate {
  // en-CA formats as YYYY-MM-DD.
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
}

export function addDays(date: IsoDate, days: number): IsoDate {
  const { year, monthIndex, day } = parseIsoDate(date)
  return toIsoDate(year, monthIndex, day + days)
}

export type Month = { year: number; monthIndex: number }

export const monthOf = (date: IsoDate): Month => {
  const { year, monthIndex } = parseIsoDate(date)
  return { year, monthIndex }
}

export const addMonths = (m: Month, n: number): Month => {
  const d = new Date(Date.UTC(m.year, m.monthIndex + n, 1))
  return { year: d.getUTCFullYear(), monthIndex: d.getUTCMonth() }
}

export const monthKey = (m: Month) => m.year * 12 + m.monthIndex

export const firstOfMonth = (m: Month): IsoDate => toIsoDate(m.year, m.monthIndex, 1)
export const lastOfMonth = (m: Month): IsoDate => toIsoDate(m.year, m.monthIndex + 1, 0)

/** Weeks (Monday first) covering the month; days outside the month are null. */
export function monthGrid(m: Month): (IsoDate | null)[][] {
  const first = new Date(Date.UTC(m.year, m.monthIndex, 1))
  const daysInMonth = new Date(Date.UTC(m.year, m.monthIndex + 1, 0)).getUTCDate()
  const leading = (first.getUTCDay() + 6) % 7
  const cells: (IsoDate | null)[] = Array.from({ length: leading }, () => null)
  for (let d = 1; d <= daysInMonth; d++) cells.push(toIsoDate(m.year, m.monthIndex, d))
  while (cells.length % 7 !== 0) cells.push(null)
  const weeks: (IsoDate | null)[][] = []
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7))
  return weeks
}

export function monthLabel(m: Month): string {
  return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(m.year, m.monthIndex, 1)))
}

export function weekdayLabels(): { short: string; long: string }[] {
  // 2024-01-01 was a Monday.
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(Date.UTC(2024, 0, 1 + i))
    return {
      short: new Intl.DateTimeFormat(undefined, { weekday: 'narrow', timeZone: 'UTC' }).format(d),
      long: new Intl.DateTimeFormat(undefined, { weekday: 'long', timeZone: 'UTC' }).format(d),
    }
  })
}

export function longDateLabel(date: IsoDate): string {
  const { year, monthIndex, day } = parseIsoDate(date)
  return new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(year, monthIndex, day)))
}

const PREFERRED_ZONES = ['Asia/Kolkata', 'Asia/Dubai', 'Europe/London', 'Asia/Singapore', 'America/New_York', 'America/Los_Angeles', 'Europe/Paris', 'Asia/Hong_Kong', 'Australia/Sydney']

/** Time zones for the selector: the visitor's own zone first, then commonly used ones, then all. */
export function timeZoneOptions(current: string): { value: string; label: string }[] {
  let all: string[] = []
  try {
    all = Intl.supportedValuesOf('timeZone').map(canonicalTimeZone)
  } catch {
    all = PREFERRED_ZONES
  }
  const ordered = [...new Set([current, ...PREFERRED_ZONES, ...all])]
  return ordered.map((z) => ({ value: z, label: `${z.replace(/_/g, ' ')} (${offsetLabel(z)})` }))
}

export function offsetLabel(timeZone: string): string {
  try {
    const part = new Intl.DateTimeFormat('en-GB', { timeZone, timeZoneName: 'shortOffset' }).formatToParts(new Date()).find((p) => p.type === 'timeZoneName')
    return part?.value ?? timeZone
  } catch {
    return timeZone
  }
}
