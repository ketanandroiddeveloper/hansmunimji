import { forwardRef, useId } from 'react'
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'

const control =
  'peer block w-full border-0 border-b bg-transparent px-0 pb-3 pt-2 text-[1.05rem] font-light text-ivory-50 placeholder:text-slate-400/60 transition-colors duration-300 focus:outline-none focus:ring-0 disabled:opacity-50'

function borderFor(error?: string) {
  return error ? 'border-danger-300/80 focus:border-danger-300' : 'border-[var(--line-strong)] hover:border-ivory-50/35 focus:border-champagne-400'
}

type FieldShellProps = {
  id: string
  label: ReactNode
  hint?: ReactNode
  error?: string
  required?: boolean
  children: ReactNode
  className?: string
}

export function FieldShell({ id, label, hint, error, required, children, className = '' }: FieldShellProps) {
  return (
    <div className={className}>
      <label htmlFor={id} className="block text-[0.68rem] font-medium uppercase tracking-[0.24em] text-ivory-200/70">
        {label}
        {required ? (
          <span aria-hidden="true" className="ml-1 text-champagne-400">
            *
          </span>
        ) : (
          <span className="ml-2 normal-case tracking-normal text-slate-400">(optional)</span>
        )}
      </label>
      {children}
      {hint && !error && (
        <p id={`${id}-hint`} className="mt-2 text-xs font-light text-slate-400">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-2 text-xs text-danger-300">
          {error}
        </p>
      )}
    </div>
  )
}

function describedBy(id: string, hint?: ReactNode, error?: string) {
  return [error ? `${id}-error` : null, hint && !error ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined
}

type BaseProps = { label: ReactNode; hint?: ReactNode; error?: string; className?: string }

export const TextInput = forwardRef<HTMLInputElement, BaseProps & InputHTMLAttributes<HTMLInputElement>>(function TextInput(
  { label, hint, error, className, required, id: idProp, ...rest },
  ref,
) {
  const generated = useId()
  const id = idProp ?? generated
  return (
    <FieldShell id={id} label={label} hint={hint} error={error} required={required} className={className}>
      <input
        ref={ref}
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        aria-required={required || undefined}
        className={`${control} ${borderFor(error)}`}
        {...rest}
      />
    </FieldShell>
  )
})

export const TextArea = forwardRef<HTMLTextAreaElement, BaseProps & TextareaHTMLAttributes<HTMLTextAreaElement> & { maxLength?: number; currentLength?: number }>(
  function TextArea({ label, hint, error, className, required, id: idProp, currentLength, maxLength, ...rest }, ref) {
    const generated = useId()
    const id = idProp ?? generated
    return (
      <FieldShell
        id={id}
        label={label}
        hint={
          hint || maxLength ? (
            <span className="flex justify-between gap-4">
              <span>{hint}</span>
              {maxLength && <span className="tabular-nums">{`${currentLength ?? 0} / ${maxLength}`}</span>}
            </span>
          ) : undefined
        }
        error={error}
        required={required}
        className={className}
      >
        <textarea
          ref={ref}
          id={id}
          rows={4}
          maxLength={maxLength}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy(id, hint || maxLength, error)}
          aria-required={required || undefined}
          className={`${control} ${borderFor(error)} min-h-28 resize-y leading-relaxed`}
          {...rest}
        />
      </FieldShell>
    )
  },
)

export const SelectInput = forwardRef<HTMLSelectElement, BaseProps & SelectHTMLAttributes<HTMLSelectElement> & { options: { value: string; label: string }[]; placeholder?: string }>(
  function SelectInput({ label, hint, error, className, required, id: idProp, options, placeholder, ...rest }, ref) {
    const generated = useId()
    const id = idProp ?? generated
    return (
      <FieldShell id={id} label={label} hint={hint} error={error} required={required} className={className}>
        <div className="relative">
          <select
            ref={ref}
            id={id}
            aria-invalid={error ? true : undefined}
            aria-describedby={describedBy(id, hint, error)}
            aria-required={required || undefined}
            className={`${control} ${borderFor(error)} cursor-pointer appearance-none pr-8 [&>option]:bg-midnight-900`}
            {...rest}
          >
            {placeholder !== undefined && <option value="">{placeholder}</option>}
            {options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
          <svg aria-hidden="true" viewBox="0 0 12 8" className="pointer-events-none absolute right-1 top-1/2 h-2 w-3 -translate-y-1/2 text-champagne-400" fill="none" stroke="currentColor" strokeWidth="1.2">
            <path d="m1 1 5 5 5-5" />
          </svg>
        </div>
      </FieldShell>
    )
  },
)

export const Checkbox = forwardRef<HTMLInputElement, { label: ReactNode; error?: string; className?: string } & InputHTMLAttributes<HTMLInputElement>>(function Checkbox(
  { label, error, className = '', id: idProp, ...rest },
  ref,
) {
  const generated = useId()
  const id = idProp ?? generated
  return (
    <div className={className}>
      <label htmlFor={id} className="group flex cursor-pointer items-start gap-4">
        <input
          ref={ref}
          id={id}
          type="checkbox"
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-error` : undefined}
          className="peer sr-only"
          {...rest}
        />
        <span
          aria-hidden="true"
          className={`mt-1 flex h-4 w-4 shrink-0 items-center justify-center border transition-colors peer-checked:border-champagne-400 peer-checked:bg-champagne-400 peer-focus-visible:outline peer-focus-visible:outline-1 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-champagne-400 peer-checked:[&>svg]:opacity-100 ${error ? 'border-danger-300' : 'border-ivory-50/40 group-hover:border-champagne-400'}`}
        >
          <svg viewBox="0 0 12 10" className="h-2.5 w-2.5 text-midnight-950 opacity-0" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="m1 5 3.5 3.5L11 1" />
          </svg>
        </span>
        <span className="text-sm font-light leading-relaxed text-ivory-200/85">{label}</span>
      </label>
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-2 pl-8 text-xs text-danger-300">
          {error}
        </p>
      )}
    </div>
  )
})

type ChoiceOption = { value: string; label: string; description?: string }

/** Large selectable cards backed by native radios (keyboard and screen-reader friendly). */
export function ChoiceGroup({
  legend,
  name,
  options,
  value,
  onChange,
  error,
  columns = 2,
  required,
}: {
  legend: ReactNode
  name: string
  options: ChoiceOption[]
  value: string | undefined | null
  onChange: (value: string) => void
  error?: string
  columns?: 1 | 2 | 3
  required?: boolean
}) {
  const id = useId()
  const cols = columns === 3 ? 'sm:grid-cols-3' : columns === 2 ? 'sm:grid-cols-2' : ''
  return (
    <fieldset aria-describedby={error ? `${id}-error` : undefined} aria-required={required || undefined}>
      <legend className="mb-4 block text-[0.68rem] font-medium uppercase tracking-[0.24em] text-ivory-200/70">
        {legend}
        {required && (
          <span aria-hidden="true" className="ml-1 text-champagne-400">
            *
          </span>
        )}
      </legend>
      <div className={`grid gap-3 ${cols}`}>
        {options.map((o) => {
          const checked = value === o.value
          return (
            <label
              key={o.value}
              className={`relative flex cursor-pointer flex-col gap-1 border px-5 py-4 transition-colors duration-300 has-[:focus-visible]:outline has-[:focus-visible]:outline-1 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-champagne-400 ${
                checked ? 'border-champagne-400 bg-champagne-400/[0.07]' : 'border-[var(--line-strong)] hover:border-ivory-50/40'
              }`}
            >
              <input type="radio" name={name} value={o.value} checked={checked} onChange={() => onChange(o.value)} className="sr-only" />
              <span className="flex items-center justify-between gap-3">
                <span className={`font-display text-xl ${checked ? 'text-champagne-200' : 'text-ivory-50'}`}>{o.label}</span>
                <span aria-hidden="true" className={`h-2 w-2 rounded-full border ${checked ? 'border-champagne-400 bg-champagne-400' : 'border-ivory-50/40'}`} />
              </span>
              {o.description && <span className="text-xs font-light text-slate-400">{o.description}</span>}
            </label>
          )
        })}
      </div>
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-2 text-xs text-danger-300">
          {error}
        </p>
      )}
    </fieldset>
  )
}

export function FormAlert({ tone = 'error', children }: { tone?: 'error' | 'info' | 'success'; children: ReactNode }) {
  const styles = {
    error: 'border-danger-300/50 text-danger-300',
    info: 'border-[var(--line-strong)] text-ivory-200',
    success: 'border-emerald-300/50 text-emerald-300',
  }[tone]
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={`border-l-2 bg-midnight-800/60 px-5 py-4 text-sm font-light ${styles}`}>
      {children}
    </div>
  )
}
