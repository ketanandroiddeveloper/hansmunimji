import { useMutation } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { ApiError } from '../../lib/api'
import { formatAdminDateTime } from '../lib/datetime'
import { adminApi, errorMessage } from '../lib/adminApi'
import { needsTwoFactorSetup, useSession } from '../lib/session'
import { Input } from '../ui/Fields'
import { AButton, Badge, KeyValues, Notice, PageHeader, Panel, Spinner } from '../ui/Primitives'
import { useToast } from '../ui/Toast'
import { AuthShell } from './AuthShell'
import { PASSWORD_HINT } from './PasswordPages'

function TwoFactorEnrolment({ onDone }: { onDone: () => void }) {
  const [code, setCode] = useState('')
  const [qr, setQr] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const setup = useMutation({ mutationFn: () => adminApi.post<{ secret: string; uri: string }>('/auth/two-factor/setup') })
  const enable = useMutation({
    mutationFn: () => adminApi.post('/auth/two-factor/enable', { code: code.replace(/\s/g, '') }),
    onSuccess: onDone,
    onError: (e) => setError(errorMessage(e)),
  })

  const started = useRef(false)
  const { mutate: begin } = setup
  useEffect(() => {
    // Each setup call issues a new secret; a second call would invalidate the QR code on screen.
    if (started.current) return
    started.current = true
    begin()
  }, [begin])

  useEffect(() => {
    if (setup.data) QRCode.toDataURL(setup.data.uri, { margin: 1, width: 220, color: { dark: '#070b14', light: '#f7f3ea' } }).then(setQr, () => setQr(null))
  }, [setup.data])

  if (setup.isError) {
    const enabled = setup.error instanceof ApiError && setup.error.code === 'two_factor_enabled'
    return <Notice tone={enabled ? 'info' : 'error'}>{enabled ? 'Two-factor authentication is already enabled for your account.' : errorMessage(setup.error)}</Notice>
  }
  if (!setup.data) return <Spinner label="Preparing" />
  const secret = setup.data.secret

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    if (!/^\d{6}$/.test(code.replace(/\s/g, ''))) return setError('Enter the six-digit code shown in your app.')
    enable.mutate()
  }

  return (
    <div className="space-y-6">
      <ol className="list-decimal space-y-2 pl-5 text-sm font-light text-ivory-200">
        <li>Open an authenticator app (such as 1Password, Google Authenticator or Microsoft Authenticator).</li>
        <li>Scan this code, or enter the key manually.</li>
        <li>Enter the six-digit code the app shows.</li>
      </ol>
      <div className="flex flex-col items-center gap-4 border border-[var(--line)] bg-midnight-900/60 p-5">
        {qr ? <img src={qr} alt="QR code for your authenticator app" width={220} height={220} /> : <span className="h-[220px]" />}
        <div className="text-center">
          <p className="text-[0.62rem] uppercase tracking-[0.2em] text-slate-400">Setup key</p>
          <code className="mt-1 block break-all font-mono text-sm tracking-[0.15em] text-champagne-200">{secret.replace(/(.{4})/g, '$1 ').trim()}</code>
        </div>
      </div>
      <form onSubmit={submit} className="space-y-4" noValidate>
        <Input label="Code from your app" required inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} />
        {error && <Notice tone="error">{error}</Notice>}
        <AButton type="submit" variant="primary" loading={enable.isPending} className="w-full !py-3">
          Turn on two-factor authentication
        </AButton>
      </form>
    </div>
  )
}

/** Full-screen enrolment shown before any admin screen when 2FA is mandatory and not yet set up. */
export function TwoFactorSetupPage() {
  const { user, refresh, signOut } = useSession()
  const navigate = useNavigate()
  if (!user) return <Navigate to="/admin/login" replace />
  if (!needsTwoFactorSetup(user)) return <Navigate to="/admin" replace />
  return (
    <AuthShell title="Secure your account" description="Two-factor authentication is required for every administrator before the private office can be opened.">
      <TwoFactorEnrolment
        onDone={async () => {
          await refresh()
          navigate('/admin', { replace: true })
        }}
      />
      <p className="mt-8 text-center text-xs text-slate-400">
        <button type="button" onClick={() => signOut()} className="underline-offset-4 hover:text-champagne-200 hover:underline">
          Sign out
        </button>
      </p>
    </AuthShell>
  )
}

export function AccountPage() {
  const { user, refresh } = useSession()
  const notify = useToast()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [pwError, setPwError] = useState<string | null>(null)
  const [enrolling, setEnrolling] = useState(false)
  const [disablePw, setDisablePw] = useState('')

  const change = useMutation({
    mutationFn: () => adminApi.post('/auth/change-password', { current_password: current, password: next }),
    onSuccess: () => {
      setCurrent('')
      setNext('')
      setConfirm('')
      notify('Password changed. Other sessions have been signed out.')
    },
    onError: (e) => setPwError(errorMessage(e)),
  })
  const disable = useMutation({
    mutationFn: () => adminApi.post('/auth/two-factor/disable', { password: disablePw }),
    onSuccess: async () => {
      setDisablePw('')
      await refresh()
      notify('Two-factor authentication turned off.')
    },
    onError: (e) => notify(errorMessage(e), 'error'),
  })

  if (!user) return null

  return (
    <>
      <PageHeader title="Your account" description="Sign-in details and security for your administrator account." />
      <div className="grid gap-6 xl:grid-cols-2">
        <Panel title="Profile">
          <KeyValues
            items={[
              ['Name', user.name],
              ['Email', user.email],
              ['Roles', user.roles.map((r) => r.replace(/-/g, ' ')).join(', ')],
              ['Last sign-in', formatAdminDateTime(user.last_login_at)],
            ]}
          />
        </Panel>
        <Panel title="Two-factor authentication" actions={user.two_factor_enabled ? <Badge tone="green">On</Badge> : <Badge tone="red">Off</Badge>}>
          {user.two_factor_enabled ? (
            user.two_factor_required ? (
              <p className="text-sm font-light text-ivory-200">Required for all administrators. If you lose your device, ask a Super Admin to reset it; you will enrol again at your next sign-in.</p>
            ) : (
              <form
                className="space-y-4"
                onSubmit={(e) => {
                  e.preventDefault()
                  disable.mutate()
                }}
              >
                <p className="text-sm font-light text-ivory-200">Turning this off makes your account easier to compromise.</p>
                <Input label="Confirm with your password" type="password" autoComplete="current-password" value={disablePw} onChange={(e) => setDisablePw(e.target.value)} />
                <AButton type="submit" variant="danger" loading={disable.isPending} disabled={!disablePw}>
                  Turn off
                </AButton>
              </form>
            )
          ) : enrolling ? (
            <TwoFactorEnrolment
              onDone={async () => {
                setEnrolling(false)
                await refresh()
                notify('Two-factor authentication is on.')
              }}
            />
          ) : (
            <div className="space-y-4">
              <p className="text-sm font-light text-ivory-200">Protect your account with a code from an authenticator app at each sign-in.</p>
              <AButton variant="primary" onClick={() => setEnrolling(true)}>
                Set up
              </AButton>
            </div>
          )}
        </Panel>
        <Panel title="Change password">
          <form
            className="space-y-4"
            noValidate
            onSubmit={(e) => {
              e.preventDefault()
              setPwError(null)
              if (next.length < 12) return setPwError('Use at least 12 characters.')
              if (next !== confirm) return setPwError('The new passwords do not match.')
              change.mutate()
            }}
          >
            <Input label="Current password" type="password" autoComplete="current-password" required value={current} onChange={(e) => setCurrent(e.target.value)} />
            <Input label="New password" type="password" autoComplete="new-password" required hint={PASSWORD_HINT} value={next} onChange={(e) => setNext(e.target.value)} />
            <Input label="Confirm new password" type="password" autoComplete="new-password" required value={confirm} onChange={(e) => setConfirm(e.target.value)} />
            {pwError && <Notice tone="error">{pwError}</Notice>}
            <AButton type="submit" variant="primary" loading={change.isPending}>
              Change password
            </AButton>
          </form>
        </Panel>
      </div>
      <p className="mt-8 text-xs text-slate-400">Need a different role or access? Ask a Super Admin to update your account.</p>
    </>
  )
}
