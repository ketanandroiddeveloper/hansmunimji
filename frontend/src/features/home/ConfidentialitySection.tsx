import type { HomeSections } from '../../lib/types'
import { Container, Section } from '../../components/ui/Container'
import { Reveal } from '../../components/ui/Reveal'
import { EditorialTitle } from '../../components/ui/Typography'

const COLUMNS: Record<number, string> = {
  2: 'md:grid-cols-2',
  3: 'md:grid-cols-3',
  4: 'md:grid-cols-2 lg:grid-cols-4',
}

export function ConfidentialitySection({ section }: { section: NonNullable<HomeSections['confidentiality']> }) {
  const points = section.points ?? []
  return (
    <Section tone="raised" labelledBy="confidentiality-title" className="grain overflow-hidden">
      <div aria-hidden="true" className="pointer-events-none absolute left-1/2 top-0 h-[40vh] w-[70vw] -translate-x-1/2 rounded-full bg-emerald-700/25 blur-[140px]" />
      <Container className="relative">
        <Reveal className="mx-auto max-w-3xl text-center">
          <svg aria-hidden="true" viewBox="0 0 40 48" className="mx-auto h-12 w-10 text-champagne-400" fill="none" stroke="currentColor" strokeWidth="0.9">
            <path d="M20 2 4 8v12.5C4 31 10.8 40 20 43.5 29.2 40 36 31 36 20.5V8L20 2Z" />
            <path d="M20 8 9 12.2v8.6c0 7.2 4.6 13.4 11 15.9 6.4-2.5 11-8.7 11-15.9v-8.6L20 8Z" opacity="0.5" />
            <path d="M14.5 23.5 18.5 27.5 26 19.5" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
          {section.eyebrow && <p className="eyebrow mt-8">{section.eyebrow}</p>}
          <h2 id="confidentiality-title" className="mt-6 text-h1">
            <EditorialTitle text={section.title ?? ''} />
          </h2>
          {section.body && <p className="mx-auto mt-8 max-w-xl text-body-l font-light text-ivory-200/85">{section.body}</p>}
        </Reveal>

        {points.length > 0 && (
          <ul className={`mt-20 grid gap-px overflow-hidden border border-[var(--line)] bg-[var(--line)] ${COLUMNS[Math.min(points.length, 4)] ?? ''}`}>
            {points.map((point, i) => (
              <Reveal as="li" key={point} delay={0.08 * i} className="bg-midnight-800 p-8 md:p-10">
                <span aria-hidden="true" className="font-display text-lg italic text-champagne-400">
                  {['I', 'II', 'III', 'IV', 'V', 'VI'][i] ?? i + 1}.
                </span>
                <p className="mt-6 font-light leading-relaxed text-ivory-200/85">{point}</p>
              </Reveal>
            ))}
          </ul>
        )}
      </Container>
    </Section>
  )
}
