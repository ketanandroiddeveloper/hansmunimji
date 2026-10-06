import type { ButtonHTMLAttributes, ReactNode, ThHTMLAttributes } from 'react'
import { Link } from 'react-router'
import { ApiError } from '../../lib/api'

type Tone = 'neutral' | 'gold' | 'green' | 'red' | 'blue' | 'muted'

const TONES: Record<Tone, string> = {
  neutral: 'border-ivory-50/20 text-ivory-200',
  gold: 'border-champagne-400/50 text-champagne-200',
  green: 'border-emerald-300/40 text-emerald-300',
  red: 'border-danger-300/50 text-danger-300',
  blue: 'border-[#9db4e8]/40 text-[#b9cbf0]',
  muted: 'border-ivory-50/10 text-slate-400',
}

export function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  return <span className={`inline-flex items-center whitespace-nowrap border px-2 py-0.5 text-[0.62rem] font-semibold uppercase tracking-[0.14em] ${TONES[tone]}`}>{children}</span>
}

export type { Tone }

type BtnVariant = 'primary' | 'secondary' | 'danger' | 'ghost'
const BTN: Record<BtnVariant, string> = {
  primary: 'bg-champagne-400 text-midnight-950 hover:bg-champagne-200 border border-champagne-400',
  secondary: 'border border-ivory-50/20 text-ivory-50 hover:border-champagne-400 hover:text-champagne-200',
  danger: 'border border-danger-300/50 text-danger-300 hover:bg-danger-300/10',
  ghost: 'text-ivory-200 hover:text-champagne-200',
}
const BTN_BASE =
  'inline-flex items-center justify-center gap-2 px-3.5 py-2 text-[0.7rem] font-semibold uppercase tracking-[0.16em] transition-colors disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline focus-visible:outline-1 focus-visible:outline-offset-2 focus-visible:outline-champagne-400'

export function AButton({
  variant = 'secondary',
  loading,
  className = '',
  children,
  type = 'button',
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: BtnVariant; loading?: boolean }) {
  return (
    <button type={type} className={`${BTN_BASE} ${BTN[variant]} ${className}`} disabled={rest.disabled || loading} aria-busy={loading || undefined} {...rest}>
      {loading && <span aria-hidden="true" className="h-3 w-3 animate-spin rounded-full border border-current border-t-transparent" />}
      {children}
    </button>
  )
}

export function ALink({ to, variant = 'secondary', className = '', children }: { to: string; variant?: BtnVariant; className?: string; children: ReactNode }) {
  return (
    <Link to={to} className={`${BTN_BASE} ${BTN[variant]} ${className}`}>
      {children}
    </Link>
  )
}

export function PageHeader({ title, description, actions, back }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; back?: { to: string; label: string } }) {
  return (
    <header className="mb-8 flex flex-wrap items-end justify-between gap-4 border-b border-[var(--line)] pb-6">
      <div className="min-w-0">
        {back && (
          <Link to={back.to} relative="path" className="mb-3 inline-flex items-center gap-2 text-[0.65rem] uppercase tracking-[0.2em] text-slate-400 hover:text-champagne-200">
            <span aria-hidden="true">←</span> {back.label}
          </Link>
        )}
        <h1 className="font-display text-3xl leading-tight text-ivory-50">{title}</h1>
        {description && <p className="mt-2 max-w-2xl text-sm font-light text-slate-400">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </header>
  )
}

export function Panel({ title, actions, children, className = '', padded = true }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; padded?: boolean }) {
  return (
    <section className={`border border-[var(--line)] bg-midnight-900/60 ${className}`}>
      {(title || actions) && (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--line)] px-5 py-3">
          {title && <h2 className="text-[0.68rem] font-semibold uppercase tracking-[0.2em] text-ivory-200">{title}</h2>}
          {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
      )}
      <div className={padded ? 'p-5' : ''}>{children}</div>
    </section>
  )
}

export function Stat({ label, value, hint, to }: { label: string; value: ReactNode; hint?: ReactNode; to?: string }) {
  const body = (
    <>
      <p className="text-[0.62rem] font-semibold uppercase tracking-[0.2em] text-slate-400">{label}</p>
      <p className="mt-3 font-display text-4xl leading-none text-ivory-50 tabular-nums">{value}</p>
      {hint && <p className="mt-2 text-xs text-slate-400">{hint}</p>}
    </>
  )
  const cls = 'block border border-[var(--line)] bg-midnight-900/60 p-5'
  return to ? (
    <Link to={to} className={`${cls} transition-colors hover:border-champagne-400/50`}>
      {body}
    </Link>
  ) : (
    <div className={cls}>{body}</div>
  )
}

export function DataTable({ children, caption }: { children: ReactNode; caption?: string }) {
  return (
    <div className="overflow-x-auto border border-[var(--line)]">
      <table className="w-full min-w-[40rem] border-collapse text-left text-sm">
        {caption && <caption className="sr-only">{caption}</caption>}
        {children}
      </table>
    </div>
  )
}

export function Th({ children, className = '', ...rest }: ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th scope="col" className={`border-b border-[var(--line)] bg-midnight-900 px-4 py-3 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400 ${className}`} {...rest}>
      {children}
    </th>
  )
}

export function Td({ children, className = '' }: { children?: ReactNode; className?: string }) {
  return <td className={`border-b border-[var(--line-faint)] px-4 py-3 align-top font-light text-ivory-200 ${className}`}>{children}</td>
}

export function TableMessage({ colSpan, children }: { colSpan: number; children: ReactNode }) {
  return (
    <tr>
      <td colSpan={colSpan} className="px-4 py-12 text-center text-sm font-light text-slate-400">
        {children}
      </td>
    </tr>
  )
}

export function Pagination({ page, totalPages, total, onPage }: { page: number; totalPages: number; total: number; onPage: (page: number) => void }) {
  if (totalPages <= 1) return <p className="mt-4 text-xs text-slate-400">{total} {total === 1 ? 'item' : 'items'}</p>
  return (
    <nav aria-label="Pagination" className="mt-4 flex items-center justify-between gap-4 text-xs text-slate-400">
      <span>
        Page {page} of {totalPages} · {total} items
      </span>
      <span className="flex gap-2">
        <AButton variant="secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Previous
        </AButton>
        <AButton variant="secondary" disabled={page >= totalPages} onClick={() => onPage(page + 1)}>
          Next
        </AButton>
      </span>
    </nav>
  )
}

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return (
    <div role="status" className="flex items-center gap-3 py-16 text-[0.65rem] uppercase tracking-[0.25em] text-slate-400">
      <span aria-hidden="true" className="h-3 w-3 animate-spin rounded-full border border-champagne-400 border-t-transparent" />
      {label}
    </div>
  )
}

export function ErrorNote({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = error instanceof ApiError ? error.message : 'Something went wrong while loading.'
  const ref = error instanceof ApiError ? error.requestId : null
  return (
    <div role="alert" className="border-l-2 border-danger-300/60 bg-midnight-800/60 px-5 py-4 text-sm text-danger-300">
      <p>{message}</p>
      {ref && <p className="mt-1 text-xs text-slate-400">Reference: {ref}</p>}
      {onRetry && (
        <AButton variant="ghost" className="mt-2 !px-0" onClick={onRetry}>
          Try again
        </AButton>
      )}
    </div>
  )
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'warn' | 'success' | 'error'; children: ReactNode }) {
  const cls = {
    info: 'border-[var(--line-strong)] text-ivory-200',
    warn: 'border-champagne-400/60 text-champagne-200',
    success: 'border-emerald-300/50 text-emerald-300',
    error: 'border-danger-300/60 text-danger-300',
  }[tone]
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={`border-l-2 bg-midnight-800/60 px-4 py-3 text-sm font-light ${cls}`}>
      {children}
    </div>
  )
}

export function KeyValues({ items }: { items: [ReactNode, ReactNode][] }) {
  return (
    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[minmax(8rem,auto)_1fr]">
      {items.map(([k, v], i) => (
        <div key={i} className="contents">
          <dt className="text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-slate-400 sm:pt-0.5">{k}</dt>
          <dd className="break-words font-light text-ivory-200">{v ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}

export function Forbidden() {
  return (
    <div className="py-24 text-center">
      <p className="font-display text-3xl text-ivory-50">You do not have access to this area.</p>
      <p className="mt-3 text-sm text-slate-400">Ask a Super Admin to adjust your role if you need it.</p>
    </div>
  )
}
