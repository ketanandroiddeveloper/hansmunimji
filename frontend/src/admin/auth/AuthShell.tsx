import type { ReactNode } from 'react'
import { LotusMark } from '../../components/ui/Ornaments'
import { EnvironmentBadge } from '../layout/EnvironmentBadge'

export function AuthShell({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
  return (
    <div className="grain flex min-h-dvh flex-col items-center justify-center bg-midnight-950 px-6 py-16">
      <title>{`${title} — Private office`}</title>
      <meta name="robots" content="noindex,nofollow" />
      <div className="w-full max-w-sm">
        <div className="mb-10 flex items-center justify-between">
          <span className="flex items-center gap-3 font-display text-xl text-ivory-50">
            <LotusMark className="h-5 w-5 text-champagne-400" />
            Private office
          </span>
          <EnvironmentBadge />
        </div>
        <h1 className="font-display text-4xl leading-tight text-ivory-50">{title}</h1>
        {description && <p className="mt-3 text-sm font-light leading-relaxed text-slate-400">{description}</p>}
        <div className="mt-10">{children}</div>
      </div>
    </div>
  )
}
