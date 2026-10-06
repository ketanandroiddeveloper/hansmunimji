import { useQuery } from '@tanstack/react-query'
import { lazy, Suspense, useEffect, useMemo } from 'react'
import { Controller } from 'react-hook-form'
import type { Control, FieldValues } from 'react-hook-form'
import { canonicalTimeZone } from '../../lib/format'
import { adminApi } from '../lib/adminApi'
import { CheckboxGroup, Input, Select, Textarea, Toggle } from '../ui/Fields'
import { MediaPicker } from '../ui/MediaPicker'
import { AButton } from '../ui/Primitives'
import { StructuredEditor } from '../ui/StructuredEditor'
import type { Json } from '../ui/StructuredEditor'
import type { FieldDef, Row } from './types'

const RichTextEditor = lazy(() => import('../ui/RichTextEditor').then((m) => ({ default: m.RichTextEditor })))

export const CURRENCIES = ['INR', 'USD', 'AED', 'GBP'] as const

export function timeZoneList(): string[] {
  const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : ['UTC', 'Asia/Kolkata', 'Asia/Dubai', 'Europe/London', 'America/New_York']
  return Array.from(new Set(['UTC', ...zones.map(canonicalTimeZone)])).sort()
}

function RelationSelect({ field, value, onChange, error }: { field: FieldDef; value: string; onChange: (v: string) => void; error?: string }) {
  const rel = field.relation!
  const options = useQuery({
    queryKey: ['admin', 'options', rel.path],
    queryFn: () => adminApi.list<Row>(rel.path, { query: { per_page: 100 } }),
    staleTime: 60_000,
  })
  const opts = useMemo(() => (options.data?.items ?? []).map((r) => ({ value: String(r.id), label: String(r[rel.label] ?? `#${r.id}`) })), [options.data, rel.label])
  return (
    <Select
      label={field.label}
      required={field.required}
      hint={field.hint}
      error={error}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      options={opts}
      placeholder={field.required ? 'Select…' : (rel.emptyLabel ?? '— None —')}
      disabled={options.isLoading}
    />
  )
}

function PractitionerField({ field, value, onChange, error }: { field: FieldDef; value: string; onChange: (v: string) => void; error?: string }) {
  const profile = useQuery({ queryKey: ['admin', 'practitioner'], queryFn: () => adminApi.get<Row & { full_name: string }>('/admin/practitioner'), staleTime: 60_000, retry: false })
  useEffect(() => {
    if (!value && profile.data) onChange(String(profile.data.id))
  }, [value, profile.data, onChange])
  return (
    <div>
      <p className="mb-1.5 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">{field.label}</p>
      <p className="text-sm font-light text-ivory-200">{profile.data?.full_name ?? (profile.isError ? 'Create the practitioner profile first.' : 'Loading…')}</p>
      {error && <p className="mt-1 text-xs text-danger-300">{error}</p>}
    </div>
  )
}

function PricesEditor({ value, onChange, error }: { value: { currency: string; amount: string }[]; onChange: (v: { currency: string; amount: string }[]) => void; error?: string }) {
  const used = new Set(value.map((p) => p.currency))
  const available = CURRENCIES.filter((c) => !used.has(c))
  return (
    <fieldset>
      <legend className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">Prices</legend>
      <div className="space-y-2">
        {value.map((p, i) => (
          <div key={p.currency} className="flex items-end gap-3">
            <span className="w-14 pb-2 text-sm font-semibold tracking-wider text-champagne-200">{p.currency}</span>
            <Input
              label={`Amount in ${p.currency}`}
              type="number"
              min={0}
              step="0.01"
              value={p.amount}
              onChange={(e) => onChange(value.map((x, j) => (j === i ? { ...x, amount: e.target.value } : x)))}
              className="flex-1"
            />
            <AButton variant="ghost" className="mb-0.5 text-danger-300" onClick={() => onChange(value.filter((_, j) => j !== i))} aria-label={`Remove ${p.currency} price`}>
              Remove
            </AButton>
          </div>
        ))}
        {value.length === 0 && <p className="text-sm font-light text-slate-400">No prices. Paid consultations need at least one currency.</p>}
      </div>
      {available.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-2">
          {available.map((c) => (
            <AButton key={c} variant="secondary" onClick={() => onChange([...value, { currency: c, amount: '' }])}>
              + {c}
            </AButton>
          ))}
        </div>
      )}
      <p className="mt-2 text-xs text-slate-400">Amounts include whole and fractional units (e.g. 1500.00). Payments are only offered in currencies with an active gateway.</p>
      {error && <p className="mt-1 text-xs text-danger-300">{error}</p>}
    </fieldset>
  )
}

export function FormField({ field, control, isNew, record }: { field: FieldDef; control: Control<FieldValues>; isNew: boolean; record?: Row }) {
  return (
    <Controller
      control={control}
      name={field.name}
      rules={field.required && field.type !== 'boolean' ? { validate: (v) => (v === '' || v === null || v === undefined || (Array.isArray(v) && v.length === 0) ? 'This field is required.' : true) } : undefined}
      render={({ field: f, fieldState }) => {
        const error = fieldState.error?.message
        const common = { label: field.label, required: field.required, hint: field.hint, error }
        switch (field.type) {
          case 'boolean':
            return <Toggle label={field.label} hint={field.hint} error={error} checked={Boolean(f.value)} onChange={(e) => f.onChange(e.target.checked)} />
          case 'textarea':
          case 'code':
            return <Textarea {...common} rows={field.rows ?? (field.type === 'code' ? 14 : 4)} mono={field.type === 'code'} maxLength={field.maxLength} value={String(f.value ?? '')} onChange={f.onChange} onBlur={f.onBlur} />
          case 'richtext':
            return (
              <Suspense fallback={<div className="h-64 border border-[var(--line-strong)] bg-midnight-950/70" />}>
                <RichTextEditor {...common} value={String(f.value ?? '')} onChange={f.onChange} />
              </Suspense>
            )
          case 'select':
            return <Select {...common} options={field.options ?? []} placeholder={field.required ? undefined : '— None —'} value={String(f.value ?? '')} onChange={f.onChange} />
          case 'timezone':
            return <Select {...common} options={timeZoneList().map((z) => ({ value: z, label: z }))} placeholder="Select a time zone" value={String(f.value ?? '')} onChange={f.onChange} />
          case 'checkboxes':
            return <CheckboxGroup legend={field.label} required={field.required} options={field.options ?? []} value={(f.value as string[]) ?? []} onChange={f.onChange} error={error} />
          case 'relation':
            return <RelationSelect field={field} value={String(f.value ?? '')} onChange={f.onChange} error={error} />
          case 'practitioner':
            return <PractitionerField field={field} value={String(f.value ?? '')} onChange={f.onChange} error={error} />
          case 'media':
            return <MediaPicker label={field.label} hint={field.hint} error={error} value={(f.value as number | null) ?? null} onChange={f.onChange} />
          case 'structured':
            return <StructuredEditor label={field.label} hint={field.hint} error={error} value={f.value as Json} onChange={f.onChange} template={field.template} />
          case 'prices':
            return <PricesEditor value={(f.value as { currency: string; amount: string }[]) ?? []} onChange={f.onChange} error={error} />
          case 'money':
            return <Input {...common} type="number" min={0} step="0.01" value={String(f.value ?? '')} onChange={f.onChange} onBlur={f.onBlur} />
          case 'number':
            return <Input {...common} type="number" min={field.min} max={field.max} value={String(f.value ?? '')} onChange={f.onChange} onBlur={f.onBlur} />
          case 'date':
          case 'time':
            return <Input {...common} type={field.type} value={String(f.value ?? '')} onChange={f.onChange} onBlur={f.onBlur} />
          case 'datetime':
            return (
              <Input
                {...common}
                type="datetime-local"
                hint={field.hint ?? (field.timezoneField ? 'Local time at the venue (uses the time zone field).' : 'In your local time zone.')}
                value={String(f.value ?? '')}
                onChange={f.onChange}
                onBlur={f.onBlur}
              />
            )
          default:
            return (
              <Input
                {...common}
                type={field.type === 'email' ? 'email' : field.type === 'url' ? 'url' : 'text'}
                maxLength={field.maxLength}
                spellCheck={field.type === 'slug' ? false : undefined}
                hint={field.hint ?? (field.type === 'slug' ? (isNew ? 'Lowercase words separated by hyphens. Filled from the title.' : record ? 'Changing the slug changes the page address.' : undefined) : undefined)}
                value={String(f.value ?? '')}
                onChange={f.onChange}
                onBlur={f.onBlur}
              />
            )
        }
      }}
    />
  )
}
