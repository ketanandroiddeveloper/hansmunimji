import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { browserTimeZone, formatMoney } from '../../../lib/format'
import { adminApi } from '../../lib/adminApi'
import { formatAdminDateTime, zonedInputToIso } from '../../lib/datetime'
import { APPOINTMENT_STATUS, CALENDAR_STATUS, FORMAT_LABEL, labelOf, toOptions } from '../../lib/labels'
import { useSession } from '../../lib/session'
import { useDebounced } from '../../resources/ResourceListPage'
import { borderClass, controlClass, FilterSelect, SearchInput } from '../../ui/Fields'
import { ALink, Badge, DataTable, ErrorNote, PageHeader, Pagination, Spinner, TableMessage, Td, Th } from '../../ui/Primitives'

interface AppointmentRow {
  id: number
  reference: string
  status: string
  type_title: string
  starts_at: string
  ends_at: string
  client_timezone: string
  format: string
  client_name: string | null
  currency: string | null
  total_minor: number
  calendar_sync_status: string
}

const VIEWS = { upcoming: 'Upcoming', past: 'Past', all: 'All dates' } as const

export default function AppointmentsPage() {
  const { can } = useSession()
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page') ?? 1))
  const status = params.get('status') ?? ''
  const type = params.get('type') ?? ''
  const view = (params.get('view') as keyof typeof VIEWS) ?? 'upcoming'
  const date = params.get('date') ?? ''
  const [q, setQ] = useState(params.get('q') ?? '')
  const debounced = useDebounced(q, 400)

  const set = (changes: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(changes)) v ? next.set(k, v) : next.delete(k)
    if (!('page' in changes)) next.delete('page')
    setParams(next, { replace: true })
  }
  useEffect(() => {
    if ((params.get('q') ?? '') !== debounced) set({ q: debounced })
  }, [debounced])

  const types = useQuery({
    queryKey: ['admin', 'options', '/admin/appointment-types'],
    queryFn: () => adminApi.list<{ id: number; slug: string; title: string }>('/admin/appointment-types', { query: { per_page: 100 } }),
    staleTime: 60_000,
  })

  const tz = browserTimeZone()
  const now = new Date(Math.floor(Date.now() / 60_000) * 60_000).toISOString()
  const range: Record<string, string | undefined> = date
    ? { from: zonedInputToIso(`${date}T00:00`, tz) ?? undefined, to: zonedInputToIso(`${date}T23:59`, tz) ?? undefined }
    : view === 'past'
      ? { to: now, sort: '-starts_at' }
      : view === 'all'
        ? { sort: '-starts_at' }
        : { from: now }

  const list = useQuery({
    queryKey: ['admin', 'appointments', page, status, type, view, date, debounced],
    queryFn: () => adminApi.list<AppointmentRow>('/admin/appointments', { query: { page, status, type, q: debounced || undefined, ...range } }),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <PageHeader
        title="Appointments"
        description="Times are shown in your time zone. Search by booking reference or the client’s exact email address."
        actions={can('appointments.manage') && <ALink to="new" variant="primary">New booking</ALink>}
      />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <SearchInput value={q} onChange={setQ} placeholder="Reference or exact email" label="Search appointments" />
        <div role="group" aria-label="Time range" className="flex border border-[var(--line-strong)]">
          {(Object.keys(VIEWS) as (keyof typeof VIEWS)[]).map((v) => (
            <button
              key={v}
              type="button"
              aria-pressed={view === v && !date}
              onClick={() => set({ view: v === 'upcoming' ? '' : v, date: '' })}
              className={`px-3 py-2 text-[0.68rem] uppercase tracking-[0.14em] transition-colors ${view === v && !date ? 'bg-champagne-400/15 text-champagne-200' : 'text-ivory-200 hover:text-champagne-200'}`}
            >
              {VIEWS[v]}
            </button>
          ))}
        </div>
        <label>
          <span className="sr-only">On date</span>
          <input type="date" value={date} onChange={(e) => set({ date: e.target.value })} className={`${controlClass} ${borderClass()} w-auto`} aria-label="On date" />
        </label>
        <FilterSelect label="Status" value={status} onChange={(v) => set({ status: v })} options={toOptions(APPOINTMENT_STATUS)} />
        <FilterSelect label="Consultation" value={type} onChange={(v) => set({ type: v })} options={(types.data?.items ?? []).map((t) => ({ value: t.slug, label: t.title }))} />
      </div>
      {list.isError ? (
        <ErrorNote error={list.error} onRetry={() => list.refetch()} />
      ) : (
        <>
          <DataTable caption="Appointments">
            <thead>
              <tr>
                <Th>When</Th>
                <Th>Client</Th>
                <Th>Consultation</Th>
                <Th>Fee</Th>
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
                <TableMessage colSpan={5}>No appointments match.</TableMessage>
              ) : (
                list.data!.items.map((a) => {
                  const s = labelOf(APPOINTMENT_STATUS, a.status)
                  return (
                    <tr key={a.id} className="hover:bg-midnight-800/50">
                      <Td>
                        <Link to={String(a.id)} className="text-ivory-50 tabular-nums hover:text-champagne-200 hover:underline">
                          {formatAdminDateTime(a.starts_at)}
                        </Link>
                        <span className="block font-mono text-[0.65rem] text-slate-400">{a.reference}</span>
                      </Td>
                      <Td>
                        <span className="text-ivory-50">{a.client_name ?? '—'}</span>
                        <span className="block text-xs text-slate-400">{a.client_timezone}</span>
                      </Td>
                      <Td>
                        {a.type_title}
                        <span className="block text-xs text-slate-400">{FORMAT_LABEL[a.format] ?? a.format}</span>
                      </Td>
                      <Td>{a.total_minor > 0 && a.currency ? formatMoney({ currency: a.currency, amount_minor: a.total_minor }) : 'Complimentary'}</Td>
                      <Td>
                        <span className="flex flex-col items-start gap-1">
                          <Badge tone={s.tone}>{s.label}</Badge>
                          {a.calendar_sync_status === 'failed' && <Badge tone="red">{CALENDAR_STATUS.failed.label}</Badge>}
                        </span>
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
    </>
  )
}
