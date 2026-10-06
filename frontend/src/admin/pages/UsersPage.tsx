import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { adminApi, errorMessage } from '../lib/adminApi'
import { formatAdminDateTime } from '../lib/datetime'
import { useSession } from '../lib/session'
import { Dialog, useConfirm } from '../ui/Dialog'
import { CheckboxGroup, Input, Select } from '../ui/Fields'
import { AButton, Badge, DataTable, ErrorNote, Notice, PageHeader, Panel, Spinner, Td, Th } from '../ui/Primitives'
import { useToast } from '../ui/Toast'

interface AdminAccount {
  id: number
  email: string
  name: string
  status: 'active' | 'disabled'
  totp_enabled: boolean
  last_login_at: string | null
  locked_until: string | null
  created_at: string
  roles: string[]
}

interface RolesData {
  roles: { id: number; slug: string; name: string; description: string | null; permissions: string[] }[]
  permissions: Record<string, string>
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function InviteDialog({ open, onClose, roles }: { open: boolean; onClose: () => void; roles: RolesData['roles'] }) {
  const notify = useToast()
  const client = useQueryClient()
  const { user } = useSession()
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [selected, setSelected] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)
  const isSuper = user?.roles.includes('super-admin') ?? false
  const invite = useMutation({
    mutationFn: () => adminApi.post('/admin/users', { email: email.trim(), name: name.trim(), roles: selected }),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['admin', 'users'] })
      notify(`Invitation sent to ${email.trim()}. The link is valid for 72 hours.`)
      setEmail('')
      setName('')
      setSelected([])
      onClose()
    },
    onError: (e) => setError(errorMessage(e)),
  })
  const submit = () => {
    setError(null)
    if (name.trim().length < 2) return setError('Enter their name.')
    if (!EMAIL.test(email.trim())) return setError('Enter a valid email address.')
    if (selected.length === 0) return setError('Assign at least one role.')
    invite.mutate()
  }
  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Invite an administrator"
      footer={
        <>
          <AButton variant="ghost" onClick={onClose}>
            Cancel
          </AButton>
          <AButton variant="primary" loading={invite.isPending} onClick={submit}>
            Send invitation
          </AButton>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-sm font-light text-ivory-200">They receive an email link to choose their own password, then must set up two-factor authentication on first sign-in.</p>
        <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} maxLength={190} />
        <Input label="Email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        <CheckboxGroup legend="Roles" required options={roles.filter((r) => isSuper || r.slug !== 'super-admin').map((r) => ({ value: r.slug, label: r.name }))} value={selected} onChange={setSelected} />
        {error && <Notice tone="error">{error}</Notice>}
      </div>
    </Dialog>
  )
}

function EditDialog({ account, onClose, roles }: { account: AdminAccount; onClose: () => void; roles: RolesData['roles'] }) {
  const notify = useToast()
  const confirm = useConfirm()
  const client = useQueryClient()
  const { user } = useSession()
  const self = user?.id === account.id
  const isSuper = user?.roles.includes('super-admin') ?? false
  const [name, setName] = useState(account.name)
  const [status, setStatus] = useState(account.status)
  const [selected, setSelected] = useState(account.roles)
  const [error, setError] = useState<string | null>(null)
  const refresh = () => client.invalidateQueries({ queryKey: ['admin', 'users'] })

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = {}
      if (name.trim() !== account.name) body.name = name.trim()
      if (!self && status !== account.status) body.status = status
      if (!self && JSON.stringify([...selected].sort()) !== JSON.stringify([...account.roles].sort())) body.roles = selected
      return Object.keys(body).length ? adminApi.put(`/admin/users/${account.id}`, body) : Promise.resolve(undefined)
    },
    onSuccess: () => {
      refresh()
      notify('Saved. Changes to roles or status sign the user out of all sessions.')
      onClose()
    },
    onError: (e) => setError(errorMessage(e)),
  })
  const action = useMutation({
    mutationFn: (path: string) => adminApi.post(`/admin/users/${account.id}/${path}`),
    onSuccess: (_, path) => {
      refresh()
      notify(path === 'resend-invitation' ? 'Invitation sent again.' : path === 'reset-two-factor' ? 'Two-factor reset. They must enrol again at next sign-in.' : 'All sessions signed out.')
    },
    onError: (e) => setError(errorMessage(e)),
  })
  const run = async (path: string, title: string, body: string, label: string) => {
    if (await confirm({ title, body, confirmLabel: label, danger: path !== 'resend-invitation' })) action.mutate(path)
  }
  const submit = () => {
    setError(null)
    if (name.trim().length < 2) return setError('Enter a name.')
    if (!self && selected.length === 0) return setError('Assign at least one role.')
    save.mutate()
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={account.name}
      footer={
        <>
          <AButton variant="ghost" onClick={onClose}>
            Close
          </AButton>
          <AButton variant="primary" loading={save.isPending} onClick={submit}>
            Save
          </AButton>
        </>
      }
    >
      <div className="space-y-5">
        <p className="text-sm font-light text-slate-400">{account.email}</p>
        <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} maxLength={190} />
        {self ? (
          <Notice>You cannot change your own roles or status. Ask another Super Admin.</Notice>
        ) : (
          <>
            <Select
              label="Status"
              value={status}
              onChange={(e) => setStatus(e.target.value as AdminAccount['status'])}
              options={[
                { value: 'active', label: 'Active' },
                { value: 'disabled', label: 'Disabled — cannot sign in' },
              ]}
            />
            <CheckboxGroup legend="Roles" options={roles.filter((r) => isSuper || r.slug !== 'super-admin' || account.roles.includes('super-admin')).map((r) => ({ value: r.slug, label: r.name }))} value={selected} onChange={setSelected} />
          </>
        )}
        {!self && (
          <div className="flex flex-wrap gap-2 border-t border-[var(--line)] pt-4">
            {!account.last_login_at && (
              <AButton variant="secondary" disabled={action.isPending} onClick={() => run('resend-invitation', 'Send the invitation again?', 'A new 72-hour setup link is emailed. Earlier links remain valid until they expire.', 'Send')}>
                Resend invitation
              </AButton>
            )}
            <AButton variant="secondary" disabled={action.isPending} onClick={() => run('revoke-sessions', 'Sign out everywhere?', 'Ends all of this user’s active sessions immediately.', 'Sign out')}>
              Sign out all sessions
            </AButton>
            {account.totp_enabled && (
              <AButton
                variant="danger"
                disabled={action.isPending}
                onClick={() => run('reset-two-factor', 'Reset two-factor authentication?', 'Use only if they have lost their authenticator and you have verified their identity. They are signed out and must enrol again.', 'Reset')}
              >
                Reset two-factor
              </AButton>
            )}
          </div>
        )}
        {error && <Notice tone="error">{error}</Notice>}
      </div>
    </Dialog>
  )
}

export default function UsersPage() {
  const users = useQuery({ queryKey: ['admin', 'users'], queryFn: () => adminApi.get<AdminAccount[]>('/admin/users') })
  const roles = useQuery({ queryKey: ['admin', 'roles'], queryFn: () => adminApi.get<RolesData>('/admin/roles'), staleTime: 5 * 60_000 })
  const [inviting, setInviting] = useState(false)
  const [editing, setEditing] = useState<AdminAccount | null>(null)

  if (users.isLoading || roles.isLoading) return <Spinner />
  if (users.isError) return <ErrorNote error={users.error} onRetry={() => users.refetch()} />
  if (roles.isError) return <ErrorNote error={roles.error} onRetry={() => roles.refetch()} />
  const roleName = new Map(roles.data!.roles.map((r) => [r.slug, r.name]))
  const now = Date.now()

  return (
    <>
      <PageHeader
        title="Administrators"
        description="Accounts are created by invitation only; nobody is ever sent a password. Two-factor authentication is required for every administrator."
        actions={
          <AButton variant="primary" onClick={() => setInviting(true)}>
            Invite
          </AButton>
        }
      />
      <DataTable caption="Administrators">
        <thead>
          <tr>
            <Th>Name</Th>
            <Th>Roles</Th>
            <Th>Security</Th>
            <Th>Last sign-in</Th>
          </tr>
        </thead>
        <tbody>
          {users.data!.map((u) => {
            const locked = u.locked_until && new Date(u.locked_until).getTime() > now
            return (
              <tr key={u.id} className="hover:bg-midnight-800/50">
                <Td>
                  <button type="button" onClick={() => setEditing(u)} className="text-left text-ivory-50 hover:text-champagne-200 hover:underline">
                    {u.name}
                  </button>
                  <span className="block text-xs text-slate-400">{u.email}</span>
                </Td>
                <Td>
                  <span className="flex flex-wrap gap-1">
                    {u.roles.map((r) => (
                      <Badge key={r} tone={r === 'super-admin' ? 'gold' : 'neutral'}>
                        {roleName.get(r) ?? r}
                      </Badge>
                    ))}
                  </span>
                </Td>
                <Td>
                  <span className="flex flex-wrap gap-1">
                    {u.status === 'disabled' && <Badge tone="muted">Disabled</Badge>}
                    {locked && <Badge tone="red">Locked</Badge>}
                    {!u.last_login_at && u.status === 'active' && <Badge tone="blue">Invited</Badge>}
                    <Badge tone={u.totp_enabled ? 'green' : 'gold'}>{u.totp_enabled ? '2FA on' : '2FA pending'}</Badge>
                  </span>
                </Td>
                <Td className="whitespace-nowrap">{formatAdminDateTime(u.last_login_at)}</Td>
              </tr>
            )
          })}
        </tbody>
      </DataTable>

      <div className="mt-8">
        <Panel title="What each role can do">
          <div className="grid gap-6 md:grid-cols-2">
            {roles.data!.roles.map((r) => (
              <div key={r.slug}>
                <p className="text-sm text-ivory-50">{r.name}</p>
                {r.description && <p className="mt-1 text-xs font-light text-slate-400">{r.description}</p>}
                <ul className="mt-2 space-y-0.5 text-xs font-light text-ivory-200">
                  {r.permissions.includes('*') ? <li>Everything, including managing other Super Admins</li> : r.permissions.map((p) => <li key={p}>{roles.data!.permissions[p] ?? p}</li>)}
                </ul>
              </div>
            ))}
          </div>
        </Panel>
      </div>

      <InviteDialog open={inviting} onClose={() => setInviting(false)} roles={roles.data!.roles} />
      {editing && <EditDialog key={editing.id} account={editing} onClose={() => setEditing(null)} roles={roles.data!.roles} />}
    </>
  )
}
