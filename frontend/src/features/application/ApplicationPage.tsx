import { zodResolver } from '@hookform/resolvers/zod'
import { motion } from 'framer-motion'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { Link, useSearchParams } from 'react-router'
import { Checkbox, ChoiceGroup, FormAlert, SelectInput, TextArea, TextInput } from '../../components/form/Fields'
import { StepIndicator } from '../../components/form/StepIndicator'
import { Seo } from '../../components/seo/Seo'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { ConfidentialitySeal, OrnamentRule } from '../../components/ui/Ornaments'
import { EASE } from '../../components/ui/Reveal'
import { LoadingBlock } from '../../components/ui/States'
import { captureAccessToken, storeToken } from '../../lib/accessTokens'
import { api, ApiError } from '../../lib/api'
import { countryName, countryOptions } from '../../lib/countries'
import { applyServerErrors } from '../../lib/forms'
import { usePublishedPages, useServices } from '../../lib/queries'
import { applicationSchema, draftPayload, FORMAT_OPTIONS, REFERRAL_OPTIONS, SERVER_FIELDS, STEPS } from './schema'
import type { ApplicationField, ApplicationForm } from './schema'

type Draft = Partial<Record<ApplicationField, string>> & { reference: string; status: string; current_step: number; info_request: string | null }

const EMPTY: Partial<ApplicationForm> = {
  full_name: '',
  email: '',
  phone: '',
  country: '',
  city: '',
  designation: '',
  organization: '',
  professional_background: '',
  consultation_type: '',
  core_objective: '',
  preferred_availability: '',
  referral_details: '',
  confidential_notes: '',
  consent_communications: false,
}

export default function ApplicationPage() {
  const [params, setParams] = useSearchParams()
  const resumeRef = params.get('resume')?.toUpperCase() ?? null
  const presetService = params.get('service')
  const { data: services = [] } = useServices()
  const { data: pages } = usePublishedPages()
  const privacyPublished = pages?.some((p) => p.slug === 'privacy-policy') ?? false

  const [step, setStep] = useState(0)
  const [reference, setReference] = useState<string | null>(null)
  const [token, setToken] = useState<string | null>(null)
  const [infoRequest, setInfoRequest] = useState<string | null>(null)
  const [loadingDraft, setLoadingDraft] = useState(Boolean(resumeRef))
  const [resumeError, setResumeError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [savedAt, setSavedAt] = useState<Date | null>(null)
  const [submitted, setSubmitted] = useState<{ reference: string; previously?: boolean } | null>(null)
  const headingRef = useRef<HTMLHeadingElement>(null)

  const form = useForm<ApplicationForm>({
    resolver: zodResolver(applicationSchema),
    defaultValues: { ...EMPTY, consultation_type: presetService ?? '' } as ApplicationForm,
    mode: 'onTouched',
  })
  const { register, control, handleSubmit, trigger, getValues, setError, reset, watch, formState } = form
  const errors = formState.errors

  const typeOptions = useMemo(
    () => [...services.map((s) => ({ value: s.slug, label: s.title, description: s.subtitle ?? undefined })), { value: 'general', label: 'Not sure yet', description: 'Guidance on where to begin' }],
    [services],
  )

  // Resume an existing draft from the emailed link (?resume=REF#access=TOKEN).
  useEffect(() => {
    if (!resumeRef) return
    const t = captureAccessToken('application', resumeRef)
    if (!t) {
      setResumeError('This resume link is incomplete. Please use the full link from your email.')
      setLoadingDraft(false)
      return
    }
    let cancelled = false
    api
      .get<Draft>(`/applications/drafts/${encodeURIComponent(resumeRef)}`, { headers: { 'X-Access-Token': t } })
      .then((draft) => {
        if (cancelled) return
        const values: Partial<ApplicationForm> = { ...EMPTY }
        for (const f of SERVER_FIELDS) {
          const v = (draft as Record<string, unknown>)[f]
          if (typeof v === 'string') (values as Record<string, unknown>)[f] = v
        }
        reset(values as ApplicationForm)
        setReference(draft.reference)
        setToken(t)
        setInfoRequest(draft.info_request)
        setStep(Math.min(Math.max(draft.current_step - 1, 0), STEPS.length - 1))
      })
      .catch((e: unknown) => {
        if (cancelled) return
        if (e instanceof ApiError && e.code === 'already_submitted') {
          setSubmitted({ reference: resumeRef, previously: true })
          return
        }
        setResumeError(e instanceof ApiError ? e.message : 'We could not load your saved application.')
      })
      .finally(() => !cancelled && setLoadingDraft(false))
    return () => {
      cancelled = true
    }
  }, [resumeRef, reset])

  useEffect(() => {
    headingRef.current?.focus({ preventScroll: true })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }, [step])

  async function saveDraft(nextStep: number): Promise<boolean> {
    const payload = draftPayload(getValues(), nextStep)
    setSaving(true)
    setFormError(null)
    try {
      if (!reference || !token) {
        const created = await api.post<{ reference: string; resume_token: string }>('/applications/drafts', payload)
        storeToken('application', created.reference, created.resume_token)
        setReference(created.reference)
        setToken(created.resume_token)
        const next = new URLSearchParams(params)
        next.set('resume', created.reference)
        next.delete('service')
        setParams(next, { replace: true })
      } else {
        await api.put(`/applications/drafts/${reference}`, payload, { headers: { 'X-Access-Token': token } })
      }
      setSavedAt(new Date())
      return true
    } catch (e) {
      const message = applyServerErrors(e, setError, SERVER_FIELDS)
      if (message) setFormError(message)
      return false
    } finally {
      setSaving(false)
    }
  }

  async function next() {
    const valid = await trigger(STEPS[step].fields, { shouldFocus: true })
    if (!valid) return
    if (await saveDraft(step + 1)) setStep((s) => Math.min(s + 1, STEPS.length - 1))
  }

  const onSubmit = handleSubmit(async (values) => {
    if (!reference || !token) return
    setFormError(null)
    try {
      const body = { ...draftPayload(values, STEPS.length - 1), consent_privacy: true, consent_communications: Boolean(values.consent_communications) }
      const result = await api.post<{ reference: string; status: string }>(`/applications/drafts/${reference}/submit`, body, { headers: { 'X-Access-Token': token } })
      setSubmitted({ reference: result.reference })
    } catch (e) {
      const message = applyServerErrors(e, setError, SERVER_FIELDS)
      if (message) setFormError(message)
      const firstBad = STEPS.findIndex((s) => s.fields.some((f) => form.getFieldState(f).invalid))
      if (firstBad >= 0 && firstBad < step) setStep(firstBad)
    }
  })

  if (loadingDraft) return <LoadingBlock className="min-h-[90vh]" label="Retrieving your application" />

  if (submitted) return <Submitted reference={submitted.reference} previously={submitted.previously} />

  const current = STEPS[step]
  const values = watch()

  return (
    <>
      <Seo title="Request private access" description="A confidential application to begin working with the practice." noindex />
      <section className="grain relative min-h-screen bg-midnight-950 pb-28 pt-[calc(var(--header-h)+4rem)]">
        <div aria-hidden="true" className="pointer-events-none absolute -left-[10%] top-0 h-[60vh] w-[50vw] rounded-full bg-emerald-700/15 blur-[140px]" />
        <Container className="relative">
          <div className="grid gap-16 lg:grid-cols-12 lg:gap-10">
            <aside className="lg:col-span-4">
              <div className="lg:sticky lg:top-[calc(var(--header-h)+var(--ribbon-h)+3rem)]">
                <p className="eyebrow flex items-center gap-4">
                  <span aria-hidden="true" className="h-px w-10 bg-champagne-400/70" />
                  Private access
                </p>
                <h1 className="mt-8 text-h1">
                  An application, <em className="font-light text-champagne-200 italic">read in confidence.</em>
                </h1>
                <p className="mt-8 font-light text-ivory-200/80">
                  Every engagement begins here. The private office reads each application personally and replies within a few working days.
                </p>
                <ul className="mt-10 space-y-4 border-t border-[var(--line)] pt-8 text-sm font-light text-ivory-200/75">
                  <li className="flex gap-4">
                    <span aria-hidden="true" className="mt-2 h-px w-4 shrink-0 bg-champagne-400" />
                    Encrypted at rest and visible only to the private office.
                  </li>
                  <li className="flex gap-4">
                    <span aria-hidden="true" className="mt-2 h-px w-4 shrink-0 bg-champagne-400" />
                    Saved as you go — a private link to resume is sent to your email.
                  </li>
                  <li className="flex gap-4">
                    <span aria-hidden="true" className="mt-2 h-px w-4 shrink-0 bg-champagne-400" />
                    No payment is taken at this stage.
                  </li>
                </ul>
                <ConfidentialitySeal className="mt-10" />
              </div>
            </aside>

            <div className="lg:col-span-7 lg:col-start-6">
              {resumeError && (
                <div className="mb-10">
                  <FormAlert>{resumeError}</FormAlert>
                </div>
              )}
              {infoRequest && (
                <div className="mb-10">
                  <FormAlert tone="info">
                    <span className="eyebrow mb-2 block">Request from the private office</span>
                    {infoRequest}
                  </FormAlert>
                </div>
              )}

              <StepIndicator steps={STEPS.map((s) => s.label)} current={step} />

              <form onSubmit={onSubmit} noValidate className="mt-14" aria-labelledby="step-title">
                <motion.div key={step} initial={{ opacity: 0, x: 24 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.5, ease: EASE }}>
                    <h2 id="step-title" ref={headingRef} tabIndex={-1} className="text-h2 outline-none">
                      {current.title}
                    </h2>
                    <p className="mt-4 max-w-xl font-light text-ivory-200/75">{current.intro}</p>

                    <div className="mt-12 space-y-10">
                      {step === 0 && (
                        <>
                          <TextInput label="Full name" autoComplete="name" required error={errors.full_name?.message} {...register('full_name')} />
                          <div className="grid gap-10 sm:grid-cols-2">
                            <TextInput label="Email" type="email" autoComplete="email" inputMode="email" required error={errors.email?.message} {...register('email')} />
                            <TextInput label="Phone" type="tel" autoComplete="tel" inputMode="tel" placeholder="+91 98765 43210" required error={errors.phone?.message} {...register('phone')} />
                          </div>
                          <div className="grid gap-10 sm:grid-cols-2">
                            <SelectInput label="Country of residence" autoComplete="country" required placeholder="Select…" options={countryOptions()} error={errors.country?.message} {...register('country')} />
                            <TextInput label="City" autoComplete="address-level2" required error={errors.city?.message} {...register('city')} />
                          </div>
                        </>
                      )}

                      {step === 1 && (
                        <>
                          <div className="grid gap-10 sm:grid-cols-2">
                            <TextInput label="Role or title" autoComplete="organization-title" required error={errors.designation?.message} {...register('designation')} />
                            <TextInput label="Organisation" autoComplete="organization" required hint="Or “Private” if you prefer." error={errors.organization?.message} {...register('organization')} />
                          </div>
                          <TextArea
                            label="Professional background"
                            required
                            rows={6}
                            maxLength={3000}
                            currentLength={values.professional_background?.length ?? 0}
                            hint="Your work, responsibilities and current season of life."
                            error={errors.professional_background?.message}
                            {...register('professional_background')}
                          />
                        </>
                      )}

                      {step === 2 && (
                        <>
                          <Controller
                            control={control}
                            name="consultation_type"
                            render={({ field }) => (
                              <ChoiceGroup legend="Area of interest" name={field.name} required options={typeOptions} value={field.value} onChange={field.onChange} error={errors.consultation_type?.message} />
                            )}
                          />
                          <TextArea
                            label="What would you like this work to help with?"
                            required
                            rows={7}
                            maxLength={4000}
                            currentLength={values.core_objective?.length ?? 0}
                            error={errors.core_objective?.message}
                            {...register('core_objective')}
                          />
                          <Controller
                            control={control}
                            name="preferred_format"
                            render={({ field }) => (
                              <ChoiceGroup legend="Preferred format" name={field.name} columns={3} required options={FORMAT_OPTIONS} value={field.value} onChange={field.onChange} error={errors.preferred_format?.message} />
                            )}
                          />
                        </>
                      )}

                      {step === 3 && (
                        <>
                          <TextInput
                            label="Preferred availability"
                            hint="Days, times or time zone that suit you."
                            error={errors.preferred_availability?.message}
                            {...register('preferred_availability')}
                          />
                          <SelectInput label="How did you hear of us?" required placeholder="Select…" options={REFERRAL_OPTIONS} error={errors.referral_source?.message} {...register('referral_source')} />
                          {(values.referral_source === 'referral' || values.referral_source === 'other' || values.referral_source === 'event') && (
                            <TextInput label="Details" hint="For example, who referred you." error={errors.referral_details?.message} {...register('referral_details')} />
                          )}
                          <TextArea
                            label="Anything else for the private office"
                            rows={5}
                            maxLength={4000}
                            currentLength={values.confidential_notes?.length ?? 0}
                            hint="Held with the same encryption as the rest of your application."
                            error={errors.confidential_notes?.message}
                            {...register('confidential_notes')}
                          />
                        </>
                      )}

                      {step === 4 && (
                        <>
                          <Review values={values} typeLabel={typeOptions.find((o) => o.value === values.consultation_type)?.label} onEdit={setStep} />
                          <div className="space-y-6 border-t border-[var(--line)] pt-10">
                            <Checkbox
                              label={
                                <>
                                  I have read the{' '}
                                  {privacyPublished ? (
                                    <Link to="/legal/privacy-policy" target="_blank" className="text-champagne-200 underline decoration-champagne-400/40 underline-offset-4">
                                      privacy notice
                                    </Link>
                                  ) : (
                                    'privacy notice'
                                  )}{' '}
                                  and agree that the private office may process this application to respond to me.
                                </>
                              }
                              error={errors.consent_privacy?.message}
                              {...register('consent_privacy')}
                            />
                            <Checkbox label="I would like to receive occasional, discreet updates about gatherings and new offerings." {...register('consent_communications')} />
                          </div>
                        </>
                      )}
                    </div>
                </motion.div>

                {formError && (
                  <div className="mt-10">
                    <FormAlert>{formError}</FormAlert>
                  </div>
                )}

                <div className="mt-14 flex flex-wrap items-center justify-between gap-6 border-t border-[var(--line)] pt-8">
                  <div className="flex items-center gap-6">
                    {step > 0 && (
                      <Button variant="ghost" size="sm" onClick={() => setStep((s) => s - 1)} className="!px-0">
                        ← Back
                      </Button>
                    )}
                    <span className="text-xs font-light text-slate-400" aria-live="polite">
                      {saving ? 'Saving securely…' : savedAt ? `Saved ${savedAt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}` : ''}
                    </span>
                  </div>
                  {step < STEPS.length - 1 ? (
                    <Button onClick={next} loading={saving} arrow>
                      Continue
                    </Button>
                  ) : (
                    <Button type="submit" loading={formState.isSubmitting} arrow>
                      Submit application
                    </Button>
                  )}
                </div>
              </form>
            </div>
          </div>
        </Container>
      </section>
    </>
  )
}

function Review({ values, typeLabel, onEdit }: { values: Partial<ApplicationForm>; typeLabel?: string; onEdit: (step: number) => void }) {
  const rows: { step: number; items: [string, string | undefined][] }[] = [
    { step: 0, items: [['Name', values.full_name], ['Email', values.email], ['Phone', values.phone], ['Location', [values.city, countryName(values.country)].filter(Boolean).join(', ')]] },
    { step: 1, items: [['Role', values.designation], ['Organisation', values.organization], ['Background', values.professional_background]] },
    { step: 2, items: [['Area of interest', typeLabel], ['Intention', values.core_objective], ['Format', FORMAT_OPTIONS.find((f) => f.value === values.preferred_format)?.label]] },
    {
      step: 3,
      items: [
        ['Availability', values.preferred_availability],
        ['Heard via', REFERRAL_OPTIONS.find((r) => r.value === values.referral_source)?.label],
        ['Notes', values.confidential_notes ? 'Provided' : undefined],
      ],
    },
  ]
  return (
    <div className="space-y-10">
      {rows.map((group) => (
        <div key={group.step} className="border-t border-[var(--line)] pt-6">
          <div className="mb-4 flex items-center justify-between">
            <p className="eyebrow">{STEPS[group.step].label}</p>
            <button type="button" onClick={() => onEdit(group.step)} className="text-[0.66rem] uppercase tracking-[0.24em] text-ivory-200/70 underline-offset-4 hover:text-champagne-200 hover:underline">
              Edit
            </button>
          </div>
          <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-[10rem_1fr]">
            {group.items
              .filter(([, v]) => v)
              .map(([k, v]) => (
                <div key={k} className="contents">
                  <dt className="text-xs uppercase tracking-[0.2em] text-slate-400">{k}</dt>
                  <dd className="whitespace-pre-line break-words font-light text-ivory-200/90">{v}</dd>
                </div>
              ))}
          </dl>
        </div>
      ))}
    </div>
  )
}

function Submitted({ reference, previously = false }: { reference: string; previously?: boolean }) {
  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [])
  return (
    <>
      <Seo title="Application received" noindex />
      <section className="grain relative flex min-h-[90vh] items-center bg-midnight-950 pb-24 pt-[calc(var(--header-h)+4rem)]">
        <Container className="max-w-3xl text-center">
          <p className="eyebrow">Received in confidence</p>
          <h1 className="mt-8 text-display font-light">
            {previously ? (
              <>
                Already <em className="text-champagne-200 italic">with the private office.</em>
              </>
            ) : (
              <>
                Thank you. <em className="text-champagne-200 italic">We will be in touch.</em>
              </>
            )}
          </h1>
          <p className="mx-auto mt-10 max-w-xl text-body-l font-light text-ivory-200/85">
            {previously
              ? 'This application has been submitted and can no longer be edited. You can follow its progress below.'
              : 'Your application has been received by the private office. A confirmation has been sent to your email, and you will hear from us personally.'}
          </p>
          <OrnamentRule className="mx-auto my-14 w-64" />
          <p className="text-[0.68rem] uppercase tracking-[0.3em] text-slate-400">Your reference</p>
          <p className="mt-3 font-display text-4xl tracking-[0.08em] text-ivory-50">{reference}</p>
          <div className="mt-14 flex flex-wrap items-center justify-center gap-10">
            <ButtonLink to={`/private-access/status/${reference}`} variant="outline">
              View status
            </ButtonLink>
            <ButtonLink to="/" variant="link">
              Return home
            </ButtonLink>
          </div>
        </Container>
      </section>
    </>
  )
}
