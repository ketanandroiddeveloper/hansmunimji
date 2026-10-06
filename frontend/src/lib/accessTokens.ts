/**
 * Client access tokens arrive in the URL fragment (never sent to servers or logged by proxies).
 * They are moved into sessionStorage immediately and the fragment is stripped from the address
 * bar and history, so they do not linger in screenshots, bookmarks or analytics.
 */
export type TokenScope = 'application' | 'appointment' | 'registration' | 'invite'

const key = (scope: TokenScope, reference: string) => `pa_token:${scope}:${reference.toUpperCase()}`

export function readFragmentParam(name: 'access' | 'invite'): string | null {
  const hash = window.location.hash.replace(/^#/, '')
  if (!hash) return null
  const value = new URLSearchParams(hash).get(name)
  return value && /^[A-Za-z0-9_-]{16,128}$/.test(value) ? value : null
}

export function stripFragment(): void {
  if (window.location.hash) {
    window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search)
  }
}

export function storeToken(scope: TokenScope, reference: string, token: string): void {
  try {
    sessionStorage.setItem(key(scope, reference), token)
  } catch {
    /* private mode: token lives only in memory for this page */
  }
}

export function getToken(scope: TokenScope, reference: string): string | null {
  try {
    return sessionStorage.getItem(key(scope, reference))
  } catch {
    return null
  }
}

export function clearToken(scope: TokenScope, reference: string): void {
  try {
    sessionStorage.removeItem(key(scope, reference))
  } catch {
    /* ignore */
  }
}

/** Captures `#access=` for a reference-scoped resource and returns the usable token. */
export function captureAccessToken(scope: Exclude<TokenScope, 'invite'>, reference: string): string | null {
  const fromFragment = readFragmentParam('access')
  if (fromFragment) {
    storeToken(scope, reference, fromFragment)
    stripFragment()
    return fromFragment
  }
  return getToken(scope, reference)
}

/** Client credentials held in this browser session (used to unlock client-only recordings). */
export function storedClientAccess(): { reference: string; token: string }[] {
  const out: { reference: string; token: string }[] = []
  try {
    for (let i = 0; i < sessionStorage.length; i++) {
      const k = sessionStorage.key(i)
      const m = k?.match(/^pa_token:(appointment|application):(.+)$/)
      const token = k ? sessionStorage.getItem(k) : null
      if (m && token) out.push({ reference: m[2], token })
    }
  } catch {
    /* storage unavailable */
  }
  return out
}

const INVITE_KEY = 'pa_token:invite'

export function captureInviteToken(): string | null {
  const fromFragment = readFragmentParam('invite')
  if (fromFragment) {
    try {
      sessionStorage.setItem(INVITE_KEY, fromFragment)
    } catch {
      /* ignore */
    }
    stripFragment()
    return fromFragment
  }
  try {
    return sessionStorage.getItem(INVITE_KEY)
  } catch {
    return null
  }
}
