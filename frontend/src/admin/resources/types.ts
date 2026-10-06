import type { ComponentType, ReactNode } from 'react'
import { browserTimeZone } from '../../lib/format'
import { utcToZonedInput, zonedInputToIso } from '../lib/datetime'
import type { Json } from '../ui/StructuredEditor'

export type Row = Record<string, unknown> & { id: number }

export type FieldType =
  | 'text'
  | 'email'
  | 'url'
  | 'slug'
  | 'number'
  | 'money'
  | 'textarea'
  | 'code'
  | 'richtext'
  | 'boolean'
  | 'select'
  | 'checkboxes'
  | 'date'
  | 'time'
  | 'datetime'
  | 'timezone'
  | 'media'
  | 'relation'
  | 'tags'
  | 'structured'
  | 'prices'
  | 'practitioner'

export interface Option {
  value: string
  label: string
}

export interface FieldDef {
  name: string
  label: string
  type: FieldType
  required?: boolean
  hint?: ReactNode
  options?: Option[]
  rows?: number
  maxLength?: number
  min?: number
  max?: number
  /** For `slug`: derive from this field while creating. */
  slugFrom?: string
  /** For `relation`: an admin list endpoint and the property used as the label. */
  relation?: { path: string; label: string; emptyLabel?: string }
  /** For `datetime`: interpret wall-clock input in the zone held by this field (default: editor's browser zone). */
  timezoneField?: string
  /** For `money`: the field holding the currency code. */
  currencyField?: string
  template?: Json
  wide?: boolean
}

export interface ColumnDef {
  key: string
  label: string
  render?: (row: Row) => ReactNode
  className?: string
}

export interface FilterDef {
  name: string
  label: string
  options: Option[]
}

export interface PanelProps {
  record: Row
  refresh: () => void
}

export interface ResourceDef {
  key: string
  endpoint: string
  title: string
  singular: string
  permission: string
  description?: ReactNode
  group: string
  fields: FieldDef[]
  /** Sidebar-free grouping inside the form, by field name. */
  sections?: { title: string; fields: string[] }[]
  columns: ColumnDef[]
  filters?: FilterDef[]
  search?: boolean
  titleOf: (row: Row) => string
  canCreate?: boolean
  canDelete?: boolean
  defaults?: Record<string, unknown>
  panels?: ComponentType<PanelProps>[]
  publicPath?: (row: Row) => string | null
  notice?: ReactNode
}

export const asBool = (v: unknown): boolean => v === true || v === 1 || v === '1'

const zoneFor = (field: FieldDef, values: Record<string, unknown>): string => {
  const z = field.timezoneField ? values[field.timezoneField] : null
  return typeof z === 'string' && z ? z : browserTimeZone()
}

export function toFormValue(field: FieldDef, record: Record<string, unknown>): unknown {
  const v = record[field.name]
  switch (field.type) {
    case 'boolean':
      return asBool(v)
    case 'number':
      return v === null || v === undefined ? '' : String(v)
    case 'money':
      return v === null || v === undefined ? '' : (Number(v) / 100).toFixed(2)
    case 'date':
      return v ? String(v).slice(0, 10) : ''
    case 'time':
      return v ? String(v).slice(0, 5) : ''
    case 'datetime':
      return utcToZonedInput(v as string | null, zoneFor(field, record))
    case 'relation':
    case 'practitioner':
      return v === null || v === undefined ? '' : String(v)
    case 'media':
      return v === null || v === undefined ? null : Number(v)
    case 'tags':
      return Array.isArray(v) ? v.join(', ') : ''
    case 'checkboxes':
      return Array.isArray(v) ? v : []
    case 'structured':
      return v ?? (field.template !== undefined ? [] : {})
    case 'prices':
      return Array.isArray(v) ? v.map((p: { currency: string; amount_minor: number }) => ({ currency: p.currency, amount: (p.amount_minor / 100).toFixed(2) })) : []
    default:
      return v === null || v === undefined ? '' : String(v)
  }
}

export function toPayloadValue(field: FieldDef, value: unknown, values: Record<string, unknown>): unknown {
  switch (field.type) {
    case 'boolean':
      return Boolean(value)
    case 'number':
      return value === '' || value === null ? null : Number(value)
    case 'money':
      return value === '' || value === null ? null : Math.round(Number(value) * 100)
    case 'datetime':
      return value ? zonedInputToIso(String(value), zoneFor(field, values)) : null
    case 'relation':
    case 'practitioner':
      return value === '' || value === null ? null : Number(value)
    case 'media':
      return value ?? null
    case 'tags':
      return String(value ?? '')
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean)
    case 'checkboxes':
    case 'structured':
      return value
    case 'prices':
      return (value as { currency: string; amount: string }[]).filter((p) => p.amount !== '').map((p) => ({ currency: p.currency, amount_minor: Math.round(Number(p.amount) * 100) }))
    default:
      return value === '' ? null : value
  }
}

export function slugify(text: string): string {
  return text
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 120)
}
