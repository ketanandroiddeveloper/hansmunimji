import type { Tone } from '../ui/Primitives'

type Labelled = Record<string, { label: string; tone: Tone }>

export const APPLICATION_STATUS: Labelled = {
  submitted: { label: 'New', tone: 'gold' },
  under_review: { label: 'In review', tone: 'blue' },
  info_requested: { label: 'Info requested', tone: 'neutral' },
  approved: { label: 'Approved', tone: 'green' },
  invited: { label: 'Invited to book', tone: 'green' },
  converted: { label: 'Booked', tone: 'blue' },
  rejected: { label: 'Declined', tone: 'muted' },
  archived: { label: 'Archived', tone: 'muted' },
}

export const APPOINTMENT_STATUS: Labelled = {
  pending_application: { label: 'Pending application', tone: 'neutral' },
  awaiting_approval: { label: 'Awaiting approval', tone: 'gold' },
  pending_payment: { label: 'Awaiting payment', tone: 'gold' },
  payment_verification: { label: 'Payment needs review', tone: 'red' },
  confirmed: { label: 'Confirmed', tone: 'green' },
  rescheduled: { label: 'Rescheduled', tone: 'green' },
  completed: { label: 'Completed', tone: 'blue' },
  no_show: { label: 'No-show', tone: 'muted' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
  expired: { label: 'Expired unpaid', tone: 'muted' },
  payment_failed: { label: 'Payment failed', tone: 'red' },
  refunded: { label: 'Refunded', tone: 'muted' },
}

export const REGISTRATION_STATUS = {
  pending_application: { label: 'Pending application', tone: 'neutral' },
  pending_payment: { label: 'Awaiting payment', tone: 'gold' },
  confirmed: { label: 'Confirmed', tone: 'green' },
  waitlisted: { label: 'Waitlisted', tone: 'blue' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
  expired: { label: 'Expired unpaid', tone: 'muted' },
  refunded: { label: 'Refunded', tone: 'muted' },
} satisfies Labelled

export const PAYMENT_STATUS: Labelled = {
  created: { label: 'Checkout started', tone: 'neutral' },
  pending: { label: 'Pending', tone: 'gold' },
  captured: { label: 'Captured', tone: 'green' },
  failed: { label: 'Failed', tone: 'red' },
  reconciliation_required: { label: 'Needs reconciliation', tone: 'red' },
  partially_refunded: { label: 'Partly refunded', tone: 'blue' },
  refunded: { label: 'Refunded', tone: 'muted' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
}

export const CALENDAR_STATUS: Labelled = {
  pending: { label: 'Calendar pending', tone: 'gold' },
  processing: { label: 'Calendar syncing', tone: 'gold' },
  synced: { label: 'Calendar synced', tone: 'green' },
  retry_required: { label: 'Calendar retrying', tone: 'gold' },
  failed: { label: 'Calendar failed', tone: 'red' },
  not_required: { label: 'No calendar', tone: 'muted' },
}

export const HISTORY_SOURCE: Record<string, string> = {
  client: 'Client',
  admin: 'Team',
  webhook: 'Gateway webhook',
  verify: 'Checkout return',
  scheduler: 'Scheduled check',
  system: 'System',
}

export const FORMAT_LABEL: Record<string, string> = { google_meet: 'Google Meet', phone: 'Telephone', in_person: 'In person' }

export const REFERRAL_LABEL: Record<string, string> = { referral: 'Personal referral', search: 'Search', event: 'Event', media: 'Media', social: 'Social media', other: 'Other' }

export function humanizeSlug(slug: string | null | undefined): string {
  if (!slug) return '—'
  const s = slug.replace(/[-_]+/g, ' ')
  return s.charAt(0).toUpperCase() + s.slice(1)
}

export function labelOf(map: Labelled, key: string | null | undefined): { label: string; tone: Tone } {
  return (key && map[key]) || { label: key ? key.replace(/_/g, ' ') : '—', tone: 'neutral' }
}

export function toOptions(map: Labelled | Record<string, string>): { value: string; label: string }[] {
  return Object.entries(map).map(([value, v]) => ({ value, label: typeof v === 'string' ? v : v.label }))
}
