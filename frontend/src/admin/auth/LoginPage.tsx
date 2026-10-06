import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router'
import { ApiError } from '../../lib/api'
import { adminApi, errorMessage } from '../lib/adminApi'
import type { AdminUser } from '../lib/adminApi'
import { useSession } from '../lib/session'
import { Input } from '../ui/Fields'
import { AButton, Notice } from '../ui/Primitives'
import { AuthShell } from './AuthShell'

type LoginResponse = { two_factor_required: true; challenge: string } | { two_factor_required: false; user: AdminUser; csrf_token: string }

export default function LoginPage() {
  const { user, signedIn } = useSession()
  const navigate = useNavigate()
  const location = useLocation()
  const [params] = useSearchParams()
  const from = (location.state as { from?: string } | null)?.from ?? '/admin'
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [challenge, setChallenge] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  if (user) return <Navigate to={from} replace />

  const finish = (res: LoginResponse) => {
    if (res.two_factor_required) {
      setChallenge(res.challenge)
      setPassword('')
      return
    }
    signedIn(res.user, res.csrf_token)
    navigate(from.startsWith('/admin') ? from : '/admin', { replace: true })
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      finish(challenge ? await adminApi.post<LoginResponse>('/auth/two-factor', { challenge, code: code.replace(/\s/g, '') }) : await adminApi.post<LoginResponse>('/auth/login', { email, password }))
    } catch (err) {
      if (challenge && err instanceof ApiError && err.status === 401) {
        setChallenge(null)
        setCode('')
        setError('The verification step expired. Please sign in again.')
      } else {
        setError(err instanceof ApiError && err.status === 429 ? 'Too many attempts. Please wait a few minutes and try again.' : errorMessage(err))
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell title={challenge ? 'Verification' : 'Sign in'} description={challenge ? 'Enter the six-digit code from your authenticator app.' : 'For authorised members of the private office only.'}>
      {params.get('expired') && !challenge && (
        <div className="mb-6">
          <Notice tone="info">Your session ended. Please sign in again.</Notice>
        </div>
      )}
      <form onSubmit={submit} className="space-y-5" noValidate>
        {challenge ? (
          <Input
            label="Authentication code"
            required
            autoFocus
            inputMode="numeric"
            autoComplete="one-time-code"
            pattern="[0-9 ]*"
            maxLength={7}
            value={code}
            onChange={(e) => setCode(e.target.value)}
          />
        ) : (
          <>
            <Input label="Email" type="email" required autoFocus autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} />
            <Input label="Password" type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
          </>
        )}
        {error && <Notice tone="error">{error}</Notice>}
        <AButton type="submit" variant="primary" loading={busy} className="w-full !py-3">
          {challenge ? 'Verify' : 'Sign in'}
        </AButton>
      </form>
      <p className="mt-8 text-center text-xs text-slate-400">
        {challenge ? (
          <button
            type="button"
            className="underline-offset-4 hover:text-champagne-200 hover:underline"
            onClick={() => {
              setChallenge(null)
              setCode('')
            }}
          >
            Use a different account
          </button>
        ) : (
          <Link to="/admin/forgot-password" className="underline-offset-4 hover:text-champagne-200 hover:underline">
            Forgotten your password?
          </Link>
        )}
      </p>
    </AuthShell>
  )
}
