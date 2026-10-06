import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { countryName } from '../../../lib/countries'
import { adminApi, errorMessage } from '../../lib/adminApi'
import { formatAdminDateTime } from '../../lib/datetime'
import { APPLICATION_STATUS, APPOINTMENT_STATUS, FORMAT_LABEL, humanizeSlug, labelOf, REFERRAL_LABEL } from '../../lib/labels'
import { useSession } from '../../lib/session'
import { Dialog, useConfirm } from '../../ui/Dialog'
import { Select, Textarea } from '../../ui/Fields'
import { AButton, Badge, ErrorNote, KeyValues, Notice, PageHeader, Panel, Spinner } from '../../ui/Primitives'
import { StatusHistory } from '../../ui/StatusHistory'
import type { HistoryEntry } from '../../ui/StatusHistory'
import { useToast } from '../../ui/Toast'

interface ApplicationDetail {
  id: number
  reference: string
  status: string
  submitted_at: string | null
  reviewed_at: string | null
  invite_expires_at: string | null
  converted_at: string | null
  appointments: { reference: string; status: string; starts_at: string }[]
  history: HistoryEntry[]
  country: string | null
  consultation_type: string | null
  preferred_format: string | null
  referral_source: string | null
  full_name?: string | null
  email?: string | null
  phone?: string | null
  city?: string | null
  professional_background?: string | null
  designation?: string | null
  organization?: string | null
  core_objective?: string | null
  preferred_availability?: string | null
  referral_details?: string | null
  confidential_notes?: string | null
  admin_notes?: string | null
  info_request?: string | null
}

function Prose({ children }: { children: ReactNode }) {
  return <p className="whitespace-pre-line text-sm font-light leading-relaxed text-ivory-200">{children || '—'}</p>
}

export default function ApplicationDetailPage() {
  const { id } = useParams()
  const { can } = useSession()
  const client = useQueryClient()
  const notify = useToast()
  const confirm = useConfirm()
  const key = ['admin', 'applications', 'item', id]
  const app = useQuery({ queryKey: key, queryFn: () => adminApi.get<ApplicationDetail>(`/admin/applications/${id}`), staleTime: Infinity })
  const [infoOpen, setInfoOpen] = useState(false)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [message, setMessage] = useState('')
  const [typeId, setTypeId] = useState('')
  const [notes, setNotes] = useState('')
  const [dialogError, setDialogError] = useState<string | null>(null)

  useEffect(() => {
    if (app.data) setNotes(app.data.admin_notes ?? '')
  }, [app.data])

  const types = useQuery({
    queryKey: ['admin', 'options', '/admin/appointment-types'],
    queryFn: () => adminApi.list<{ id: number; title: string; is_active: number | boolean }>('/admin/appointment-types', { query: { per_page: 100 } }),
    enabled: inviteOpen,
  })

  const done = (msg: string) => {
    client.invalidateQueries({ queryKey: ['admin', 'applications'] })
    client.invalidateQueries({ queryKey: ['admin', 'dashboard'] })
    app.refetch()
    notify(msg)
  }
  const action = useMutation({
    mutationFn: ({ path, body }: { path: string; body?: unknown }) => adminApi.post(`/admin/applications/${id}/${path}`, body),
    onError: (e) => setDialogError(errorMessage(e)),
  })
  const saveNotes = useMutation({
    mutationFn: () => adminApi.patch(`/admin/applications/${id}/notes`, { admin_notes: notes || null }),
    onSuccess: () => notify('Notes saved.'),
    onError: (e) => notify(errorMessage(e), 'error'),
  })

  if (app.isLoading) return <Spinner />
  if (app.isError) return <ErrorNote error={app.error} onRetry={() => app.refetch()} />
  const a = app.data!
  const s = labelOf(APPLICATION_STATUS, a.status)
  const manage = can('applications.manage')
  const confidential = 'full_name' in a
  const canApprove = ['submitted', 'under_review', 'info_requested'].includes(a.status)
  const canReject = ['submitted', 'under_review', 'info_requested', 'approved'].includes(a.status)
  const canAsk = ['submitted', 'under_review'].includes(a.status)
  const canInvite = ['approved', 'invited'].includes(a.status)
  const canArchive = !['archived', 'draft'].includes(a.status)

  const SUCCESS = {
    approve: 'Application approved. The applicant has been emailed.',
    reject: 'Application declined. The applicant has been emailed.',
    archive: 'Application archived. The applicant was not notified.',
  }
  const simple = async (path: keyof typeof SUCCESS, title: string, body: string, label: string, danger = false) => {
    if (!(await confirm({ title, body, confirmLabel: label, danger }))) return
    action.mutate({ path }, { onSuccess: () => done(SUCCESS[path]), onError: (e) => notify(errorMessage(e), 'error') })
  }

  return (
    <>
      <PageHeader
        back={{ to: '/admin/applications', label: 'Applications' }}
        title={confidential ? (a.full_name ?? a.reference) : a.reference}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <Badge tone={s.tone}>{s.label}</Badge>
            <span className="font-mono text-xs">{a.reference}</span>
            <span>Submitted {formatAdminDateTime(a.submitted_at)}</span>
          </span>
        }
        actions={
          manage && (
            <>
              {canArchive && (
                <AButton
                  variant="ghost"
                  onClick={() =>
                    simple('archive', 'Archive this application?', 'Closes the application without emailing the applicant and cancels any open booking invitation. Its details are kept only for the retention period set in Settings.', 'Archive')
                  }
                >
                  Archive
                </AButton>
              )}
              {canAsk && (
                <AButton
                  onClick={() => {
                    setDialogError(null)
                    setInfoOpen(true)
                  }}
                >
                  Request information
                </AButton>
              )}
              {canReject && (
                <AButton variant="danger" onClick={() => simple('reject', 'Decline this application?', 'The applicant will receive a courteous email. Their details are kept only for the retention period set in Settings.', 'Decline', true)}>
                  Decline
                </AButton>
              )}
              {canApprove && (
                <AButton variant="primary" onClick={() => simple('approve', 'Approve this application?', 'The applicant will be emailed that their application has been accepted. You can then invite them to book.', 'Approve')}>
                  Approve
                </AButton>
              )}
              {canInvite && (
                <AButton
                  variant="primary"
                  onClick={() => {
                    setDialogError(null)
                    setInviteOpen(true)
                  }}
                >
                  {a.status === 'invited' ? 'Send a new invitation' : 'Invite to book'}
                </AButton>
              )}
            </>
          )
        }
      />

      {!confidential && <div className="mb-6"><Notice tone="info">Your role can see the status of applications but not the applicant’s confidential details.</Notice></div>}
      {a.status === 'invited' && a.invite_expires_at && (
        <div className="mb-6">
          <Notice tone="info">Booking invitation sent. The link expires {formatAdminDateTime(a.invite_expires_at)}.</Notice>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[3fr_2fr]">
        <div className="space-y-6">
          {confidential && (
            <Panel title="Applicant">
              <KeyValues
                items={[
                  ['Name', a.full_name],
                  ['Email', a.email ? <a href={`mailto:${a.email}`} className="text-champagne-200 hover:underline">{a.email}</a> : '—'],
                  ['Phone', a.phone],
                  ['Location', [a.city, a.country ? countryName(a.country) : null].filter(Boolean).join(', ') || '—'],
                  ['Role', [a.designation, a.organization].filter(Boolean).join(' · ') || '—'],
                ]}
              />
            </Panel>
          )}
          {confidential && (
            <Panel title="In their words">
              <div className="space-y-6">
                <div>
                  <h3 className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">What they hope to achieve</h3>
                  <Prose>{a.core_objective}</Prose>
                </div>
                <div>
                  <h3 className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Professional background</h3>
                  <Prose>{a.professional_background}</Prose>
                </div>
                <div>
                  <h3 className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Availability</h3>
                  <Prose>{a.preferred_availability}</Prose>
                </div>
                {a.confidential_notes && (
                  <div>
                    <h3 className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-champagne-200">Confidential notes</h3>
                    <Prose>{a.confidential_notes}</Prose>
                  </div>
                )}
              </div>
            </Panel>
          )}
          {confidential && a.info_request && (
            <Panel title="Information requested">
              <Prose>{a.info_request}</Prose>
            </Panel>
          )}
        </div>
        <div className="space-y-6">
          <Panel title="Request">
            <KeyValues
              items={[
                ['Interest', humanizeSlug(a.consultation_type)],
                ['Format', a.preferred_format ? (FORMAT_LABEL[a.preferred_format] ?? a.preferred_format) : '—'],
                ['Heard about us', a.referral_source ? (REFERRAL_LABEL[a.referral_source] ?? a.referral_source) : '—'],
                ...(confidential && a.referral_details ? ([['Referral details', a.referral_details]] as [string, string][]) : []),
                ['Reviewed', formatAdminDateTime(a.reviewed_at)],
                ...(a.converted_at ? ([['Booked', formatAdminDateTime(a.converted_at)]] as [string, string][]) : []),
              ]}
            />
          </Panel>
          <Panel title="Status history">
            <StatusHistory entries={a.history} labels={APPLICATION_STATUS} />
          </Panel>
          <Panel title="Appointments">
            {a.appointments.length === 0 ? (
              <p className="text-sm font-light text-slate-400">None yet.</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {a.appointments.map((ap) => (
                  <li key={ap.reference} className="flex items-center justify-between gap-3">
                    <Link to={`/admin/appointments?q=${ap.reference}`} className="font-mono text-xs text-ivory-50 hover:text-champagne-200 hover:underline">
                      {ap.reference}
                    </Link>
                    <span className="text-xs text-slate-400">{formatAdminDateTime(ap.starts_at)}</span>
                    <Badge tone={labelOf(APPOINTMENT_STATUS, ap.status).tone}>{labelOf(APPOINTMENT_STATUS, ap.status).label}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          {confidential && manage && (
            <Panel title="Private notes">
              <Textarea label="Notes for the office" hint="Encrypted. Never shown to the applicant." rows={6} maxLength={10000} value={notes} onChange={(e) => setNotes(e.target.value)} />
              <AButton className="mt-3" onClick={() => saveNotes.mutate()} loading={saveNotes.isPending} disabled={notes === (a.admin_notes ?? '')}>
                Save notes
              </AButton>
            </Panel>
          )}
        </div>
      </div>

      <Dialog
        open={infoOpen}
        onClose={() => setInfoOpen(false)}
        title="Request more information"
        footer={
          <>
            <AButton variant="ghost" onClick={() => setInfoOpen(false)}>
              Cancel
            </AButton>
            <AButton
              variant="primary"
              loading={action.isPending}
              onClick={() => {
                setDialogError(null)
                if (message.trim().length < 10) return setDialogError('Write at least a sentence so the applicant knows what to add.')
                action.mutate(
                  { path: 'request-info', body: { message: message.trim() } },
                  {
                    onSuccess: () => {
                      setInfoOpen(false)
                      setMessage('')
                      done('Request sent. The applicant can update their application from the email link.')
                    },
                  },
                )
              }}
            >
              Send request
            </AButton>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-sm font-light text-ivory-200">The applicant receives your message by email with a private link to add to their application (valid for 14 days).</p>
          <Textarea label="Message" required rows={6} maxLength={3000} value={message} onChange={(e) => setMessage(e.target.value)} />
          {dialogError && <Notice tone="error">{dialogError}</Notice>}
        </div>
      </Dialog>

      <Dialog
        open={inviteOpen}
        onClose={() => setInviteOpen(false)}
        title="Invite to book"
        footer={
          <>
            <AButton variant="ghost" onClick={() => setInviteOpen(false)}>
              Cancel
            </AButton>
            <AButton
              variant="primary"
              loading={action.isPending}
              onClick={() =>
                action.mutate(
                  { path: 'invite', body: { appointment_type_id: typeId ? Number(typeId) : null } },
                  {
                    onSuccess: () => {
                      setInviteOpen(false)
                      done('Invitation sent.')
                    },
                  },
                )
              }
            >
              Send invitation
            </AButton>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-sm font-light text-ivory-200">The applicant receives a private booking link. Choose a consultation to restrict the invitation, or leave it open to any active consultation.</p>
          <Select
            label="Consultation"
            value={typeId}
            onChange={(e) => setTypeId(e.target.value)}
            placeholder="Any active consultation"
            options={(types.data?.items ?? []).filter((t) => Boolean(Number(t.is_active))).map((t) => ({ value: String(t.id), label: t.title }))}
          />
          {dialogError && <Notice tone="error">{dialogError}</Notice>}
        </div>
      </Dialog>
    </>
  )
}
