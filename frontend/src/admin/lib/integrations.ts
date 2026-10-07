export type GoogleServiceStatus = 'connected' | 'missing_permission' | 'error' | 'reauth_required' | 'disconnected' | 'not_used'

export interface GoogleLog {
  operation: string
  outcome: 'success' | 'failure'
  http_status: number | null
  error_category: string | null
  reference: string | null
  duration_ms: number | null
  created_at: string
}

export interface GoogleStatus {
  configured: boolean
  status: 'connected' | 'needs_reauth' | 'disconnected'
  account_email: string | null
  expected_account: string | null
  calendar_id: string | null
  last_error: string | null
  last_synced_at: string | null
  redirect_uri: string | null
  missing_scopes: string[]
  services: {
    gmail: { status: GoogleServiceStatus; mail_driver: boolean }
    calendar: { status: GoogleServiceStatus }
    meet: { status: GoogleServiceStatus }
  }
  logs: GoogleLog[]
}

export interface Integrations {
  environment: string
  google: GoogleStatus
  payments: Record<string, { configured: boolean; mode: 'live' | 'test' | 'unconfigured'; currencies: string[]; webhook_url: string }>
  email: { configured: boolean; driver: string }
  frontend_rebuild: { configured: boolean }
  queues: { jobs_pending: number; jobs_failed: number; emails_queued: number; emails_failed: number }
  failed_jobs: { id: number; type: string; attempts: number; last_error: string | null; finished_at: string | null }[]
}

export const INTEGRATIONS_KEY = ['admin', 'integrations'] as const
export const GOOGLE_PAGE = '/admin/settings/integrations/google'
