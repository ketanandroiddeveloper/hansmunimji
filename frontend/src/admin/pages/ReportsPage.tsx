import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { formatMoney } from '../../lib/format'
import { adminApi } from '../lib/adminApi'
import { formatAdminDate } from '../lib/datetime'
import { APPLICATION_STATUS, APPOINTMENT_STATUS, FORMAT_LABEL, labelOf, PAYMENT_STATUS, REFERRAL_LABEL } from '../lib/labels'
import { Input } from '../ui/Fields'
import { DataTable, ErrorNote, PageHeader, Panel, Spinner, TableMessage, Td, Th } from '../ui/Primitives'

type Num = number | string

interface Report {
  range: { from: string; to: string }
  revenue_by_month: { month: string; currency: string; gross_minor: Num; refunded_minor: Num; payments: Num }[]
  bookings_by_status: { status: string; count: Num }[]
  bookings_by_type: { title: string; count: Num }[]
  bookings_by_format: { format: string; count: Num }[]
  applications_by_status: { status: string; count: Num }[]
  applications_by_source: { source: string; count: Num }[]
  environment: string
  revenue_by_gateway: { gateway: string; currency: string; gross_minor: Num; refunded_minor: Num; payments: Num }[]
  payment_outcomes: { gateway: string; status: string; count: Num }[]
  event_registrations: { id: number; title: string; starts_at: string; status: string; confirmed_seats: Num | null; waitlisted_seats: Num | null; cancellations: Num | null; seat_quota: Num | null }[]
  event_revenue: { event_id: number; currency: string; gross_minor: Num; refunded_minor: Num }[]
}

const GATEWAY_LABEL: Record<string, string> = { razorpay: 'Razorpay', stripe: 'Stripe' }

function netByCurrency(rows: { currency: string; gross_minor: Num; refunded_minor: Num }[]): string {
  return rows.map((x) => formatMoney({ currency: x.currency, amount_minor: Number(x.gross_minor) - Number(x.refunded_minor) })).join(' · ') || '—'
}

function Bars({ rows, empty }: { rows: { label: string; value: number }[]; empty: string }) {
  const max = Math.max(1, ...rows.map((r) => r.value))
  if (rows.length === 0) return <p className="text-sm font-light text-slate-400">{empty}</p>
  return (
    <ul className="space-y-3">
      {rows.map((r) => (
        <li key={r.label}>
          <div className="mb-1 flex justify-between gap-3 text-sm">
            <span className="font-light text-ivory-200">{r.label}</span>
            <span className="tabular-nums text-ivory-50">{r.value}</span>
          </div>
          <div className="h-1 bg-midnight-800" aria-hidden="true">
            <div className="h-full bg-champagne-400/70" style={{ width: `${(r.value / max) * 100}%` }} />
          </div>
        </li>
      ))}
    </ul>
  )
}

function monthLabel(month: string): string {
  const [y, m] = month.split('-').map(Number)
  return new Intl.DateTimeFormat(undefined, { month: 'short', year: 'numeric', timeZone: 'UTC' }).format(Date.UTC(y, m - 1, 1))
}

export default function ReportsPage() {
  const [params, setParams] = useSearchParams()
  const from = params.get('from') ?? ''
  const to = params.get('to') ?? ''
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params)
    v ? next.set(k, v) : next.delete(k)
    setParams(next, { replace: true })
  }

  const report = useQuery({
    queryKey: ['admin', 'reports', from, to],
    queryFn: () => adminApi.get<Report>('/admin/reports', { query: { from, to } }),
    placeholderData: keepPreviousData,
    enabled: !(from && to && from > to),
  })

  const r = report.data
  const totals = new Map<string, { gross: number; refunded: number; count: number }>()
  for (const row of r?.revenue_by_month ?? []) {
    const t = totals.get(row.currency) ?? { gross: 0, refunded: 0, count: 0 }
    t.gross += Number(row.gross_minor)
    t.refunded += Number(row.refunded_minor)
    t.count += Number(row.payments)
    totals.set(row.currency, t)
  }

  return (
    <>
      <PageHeader
        title="Reports"
        description={`Aggregate figures only — no personal data. Revenue is grouped by capture date (UTC) and shown per currency; currencies are never converted or summed together.${r ? ` Only ${r.environment} payments are counted.` : ''}`}
      />
      <div className="mb-6 flex flex-wrap items-end gap-4">
        <Input label="From" type="date" value={from} max={to || undefined} onChange={(e) => set('from', e.target.value)} />
        <Input label="To" type="date" value={to} min={from || undefined} onChange={(e) => set('to', e.target.value)} />
        {r && (
          <p className="pb-2 text-xs text-slate-400">
            Showing {formatAdminDate(r.range.from.slice(0, 10))} – {formatAdminDate(new Date(new Date(r.range.to).getTime() - 1).toISOString().slice(0, 10))} (UTC)
            {!from && !to && ' · last 12 months'}
          </p>
        )}
      </div>

      {from && to && from > to ? (
        <p className="text-sm text-danger-300">The start date must be on or before the end date.</p>
      ) : report.isLoading ? (
        <Spinner />
      ) : report.isError ? (
        <ErrorNote error={report.error} onRetry={() => report.refetch()} />
      ) : (
        <div className={`space-y-6 ${report.isFetching ? 'opacity-60' : ''}`}>
          {totals.size > 0 && (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              {[...totals].map(([currency, t]) => (
                <div key={currency} className="border border-[var(--line)] bg-midnight-900/60 p-5">
                  <p className="text-[0.62rem] font-semibold uppercase tracking-[0.2em] text-slate-400">Net revenue · {currency}</p>
                  <p className="mt-3 font-display text-3xl tabular-nums text-ivory-50">{formatMoney({ currency, amount_minor: t.gross - t.refunded })}</p>
                  <p className="mt-2 text-xs text-slate-400">
                    {t.count} payment{t.count === 1 ? '' : 's'}
                    {t.refunded > 0 && ` · ${formatMoney({ currency, amount_minor: t.refunded })} refunded`}
                  </p>
                </div>
              ))}
            </div>
          )}

          <Panel title="Revenue by month" padded={false}>
            <DataTable caption="Revenue by month">
              <thead>
                <tr>
                  <Th>Month</Th>
                  <Th>Currency</Th>
                  <Th className="text-right">Payments</Th>
                  <Th className="text-right">Gross</Th>
                  <Th className="text-right">Refunded</Th>
                  <Th className="text-right">Net</Th>
                </tr>
              </thead>
              <tbody>
                {r!.revenue_by_month.length === 0 ? (
                  <TableMessage colSpan={6}>No captured payments in this period.</TableMessage>
                ) : (
                  r!.revenue_by_month.map((m) => {
                    const gross = Number(m.gross_minor)
                    const refunded = Number(m.refunded_minor)
                    return (
                      <tr key={m.month + m.currency}>
                        <Td>{monthLabel(m.month)}</Td>
                        <Td>{m.currency}</Td>
                        <Td className="text-right tabular-nums">{Number(m.payments)}</Td>
                        <Td className="text-right tabular-nums">{formatMoney({ currency: m.currency, amount_minor: gross })}</Td>
                        <Td className="text-right tabular-nums">{refunded ? formatMoney({ currency: m.currency, amount_minor: refunded }) : '—'}</Td>
                        <Td className="text-right tabular-nums text-ivory-50">{formatMoney({ currency: m.currency, amount_minor: gross - refunded })}</Td>
                      </tr>
                    )
                  })
                )}
              </tbody>
            </DataTable>
          </Panel>

          <div className="grid gap-6 lg:grid-cols-2">
            <Panel title="Revenue by gateway" padded={false}>
              <DataTable caption="Revenue by gateway">
                <thead>
                  <tr>
                    <Th>Gateway</Th>
                    <Th>Currency</Th>
                    <Th className="text-right">Payments</Th>
                    <Th className="text-right">Net</Th>
                  </tr>
                </thead>
                <tbody>
                  {r!.revenue_by_gateway.length === 0 ? (
                    <TableMessage colSpan={4}>No captured payments in this period.</TableMessage>
                  ) : (
                    r!.revenue_by_gateway.map((g) => (
                      <tr key={g.gateway + g.currency}>
                        <Td>{GATEWAY_LABEL[g.gateway] ?? g.gateway}</Td>
                        <Td>{g.currency}</Td>
                        <Td className="text-right tabular-nums">{Number(g.payments)}</Td>
                        <Td className="text-right tabular-nums text-ivory-50">{netByCurrency([g])}</Td>
                      </tr>
                    ))
                  )}
                </tbody>
              </DataTable>
            </Panel>
            <Panel title="Payment outcomes">
              <p className="mb-4 text-xs text-slate-400">Every checkout started in this period, by gateway and current status.</p>
              <Bars
                rows={r!.payment_outcomes.map((o) => ({ label: `${GATEWAY_LABEL[o.gateway] ?? o.gateway} · ${labelOf(PAYMENT_STATUS, o.status).label}`, value: Number(o.count) }))}
                empty="No checkouts started in this period."
              />
            </Panel>
          </div>

          <div className="grid gap-6 lg:grid-cols-3">
            <Panel title="Bookings by status">
              <p className="mb-4 text-xs text-slate-400">By date booked.</p>
              <Bars rows={r!.bookings_by_status.map((b) => ({ label: labelOf(APPOINTMENT_STATUS, b.status).label, value: Number(b.count) }))} empty="No bookings made in this period." />
            </Panel>
            <Panel title="Sessions by consultation">
              <p className="mb-4 text-xs text-slate-400">Confirmed or completed, by session date.</p>
              <Bars rows={r!.bookings_by_type.map((b) => ({ label: b.title, value: Number(b.count) }))} empty="No sessions in this period." />
            </Panel>
            <Panel title="Sessions by format">
              <p className="mb-4 text-xs text-slate-400">Confirmed or completed, by session date.</p>
              <Bars rows={r!.bookings_by_format.map((b) => ({ label: FORMAT_LABEL[b.format] ?? b.format, value: Number(b.count) }))} empty="No sessions in this period." />
            </Panel>
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <Panel title="Applications by status">
              <p className="mb-4 text-xs text-slate-400">By date submitted.</p>
              <Bars rows={r!.applications_by_status.map((a) => ({ label: labelOf(APPLICATION_STATUS, a.status).label, value: Number(a.count) }))} empty="No applications submitted in this period." />
            </Panel>
            <Panel title="How applicants found you">
              <p className="mb-4 text-xs text-slate-400">By date submitted.</p>
              <Bars rows={r!.applications_by_source.map((a) => ({ label: a.source === 'unspecified' ? 'Not stated' : (REFERRAL_LABEL[a.source] ?? a.source), value: Number(a.count) }))} empty="No applications submitted in this period." />
            </Panel>
          </div>

          <Panel title="Gatherings" padded={false}>
            <DataTable caption="Gatherings">
              <thead>
                <tr>
                  <Th>Gathering</Th>
                  <Th>Date</Th>
                  <Th className="text-right">Confirmed seats</Th>
                  <Th className="text-right">Capacity</Th>
                  <Th className="text-right">Waitlist</Th>
                  <Th className="text-right">Cancellations</Th>
                  <Th className="text-right">Net contributions</Th>
                </tr>
              </thead>
              <tbody>
                {r!.event_registrations.length === 0 ? (
                  <TableMessage colSpan={7}>No gatherings in this period.</TableMessage>
                ) : (
                  r!.event_registrations.map((e) => (
                    <tr key={e.id}>
                      <Td className="text-ivory-50">
                        {e.title}
                        {e.status === 'cancelled' && <span className="ml-2 text-xs text-danger-300">Cancelled</span>}
                      </Td>
                      <Td>{formatAdminDate(e.starts_at)}</Td>
                      <Td className="text-right tabular-nums">{Number(e.confirmed_seats ?? 0)}</Td>
                      <Td className="text-right tabular-nums">{e.seat_quota === null ? 'Unlimited' : Number(e.seat_quota)}</Td>
                      <Td className="text-right tabular-nums">{Number(e.waitlisted_seats ?? 0) || '—'}</Td>
                      <Td className="text-right tabular-nums">{Number(e.cancellations ?? 0) || '—'}</Td>
                      <Td className="text-right tabular-nums text-ivory-50">{netByCurrency(r!.event_revenue.filter((x) => Number(x.event_id) === Number(e.id)))}</Td>
                    </tr>
                  ))
                )}
              </tbody>
            </DataTable>
          </Panel>
        </div>
      )}
    </>
  )
}
