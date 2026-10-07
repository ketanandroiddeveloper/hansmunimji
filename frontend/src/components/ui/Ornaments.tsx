/** Small lotus-derived mark used as a section ornament and in the wordmark. */
export function LotusMark({ className = 'h-5 w-5' }: { className?: string }) {
  return (
    <svg aria-hidden="true" viewBox="0 0 32 32" className={className} fill="none" stroke="currentColor" strokeWidth="0.9" strokeLinecap="round">
      <path d="M16 6c3 3.5 4.5 7 4.5 10.5S19 23 16 25c-3-2-4.5-5-4.5-8.5S13 9.5 16 6Z" />
      <path d="M16 25c-4-.3-8-2.6-10-6.5 3.2-.6 6 .1 8.2 1.8" />
      <path d="M16 25c4-.3 8-2.6 10-6.5-3.2-.6-6 .1-8.2 1.8" />
      <path d="M6 26.5h20" />
    </svg>
  )
}

export function OrnamentRule({ className = '' }: { className?: string }) {
  return (
    <div aria-hidden="true" className={`flex items-center gap-5 text-champagne-400/70 ${className}`}>
      <span className="rule-gold flex-1" />
      <LotusMark className="h-4 w-4" />
      <span className="rule-gold flex-1" />
    </div>
  )
}

/**
 * Soft blurred light behind a section. The blob is clipped to its positioned parent so it can never widen the page,
 * without making the parent a scroll container (which would break sticky children).
 */
export function Glow({ className }: { className: string }) {
  return (
    <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
      <div className={`absolute rounded-full ${className}`} />
    </div>
  )
}

export function ConfidentialitySeal({ label = 'Confidential · Encrypted inquiry', className = '' }: { label?: string; className?: string }) {
  return (
    <p className={`inline-flex max-w-full shrink-0 items-center gap-3 text-[0.68rem] xl:whitespace-nowrap font-medium uppercase tracking-[0.26em] text-ivory-200/70 ${className}`}>
      <svg aria-hidden="true" viewBox="0 0 20 24" className="h-4 w-3.5 shrink-0 text-champagne-400" fill="none" stroke="currentColor" strokeWidth="1.1">
        <path d="M10 1.5 2 4.5v6.2c0 5.2 3.4 9.7 8 11.3 4.6-1.6 8-6.1 8-11.3V4.5l-8-3Z" />
        <path d="M6.8 11.8 9 14l4.4-4.6" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <span>{label}</span>
    </p>
  )
}
