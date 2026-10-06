import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import type { FieldValues } from 'react-hook-form'
import { useNavigate, useParams } from 'react-router'
import { ApiError } from '../../lib/api'
import { adminApi } from '../lib/adminApi'
import { useSession } from '../lib/session'
import { useConfirm } from '../ui/Dialog'
import { AButton, ErrorNote, Notice, PageHeader, Panel, Spinner } from '../ui/Primitives'
import { useToast } from '../ui/Toast'
import { FormField } from './FormField'
import { slugify, toFormValue, toPayloadValue } from './types'
import type { FieldDef, ResourceDef, Row } from './types'

const WIDE: FieldDef['type'][] = ['richtext', 'structured', 'textarea', 'code', 'prices', 'checkboxes', 'media']

function initialValues(def: ResourceDef, record: Record<string, unknown>): FieldValues {
  return Object.fromEntries(def.fields.map((f) => [f.name, toFormValue(f, record)]))
}

export function useUnsavedChangesWarning(dirty: boolean) {
  useEffect(() => {
    if (!dirty) return
    const handler = (e: BeforeUnloadEvent) => e.preventDefault()
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [dirty])
}

export function ResourceEditPage({ def }: { def: ResourceDef }) {
  const { id } = useParams()
  const isNew = id === 'new'
  const record = useQuery({
    queryKey: ['admin', 'resource', def.key, 'item', id],
    queryFn: () => adminApi.get<Row>(`${def.endpoint}/${id}`),
    enabled: !isNew,
  })

  if (!isNew && record.isLoading) return <Spinner />
  if (!isNew && record.isError) return <ErrorNote error={record.error} onRetry={() => record.refetch()} />
  return <ResourceForm key={id} def={def} record={isNew ? null : record.data!} onReload={() => record.refetch()} />
}

function ResourceForm({ def, record, onReload }: { def: ResourceDef; record: Row | null; onReload: () => void }) {
  const isNew = record === null
  const navigate = useNavigate()
  const client = useQueryClient()
  const notify = useToast()
  const confirm = useConfirm()
  const { can } = useSession()
  const editable = can(def.permission)
  const [formError, setFormError] = useState<string | null>(null)
  const defaults = useMemo(() => initialValues(def, record ?? def.defaults ?? {}), [def, record])
  const { control, handleSubmit, setValue, setError, reset, getFieldState, formState } = useForm<FieldValues>({ defaultValues: defaults })
  useUnsavedChangesWarning(formState.isDirty)

  const slugField = def.fields.find((f) => f.type === 'slug' && f.slugFrom)
  const source = useWatch({ control, name: slugField?.slugFrom ?? '__none__' }) as string | undefined
  useEffect(() => {
    if (isNew && slugField && source !== undefined && !getFieldState(slugField.name).isDirty) {
      setValue(slugField.name, slugify(source))
    }
  }, [isNew, slugField, source, setValue, getFieldState])

  const save = useMutation({
    mutationFn: (values: FieldValues) => {
      const payload = Object.fromEntries(def.fields.map((f) => [f.name, toPayloadValue(f, values[f.name], values)]))
      return isNew ? adminApi.post<Row>(def.endpoint, payload) : adminApi.put<Row>(`${def.endpoint}/${record.id}`, payload)
    },
    onSuccess: (saved) => {
      client.invalidateQueries({ queryKey: ['admin', 'resource', def.key] })
      client.invalidateQueries({ queryKey: ['admin', 'options'] })
      notify(isNew ? `${def.singular} created.` : 'Changes saved.')
      if (isNew) {
        reset(initialValues(def, saved))
        navigate(`../${saved.id}`, { replace: true, relative: 'path' })
      } else {
        client.setQueryData(['admin', 'resource', def.key, 'item', String(saved.id)], saved)
        reset(initialValues(def, saved))
      }
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        const known = new Set(def.fields.map((f) => f.name))
        const general: string[] = []
        for (const [field, messages] of Object.entries(e.fields)) {
          const base = field.split('.')[0]
          if (known.has(base)) setError(base, { type: 'server', message: messages[0] })
          else general.push(messages[0])
        }
        setFormError(general[0] ?? (Object.keys(e.fields).length ? 'Please review the highlighted fields.' : e.message))
      } else {
        setFormError('Something went wrong. Please try again.')
      }
    },
  })

  const remove = useMutation({
    mutationFn: () => adminApi.delete(`${def.endpoint}/${record!.id}`),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['admin', 'resource', def.key] })
      notify(`${def.singular} deleted.`)
      reset(defaults)
      navigate('..', { relative: 'path' })
    },
    onError: (e) => notify(e instanceof ApiError ? e.message : 'Could not delete.', 'error'),
  })

  const onSubmit = handleSubmit((values) => {
    setFormError(null)
    save.mutate(values)
  })

  const onDelete = async () => {
    const ok = await confirm({ title: `Delete this ${def.singular.toLowerCase()}?`, body: 'This cannot be undone. If it is referenced elsewhere, unpublish it instead.', confirmLabel: 'Delete', danger: true })
    if (ok) remove.mutate()
  }

  const byName = new Map(def.fields.map((f) => [f.name, f]))
  const sections = def.sections ?? [{ title: 'Details', fields: def.fields.map((f) => f.name) }]
  const publicPath = record && def.publicPath ? def.publicPath(record) : null

  return (
    <>
      <PageHeader
        back={{ to: '..', label: def.title }}
        title={isNew ? `New ${def.singular.toLowerCase()}` : def.titleOf(record)}
        actions={
          <>
            {publicPath && (
              <a href={publicPath} target="_blank" rel="noopener noreferrer" className="px-3 py-2 text-[0.7rem] font-semibold uppercase tracking-[0.16em] text-ivory-200 hover:text-champagne-200">
                View on site ↗
              </a>
            )}
            {!isNew && def.canDelete !== false && editable && (
              <AButton variant="danger" onClick={onDelete} loading={remove.isPending}>
                Delete
              </AButton>
            )}
          </>
        }
      />
      <form onSubmit={onSubmit} noValidate className="space-y-6">
        <fieldset disabled={!editable} className="space-y-6">
          {sections.map((s) => (
            <Panel key={s.title} title={s.title}>
              <div className="grid gap-5 md:grid-cols-2">
                {s.fields.map((name) => {
                  const f = byName.get(name)
                  if (!f) return null
                  return (
                    <div key={name} className={f.wide || WIDE.includes(f.type) ? 'md:col-span-2' : ''}>
                      <FormField field={f} control={control} isNew={isNew} record={record ?? undefined} />
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
              {isNew ? `Create ${def.singular.toLowerCase()}` : 'Save changes'}
            </AButton>
            {formState.isDirty && <span className="text-xs text-champagne-200">Unsaved changes</span>}
          </div>
        )}
      </form>
      {record && def.panels?.map((P, i) => (
        <div key={i} className="mt-8">
          <P record={record} refresh={onReload} />
        </div>
      ))}
    </>
  )
}
