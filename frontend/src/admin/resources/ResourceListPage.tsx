import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { adminApi } from '../lib/adminApi'
import { useSession } from '../lib/session'
import { FilterSelect, SearchInput } from '../ui/Fields'
import { ALink, DataTable, ErrorNote, PageHeader, Pagination, Spinner, TableMessage, Td, Th } from '../ui/Primitives'
import type { ResourceDef, Row } from './types'

export function useDebounced<T>(value: T, ms = 300): T {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = window.setTimeout(() => setV(value), ms)
    return () => window.clearTimeout(t)
  }, [value, ms])
  return v
}

export function ResourceListPage({ def }: { def: ResourceDef }) {
  const { can } = useSession()
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page') ?? 1))
  const [q, setQ] = useState(params.get('q') ?? '')
  const debounced = useDebounced(q)
  const filters = Object.fromEntries((def.filters ?? []).map((f) => [f.name, params.get(f.name) ?? '']))

  const update = (changes: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(changes)) {
      if (v) next.set(k, v)
      else next.delete(k)
    }
    if (!('page' in changes)) next.delete('page')
    setParams(next, { replace: true })
  }

  useEffect(() => {
    if ((params.get('q') ?? '') !== debounced) update({ q: debounced })
  }, [debounced])

  const list = useQuery({
    queryKey: ['admin', 'resource', def.key, 'list', page, debounced, filters],
    queryFn: () => adminApi.list<Row>(def.endpoint, { query: { page, per_page: 25, q: debounced || undefined, ...filters } }),
    placeholderData: keepPreviousData,
  })

  const canCreate = def.canCreate !== false && can(def.permission)

  return (
    <>
      <PageHeader
        title={def.title}
        description={def.description}
        actions={
          canCreate && (
            <ALink to="new" variant="primary">
              New {def.singular.toLowerCase()}
            </ALink>
          )
        }
      />
      {def.notice && <div className="mb-6">{def.notice}</div>}
      {(def.search || def.filters?.length) && (
        <div className="mb-4 flex flex-wrap items-center gap-3">
          {def.search && <SearchInput value={q} onChange={setQ} placeholder={`Search ${def.title.toLowerCase()}`} />}
          {def.filters?.map((f) => (
            <FilterSelect key={f.name} label={f.label} value={filters[f.name]} onChange={(v) => update({ [f.name]: v })} options={f.options} />
          ))}
        </div>
      )}
      {list.isError ? (
        <ErrorNote error={list.error} onRetry={() => list.refetch()} />
      ) : (
        <>
          <DataTable caption={def.title}>
            <thead>
              <tr>
                {def.columns.map((c) => (
                  <Th key={c.key} className={c.className}>
                    {c.label}
                  </Th>
                ))}
              </tr>
            </thead>
            <tbody className={list.isFetching && !list.isLoading ? 'opacity-60' : ''}>
              {list.isLoading ? (
                <tr>
                  <td colSpan={def.columns.length} className="px-4">
                    <Spinner />
                  </td>
                </tr>
              ) : list.data!.items.length === 0 ? (
                <TableMessage colSpan={def.columns.length}>{debounced || Object.values(filters).some(Boolean) ? 'Nothing matches these filters.' : `No ${def.title.toLowerCase()} yet.`}</TableMessage>
              ) : (
                list.data!.items.map((row) => (
                  <tr key={row.id} className="transition-colors hover:bg-midnight-800/50">
                    {def.columns.map((c, i) => (
                      <Td key={c.key} className={c.className}>
                        {i === 0 ? (
                          <Link to={String(row.id)} className="text-ivory-50 underline-offset-4 hover:text-champagne-200 hover:underline">
                            {c.render ? c.render(row) : String(row[c.key] ?? '—')}
                          </Link>
                        ) : c.render ? (
                          c.render(row)
                        ) : (
                          String(row[c.key] ?? '—')
                        )}
                      </Td>
                    ))}
                  </tr>
                ))
              )}
            </tbody>
          </DataTable>
          {list.data && <Pagination page={list.data.meta.page} totalPages={list.data.meta.total_pages} total={list.data.meta.total} onPage={(p) => update({ page: String(p) })} />}
        </>
      )}
    </>
  )
}
