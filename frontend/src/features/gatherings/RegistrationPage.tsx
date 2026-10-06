import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { useParams } from 'react-router'
import { FormAlert, TextArea } from '../../components/form/Fields'
import { Seo } from '../../components/seo/Seo'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { ConfidentialitySeal } from '../../components/ui/Ornaments'
import { ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { captureAccessToken } from '../../lib/accessTokens'
import { api, ApiError } from '../../lib/api'
import { formatDateTime, formatMoney, formatNoticePeriod } from '../../lib/format'
import type { Money } from '../../lib/types'
import type { PaymentAttempt } from '../../lib/payments'
import { PaymentPanel } from '../payments/PaymentPanel'
import { useCheckoutReturn } from '../payments/useCheckoutReturn'
import { REGISTRATION_STATUS } from './EventPage'

interface ClientRegistration {
  reference: string
  status: string
  seats: number
  country: string | null
  name: string | null
  event: { title: string; slug: string; starts_at: string; ends_at: string; timezone: string; venue: string | null; status: string }
  amount: { currency: string | null; subtotal_minor: number; tax_minor: number; total_minor: number; tax_label: string | null }
  payment: PaymentAttempt | null
  hold_expires_at: string | null
  waitlist_position: number | null
  can_cancel: boolean
  refund_eligible: boolean
  refund_on_cancel_percent: number
  cancellation_window_hours: number
  cancellation_policy: string | null
}

const PAID = ['captured', 'partially_refunded']

export default function RegistrationPage() {
  const reference = (useParams().reference ?? '').toUpperCase()
  const [token] = useState(() => captureAccessToken('registration', reference))
  const [flash, setFlash] = useState<string | null>(null)
  const [cancelling, setCancelling] = useState(false)

  const registration = useQuery({
    queryKey: ['registration', reference],
    queryFn: () => api.get<ClientRegistration>(`/event-registrations/${encodeURIComponent(reference)}`, { headers: { 'X-Access-Token': token ?? '' } }),
    enabled: Boolean(token),
    staleTime: 0,
  })

  const checkout = useCheckoutReturn((result) => {
    if (result.status === 'confirmed') setFlash('Thank you — your payment has been verified and your place is confirmed.')
    registration.refetch()
  })

  const shell = (content: ReactNode) => (
    <section className="grain relative min-h-screen bg-midnight-950 pb-28 pt-[calc(var(--header-h)+4rem)]">
      <Seo title="Your registration" noindex />
      <Container className="relative">{content}</Container>
    </section>
  )

  if (!token) {
    return shell(
      <div className="mx-auto max-w-2xl py-20 text-center">
        <h1 className="text-h1">Please use your private link.</h1>
        <p className="mt-8 font-light text-ivory-200/80">Registrations can only be viewed from the personal link in your email.</p>
      </div>,
    )
  }
  if (registration.isLoading || checkout.verifying) return <LoadingBlock className="min-h-[90vh]" label={checkout.verifying ? 'Confirming your payment' : 'Retrieving your registration'} />
  if (registration.isError) {
    if (registration.error instanceof ApiError && registration.error.status === 404) {
      return shell(
        <div className="mx-auto max-w-2xl py-20 text-center">
          <h1 className="text-h1">We could not find this registration.</h1>
          <p className="mt-8 font-light text-ivory-200/80">The link may be incomplete. Please open it again from your email.</p>
        </div>,
      )
    }
    return <ErrorBlock className="min-h-[90vh]" error={registration.error} onRetry={() => registration.refetch()} />
  }

  const r = registration.data!
  const copy = REGISTRATION_STATUS[r.status] ?? { title: 'Your registration', body: '' }
  const money = r.amount.currency && r.amount.total_minor > 0 ? { currency: r.amount.currency, amount_minor: r.amount.total_minor } : null
  const eventOpen = r.event.status !== 'cancelled' && new Date(r.event.starts_at).getTime() > Date.now()
  const canPay = money && eventOpen && (r.status === 'pending_payment' || r.status === 'expired')

  return shell(
    <div className="grid gap-16 lg:grid-cols-12 lg:gap-10">
      <div className="lg:col-span-7">
        <p className="eyebrow flex items-center gap-4">
          <span aria-hidden="true" className="h-px w-10 bg-champagne-400/70" />
          Registration · {r.reference}
        </p>
        <h1 className="mt-8 text-h1">{copy.title}</h1>
        <p className="mt-6 max-w-xl font-light text-ivory-200/80">{copy.body}</p>
        <div className="mt-8 space-y-4" aria-live="polite">
          {flash && <FormAlert tone="success">{flash}</FormAlert>}
          {checkout.error && <FormAlert>{checkout.error}</FormAlert>}
          {checkout.cancelled && r.status === 'pending_payment' && <FormAlert tone="info">Payment was not completed. You can try again below.</FormAlert>}
          {r.event.status === 'cancelled' && r.status !== 'cancelled' && r.status !== 'refunded' && (
            <FormAlert tone="info">This gathering has been cancelled. Any contribution you made is refunded in full to your original payment method.</FormAlert>
          )}
        </div>
        <dl className="mt-12 divide-y divide-[var(--line)] border-y border-[var(--line)]">
          <Row label="Gathering">
            <ButtonLink to={`/gatherings/${r.event.slug}`} variant="link" arrow={false}>
              {r.event.title}
            </ButtonLink>
          </Row>
          <Row label="When">{formatDateTime(r.event.starts_at, r.event.timezone)}</Row>
          {r.event.venue && <Row label="Where">{r.event.venue}</Row>}
          <Row label="Places">{r.seats}</Row>
          {r.waitlist_position !== null && <Row label="Waitlist">Position {r.waitlist_position}</Row>}
          {money && (
            <Row label="Total">
              {formatMoney(money)}
              {r.amount.tax_minor > 0 && (
                <span className="mt-1 block text-xs text-slate-400">
                  incl. {formatMoney({ currency: money.currency, amount_minor: r.amount.tax_minor })} {r.amount.tax_label ?? 'tax'}
                </span>
              )}
            </Row>
          )}
          {r.payment && PAID.includes(r.payment.status) && <Row label="Payment">Received · {r.payment.reference}</Row>}
        </dl>

        {r.can_cancel && (r.cancellation_policy || money) && (
          <p className="mt-10 max-w-xl text-xs font-light leading-relaxed text-slate-400">{r.cancellation_policy ?? refundSummary(r)}</p>
        )}
        {r.can_cancel && !cancelling && (
          <div className="mt-8">
            <Button variant="link" onClick={() => setCancelling(true)}>
              {r.status === 'waitlisted' ? 'Leave the waitlist' : 'Cancel my registration'}
            </Button>
          </div>
        )}
        {cancelling && (
          <CancelPanel
            registration={r}
            token={token}
            onClose={() => setCancelling(false)}
            onDone={(refund) => {
              setCancelling(false)
              setFlash(refund ? `Your registration has been cancelled. A refund of ${formatMoney(refund)} has been requested and will reach your original payment method.` : 'Your registration has been cancelled.')
              window.scrollTo({ top: 0, behavior: 'smooth' })
            }}
          />
        )}
      </div>
      <aside className="lg:col-span-5">
        {canPay ? (
          <>
            {r.status === 'expired' && (
              <div className="mb-6">
                <FormAlert tone="info">Your held place lapsed before payment was completed. If a place is still available, it is held again for you as soon as you start payment.</FormAlert>
              </div>
            )}
            <PaymentPanel
              payable={{ kind: 'registration', reference: r.reference, token }}
              currency={money.currency}
              country={r.country}
              amountLabel={formatMoney(money)}
              holdExpiresAt={r.hold_expires_at}
              lastAttempt={r.payment}
              prefill={{ name: r.name }}
              onVerified={(result) => {
                if (result.status === 'confirmed') setFlash('Thank you — your payment has been verified and your place is confirmed.')
                registration.refetch()
              }}
            />
          </>
        ) : (
          <div className="border border-[var(--line)] bg-midnight-900/50 p-8">
            <ConfidentialitySeal label="Confidential · Encrypted registration" />
            <div className="mt-8">
              <ButtonLink to="/gatherings" variant="link">
                Other gatherings
              </ButtonLink>
            </div>
          </div>
        )}
      </aside>
    </div>,
  )
}

function refundSummary(r: ClientRegistration): string {
  if (r.refund_on_cancel_percent <= 0) return 'Contributions are not refundable on cancellation.'
  const share = r.refund_on_cancel_percent >= 100 ? 'a full refund' : `a ${r.refund_on_cancel_percent}% refund`
  return r.cancellation_window_hours > 0 ? `Cancel at least ${formatNoticePeriod(r.cancellation_window_hours)} before the gathering for ${share}.` : `Cancel before the gathering begins for ${share}.`
}

function CancelPanel({ registration: r, token, onClose, onDone }: { registration: ClientRegistration; token: string; onClose: () => void; onDone: (refund: Money | null) => void }) {
  const client = useQueryClient()
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const paid = r.payment !== null && PAID.includes(r.payment.status)
  const refundMinor = r.refund_eligible && r.amount.currency ? Math.floor((r.amount.total_minor * r.refund_on_cancel_percent) / 100) : 0

  async function submit() {
    setBusy(true)
    setError(null)
    try {
      const result = await api.post<ClientRegistration & { refund: Money | null }>(
        `/event-registrations/${encodeURIComponent(r.reference)}/cancel`,
        { reason: reason.trim() || undefined },
        { headers: { 'X-Access-Token': token } },
      )
      client.setQueryData(['registration', r.reference], result)
      onDone(result.refund)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The registration could not be cancelled. Please try again.')
      setBusy(false)
    }
  }

  return (
    <div className="mt-10 border border-[var(--line-strong)] p-8" role="region" aria-labelledby="cancel-title">
      <h2 id="cancel-title" className="font-display text-2xl text-ivory-50">
        {r.status === 'waitlisted' ? 'Leave the waitlist?' : 'Cancel this registration?'}
      </h2>
      <p className="mt-4 text-sm font-light leading-relaxed text-ivory-200/80">
        {!paid
          ? 'Your place is released for another guest. Nothing has been charged.'
          : refundMinor > 0 && r.amount.currency
            ? `Your place is released and ${formatMoney({ currency: r.amount.currency, amount_minor: refundMinor })} is refunded to your original payment method. Depending on your bank, a refund can take several working days to appear.`
            : 'Your place is released. Under the cancellation policy for this gathering, no refund is due at this point.'}
      </p>
      <div className="mt-6">
        <TextArea label="Reason (optional)" rows={3} maxLength={500} currentLength={reason.length} value={reason} onChange={(e) => setReason(e.target.value)} />
      </div>
      {error && (
        <div className="mt-4">
          <FormAlert>{error}</FormAlert>
        </div>
      )}
      <div className="mt-6 flex flex-wrap gap-4">
        <Button onClick={submit} loading={busy}>
          {r.status === 'waitlisted' ? 'Leave the waitlist' : 'Cancel registration'}
        </Button>
        <Button variant="outline" onClick={onClose} disabled={busy}>
          Keep my place
        </Button>
      </div>
    </div>
  )
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-2 py-5 sm:grid-cols-[9rem_1fr] sm:gap-6">
      <dt className="text-[0.66rem] uppercase tracking-[0.22em] text-slate-400">{label}</dt>
      <dd className="font-light text-ivory-200/90">{children}</dd>
    </div>
  )
}
