import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { countryName } from '../../../lib/countries'
import { adminApi } from '../../lib/adminApi'
import { formatAdminDateTime } from '../../lib/datetime'
import { APPLICATION_STATUS, FORMAT_LABEL, humanizeSlug, labelOf, toOptions } from '../../lib/labels'
import { useDebounced } from '../../resources/ResourceListPage'
import { FilterSelect, SearchInput } from '../../ui/Fields'
import { Badge, DataTable, ErrorNote, PageHeader, Pagination, Spinner, TableMessage, Td, Th } from '../../ui/Primitives'

interface ApplicationRow {
  id: number
  reference: string
  status: string
  consultation_type: string | null
  preferred_format: string | null
  country: string | null
  referral_source: string | null
  submitted_at: string | null
  full_name?: string | null
  organization?: string | null
}

export default function ApplicationsPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page') ?? 1))
  const status = params.get('status') ?? ''
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

  const list = useQuery({
    queryKey: ['admin', 'applications', page, status, debounced],
    queryFn: () => adminApi.list<ApplicationRow>('/admin/applications', { query: { page, status, q: debounced || undefined } }),
    placeholderData: keepPreviousData,
  })
  const confidential = list.data?.items.some((r) => 'full_name' in r) ?? false
  const cols = confidential ? 7 : 6

  return (
    <>
      <PageHeader title="Applications" description="Private access requests. Opening an application marks it as in review. Details are decrypted only for roles with confidential access, and every view is logged." />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <SearchInput value={q} onChange={setQ} placeholder="Reference or exact email" label="Search applications" />
        <FilterSelect label="Status" value={status} onChange={(v) => set({ status: v })} options={toOptions(APPLICATION_STATUS)} />
      </div>
      {list.isError ? (
        <ErrorNote error={list.error} onRetry={() => list.refetch()} />
      ) : (
        <>
          <DataTable caption="Applications">
            <thead>
              <tr>
                <Th>Reference</Th>
                {confidential && <Th>Applicant</Th>}
                <Th>Interest</Th>
                <Th>Format</Th>
                <Th>Country</Th>
                <Th>Submitted</Th>
                <Th>Status</Th>
              </tr>
            </thead>
            <tbody>
              {list.isLoading ? (
                <tr>
                  <td colSpan={cols} className="px-4">
                    <Spinner />
                  </td>
                </tr>
              ) : list.data!.items.length === 0 ? (
                <TableMessage colSpan={cols}>No applications match.</TableMessage>
              ) : (
                list.data!.items.map((a) => {
                  const s = labelOf(APPLICATION_STATUS, a.status)
                  return (
                    <tr key={a.id} className="hover:bg-midnight-800/50">
                      <Td>
                        <Link to={String(a.id)} className="font-mono text-xs text-ivory-50 hover:text-champagne-200 hover:underline">
                          {a.reference}
                        </Link>
                      </Td>
                      {confidential && (
                        <Td>
                          <span className="text-ivory-50">{a.full_name ?? '—'}</span>
                          {a.organization && <span className="block text-xs text-slate-400">{a.organization}</span>}
                        </Td>
                      )}
                      <Td>{humanizeSlug(a.consultation_type)}</Td>
                      <Td>{a.preferred_format ? (FORMAT_LABEL[a.preferred_format] ?? a.preferred_format) : '—'}</Td>
                      <Td>{a.country ? countryName(a.country) : '—'}</Td>
                      <Td>{formatAdminDateTime(a.submitted_at)}</Td>
                      <Td>
                        <Badge tone={s.tone}>{s.label}</Badge>
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
