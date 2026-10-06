import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { browserTimeZone, formatMoney } from '../../../lib/format'
import { ApiError } from '../../../lib/api'
import { adminApi, errorMessage } from '../../lib/adminApi'
import { zonedInputToIso } from '../../lib/datetime'
import { FORMAT_LABEL } from '../../lib/labels'
import { Input, Select, Textarea, Toggle } from '../../ui/Fields'
import { AButton, ErrorNote, Notice, PageHeader, Panel, Spinner } from '../../ui/Primitives'
import { useToast } from '../../ui/Toast'
import { TimeChooser } from './TimeChooser'
import type { TimeValue } from './TimeChooser'

interface AdminType {
  id: number
  slug: string
  title: string
  duration_minutes: number
  formats: string[]
  requires_payment: number
  is_active: number
  prices: { currency: string; amount_minor: number }[]
}

interface City {
  id: number
  name: string
  country: string
  is_active: number
}

type Errors = Partial<Record<string, string>>

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export default function NewAppointmentPage() {
  const navigate = useNavigate()
  const notify = useToast()
  const client = useQueryClient()

  const types = useQuery({
    queryKey: ['admin', 'appointment-types', 'bookable'],
    queryFn: () => adminApi.list<AdminType>('/admin/appointment-types', { query: { per_page: 100, is_active: 1 } }),
  })
  const cities = useQuery({
    queryKey: ['admin', 'options', '/admin/cities'],
    queryFn: () => adminApi.list<City>('/admin/cities', { query: { per_page: 100 } }),
    staleTime: 60_000,
  })

  const [typeSlug, setTypeSlug] = useState('')
  const [format, setFormat] = useState('')
  const [cityId, setCityId] = useState('')
  const [when, setWhen] = useState<TimeValue>({ date: '', time: '', timezone: browserTimeZone() })
  const [exempt, setExempt] = useState(false)
  const [currency, setCurrency] = useState('')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [notes, setNotes] = useState('')
  const [errors, setErrors] = useState<Errors>({})
  const [formError, setFormError] = useState<string | null>(null)

  const type = types.data?.items.find((t) => t.slug === typeSlug) ?? null
  const needsPayment = Boolean(type?.requires_payment) && !exempt
  const price = type?.prices.find((p) => p.currency === currency)

  const chooseType = (slug: string) => {
    const next = types.data?.items.find((t) => t.slug === slug)
    setTypeSlug(slug)
    setFormat(next?.formats.length === 1 ? next.formats[0] : '')
    setCurrency(next?.prices.length === 1 ? next.prices[0].currency : '')
  }

  const create = useMutation({
    mutationFn: (body: Record<string, unknown>) => adminApi.post<{ id: number; reference: string; status: string }>('/admin/appointments', body),
    onSuccess: (r) => {
      client.invalidateQueries({ queryKey: ['admin', 'appointments'] })
      client.invalidateQueries({ queryKey: ['admin', 'dashboard'] })
      notify(r.status === 'confirmed' ? `Booked ${r.reference}. Confirmation sent to the client.` : `Booked ${r.reference}. A payment request has been emailed to the client.`)
      navigate(`/admin/appointments/${r.id}`)
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'validation_failed' && e.fields) {
        const mapped: Errors = {}
        for (const [k, v] of Object.entries(e.fields)) mapped[k === 'starts_at' || k === 'timezone' ? 'when' : k] = v[0]
        setErrors(mapped)
      }
      setFormError(errorMessage(e))
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setFormError(null)
    const next: Errors = {}
    if (!type) next.type = 'Choose a consultation.'
    if (!format) next.format = 'Choose a format.'
    if (format === 'in_person' && !cityId) next.city_id = 'Choose a city for an in-person meeting.'
    const startsAt = zonedInputToIso(`${when.date}T${when.time}`, when.timezone)
    if (!startsAt) next.when = 'Choose a date and start time.'
    if (needsPayment && !currency) next.currency = 'Choose the currency the client will pay in.'
    if (name.trim().length < 2) next.name = 'Enter the client’s name.'
    if (!EMAIL.test(email.trim())) next.email = 'Enter a valid email address.'
    setErrors(next)
    if (Object.keys(next).length) return

    create.mutate({
      type: typeSlug,
      starts_at: startsAt,
      timezone: when.timezone,
      format,
      city_id: format === 'in_person' ? Number(cityId) : null,
      currency: needsPayment ? currency : null,
      payment_exempt: Boolean(type?.requires_payment) && exempt,
      name: name.trim(),
      email: email.trim(),
      phone: phone.trim() || null,
      notes: notes.trim() || null,
    })
  }

  if (types.isLoading) return <Spinner />
  if (types.isError) return <ErrorNote error={types.error} onRetry={() => types.refetch()} />

  return (
    <>
      <PageHeader back={{ to: '/admin/appointments', label: 'Appointments' }} title="New booking" description="Book a session on a client’s behalf. Any time that does not overlap another booking may be used." />
      {types.data!.items.length === 0 ? (
        <Notice tone="warn">There are no active consultation types. Create one under Scheduling → Consultation types first.</Notice>
      ) : (
        <form onSubmit={submit} noValidate className="max-w-3xl space-y-6">
          <Panel title="Consultation">
            <div className="space-y-5">
              <Select
                label="Consultation"
                required
                placeholder="Choose…"
                value={typeSlug}
                onChange={(e) => chooseType(e.target.value)}
                options={types.data!.items.map((t) => ({ value: t.slug, label: `${t.title} · ${t.duration_minutes} min` }))}
                error={errors.type}
              />
              {type && (
                <div className="grid gap-5 sm:grid-cols-2">
                  <Select label="Format" required placeholder="Choose…" value={format} onChange={(e) => setFormat(e.target.value)} options={type.formats.map((f) => ({ value: f, label: FORMAT_LABEL[f] ?? f }))} error={errors.format} />
                  {format === 'in_person' && (
                    <Select
                      label="City"
                      required
                      placeholder="Choose…"
                      value={cityId}
                      onChange={(e) => setCityId(e.target.value)}
                      options={(cities.data?.items ?? []).filter((c) => c.is_active).map((c) => ({ value: String(c.id), label: `${c.name}, ${c.country}` }))}
                      error={errors.city_id}
                    />
                  )}
                </div>
              )}
            </div>
          </Panel>

          <Panel title="Time">
            <TimeChooser typeSlug={type?.slug ?? null} value={when} onChange={setWhen} error={errors.when} />
            <p className="mt-3 text-xs text-slate-400">The time zone is used for the client’s confirmation and reminders.</p>
          </Panel>

          {type && Boolean(type.requires_payment) && (
            <Panel title="Payment">
              <div className="space-y-5">
                <Toggle label="Complimentary — no payment required" checked={exempt} onChange={(e) => setExempt(e.target.checked)} hint="When off, the client is emailed a secure link to pay before the booking is confirmed." />
                {!exempt &&
                  (type.prices.length === 0 ? (
                    <Notice tone="warn">This consultation has no prices configured. Add prices to the consultation type, or mark this booking as complimentary.</Notice>
                  ) : (
                    <Select
                      label="Currency"
                      required
                      placeholder="Choose…"
                      value={currency}
                      onChange={(e) => setCurrency(e.target.value)}
                      options={type.prices.map((p) => ({ value: p.currency, label: `${p.currency} · ${formatMoney(p)}` }))}
                      error={errors.currency}
                      hint={price ? 'Tax is applied according to the consultation type’s settings.' : undefined}
                    />
                  ))}
              </div>
            </Panel>
          )}

          <Panel title="Client">
            <div className="grid gap-5 sm:grid-cols-2">
              <Input label="Full name" required autoComplete="off" value={name} onChange={(e) => setName(e.target.value)} error={errors.name} maxLength={190} />
              <Input label="Email" type="email" required autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email} />
              <Input label="Phone" type="tel" autoComplete="off" value={phone} onChange={(e) => setPhone(e.target.value)} error={errors.phone} hint="International format, e.g. +44 20 7946 0000" />
              <Textarea label="Private notes" className="sm:col-span-2" rows={3} maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} error={errors.notes} hint="Stored encrypted; visible to your team only." />
            </div>
          </Panel>

          {formError && <Notice tone="error">{formError}</Notice>}
          <div className="flex justify-end gap-3">
            <AButton variant="ghost" onClick={() => navigate('/admin/appointments')}>
              Cancel
            </AButton>
            <AButton type="submit" variant="primary" loading={create.isPending}>
              {needsPayment ? 'Book and request payment' : 'Book and confirm'}
            </AButton>
          </div>
        </form>
      )}
    </>
  )
}
