import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router'
import { ApiError } from '../../lib/api'
import { verifyPayment, type VerifyResult } from '../../lib/payments'

/**
 * Handles the return from Stripe Checkout (`?checkout=success&session_id=…` or `?checkout=cancelled`).
 * The session is verified server-side; the query string is then removed so a reload never re-verifies.
 */
export function useCheckoutReturn(onVerified: (result: VerifyResult) => void) {
  const [params, setParams] = useSearchParams()
  const checkout = params.get('checkout')
  const sessionId = params.get('session_id')
  const [state, setState] = useState<{ verifying: boolean; error: string | null; cancelled: boolean }>({
    verifying: checkout === 'success' && Boolean(sessionId),
    error: null,
    cancelled: checkout === 'cancelled',
  })
  const handled = useRef(false)
  const callback = useRef(onVerified)
  callback.current = onVerified

  useEffect(() => {
    if (handled.current || !checkout) return
    handled.current = true
    const clear = () => setParams((p) => {
      const next = new URLSearchParams(p)
      next.delete('checkout')
      next.delete('session_id')
      return next
    }, { replace: true })

    if (checkout === 'success' && sessionId && /^cs_[A-Za-z0-9_]+$/.test(sessionId)) {
      verifyPayment({ gateway: 'stripe', session_id: sessionId })
        .then((result) => {
          setState({ verifying: false, error: null, cancelled: false })
          callback.current(result)
        })
        .catch((e: unknown) => {
          setState({
            verifying: false,
            cancelled: false,
            error: e instanceof ApiError ? e.message : 'We could not confirm the payment yet. If you were charged, your booking will be confirmed automatically.',
          })
        })
        .finally(clear)
    } else {
      setState((s) => ({ ...s, verifying: false }))
      clear()
    }
  }, [checkout, sessionId, setParams])

  return state
}
