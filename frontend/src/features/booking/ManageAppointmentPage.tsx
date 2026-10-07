import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { useNavigate, useParams } from 'react-router'
import { FormAlert, TextArea } from '../../components/form/Fields'
import { Seo } from '../../components/seo/Seo'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { ConfidentialitySeal, Glow } from '../../components/ui/Ornaments'
import { ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { captureAccessToken } from '../../lib/accessTokens'
import { api, ApiError } from '../../lib/api'
import { browserTimeZone, formatDateTime, formatMoney } from '../../lib/format'
import { buildIcs, downloadIcs } from '../../lib/ics'
import { useSettings } from '../../lib/queries'
import type { PaymentAttempt } from '../../lib/payments'
import type { MeetingFormat } from '../../lib/types'
import { PaymentPanel } from '../payments/PaymentPanel'
import { useCheckoutReturn } from '../payments/useCheckoutReturn'
import { APPOINTMENT_STATUS, MEETING_FORMAT, TONE_CLASS } from './labels'
import { SlotPicker, type Slot } from './SlotPicker'

interface ClientAppointment {
  reference: string
  status: string
  type: { slug: string; title: string; duration_minutes: number; max_advance_days: number }
  starts_at: string
  ends_at: string
  timezone: string
  format: MeetingFormat
  city: string | null
  client_name: string | null
  meet_url: string | null
  calendar_sync_status: string
  amount: { currency: string | null; subtotal_minor: number; tax_minor: number; total_minor: number }
  hold_expires_at: string | null
  country: string | null
  payment: PaymentAttempt | null
  can_reschedule: boolean
  can_cancel: boolean
  refund_eligible: boolean
  cancellation_policy: string | null
  reschedule_policy: string | null
}

type Panel = 'none' | 'reschedule' | 'cancel'

export default function ManageAppointmentPage() {
  const reference = (useParams().reference ?? '').toUpperCase()
  const navigate = useNavigate()
  const [token] = useState(() => captureAccessToken('appointment', reference))
  const queryClient = useQueryClient()
  const settings = useSettings()
  const [panel, setPanel] = useState<Panel>('none')
  const [flash, setFlash] = useState<string | null>(null)

  const appointment = useQuery({
    queryKey: ['appointment', reference],
    queryFn: () => api.get<ClientAppointment>(`/appointments/${encodeURIComponent(reference)}`, { headers: { 'X-Access-Token': token ?? '' } }),
    enabled: Boolean(token),
    staleTime: 0,
  })

  const checkout = useCheckoutReturn((result) => {
    setFlash(result.status === 'confirmed' ? 'Thank you — your payment has been verified and your consultation is confirmed.' : null)
    appointment.refetch()
  })

  const refresh = (data?: ClientAppointment) => {
    if (data) queryClient.setQueryData(['appointment', reference], data)
    else appointment.refetch()
  }

  const finish = (data: ClientAppointment, message: string) => {
    setPanel('none')
    setFlash(message)
    refresh(data)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const contact = settings.data?.['contact.email']

  if (!token) {
    return (
      <Shell>
        <Notice title="Please use your private link.">
          For your privacy, bookings can only be viewed from the personal link in your confirmation email{contact ? <> — or contact the private office at <a className="link-underline text-ivory-50" href={`mailto:${contact}`}>{contact}</a></> : null}.
        </Notice>
      </Shell>
    )
  }
  if (appointment.isLoading || checkout.verifying) return <LoadingBlock className="min-h-[90vh]" label={checkout.verifying ? 'Confirming your payment' : 'Retrieving your booking'} />
  if (appointment.isError) {
    const e = appointment.error
    if (e instanceof ApiError && (e.status === 404 || e.status === 410)) {
      return (
        <Shell>
          <Notice title={e.status === 410 ? 'This link has expired.' : 'We could not find this booking.'}>
            {e.status === 410 ? 'Links expire some time after a session has taken place.' : 'The link may be incomplete. Please open it again from your email.'}
          </Notice>
        </Shell>
      )
    }
    return <ErrorBlock className="min-h-[90vh]" error={e} onRetry={() => appointment.refetch()} />
  }

  const a = appointment.data!
  const status = APPOINTMENT_STATUS[a.status] ?? { label: a.status, tone: 'muted' as const }
  const viewerTz = browserTimeZone()
  const awaitingPayment = a.status === 'pending_payment' || a.status === 'payment_failed'
  const active = a.status === 'confirmed' || a.status === 'rescheduled'
  const money = a.amount.currency && a.amount.total_minor > 0 ? { currency: a.amount.currency, amount_minor: a.amount.total_minor } : null

  return (
    <Shell>
      <div className="grid gap-16 lg:grid-cols-12 lg:gap-10">
        <div className="lg:col-span-7">
          <p className="eyebrow flex items-center gap-4">
            <span aria-hidden="true" className="h-px w-10 bg-champagne-400/70" />
            Your consultation · {a.reference}
          </p>
          <h1 className="mt-8 text-h1">{a.type.title}</h1>
          <p className={`mt-6 inline-flex border px-3 py-1.5 text-[0.66rem] uppercase tracking-[0.24em] ${TONE_CLASS[status.tone]}`}>{status.label}</p>

          <div className="mt-10 space-y-4" aria-live="polite">
            {flash && <FormAlert tone="success">{flash}</FormAlert>}
            {checkout.error && <FormAlert>{checkout.error}</FormAlert>}
            {checkout.cancelled && awaitingPayment && <FormAlert tone="info">Payment was not completed. Your time remains held for a short while — you can try again below.</FormAlert>}
            {a.status === 'payment_verification' && (
              <FormAlert tone="info">Your payment has been received and is being verified. The private office will confirm your booking personally, and any payment will be refunded in full if the time can no longer be honoured.</FormAlert>
            )}
            {a.status === 'expired' && (
              <FormAlert tone="info">
                This reservation expired because payment was not completed in time, and the time has been released. You are welcome to choose a new time. If a payment you made still reaches us, the booking is
                confirmed if the time is free, or refunded in full.
              </FormAlert>
            )}
          </div>

          <dl className="mt-12 divide-y divide-[var(--line)] border-y border-[var(--line)]">
            <Detail label="When">
              <span className="block text-ivory-50">{formatDateTime(a.starts_at, a.timezone)}</span>
              {viewerTz !== a.timezone && <span className="mt-1 block text-xs text-slate-400">{formatDateTime(a.starts_at, viewerTz)} where you are now</span>}
            </Detail>
            <Detail label="Duration">{a.type.duration_minutes} minutes</Detail>
            <Detail label="Format">
              {MEETING_FORMAT[a.format]?.label ?? a.format}
              {a.city && ` · ${a.city}`}
            </Detail>
            {active && a.format === 'google_meet' && (
              <Detail label="Meeting link">
                {a.meet_url ? (
                  <a href={a.meet_url} target="_blank" rel="noopener noreferrer" className="link-underline text-champagne-200">
                    Join on Google Meet
                  </a>
                ) : (
                  <span className="text-slate-400">Your private link will be shared before the session.</span>
                )}
              </Detail>
            )}
            {money && (
              <Detail label="Fee">
                {formatMoney(money)}
                {a.amount.tax_minor > 0 && <span className="ml-2 text-xs text-slate-400">incl. {formatMoney({ currency: money.currency, amount_minor: a.amount.tax_minor })} tax</span>}
              </Detail>
            )}
          </dl>

          {active && (
            <div className="mt-10 flex flex-wrap gap-x-10 gap-y-4">
              <Button
                variant="link"
                onClick={() =>
                  downloadIcs(
                    `${a.reference}.ics`,
                    buildIcs({
                      uid: `${a.reference}@private-office`,
                      title: a.type.title,
                      startsAt: a.starts_at,
                      endsAt: a.ends_at,
                      location: a.format === 'google_meet' ? (a.meet_url ?? 'Google Meet') : (a.city ?? undefined),
                      description: `Reference ${a.reference}`,
                    }),
                  )
                }
              >
                Add to calendar
              </Button>
              {a.can_reschedule && (
                <Button variant="link" onClick={() => setPanel(panel === 'reschedule' ? 'none' : 'reschedule')}>
                  Reschedule
                </Button>
              )}
              {a.can_cancel && (
                <Button variant="link" onClick={() => setPanel(panel === 'cancel' ? 'none' : 'cancel')}>
                  Cancel booking
                </Button>
              )}
              <ButtonLink to="/library" variant="link">
                Client library
              </ButtonLink>
            </div>
          )}

          {(active || awaitingPayment) && (a.reschedule_policy || a.cancellation_policy) && (
            <div className="mt-12 space-y-3 text-xs font-light leading-relaxed text-slate-400">
              {a.reschedule_policy && <p>{a.reschedule_policy}</p>}
              {a.cancellation_policy && <p>{a.cancellation_policy}</p>}
            </div>
          )}
        </div>

        <aside className="lg:col-span-5">
          <div className="lg:sticky lg:top-[calc(var(--header-h)+var(--ribbon-h)+3rem)]">
            {awaitingPayment && money ? (
              <>
                <PaymentPanel
                  payable={{ kind: 'appointment', reference: a.reference, token }}
                  currency={money.currency}
                  country={a.country}
                  amountLabel={formatMoney(money)}
                  holdExpiresAt={a.hold_expires_at}
                  lastAttempt={a.payment}
                  prefill={{ name: a.client_name }}
                  onVerified={(result) => {
                    setFlash(result.status === 'confirmed' ? 'Thank you — your payment has been verified and your consultation is confirmed.' : null)
                    refresh()
                  }}
                  onSlotLost={() => navigate(`/consultation?type=${encodeURIComponent(a.type.slug)}`)}
                />
                {a.can_cancel && (
                  <div className="mt-6 text-center">
                    <Button variant="link" onClick={() => setPanel('cancel')}>
                      Release this time instead
                    </Button>
                  </div>
                )}
              </>
            ) : (
              <div className="border border-[var(--line)] bg-midnight-900/50 p-6 sm:p-8">
                <p className="eyebrow">Private office</p>
                <p className="mt-5 font-light leading-relaxed text-ivory-200/80">
                  {active
                    ? 'A confirmation has been sent to your email. Should you need anything before your session, the private office is at your disposal.'
                    : 'Should you wish to arrange another time, the private office will be glad to assist.'}
                </p>
                {contact && (
                  <a href={`mailto:${contact}`} className="link-underline mt-6 inline-block text-sm text-champagne-200">
                    {contact}
                  </a>
                )}
                {!active && !awaitingPayment && (
                  <div className="mt-8">
                    <ButtonLink to="/consultation" variant="outline" size="sm">
                      Book another time
                    </ButtonLink>
                  </div>
                )}
                <ConfidentialitySeal className="mt-8" label="Confidential · Encrypted booking" />
              </div>
            )}
          </div>
        </aside>
      </div>

      {panel === 'reschedule' && <ReschedulePanel appointment={a} token={token} onDone={finish} onClose={() => setPanel('none')} />}
      {panel === 'cancel' && <CancelPanel appointment={a} token={token} onDone={finish} onClose={() => setPanel('none')} />}
    </Shell>
  )
}

function ReschedulePanel({ appointment: a, token, onDone, onClose }: { appointment: ClientAppointment; token: string; onDone: (data: ClientAppointment, message: string) => void; onClose: () => void }) {
  const [timezone, setTimezone] = useState(a.timezone)
  const [slot, setSlot] = useState<Slot | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function confirm() {
    if (!slot) return
    setBusy(true)
    setError(null)
    try {
      const data = await api.post<ClientAppointment>(`/appointments/${a.reference}/reschedule`, { starts_at: slot.starts_at }, { headers: { 'X-Access-Token': token } })
      onDone(data, 'Your consultation has been moved. An updated confirmation is on its way to your email.')
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'The booking could not be rescheduled. Please try again.')
      setBusy(false)
    }
  }

  return (
    <section aria-labelledby="reschedule-title" className="mt-20 border-t border-[var(--line)] pt-16">
      <h2 id="reschedule-title" className="text-h2">
        Choose a new time.
      </h2>
      <p className="mt-4 max-w-xl font-light text-ivory-200/75">Your current time stays reserved until you confirm a new one.</p>
      <div className="mt-12">
        <SlotPicker
          typeSlug={a.type.slug}
          maxAdvanceDays={a.type.max_advance_days}
          timezone={timezone}
          onTimezoneChange={setTimezone}
          value={slot}
          onChange={setSlot}
          auth={{ appointment: { reference: a.reference, token } }}
          excludeStartsAt={a.starts_at}
        />
      </div>
      {error && (
        <div className="mt-8">
          <FormAlert>{error}</FormAlert>
        </div>
      )}
      <div className="mt-12 flex flex-wrap items-center justify-between gap-6 border-t border-[var(--line)] pt-8">
        <Button variant="ghost" size="flush" onClick={onClose}>
          Keep current time
        </Button>
        <Button arrow disabled={!slot} loading={busy} onClick={confirm}>
          {slot ? `Move to ${formatDateTime(slot.starts_at, timezone)}` : 'Select a new time'}
        </Button>
      </div>
    </section>
  )
}

function CancelPanel({ appointment: a, token, onDone, onClose }: { appointment: ClientAppointment; token: string; onDone: (data: ClientAppointment, message: string) => void; onClose: () => void }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const awaitingPayment = a.status === 'pending_payment' || a.status === 'payment_failed'
  const paid = !awaitingPayment && a.amount.total_minor > 0

  async function confirm() {
    setBusy(true)
    setError(null)
    try {
      const data = await api.post<ClientAppointment>(`/appointments/${a.reference}/cancel`, { reason: reason.trim() || undefined }, { headers: { 'X-Access-Token': token } })
      onDone(data, paid && a.refund_eligible ? 'Your booking has been cancelled. A refund to your original payment method has been initiated.' : 'Your booking has been cancelled.')
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'The booking could not be cancelled. Please try again.')
      setBusy(false)
    }
  }

  return (
    <section aria-labelledby="cancel-title" className="mt-20 max-w-2xl border-t border-[var(--line)] pt-16">
      <h2 id="cancel-title" className="text-h2">
        Cancel this booking?
      </h2>
      <p className="mt-4 font-light text-ivory-200/80">
        {awaitingPayment
          ? 'The time held for you will be released. No payment has been taken.'
          : paid
            ? a.refund_eligible
              ? 'You are cancelling within the policy window, so a refund will be issued to your original payment method.'
              : 'Under the cancellation policy, this booking is no longer eligible for a refund.'
            : 'The time will be released.'}
      </p>
      <TextArea className="mt-10" label="Reason" rows={3} maxLength={500} currentLength={reason.length} value={reason} onChange={(e) => setReason(e.target.value)} hint="Optional — shared only with the private office." />
      {error && (
        <div className="mt-8">
          <FormAlert>{error}</FormAlert>
        </div>
      )}
      <div className="mt-10 flex flex-wrap items-center justify-between gap-6 border-t border-[var(--line)] pt-8">
        <Button variant="ghost" size="flush" onClick={onClose}>
          Keep my booking
        </Button>
        <Button variant="outline" loading={busy} onClick={confirm}>
          Confirm cancellation
        </Button>
      </div>
    </section>
  )
}

function Shell({ children }: { children: ReactNode }) {
  return (
    <section className="grain relative min-h-svh bg-midnight-950 pb-28 pt-[calc(var(--header-h)+4rem)]">
      <Seo title="Your consultation" noindex />
      <Glow className="-left-[10%] top-0 h-[60vh] w-[50vw] bg-emerald-700/15 blur-[140px]" />
      <Container className="relative">{children}</Container>
    </section>
  )
}

function Notice({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="mx-auto max-w-2xl py-20 text-center">
      <h1 className="text-h1">{title}</h1>
      <p className="mt-8 font-light leading-relaxed text-ivory-200/80">{children}</p>
      <div className="mt-12">
        <ButtonLink to="/" variant="link">
          Return home
        </ButtonLink>
      </div>
    </div>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-2 py-5 sm:grid-cols-[9rem_1fr] sm:gap-6">
      <dt className="text-[0.66rem] uppercase tracking-[0.22em] text-slate-400">{label}</dt>
      <dd className="font-light text-ivory-200/90">{children}</dd>
    </div>
  )
}
