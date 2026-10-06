import { useState } from 'react'
import type { ReactNode } from 'react'
import { Input, Textarea, Toggle } from './Fields'
import { MediaPicker } from './MediaPicker'
import { AButton, Notice } from './Primitives'

export type Json = string | number | boolean | null | Json[] | { [key: string]: Json }

const LONG_KEYS = /(body|description|intro|subtitle|answer|summary|text|quote|bio)$/i

export function humanize(key: string): string {
  const k = key.replace(/_media_id$/, '_image').replace(/_/g, ' ').trim()
  return k.charAt(0).toUpperCase() + k.slice(1)
}

function blank(value: Json, key = ''): Json {
  if (Array.isArray(value)) return []
  if (value !== null && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, blank(v, k)]))
  if (typeof value === 'number') return key.endsWith('_media_id') ? null : 0
  if (typeof value === 'boolean') return false
  return key.endsWith('_media_id') ? null : ''
}

function Node({ name, label, value, onChange, depth }: { name: string; label: string; value: Json; onChange: (v: Json) => void; depth: number }) {
  if (name.endsWith('_media_id')) {
    return <MediaPicker label={label} value={typeof value === 'number' ? value : null} onChange={(id) => onChange(id)} />
  }
  if (typeof value === 'boolean') return <Toggle label={label} checked={value} onChange={(e) => onChange(e.target.checked)} />
  if (typeof value === 'number') return <Input label={label} type="number" value={String(value)} onChange={(e) => onChange(e.target.value === '' ? 0 : Number(e.target.value))} />
  if (Array.isArray(value)) return <ArrayNode name={name} label={label} value={value} onChange={onChange} depth={depth} />
  if (value !== null && typeof value === 'object') {
    return (
      <fieldset className={`grid gap-4 border border-[var(--line)] p-4 ${depth === 0 ? 'bg-midnight-900/40' : ''}`}>
        <legend className="px-2 text-[0.62rem] font-semibold uppercase tracking-[0.2em] text-champagne-200">{label}</legend>
        {Object.entries(value).map(([k, v]) => (
          <Node key={k} name={k} label={humanize(k)} value={v} depth={depth + 1} onChange={(nv) => onChange({ ...value, [k]: nv })} />
        ))}
      </fieldset>
    )
  }
  const text = value ?? ''
  return LONG_KEYS.test(name) || String(text).length > 120 ? (
    <Textarea label={label} rows={3} value={String(text)} onChange={(e) => onChange(e.target.value)} />
  ) : (
    <Input label={label} value={String(text)} onChange={(e) => onChange(e.target.value)} />
  )
}

function ArrayNode({ name, label, value, onChange, depth, template }: { name: string; label: string; value: Json[]; onChange: (v: Json) => void; depth: number; template?: Json }) {
  const shape = template ?? (value.length > 0 ? blank(value[0]) : '')
  const move = (i: number, dir: -1 | 1) => {
    const next = [...value]
    ;[next[i], next[i + dir]] = [next[i + dir], next[i]]
    onChange(next)
  }
  return (
    <fieldset className="border border-[var(--line)] p-4">
      <legend className="px-2 text-[0.62rem] font-semibold uppercase tracking-[0.2em] text-champagne-200">{label}</legend>
      <ol className="space-y-3">
        {value.map((item, i) => (
          <li key={i} className="flex gap-3">
            <span className="mt-7 w-5 shrink-0 text-right text-xs tabular-nums text-slate-400">{i + 1}.</span>
            <div className="min-w-0 flex-1">
              <Node name={name.replace(/s$/, '')} label={`${humanize(name.replace(/s$/, ''))} ${i + 1}`} value={item} depth={depth + 1} onChange={(nv) => onChange(value.map((v, j) => (j === i ? nv : v)))} />
            </div>
            <div className="mt-6 flex shrink-0 flex-col gap-1">
              <button type="button" className="px-1 text-xs text-slate-400 hover:text-champagne-200 disabled:opacity-30" disabled={i === 0} onClick={() => move(i, -1)} aria-label={`Move item ${i + 1} up`}>
                ↑
              </button>
              <button type="button" className="px-1 text-xs text-slate-400 hover:text-champagne-200 disabled:opacity-30" disabled={i === value.length - 1} onClick={() => move(i, 1)} aria-label={`Move item ${i + 1} down`}>
                ↓
              </button>
              <button type="button" className="px-1 text-xs text-danger-300/80 hover:text-danger-300" onClick={() => onChange(value.filter((_, j) => j !== i))} aria-label={`Remove item ${i + 1}`}>
                ✕
              </button>
            </div>
          </li>
        ))}
      </ol>
      <AButton variant="ghost" className="mt-3 !px-0" onClick={() => onChange([...value, blank(shape)])}>
        + Add {humanize(name.replace(/s$/, '')).toLowerCase()}
      </AButton>
    </fieldset>
  )
}

/**
 * Edits structured JSON content as form fields. `template` describes the shape of new list items
 * when the list starts empty (e.g. `{ title: '', description: '' }`).
 */
export function StructuredEditor({ label, value, onChange, error, template, hint }: { label: string; value: Json; onChange: (v: Json) => void; error?: string; template?: Json; hint?: ReactNode }) {
  const [raw, setRaw] = useState<string | null>(null)
  const [rawError, setRawError] = useState<string | null>(null)

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <span className="text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">{label}</span>
        <AButton
          variant="ghost"
          className="!py-1 text-slate-400"
          onClick={() => {
            if (raw === null) setRaw(JSON.stringify(value ?? (template !== undefined ? [] : {}), null, 2))
            else setRaw(null)
            setRawError(null)
          }}
        >
          {raw === null ? 'Edit as JSON' : 'Back to fields'}
        </AButton>
      </div>
      {hint && <p className="text-xs font-light text-slate-400">{hint}</p>}
      {raw !== null ? (
        <>
          <Textarea
            label={`${label} (JSON)`}
            mono
            rows={16}
            value={raw}
            onChange={(e) => {
              setRaw(e.target.value)
              try {
                onChange(JSON.parse(e.target.value) as Json)
                setRawError(null)
              } catch {
                setRawError('This is not valid JSON yet; changes are applied once it parses.')
              }
            }}
          />
          {rawError && <Notice tone="warn">{rawError}</Notice>}
        </>
      ) : Array.isArray(value) || (value === null && template !== undefined) ? (
        <ArrayNode name={label.toLowerCase().replace(/\s+/g, '_')} label={label} value={(value as Json[] | null) ?? []} onChange={onChange} depth={0} template={template} />
      ) : value !== null && typeof value === 'object' ? (
        <div className="grid gap-4">
          {Object.entries(value).map(([k, v]) => (
            <Node key={k} name={k} label={humanize(k)} value={v} depth={0} onChange={(nv) => onChange({ ...value, [k]: nv })} />
          ))}
          {Object.keys(value).length === 0 && <p className="text-sm text-slate-400">No sections yet. Use “Edit as JSON” to add structured content.</p>}
        </div>
      ) : (
        <p className="text-sm text-slate-400">No structured content. Use “Edit as JSON” to add some.</p>
      )}
      {error && (
        <p role="alert" className="text-xs text-danger-300">
          {error}
        </p>
      )}
    </div>
  )
}
