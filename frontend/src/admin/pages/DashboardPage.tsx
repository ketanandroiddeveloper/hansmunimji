import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { formatMoney } from '../../lib/format'
import { adminApi } from '../lib/adminApi'
import { formatAdminDateTime } from '../lib/datetime'
import { GOOGLE_PAGE } from '../lib/integrations'
import { APPOINTMENT_STATUS, FORMAT_LABEL, labelOf } from '../lib/labels'
import { useSession } from '../lib/session'
import { Badge, ErrorNote, Notice, PageHeader, Panel, Spinner, Stat } from '../ui/Primitives'

interface Dashboard {
  upcoming?: { id: number; reference: string; status: string; starts_at: string; format: string; type_title: string; client_name: string | null }[]
  counts?: { today?: number; needs_attention?: number; pending_payment?: number; applications_new?: number; applications_in_review?: number }
  revenue_30d?: { currency: string; net_minor: string | number; count: string | number }[]
  health?: { google_calendar: 'connected' | 'not_connected' | 'not_configured' | 'needs_reauth' | 'missing_permission'; failed_jobs: number; failed_emails: number; failed_webhooks: number }
}

const GOOGLE: Record<string, string> = {
  connected: 'connected',
  not_connected: 'not connected',
  not_configured: 'not configured',
  needs_reauth: 'disconnected because access was revoked or expired',
  missing_permission: 'connected without every required permission',
}
const GOOGLE_BADGE: Record<string, string> = {
  connected: 'Connected',
  not_connected: 'Not connected',
  not_configured: 'Not configured',
  needs_reauth: 'Reconnect required',
  missing_permission: 'Permission missing',
}

export default function DashboardPage() {
  const { user } = useSession()
  const dash = useQuery({ queryKey: ['admin', 'dashboard'], queryFn: () => adminApi.get<Dashboard>('/admin/dashboard'), refetchInterval: 60_000 })
  const hour = new Date().getHours()
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'

  if (dash.isLoading) return <Spinner />
  if (dash.isError) return <ErrorNote error={dash.error} onRetry={() => dash.refetch()} />
  const d = dash.data!
  const c = d.counts ?? {}
  const healthIssues = d.health ? d.health.failed_jobs + d.health.failed_emails + d.health.failed_webhooks : 0

  return (
    <>
      <PageHeader title={`${greeting}, ${user?.name.split(' ')[0] ?? ''}`} description="An overview of the private office today." />

      {d.health && (healthIssues > 0 || d.health.google_calendar !== 'connected') && (
        <div className="mb-6">
          <Notice tone="warn">
            {d.health.google_calendar !== 'connected' && <>Google Workspace is {GOOGLE[d.health.google_calendar]}, so calendar invitations, Meet links or Gmail messages may not be sent. </>}
            {healthIssues > 0 && <>There are {healthIssues} failed background tasks in the last 7 days. </>}
            <Link to={d.health.google_calendar !== 'connected' ? GOOGLE_PAGE : '/admin/integrations'} className="underline underline-offset-4">
              Review integrations
            </Link>
          </Notice>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {c.today !== undefined && <Stat label="Sessions today" value={c.today} to="/admin/appointments" />}
        {c.applications_new !== undefined && <Stat label="New applications" value={c.applications_new} hint={`${c.applications_in_review ?? 0} in review`} to="/admin/applications?status=submitted" />}
        {c.pending_payment !== undefined && <Stat label="Awaiting payment" value={c.pending_payment} to="/admin/appointments?status=pending_payment" />}
        {c.needs_attention !== undefined && <Stat label="Needs attention" value={c.needs_attention} hint="Payment checks or calendar failures" to="/admin/appointments?status=payment_verification" />}
      </div>

      <div className="mt-8 grid gap-6 xl:grid-cols-[2fr_1fr]">
        {d.upcoming && (
          <Panel title="Upcoming sessions" padded={false}>
            {d.upcoming.length === 0 ? (
              <p className="px-5 py-10 text-center text-sm font-light text-slate-400">No confirmed sessions ahead.</p>
            ) : (
              <ul className="divide-y divide-[var(--line-faint)]">
                {d.upcoming.map((a) => (
                  <li key={a.id}>
                    <Link to={`/admin/appointments/${a.id}`} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 transition-colors hover:bg-midnight-800/50">
                      <span className="min-w-0">
                        <span className="block text-sm text-ivory-50">{a.client_name ?? 'Client'}</span>
                        <span className="block text-xs text-slate-400">
                          {a.type_title} · {FORMAT_LABEL[a.format] ?? a.format}
                        </span>
                      </span>
                      <span className="flex items-center gap-3">
                        <span className="text-sm tabular-nums text-ivory-200">{formatAdminDateTime(a.starts_at)}</span>
                        <Badge tone={labelOf(APPOINTMENT_STATUS, a.status).tone}>{labelOf(APPOINTMENT_STATUS, a.status).label}</Badge>
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        )}
        <div className="space-y-6">
          {d.revenue_30d && (
            <Panel title="Net revenue · 30 days">
              {d.revenue_30d.length === 0 ? (
                <p className="text-sm font-light text-slate-400">No captured payments in this period.</p>
              ) : (
                <ul className="space-y-3">
                  {d.revenue_30d.map((r) => (
                    <li key={r.currency} className="flex items-baseline justify-between">
                      <span className="font-display text-2xl text-ivory-50 tabular-nums">{formatMoney({ currency: r.currency, amount_minor: Number(r.net_minor) })}</span>
                      <span className="text-xs text-slate-400">{Number(r.count)} payments</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          )}
          {d.health && (
            <Panel title="System health">
              <ul className="space-y-2 text-sm font-light">
                <li className="flex justify-between">
                  <span className="text-slate-400">Google Workspace</span>
                  <Badge tone={d.health.google_calendar === 'connected' ? 'green' : 'red'}>{GOOGLE_BADGE[d.health.google_calendar]}</Badge>
                </li>
                <li className="flex justify-between">
                  <span className="text-slate-400">Failed jobs (7 days)</span>
                  <span className={d.health.failed_jobs ? 'text-danger-300' : 'text-ivory-200'}>{d.health.failed_jobs}</span>
                </li>
                <li className="flex justify-between">
                  <span className="text-slate-400">Failed emails (7 days)</span>
                  <span className={d.health.failed_emails ? 'text-danger-300' : 'text-ivory-200'}>{d.health.failed_emails}</span>
                </li>
                <li className="flex justify-between">
                  <span className="text-slate-400">Failed webhooks</span>
                  <span className={d.health.failed_webhooks ? 'text-danger-300' : 'text-ivory-200'}>{d.health.failed_webhooks}</span>
                </li>
              </ul>
            </Panel>
          )}
        </div>
      </div>
    </>
  )
}
