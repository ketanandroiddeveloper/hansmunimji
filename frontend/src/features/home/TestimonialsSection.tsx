import type { Testimonial } from '../../lib/types'
import { Container, Section } from '../../components/ui/Container'
import { Reveal } from '../../components/ui/Reveal'

/** Rendered only for testimonials the practice has marked as authorised with recorded consent. */
export function TestimonialsSection({ testimonials }: { testimonials: Testimonial[] }) {
  return (
    <Section tone="base" labelledBy="testimonials-title">
      <Container>
        <h2 id="testimonials-title" className="sr-only">
          In their words
        </h2>
        <ul className="grid gap-16 md:grid-cols-2">
          {testimonials.slice(0, 4).map((t, i) => (
            <Reveal as="li" key={i} delay={0.08 * i}>
              <figure>
                <span aria-hidden="true" className="font-display text-6xl leading-none text-champagne-400/60">
                  “
                </span>
                <blockquote className="mt-2 font-display text-h3 font-light italic leading-snug text-ivory-50">{t.quote}</blockquote>
                {(t.attribution || t.role) && (
                  <figcaption className="mt-8 text-[0.68rem] uppercase tracking-[0.26em] text-slate-400">
                    {[t.attribution, t.role].filter(Boolean).join(' · ')}
                  </figcaption>
                )}
              </figure>
            </Reveal>
          ))}
        </ul>
      </Container>
    </Section>
  )
}
