import { useLocation } from 'react-router'
import { useSettings } from '../../lib/queries'
import type { Seo as SeoData } from '../../lib/types'

export const SITE_URL = ((import.meta.env.VITE_SITE_URL as string | undefined) ?? (typeof window !== 'undefined' ? window.location.origin : '')).replace(/\/$/, '')

const INDEXABLE_BUILD = ((import.meta.env.VITE_APP_ENV as string | undefined) ?? (import.meta.env.PROD ? 'production' : 'local')) === 'production'

type SeoProps = {
  title?: string | null
  description?: string | null
  image?: string | null
  /** Overrides from the admin SEO manager win over page defaults. */
  override?: SeoData | null
  noindex?: boolean
  type?: 'website' | 'article' | 'profile' | 'event'
  jsonLd?: Record<string, unknown> | Record<string, unknown>[] | null
}

/**
 * Per-route metadata via React 19's native <title>/<meta>/<link> hoisting. Private routes pass
 * `noindex`; the server also sends `X-Robots-Tag` for API responses.
 */
export function Seo({ title, description, image, override, noindex = false, type = 'website', jsonLd }: SeoProps) {
  const { pathname } = useLocation()
  const { data: settings } = useSettings()
  const siteName = settings?.['site.name'] ?? 'Hansmuniji'
  const resolvedTitle = override?.title ?? title
  const fullTitle = resolvedTitle ? (resolvedTitle.includes(siteName) ? resolvedTitle : `${resolvedTitle} — ${siteName}`) : `${siteName} — ${settings?.['site.tagline'] ?? ''}`.replace(/ — $/, '')
  const desc = override?.description ?? description ?? settings?.['site.description'] ?? ''
  const canonical = override?.canonical ?? `${SITE_URL}${pathname === '/' ? '/' : pathname.replace(/\/$/, '')}`
  const pageRobots = noindex ? 'noindex,nofollow' : (override?.robots ?? 'index,follow')
  const robots = INDEXABLE_BUILD ? pageRobots : 'noindex,nofollow'
  const img = override?.image ?? image ?? null

  return (
    <>
      <title>{fullTitle}</title>
      {desc && <meta name="description" content={desc} />}
      {/* data-page-robots lets prerendering skip private pages even on non-production builds. */}
      <meta name="robots" content={robots} data-page-robots={pageRobots} />
      {!noindex && <link rel="canonical" href={canonical} />}
      <meta property="og:site_name" content={siteName} />
      <meta property="og:title" content={fullTitle} />
      {desc && <meta property="og:description" content={desc} />}
      <meta property="og:type" content={type === 'event' ? 'website' : type} />
      {!noindex && <meta property="og:url" content={canonical} />}
      {img && <meta property="og:image" content={img} />}
      <meta name="twitter:card" content={img ? 'summary_large_image' : 'summary'} />
      {!noindex && jsonLd && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd).replace(/</g, '\\u003c') }} />
      )}
    </>
  )
}
