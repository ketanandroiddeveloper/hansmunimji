import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { adminApi, errorMessage } from '../lib/adminApi'
import { Input } from '../ui/Fields'
import { ALink, AButton, Notice } from '../ui/Primitives'
import { AuthShell } from './AuthShell'

export const PASSWORD_HINT = 'At least 12 characters. A memorable passphrase of several words works well.'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      await adminApi.post('/auth/forgot-password', { email })
      setSent(true)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell title="Reset password" description="We will email a reset link if an account exists for this address.">
      {sent ? (
        <Notice tone="success">If an account exists for that address, a reset link is on its way. It expires shortly, so please use it soon.</Notice>
      ) : (
        <form onSubmit={submit} className="space-y-5" noValidate>
          <Input label="Email" type="email" required autoFocus autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} />
          {error && <Notice tone="error">{error}</Notice>}
          <AButton type="submit" variant="primary" loading={busy} className="w-full !py-3">
            Send reset link
          </AButton>
        </form>
      )}
      <p className="mt-8 text-center text-xs text-slate-400">
        <Link to="/admin/login" className="underline-offset-4 hover:text-champagne-200 hover:underline">
          Back to sign in
        </Link>
      </p>
    </AuthShell>
  )
}

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const welcome = params.get('welcome') === '1'
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [done, setDone] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    if (password.length < 12) return setError('Use at least 12 characters.')
    if (password !== confirm) return setError('The two passwords do not match.')
    setBusy(true)
    try {
      await adminApi.post('/auth/reset-password', { token, password })
      setDone(true)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell title={welcome ? 'Welcome' : 'Choose a new password'} description={welcome ? 'Set a password to activate your administrator account.' : undefined}>
      {!token ? (
        <Notice tone="error">This link is incomplete. Please use the full link from your email, or request a new one.</Notice>
      ) : done ? (
        <div className="space-y-6">
          <Notice tone="success">Your password has been set. You can now sign in{welcome ? ' and set up two-factor authentication' : ''}.</Notice>
          <ALink to="/admin/login" variant="primary">
            Sign in
          </ALink>
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-5" noValidate>
          <Input label="New password" type="password" required autoFocus autoComplete="new-password" hint={PASSWORD_HINT} value={password} onChange={(e) => setPassword(e.target.value)} />
          <Input label="Confirm password" type="password" required autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} />
          {error && <Notice tone="error">{error}</Notice>}
          <AButton type="submit" variant="primary" loading={busy} className="w-full !py-3">
            Set password
          </AButton>
        </form>
      )}
    </AuthShell>
  )
}
