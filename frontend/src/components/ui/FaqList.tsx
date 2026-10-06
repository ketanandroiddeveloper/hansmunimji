import { useId, useState } from 'react'
import type { Faq } from '../../lib/types'

export function FaqList({ faqs }: { faqs: Faq[] }) {
  const [open, setOpen] = useState<number | null>(null)
  const baseId = useId()
  return (
    <dl className="border-t border-[var(--line)]">
      {faqs.map((f, i) => {
        const expanded = open === i
        return (
          <div key={f.question} className="border-b border-[var(--line)]">
            <dt>
              <button
                type="button"
                aria-expanded={expanded}
                aria-controls={`${baseId}-${i}`}
                onClick={() => setOpen(expanded ? null : i)}
                className="group flex w-full items-center justify-between gap-6 py-7 text-left"
              >
                <span className="font-display text-2xl text-ivory-50 transition-colors group-hover:text-champagne-200">{f.question}</span>
                <span aria-hidden="true" className="relative h-3 w-3 shrink-0 text-champagne-400">
                  <span className="absolute left-0 top-1/2 h-px w-3 bg-current" />
                  <span className={`absolute left-1/2 top-0 h-3 w-px bg-current transition-transform duration-500 ${expanded ? 'scale-y-0' : ''}`} />
                </span>
              </button>
            </dt>
            <dd id={`${baseId}-${i}`} hidden={!expanded} className="pb-8 pr-10 font-light leading-relaxed text-ivory-200/80">
              {f.answer}
            </dd>
          </div>
        )
      })}
    </dl>
  )
}
