import { useEffect } from 'react'
import { useSettings } from '../../lib/queries'

const BUILD_ENV = (import.meta.env.VITE_APP_ENV as string | undefined) ?? (import.meta.env.PROD ? 'production' : 'local')

const LABELS: Record<string, string> = {
  local: 'Local development',
  testing: 'Testing environment',
  staging: 'Staging environment · test payments only',
}

/**
 * Explicit, non-dismissable indicator outside production. The API's reported environment wins
 * so a staging build pointed at the wrong backend is still flagged.
 */
export function EnvironmentRibbon() {
  const { data } = useSettings()
  const env = data?.environment ?? BUILD_ENV
  const mismatch = data?.environment !== undefined && BUILD_ENV !== data.environment && BUILD_ENV === 'production'
  const label = mismatch ? `Build/API mismatch: build=${BUILD_ENV}, api=${data?.environment}` : LABELS[env]
  const visible = env !== 'production' || mismatch

  useEffect(() => {
    document.documentElement.style.setProperty('--ribbon-h', visible ? '1.75rem' : '0px')
    document.documentElement.dataset.env = env
  }, [visible, env])

  if (!visible || !label) return null
  return (
    <div role="status" className="fixed inset-x-0 top-0 z-[60] flex h-7 items-center justify-center gap-3 bg-emerald-700 text-[0.62rem] font-semibold uppercase tracking-[0.28em] text-ivory-50">
      <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-champagne-200" />
      {label}
    </div>
  )
}
