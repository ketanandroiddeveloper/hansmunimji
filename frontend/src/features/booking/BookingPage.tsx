import { zodResolver } from '@hookform/resolvers/zod'
import { useQueries, useQueryClient } from '@tanstack/react-query'
import { motion } from 'framer-motion'
import { useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode, RefObject } from 'react'
import { Controller, useForm, type UseFormReturn } from 'react-hook-form'
import { useNavigate, useSearchParams } from 'react-router'
import { z } from 'zod'
import { Checkbox, ChoiceGroup, FormAlert, SelectInput, TextArea, TextInput } from '../../components/form/Fields'
import { PrivacyNoticeLink } from '../../components/form/PrivacyNoticeLink'
import { StepIndicator } from '../../components/form/StepIndicator'
import { Seo } from '../../components/seo/Seo'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { ConfidentialitySeal } from '../../components/ui/Ornaments'
import { EASE } from '../../components/ui/Reveal'
import { EmptyState, ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { captureInviteToken, storeToken } from '../../lib/accessTokens'
import { api, ApiError, idempotencyKey } from '../../lib/api'
import { countryOptions } from '../../lib/countries'
import { browserTimeZone, formatDateTime, formatMoney } from '../../lib/format'
import { applyServerErrors, PHONE_MESSAGE, PHONE_PATTERN } from '../../lib/forms'
import { fetchGateways } from '../../lib/payments'
import { useAppointmentTypes, useCities, useSettings } from '../../lib/queries'
import type { AppointmentType, MeetingFormat } from '../../lib/types'
import { MEETING_FORMAT } from './labels'
import { SlotPicker, type Slot } from './SlotPicker'

const detailsSchema = z
  .object({
    format: z.enum(['google_meet', 'phone', 'in_person'], { message: 'Choose how you would like to meet.' }),
    city_id: z.string().optional(),
    name: z.string().trim().min(2, 'Please enter your full name.').max(190),
    email: z.string().trim().email('Enter a valid email address.').max(190),
    phone: z.string().trim().regex(PHONE_PATTERN, PHONE_MESSAGE).or(z.literal('')),
    country: z.string().regex(/^[A-Z]{2}$/).or(z.literal('')),
    notes: z.string().trim().max(2000).optional(),
    consent_privacy: z.literal(true, { message: 'Please confirm you have read the privacy notice.' }),
  })
  .superRefine((v, ctx) => {
    if (v.format === 'in_person' && !v.city_id) ctx.addIssue({ code: 'custom', path: ['city_id'], message: 'Choose a city for an in-person meeting.' })
  })

type DetailsForm = z.infer<typeof detailsSchema>
const DETAIL_FIELDS = ['format', 'city_id', 'name', 'email', 'phone', 'country', 'notes', 'consent_privacy'] as const

interface CreatedBooking {
  reference: string
  access_token: string
  status: string
  hold_expires_at: string | null
}

const STEP_LABELS = ['Session', 'Time', 'Details']

export default function BookingPage() {
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [inviteToken] = useState(() => captureInviteToken())
  const types = useAppointmentTypes(undefined, inviteToken)
  const settings = useSettings()

  const typeSlug = params.get('type')
  const type = types.data?.find((t) => t.slug === typeSlug) ?? (types.data?.length === 1 ? types.data[0] : undefined)

  const [step, setStep] = useState(0)
  const [timezone, setTimezone] = useState(browserTimeZone)
  const [slot, setSlot] = useState<Slot | null>(null)
  const [currency, setCurrency] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const headingRef = useRef<HTMLHeadingElement>(null)
  const settled = useRef(false)
  const suppressFocus = useRef(true)

  const form = useForm<DetailsForm>({
    resolver: zodResolver(detailsSchema),
    defaultValues: { city_id: '', name: '', email: '', phone: '', country: '', notes: '' } as Partial<DetailsForm> as DetailsForm,
    mode: 'onTouched',
  })

  // A preselected type skips straight to choosing a time (without moving focus on page load).
  useEffect(() => {
    if (settled.current || !types.data) return
    settled.current = true
    if (type) {
      suppressFocus.current = true
      setStep(1)
    }
  }, [type, types.data])

  useEffect(() => {
    if (suppressFocus.current) {
      suppressFocus.current = false
      return
    }
    headingRef.current?.focus({ preventScroll: true })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }, [step])

  useEffect(() => {
    if (type?.formats.length === 1) form.setValue('format', type.formats[0])
  }, [type, form])

  const priceCurrencies = useMemo(() => type?.prices.map((p) => p.currency) ?? [], [type])
  const gatewayQueries = useQueries({
    queries: (type?.requires_payment ? priceCurrencies : []).map((c) => ({
      queryKey: ['gateways', c],
      queryFn: () => fetchGateways(c),
      staleTime: 5 * 60_000,
    })),
  })
  const gatewaysLoading = gatewayQueries.some((q) => q.isLoading)
  const payableCurrencies = type?.requires_payment ? priceCurrencies.filter((_, i) => (gatewayQueries[i]?.data?.length ?? 0) > 0) : []
  const payableKey = payableCurrencies.join(',')

  useEffect(() => {
    if (!type?.requires_payment) return setCurrency(null)
    const list = payableKey ? payableKey.split(',') : []
    if (currency && list.includes(currency)) return
    const preferred = settings.data?.['currencies.default']
    setCurrency(preferred && list.includes(preferred) ? preferred : (list[0] ?? null))
  }, [type?.requires_payment, payableKey, settings.data, currency])

  function chooseType(slug: string) {
    const next = new URLSearchParams(params)
    next.set('type', slug)
    setParams(next, { replace: true })
    setSlot(null)
    setNotice(null)
    setStep(1)
  }

  if (types.isLoading) return <LoadingBlock className="min-h-[90vh]" label="Preparing availability" />
  if (types.isError) return <ErrorBlock className="min-h-[90vh]" error={types.error} onRetry={() => types.refetch()} />

  const price = type?.requires_payment && currency ? type.prices.find((p) => p.currency === currency) : undefined
  const paymentUnavailable = Boolean(type?.requires_payment && !gatewaysLoading && payableCurrencies.length === 0)

  return (
    <>
      <Seo title="Book a consultation" description="Choose a time for a private consultation." noindex={Boolean(inviteToken)} />
      <section className="grain relative min-h-screen bg-midnight-950 pb-28 pt-[calc(var(--header-h)+4rem)]">
        <div aria-hidden="true" className="pointer-events-none absolute -right-[10%] top-0 h-[60vh] w-[50vw] rounded-full bg-emerald-700/15 blur-[140px]" />
        <Container className="relative">
          <header className="max-w-3xl">
            <p className="eyebrow flex items-center gap-4">
              <span aria-hidden="true" className="h-px w-10 bg-champagne-400/70" />
              {inviteToken ? 'By private invitation' : 'Consultation'}
            </p>
            <h1 className="mt-8 text-h1">
              Arrange a time, <em className="font-light text-champagne-200 italic">at your discretion.</em>
            </h1>
          </header>

          {!types.data?.length ? (
            <div className="mt-20">
              <EmptyState title="Consultations are arranged by private invitation.">
                <p>Begin with a confidential application and the private office will invite you to choose a time.</p>
                <div className="mt-8">
                  <ButtonLink to="/private-access" variant="outline">
                    Request private access
                  </ButtonLink>
                </div>
              </EmptyState>
            </div>
          ) : (
            <div className="mt-16 grid gap-16 lg:grid-cols-12 lg:gap-10">
              <div className="lg:col-span-8">
                <StepIndicator steps={STEP_LABELS} current={step} label="Booking progress" />
                {notice && (
                  <div className="mt-10">
                    <FormAlert>{notice}</FormAlert>
                  </div>
                )}
                {paymentUnavailable && step === 1 && (
                  <div className="mt-10">
                    <FormAlert tone="info">
                      Online payment for this session is not available at present, so it cannot be reserved online.{' '}
                      {settings.data?.['contact.email'] ? (
                        <>
                          Please write to{' '}
                          <a className="link-underline text-ivory-50" href={`mailto:${settings.data['contact.email']}`}>
                            {settings.data['contact.email']}
                          </a>{' '}
                          and the private office will arrange a time with you.
                        </>
                      ) : (
                        'Please contact the private office to arrange a time.'
                      )}
                    </FormAlert>
                  </div>
                )}
                <motion.div key={step} className="mt-14" initial={{ opacity: 0, x: 24 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.5, ease: EASE }}>
                    {step === 0 && (
                      <>
                        <StepTitle refEl={headingRef} title="Choose a session." intro="Each session is held one-to-one, in complete confidence." />
                        <TypeList types={types.data} selected={type?.slug} currency={currency} onSelect={chooseType} />
                      </>
                    )}

                    {step === 1 && type && (
                      <>
                        <StepTitle refEl={headingRef} title="Choose a time." intro="Times are shown in your time zone. Select a day, then a starting time." />
                        <SlotPicker
                          typeSlug={type.slug}
                          maxAdvanceDays={type.max_advance_days}
                          timezone={timezone}
                          onTimezoneChange={setTimezone}
                          value={slot}
                          onChange={setSlot}
                          auth={{ inviteToken }}
                        />
                        <div className="mt-14 flex flex-wrap items-center justify-between gap-6 border-t border-[var(--line)] pt-8">
                          {(types.data.length ?? 0) > 1 ? (
                            <Button variant="ghost" size="sm" className="!px-0" onClick={() => setStep(0)}>
                              ← Change session
                            </Button>
                          ) : (
                            <span />
                          )}
                          <Button arrow disabled={!slot} onClick={() => setStep(2)}>
                            Continue
                          </Button>
                        </div>
                      </>
                    )}

                    {step === 2 && type && slot && (
                      <>
                        <StepTitle refEl={headingRef} title="Your details." intro="Used only to confirm and prepare your session. Stored encrypted and visible only to the private office." />
                        <DetailsStep
                          form={form}
                          type={type}
                          slot={slot}
                          timezone={timezone}
                          currency={currency}
                          paymentUnavailable={paymentUnavailable}
                          inviteToken={inviteToken}
                          onBack={() => setStep(1)}
                          onSlotLost={(message) => {
                            setSlot(null)
                            setNotice(message)
                            queryClient.invalidateQueries({ queryKey: ['availability', type.slug] })
                            setStep(1)
                          }}
                          onCreated={(created) => {
                            storeToken('appointment', created.reference, created.access_token)
                            queryClient.invalidateQueries({ queryKey: ['availability', type.slug] })
                            navigate(`/consultation/${created.reference}`, { replace: false })
                          }}
                        />
                      </>
                    )}
                </motion.div>
              </div>

              <aside className="lg:col-span-4">
                <div className="lg:sticky lg:top-[calc(var(--header-h)+var(--ribbon-h)+3rem)]">
                  <Summary
                    type={type}
                    slot={slot}
                    timezone={timezone}
                    price={price ?? null}
                    currencies={payableCurrencies}
                    currency={currency}
                    onCurrencyChange={setCurrency}
                    paymentUnavailable={paymentUnavailable}
                    contactEmail={settings.data?.['contact.email']}
                  />
                </div>
              </aside>
            </div>
          )}
        </Container>
      </section>
    </>
  )
}

function StepTitle({ refEl, title, intro }: { refEl: RefObject<HTMLHeadingElement | null>; title: string; intro: string }) {
  return (
    <div className="mb-12">
      <h2 ref={refEl} tabIndex={-1} className="text-h2 outline-none">
        {title}
      </h2>
      <p className="mt-4 max-w-xl font-light text-ivory-200/75">{intro}</p>
    </div>
  )
}

function TypeList({ types, selected, currency, onSelect }: { types: AppointmentType[]; selected?: string; currency: string | null; onSelect: (slug: string) => void }) {
  return (
    <ul className="divide-y divide-[var(--line)] border-y border-[var(--line)]">
      {types.map((t) => {
        const price = t.requires_payment ? (t.prices.find((p) => p.currency === currency) ?? t.prices[0]) : null
        const active = t.slug === selected
        return (
          <li key={t.slug}>
            <button
              type="button"
              onClick={() => onSelect(t.slug)}
              aria-current={active || undefined}
              className="group grid w-full gap-4 py-8 text-left sm:grid-cols-[1fr_auto] sm:items-end sm:gap-10"
            >
              <span>
                {t.service && <span className="eyebrow block">{t.service.title}</span>}
                <span className={`mt-3 block font-display text-3xl transition-colors duration-300 ${active ? 'text-champagne-200' : 'text-ivory-50 group-hover:text-champagne-200'}`}>{t.title}</span>
                {t.description && <span className="mt-3 block max-w-xl text-sm font-light text-ivory-200/70">{t.description}</span>}
                <span className="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-xs uppercase tracking-[0.2em] text-slate-400">
                  <span>{t.duration_minutes} minutes</span>
                  {t.formats.map((f) => (
                    <span key={f}>{MEETING_FORMAT[f].label}</span>
                  ))}
                </span>
              </span>
              <span className="flex items-center gap-6 sm:flex-col sm:items-end sm:gap-3">
                {price && <span className="font-display text-2xl text-ivory-50">{formatMoney(price)}</span>}
                {!t.requires_payment && <span className="text-xs uppercase tracking-[0.2em] text-slate-400">Complimentary</span>}
                <span className="text-[0.68rem] uppercase tracking-[0.24em] text-champagne-200">Select →</span>
              </span>
            </button>
          </li>
        )
      })}
    </ul>
  )
}

function DetailsStep({
  form,
  type,
  slot,
  timezone,
  currency,
  paymentUnavailable,
  inviteToken,
  onBack,
  onSlotLost,
  onCreated,
}: {
  form: UseFormReturn<DetailsForm>
  type: AppointmentType
  slot: Slot
  timezone: string
  currency: string | null
  paymentUnavailable: boolean
  inviteToken: string | null
  onBack: () => void
  onSlotLost: (message: string) => void
  onCreated: (created: CreatedBooking) => void
}) {
  const cities = useCities()
  const [formError, setFormError] = useState<string | null>(null)
  // One key per intended booking, so a retried request after a network drop cannot double-book.
  const key = useMemo(() => idempotencyKey(), [slot.starts_at, type.slug, currency])

  const { register, control, handleSubmit, watch, setError, formState } = form
  const errors = formState.errors
  const format = watch('format')
  const notes = watch('notes') ?? ''

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      const created = await api.post<CreatedBooking>(
        '/appointments',
        {
          type: type.slug,
          starts_at: slot.starts_at,
          timezone,
          format: values.format,
          currency: type.requires_payment ? currency : undefined,
          name: values.name,
          email: values.email,
          phone: values.phone || undefined,
          country: values.country || undefined,
          notes: values.notes || undefined,
          city_id: values.format === 'in_person' && values.city_id ? Number(values.city_id) : undefined,
          consent_privacy: true,
        },
        { headers: { 'Idempotency-Key': key, ...(inviteToken ? { 'X-Invite-Token': inviteToken } : {}) } },
      )
      onCreated(created)
    } catch (e) {
      if (e instanceof ApiError && e.code === 'slot_unavailable') return onSlotLost(e.message)
      const message = applyServerErrors(e, setError, DETAIL_FIELDS)
      if (message) setFormError(message)
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-10">
      <Controller
        control={control}
        name="format"
        render={({ field }) => (
          <ChoiceGroup
            legend="How you would like to meet"
            name="format"
            required
            columns={type.formats.length >= 3 ? 3 : type.formats.length === 1 ? 1 : 2}
            value={field.value}
            onChange={(v) => field.onChange(v as MeetingFormat)}
            options={type.formats.map((f) => ({ value: f, label: MEETING_FORMAT[f].label, description: MEETING_FORMAT[f].description }))}
            error={errors.format?.message}
          />
        )}
      />
      {format === 'in_person' && (
        <SelectInput
          label="City"
          required
          placeholder="Select a city…"
          options={(cities.data ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
          hint="The private office will confirm the exact venue with you."
          error={errors.city_id?.message}
          {...register('city_id')}
        />
      )}
      <TextInput label="Full name" autoComplete="name" required error={errors.name?.message} {...register('name')} />
      <div className="grid gap-10 sm:grid-cols-2">
        <TextInput label="Email" type="email" autoComplete="email" inputMode="email" required hint="Your confirmation and private booking link are sent here." error={errors.email?.message} {...register('email')} />
        <TextInput label="Phone" type="tel" autoComplete="tel" inputMode="tel" placeholder="+91 98765 43210" error={errors.phone?.message} {...register('phone')} />
      </div>
      <SelectInput
        label="Country of residence"
        autoComplete="country"
        placeholder="Select…"
        options={countryOptions()}
        hint={type.requires_payment ? 'Optional. Helps us offer the payment method best suited to where you are.' : 'Optional.'}
        error={errors.country?.message}
        {...register('country')}
      />
      <TextArea
        label="Anything you would like us to know"
        rows={4}
        maxLength={2000}
        currentLength={notes.length}
        hint="Optional. Please avoid sharing medical or financial details here."
        error={errors.notes?.message}
        {...register('notes')}
      />
      <Checkbox
        label={
          <>
            I have read the <PrivacyNoticeLink /> and agree that my details may be used to arrange and conduct this session.
          </>
        }
        error={errors.consent_privacy?.message}
        {...register('consent_privacy')}
      />

      {formError && <FormAlert>{formError}</FormAlert>}
      {paymentUnavailable && <FormAlert tone="info">Online payment for this session is not available at present, so it cannot be booked online. Please contact the private office.</FormAlert>}

      <div className="flex flex-wrap items-center justify-between gap-6 border-t border-[var(--line)] pt-8">
        <Button variant="ghost" size="sm" className="!px-0" onClick={onBack}>
          ← Change time
        </Button>
        <Button type="submit" arrow loading={formState.isSubmitting} disabled={paymentUnavailable}>
          {type.requires_payment ? 'Reserve and continue to payment' : 'Confirm booking'}
        </Button>
      </div>
      {type.requires_payment && !paymentUnavailable && (
        <p className="text-xs font-light text-slate-400">The time is held for you while you complete payment. The booking is confirmed once payment is verified.</p>
      )}
    </form>
  )
}

function Summary({
  type,
  slot,
  timezone,
  price,
  currencies,
  currency,
  onCurrencyChange,
  paymentUnavailable,
  contactEmail,
}: {
  type?: AppointmentType
  slot: Slot | null
  timezone: string
  price: { currency: string; amount_minor: number } | null
  currencies: string[]
  currency: string | null
  onCurrencyChange: (c: string) => void
  paymentUnavailable: boolean
  contactEmail?: string
}) {
  const tax = type?.tax
  const taxLine =
    price && tax && tax.rate_bp > 0
      ? `${tax.inclusive ? 'Includes' : 'Plus'} ${tax.label ?? 'tax'} at ${(tax.rate_bp / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}%`
      : null

  return (
    <div className="border border-[var(--line)] bg-midnight-900/50 p-8">
      <p className="eyebrow">Your session</p>
      {type ? (
        <>
          <p className="mt-5 font-display text-2xl text-ivory-50">{type.title}</p>
          <dl className="mt-8 space-y-5 text-sm">
            <Row label="Duration">{type.duration_minutes} minutes</Row>
            <Row label="When">{slot ? formatDateTime(slot.starts_at, timezone) : <span className="text-slate-400">Not yet chosen</span>}</Row>
            {type.requires_payment && (
              <Row label="Fee">
                {paymentUnavailable ? (
                  <span className="text-slate-400">Available on request{contactEmail ? ` · ${contactEmail}` : ''}</span>
                ) : price ? (
                  <span>
                    <span className="font-display text-xl text-ivory-50">{formatMoney(price)}</span>
                    {taxLine && <span className="mt-1 block text-xs text-slate-400">{taxLine}</span>}
                  </span>
                ) : (
                  <span className="text-slate-400">—</span>
                )}
              </Row>
            )}
          </dl>
          {currencies.length > 1 && currency && (
            <div className="mt-8">
              <SelectInput label="Currency" required value={currency} onChange={(e) => onCurrencyChange(e.target.value)} options={currencies.map((c) => ({ value: c, label: c }))} />
            </div>
          )}
          {(type.cancellation_policy || type.reschedule_policy) && (
            <div className="mt-8 space-y-3 border-t border-[var(--line)] pt-6 text-xs font-light leading-relaxed text-slate-400">
              {type.reschedule_policy && <p>{type.reschedule_policy}</p>}
              {type.cancellation_policy && <p>{type.cancellation_policy}</p>}
            </div>
          )}
        </>
      ) : (
        <p className="mt-5 text-sm font-light text-slate-400">Choose a session to see its details.</p>
      )}
      <ConfidentialitySeal className="mt-8" label="Confidential · Encrypted booking" />
    </div>
  )
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[6rem_1fr] gap-4">
      <dt className="text-[0.66rem] uppercase tracking-[0.22em] text-slate-400">{label}</dt>
      <dd className="font-light text-ivory-200/90">{children}</dd>
    </div>
  )
}
