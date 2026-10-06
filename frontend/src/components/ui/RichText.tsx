import DOMPurify from 'dompurify'
import { useMemo } from 'react'

const CONFIG = {
  ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'hr', 'span'],
  ALLOWED_ATTR: ['href', 'title', 'rel', 'target'],
  ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|tel:|\/(?!\/)|#)/i,
}

let hooked = false
function ensureLinkHook() {
  if (hooked) return
  hooked = true
  DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node instanceof HTMLAnchorElement && /^https?:/i.test(node.getAttribute('href') ?? '')) {
      node.setAttribute('target', '_blank')
      node.setAttribute('rel', 'noopener noreferrer')
    }
  })
}

/** CMS HTML is purified on the server; it is sanitized again here as defence in depth. */
export function RichText({ html, className = '' }: { html: string | null | undefined; className?: string }) {
  const clean = useMemo(() => {
    if (!html) return ''
    ensureLinkHook()
    return DOMPurify.sanitize(html, CONFIG) as string
  }, [html])
  if (!clean) return null
  return <div className={`prose-quiet ${className}`} dangerouslySetInnerHTML={{ __html: clean }} />
}
