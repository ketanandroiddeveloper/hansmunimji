import { AnimatePresence, motion } from 'framer-motion'
import { useEffect, useState } from 'react'
import { Link, useLocation } from 'react-router'
import { loadAnalytics, readConsent, trackPageView, writeConsent } from '../../lib/analytics'
import { usePublishedPages, useSettings } from '../../lib/queries'
import { Button } from '../ui/Button'

/** Shown only when an analytics provider is configured; strictly necessary storage needs no banner. */
export function ConsentBanner() {
  const { data: settings } = useSettings()
  const { data: pages } = usePublishedPages()
  const cookiePolicyPublished = pages?.some((p) => p.slug === 'cookie-policy') ?? false
  const { pathname } = useLocation()
  const measurementId = (settings?.['analytics.ga_measurement_id'] ?? '').trim()
  const version = settings?.['privacy.policy_version'] ?? '1'
  const [decided, setDecided] = useState<boolean | null>(null)

  useEffect(() => {
    if (!measurementId) return
    const consent = readConsent(version)
    setDecided(consent !== null)
    if (consent?.analytics) loadAnalytics(measurementId)
  }, [measurementId, version])

  useEffect(() => {
    if (measurementId && readConsent(version)?.analytics) {
      const id = window.setTimeout(() => trackPageView(pathname), 50)
      return () => window.clearTimeout(id)
    }
    return undefined
  }, [pathname, measurementId, version])

  const choose = (analytics: boolean) => {
    writeConsent(analytics, version)
    setDecided(true)
    if (analytics) {
      loadAnalytics(measurementId)
      trackPageView(pathname)
    }
  }

  const open = Boolean(measurementId) && decided === false && !pathname.startsWith('/admin')

  return (
    <AnimatePresence>
      {open && (
        <motion.div
          role="dialog"
          data-prerender="skip"
          aria-labelledby="consent-title"
          aria-describedby="consent-text"
          initial={{ opacity: 0, y: 24 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: 24 }}
          transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
          className="fixed inset-x-4 bottom-[max(1rem,env(safe-area-inset-bottom))] z-50 max-h-[calc(100dvh-2rem)] overflow-y-auto mx-auto max-w-2xl border border-[var(--line-strong)] bg-midnight-900/95 p-6 backdrop-blur-md md:p-8"
        >
          <p id="consent-title" className="eyebrow">
            Your privacy
          </p>
          <p id="consent-text" className="mt-4 text-sm font-light leading-relaxed text-ivory-200/85">
            {settings?.['consent.banner_text']}{' '}
            {cookiePolicyPublished && (
              <Link to="/legal/cookie-policy" className="text-champagne-200 underline decoration-champagne-400/40 underline-offset-4">
                Cookie policy
              </Link>
            )}
          </p>
          <div className="mt-6 flex flex-wrap gap-3">
            <Button variant="solid" size="sm" onClick={() => choose(true)}>
              Allow analytics
            </Button>
            <Button variant="outline" size="sm" onClick={() => choose(false)}>
              Necessary only
            </Button>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
