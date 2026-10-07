import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { lazy, Suspense } from 'react'
import type { ReactNode } from 'react'
import { Route, Routes } from 'react-router'
import { ApiError } from '../lib/api'
import LoginPage from './auth/LoginPage'
import { ForgotPasswordPage, ResetPasswordPage } from './auth/PasswordPages'
import { AccountPage, TwoFactorSetupPage } from './auth/SecurityPages'
import { AdminLayout } from './layout/AdminLayout'
import { markSessionExpired, ME_KEY, SessionProvider, useSession } from './lib/session'
import { RESOURCES } from './resources/definitions'
import { ResourceEditPage } from './resources/ResourceEditPage'
import { ResourceListPage } from './resources/ResourceListPage'
import { ConfirmProvider } from './ui/Dialog'
import { Forbidden, Spinner } from './ui/Primitives'
import { ToastProvider } from './ui/Toast'
import './admin.css'

const DashboardPage = lazy(() => import('./pages/DashboardPage'))
const ApplicationsPage = lazy(() => import('./pages/applications/ApplicationsPage'))
const ApplicationDetailPage = lazy(() => import('./pages/applications/ApplicationDetailPage'))
const AppointmentsPage = lazy(() => import('./pages/appointments/AppointmentsPage'))
const AppointmentDetailPage = lazy(() => import('./pages/appointments/AppointmentDetailPage'))
const NewAppointmentPage = lazy(() => import('./pages/appointments/NewAppointmentPage'))
const PaymentsPage = lazy(() => import('./pages/payments/PaymentsPage'))
const PaymentDetailPage = lazy(() => import('./pages/payments/PaymentDetailPage'))
const ReportsPage = lazy(() => import('./pages/ReportsPage'))
const PractitionerPage = lazy(() => import('./pages/PractitionerPage'))
const MediaPage = lazy(() => import('./pages/MediaPage'))
const SettingsPage = lazy(() => import('./pages/SettingsPage'))
const IntegrationsPage = lazy(() => import('./pages/IntegrationsPage'))
const GoogleIntegrationPage = lazy(() => import('./pages/GoogleIntegrationPage'))
const UsersPage = lazy(() => import('./pages/UsersPage'))
const AuditLogPage = lazy(() => import('./pages/AuditLogPage'))

function onApiError(error: unknown) {
  if (!(error instanceof ApiError)) return
  if (error.status === 401) {
    markSessionExpired()
    adminQueryClient.setQueryData(ME_KEY, null)
  } else if (error.code === 'two_factor_setup_required') {
    adminQueryClient.invalidateQueries({ queryKey: ME_KEY })
  }
}

const adminQueryClient: QueryClient = new QueryClient({
  queryCache: new QueryCache({ onError: onApiError }),
  mutationCache: new MutationCache({ onError: onApiError }),
  defaultOptions: {
    queries: {
      staleTime: 15_000,
      refetchOnWindowFocus: false,
      retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 1,
    },
  },
})

function Guard({ permission, children }: { permission: string; children: ReactNode }) {
  const { can } = useSession()
  return can(permission) ? <>{children}</> : <Forbidden />
}

function AdminNotFound() {
  return (
    <div className="py-24 text-center">
      <p className="font-display text-3xl text-ivory-50">This admin page does not exist.</p>
    </div>
  )
}

export default function AdminApp() {
  return (
    <QueryClientProvider client={adminQueryClient}>
      <SessionProvider>
        <ToastProvider>
          <ConfirmProvider>
            <meta name="robots" content="noindex,nofollow" />
            <title>Private office</title>
            <Suspense fallback={<Spinner />}>
              <Routes>
                <Route path="login" element={<LoginPage />} />
                <Route path="forgot-password" element={<ForgotPasswordPage />} />
                <Route path="reset-password" element={<ResetPasswordPage />} />
                <Route path="two-factor" element={<TwoFactorSetupPage />} />
                <Route element={<AdminLayout />}>
                  <Route index element={<Guard permission="dashboard.view"><DashboardPage /></Guard>} />
                  <Route path="account" element={<AccountPage />} />
                  <Route path="applications" element={<Guard permission="applications.view"><ApplicationsPage /></Guard>} />
                  <Route path="applications/:id" element={<Guard permission="applications.view"><ApplicationDetailPage /></Guard>} />
                  <Route path="appointments" element={<Guard permission="appointments.view"><AppointmentsPage /></Guard>} />
                  <Route path="appointments/new" element={<Guard permission="appointments.manage"><NewAppointmentPage /></Guard>} />
                  <Route path="appointments/:id" element={<Guard permission="appointments.view"><AppointmentDetailPage /></Guard>} />
                  <Route path="payments" element={<Guard permission="payments.view"><PaymentsPage /></Guard>} />
                  <Route path="payments/:id" element={<Guard permission="payments.view"><PaymentDetailPage /></Guard>} />
                  <Route path="reports" element={<Guard permission="reports.view"><ReportsPage /></Guard>} />
                  <Route path="practitioner" element={<Guard permission="content.manage"><PractitionerPage /></Guard>} />
                  <Route path="media" element={<Guard permission="media.manage"><MediaPage /></Guard>} />
                  <Route path="settings" element={<Guard permission="settings.manage"><SettingsPage /></Guard>} />
                  <Route path="integrations" element={<Guard permission="integrations.manage"><IntegrationsPage /></Guard>} />
                  <Route path="settings/integrations/google" element={<Guard permission="integrations.manage"><GoogleIntegrationPage /></Guard>} />
                  <Route path="users" element={<Guard permission="users.manage"><UsersPage /></Guard>} />
                  <Route path="audit-log" element={<Guard permission="audit.view"><AuditLogPage /></Guard>} />
                  {RESOURCES.flatMap((def) => [
                    <Route key={def.key} path={def.key} element={<Guard permission={def.permission}><ResourceListPage def={def} /></Guard>} />,
                    <Route key={`${def.key}-item`} path={`${def.key}/:id`} element={<Guard permission={def.permission}><ResourceEditPage def={def} /></Guard>} />,
                  ])}
                  <Route path="*" element={<AdminNotFound />} />
                </Route>
              </Routes>
            </Suspense>
          </ConfirmProvider>
        </ToastProvider>
      </SessionProvider>
    </QueryClientProvider>
  )
}
