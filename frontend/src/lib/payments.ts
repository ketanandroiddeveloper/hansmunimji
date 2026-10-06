import { api, idempotencyKey } from './api'

/**
 * Client side of the payment flow. The browser only starts checkout; a booking is confirmed
 * exclusively by the server after it verifies the signature and re-reads the transaction from
 * the gateway (and independently via webhooks).
 */

export type GatewayName = 'razorpay' | 'stripe'

export interface GatewayOption {
  name: GatewayName
  public_key: string | null
  mode: 'live' | 'test'
}

export type Payable = { kind: 'appointment' | 'registration'; reference: string; token: string }

/** The most recent payment attempt for a booking, as returned with the booking itself. */
export interface PaymentAttempt {
  reference: string
  gateway: GatewayName
  status: string
}

/** Attempt statuses after which a fresh checkout may be started. */
export const RETRYABLE = ['created', 'pending', 'failed', 'cancelled']

type OrderPayload = { payment_reference: string } & (
  | { gateway: 'razorpay'; key_id: string; order_id: string; amount: number; currency: string; description: string }
  | { gateway: 'stripe'; checkout_url: string; session_id: string }
)

export interface VerifyResult {
  status: string
  payable_type: 'appointment' | 'event_registration' | string
  reference: string
}

export type PaymentOutcome = { kind: 'verified'; result: VerifyResult } | { kind: 'redirecting' } | { kind: 'dismissed'; attempt: PaymentAttempt }

export const GATEWAY_LABEL: Record<GatewayName, { title: string; detail: string }> = {
  razorpay: { title: 'Razorpay', detail: 'Cards, UPI, net banking and wallets' },
  stripe: { title: 'Stripe', detail: 'International cards, Apple Pay and Google Pay' },
}

export const fetchGateways = (currency: string, country?: string | null) =>
  api.get<GatewayOption[]>('/payments/gateways', { query: country ? { currency, country } : { currency } })

interface RazorpayResponse {
  razorpay_order_id: string
  razorpay_payment_id: string
  razorpay_signature: string
}

interface RazorpayInstance {
  open(): void
  on(event: 'payment.failed', handler: () => void): void
}

type RazorpayConstructor = new (options: Record<string, unknown>) => RazorpayInstance

declare global {
  interface Window {
    Razorpay?: RazorpayConstructor
  }
}

const RAZORPAY_SRC = 'https://checkout.razorpay.com/v1/checkout.js'
let razorpayLoader: Promise<RazorpayConstructor> | null = null

function loadRazorpay(): Promise<RazorpayConstructor> {
  if (window.Razorpay) return Promise.resolve(window.Razorpay)
  razorpayLoader ??= new Promise<RazorpayConstructor>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = RAZORPAY_SRC
    script.async = true
    script.onload = () => (window.Razorpay ? resolve(window.Razorpay) : reject(new Error('Razorpay unavailable')))
    script.onerror = () => {
      razorpayLoader = null
      script.remove()
      reject(new Error('The payment window could not be loaded. Please check your connection or disable content blockers for this page.'))
    }
    document.head.appendChild(script)
  })
  return razorpayLoader
}

export const verifyPayment = (payload: Record<string, string>) => api.post<VerifyResult>('/payments/verify', payload)

/**
 * Starts checkout. Razorpay resolves after server-side verification; Stripe navigates away to
 * its hosted page (the access token must already be in sessionStorage to survive the redirect).
 * After an unsuccessful attempt the server is asked to retry it, which always creates a new
 * order — with the same or a different gateway — and links it to the earlier attempt.
 */
export async function startPayment(
  payable: Payable,
  gateway: GatewayName,
  prefill: { name?: string | null; email?: string | null } = {},
  previous?: PaymentAttempt | null,
): Promise<PaymentOutcome> {
  const headers = { 'Idempotency-Key': idempotencyKey(), 'X-Access-Token': payable.token }
  const order =
    previous && RETRYABLE.includes(previous.status)
      ? await api.post<OrderPayload>(`/payments/${encodeURIComponent(previous.reference)}/retry`, { gateway }, { headers })
      : await api.post<OrderPayload>(
          `/${payable.kind === 'appointment' ? 'appointments' : 'event-registrations'}/${encodeURIComponent(payable.reference)}/payment`,
          { gateway },
          { headers },
        )

  if (order.gateway === 'stripe') {
    window.location.assign(order.checkout_url)
    return { kind: 'redirecting' }
  }

  const Razorpay = await loadRazorpay()
  return new Promise<PaymentOutcome>((resolve, reject) => {
    const checkout = new Razorpay({
      key: order.key_id,
      order_id: order.order_id,
      amount: order.amount,
      currency: order.currency,
      name: document.title.split('—').pop()?.trim() || 'Private Office',
      description: order.description,
      prefill: { name: prefill.name ?? undefined, email: prefill.email ?? undefined },
      theme: { color: '#0b1220' },
      modal: { ondismiss: () => resolve({ kind: 'dismissed', attempt: { reference: order.payment_reference, gateway: 'razorpay', status: 'created' } }), escape: true, confirm_close: true },
      handler: (response: RazorpayResponse) => {
        verifyPayment({ gateway: 'razorpay', ...response })
          .then((result) => resolve({ kind: 'verified', result }))
          .catch(reject)
      },
    })
    checkout.on('payment.failed', () => {
      /* Razorpay shows the failure and lets the client retry inside the same window. */
    })
    checkout.open()
  })
}
