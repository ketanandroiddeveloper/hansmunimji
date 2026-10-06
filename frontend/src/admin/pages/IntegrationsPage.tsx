import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { useSearchParams } from 'react-router'
import { adminApi, errorMessage } from '../lib/adminApi'
import { formatAdminDateTime } from '../lib/datetime'
import { humanizeSlug } from '../lib/labels'
import { useConfirm } from '../ui/Dialog'
import { AButton, Badge, DataTable, ErrorNote, KeyValues, Notice, PageHeader, Panel, Spinner, Stat, TableMessage, Td, Th } from '../ui/Primitives'
import { useToast } from '../ui/Toast'

interface Integrations {
  environment: string
  google_calendar: { configured: boolean; status: 'connected' | 'needs_reauth' | 'disconnected'; account_email: string | null; calendar_id: string | null; last_error: string | null; last_synced_at: string | null; redirect_uri: string | null }
  payments: Record<string, { configured: boolean; mode: 'live' | 'test' | 'unconfigured'; currencies: string[]; webhook_url: string }>
  email: { configured: boolean; driver: string }
  frontend_rebuild: { configured: boolean }
  queues: { jobs_pending: number; jobs_failed: number; emails_queued: number; emails_failed: number }
  failed_jobs: { id: number; type: string; attempts: number; last_error: string | null; finished_at: string | null }[]
}

const GOOGLE_RESULT: Record<string, { tone: 'success' | 'error'; text: string }> = {
  connected: { tone: 'success', text: 'Google Calendar connected. Upcoming bookings are being synced.' },
  invalid_state: { tone: 'error', text: 'The Google sign-in link expired or was invalid. Please try connecting again.' },
  unauthorized: { tone: 'error', text: 'Your session or permissions changed during sign-in. Please sign in and try again.' },
  denied: { tone: 'error', text: 'Access was not granted in Google. The calendar is not connected.' },
  failed: { tone: 'error', text: 'Google could not complete the connection. Check the OAuth configuration and try again.' },
}

const GATEWAY_NAME: Record<string, string> = { razorpay: 'Razorpay', stripe: 'Stripe' }

function Copyable({ value }: { value: string }) {
  const notify = useToast()
  return (
    <span className="flex items-center gap-2">
      <code className="min-w-0 break-all font-mono text-xs text-ivory-200">{value}</code>
      <button
        type="button"
        className="shrink-0 text-[0.6rem] uppercase tracking-[0.16em] text-champagne-200 hover:underline"
        onClick={() => navigator.clipboard.writeText(value).then(() => notify('Copied.'), () => notify('Could not copy.', 'error'))}
      >
        Copy
      </button>
    </span>
  )
}

export default function IntegrationsPage() {
  const notify = useToast()
  const confirm = useConfirm()
  const client = useQueryClient()
  const [params, setParams] = useSearchParams()
  const googleResult = GOOGLE_RESULT[params.get('google') ?? '']
  const data = useQuery({ queryKey: ['admin', 'integrations'], queryFn: () => adminApi.get<Integrations>('/admin/integrations') })

  useEffect(() => {
    if (params.get('google') === 'connected') client.invalidateQueries({ queryKey: ['admin', 'dashboard'] })
  }, [params, client])

  const refresh = () => client.invalidateQueries({ queryKey: ['admin', 'integrations'] })
  const connect = useMutation({
    mutationFn: () => adminApi.post<{ authorization_url: string }>('/admin/integrations/google/connect'),
    onSuccess: (r) => window.location.assign(r.authorization_url),
    onError: (e) => notify(errorMessage(e), 'error'),
  })
  const disconnect = useMutation({
    mutationFn: () => adminApi.post('/admin/integrations/google/disconnect'),
    onSuccess: () => {
      refresh()
      notify('Google Calendar disconnected.')
    },
    onError: (e) => notify(errorMessage(e), 'error'),
  })
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
  const g = d.google_calendar

  const onDisconnect = async () => {
    const ok = await confirm({
      title: 'Disconnect Google Calendar?',
      body: 'New bookings will not be added to the calendar and no Meet links will be created until it is reconnected. Existing events are left in place.',
      confirmLabel: 'Disconnect',
      danger: true,
    })
    if (ok) disconnect.mutate()
  }

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
      {googleResult && (
        <div className="mb-6 flex items-start justify-between gap-4">
          <div className="flex-1">
            <Notice tone={googleResult.tone}>{googleResult.text}</Notice>
          </div>
          <AButton variant="ghost" onClick={() => setParams({}, { replace: true })}>
            Dismiss
          </AButton>
        </div>
      )}

      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Jobs waiting" value={d.queues.jobs_pending} hint={d.queues.jobs_pending > 50 ? 'Check the worker is running' : 'Background worker queue'} />
        <Stat label="Jobs failed" value={d.queues.jobs_failed} />
        <Stat label="Emails queued" value={d.queues.emails_queued} />
        <Stat label="Emails failed" value={d.queues.emails_failed} />
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Panel
          title="Google Calendar & Meet"
          actions={
            g.configured &&
            (g.status === 'disconnected' ? (
              <AButton variant="primary" className="!py-1" loading={connect.isPending} onClick={() => connect.mutate()}>
                Connect
              </AButton>
            ) : (
              <>
                {g.status === 'needs_reauth' && (
                  <AButton variant="primary" className="!py-1" loading={connect.isPending} onClick={() => connect.mutate()}>
                    Reconnect
                  </AButton>
                )}
                <AButton variant="ghost" className="!py-1" loading={disconnect.isPending} onClick={onDisconnect}>
                  Disconnect
                </AButton>
              </>
            ))
          }
        >
          {!g.configured ? (
            <Notice tone="warn">Google OAuth credentials (GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI) are not set for this environment. Bookings are confirmed normally but no calendar events or Meet links are created.</Notice>
          ) : (
            <KeyValues
              items={[
                ['Status', <Badge key="s" tone={g.status === 'connected' ? 'green' : g.status === 'needs_reauth' ? 'red' : 'muted'}>{g.status === 'needs_reauth' ? 'Needs reconnecting' : humanizeSlug(g.status)}</Badge>],
                ['Account', g.account_email],
                ['Calendar', g.calendar_id],
                ['Last sync', formatAdminDateTime(g.last_synced_at)],
                ...(g.last_error ? ([['Last error', <span key="e" className="text-danger-300">{g.last_error}</span>]] as [string, ReactNode][]) : []),
                ['Redirect URI', g.redirect_uri ? <Copyable key="r" value={g.redirect_uri} /> : '—'],
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
