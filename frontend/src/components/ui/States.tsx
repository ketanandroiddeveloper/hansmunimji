import type { ReactNode } from 'react'
import { ApiError } from '../../lib/api'
import { Button } from './Button'

export function LoadingBlock({ label = 'Loading', className = '' }: { label?: string; className?: string }) {
  return (
    <div role="status" aria-live="polite" className={`flex min-h-[40vh] items-center justify-center ${className}`}>
      <span className="flex items-center gap-4 text-[0.68rem] uppercase tracking-[0.3em] text-slate-400">
        <span aria-hidden="true" className="h-px w-10 animate-pulse bg-champagne-400/70" />
        {label}
      </span>
    </div>
  )
}

export function ErrorBlock({ error, onRetry, className = '' }: { error: unknown; onRetry?: () => void; className?: string }) {
  const message = error instanceof ApiError ? error.message : 'Something went wrong while loading this page.'
  const requestId = error instanceof ApiError ? error.requestId : null
  return (
    <div role="alert" className={`flex min-h-[40vh] flex-col items-center justify-center gap-6 text-center ${className}`}>
      <p className="max-w-md font-display text-h3 text-ivory-50">{message}</p>
      {requestId && <p className="text-xs text-slate-400">Reference: {requestId}</p>}
      {onRetry && (
        <Button variant="outline" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  )
}

export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="border-y border-[var(--line)] py-14 text-center">
      <p className="font-display text-h3 text-ivory-50">{title}</p>
      {children && <div className="mx-auto mt-4 max-w-md text-sm font-light text-slate-400">{children}</div>}
    </div>
  )
}

export function isNotFound(error: unknown): boolean {
  return error instanceof ApiError && error.status === 404
}
