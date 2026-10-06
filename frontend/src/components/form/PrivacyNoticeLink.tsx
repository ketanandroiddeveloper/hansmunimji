import { Link } from 'react-router'
import { usePublishedPages } from '../../lib/queries'

/** Links to the privacy notice only once it has been published from the admin. */
export function PrivacyNoticeLink() {
  const { data: pages } = usePublishedPages()
  if (!pages?.some((p) => p.slug === 'privacy-policy')) return <>privacy notice</>
  return (
    <Link to="/legal/privacy-policy" target="_blank" className="text-champagne-200 underline decoration-champagne-400/40 underline-offset-4">
      privacy notice
    </Link>
  )
}
