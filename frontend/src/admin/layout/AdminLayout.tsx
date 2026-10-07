import { useEffect, useState } from 'react'
import { Navigate, NavLink, Outlet, useLocation } from 'react-router'
import { LotusMark } from '../../components/ui/Ornaments'
import { consumeSessionExpired, needsTwoFactorSetup, useSession } from '../lib/session'
import { RESOURCES } from '../resources/definitions'
import { Spinner } from '../ui/Primitives'
import { EnvironmentBadge } from './EnvironmentBadge'

type NavItem = { to: string; label: string; permission: string }

const NAV: { group: string; items: NavItem[] }[] = [
  {
    group: 'Overview',
    items: [
      { to: '/admin', label: 'Dashboard', permission: 'dashboard.view' },
      { to: '/admin/reports', label: 'Reports', permission: 'reports.view' },
    ],
  },
  {
    group: 'Clients',
    items: [
      { to: '/admin/applications', label: 'Applications', permission: 'applications.view' },
      { to: '/admin/appointments', label: 'Appointments', permission: 'appointments.view' },
      { to: '/admin/payments', label: 'Payments', permission: 'payments.view' },
    ],
  },
  { group: 'Scheduling', items: RESOURCES.filter((r) => r.group === 'Scheduling').map((r) => ({ to: `/admin/${r.key}`, label: r.title, permission: r.permission })) },
  {
    group: 'Content',
    items: [
      { to: '/admin/practitioner', label: 'Practitioner', permission: 'content.manage' },
      ...RESOURCES.filter((r) => r.group === 'Content').map((r) => ({ to: `/admin/${r.key}`, label: r.title, permission: r.permission })),
      { to: '/admin/media', label: 'Media library', permission: 'media.manage' },
    ],
  },
  {
    group: 'System',
    items: [
      ...RESOURCES.filter((r) => r.group === 'System').map((r) => ({ to: `/admin/${r.key}`, label: r.title, permission: r.permission })),
      { to: '/admin/settings', label: 'Settings', permission: 'settings.manage' },
      { to: '/admin/integrations', label: 'Integrations', permission: 'integrations.manage' },
      { to: '/admin/users', label: 'Users & roles', permission: 'users.manage' },
      { to: '/admin/audit-log', label: 'Audit log', permission: 'audit.view' },
    ],
  },
]

function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { can } = useSession()
  const { pathname } = useLocation()
  return (
    <nav aria-label="Admin" className="flex-1 overflow-y-auto px-3 pb-8">
      {NAV.map(({ group, items }) => {
        const visible = items.filter((i) => can(i.permission))
        if (visible.length === 0) return null
        return (
          <div key={group} className="mt-6">
            <p className="px-3 text-[0.58rem] font-semibold uppercase tracking-[0.26em] text-slate-400/80">{group}</p>
            <ul className="mt-2 space-y-px">
              {visible.map((i) => (
                <li key={i.to}>
                  <NavLink
                    to={i.to}
                    end={i.to === '/admin' || i.to === '/admin/settings'}
                    onClick={onNavigate}
                    className={({ isActive }) =>
                      `block border-l px-3 py-1.5 text-[0.82rem] font-light transition-colors ${isActive || (i.to === '/admin/integrations' && pathname.startsWith('/admin/settings/integrations')) ? 'border-champagne-400 bg-champagne-400/[0.06] text-champagne-200' : 'border-transparent text-ivory-200/80 hover:text-ivory-50'}`
                    }
                  >
                    {i.label}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        )
      })}
    </nav>
  )
}

export function AdminLayout() {
  const { user, loading, signOut } = useSession()
  const location = useLocation()
  const [menuOpen, setMenuOpen] = useState(false)

  useEffect(() => setMenuOpen(false), [location.pathname])

  if (loading) return <Spinner />
  if (!user) return <Navigate to={consumeSessionExpired() ? '/admin/login?expired=1' : '/admin/login'} replace state={{ from: location.pathname + location.search }} />
  if (needsTwoFactorSetup(user)) return <Navigate to="/admin/two-factor" replace />

  return (
    <div className="min-h-dvh bg-midnight-950 lg:grid lg:grid-cols-[15.5rem_1fr]">
      <a href="#admin-main" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[90] focus:bg-champagne-400 focus:px-4 focus:py-2 focus:text-midnight-950">
        Skip to content
      </a>
      <aside className={`fixed inset-y-0 left-0 z-40 flex w-[15.5rem] flex-col border-r border-[var(--line)] bg-midnight-900 transition-transform lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0 ${menuOpen ? 'translate-x-0' : '-translate-x-full'}`}>
        <div className="flex h-16 shrink-0 items-center gap-3 border-b border-[var(--line)] px-6">
          <LotusMark className="h-5 w-5 text-champagne-400" />
          <span className="font-display text-lg text-ivory-50">Private office</span>
        </div>
        <Sidebar onNavigate={() => setMenuOpen(false)} />
      </aside>
      {menuOpen && <button type="button" aria-label="Close menu" className="fixed inset-0 z-30 bg-midnight-950/70 lg:hidden" onClick={() => setMenuOpen(false)} />}
      <div className="min-w-0">
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-[var(--line)] bg-midnight-950/90 px-5 backdrop-blur md:px-8">
          <div className="flex items-center gap-4">
            <button type="button" className="p-1 text-ivory-200 lg:hidden" aria-label="Open menu" aria-expanded={menuOpen} onClick={() => setMenuOpen(true)}>
              <svg aria-hidden="true" viewBox="0 0 20 14" className="h-3.5 w-5" fill="none" stroke="currentColor" strokeWidth="1.3">
                <path d="M0 1h20M0 7h20M0 13h20" />
              </svg>
            </button>
            <EnvironmentBadge />
          </div>
          <div className="flex items-center gap-5 text-xs">
            <a href="/" target="_blank" rel="noopener noreferrer" className="hidden text-slate-400 hover:text-champagne-200 sm:inline">
              View site ↗
            </a>
            <NavLink to="/admin/account" className={({ isActive }) => (isActive ? 'text-champagne-200' : 'text-ivory-200 hover:text-champagne-200')}>
              {user.name}
            </NavLink>
            <button type="button" onClick={() => signOut()} className="uppercase tracking-[0.16em] text-slate-400 hover:text-champagne-200">
              Sign out
            </button>
          </div>
        </header>
        <main id="admin-main" className="mx-auto max-w-[88rem] px-5 py-8 md:px-8 md:py-10">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
