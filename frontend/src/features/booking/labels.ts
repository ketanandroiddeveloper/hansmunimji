import type { MeetingFormat } from '../../lib/types'

export const MEETING_FORMAT: Record<MeetingFormat, { label: string; description: string }> = {
  google_meet: { label: 'Google Meet', description: 'A private video link is shared on confirmation' },
  phone: { label: 'Telephone', description: 'The private office will call you at the agreed time' },
  in_person: { label: 'In person', description: 'At a discreet venue in your chosen city' },
}

export const APPOINTMENT_STATUS: Record<string, { label: string; tone: 'gold' | 'emerald' | 'muted' | 'danger' }> = {
  pending_payment: { label: 'Awaiting payment', tone: 'gold' },
  payment_failed: { label: 'Payment unsuccessful', tone: 'danger' },
  payment_verification: { label: 'Payment being verified', tone: 'gold' },
  confirmed: { label: 'Confirmed', tone: 'emerald' },
  rescheduled: { label: 'Confirmed · rescheduled', tone: 'emerald' },
  pending_application: { label: 'Awaiting application', tone: 'gold' },
  awaiting_approval: { label: 'Awaiting approval', tone: 'gold' },
  completed: { label: 'Completed', tone: 'muted' },
  no_show: { label: 'Not attended', tone: 'muted' },
  expired: { label: 'Reservation expired', tone: 'muted' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
  refunded: { label: 'Refunded', tone: 'muted' },
}

export const TONE_CLASS = {
  gold: 'border-champagne-400/50 text-champagne-200',
  emerald: 'border-emerald-300/50 text-emerald-300',
  muted: 'border-[var(--line-strong)] text-slate-400',
  danger: 'border-danger-300/50 text-danger-300',
} as const
