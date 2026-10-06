import { Suspense, useEffect } from 'react'
import { Outlet, ScrollRestoration, useLocation } from 'react-router'
import { LoadingBlock } from '../ui/States'
import { ConsentBanner } from './ConsentBanner'
import { EnvironmentRibbon } from './EnvironmentRibbon'
import { SiteFooter } from './SiteFooter'
import { SiteHeader } from './SiteHeader'

/** Moves focus to the main region on navigation so screen-reader users hear the new page. */
function RouteFocus() {
  const { pathname } = useLocation()
  useEffect(() => {
    const main = document.getElementById('main')
    if (main && document.activeElement !== document.body) main.focus({ preventScroll: true })
  }, [pathname])
  return null
}

export function PublicLayout() {
  return (
    <>
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:bg-champagne-400 focus:px-4 focus:py-2 focus:text-midnight-950"
      >
        Skip to content
      </a>
      <EnvironmentRibbon />
      <SiteHeader />
      <main id="main" tabIndex={-1} className="outline-none" style={{ paddingTop: 'var(--ribbon-h)' }}>
        <Suspense fallback={<LoadingBlock className="min-h-[70vh]" />}>
          <Outlet />
        </Suspense>
      </main>
      <SiteFooter />
      <ConsentBanner />
      <ScrollRestoration />
      <RouteFocus />
    </>
  )
}
