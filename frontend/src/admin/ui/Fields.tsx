import { forwardRef, useId } from 'react'
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'

export const controlClass =
  'block w-full border bg-midnight-950/70 px-3 py-2 text-sm font-light text-ivory-50 placeholder:text-slate-400/60 transition-colors focus:outline-none focus:ring-0 disabled:opacity-50'

export function borderClass(error?: string): string {
  return error ? 'border-danger-300/70 focus:border-danger-300' : 'border-[var(--line-strong)] hover:border-ivory-50/30 focus:border-champagne-400'
}

type WrapProps = { id: string; label: ReactNode; hint?: ReactNode; error?: string; required?: boolean; className?: string; children: ReactNode }

export function FieldWrap({ id, label, hint, error, required, className = '', children }: WrapProps) {
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">
        {label}
        {required && (
          <span aria-hidden="true" className="ml-1 text-champagne-400">
            *
          </span>
        )}
      </label>
      {children}
      {hint && !error && (
        <p id={`${id}-hint`} className="mt-1.5 text-xs font-light text-slate-400">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-1.5 text-xs text-danger-300">
          {error}
        </p>
      )}
    </div>
  )
}

function describedBy(id: string, hint?: ReactNode, error?: string) {
  return [error ? `${id}-error` : null, hint && !error ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined
}

type Base = { label: ReactNode; hint?: ReactNode; error?: string; className?: string }

export const Input = forwardRef<HTMLInputElement, Base & InputHTMLAttributes<HTMLInputElement>>(function Input({ label, hint, error, className, required, id: idProp, ...rest }, ref) {
  const gen = useId()
  const id = idProp ?? gen
  return (
    <FieldWrap id={id} label={label} hint={hint} error={error} required={required} className={className}>
      <input ref={ref} id={id} aria-invalid={error ? true : undefined} aria-describedby={describedBy(id, hint, error)} aria-required={required || undefined} className={`${controlClass} ${borderClass(error)}`} {...rest} />
    </FieldWrap>
  )
})

export const Textarea = forwardRef<HTMLTextAreaElement, Base & TextareaHTMLAttributes<HTMLTextAreaElement> & { mono?: boolean }>(function Textarea(
  { label, hint, error, className, required, id: idProp, mono, rows = 4, ...rest },
  ref,
) {
  const gen = useId()
  const id = idProp ?? gen
  return (
    <FieldWrap id={id} label={label} hint={hint} error={error} required={required} className={className}>
      <textarea
        ref={ref}
        id={id}
        rows={rows}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        aria-required={required || undefined}
        className={`${controlClass} ${borderClass(error)} resize-y leading-relaxed ${mono ? 'font-mono text-xs' : ''}`}
        {...rest}
      />
    </FieldWrap>
  )
})

export const Select = forwardRef<HTMLSelectElement, Base & SelectHTMLAttributes<HTMLSelectElement> & { options: { value: string; label: string }[]; placeholder?: string }>(function Select(
  { label, hint, error, className, required, id: idProp, options, placeholder, ...rest },
  ref,
) {
  const gen = useId()
  const id = idProp ?? gen
  return (
    <FieldWrap id={id} label={label} hint={hint} error={error} required={required} className={className}>
      <select
        ref={ref}
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        aria-required={required || undefined}
        className={`${controlClass} ${borderClass(error)} cursor-pointer [&>option]:bg-midnight-900`}
        {...rest}
      >
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </FieldWrap>
  )
})

export const Toggle = forwardRef<HTMLInputElement, { label: ReactNode; hint?: ReactNode; error?: string; className?: string } & InputHTMLAttributes<HTMLInputElement>>(function Toggle(
  { label, hint, error, className = '', id: idProp, ...rest },
  ref,
) {
  const gen = useId()
  const id = idProp ?? gen
  return (
    <div className={className}>
      <label htmlFor={id} className="group inline-flex cursor-pointer items-center gap-3">
        <input ref={ref} id={id} type="checkbox" role="switch" className="peer sr-only" aria-describedby={describedBy(id, hint, error)} {...rest} />
        <span
          aria-hidden="true"
          className="relative h-5 w-9 shrink-0 border border-ivory-50/30 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-3.5 after:w-3.5 after:bg-ivory-200/70 after:transition-transform peer-checked:border-champagne-400 peer-checked:bg-champagne-400/20 peer-checked:after:translate-x-4 peer-checked:after:bg-champagne-400 peer-focus-visible:outline peer-focus-visible:outline-1 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-champagne-400"
        />
        <span className="text-sm font-light text-ivory-200">{label}</span>
      </label>
      {hint && !error && (
        <p id={`${id}-hint`} className="mt-1 pl-12 text-xs font-light text-slate-400">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-1 pl-12 text-xs text-danger-300">
          {error}
        </p>
      )}
    </div>
  )
})

export function CheckboxGroup({
  legend,
  options,
  value,
  onChange,
  error,
  required,
}: {
  legend: ReactNode
  options: { value: string; label: string }[]
  value: string[]
  onChange: (value: string[]) => void
  error?: string
  required?: boolean
}) {
  const id = useId()
  return (
    <fieldset aria-describedby={error ? `${id}-error` : undefined}>
      <legend className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">
        {legend}
        {required && <span aria-hidden="true" className="ml-1 text-champagne-400">*</span>}
      </legend>
      <div className="flex flex-wrap gap-x-6 gap-y-2">
        {options.map((o) => (
          <label key={o.value} className="inline-flex cursor-pointer items-center gap-2 text-sm font-light text-ivory-200">
            <input
              type="checkbox"
              className="h-3.5 w-3.5 accent-[#c9b07a]"
              checked={value.includes(o.value)}
              onChange={(e) => onChange(e.target.checked ? [...value, o.value] : value.filter((v) => v !== o.value))}
            />
            {o.label}
          </label>
        ))}
      </div>
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-1.5 text-xs text-danger-300">
          {error}
        </p>
      )}
    </fieldset>
  )
}

export function SearchInput({ value, onChange, placeholder = 'Search', label = 'Search' }: { value: string; onChange: (v: string) => void; placeholder?: string; label?: string }) {
  return (
    <label className="relative block w-full max-w-xs">
      <span className="sr-only">{label}</span>
      <input type="search" value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className={`${controlClass} ${borderClass()} pl-8`} />
      <svg aria-hidden="true" viewBox="0 0 16 16" className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" strokeWidth="1.4">
        <circle cx="7" cy="7" r="5" />
        <path d="m11 11 3.5 3.5" />
      </svg>
    </label>
  )
}

export function FilterSelect({ label, value, onChange, options, allLabel = 'All' }: { label: string; value: string; onChange: (v: string) => void; options: { value: string; label: string }[]; allLabel?: string }) {
  return (
    <label className="block">
      <span className="sr-only">{label}</span>
      <select value={value} onChange={(e) => onChange(e.target.value)} className={`${controlClass} ${borderClass()} w-auto cursor-pointer pr-8 [&>option]:bg-midnight-900`} aria-label={label}>
        <option value="">
          {label}: {allLabel}
        </option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  )
}
