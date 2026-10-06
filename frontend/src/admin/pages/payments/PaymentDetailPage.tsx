import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { idempotencyKey } from '../../../lib/api'
import { formatMoney } from '../../../lib/format'
import { adminApi, errorMessage } from '../../lib/adminApi'
import { formatAdminDateTime } from '../../lib/datetime'
import { labelOf, PAYMENT_STATUS } from '../../lib/labels'
import { useSession } from '../../lib/session'
import { Dialog, useConfirm } from '../../ui/Dialog'
import { Input } from '../../ui/Fields'
import { AButton, Badge, DataTable, ErrorNote, KeyValues, Notice, PageHeader, Panel, Spinner, TableMessage, Td, Th } from '../../ui/Primitives'
import { StatusHistory } from '../../ui/StatusHistory'
import type { HistoryEntry } from '../../ui/StatusHistory'
import { useToast } from '../../ui/Toast'
import { PAYABLE_LABEL } from './PaymentsPage'

interface Refund {
  id: number
  gateway_refund_id: string | null
  amount_minor: number
  currency: string
  status: 'pending' | 'processed' | 'failed'
  reason: string | null
  created_at: string
}

interface PaymentDetail {
  id: number
  reference: string
  payable_type: 'appointment' | 'event_registration'
  payable_id: number
  payable_reference: string | null
  reconciliation_note: string | null
  history: HistoryEntry[]
  gateway: string
  environment: string
  gateway_order_id: string
  gateway_payment_id: string | null
  currency: string
  amount_minor: number
  refunded_minor: number
  status: string
  receipt_number: string | null
  failure_reason: string | null
  metadata: Record<string, unknown> | null
  verified_at: string | null
  captured_at: string | null
  created_at: string
  refunds: Refund[]
}

const REFUND_TONE = { pending: 'gold', processed: 'green', failed: 'red' } as const

/** Parses a major-unit amount ("1250.50") into minor units without floating-point drift. */
function toMinor(value: string): number | null {
  const m = value.trim().replace(/,/g, '').match(/^(\d+)(?:\.(\d{1,2}))?$/)
  if (!m) return null
  return Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0'))
}

export default function PaymentDetailPage() {
  const { id } = useParams()
  const { can } = useSession()
  const notify = useToast()
  const confirm = useConfirm()
  const client = useQueryClient()
  const payment = useQuery({ queryKey: ['admin', 'payments', 'item', id], queryFn: () => adminApi.get<PaymentDetail>(`/admin/payments/${id}`) })
  const accept = useMutation({
    mutationFn: () => adminApi.post<{ status: string }>(`/admin/payments/${id}/accept-reconciliation`, { confirm: true }),
    onSuccess: (r) => {
      client.invalidateQueries({ queryKey: ['admin', 'payments'] })
      client.invalidateQueries({ queryKey: ['admin', 'appointments'] })
      client.invalidateQueries({ queryKey: ['admin', 'dashboard'] })
      notify(r.status === 'confirmed' ? 'Payment accepted and the booking confirmed.' : 'Payment accepted, but the booking could no longer be confirmed. Refund it from this page.', r.status === 'confirmed' ? 'success' : 'error')
    },
    onError: (e) => notify(errorMessage(e), 'error'),
  })
  const [open, setOpen] = useState(false)
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [key, setKey] = useState('')
  const [error, setError] = useState<string | null>(null)

  const refund = useMutation({
    mutationFn: (body: { amount_minor: number; reason: string }) => adminApi.post<Refund>(`/admin/payments/${id}/refund`, body, { headers: { 'Idempotency-Key': key } }),
    onSuccess: (r) => {
      setOpen(false)
      client.invalidateQueries({ queryKey: ['admin', 'payments'] })
      client.invalidateQueries({ queryKey: ['admin', 'appointments'] })
      notify(r.status === 'processed' ? 'Refund processed.' : 'Refund submitted. It completes when the gateway confirms it.')
    },
    onError: (e) => setError(errorMessage(e)),
  })

  if (payment.isLoading) return <Spinner />
  if (payment.isError) return <ErrorNote error={payment.error} onRetry={() => payment.refetch()} />
  const p = payment.data!
  const s = labelOf(PAYMENT_STATUS, p.status)
  const pending = p.refunds.filter((r) => r.status === 'pending').reduce((sum, r) => sum + r.amount_minor, 0)
  const remaining = p.amount_minor - p.refunded_minor - pending
  const refundable = can('payments.refund') && ['captured', 'partially_refunded', 'reconciliation_required'].includes(p.status) && Boolean(p.gateway_payment_id) && remaining > 0
  const reconcilable = can('payments.refund') && p.status === 'reconciliation_required'
  const money = (minor: number) => formatMoney({ currency: p.currency, amount_minor: minor })

  const acceptReconciliation = async () => {
    const ok = await confirm({
      title: 'Accept this payment?',
      body: `Only accept after checking the transaction in the ${p.gateway === 'stripe' ? 'Stripe' : 'Razorpay'} dashboard. The payment is marked captured and the booking is confirmed if its time or seat is still available.`,
      confirmLabel: 'Accept payment',
    })
    if (ok) accept.mutate()
  }

  const openRefund = () => {
    setAmount((remaining / 100).toFixed(2))
    setReason('')
    setError(null)
    setKey(idempotencyKey())
    setOpen(true)
  }

  const submit = () => {
    setError(null)
    const minor = toMinor(amount)
    if (minor === null || minor < 1) return setError('Enter a valid amount, e.g. 1250.00')
    if (minor > remaining) return setError(`The most that can be refunded is ${money(remaining)}.`)
    if (reason.trim().length < 3) return setError('Give a short reason for the refund.')
    refund.mutate({ amount_minor: minor, reason: reason.trim() })
  }

  return (
    <>
      <PageHeader
        back={{ to: '/admin/payments', label: 'Payments' }}
        title={money(p.amount_minor)}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <Badge tone={s.tone}>{s.label}</Badge>
            <span className="font-mono text-xs">{p.reference}</span>
            <span className="capitalize">{p.gateway}</span>
            {p.environment !== 'production' && <Badge tone="muted">{p.environment}</Badge>}
          </span>
        }
        actions={
          <>
            {reconcilable && (
              <AButton variant="primary" loading={accept.isPending} onClick={acceptReconciliation}>
                Accept payment
              </AButton>
            )}
            {refundable && (
              <AButton variant="danger" onClick={openRefund}>
                Issue refund
              </AButton>
            )}
          </>
        }
      />
      {p.status === 'reconciliation_required' && (
        <div className="mb-6">
          <Notice tone="warn">
            The client was charged, but this payment did not match its order{p.reconciliation_note ? `: ${p.reconciliation_note}` : '.'} The booking has not been confirmed. Check the transaction in the gateway dashboard, then accept it or refund it.
          </Notice>
        </div>
      )}
      {pending > 0 && (
        <div className="mb-6">
          <Notice tone="warn">{money(pending)} is being refunded and awaiting confirmation from {p.gateway}.</Notice>
        </div>
      )}
      <div className="grid gap-6 xl:grid-cols-2">
        <Panel title="Payment">
          <KeyValues
            items={[
              [
                'For',
                p.payable_type === 'appointment' ? (
                  <Link to={`/admin/appointments/${p.payable_id}`} className="text-champagne-200 hover:underline">
                    {PAYABLE_LABEL[p.payable_type]} booking {p.payable_reference}
                  </Link>
                ) : (
                  `${PAYABLE_LABEL[p.payable_type]} ${p.payable_reference ?? ''}`
                ),
              ],
              ['Receipt', p.receipt_number ? <span className="font-mono text-xs">{p.receipt_number}</span> : '—'],
              ['Amount', money(p.amount_minor)],
              ['Refunded', p.refunded_minor ? money(p.refunded_minor) : '—'],
              ['Created', formatAdminDateTime(p.created_at)],
              ['Verified', formatAdminDateTime(p.verified_at)],
              ['Captured', formatAdminDateTime(p.captured_at)],
              ...(p.failure_reason ? ([['Failure', <span key="f" className="text-danger-300">{p.failure_reason}</span>]] as [string, ReactNode][]) : []),
            ]}
          />
        </Panel>
        <Panel title="Gateway reference">
          <KeyValues
            items={[
              ['Environment', p.environment],
              ['Order ID', <span key="o" className="break-all font-mono text-xs">{p.gateway_order_id}</span>],
              ['Payment ID', p.gateway_payment_id ? <span className="break-all font-mono text-xs">{p.gateway_payment_id}</span> : '—'],
            ]}
          />
          <p className="mt-4 text-xs text-slate-400">Use these identifiers to find the transaction in the {p.gateway === 'stripe' ? 'Stripe' : 'Razorpay'} dashboard.</p>
        </Panel>
        <Panel title="Status history" className="xl:col-span-2">
          <StatusHistory entries={p.history} labels={PAYMENT_STATUS} />
        </Panel>
      </div>

      <h2 className="mb-3 mt-8 text-[0.68rem] font-semibold uppercase tracking-[0.2em] text-ivory-200">Refunds</h2>
      <DataTable caption="Refunds">
        <thead>
          <tr>
            <Th>Requested</Th>
            <Th className="text-right">Amount</Th>
            <Th>Reason</Th>
            <Th>Status</Th>
          </tr>
        </thead>
        <tbody>
          {p.refunds.length === 0 ? (
            <TableMessage colSpan={4}>No refunds.</TableMessage>
          ) : (
            p.refunds.map((r) => (
              <tr key={r.id}>
                <Td className="tabular-nums">{formatAdminDateTime(r.created_at)}</Td>
                <Td className="text-right tabular-nums">{formatMoney({ currency: r.currency, amount_minor: r.amount_minor })}</Td>
                <Td>{r.reason ?? '—'}</Td>
                <Td>
                  <Badge tone={REFUND_TONE[r.status] ?? 'neutral'}>{r.status}</Badge>
                </Td>
              </tr>
            ))
          )}
        </tbody>
      </DataTable>

      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        title="Issue refund"
        footer={
          <>
            <AButton variant="ghost" onClick={() => setOpen(false)}>
              Close
            </AButton>
            <AButton variant="danger" loading={refund.isPending} onClick={submit}>
              Refund
            </AButton>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-sm font-light text-ivory-200">
            The refund is sent to {p.gateway === 'stripe' ? 'Stripe' : 'Razorpay'} immediately and returned to the client’s original payment method. Up to {money(remaining)} can be refunded.
          </p>
          <Input label={`Amount (${p.currency})`} required inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
          <Input label="Reason" required maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} hint="Stored with the refund record." />
          {error && <Notice tone="error">{error}</Notice>}
        </div>
      </Dialog>
    </>
  )
}
