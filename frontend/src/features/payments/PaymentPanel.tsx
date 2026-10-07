import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { FormAlert } from '../../components/form/Fields'
import { Button } from '../../components/ui/Button'
import { ApiError } from '../../lib/api'
import { fetchGateways, GATEWAY_LABEL, RETRYABLE, startPayment, type GatewayName, type Payable, type PaymentAttempt, type VerifyResult } from '../../lib/payments'
import { useSettings } from '../../lib/queries'

export function useCountdown(iso: string | null | undefined): number | null {
  const target = iso ? new Date(iso).getTime() : null
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (target === null) return
    const id = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(id)
  }, [target])
  return target === null ? null : Math.max(0, Math.floor((target - now) / 1000))
}

const mmss = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

const ATTEMPT_OUTCOME: Record<string, string> = {
  created: 'was started but not completed',
  pending: 'is still being processed by the provider',
  failed: 'did not go through',
  cancelled: 'was cancelled',
}

export function PaymentPanel({
  payable,
  currency,
  country,
  amountLabel,
  holdExpiresAt,
  lastAttempt,
  prefill,
  onVerified,
  onSlotLost,
}: {
  payable: Payable
  currency: string
  country?: string | null
  amountLabel: string
  holdExpiresAt: string | null
  lastAttempt?: PaymentAttempt | null
  prefill?: { name?: string | null; email?: string | null }
  onVerified: (result: VerifyResult) => void
  onSlotLost?: () => void
}) {
  const settings = useSettings()
  const gateways = useQuery({ queryKey: ['gateways', currency, country ?? ''], queryFn: () => fetchGateways(currency, country), staleTime: 5 * 60_000 })
  const [busy, setBusy] = useState<GatewayName | null>(null)
  const [switchTo, setSwitchTo] = useState<GatewayName | null>(null)
  const [attempt, setAttempt] = useState<PaymentAttempt | null>(lastAttempt && RETRYABLE.includes(lastAttempt.status) ? lastAttempt : null)
  const [error, setError] = useState<{ message: string; slotLost?: boolean } | null>(null)
  const remaining = useCountdown(holdExpiresAt)
  const contact = settings.data?.['contact.email']
  const testMode = gateways.data?.some((g) => g.mode === 'test')

  function choose(gateway: GatewayName) {
    if (attempt && attempt.gateway !== gateway) return setSwitchTo(gateway)
    void pay(gateway)
  }

  async function pay(gateway: GatewayName) {
    setSwitchTo(null)
    setBusy(gateway)
    setError(null)
    try {
      const outcome = await startPayment(payable, gateway, prefill, attempt)
      if (outcome.kind === 'verified') onVerified(outcome.result)
      if (outcome.kind === 'redirecting') return // keep the button busy while the browser leaves
      if (outcome.kind === 'dismissed') setAttempt(outcome.attempt)
    } catch (e) {
      if (e instanceof ApiError && e.code === 'slot_unavailable') {
        setError({ message: e.message, slotLost: true })
      } else {
        setError({ message: e instanceof Error ? e.message : 'The payment could not be started. Please try again.' })
      }
    }
    setBusy(null)
  }

  return (
    <div className="border border-[var(--line-strong)] bg-midnight-900/60 p-6 sm:p-8 md:p-10">
      <div className="flex flex-wrap items-baseline justify-between gap-4">
        <p className="eyebrow">Secure payment</p>
        {remaining !== null && (
          <p className="text-xs font-light text-slate-400" aria-live="off">
            {remaining > 0 ? (
              <>
                Time held for you · <span className="tabular-nums text-ivory-200">{mmss(remaining)}</span>
              </>
            ) : (
              'Your hold has lapsed — we will try to reserve the time again when you pay.'
            )}
          </p>
        )}
      </div>
      <p className="mt-6 font-display text-4xl text-ivory-50">{amountLabel}</p>
      {testMode && <p className="mt-2 text-xs font-medium uppercase tracking-[0.16em] text-champagne-300">Test mode · no real payment is taken</p>}

      {attempt && !switchTo && (
        <div className="mt-6">
          <FormAlert tone="info">
            Your earlier payment with {GATEWAY_LABEL[attempt.gateway].title} {ATTEMPT_OUTCOME[attempt.status] ?? 'was not completed'}.
            {attempt.status === 'pending'
              ? ' If it completes, your booking is confirmed automatically — paying again could charge you twice, although any duplicate charge is refunded.'
              : ' You can try again below; a new checkout is created each time.'}
          </FormAlert>
        </div>
      )}

      {switchTo && attempt && (
        <div className="mt-8 border border-[var(--line-strong)] p-6" role="alertdialog" aria-labelledby="switch-title" aria-describedby="switch-body">
          <p id="switch-title" className="font-display text-xl text-ivory-50">
            Pay with {GATEWAY_LABEL[switchTo].title} instead?
          </p>
          <p id="switch-body" className="mt-3 text-sm font-light leading-relaxed text-ivory-200">
            This starts a new, separate checkout with {GATEWAY_LABEL[switchTo].title} for {amountLabel}. Your earlier {GATEWAY_LABEL[attempt.gateway].title} checkout is not reused. If you were in fact charged by
            both providers, the duplicate payment is refunded to its original method.
          </p>
          <div className="mt-6 flex flex-wrap gap-3">
            <Button onClick={() => pay(switchTo)} arrow>
              Continue with {GATEWAY_LABEL[switchTo].title}
            </Button>
            <Button variant="outline" onClick={() => setSwitchTo(null)}>
              Go back
            </Button>
          </div>
        </div>
      )}

      <div className={`mt-8 space-y-3 ${switchTo ? 'hidden' : ''}`}>
        {gateways.isLoading && <p className="text-sm font-light text-slate-400">Preparing payment options…</p>}
        {gateways.isSuccess && gateways.data.length === 0 && (
          <FormAlert tone="info">
            Online payment is not available in {currency} at present.{' '}
            {contact ? (
              <>
                Please contact the private office at{' '}
                <a className="link-underline text-ivory-50" href={`mailto:${contact}`}>
                  {contact}
                </a>{' '}
                to complete your booking.
              </>
            ) : (
              'Please contact the private office to complete your booking.'
            )}
          </FormAlert>
        )}
        {gateways.data?.map((g) => (
          <Button
            key={g.name}
            variant={g === gateways.data[0] ? 'solid' : 'outline'}
            className="w-full justify-between"
            loading={busy === g.name}
            disabled={busy !== null}
            onClick={() => choose(g.name)}
            arrow
          >
            <span className="flex flex-col items-start gap-1 text-left">
              <span>Pay with {GATEWAY_LABEL[g.name].title}</span>
              <span className="text-[0.62rem] normal-case tracking-normal opacity-70">{GATEWAY_LABEL[g.name].detail}</span>
            </span>
          </Button>
        ))}
      </div>

      {error && (
        <div className="mt-6">
          <FormAlert>
            {error.message}
            {error.slotLost && onSlotLost && (
              <>
                {' '}
                <button type="button" onClick={onSlotLost} className="link-underline text-ivory-50">
                  Choose another time
                </button>
              </>
            )}
          </FormAlert>
        </div>
      )}

      <p className="mt-8 text-xs font-light leading-relaxed text-slate-400">
        Payment is processed by the provider over an encrypted connection. Card details are never seen or stored by this site. Your booking is confirmed only once the payment has been verified.
      </p>
    </div>
  )
}
