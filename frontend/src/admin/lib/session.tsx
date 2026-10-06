import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useMemo } from 'react'
import type { ReactNode } from 'react'
import { adminApi, fetchMe, setCsrfToken } from './adminApi'
import type { AdminUser } from './adminApi'

export const ME_KEY = ['admin', 'me'] as const

type SessionValue = {
  user: AdminUser | null
  loading: boolean
  can: (permission: string) => boolean
  signedIn: (user: AdminUser, csrf: string) => void
  refresh: () => Promise<unknown>
  signOut: () => Promise<void>
}

const SessionContext = createContext<SessionValue | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient()
  const me = useQuery({ queryKey: ME_KEY, queryFn: fetchMe, staleTime: Infinity, retry: false })
  const user = me.data ?? null

  const signedIn = useCallback(
    (u: AdminUser, csrf: string) => {
      setCsrfToken(csrf)
      client.setQueryData(ME_KEY, u)
    },
    [client],
  )

  const signOut = useCallback(async () => {
    try {
      await adminApi.post('/auth/logout')
    } finally {
      setCsrfToken(null)
      client.clear()
      client.setQueryData(ME_KEY, null)
    }
  }, [client])

  const value = useMemo<SessionValue>(
    () => ({
      user,
      loading: me.isLoading,
      can: (permission) => Boolean(user?.permissions.includes(permission)),
      signedIn,
      refresh: () => me.refetch(),
      signOut,
    }),
    [user, me, signedIn, signOut],
  )

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext)
  if (!value) throw new Error('useSession must be used inside SessionProvider')
  return value
}

let expired = false

/** Set when an API call reports the session ended, so the sign-in screen can explain why. */
export function markSessionExpired(): void {
  expired = true
}

export function consumeSessionExpired(): boolean {
  const was = expired
  expired = false
  return was
}

export function needsTwoFactorSetup(user: AdminUser | null): boolean {
  return Boolean(user && user.two_factor_required && !user.two_factor_enabled)
}
