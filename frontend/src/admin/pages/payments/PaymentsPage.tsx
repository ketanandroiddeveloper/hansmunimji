import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { API_BASE } from '../../../lib/api'
import { browserTimeZone, formatMoney } from '../../../lib/format'
import { adminApi } from '../../lib/adminApi'
import { formatAdminDateTime, zonedInputToIso } from '../../lib/datetime'
import { labelOf, PAYMENT_STATUS, toOptions } from '../../lib/labels'
import { useDebounced } from '../../resources/ResourceListPage'
import { CURRENCIES } from '../../resources/FormField'
import { Dialog } from '../../ui/Dialog'
import { FilterSelect, Input, SearchInput } from '../../ui/Fields'
import { AButton, Badge, DataTable, ErrorNote, Notice, PageHeader, Pagination, Spinner, TableMessage, Td, Th } from '../../ui/Primitives'

export interface PaymentRow {
  id: number
  reference: string
  reconciliation_note: string | null
  payable_type: 'appointment' | 'event_registration'
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
  captured_at: string | null
  created_at: string
  payable_reference: string | null
}

export const PAYABLE_LABEL: Record<string, string> = { appointment: 'Consultation', event_registration: 'Gathering' }
const GATEWAYS = { razorpay: 'Razorpay', stripe: 'Stripe' }

function ExportDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const today = new Date().toISOString().slice(0, 10)
  const [from, setFrom] = useState(`${today.slice(0, 8)}01`)
  const [to, setTo] = useState(today)
  const tz = browserTimeZone()
  const fromIso = zonedInputToIso(`${from}T00:00`, tz)
  const toIso = to ? new Date(new Date(zonedInputToIso(`${to}T00:00`, tz) ?? '').getTime() + 86_400_000).toISOString() : null
  const valid = Boolean(fromIso && toIso && from <= to)
  const href = valid ? `${API_BASE}/admin/payments/export?${new URLSearchParams({ from: fromIso!, to: toIso! })}` : undefined

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Export payments"
      footer={
        <>
          <AButton variant="ghost" onClick={onClose}>
            Close
          </AButton>
          <a
            href={href}
            aria-disabled={!valid}
            onClick={(e) => (valid ? onClose() : e.preventDefault())}
            className={`inline-flex items-center justify-center border border-champagne-400 bg-champagne-400 px-3.5 py-2 text-[0.7rem] font-semibold uppercase tracking-[0.16em] text-midnight-950 hover:bg-champagne-200 ${valid ? '' : 'pointer-events-none opacity-50'}`}
          >
            Download CSV
          </a>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-sm font-light text-ivory-200">Payments created between these dates (inclusive, in {tz}). Amounts are in major units; timestamps in UTC. The file contains no client names or contact details.</p>
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label="From" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
          <Input label="To" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
        </div>
        {!valid && <Notice tone="error">Choose a start date on or before the end date.</Notice>}
      </div>
    </Dialog>
  )
}

export default function PaymentsPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page') ?? 1))
  const filters = { status: params.get('status') ?? '', gateway: params.get('gateway') ?? '', currency: params.get('currency') ?? '', payable_type: params.get('payable_type') ?? '' }
  const [q, setQ] = useState(params.get('q') ?? '')
  const debounced = useDebounced(q, 400)
  const [exporting, setExporting] = useState(false)

  const set = (changes: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(changes)) v ? next.set(k, v) : next.delete(k)
    if (!('page' in changes)) next.delete('page')
    setParams(next, { replace: true })
  }
  useEffect(() => {
    if ((params.get('q') ?? '') !== debounced) set({ q: debounced })
  }, [debounced])

  const list = useQuery({
    queryKey: ['admin', 'payments', page, filters, debounced],
    queryFn: () => adminApi.list<PaymentRow>('/admin/payments', { query: { page, ...filters, q: debounced || undefined } }),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <PageHeader
        title="Payments"
        description="Every payment is verified with the gateway on the server before a booking is confirmed. Only gateway identifiers are stored — never card details."
        actions={<AButton onClick={() => setExporting(true)}>Export CSV</AButton>}
      />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <SearchInput value={q} onChange={setQ} placeholder="Payment or booking reference, receipt or gateway ID" label="Search payments" />
        <FilterSelect label="Status" value={filters.status} onChange={(v) => set({ status: v })} options={toOptions(PAYMENT_STATUS)} />
        <FilterSelect label="Gateway" value={filters.gateway} onChange={(v) => set({ gateway: v })} options={toOptions(GATEWAYS)} />
        <FilterSelect label="Currency" value={filters.currency} onChange={(v) => set({ currency: v })} options={CURRENCIES.map((c) => ({ value: c, label: c }))} />
        <FilterSelect label="For" value={filters.payable_type} onChange={(v) => set({ payable_type: v })} options={toOptions(PAYABLE_LABEL)} />
      </div>
      {list.isError ? (
        <ErrorNote error={list.error} onRetry={() => list.refetch()} />
      ) : (
        <>
          <DataTable caption="Payments">
            <thead>
              <tr>
                <Th>Created</Th>
                <Th>For</Th>
                <Th>Gateway</Th>
                <Th className="text-right">Amount</Th>
                <Th>Status</Th>
              </tr>
            </thead>
            <tbody className={list.isFetching && !list.isLoading ? 'opacity-60' : ''}>
              {list.isLoading ? (
                <tr>
                  <td colSpan={5} className="px-4">
                    <Spinner />
                  </td>
                </tr>
              ) : list.data!.items.length === 0 ? (
                <TableMessage colSpan={5}>No payments match.</TableMessage>
              ) : (
                list.data!.items.map((p) => {
                  const s = labelOf(PAYMENT_STATUS, p.status)
                  return (
                    <tr key={p.id} className="hover:bg-midnight-800/50">
                      <Td>
                        <Link to={String(p.id)} className="tabular-nums text-ivory-50 hover:text-champagne-200 hover:underline">
                          {formatAdminDateTime(p.created_at)}
                        </Link>
                        <span className="block font-mono text-[0.65rem] text-slate-400">{p.receipt_number ?? p.reference}</span>
                      </Td>
                      <Td>
                        {PAYABLE_LABEL[p.payable_type]}
                        <span className="block font-mono text-[0.65rem] text-slate-400">{p.payable_reference ?? '—'}</span>
                      </Td>
                      <Td>
                        <span className="capitalize">{p.gateway}</span>
                        {p.environment !== 'production' && <span className="ml-2 align-middle"><Badge tone="muted">{p.environment}</Badge></span>}
                      </Td>
                      <Td className="text-right tabular-nums">
                        {formatMoney({ currency: p.currency, amount_minor: p.amount_minor })}
                        {p.refunded_minor > 0 && <span className="block text-xs text-slate-400">−{formatMoney({ currency: p.currency, amount_minor: p.refunded_minor })} refunded</span>}
                      </Td>
                      <Td>
                        <Badge tone={s.tone}>{s.label}</Badge>
                        {p.reconciliation_note && <span className="mt-1 block max-w-xs text-xs text-danger-300">{p.reconciliation_note}</span>}
                      </Td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </DataTable>
          {list.data && <Pagination page={list.data.meta.page} totalPages={list.data.meta.total_pages} total={list.data.meta.total} onPage={(p) => set({ page: String(p) })} />}
        </>
      )}
      <ExportDialog open={exporting} onClose={() => setExporting(false)} />
    </>
  )
}
