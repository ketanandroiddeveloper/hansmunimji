import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { useSearchParams } from 'react-router'
import { adminApi, errorMessage } from '../lib/adminApi'
import { formatAdminDateTime } from '../lib/datetime'
import { INTEGRATIONS_KEY } from '../lib/integrations'
import type { GoogleServiceStatus, Integrations } from '../lib/integrations'
import { Copyable } from '../ui/Copyable'
import { useConfirm } from '../ui/Dialog'
import { AButton, Badge, DataTable, ErrorNote, KeyValues, Notice, PageHeader, Panel, Spinner, TableMessage, Td, Th } from '../ui/Primitives'
import { useToast } from '../ui/Toast'

const RESULT: Record<string, { tone: 'success' | 'warn' | 'error'; text: string }> = {
  connected: { tone: 'success', text: 'Google connected. Upcoming bookings are being added to the calendar. Run the tests below to confirm Gmail, Calendar and Meet.' },
  partial: { tone: 'warn', text: 'Google connected, but not every permission was allowed. Reconnect and leave all requested access ticked.' },
  invalid_state: { tone: 'error', text: 'The Google sign-in link expired or was invalid. Please try connecting again.' },
  unauthorized: { tone: 'error', text: 'Your session or permissions changed during sign-in. Please sign in and try again.' },
  denied: { tone: 'error', text: 'Access was not granted. If the Google app is in Testing, make sure this Google account is listed as a test user on the OAuth consent screen.' },
  invalid_client: { tone: 'error', text: 'Google rejected the OAuth client. Check GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET for this environment.' },
  redirect_uri_mismatch: { tone: 'error', text: 'The redirect URI is not registered on the OAuth client. Add the redirect URI shown below in Google Cloud Console.' },
  invalid_code: { tone: 'error', text: 'The Google sign-in code expired or was already used. Please connect again.' },
  no_refresh_token: { tone: 'error', text: 'Google did not grant offline access. Remove the app under your Google account’s third-party access, then connect again.' },
  wrong_account: { tone: 'error', text: 'A different Google account was chosen than the one configured for this environment. Connect with the expected account.' },
  network: { tone: 'error', text: 'Google could not be reached from the server. Please try again.' },
  failed: { tone: 'error', text: 'Google could not complete the connection. Check the OAuth configuration and try again.' },
}

const SERVICE: Record<GoogleServiceStatus, { label: string; tone: 'green' | 'red' | 'muted' }> = {
  connected: { label: 'Connected', tone: 'green' },
  missing_permission: { label: 'Permission not granted', tone: 'red' },
  error: { label: 'Error', tone: 'red' },
  reauth_required: { label: 'Reauthorization required', tone: 'red' },
  disconnected: { label: 'Disconnected', tone: 'muted' },
  not_used: { label: 'Not used', tone: 'muted' },
}

const OPERATION: Record<string, string> = {
  'oauth.connect': 'Connect',
  'oauth.consent': 'Consent screen',
  'oauth.refresh': 'Token refresh',
  'oauth.revoke': 'Revoke access',
  'gmail.send': 'Gmail send',
  'calendar.create': 'Create event',
  'calendar.get': 'Read event',
  'calendar.update': 'Update event',
  'calendar.cancel': 'Cancel event',
  'calendar.meet': 'Meet conference',
  'calendar.meet_retry': 'Meet retry',
  'test.calendar': 'Calendar test',
  'test.meet': 'Meet test',
  'test.cleanup': 'Delete test event',
  'test.cleanup_list': 'Find test events',
}

const CATEGORY: Record<string, string> = {
  not_configured: 'Not configured',
  not_connected: 'Not connected',
  invalid_client: 'Invalid client',
  redirect_uri_mismatch: 'Redirect URI mismatch',
  invalid_code: 'Invalid code',
  no_refresh_token: 'No offline access',
  wrong_account: 'Wrong account',
  access_denied: 'Access denied',
  revoked: 'Access revoked',
  insufficient_scope: 'Missing permission',
  api_disabled: 'API not enabled',
  calendar_not_found: 'Calendar not found',
  quota: 'Quota / rate limit',
  meet_failed: 'Meet not created',
  unauthorized: 'Unauthorized',
  invalid_request: 'Invalid request',
  server_error: 'Google unavailable',
  network: 'Network',
  invalid_recipient: 'Invalid recipient',
  unknown: 'Unknown',
}

const SCOPE_LABEL: Record<string, string> = {
  'https://www.googleapis.com/auth/calendar.events': 'Google Calendar events (also used for Meet links)',
  'https://www.googleapis.com/auth/gmail.send': 'Send email with Gmail',
}

type TestName = 'gmail' | 'calendar' | 'meet' | 'cleanup'

export default function GoogleIntegrationPage() {
  const notify = useToast()
  const confirm = useConfirm()
  const client = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [testResult, setTestResult] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const result = RESULT[params.get('google') ?? '']
  const data = useQuery({ queryKey: INTEGRATIONS_KEY, queryFn: () => adminApi.get<Integrations>('/admin/integrations') })

  useEffect(() => {
    if (params.get('google') === 'connected' || params.get('google') === 'partial') client.invalidateQueries({ queryKey: ['admin', 'dashboard'] })
  }, [params, client])

  const refresh = () => client.invalidateQueries({ queryKey: INTEGRATIONS_KEY })
  const connect = useMutation({
    mutationFn: () => adminApi.post<{ authorization_url: string }>('/admin/integrations/google/connect'),
    onSuccess: (r) => window.location.assign(r.authorization_url),
    onError: (e) => notify(errorMessage(e), 'error'),
  })
  const disconnect = useMutation({
    mutationFn: () => adminApi.post('/admin/integrations/google/disconnect'),
    onSuccess: () => {
      refresh()
      notify('Google disconnected.')
    },
    onError: (e) => notify(errorMessage(e), 'error'),
  })
  const test = useMutation({
    mutationFn: (name: TestName) => adminApi.post<Record<string, unknown>>(`/admin/integrations/google/test/${name}`),
    onSuccess: (r, name) => {
      refresh()
      setTestResult({ tone: 'success', text: describeTest(name, r) })
    },
    onError: (e) => {
      refresh()
      setTestResult({ tone: 'error', text: errorMessage(e) })
    },
  })

  if (data.isLoading) return <Spinner />
  if (data.isError) return <ErrorNote error={data.error} onRetry={() => data.refetch()} />
  const d = data.data!
  const g = d.google
  const connected = g.status === 'connected'
  const wrongAccount = connected && g.expected_account && g.account_email && g.expected_account.toLowerCase() !== g.account_email.toLowerCase()

  const onDisconnect = async () => {
    const ok = await confirm({
      title: 'Disconnect Google?',
      body: 'Google access is revoked and the stored tokens are deleted. New bookings will not be added to the calendar, no Meet links will be created and Gmail sending stops until Google is reconnected. Existing calendar events are left in place.',
      confirmLabel: 'Disconnect',
      danger: true,
    })
    if (ok) disconnect.mutate()
  }

  const testButton = (name: TestName, label: string, enabled: boolean) => (
    <AButton variant="secondary" className="!py-1" disabled={!enabled || test.isPending} loading={test.isPending && test.variables === name} onClick={() => test.mutate(name)}>
      {label}
    </AButton>
  )

  return (
    <>
      <PageHeader
        title="Google Workspace"
        back={{ to: '/admin/integrations', label: 'Integrations' }}
        description={
          <>
            Gmail, Google Calendar and Google Meet share one OAuth connection for <strong className="font-medium text-ivory-50">{d.environment}</strong>. Tokens are encrypted on the server and are never shown here.
          </>
        }
        actions={
          g.configured && (
            <>
              {g.status === 'disconnected' && (
                <AButton variant="primary" loading={connect.isPending} onClick={() => connect.mutate()}>
                  Connect Google
                </AButton>
              )}
              {g.status !== 'disconnected' && (
                <AButton variant={g.status === 'needs_reauth' || g.missing_scopes.length > 0 ? 'primary' : 'secondary'} loading={connect.isPending} onClick={() => connect.mutate()}>
                  Reconnect Google
                </AButton>
              )}
              {g.status !== 'disconnected' && (
                <AButton variant="danger" loading={disconnect.isPending} onClick={onDisconnect}>
                  Disconnect Google
                </AButton>
              )}
            </>
          )
        }
      />

      {result && (
        <div className="mb-6 flex items-start justify-between gap-4">
          <div className="flex-1">
            <Notice tone={result.tone}>{result.text}</Notice>
          </div>
          <AButton variant="ghost" onClick={() => setParams({}, { replace: true })}>
            Dismiss
          </AButton>
        </div>
      )}

      {!g.configured && (
        <div className="mb-6">
          <Notice tone="warn">
            Google OAuth credentials (GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI) are not set in the server environment. Bookings are confirmed normally, but no calendar events, Meet links or Gmail messages are created.
          </Notice>
        </div>
      )}
      {g.status === 'needs_reauth' && (
        <div className="mb-6">
          <Notice tone="error">Google access was revoked or has expired. Calendar events, Meet links and Gmail sending are paused. Reconnect Google to resume; bookings made in the meantime are synced automatically.</Notice>
        </div>
      )}
      {connected && g.missing_scopes.length > 0 && (
        <div className="mb-6">
          <Notice tone="warn">
            Not granted: {g.missing_scopes.map((s) => SCOPE_LABEL[s] ?? s).join('; ')}. Reconnect Google and leave every requested permission ticked.
          </Notice>
        </div>
      )}
      {wrongAccount && (
        <div className="mb-6">
          <Notice tone="error">Connected as {g.account_email}, but this environment expects {g.expected_account}. Disconnect and connect with the expected account.</Notice>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-2">
        <Panel title="Connection">
          <KeyValues
            items={[
              ['OAuth', <Badge key="o" tone={connected ? 'green' : g.status === 'needs_reauth' ? 'red' : 'muted'}>{connected ? 'Connected' : g.status === 'needs_reauth' ? 'Reauthorization required' : 'Not connected'}</Badge>],
              ['Account', g.account_email ?? '—'],
              ['Calendar', g.calendar_id === 'primary' ? 'Primary calendar' : (g.calendar_id ?? '—')],
              ['Last successful sync', formatAdminDateTime(g.last_synced_at)],
              ...(g.last_error ? ([['Last error', <span key="e" className="text-danger-300">{g.last_error}</span>]] as [ReactNode, ReactNode][]) : []),
              ['Redirect URI', g.redirect_uri ? <Copyable key="r" value={g.redirect_uri} /> : '—'],
            ]}
          />
        </Panel>

        <Panel title="Services">
          <KeyValues
            items={[
              ['Gmail', <ServiceRow key="g" status={g.services.gmail.status} action={testButton('gmail', 'Test Gmail', !['disconnected', 'missing_permission', 'not_used'].includes(g.services.gmail.status))} />],
              ['Calendar', <ServiceRow key="c" status={g.services.calendar.status} action={testButton('calendar', 'Test Calendar', g.services.calendar.status !== 'disconnected' && g.services.calendar.status !== 'missing_permission')} />],
              ['Google Meet', <ServiceRow key="m" status={g.services.meet.status} available action={testButton('meet', 'Test Meet', g.services.meet.status !== 'disconnected' && g.services.meet.status !== 'missing_permission')} />],
            ]}
          />
          {connected && !g.services.gmail.mail_driver && (
            <p className="mt-4 text-sm font-light text-slate-400">
              Email notifications use the <code className="font-mono text-xs">{d.email.driver}</code> driver, so Gmail access is not requested. To send them from {g.account_email ?? 'the connected account'} instead, set <code className="font-mono text-xs">MAIL_DRIVER=gmail</code> in the server environment and reconnect Google.
            </p>
          )}
          <p className="mt-4 text-sm font-light text-slate-400">
            Tests send one email to the operations address and create a private, guest-free event tomorrow that is deleted immediately.{' '}
            <button type="button" className="text-champagne-200 underline-offset-4 hover:underline disabled:opacity-50" disabled={!connected || test.isPending} onClick={() => test.mutate('cleanup')}>
              Remove leftover test events
            </button>
          </p>
          {testResult && (
            <div className="mt-4">
              <Notice tone={testResult.tone}>{testResult.text}</Notice>
            </div>
          )}
        </Panel>
      </div>

      <h2 className="mb-3 mt-8 text-[0.68rem] font-semibold uppercase tracking-[0.2em] text-ivory-200">Recent Google activity</h2>
      <DataTable caption="Recent Google activity">
        <thead>
          <tr>
            <Th>When</Th>
            <Th>Operation</Th>
            <Th>Result</Th>
            <Th>HTTP</Th>
            <Th>Reference</Th>
          </tr>
        </thead>
        <tbody>
          {g.logs.length === 0 ? (
            <TableMessage colSpan={5}>No Google activity yet.</TableMessage>
          ) : (
            g.logs.map((log, i) => (
              <tr key={`${log.created_at}-${i}`}>
                <Td className="whitespace-nowrap">{formatAdminDateTime(log.created_at)}</Td>
                <Td>{OPERATION[log.operation] ?? log.operation}</Td>
                <Td>
                  {log.outcome === 'success' ? <Badge tone="green">OK</Badge> : <Badge tone="red">{CATEGORY[log.error_category ?? 'unknown'] ?? log.error_category}</Badge>}
                </Td>
                <Td className="tabular-nums">{log.http_status ?? '—'}</Td>
                <Td className="font-mono text-xs">{log.reference ?? '—'}</Td>
              </tr>
            ))
          )}
        </tbody>
      </DataTable>
    </>
  )
}

function ServiceRow({ status, action, available = false }: { status: GoogleServiceStatus; action: ReactNode; available?: boolean }) {
  const s = SERVICE[status]
  return (
    <span className="flex flex-wrap items-center justify-between gap-3">
      <Badge tone={s.tone}>{available && status === 'connected' ? 'Available' : s.label}</Badge>
      {action}
    </span>
  )
}

function describeTest(name: TestName, r: Record<string, unknown>): string {
  if (name === 'gmail') return `Test email sent from ${String(r.sent_from ?? 'the connected account')} to ${String(r.sent_to)}. Check that inbox.`
  if (name === 'cleanup') return Number(r.removed) === 0 ? 'No leftover test events were found.' : `Removed ${String(r.removed)} leftover test event(s).`
  const cleaned = r.cleaned_up ? 'The test event was deleted.' : 'The test event could not be deleted; use “Remove leftover test events”.'
  return name === 'meet' ? `A Meet conference was created successfully. ${cleaned}` : `A calendar event was created successfully. ${cleaned}`
}
