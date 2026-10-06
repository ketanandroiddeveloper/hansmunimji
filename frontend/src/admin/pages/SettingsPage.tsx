import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { adminApi, errorMessage } from '../lib/adminApi'
import { useUnsavedChangesWarning } from '../resources/ResourceEditPage'
import { CURRENCIES } from '../resources/FormField'
import { CheckboxGroup, Input, Select, Textarea, Toggle } from '../ui/Fields'
import { AButton, ErrorNote, Notice, PageHeader, Panel, Spinner } from '../ui/Primitives'
import { StructuredEditor } from '../ui/StructuredEditor'
import type { Json } from '../ui/StructuredEditor'
import { useToast } from '../ui/Toast'

type Stored = Record<string, { value: unknown; is_public: boolean }>
type Values = Record<string, unknown>

type Kind = 'text' | 'email' | 'textarea' | 'number' | 'boolean' | 'currencies' | 'default_currency' | 'routing' | 'country_routing' | 'reminders' | 'offsets' | 'links'

interface KnownSetting {
  key: string
  label: string
  kind: Kind
  isPublic: boolean
  hint?: ReactNode
  fallback: unknown
}

const GROUPS: { title: string; description?: string; settings: KnownSetting[] }[] = [
  {
    title: 'Site',
    settings: [
      { key: 'site.name', label: 'Site name', kind: 'text', isPublic: true, fallback: '' },
      { key: 'site.tagline', label: 'Tagline', kind: 'text', isPublic: true, fallback: '' },
      { key: 'site.description', label: 'Default description', kind: 'textarea', isPublic: true, fallback: '', hint: 'Used for search results and social previews when a page has none of its own.' },
    ],
  },
  {
    title: 'Contact',
    description: 'Shown publicly in the footer, on booking pages and in emails.',
    settings: [
      { key: 'contact.email', label: 'Public email', kind: 'email', isPublic: true, fallback: '' },
      { key: 'contact.phone', label: 'Public phone', kind: 'text', isPublic: true, fallback: '', hint: 'International format, e.g. +91 98765 43210' },
      { key: 'contact.whatsapp', label: 'WhatsApp number', kind: 'text', isPublic: true, fallback: '' },
      { key: 'social.links', label: 'Official profiles', kind: 'links', isPublic: true, fallback: [], hint: 'Also used as schema.org sameAs. Use https:// links to profiles you control.' },
    ],
  },
  {
    title: 'Notifications',
    settings: [
      { key: 'notifications.admin_email', label: 'Private office email', kind: 'email', isPublic: false, fallback: '', hint: 'Receives new-application, booking and integration alerts. Never shown publicly. Falls back to MAIL_ADMIN_ADDRESS.' },
    ],
  },
  {
    title: 'Currencies & payments',
    description: 'Gateways must also be configured with credentials in the server environment; see Integrations.',
    settings: [
      { key: 'currencies.enabled', label: 'Currencies offered', kind: 'currencies', isPublic: true, fallback: [...CURRENCIES] },
      { key: 'currencies.default', label: 'Default currency', kind: 'default_currency', isPublic: true, fallback: 'INR' },
      { key: 'payments.routing', label: 'Gateway routing', kind: 'routing', isPublic: false, fallback: {}, hint: 'Which gateway is offered for each currency, in order of preference. Unconfigured gateways are skipped automatically.' },
      {
        key: 'payments.country_routing',
        label: 'Preferred gateway by country',
        kind: 'country_routing',
        isPublic: false,
        fallback: {},
        hint: 'Optional. For clients in these countries the listed gateway is offered first, provided it is allowed for the chosen currency above and enabled for that currency on the merchant account.',
      },
      {
        key: 'payments.auto_refund_conflicts',
        label: 'Automatically refund conflicting payments',
        kind: 'boolean',
        isPublic: false,
        fallback: true,
        hint: 'If a payment completes after its reserved time was released and booked by someone else, refund it in full automatically. You are alerted either way.',
      },
    ],
  },
  {
    title: 'Booking',
    settings: [
      { key: 'booking.max_reschedules', label: 'Client reschedules allowed per booking', kind: 'number', isPublic: false, fallback: 2 },
      {
        key: 'booking.unpaid_expiry_hours',
        label: 'Expire unpaid reservations after (hours)',
        kind: 'number',
        isPublic: false,
        fallback: 24,
        hint: 'Counted from when the reserved time is released. Unpaid reservations also expire once their start time passes. A payment that still arrives later is honoured if the time is free, otherwise refunded.',
      },
      { key: 'reminders.schedule', label: 'Reminder emails', kind: 'reminders', isPublic: false, fallback: [] },
    ],
  },
  {
    title: 'Gatherings',
    settings: [
      {
        key: 'events.auto_promote_waitlist',
        label: 'Offer freed places to the waitlist automatically',
        kind: 'boolean',
        isPublic: false,
        fallback: true,
        hint: 'In order of registration. Free gatherings confirm the guest directly; paid gatherings email a payment link and hold the place for the offer period.',
      },
      { key: 'events.waitlist_offer_hours', label: 'Hold an offered place for (hours)', kind: 'number', isPublic: false, fallback: 48, hint: 'Also used when you send a payment link from the registrations list.' },
      { key: 'events.reminder_offsets_minutes', label: 'Reminder emails to confirmed guests', kind: 'offsets', isPublic: false, fallback: [1440, 60] },
    ],
  },
  {
    title: 'Privacy & analytics',
    settings: [
      {
        key: 'analytics.ga_measurement_id',
        label: 'Google Analytics measurement ID',
        kind: 'text',
        isPublic: true,
        fallback: '',
        hint: 'e.g. G-XXXXXXXXXX. Loaded only after a visitor consents; never on admin, application, booking or private pages. Leave blank to disable.',
      },
      { key: 'consent.banner_text', label: 'Consent banner text', kind: 'textarea', isPublic: true, fallback: '' },
      { key: 'privacy.policy_version', label: 'Privacy policy version', kind: 'text', isPublic: true, fallback: '1.0', hint: 'Change this when the privacy policy changes; visitors are asked for consent again and new consents record this version.' },
      { key: 'privacy.retention_rejected_days', label: 'Keep declined applications for (days)', kind: 'number', isPublic: false, fallback: 180, hint: 'Declined applications are deleted automatically after this period. Applies to applications declined after the change.' },
    ],
  },
]

const KNOWN = new Map(GROUPS.flatMap((g) => g.settings).map((s) => [s.key, s]))
const GATEWAY_ORDERS: { value: string; label: string; order: string[] }[] = [
  { value: 'razorpay,stripe', label: 'Razorpay, then Stripe', order: ['razorpay', 'stripe'] },
  { value: 'stripe,razorpay', label: 'Stripe, then Razorpay', order: ['stripe', 'razorpay'] },
  { value: 'razorpay', label: 'Razorpay only', order: ['razorpay'] },
  { value: 'stripe', label: 'Stripe only', order: ['stripe'] },
]

function RoutingEditor({ value, onChange, enabled }: { value: Record<string, string[]>; onChange: (v: Record<string, string[]>) => void; enabled: string[] }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {CURRENCIES.filter((c) => enabled.includes(c)).map((c) => (
        <Select
          key={c}
          label={c}
          value={(value[c] ?? ['stripe', 'razorpay']).join(',')}
          onChange={(e) => onChange({ ...value, [c]: GATEWAY_ORDERS.find((o) => o.value === e.target.value)!.order })}
          options={GATEWAY_ORDERS}
        />
      ))}
    </div>
  )
}

function CountryRoutingEditor({ value, onChange }: { value: Record<string, string[]>; onChange: (v: Record<string, string[]>) => void }) {
  const [rows, setRows] = useState(() => Object.entries(value).map(([country, order]) => ({ country, order: order.join(',') })))
  const commit = (next: typeof rows) => {
    setRows(next)
    onChange(Object.fromEntries(next.filter((r) => r.country).map((r) => [r.country, GATEWAY_ORDERS.find((o) => o.value === r.order)!.order])))
  }
  return (
    <div className="space-y-3">
      {rows.map((r, i) => {
        const update = (patch: Partial<(typeof rows)[number]>) => commit(rows.map((x, j) => (j === i ? { ...x, ...patch } : x)))
        return (
          <div key={i} className="flex flex-wrap items-end gap-3">
            <Input label="Country (ISO code)" className="w-40" maxLength={2} placeholder="AE" value={r.country} onChange={(e) => update({ country: e.target.value.toUpperCase().replace(/[^A-Z]/g, '') })} />
            <Select label="Prefer" className="w-56" value={r.order} onChange={(e) => update({ order: e.target.value })} options={GATEWAY_ORDERS} />
            <AButton variant="ghost" className="mb-0.5 text-danger-300" onClick={() => commit(rows.filter((_, j) => j !== i))}>
              Remove
            </AButton>
          </div>
        )
      })}
      {rows.length === 0 && <p className="text-sm font-light text-slate-400">No country preferences; the currency order above applies everywhere.</p>}
      <AButton variant="secondary" onClick={() => commit([...rows, { country: '', order: 'stripe,razorpay' }])}>
        + Add country
      </AButton>
    </div>
  )
}

function OffsetsEditor({ value, onChange }: { value: number[]; onChange: (v: number[]) => void }) {
  const unitOf = (m: number) => (m % 1440 === 0 ? 1440 : m % 60 === 0 ? 60 : 1)
  return (
    <div className="space-y-3">
      {value.map((m, i) => {
        const unit = unitOf(m)
        const set = (next: number) => onChange(value.map((x, j) => (j === i ? next : x)))
        return (
          <div key={i} className="flex flex-wrap items-end gap-3">
            <Input label="Send" type="number" min={1} className="w-24" value={String(m / unit)} onChange={(e) => set(Math.max(1, Number(e.target.value) || 1) * unit)} />
            <Select label="Unit" className="w-32" value={String(unit)} onChange={(e) => set((m / unit) * Number(e.target.value))} options={REMINDER_UNITS} />
            <span className="pb-2 text-sm font-light text-slate-400">before the gathering</span>
            <AButton variant="ghost" className="mb-0.5 text-danger-300" onClick={() => onChange(value.filter((_, j) => j !== i))}>
              Remove
            </AButton>
          </div>
        )
      })}
      {value.length === 0 && <p className="text-sm font-light text-slate-400">No gathering reminders are sent.</p>}
      <AButton variant="secondary" onClick={() => onChange([...value, 1440])}>
        + Add reminder
      </AButton>
      <p className="text-xs text-slate-400">If several reminders fall due together, only the closest one is sent.</p>
    </div>
  )
}

type Reminder = { minutes: number; enabled: boolean }
const REMINDER_UNITS = [
  { value: '1440', label: 'days' },
  { value: '60', label: 'hours' },
  { value: '1', label: 'minutes' },
]

function RemindersEditor({ value, onChange }: { value: Reminder[]; onChange: (v: Reminder[]) => void }) {
  const unitOf = (m: number) => (m % 1440 === 0 ? 1440 : m % 60 === 0 ? 60 : 1)
  return (
    <div className="space-y-3">
      {value.map((r, i) => {
        const unit = unitOf(r.minutes)
        const update = (patch: Partial<Reminder>) => onChange(value.map((x, j) => (j === i ? { ...x, ...patch } : x)))
        return (
          <div key={i} className="flex flex-wrap items-end gap-3">
            <Input label="Send" type="number" min={1} className="w-24" value={String(r.minutes / unit)} onChange={(e) => update({ minutes: Math.max(1, Number(e.target.value) || 1) * unit })} />
            <Select label="Unit" className="w-32" value={String(unit)} onChange={(e) => update({ minutes: (r.minutes / unit) * Number(e.target.value) })} options={REMINDER_UNITS} />
            <span className="pb-2 text-sm font-light text-slate-400">before the session</span>
            <Toggle label="On" checked={r.enabled} onChange={(e) => update({ enabled: e.target.checked })} className="pb-2" />
            <AButton variant="ghost" className="mb-0.5 text-danger-300" onClick={() => onChange(value.filter((_, j) => j !== i))}>
              Remove
            </AButton>
          </div>
        )
      })}
      {value.length === 0 && <p className="text-sm font-light text-slate-400">No reminders are sent.</p>}
      <AButton variant="secondary" onClick={() => onChange([...value, { minutes: 1440, enabled: true }])}>
        + Add reminder
      </AButton>
      <p className="text-xs text-slate-400">Applies to bookings confirmed or rescheduled after saving.</p>
    </div>
  )
}

function SettingControl({ s, value, onChange, values }: { s: KnownSetting; value: unknown; onChange: (v: unknown) => void; values: Values }) {
  switch (s.kind) {
    case 'textarea':
      return <Textarea label={s.label} hint={s.hint} rows={3} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />
    case 'number':
      return <Input label={s.label} hint={s.hint} type="number" min={0} value={String(value ?? '')} onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} />
    case 'boolean':
      return <Toggle label={s.label} hint={s.hint} checked={Boolean(value)} onChange={(e) => onChange(e.target.checked)} />
    case 'currencies':
      return <CheckboxGroup legend={s.label} options={CURRENCIES.map((c) => ({ value: c, label: c }))} value={(value as string[]) ?? []} onChange={onChange} />
    case 'default_currency':
      return <Select label={s.label} hint={s.hint} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} options={((values['currencies.enabled'] as string[]) ?? []).map((c) => ({ value: c, label: c }))} />
    case 'routing':
      return (
        <fieldset>
          <legend className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">{s.label}</legend>
          <RoutingEditor value={(value as Record<string, string[]>) ?? {}} onChange={onChange} enabled={(values['currencies.enabled'] as string[]) ?? []} />
          {s.hint && <p className="mt-2 text-xs text-slate-400">{s.hint}</p>}
        </fieldset>
      )
    case 'country_routing':
      return (
        <fieldset>
          <legend className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">{s.label}</legend>
          <CountryRoutingEditor value={(value as Record<string, string[]>) ?? {}} onChange={onChange} />
          {s.hint && <p className="mt-2 text-xs text-slate-400">{s.hint}</p>}
        </fieldset>
      )
    case 'offsets':
      return (
        <fieldset>
          <legend className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">{s.label}</legend>
          <OffsetsEditor value={(value as number[]) ?? []} onChange={onChange} />
        </fieldset>
      )
    case 'reminders':
      return (
        <fieldset>
          <legend className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-ivory-200/75">{s.label}</legend>
          <RemindersEditor value={(value as Reminder[]) ?? []} onChange={onChange} />
        </fieldset>
      )
    case 'links':
      return <StructuredEditor label={s.label} hint={s.hint} value={(value as Json) ?? []} onChange={onChange} template={{ label: '', url: '' }} />
    default:
      return <Input label={s.label} hint={s.hint} type={s.kind === 'email' ? 'email' : 'text'} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />
  }
}

function validate(values: Values): string | null {
  const email = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  for (const k of ['contact.email', 'notifications.admin_email']) {
    const v = String(values[k] ?? '').trim()
    if (v && !email.test(v)) return `${KNOWN.get(k)!.label}: enter a valid email address.`
  }
  const ga = String(values['analytics.ga_measurement_id'] ?? '').trim()
  if (ga && !/^G-[A-Z0-9]{4,}$/.test(ga)) return 'Google Analytics measurement ID should look like G-XXXXXXXXXX.'
  const enabled = (values['currencies.enabled'] as string[]) ?? []
  if (enabled.length === 0) return 'Offer at least one currency.'
  if (!enabled.includes(String(values['currencies.default']))) return 'The default currency must be one of the currencies offered.'
  for (const link of (values['social.links'] as { label?: string; url?: string }[]) ?? []) {
    if (!link.label?.trim() || !/^https:\/\/\S+$/.test(link.url ?? '')) return 'Each official profile needs a label and an https:// link.'
  }
  for (const k of ['booking.max_reschedules', 'privacy.retention_rejected_days', 'booking.unpaid_expiry_hours', 'events.waitlist_offer_hours']) {
    const n = values[k]
    if (n === '' || !Number.isInteger(n) || (n as number) < 0) return `${KNOWN.get(k)!.label}: enter a whole number.`
  }
  for (const k of ['booking.unpaid_expiry_hours', 'events.waitlist_offer_hours']) {
    if ((values[k] as number) < 1 || (values[k] as number) > 720) return `${KNOWN.get(k)!.label}: choose between 1 and 720 hours.`
  }
  const countries = Object.keys((values['payments.country_routing'] as Record<string, string[]>) ?? {})
  if (countries.some((c) => !/^[A-Z]{2}$/.test(c))) return 'Preferred gateway by country: use two-letter ISO country codes, e.g. AE or GB.'
  return null
}

export default function SettingsPage() {
  const notify = useToast()
  const client = useQueryClient()
  const stored = useQuery({ queryKey: ['admin', 'settings'], queryFn: () => adminApi.get<Stored>('/admin/settings') })
  const [values, setValues] = useState<Values>({})
  const [baseline, setBaseline] = useState<string>('')
  const [error, setError] = useState<string | null>(null)
  const [custom, setCustom] = useState<{ key: string; value: string; is_public: boolean; isNew?: boolean }[]>([])
  const [customBaseline, setCustomBaseline] = useState('')

  const load = (data: Stored) => {
    const v: Values = {}
    for (const [key, s] of KNOWN) v[key] = data[key]?.value ?? s.fallback
    setValues(v)
    setBaseline(JSON.stringify(v))
    const extra = Object.entries(data)
      .filter(([k]) => !KNOWN.has(k))
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([key, s]) => ({ key, value: JSON.stringify(s.value, null, 2), is_public: s.is_public }))
    setCustom(extra)
    setCustomBaseline(JSON.stringify(extra))
  }
  useEffect(() => {
    if (stored.data) load(stored.data)
  }, [stored.data])

  const dirty = useMemo(() => baseline !== '' && (JSON.stringify(values) !== baseline || JSON.stringify(custom) !== customBaseline), [values, baseline, custom, customBaseline])
  useUnsavedChangesWarning(dirty)

  const save = useMutation({
    mutationFn: (items: { key: string; value: unknown; is_public: boolean }[]) => adminApi.put<Stored>('/admin/settings', { settings: items }),
    onSuccess: (data) => {
      client.setQueryData(['admin', 'settings'], data)
      client.invalidateQueries({ queryKey: ['settings'] })
      load(data)
      notify('Settings saved. The public site will refresh shortly.')
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const submit = () => {
    setError(null)
    const problem = validate(values)
    if (problem) return setError(problem)
    const before = JSON.parse(baseline) as Values
    const items: { key: string; value: unknown; is_public: boolean }[] = []
    for (const [key, s] of KNOWN) {
      const v = typeof values[key] === 'string' ? (values[key] as string).trim() : values[key]
      if (JSON.stringify(v) !== JSON.stringify(before[key]) || stored.data?.[key] === undefined) items.push({ key, value: v, is_public: s.isPublic })
    }
    const previous = new Map((JSON.parse(customBaseline) as typeof custom).map((c) => [c.key, c]))
    for (const c of custom) {
      if (!/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/.test(c.key)) return setError(`“${c.key || '(blank)'}” is not a valid key. Use dotted lowercase, e.g. section.name.`)
      let parsed: unknown
      try {
        parsed = JSON.parse(c.value)
      } catch {
        return setError(`The value of ${c.key} is not valid JSON. Wrap text in double quotes, e.g. "Hello".`)
      }
      const old = previous.get(c.key)
      if (!old || old.value !== c.value || old.is_public !== c.is_public) items.push({ key: c.key, value: parsed, is_public: c.is_public })
    }
    if (items.length === 0) return notify('Nothing to save.')
    save.mutate(items)
  }

  if (stored.isLoading || (stored.data && baseline === '')) return <Spinner />
  if (stored.isError) return <ErrorNote error={stored.error} onRetry={() => stored.refetch()} />

  return (
    <>
      <PageHeader title="Settings" description="Everyday site configuration. Credentials such as gateway keys, OAuth secrets and SMTP passwords are never stored here — they are set in the server environment." />
      <div className="space-y-6">
        {GROUPS.map((g) => (
          <Panel key={g.title} title={g.title}>
            {g.description && <p className="mb-5 text-sm font-light text-slate-400">{g.description}</p>}
            <div className="grid gap-5 md:grid-cols-2">
              {g.settings.map((s) => (
                <div key={s.key} className={['textarea', 'routing', 'country_routing', 'reminders', 'offsets', 'links', 'boolean'].includes(s.kind) || g.settings.length === 1 ? 'md:col-span-2' : ''}>
                  <SettingControl s={s} value={values[s.key]} values={values} onChange={(v) => setValues((cur) => ({ ...cur, [s.key]: v }))} />
                  {!s.isPublic && s.kind !== 'boolean' && <p className="mt-1 text-[0.6rem] uppercase tracking-[0.16em] text-slate-400/70">Private</p>}
                </div>
              ))}
            </div>
          </Panel>
        ))}

        <Panel
          title="Additional settings"
          actions={
            <AButton variant="ghost" className="!py-1" onClick={() => setCustom((c) => [...c, { key: '', value: '""', is_public: false, isNew: true }])}>
              + Add
            </AButton>
          }
        >
          <p className="mb-4 text-sm font-light text-slate-400">Advanced key–value settings used by custom templates. Values are JSON. Public settings are readable by anyone visiting the site.</p>
          {custom.length === 0 ? (
            <p className="text-sm font-light text-slate-400">None.</p>
          ) : (
            <div className="space-y-5">
              {custom.map((c, i) => {
                const update = (patch: Partial<typeof c>) => setCustom((list) => list.map((x, j) => (j === i ? { ...x, ...patch } : x)))
                return (
                  <div key={i} className="grid gap-3 border-t border-[var(--line)] pt-4 first:border-0 first:pt-0 md:grid-cols-[1fr_2fr]">
                    <div className="space-y-3">
                      <Input label="Key" value={c.key} readOnly={!c.isNew} onChange={(e) => update({ key: e.target.value.toLowerCase() })} spellCheck={false} />
                      <Toggle label="Public" checked={c.is_public} onChange={(e) => update({ is_public: e.target.checked })} />
                      {c.isNew && (
                        <AButton variant="ghost" className="!px-0 text-danger-300" onClick={() => setCustom((list) => list.filter((_, j) => j !== i))}>
                          Discard
                        </AButton>
                      )}
                    </div>
                    <Textarea label="Value (JSON)" mono rows={Math.min(10, Math.max(2, c.value.split('\n').length))} value={c.value} onChange={(e) => update({ value: e.target.value })} spellCheck={false} />
                  </div>
                )
              })}
            </div>
          )}
        </Panel>

        {error && <Notice tone="error">{error}</Notice>}
        <div className="sticky bottom-0 z-10 -mx-1 flex items-center gap-4 border-t border-[var(--line)] bg-midnight-950/95 px-1 py-4 backdrop-blur">
          <AButton variant="primary" loading={save.isPending} onClick={submit}>
            Save settings
          </AButton>
          {dirty && <span className="text-xs text-champagne-200">Unsaved changes</span>}
        </div>
      </div>
    </>
  )
}
