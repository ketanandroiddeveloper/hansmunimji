import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import type { FieldValues } from 'react-hook-form'
import { ApiError } from '../../lib/api'
import { adminApi } from '../lib/adminApi'
import { useSession } from '../lib/session'
import { FormField } from '../resources/FormField'
import { useUnsavedChangesWarning } from '../resources/ResourceEditPage'
import { slugify, toFormValue, toPayloadValue } from '../resources/types'
import type { FieldDef, Row } from '../resources/types'
import { AButton, ErrorNote, Notice, PageHeader, Panel, Spinner } from '../ui/Primitives'
import { useToast } from '../ui/Toast'

const FIELDS: FieldDef[] = [
  { name: 'full_name', label: 'Full name', type: 'text', required: true, maxLength: 190 },
  { name: 'honorific', label: 'Honorific', type: 'text', maxLength: 60, hint: 'Shown before the name where appropriate.' },
  { name: 'title', label: 'Professional title', type: 'text', maxLength: 190, hint: 'Describe the role truthfully; avoid regulated titles unless held.' },
  { name: 'slug', label: 'Slug', type: 'slug', required: true, maxLength: 120, slugFrom: 'full_name' },
  { name: 'short_bio', label: 'Short biography', type: 'textarea', maxLength: 2000, rows: 4, wide: true, hint: 'Used in summaries, search results and structured data.' },
  { name: 'portrait_media_id', label: 'Portrait', type: 'media' },
  { name: 'secondary_media_id', label: 'Secondary image', type: 'media' },
  { name: 'biography', label: 'Biography', type: 'richtext', wide: true },
  { name: 'philosophy', label: 'Philosophy', type: 'richtext', wide: true },
  { name: 'approach', label: 'Approach', type: 'richtext', wide: true },
  { name: 'expertise', label: 'Areas of expertise', type: 'structured', template: '', wide: true },
  { name: 'experience', label: 'Experience timeline', type: 'structured', template: { period: '', title: '', description: '' }, wide: true, hint: 'Each entry needs a title. Period is free text, e.g. "2012 – present".' },
  { name: 'same_as', label: 'Verified profile links', type: 'structured', template: '', wide: true, hint: 'https:// links to official profiles that identify the practitioner (used as schema.org sameAs).' },
]

const SECTIONS = [
  { title: 'Identity', fields: ['full_name', 'honorific', 'title', 'slug', 'short_bio'] },
  { title: 'Images', fields: ['portrait_media_id', 'secondary_media_id'] },
  { title: 'Story', fields: ['biography', 'philosophy', 'approach'] },
  { title: 'Expertise & experience', fields: ['expertise', 'experience', 'same_as'] },
]

const BY_NAME = new Map(FIELDS.map((f) => [f.name, f]))
const initial = (record: Record<string, unknown>) => Object.fromEntries(FIELDS.map((f) => [f.name, toFormValue(f, record)]))

export default function PractitionerPage() {
  const { can } = useSession()
  const notify = useToast()
  const client = useQueryClient()
  const editable = can('content.manage')
  const profile = useQuery({
    queryKey: ['admin', 'practitioner'],
    queryFn: async () => {
      try {
        return await adminApi.get<Row>('/admin/practitioner')
      } catch (e) {
        if (e instanceof ApiError && e.status === 404) return null
        throw e
      }
    },
  })
  const isNew = profile.data === null
  const { control, handleSubmit, reset, setError, setValue, getFieldState, formState } = useForm<FieldValues>({ defaultValues: initial({}) })
  const [formError, setFormError] = useState<string | null>(null)
  useUnsavedChangesWarning(formState.isDirty)

  useEffect(() => {
    if (profile.data) reset(initial(profile.data))
  }, [profile.data, reset])

  const name = useWatch({ control, name: 'full_name' }) as string
  useEffect(() => {
    if (isNew && !getFieldState('slug').isDirty) setValue('slug', slugify(name ?? ''))
  }, [isNew, name, setValue, getFieldState])

  const save = useMutation({
    mutationFn: (values: FieldValues) => adminApi.put<Row>('/admin/practitioner', Object.fromEntries(FIELDS.map((f) => [f.name, toPayloadValue(f, values[f.name], values)]))),
    onSuccess: (saved) => {
      client.setQueryData(['admin', 'practitioner'], saved)
      reset(initial(saved))
      notify('Profile saved. The public site will refresh shortly.')
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'validation_failed') {
        for (const [key, messages] of Object.entries(e.fields)) {
          const base = key.split('.')[0]
          if (BY_NAME.has(base)) setError(base, { type: 'server', message: messages[0] })
        }
        setFormError('Please review the highlighted fields.')
      } else {
        setFormError(e instanceof ApiError ? e.message : `Could not save${e instanceof Error ? ` (${e.message})` : ''}.`)
      }
    },
  })

  if (profile.isLoading) return <Spinner />
  if (profile.isError) return <ErrorNote error={profile.error} onRetry={() => profile.refetch()} />

  return (
    <>
      <PageHeader
        title="Practitioner profile"
        description="The biography shown on the About page and in structured data. Qualifications and credentials are managed separately under Content → Qualifications."
        actions={
          !isNew && (
            <a href="/practitioner" target="_blank" rel="noopener noreferrer" className="px-3 py-2 text-[0.7rem] font-semibold uppercase tracking-[0.16em] text-ivory-200 hover:text-champagne-200">
              View on site ↗
            </a>
          )
        }
      />
      <div className="mb-6">
        <Notice tone="warn">Only publish verifiable facts. Do not state credentials, affiliations or outcomes that cannot be substantiated, and avoid medical or therapeutic claims.</Notice>
      </div>
      {isNew && (
        <div className="mb-6">
          <Notice>No profile exists yet. Saving this form creates it.</Notice>
        </div>
      )}
      <form
        onSubmit={handleSubmit((v) => {
          setFormError(null)
          save.mutate(v)
        })}
        noValidate
        className="space-y-6"
      >
        <fieldset disabled={!editable} className="space-y-6">
          {SECTIONS.map((s) => (
            <Panel key={s.title} title={s.title}>
              <div className="grid gap-5 md:grid-cols-2">
                {s.fields.map((n) => {
                  const f = BY_NAME.get(n)!
                  return (
                    <div key={n} className={f.wide ? 'md:col-span-2' : ''}>
                      <FormField field={f} control={control} isNew={isNew} record={profile.data ?? undefined} />
                    </div>
                  )
                })}
              </div>
            </Panel>
          ))}
        </fieldset>
        {formError && <Notice tone="error">{formError}</Notice>}
        {editable && (
          <div className="sticky bottom-0 z-10 -mx-1 flex items-center gap-4 border-t border-[var(--line)] bg-midnight-950/95 px-1 py-4 backdrop-blur">
            <AButton type="submit" variant="primary" loading={save.isPending}>
              {isNew ? 'Create profile' : 'Save changes'}
            </AButton>
            {formState.isDirty && <span className="text-xs text-champagne-200">Unsaved changes</span>}
          </div>
        )}
      </form>
    </>
  )
}
