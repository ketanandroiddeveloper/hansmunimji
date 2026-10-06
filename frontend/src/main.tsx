import { QueryClientProvider } from '@tanstack/react-query'
import { MotionConfig } from 'framer-motion'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router/dom'
import { router } from './app/router'
import { queryClient } from './lib/queries'
import { endSnapshotEntry, normalisePath } from './lib/snapshot'
import './index.css'

declare global {
  interface Window {
    __APP_IDLE__?: () => boolean
  }
}

/** Used by scripts/prerender.mjs and the snapshot hand-over below. */
window.__APP_IDLE__ = () => router.state.initialized && router.state.navigation.state === 'idle' && queryClient.isFetching() === 0

const app = (
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <MotionConfig reducedMotion="user">
        <RouterProvider router={router} />
      </MotionConfig>
    </QueryClientProvider>
  </StrictMode>
)

const container = document.getElementById('root')!
const snapshotPath = container.getAttribute('data-prerendered')

function removeSnapshotHead() {
  document.querySelectorAll('head [data-prerendered]').forEach((el) => el.remove())
}

/** Resolves once the live app has loaded its route and data and the DOM has stopped changing. */
function settled(el: HTMLElement, timeoutMs = 8000): Promise<void> {
  return new Promise((resolve) => {
    const deadline = performance.now() + timeoutMs
    let lastLength = -1
    let stableTicks = 0
    const tick = () => {
      const length = el.innerHTML.length
      stableTicks = window.__APP_IDLE__?.() && length > 0 && length === lastLength ? stableTicks + 1 : 0
      lastLength = length
      if (stableTicks >= 2 || performance.now() > deadline) resolve()
      else window.setTimeout(tick, 60)
    }
    tick()
  })
}

if (snapshotPath !== null && normalisePath(snapshotPath) === normalisePath(window.location.pathname)) {
  // Render the live app off-screen and swap it in once ready, so the static snapshot never blanks out.
  const live = document.createElement('div')
  live.hidden = true
  container.after(live)
  createRoot(live).render(app)
  void settled(live).then(() => {
    removeSnapshotHead()
    container.remove()
    live.id = 'root'
    live.hidden = false
  })
  // Content that mounts late on the entry page must not animate either; later pages animate normally.
  const entryPath = window.location.pathname
  const unsubscribe = router.subscribe((state) => {
    if (state.location.pathname !== entryPath) {
      endSnapshotEntry()
      unsubscribe()
    }
  })
} else {
  if (snapshotPath !== null) {
    // A snapshot served for the wrong path (server fallback misconfigured): discard it.
    removeSnapshotHead()
    container.replaceChildren()
    container.removeAttribute('data-prerendered')
    endSnapshotEntry()
  }
  createRoot(container).render(app)
}
