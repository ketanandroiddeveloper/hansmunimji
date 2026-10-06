import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { useSearchParams } from 'react-router'
import { z } from 'zod'
import { ChoiceGroup, FormAlert, TextInput } from '../../components/form/Fields'
import { PrivacyNoticeLink } from '../../components/form/PrivacyNoticeLink'
import { Seo } from '../../components/seo/Seo'
import { Button } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { api } from '../../lib/api'
import { applyServerErrors } from '../../lib/forms'

const schema = z.object({
  type: z.enum(['access', 'deletion'], { message: 'Choose the kind of request.' }),
  email: z.string().trim().email('Enter a valid email address.').max(190),
})
type RequestForm = z.infer<typeof schema>

export default function PrivacyRequestPage() {
  const [params] = useSearchParams()
  const verified = params.get('verified') === '1'
  const [sent, setSent] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const { register, control, handleSubmit, setError, formState } = useForm<RequestForm>({ resolver: zodResolver(schema), defaultValues: { email: '' }, mode: 'onTouched' })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await api.post('/privacy/requests', values)
      setSent(true)
    } catch (e) {
      const message = applyServerErrors(e, setError, ['type', 'email'])
      if (message) setFormError(message)
    }
  })

  return (
    <>
      <Seo title="Your data" description="Request a copy of, or the deletion of, the personal data held about you." noindex />
      <section className="grain bg-midnight-950 pb-32 pt-[calc(var(--header-h)+4rem)] md:pt-[calc(var(--header-h)+6rem)]">
        <Container className="max-w-2xl">
          <p className="eyebrow flex items-center gap-4">
            <span aria-hidden="true" className="h-px w-10 bg-champagne-400/70" />
            Your data
          </p>
          <h1 className="mt-8 text-h1">
            Your information, <em className="font-light text-champagne-200 italic">at your request.</em>
          </h1>
          <p className="mt-8 font-light leading-relaxed text-ivory-200/80">
            You may ask for a copy of the personal information held about you, or for it to be deleted. To protect your privacy, we first confirm the request through your email address. Read more in our{' '}
            <PrivacyNoticeLink />.
          </p>

          <div className="mt-14" aria-live="polite">
            {verified ? (
              <FormAlert tone="success">Thank you — your request has been verified. The private office will respond to you by email.</FormAlert>
            ) : sent ? (
              <FormAlert tone="success">
                A confirmation link is on its way to that address. Please open it to verify your request — it will only be processed once verified.
              </FormAlert>
            ) : (
              <form onSubmit={onSubmit} noValidate className="space-y-10">
                <Controller
                  control={control}
                  name="type"
                  render={({ field }) => (
                    <ChoiceGroup
                      legend="I would like"
                      name="type"
                      required
                      value={field.value}
                      onChange={field.onChange}
                      error={formState.errors.type?.message}
                      options={[
                        { value: 'access', label: 'A copy of my data', description: 'A summary of the personal information held about you' },
                        { value: 'deletion', label: 'My data deleted', description: 'Subject to any records we are required to keep' },
                      ]}
                    />
                  )}
                />
                <TextInput label="Email address" type="email" autoComplete="email" required hint="The address you used with the practice." error={formState.errors.email?.message} {...register('email')} />
                {formError && <FormAlert>{formError}</FormAlert>}
                <Button type="submit" arrow loading={formState.isSubmitting}>
                  Send request
                </Button>
              </form>
            )}
          </div>
        </Container>
      </section>
    </>
  )
}
