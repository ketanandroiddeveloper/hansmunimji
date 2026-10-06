import type { ReactNode } from 'react'
import { paragraphs } from '../../lib/format'

export function Eyebrow({ children, className = '', as: Tag = 'p' }: { children: ReactNode; className?: string; as?: 'p' | 'span' | 'h2' }) {
  return (
    <Tag className={`eyebrow flex items-center gap-4 ${className}`}>
      <span aria-hidden="true" className="h-px w-8 bg-champagne-400/60" />
      <span>{children}</span>
    </Tag>
  )
}

type HeadingLevel = 1 | 2 | 3
type HeadingSize = 'display' | 'h1' | 'h2' | 'h3'

const sizes: Record<HeadingSize, string> = {
  display: 'text-display font-light',
  h1: 'text-h1',
  h2: 'text-h2',
  h3: 'text-h3 font-medium',
}

export function Heading({
  level = 2,
  size,
  id,
  children,
  className = '',
}: {
  level?: HeadingLevel
  size?: HeadingSize
  id?: string
  children: ReactNode
  className?: string
}) {
  const Tag = `h${level}` as const
  const resolved = size ?? (level === 1 ? 'h1' : level === 2 ? 'h2' : 'h3')
  return (
    <Tag id={id} className={`${sizes[resolved]} ${className}`}>
      {children}
    </Tag>
  )
}

/**
 * Renders a CMS headline, italicising the final phrase after a comma or full stop so the
 * display type has the editorial rhythm described in the design system.
 */
export function EditorialTitle({ text }: { text: string }) {
  const match = text.match(/^(.*?[,.—–:])\s+(.+)$/)
  if (!match) return <>{text}</>
  return (
    <>
      {match[1]}{' '}
      <em className="font-light text-champagne-200 italic">{match[2]}</em>
    </>
  )
}

export function Lead({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <p className={`text-body-l font-light text-ivory-200/90 ${className}`}>{children}</p>
}

export function Paragraphs({ text, className = '', lead = false }: { text: string | null | undefined; className?: string; lead?: boolean }) {
  const parts = paragraphs(text)
  if (parts.length === 0) return null
  return (
    <div className={`space-y-5 ${className}`}>
      {parts.map((p, i) =>
        lead && i === 0 ? (
          <Lead key={i}>{p}</Lead>
        ) : (
          <p key={i} className="font-light text-ivory-200/85">
            {p}
          </p>
        ),
      )}
    </div>
  )
}

export function RomanNumeral({ value, className = '' }: { value: string | null | undefined; className?: string }) {
  if (!value) return null
  return (
    <span aria-hidden="true" className={`font-display text-lg italic tracking-wide text-champagne-400 ${className}`}>
      {value}.
    </span>
  )
}
