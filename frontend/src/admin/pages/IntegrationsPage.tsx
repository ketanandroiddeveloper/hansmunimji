import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router'
import { adminApi, errorMessage } from '../lib/adminApi'
import { formatAdminDateTime } from '../lib/datetime'
import { GOOGLE_PAGE, INTEGRATIONS_KEY } from '../lib/integrations'
import type { Integrations } from '../lib/integrations'
import { Copyable } from '../ui/Copyable'
import { AButton, Badge, DataTable, ErrorNote, KeyValues, Notice, PageHeader, Panel, Spinner, Stat, TableMessage, Td, Th } from '../ui/Primitives'
import { useToast } from '../ui/Toast'

const GATEWAY_NAME: Record<string, string> = { razorpay: 'Razorpay', stripe: 'Stripe' }

export default function IntegrationsPage() {
  const notify = useToast()
  const client = useQueryClient()
  const data = useQuery({ queryKey: INTEGRATIONS_KEY, queryFn: () => adminApi.get<Integrations>('/admin/integrations') })

  const refresh = () => client.invalidateQueries({ queryKey: INTEGRATIONS_KEY })
  const retry = useMutation({
    mutationFn: (id: number) => adminApi.post(`/admin/integrations/jobs/${id}/retry`),
    onSuccess: () => {
      refresh()
      notify('Job queued to run again.')
    },
    onError: (e) => notify(errorMessage(e), 'error'),
  })
  const testEmail = useMutation({
    mutationFn: () => adminApi.post('/admin/integrations/email/test'),
    onSuccess: () => notify('Test email sent to your address.'),
    onError: (e) => notify(errorMessage(e), 'error'),
  })

  if (data.isLoading) return <Spinner />
  if (data.isError) return <ErrorNote error={data.error} onRetry={() => data.refetch()} />
  const d = data.data!
  const g = d.google
  const googleHealthy = g.status === 'connected' && g.missing_scopes.length === 0

  return (
    <>
      <PageHeader
        title="Integrations"
        description={
          <>
            Credentials are set in the server environment for <strong className="font-medium text-ivory-50">{d.environment}</strong> and are never shown here. This page reports only whether each service is configured.
          </>
        }
      />
      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Jobs waiting" value={d.queues.jobs_pending} hint={d.queues.jobs_pending > 50 ? 'Check the worker is running' : 'Background worker queue'} />
        <Stat label="Jobs failed" value={d.queues.jobs_failed} />
        <Stat label="Emails queued" value={d.queues.emails_queued} />
        <Stat label="Emails failed" value={d.queues.emails_failed} />
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Panel
          title="Google Workspace"
          actions={
            <Link to={GOOGLE_PAGE} className="text-[0.65rem] uppercase tracking-[0.18em] text-champagne-200 hover:underline">
              Manage
            </Link>
          }
        >
          {!g.configured ? (
            <Notice tone="warn">Google OAuth credentials are not set for this environment. Bookings are confirmed normally, but no calendar events, Meet links or Gmail messages are created.</Notice>
          ) : (
            <KeyValues
              items={[
                ['Status', <Badge key="s" tone={googleHealthy ? 'green' : g.status === 'disconnected' ? 'muted' : 'red'}>{g.status === 'needs_reauth' ? 'Reauthorization required' : g.status === 'disconnected' ? 'Not connected' : googleHealthy ? 'Connected' : 'Permission missing'}</Badge>],
                ['Account', g.account_email ?? '—'],
                ['Gmail · Calendar · Meet', `${g.services.gmail.status === 'connected' ? 'OK' : '—'} · ${g.services.calendar.status === 'connected' ? 'OK' : '—'} · ${g.services.meet.status === 'connected' ? 'OK' : '—'}`],
                ['Last sync', formatAdminDateTime(g.last_synced_at)],
              ]}
            />
          )}
        </Panel>

        <Panel title="Email" actions={d.email.configured && <AButton variant="ghost" className="!py-1" loading={testEmail.isPending} onClick={() => testEmail.mutate()}>Send test</AButton>}>
          <KeyValues
            items={[
              ['Status', <Badge key="s" tone={d.email.configured ? 'green' : 'red'}>{d.email.configured ? 'Configured' : 'Not configured'}</Badge>],
              ['Driver', d.email.driver],
            ]}
          />
          {!d.email.configured && <p className="mt-4 text-sm font-light text-slate-400">Set the MAIL_* variables in the server environment. Until then, emails stay queued and are not delivered.</p>}
          {d.email.driver === 'log' && <p className="mt-4 text-sm font-light text-slate-400">The log driver writes emails to the server log instead of sending them — suitable for local development only.</p>}
        </Panel>

        {Object.entries(d.payments).map(([name, p]) => (
          <Panel key={name} title={GATEWAY_NAME[name] ?? name}>
            <KeyValues
              items={[
                ['Status', <Badge key="s" tone={p.configured ? 'green' : 'red'}>{p.configured ? 'Configured' : 'Not configured'}</Badge>],
                ['Mode', <Badge key="m" tone={p.mode === 'live' ? 'gold' : 'muted'}>{p.mode}</Badge>],
                ['Currencies', p.currencies.join(', ')],
                ['Webhook URL', <Copyable key="w" value={p.webhook_url} />],
              ]}
            />
            {p.configured && d.environment !== 'production' && p.mode === 'live' && <div className="mt-4"><Notice tone="error">Live keys are configured outside production. Replace them with test keys.</Notice></div>}
            {p.configured && d.environment === 'production' && p.mode === 'test' && <div className="mt-4"><Notice tone="warn">Test keys are configured in production; real payments cannot be taken.</Notice></div>}
            {!p.configured && <p className="mt-4 text-sm font-light text-slate-400">Add the {GATEWAY_NAME[name] ?? name} keys and webhook secret to the server environment, then register the webhook URL above in the {GATEWAY_NAME[name] ?? name} dashboard.</p>}
          </Panel>
        ))}

        <Panel title="Static site rebuild">
          <KeyValues items={[['Status', <Badge key="s" tone={d.frontend_rebuild.configured ? 'green' : 'muted'}>{d.frontend_rebuild.configured ? 'Configured' : 'Not configured'}</Badge>]]} />
          <p className="mt-4 text-sm font-light text-slate-400">When configured (FRONTEND_REBUILD_HOOK_URL), content changes trigger a rebuild of the pre-rendered public pages.</p>
        </Panel>
      </div>

      <h2 className="mb-3 mt-8 text-[0.68rem] font-semibold uppercase tracking-[0.2em] text-ivory-200">Recent failed jobs</h2>
      <DataTable caption="Failed jobs">
        <thead>
          <tr>
            <Th>Job</Th>
            <Th>Attempts</Th>
            <Th>Error</Th>
            <Th>Failed</Th>
            <Th>
              <span className="sr-only">Actions</span>
            </Th>
          </tr>
        </thead>
        <tbody>
          {d.failed_jobs.length === 0 ? (
            <TableMessage colSpan={5}>No failed jobs.</TableMessage>
          ) : (
            d.failed_jobs.map((j) => (
              <tr key={j.id}>
                <Td className="font-mono text-xs">{j.type}</Td>
                <Td className="tabular-nums">{j.attempts}</Td>
                <Td className="max-w-md break-words text-xs">{j.last_error ?? '—'}</Td>
                <Td className="whitespace-nowrap">{formatAdminDateTime(j.finished_at)}</Td>
                <Td>
                  <AButton variant="ghost" className="!py-1" disabled={retry.isPending && retry.variables === j.id} onClick={() => retry.mutate(j.id)}>
                    Retry
                  </AButton>
                </Td>
              </tr>
            ))
          )}
        </tbody>
      </DataTable>
    </>
  )
}
