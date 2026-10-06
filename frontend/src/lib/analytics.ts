/**
 * Consent-gated analytics. Nothing is loaded until the visitor opts in, and confidential flows
 * (applications, bookings, registrations, privacy requests, admin) are never reported. Only the
 * bare path is sent — query strings and fragments (which can carry access tokens) are dropped.
 */
const STORAGE_KEY = 'pa_consent'

export type ConsentState = { analytics: boolean; version: string; at: string }

const EXCLUDED = [/^\/private-access/, /^\/consultation/, /^\/admin/, /^\/privacy/, /^\/gatherings\/registration/]

declare global {
  interface Window {
    dataLayer?: unknown[]
    gtag?: (...args: unknown[]) => void
  }
}

export function readConsent(version: string): ConsentState | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as ConsentState
    return parsed.version === version ? parsed : null
  } catch {
    return null
  }
}

export function writeConsent(analytics: boolean, version: string): ConsentState {
  const state = { analytics, version, at: new Date().toISOString() }
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  } catch {
    /* storage unavailable: consent applies to this page view only */
  }
  return state
}

let loadedId: string | null = null

export function isTrackablePath(pathname: string): boolean {
  return !EXCLUDED.some((re) => re.test(pathname))
}

export function loadAnalytics(measurementId: string): void {
  if (!/^G-[A-Z0-9]{4,}$/.test(measurementId) || loadedId === measurementId) return
  loadedId = measurementId
  window.dataLayer = window.dataLayer ?? []
  // gtag.js only recognises the native `arguments` object, not a rest-parameter array.
  window.gtag = function gtag() {
    window.dataLayer!.push(arguments)
  }
  window.gtag('js', new Date())
  window.gtag('config', measurementId, {
    send_page_view: false,
    allow_google_signals: false,
    allow_ad_personalization_signals: false,
  })
  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`
  document.head.appendChild(script)
}

export function trackPageView(pathname: string): void {
  if (!loadedId || !window.gtag || !isTrackablePath(pathname)) return
  window.gtag('event', 'page_view', { page_path: pathname, page_location: `${window.location.origin}${pathname}`, page_title: document.title })
}
