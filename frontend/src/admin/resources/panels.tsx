import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useState } from 'react'
import { formatMoney } from '../../lib/format'
import { adminApi, errorMessage } from '../lib/adminApi'
import { formatAdminDateTime } from '../lib/datetime'
import { labelOf, PAYMENT_STATUS, REGISTRATION_STATUS } from '../lib/labels'
import { Dialog } from '../ui/Dialog'
import { FieldWrap } from '../ui/Fields'
import { AButton, Badge, DataTable, ErrorNote, Notice, Panel, Spinner, TableMessage, Td, Th } from '../ui/Primitives'
import { useToast } from '../ui/Toast'
import type { PanelProps } from './types'

interface Registration {
  id: number
  reference: string
  status: keyof typeof REGISTRATION_STATUS
  seats: number
  name: string | null
  email: string | null
  phone: string | null
  country: string | null
  notes: string | null
  currency: string | null
  amount_minor: number
  hold_expires_at: string | null
  payment: { reference: string; gateway: string; status: string; refunded_minor: number } | null
  created_at: string
}

const SETTABLE = ['pending_payment', 'confirmed', 'waitlisted', 'cancelled'] as const
const CLOSED = ['refunded', 'expired', 'cancelled']

export function EventRegistrationsPanel({ record }: PanelProps) {
  const client = useQueryClient()
  const notify = useToast()
  const key = ['admin', 'event-registrations', record.id]
  const list = useQuery({ queryKey: key, queryFn: () => adminApi.get<Registration[]>(`/admin/events/${record.id}/registrations`) })
  const [cancelling, setCancelling] = useState<Registration | null>(null)
  const update = useMutation({
    mutationFn: ({ id, status, refund }: { id: number; status: string; refund?: boolean }) => adminApi.put(`/admin/events/${record.id}/registrations/${id}`, { status, refund }),
    onSuccess: (_, v) => {
      client.invalidateQueries({ queryKey: key })
      setCancelling(null)
      notify(v.status === 'pending_payment' ? 'Payment link emailed to the guest.' : v.refund ? 'Registration cancelled and a full refund requested.' : 'Registration updated.')
    },
    onError: (e) => notify(errorMessage(e), 'error'),
  })
  const change = (r: Registration, status: string) => {
    if (status === 'cancelled' && r.payment && ['captured', 'partially_refunded'].includes(r.payment.status)) return setCancelling(r)
    update.mutate({ id: r.id, status })
  }
  const confirmed = (list.data ?? []).filter((r) => r.status === 'confirmed').reduce((n, r) => n + r.seats, 0)

  return (
    <Panel title="Registrations" actions={list.data && <span className="text-xs text-slate-400">{confirmed} confirmed seats{record.seat_quota ? ` of ${String(record.seat_quota)}` : ''}</span>} padded={false}>
      {list.isLoading ? (
        <div className="px-5">
          <Spinner />
        </div>
      ) : list.isError ? (
        <div className="p-5">
          <ErrorNote error={list.error} />
        </div>
      ) : (
        <DataTable caption="Registrations">
          <thead>
            <tr>
              <Th>Guest</Th>
              <Th>Seats</Th>
              <Th>Amount</Th>
              <Th>Received</Th>
              <Th>Status</Th>
            </tr>
          </thead>
          <tbody>
            {list.data!.length === 0 ? (
              <TableMessage colSpan={5}>No registrations yet.</TableMessage>
            ) : (
              list.data!.map((r) => (
                <tr key={r.id}>
                  <Td>
                    <p className="text-ivory-50">{r.name ?? '—'}</p>
                    <p className="text-xs text-slate-400">{r.email}</p>
                    {r.phone && <p className="text-xs text-slate-400">{r.phone}</p>}
                    {r.country && <p className="text-xs text-slate-400">{r.country}</p>}
                    {r.notes && <p className="mt-1 max-w-sm whitespace-pre-line text-xs text-ivory-200/80">{r.notes}</p>}
                    <p className="mt-1 font-mono text-[0.65rem] text-slate-400">{r.reference}</p>
                  </Td>
                  <Td>{r.seats}</Td>
                  <Td>
                    {r.amount_minor > 0 && r.currency ? formatMoney({ currency: r.currency, amount_minor: r.amount_minor }) : '—'}
                    {r.payment && (
                      <span className="mt-1 block text-xs text-slate-400">
                        <span className="capitalize">{r.payment.gateway}</span> · {labelOf(PAYMENT_STATUS, r.payment.status).label}
                        <span className="block font-mono text-[0.65rem]">{r.payment.reference}</span>
                      </span>
                    )}
                  </Td>
                  <Td>
                    {formatAdminDateTime(r.created_at)}
                    {r.status === 'pending_payment' && r.hold_expires_at && <span className="mt-1 block text-xs text-slate-400">Held until {formatAdminDateTime(r.hold_expires_at)}</span>}
                  </Td>
                  <Td>
                    <div className="flex flex-col items-start gap-2">
                      <Badge tone={REGISTRATION_STATUS[r.status]?.tone}>{REGISTRATION_STATUS[r.status]?.label ?? r.status}</Badge>
                      {!CLOSED.includes(r.status) && (
                        <select
                          aria-label={`Change status for ${r.reference}`}
                          value=""
                          disabled={update.isPending}
                          onChange={(e) => e.target.value && change(r, e.target.value)}
                          className="border border-[var(--line-strong)] bg-midnight-950 px-2 py-1 text-xs text-ivory-200"
                        >
                          <option value="">Change…</option>
                          {SETTABLE.filter((s) => s !== r.status).map((s) => (
                            <option key={s} value={s}>
                              {REGISTRATION_STATUS[s].label}
                            </option>
                          ))}
                        </select>
                      )}
                    </div>
                  </Td>
                </tr>
              ))
            )}
          </tbody>
        </DataTable>
      )}
      <p className="px-5 py-3 text-xs text-slate-400">
        “Awaiting payment” emails the guest a fresh payment link and holds their place for the waitlist offer period. Freed places are offered to the waitlist automatically. Partial refunds are issued from Payments.
      </p>
      <Dialog
        open={cancelling !== null}
        onClose={() => setCancelling(null)}
        title="Cancel a paid registration?"
        footer={
          <>
            <AButton variant="ghost" onClick={() => setCancelling(null)}>
              Keep registration
            </AButton>
            <AButton variant="danger" loading={update.isPending} onClick={() => cancelling && update.mutate({ id: cancelling.id, status: 'cancelled', refund: false })}>
              Cancel without refund
            </AButton>
            <AButton variant="danger" loading={update.isPending} onClick={() => cancelling && update.mutate({ id: cancelling.id, status: 'cancelled', refund: true })}>
              Cancel and refund in full
            </AButton>
          </>
        }
      >
        <p className="text-sm font-light text-ivory-200">
          {cancelling?.name ?? 'The guest'} paid {cancelling?.currency ? formatMoney({ currency: cancelling.currency, amount_minor: cancelling.amount_minor }) : ''}. The guest is emailed either way; a refund completes only once the gateway confirms it.
        </p>
      </Dialog>
    </Panel>
  )
}

export function AudioFilePanel({ record, refresh }: PanelProps) {
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)
  const id = useId()
  const notify = useToast()
  const client = useQueryClient()
  const upload = useMutation({
    mutationFn: () => {
      const form = new FormData()
      form.append('file', file!)
      return adminApi.post(`/admin/audio/${record.id}/file`, form)
    },
    onSuccess: () => {
      setFile(null)
      notify('Audio file uploaded.')
      client.invalidateQueries({ queryKey: ['admin', 'resource', 'audio'] })
      refresh()
    },
    onError: (e) => setError(errorMessage(e)),
  })
  return (
    <Panel title="Audio file">
      <p className="mb-4 text-sm font-light text-ivory-200">
        {record.has_file ? 'A file is attached. Uploading a new one replaces it.' : 'No file yet. The recording shows as “being prepared” until a file is uploaded.'} Files are stored privately and streamed through short-lived signed links.
      </p>
      <form
        className="flex flex-wrap items-end gap-4"
        onSubmit={(e) => {
          e.preventDefault()
          setError(null)
          if (!file) return setError('Choose an audio file.')
          upload.mutate()
        }}
      >
        <FieldWrap id={id} label="File" hint="MP3, M4A/AAC, OGG or WAV." className="flex-1">
          <input id={id} type="file" accept="audio/mpeg,audio/mp4,audio/aac,audio/ogg,audio/wav,audio/x-wav,.mp3,.m4a" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="block w-full text-sm text-ivory-200 file:mr-4 file:border file:border-ivory-50/20 file:bg-transparent file:px-3 file:py-1.5 file:text-xs file:uppercase file:tracking-[0.14em] file:text-ivory-50" />
        </FieldWrap>
        <AButton type="submit" variant="primary" loading={upload.isPending}>
          Upload
        </AButton>
      </form>
      {error && (
        <div className="mt-4">
          <Notice tone="error">{error}</Notice>
        </div>
      )}
    </Panel>
  )
}

export function EmailTemplatePanel({ record }: PanelProps) {
  const preview = useMutation({ mutationFn: () => adminApi.post<{ subject: string; html: string; text: string | null }>(`/admin/email-templates/${record.id}/preview`, {}) })
  const variables = Array.isArray(record.variables) ? (record.variables as string[]) : []
  return (
    <Panel title="Variables & preview" actions={<AButton onClick={() => preview.mutate()} loading={preview.isPending}>Preview saved version</AButton>}>
      <p className="text-sm font-light text-ivory-200">Insert variables with double braces. Values are escaped automatically. Optional blocks use {'{{#if name}}…{{/if}}'}.</p>
      <ul className="mt-3 flex flex-wrap gap-2">
        {variables.length === 0 && <li className="text-sm text-slate-400">This template has no variables.</li>}
        {variables.map((v) => (
          <li key={v}>
            <code className="border border-[var(--line)] bg-midnight-950 px-2 py-0.5 text-xs text-champagne-200">{`{{${v}}}`}</code>
          </li>
        ))}
      </ul>
      {preview.isError && (
        <div className="mt-4">
          <ErrorNote error={preview.error} />
        </div>
      )}
      {preview.data && (
        <div className="mt-5 space-y-3">
          <p className="text-sm">
            <span className="text-slate-400">Subject: </span>
            {preview.data.subject}
          </p>
          <iframe title="Email preview" sandbox="" srcDoc={preview.data.html} className="h-[32rem] w-full border border-[var(--line)] bg-white" />
        </div>
      )}
    </Panel>
  )
}
