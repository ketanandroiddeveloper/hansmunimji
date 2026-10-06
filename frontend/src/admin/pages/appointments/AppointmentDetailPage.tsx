import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { browserTimeZone, formatMoney } from '../../../lib/format'
import { adminApi, errorMessage } from '../../lib/adminApi'
import { formatAdminDateTime, utcToZonedInput, zonedInputToIso } from '../../lib/datetime'
import { APPOINTMENT_STATUS, CALENDAR_STATUS, FORMAT_LABEL, labelOf, PAYMENT_STATUS } from '../../lib/labels'
import { useSession } from '../../lib/session'
import { Dialog, useConfirm } from '../../ui/Dialog'
import { Textarea } from '../../ui/Fields'
import { AButton, Badge, ErrorNote, KeyValues, Notice, PageHeader, Panel, Spinner } from '../../ui/Primitives'
import { StatusHistory } from '../../ui/StatusHistory'
import type { HistoryEntry } from '../../ui/StatusHistory'
import { useToast } from '../../ui/Toast'
import { TimeChooser } from './TimeChooser'
import type { TimeValue } from './TimeChooser'

interface AppointmentDetail {
  id: number
  reference: string
  status: string
  type: { id: number; slug: string; title: string }
  starts_at: string
  ends_at: string
  client_timezone: string
  format: string
  city: string | null
  country: string | null
  application_id: number | null
  client: { name: string | null; email: string | null; phone: string | null }
  notes: string | null
  meet_url: string | null
  amount: { currency: string | null; subtotal_minor: number; tax_minor: number; total_minor: number }
  calendar: { status: string; attempts: number; last_error: string | null; event_id: string | null }
  reschedule_count: number
  cancellation_reason: string | null
  confirmed_at: string | null
  cancelled_at: string | null
  completed_at: string | null
  no_show_at: string | null
  expired_at: string | null
  created_at: string
  payments: { id: number; reference: string; gateway: string; status: string; currency: string; amount_minor: number; refunded_minor: number; created_at: string }[]
  reminders: { offset_minutes: number; run_at: string; status: string; sent_at: string | null }[]
  history: HistoryEntry[]
}

const ACTIVE = ['confirmed', 'rescheduled']
const CANCELLABLE = ['pending_application', 'awaiting_approval', 'pending_payment', 'payment_verification', 'confirmed', 'rescheduled', 'payment_failed']

function offsetLabel(minutes: number): string {
  if (minutes % 1440 === 0) return `${minutes / 1440} day${minutes === 1440 ? '' : 's'} before`
  if (minutes % 60 === 0) return `${minutes / 60} hour${minutes === 60 ? '' : 's'} before`
  return `${minutes} minutes before`
}

export default function AppointmentDetailPage() {
  const { id } = useParams()
  const { can } = useSession()
  const notify = useToast()
  const confirm = useConfirm()
  const client = useQueryClient()
  const appt = useQuery({ queryKey: ['admin', 'appointments', 'item', id], queryFn: () => adminApi.get<AppointmentDetail>(`/admin/appointments/${id}`) })
  const [rescheduling, setRescheduling] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [when, setWhen] = useState<TimeValue>({ date: '', time: '', timezone: browserTimeZone() })
  const [reason, setReason] = useState('')
  const [refund, setRefund] = useState<'policy' | 'refund' | 'none'>('policy')
  const [dialogError, setDialogError] = useState<string | null>(null)

  const refreshAll = (message: string) => {
    client.invalidateQueries({ queryKey: ['admin', 'appointments'] })
    client.invalidateQueries({ queryKey: ['admin', 'dashboard'] })
    notify(message)
  }
  const act = useMutation({
    mutationFn: ({ path, body }: { path: string; body?: unknown }) => adminApi.post(`/admin/appointments/${id}/${path}`, body),
  })

  if (appt.isLoading) return <Spinner />
  if (appt.isError) return <ErrorNote error={appt.error} onRetry={() => appt.refetch()} />
  const a = appt.data!
  const s = labelOf(APPOINTMENT_STATUS, a.status)
  const manage = can('appointments.manage')
  const myZone = browserTimeZone()
  const paid = a.payments.some((p) => ['captured', 'partially_refunded'].includes(p.status))

  const openReschedule = () => {
    const local = utcToZonedInput(a.starts_at, a.client_timezone)
    setWhen({ date: local.slice(0, 10), time: local.slice(11), timezone: a.client_timezone })
    setDialogError(null)
    setRescheduling(true)
  }

  const submitReschedule = () => {
    setDialogError(null)
    const iso = zonedInputToIso(`${when.date}T${when.time}`, when.timezone)
    if (!iso) return setDialogError('Choose a date and start time.')
    act.mutate(
      { path: 'reschedule', body: { starts_at: iso } },
      {
        onSuccess: () => {
          setRescheduling(false)
          refreshAll('Rescheduled. The client has been emailed the new time.')
        },
        onError: (e) => setDialogError(errorMessage(e)),
      },
    )
  }

  const submitCancel = () => {
    setDialogError(null)
    act.mutate(
      { path: 'cancel', body: { reason: reason.trim() || null, refund: refund === 'policy' ? null : refund === 'refund' } },
      {
        onSuccess: () => {
          setCancelling(false)
          refreshAll('Booking cancelled. The client has been emailed.')
        },
        onError: (e) => setDialogError(errorMessage(e)),
      },
    )
  }

  const simple = async (path: 'complete' | 'calendar-sync' | 'no-show' | 'resend-confirmation', title: string, body: string, label: string, success: string) => {
    if (!(await confirm({ title, body, confirmLabel: label }))) return
    act.mutate({ path }, { onSuccess: () => refreshAll(success), onError: (e) => notify(errorMessage(e), 'error') })
  }

  return (
    <>
      <PageHeader
        back={{ to: '/admin/appointments', label: 'Appointments' }}
        title={a.client.name ?? a.reference}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <Badge tone={s.tone}>{s.label}</Badge>
            <span className="font-mono text-xs">{a.reference}</span>
            <span>{a.type.title}</span>
          </span>
        }
        actions={
          manage && (
            <>
              {ACTIVE.includes(a.status) && new Date(a.ends_at) < new Date() && (
                <AButton onClick={() => simple('complete', 'Mark as completed?', 'Records that the session took place.', 'Mark completed', 'Marked as completed.')}>Mark completed</AButton>
              )}
              {ACTIVE.includes(a.status) && new Date(a.starts_at) < new Date() && (
                <AButton onClick={() => simple('no-show', 'Record a no-show?', 'Records that the client did not attend. Payment is retained; reminders stop. A refund can still be issued from Payments.', 'Record no-show', 'Recorded as a no-show.')}>
                  No-show
                </AButton>
              )}
              {ACTIVE.includes(a.status) && (
                <AButton onClick={() => simple('resend-confirmation', 'Resend the confirmation?', 'Emails the client the confirmation with the session details again.', 'Resend', 'Confirmation queued for sending.')}>
                  Resend confirmation
                </AButton>
              )}
              {ACTIVE.includes(a.status) && <AButton onClick={openReschedule}>Reschedule</AButton>}
              {CANCELLABLE.includes(a.status) && (
                <AButton
                  variant="danger"
                  onClick={() => {
                    setDialogError(null)
                    setReason('')
                    setRefund('policy')
                    setCancelling(true)
                  }}
                >
                  Cancel booking
                </AButton>
              )}
            </>
          )
        }
      />

      {a.status === 'payment_verification' && (
        <div className="mb-6">
          <Notice tone="warn">
            {a.payments.some((p) => p.status === 'reconciliation_required')
              ? 'The client was charged, but the captured amount, currency or environment did not match the order. Review the payment in Payments: accept it to confirm this booking, or refund it.'
              : 'The client paid, but the reserved time was taken before the payment completed. Cancelling refunds the payment in full; when automatic conflict refunds are enabled this has already been queued.'}
          </Notice>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[3fr_2fr]">
        <div className="space-y-6">
          <Panel title="Session">
            <KeyValues
              items={[
                ['Client time', `${formatAdminDateTime(a.starts_at, a.client_timezone)} (${a.client_timezone})`],
                ...(a.client_timezone !== myZone ? ([['Your time', `${formatAdminDateTime(a.starts_at, myZone)} (${myZone})`]] as [string, string][]) : []),
                ['Duration', `${Math.round((new Date(a.ends_at).getTime() - new Date(a.starts_at).getTime()) / 60000)} minutes`],
                ['Format', `${FORMAT_LABEL[a.format] ?? a.format}${a.city ? ` · ${a.city}` : ''}`],
                [
                  'Meeting link',
                  a.meet_url ? (
                    <a key="meet" href={a.meet_url} target="_blank" rel="noopener noreferrer" className="break-all text-champagne-200 hover:underline">
                      {a.meet_url}
                    </a>
                  ) : a.format === 'google_meet' ? (
                    'Created when the calendar syncs'
                  ) : (
                    '—'
                  ),
                ],
                ['Rescheduled', a.reschedule_count ? `${a.reschedule_count} time${a.reschedule_count === 1 ? '' : 's'}` : 'No'],
                ...(a.cancellation_reason ? ([['Cancellation reason', a.cancellation_reason]] as [string, string][]) : []),
              ]}
            />
          </Panel>
          <Panel title="Client">
            <KeyValues
              items={[
                ['Name', a.client.name],
                ['Email', a.client.email ? <a key="email" href={`mailto:${a.client.email}`} className="text-champagne-200 hover:underline">{a.client.email}</a> : '—'],
                ['Phone', a.client.phone],
                ['Country', a.country],
                ['Application', a.application_id ? <Link key="app" to={`/admin/applications/${a.application_id}`} className="text-champagne-200 hover:underline">View application</Link> : '—'],
                ['Notes', a.notes ? <span key="notes" className="whitespace-pre-line">{a.notes}</span> : '—'],
              ]}
            />
          </Panel>
        </div>
        <div className="space-y-6">
          <Panel title="Payment">
            {a.amount.total_minor > 0 && a.amount.currency ? (
              <KeyValues
                items={[
                  ['Subtotal', formatMoney({ currency: a.amount.currency, amount_minor: a.amount.subtotal_minor })],
                  ['Tax', formatMoney({ currency: a.amount.currency, amount_minor: a.amount.tax_minor })],
                  ['Total', <strong key="t" className="font-medium text-ivory-50">{formatMoney({ currency: a.amount.currency, amount_minor: a.amount.total_minor })}</strong>],
                ]}
              />
            ) : (
              <p className="text-sm font-light text-slate-400">Complimentary — no payment required.</p>
            )}
            {a.payments.length > 0 && (
              <ul className="mt-4 space-y-2 border-t border-[var(--line)] pt-4 text-sm">
                {a.payments.map((p) => {
                  const ps = labelOf(PAYMENT_STATUS, p.status)
                  return (
                    <li key={p.id} className="flex items-center justify-between gap-3">
                      <span>
                        {can('payments.view') ? (
                          <Link to={`/admin/payments/${p.id}`} className="capitalize text-ivory-50 hover:text-champagne-200 hover:underline">
                            {p.gateway} · {formatMoney({ currency: p.currency, amount_minor: p.amount_minor })}
                          </Link>
                        ) : (
                          <span className="capitalize">
                            {p.gateway} · {formatMoney({ currency: p.currency, amount_minor: p.amount_minor })}
                          </span>
                        )}
                        <span className="block font-mono text-[0.65rem] text-slate-400">{p.reference}</span>
                      </span>
                      <Badge tone={ps.tone}>{ps.label}</Badge>
                    </li>
                  )
                })}
              </ul>
            )}
          </Panel>
          <Panel
            title="Calendar"
            actions={
              manage &&
              ACTIVE.includes(a.status) && (
                <AButton variant="ghost" className="!py-1" onClick={() => simple('calendar-sync', 'Sync with Google Calendar again?', 'Queues the event (and Meet link) to be created or updated.', 'Sync now', 'Calendar sync queued.')}>
                  Sync again
                </AButton>
              )
            }
          >
            <div className="space-y-2 text-sm">
              <Badge tone={labelOf(CALENDAR_STATUS, a.calendar.status).tone}>{labelOf(CALENDAR_STATUS, a.calendar.status).label}</Badge>
              {a.calendar.last_error && <p className="text-xs text-danger-300">{a.calendar.last_error}</p>}
              {a.calendar.attempts > 0 && <p className="text-xs text-slate-400">{a.calendar.attempts} attempt(s)</p>}
            </div>
          </Panel>
          <Panel title="Reminders">
            {a.reminders.length === 0 ? (
              <p className="text-sm font-light text-slate-400">None scheduled.</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {a.reminders.map((r) => (
                  <li key={r.run_at + r.offset_minutes} className="flex items-center justify-between gap-3">
                    <span className="font-light text-ivory-200">{offsetLabel(r.offset_minutes)}</span>
                    <Badge tone={r.status === 'sent' ? 'green' : r.status === 'failed' ? 'red' : r.status === 'cancelled' ? 'muted' : 'gold'}>{r.status}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title="History">
            <KeyValues
              items={[
                ['Booked', formatAdminDateTime(a.created_at)],
                ['Confirmed', formatAdminDateTime(a.confirmed_at)],
                ...(a.cancelled_at ? ([['Cancelled', formatAdminDateTime(a.cancelled_at)]] as [string, string][]) : []),
                ...(a.completed_at ? ([['Completed', formatAdminDateTime(a.completed_at)]] as [string, string][]) : []),
                ...(a.no_show_at ? ([['No-show recorded', formatAdminDateTime(a.no_show_at)]] as [string, string][]) : []),
                ...(a.expired_at ? ([['Expired unpaid', formatAdminDateTime(a.expired_at)]] as [string, string][]) : []),
              ]}
            />
            <div className="mt-5 border-t border-[var(--line)] pt-4">
              <StatusHistory entries={a.history} labels={APPOINTMENT_STATUS} />
            </div>
          </Panel>
        </div>
      </div>

      <Dialog
        open={rescheduling}
        onClose={() => setRescheduling(false)}
        title="Reschedule"
        wide
        footer={
          <>
            <AButton variant="ghost" onClick={() => setRescheduling(false)}>
              Close
            </AButton>
            <AButton variant="primary" loading={act.isPending} onClick={submitReschedule}>
              Move booking
            </AButton>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-sm font-light text-ivory-200">Currently {formatAdminDateTime(a.starts_at, a.client_timezone)} ({a.client_timezone}). The client is emailed the new time and the calendar event is updated.</p>
          <TimeChooser typeSlug={a.type.slug} ignoreId={a.id} value={when} onChange={setWhen} />
          {dialogError && <Notice tone="error">{dialogError}</Notice>}
        </div>
      </Dialog>

      <Dialog
        open={cancelling}
        onClose={() => setCancelling(false)}
        title="Cancel this booking?"
        footer={
          <>
            <AButton variant="ghost" onClick={() => setCancelling(false)}>
              Keep booking
            </AButton>
            <AButton variant="danger" loading={act.isPending} onClick={submitCancel}>
              Cancel booking
            </AButton>
          </>
        }
      >
        <div className="space-y-5">
          <p className="text-sm font-light text-ivory-200">The time is released and the client is emailed.</p>
          {paid && (
            <fieldset className="space-y-2">
              <legend className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">Refund</legend>
              {(
                [
                  ['policy', 'Follow the cancellation policy (refund only if inside the free-cancellation window)'],
                  ['refund', 'Refund the policy percentage regardless of timing'],
                  ['none', 'No refund'],
                ] as const
              ).map(([v, label]) => (
                <label key={v} className="flex cursor-pointer items-start gap-3 text-sm font-light text-ivory-200">
                  <input type="radio" name="refund" className="mt-1 accent-[#c9b07a]" checked={refund === v} onChange={() => setRefund(v)} />
                  {label}
                </label>
              ))}
              <p className="pt-1 text-xs text-slate-400">For a different amount, cancel without a refund and issue a partial refund from Payments.</p>
            </fieldset>
          )}
          <Textarea label="Reason" hint="Kept on the booking record for your team; it is not included in the client’s email." rows={3} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          {dialogError && <Notice tone="error">{dialogError}</Notice>}
        </div>
      </Dialog>
    </>
  )
}
