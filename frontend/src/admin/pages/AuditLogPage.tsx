import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router'
import { adminApi } from '../lib/adminApi'
import { formatAdminDateTime } from '../lib/datetime'
import { useSession } from '../lib/session'
import { FilterSelect } from '../ui/Fields'
import { DataTable, ErrorNote, PageHeader, Pagination, Spinner, TableMessage, Td, Th } from '../ui/Primitives'

interface AuditRow {
  id: number
  user_id: number | null
  user_name: string | null
  action: string
  entity_type: string | null
  entity_id: number | string | null
  metadata: Record<string, unknown> | null
  created_at: string
}

const AREAS = [
  { value: 'auth.', label: 'Sign-in & security' },
  { value: 'application.', label: 'Applications' },
  { value: 'appointment.', label: 'Appointments' },
  { value: 'payment.', label: 'Payments' },
  { value: 'user.', label: 'Administrators' },
  { value: 'settings.', label: 'Settings' },
  { value: 'integration.', label: 'Integrations' },
  { value: 'media.', label: 'Media' },
  { value: 'practitioner.', label: 'Practitioner profile' },
  { value: 'pages.', label: 'Pages' },
  { value: 'services.', label: 'Services' },
  { value: 'blogs.', label: 'Journal' },
  { value: 'events.', label: 'Gatherings' },
  { value: 'qualifications.', label: 'Qualifications' },
]

const ENTITY_LINK: Record<string, (id: string) => string> = {
  application: (id) => `/admin/applications/${id}`,
  appointment: (id) => `/admin/appointments/${id}`,
  payment: (id) => `/admin/payments/${id}`,
}

function describeAction(action: string): string {
  const [area, ...rest] = action.split('.')
  const verb = rest.join(' ').replace(/_/g, ' ')
  return `${area.replace(/_/g, ' ')} · ${verb}`
}

function MetadataCell({ metadata }: { metadata: AuditRow['metadata'] }) {
  if (!metadata || Object.keys(metadata).length === 0) return <span className="text-slate-400">—</span>
  return (
    <dl className="space-y-0.5 text-xs">
      {Object.entries(metadata).map(([k, v]) => (
        <div key={k} className="flex gap-2">
          <dt className="text-slate-400">{k.replace(/_/g, ' ')}:</dt>
          <dd className="break-all">{Array.isArray(v) ? v.join(', ') : typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v)}</dd>
        </div>
      ))}
    </dl>
  )
}

export default function AuditLogPage() {
  const { can } = useSession()
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page') ?? 1))
  const action = params.get('action') ?? ''
  const userId = params.get('user_id') ?? ''
  const set = (changes: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(changes)) v ? next.set(k, v) : next.delete(k)
    if (!('page' in changes)) next.delete('page')
    setParams(next, { replace: true })
  }

  const users = useQuery({
    queryKey: ['admin', 'users'],
    queryFn: () => adminApi.get<{ id: number; name: string }[]>('/admin/users'),
    enabled: can('users.manage'),
  })
  const list = useQuery({
    queryKey: ['admin', 'audit', page, action, userId],
    queryFn: () => adminApi.list<AuditRow>('/admin/audit-logs', { query: { page, action, user_id: userId } }),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <PageHeader title="Audit log" description="A permanent record of sign-ins, changes and access to confidential records. Entries cannot be edited or deleted from the admin. Confidential content is never written to the log." />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <FilterSelect label="Area" value={action} onChange={(v) => set({ action: v })} options={AREAS} />
        {can('users.manage') && <FilterSelect label="Person" value={userId} onChange={(v) => set({ user_id: v })} options={(users.data ?? []).map((u) => ({ value: String(u.id), label: u.name }))} />}
      </div>
      {list.isError ? (
        <ErrorNote error={list.error} onRetry={() => list.refetch()} />
      ) : (
        <>
          <DataTable caption="Audit log">
            <thead>
              <tr>
                <Th>When</Th>
                <Th>Who</Th>
                <Th>Action</Th>
                <Th>Record</Th>
                <Th>Details</Th>
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
                <TableMessage colSpan={5}>No entries.</TableMessage>
              ) : (
                list.data!.items.map((r) => {
                  const link = r.entity_type && r.entity_id !== null ? ENTITY_LINK[r.entity_type]?.(String(r.entity_id)) : undefined
                  return (
                    <tr key={r.id}>
                      <Td className="whitespace-nowrap tabular-nums">{formatAdminDateTime(r.created_at)}</Td>
                      <Td>{r.user_name ?? (r.user_id ? `User #${r.user_id}` : <span className="text-slate-400">System / client</span>)}</Td>
                      <Td className="capitalize">{describeAction(r.action)}</Td>
                      <Td>
                        {r.entity_type ? (
                          link ? (
                            <Link to={link} className="text-champagne-200 hover:underline">
                              {r.entity_type.replace(/_/g, ' ')} #{r.entity_id}
                            </Link>
                          ) : (
                            <span>
                              {r.entity_type.replace(/_/g, ' ')}
                              {r.entity_id !== null && ` #${r.entity_id}`}
                            </span>
                          )
                        ) : (
                          '—'
                        )}
                      </Td>
                      <Td>
                        <MetadataCell metadata={r.metadata} />
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
