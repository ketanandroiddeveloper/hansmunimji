import { useSettings } from '../../lib/queries'

const STYLE: Record<string, string> = {
  production: 'border-danger-300/60 text-danger-300',
  staging: 'border-champagne-400/70 text-champagne-200',
  local: 'border-emerald-300/50 text-emerald-300',
  testing: 'border-emerald-300/50 text-emerald-300',
}

/** Always visible in the admin, including production, so nobody mistakes which data they are editing. */
export function EnvironmentBadge() {
  const { data } = useSettings()
  const env = data?.environment
  if (!env) return null
  return (
    <span role="status" aria-label={`Environment: ${env}`} className={`border px-2 py-0.5 text-[0.6rem] font-bold uppercase tracking-[0.22em] ${STYLE[env] ?? STYLE.local}`}>
      {env}
    </span>
  )
}
