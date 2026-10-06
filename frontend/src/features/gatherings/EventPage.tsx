import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate, useParams } from 'react-router'
import { z } from 'zod'
import { Checkbox, FormAlert, SelectInput, TextArea, TextInput } from '../../components/form/Fields'
import { PrivacyNoticeLink } from '../../components/form/PrivacyNoticeLink'
import { Seo, SITE_URL } from '../../components/seo/Seo'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { ArchFrame } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { RichText } from '../../components/ui/RichText'
import { ErrorBlock, isNotFound, LoadingBlock } from '../../components/ui/States'
import { EditorialTitle } from '../../components/ui/Typography'
import { storeToken } from '../../lib/accessTokens'
import { api, ApiError } from '../../lib/api'
import { countryOptions } from '../../lib/countries'
import { browserTimeZone, dateParts, formatDateTime, formatMoney, formatNoticePeriod, formatTime, paragraphs } from '../../lib/format'
import { applyServerErrors, PHONE_MESSAGE, PHONE_PATTERN } from '../../lib/forms'
import { useEvent, useSettings } from '../../lib/queries'
import { breadcrumbLd, eventLd } from '../../lib/structuredData'
import type { EventDetail } from '../../lib/types'
import { NotFoundPage } from '../../pages/NotFoundPage'

const schema = z.object({
  name: z.string().trim().min(2, 'Please enter your full name.').max(190),
  email: z.string().trim().email('Enter a valid email address.').max(190),
  phone: z.string().trim().regex(PHONE_PATTERN, PHONE_MESSAGE).or(z.literal('')),
  country: z.string().regex(/^[A-Z]{2}$/).or(z.literal('')),
  seats: z.string().regex(/^[1-4]$/),
  notes: z.string().trim().max(2000).optional(),
  consent_privacy: z.literal(true, { message: 'Please confirm you have read the privacy notice.' }),
})
type RegistrationForm = z.infer<typeof schema>
const FIELDS = ['name', 'email', 'phone', 'country', 'seats', 'notes', 'consent_privacy'] as const

export const REGISTRATION_STATUS: Record<string, { title: string; body: string }> = {
  confirmed: { title: 'Your place is confirmed.', body: 'A confirmation has been sent to your email with the details you will need.' },
  pending_payment: { title: 'Your place is held.', body: 'Complete payment to confirm your place.' },
  pending_application: { title: 'Thank you for your interest.', body: 'Places at this gathering are offered by application. The private office will be in touch personally.' },
  waitlisted: { title: 'You are on the waitlist.', body: 'This gathering is currently full. Should a place become available, you will be emailed straight away. Nothing has been charged.' },
  expired: { title: 'Your held place has lapsed.', body: 'Payment was not completed while your place was held. If a place is still available you can complete payment below.' },
  cancelled: { title: 'This registration has been cancelled.', body: 'Should you wish to attend a future gathering, the private office will be glad to assist.' },
  refunded: { title: 'This registration has been refunded.', body: 'The refund has been issued to your original payment method.' },
}

export default function EventPage() {
  const slug = useParams().slug ?? ''
  const event = useEvent(slug)

  if (event.isLoading) return <LoadingBlock className="min-h-[90vh]" />
  if (event.isError) return isNotFound(event.error) ? <NotFoundPage /> : <ErrorBlock className="min-h-[90vh]" error={event.error} onRetry={() => event.refetch()} />

  const e = event.data!
  const parts = dateParts(e.starts_at, e.timezone)
  const viewerTz = browserTimeZone()
  const place = [e.venue, e.city?.name].filter(Boolean).join(', ')
  const upcoming = new Date(e.ends_at).getTime() > Date.now()

  return (
    <>
      <Seo
        title={e.title}
        description={e.summary}
        image={e.cover?.url}
        override={e.seo}
        type="event"
        jsonLd={[
          eventLd(SITE_URL, e),
          breadcrumbLd(SITE_URL, [
            { name: 'Home', path: '/' },
            { name: 'Gatherings', path: '/gatherings' },
            { name: e.title, path: `/gatherings/${e.slug}` },
          ]),
        ]}
      />
      <article>
        <header className="grain relative overflow-hidden bg-midnight-950 pb-20 pt-[calc(var(--header-h)+4rem)] md:pb-28 md:pt-[calc(var(--header-h)+6rem)]">
          <div aria-hidden="true" className="pointer-events-none absolute -right-[10%] top-0 h-[60vh] w-[50vw] rounded-full bg-emerald-700/20 blur-[140px]" />
          <Container className="relative">
            <nav aria-label="Breadcrumb" className="mb-10">
              <ol className="flex flex-wrap items-center gap-3 text-[0.66rem] uppercase tracking-[0.24em] text-slate-400">
                <li>
                  <ButtonLink to="/gatherings" variant="ghost" size="sm" className="!px-0 !py-0 !text-[0.66rem]">
                    ← Gatherings
                  </ButtonLink>
                </li>
              </ol>
            </nav>
            <div className="grid items-end gap-14 lg:grid-cols-12 lg:gap-10">
              <Reveal className="lg:col-span-7">
                <p className="eyebrow flex items-center gap-4">
                  <span aria-hidden="true" className="h-px w-10 bg-champagne-400/70" />
                  {parts.weekday} · {parts.day} {parts.month} {parts.year}
                </p>
                <h1 className="mt-8 text-display font-light">
                  <EditorialTitle text={e.title} />
                </h1>
                {e.summary && <p className="mt-10 max-w-2xl text-body-l font-light text-ivory-200/85">{e.summary}</p>}
                {e.status === 'cancelled' && (
                  <div className="mt-10 max-w-xl">
                    <FormAlert>This gathering has been cancelled. Registered guests have been contacted personally.</FormAlert>
                  </div>
                )}
              </Reveal>
              {e.cover && (
                <Reveal delay={0.15} className="mx-auto w-full max-w-xs lg:col-span-4 lg:col-start-9 lg:max-w-none">
                  <ArchFrame media={e.cover} ratio={3 / 4} priority sizes="(min-width: 1024px) 30vw, 70vw" />
                </Reveal>
              )}
            </div>
          </Container>
        </header>

        <section className="bg-midnight-900 py-20 md:py-28">
          <Container>
            <div className="grid gap-16 lg:grid-cols-12 lg:gap-10">
              <div className="lg:col-span-7">
                <dl className="divide-y divide-[var(--line)] border-y border-[var(--line)]">
                  <Detail label="When">
                    <span className="block text-ivory-50">
                      {formatDateTime(e.starts_at, e.timezone)} – {formatTime(e.ends_at, e.timezone)}
                    </span>
                    {viewerTz !== e.timezone && <span className="mt-1 block text-xs text-slate-400">{formatDateTime(e.starts_at, viewerTz)} where you are</span>}
                  </Detail>
                  {place && <Detail label="Where">{place}</Detail>}
                  {e.address && <Detail label="Address">{e.address}</Detail>}
                  {e.prices.length > 0 && (
                    <Detail label="Contribution">
                      {e.prices.map((p) => formatMoney(p)).join(' · ')} per guest
                      {e.tax && <span className="mt-1 block text-xs text-slate-400">{taxLine(e.tax)}</span>}
                    </Detail>
                  )}
                  {e.seats_left !== null && e.status !== 'cancelled' && upcoming && (
                    <Detail label="Places">{e.seats_left > 0 ? `${e.seats_left} remaining` : e.waitlist_enabled ? 'Currently full — waitlist open' : 'Fully booked'}</Detail>
                  )}
                  {upcoming && e.status !== 'cancelled' && (e.registration_opens_at || e.registration_closes_at) && (
                    <Detail label="Registration">
                      {e.registration_opens_at && new Date(e.registration_opens_at).getTime() > Date.now() && <span className="block">Opens {formatDateTime(e.registration_opens_at, e.timezone)}</span>}
                      {e.registration_closes_at && <span className="block">Closes {formatDateTime(e.registration_closes_at, e.timezone)}</span>}
                    </Detail>
                  )}
                </dl>

                <RichText html={e.description} className="mt-14" />

                {e.requirements && (
                  <div className="mt-14 border-l border-champagne-400/50 pl-8">
                    <p className="eyebrow">Before you attend</p>
                    {paragraphs(e.requirements).map((p, i) => (
                      <p key={i} className="mt-4 font-light leading-relaxed text-ivory-200/80">
                        {p}
                      </p>
                    ))}
                  </div>
                )}
              </div>

              <aside className="lg:col-span-5">
                <div className="lg:sticky lg:top-[calc(var(--header-h)+var(--ribbon-h)+3rem)]">
                  <RegistrationPanel event={e} upcoming={upcoming} />
                </div>
              </aside>
            </div>
          </Container>
        </section>
      </article>
    </>
  )
}

function taxLine(tax: NonNullable<EventDetail['tax']>): string {
  const rate = (tax.rate_bp / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })
  return `${tax.inclusive ? 'Includes' : 'Plus'} ${tax.label ?? 'tax'} at ${rate}%`
}

/** Mirrors the server's calculation (BookingService::computeAmounts) for display only. */
function totalWithTax(subtotal: number, tax: EventDetail['tax']): { tax: number; total: number } {
  if (!tax || tax.rate_bp <= 0) return { tax: 0, total: subtotal }
  if (tax.inclusive) return { tax: Math.round((subtotal * tax.rate_bp) / (10000 + tax.rate_bp)), total: subtotal }
  const t = Math.round((subtotal * tax.rate_bp) / 10000)
  return { tax: t, total: subtotal + t }
}

function policyText(e: EventDetail): string | null {
  if (e.cancellation_policy) return e.cancellation_policy
  if (e.prices.length === 0) return null
  if (e.refund_on_cancel_percent <= 0) return 'Contributions are not refundable if you cancel, but you may still release your place for another guest.'
  const share = e.refund_on_cancel_percent >= 100 ? 'a full refund' : `a ${e.refund_on_cancel_percent}% refund`
  return e.cancellation_window_hours > 0
    ? `Cancel at least ${formatNoticePeriod(e.cancellation_window_hours)} before the gathering for ${share}. Should the gathering be cancelled, you are refunded in full.`
    : `Cancel before the gathering begins for ${share}. Should the gathering be cancelled, you are refunded in full.`
}

function RegistrationPanel({ event: e, upcoming }: { event: EventDetail; upcoming: boolean }) {
  const settings = useSettings()
  const contact = settings.data?.['contact.email']
  const state = upcoming ? e.registration_state : 'closed'

  if (state === 'not_yet_open') {
    return (
      <Panel title="Registration opens soon.">
        <p className="mt-4 text-sm font-light text-ivory-200/80">
          Places can be reserved from {e.registration_opens_at ? formatDateTime(e.registration_opens_at, e.timezone) : 'a later date'}.
        </p>
      </Panel>
    )
  }

  if (!['open', 'application', 'waitlist'].includes(state)) {
    const message =
      state === 'cancelled'
        ? 'This gathering will not take place.'
        : !upcoming
          ? 'This gathering has taken place.'
          : state === 'invitation'
            ? 'Places at this gathering are offered by personal invitation.'
            : state === 'full'
              ? 'This gathering is fully booked.'
              : 'Registration for this gathering is closed.'
    return (
      <Panel title={message}>
        {contact && upcoming && state !== 'cancelled' && (
          <p className="mt-4 text-sm font-light text-ivory-200/80">
            For enquiries, the private office is at{' '}
            <a className="link-underline text-ivory-50" href={`mailto:${contact}`}>
              {contact}
            </a>
            .
          </p>
        )}
        <div className="mt-8">
          <ButtonLink to="/gatherings" variant="link">
            View other gatherings
          </ButtonLink>
        </div>
      </Panel>
    )
  }

  return <RegistrationForm event={e} />
}

function RegistrationForm({ event: e }: { event: EventDetail }) {
  const navigate = useNavigate()
  const settings = useSettings()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, watch, formState } = useForm<RegistrationForm>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', email: '', phone: '', country: '', seats: '1', notes: '' } as Partial<RegistrationForm> as RegistrationForm,
    mode: 'onTouched',
  })
  const errors = formState.errors
  const seats = Number(watch('seats') || 1)
  const full = e.registration_state === 'waitlist'
  const byApplication = e.registration_state === 'application'
  const maxSeats = Math.max(1, Math.min(4, !full && e.seats_left && e.seats_left > 0 ? e.seats_left : 4))
  const paid = e.prices.length > 0
  const needsPayment = paid && !byApplication && !full
  const payable = e.prices.filter((p) => p.payable)
  const choices = needsPayment ? payable : e.prices
  const preferred = settings.data?.['currencies.default']
  const [chosen, setChosen] = useState<string | null>(null)
  const price = choices.find((p) => p.currency === chosen) ?? choices.find((p) => p.currency === preferred) ?? choices[0] ?? null
  const paymentUnavailable = needsPayment && payable.length === 0
  const amounts = price ? totalWithTax(price.amount_minor * seats, e.tax) : null
  const policy = policyText(e)
  const contactEmail = settings.data?.['contact.email']

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      const created = await api.post<{ reference: string; access_token: string; status: string }>(`/events/${encodeURIComponent(e.slug)}/reserve`, {
        name: values.name,
        email: values.email,
        phone: values.phone || undefined,
        country: values.country || undefined,
        notes: values.notes || undefined,
        seats: Number(values.seats),
        currency: price?.currency,
        consent_privacy: true,
      })
      storeToken('registration', created.reference, created.access_token)
      navigate(`/gatherings/registration/${created.reference}`)
    } catch (err) {
      const message = applyServerErrors(err, setError, FIELDS)
      if (message) setFormError(err instanceof ApiError && err.code === 'already_registered' ? 'A registration already exists for this email address. Please check your inbox for your confirmation.' : message)
    }
  })

  const cta = full ? 'Join the waitlist' : byApplication ? 'Request a place' : needsPayment ? 'Reserve and continue to payment' : 'Confirm my place'

  return (
    <Panel title={full ? 'Join the waitlist.' : byApplication ? 'Request a place.' : 'Reserve your place.'}>
      <p className="mt-4 text-sm font-light text-ivory-200/75">
        {byApplication
          ? 'Places are offered by application. Share a few words and the private office will reply personally.'
          : full
            ? paid
              ? 'This gathering is full. Join the waitlist and, should a place become available, you will be emailed a link to pay and confirm it. Nothing is charged now.'
              : 'This gathering is full. Join the waitlist and you will be emailed should a place become available.'
            : needsPayment
              ? 'Your place is held briefly while you complete payment.'
              : 'There is no charge for this gathering.'}
      </p>
      <form onSubmit={onSubmit} noValidate className="mt-10 space-y-8">
        <TextInput label="Full name" autoComplete="name" required error={errors.name?.message} {...register('name')} />
        <TextInput label="Email" type="email" autoComplete="email" inputMode="email" required error={errors.email?.message} {...register('email')} />
        <TextInput label="Phone" type="tel" autoComplete="tel" inputMode="tel" placeholder="+91 98765 43210" error={errors.phone?.message} {...register('phone')} />
        <SelectInput label="Country of residence" autoComplete="country" placeholder="Select…" options={countryOptions()} hint="Optional." error={errors.country?.message} {...register('country')} />
        {paid && choices.length > 1 && (
          <SelectInput
            label="Pay in"
            required
            value={price?.currency ?? ''}
            onChange={(ev) => setChosen(ev.target.value)}
            options={choices.map((p) => ({ value: p.currency, label: `${p.currency} · ${formatMoney(p)} per guest` }))}
            hint="Amounts are set separately per currency; no conversion is applied."
          />
        )}
        <SelectInput
          label="Places"
          required
          options={Array.from({ length: maxSeats }, (_, i) => ({ value: String(i + 1), label: i === 0 ? '1 guest' : `${i + 1} guests` }))}
          error={errors.seats?.message}
          {...register('seats')}
        />
        <TextArea
          label={byApplication ? 'A few words about your interest' : 'Anything we should know'}
          rows={3}
          maxLength={2000}
          currentLength={(watch('notes') ?? '').length}
          hint={byApplication ? undefined : 'Optional — e.g. accessibility needs.'}
          error={errors.notes?.message}
          {...register('notes')}
        />
        <Checkbox
          label={
            <>
              I have read the <PrivacyNoticeLink /> and agree that my details may be used to arrange my attendance.
            </>
          }
          error={errors.consent_privacy?.message}
          {...register('consent_privacy')}
        />
        {needsPayment && price && amounts && (
          <div className="border-t border-[var(--line)] pt-6 text-sm font-light text-ivory-200/80">
            <p className="flex items-baseline justify-between">
              <span>Total</span>
              <span className="font-display text-2xl text-ivory-50">{formatMoney({ currency: price.currency, amount_minor: amounts.total })}</span>
            </p>
            {e.tax && amounts.tax > 0 && (
              <p className="mt-1 text-right text-xs text-slate-400">
                {e.tax.inclusive ? 'Includes' : 'Plus'} {formatMoney({ currency: price.currency, amount_minor: amounts.tax })} {e.tax.label ?? 'tax'}
              </p>
            )}
          </div>
        )}
        {policy && !byApplication && <p className="text-xs font-light leading-relaxed text-slate-400">{policy}</p>}
        {paymentUnavailable && (
          <FormAlert tone="info">
            Online payment for this gathering is not available at present, so places cannot be reserved online.{' '}
            {contactEmail ? (
              <>
                Please write to{' '}
                <a className="link-underline text-ivory-50" href={`mailto:${contactEmail}`}>
                  {contactEmail}
                </a>
                .
              </>
            ) : (
              'Please contact the private office.'
            )}
          </FormAlert>
        )}
        {formError && <FormAlert>{formError}</FormAlert>}
        <Button type="submit" arrow loading={formState.isSubmitting} disabled={paymentUnavailable} className="w-full">
          {cta}
        </Button>
      </form>
    </Panel>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="border border-[var(--line-strong)] bg-midnight-950/60 p-8 md:p-10">
      <h2 className="font-display text-3xl text-ivory-50">{title}</h2>
      {children}
    </div>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-2 py-5 sm:grid-cols-[9rem_1fr] sm:gap-6">
      <dt className="text-[0.66rem] uppercase tracking-[0.22em] text-slate-400">{label}</dt>
      <dd className="font-light text-ivory-200/90">{children}</dd>
    </div>
  )
}
