import type { Money } from './types'

/** All supported currencies (INR, USD, AED, GBP) use two minor-unit digits. */
export function formatMoney(money: Money | null | undefined, locale?: string): string {
  if (!money) return ''
  const value = money.amount_minor / 100
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: money.currency,
    minimumFractionDigits: value % 1 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(value)
}

/** ICU still reports some legacy zone names; map the common ones to their current IANA names. */
const LEGACY_ZONES: Record<string, string> = {
  'Asia/Calcutta': 'Asia/Kolkata',
  'Asia/Katmandu': 'Asia/Kathmandu',
  'Asia/Saigon': 'Asia/Ho_Chi_Minh',
  'Asia/Rangoon': 'Asia/Yangon',
  'Europe/Kiev': 'Europe/Kyiv',
  'Atlantic/Faeroe': 'Atlantic/Faroe',
  'Africa/Asmera': 'Africa/Asmara',
  'America/Godthab': 'America/Nuuk',
  'Pacific/Truk': 'Pacific/Chuuk',
  'Pacific/Ponape': 'Pacific/Pohnpei',
  'Pacific/Enderbury': 'Pacific/Kanton',
}

export const canonicalTimeZone = (zone: string): string => LEGACY_ZONES[zone] ?? zone

export function browserTimeZone(): string {
  try {
    return canonicalTimeZone(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')
  } catch {
    return 'UTC'
  }
}

export function formatDate(iso: string, timeZone?: string, options: Intl.DateTimeFormatOptions = {}): string {
  return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'long', year: 'numeric', timeZone, ...options }).format(new Date(iso))
}

export function formatTime(iso: string, timeZone?: string): string {
  return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', timeZone }).format(new Date(iso))
}

export function formatDateTime(iso: string, timeZone?: string): string {
  return new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZone,
    timeZoneName: 'short',
  }).format(new Date(iso))
}

/** Parts used for the editorial date block (day numeral, month, weekday). */
export function dateParts(iso: string, timeZone?: string): { day: string; month: string; weekday: string; year: string } {
  const d = new Date(iso)
  const get = (opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(undefined, { ...opts, timeZone }).format(d)
  return { day: get({ day: '2-digit' }), month: get({ month: 'short' }), weekday: get({ weekday: 'long' }), year: get({ year: 'numeric' }) }
}

export function formatDuration(seconds: number | null | undefined): string {
  if (!seconds || seconds < 0) return ''
  const m = Math.floor(seconds / 60)
  const s = Math.floor(seconds % 60)
  return `${m}:${String(s).padStart(2, '0')}`
}

/** "48 hours" → "2 days"; keeps hours when not a whole number of days. */
export function formatNoticePeriod(hours: number): string {
  if (hours >= 24 && hours % 24 === 0) return hours === 24 ? '24 hours' : `${hours / 24} days`
  return hours === 1 ? '1 hour' : `${hours} hours`
}

export function paragraphs(text: string | null | undefined): string[] {
  return (text ?? '')
    .split(/\n{2,}/)
    .map((p) => p.trim())
    .filter(Boolean)
}

export function isExternal(href: string): boolean {
  return /^(https?:)?\/\//i.test(href) || href.startsWith('mailto:') || href.startsWith('tel:')
}
