import type { HomeSections } from '../../lib/types'
import { Container, Section } from '../../components/ui/Container'
import { ArchFrame } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { EditorialTitle, Eyebrow } from '../../components/ui/Typography'

export function PhilosophySection({ section }: { section: NonNullable<HomeSections['philosophy']> }) {
  const pillars = section.pillars ?? []
  return (
    <Section tone="deep" labelledBy="philosophy-title" className="grain overflow-hidden">
      <div aria-hidden="true" className="pointer-events-none absolute left-[-10%] top-1/3 h-[50vh] w-[40vw] rounded-full bg-emerald-700/20 blur-[140px]" />
      <Container className="relative">
        <div className="grid gap-16 lg:grid-cols-12 lg:gap-10">
          <Reveal className="mx-auto w-full max-w-sm lg:col-span-4 lg:max-w-none">
            <div className="lg:sticky lg:top-[calc(var(--header-h)+var(--ribbon-h)+3rem)]">
              <ArchFrame media={section.image_media} ratio={3 / 4.2} sizes="(min-width: 1024px) 30vw, 80vw" />
            </div>
          </Reveal>

          <div className="lg:col-span-7 lg:col-start-6">
            <Reveal>
              {section.eyebrow && <Eyebrow>{section.eyebrow}</Eyebrow>}
              <h2 id="philosophy-title" className="mt-8 text-h1">
                <EditorialTitle text={section.title ?? ''} />
              </h2>
              {section.body && <p className="mt-8 max-w-lg text-body-l font-light text-ivory-200/85">{section.body}</p>}
            </Reveal>

            {pillars.length > 0 && (
              <ol className="mt-16 md:mt-20">
                {pillars.map((pillar, i) => (
                  <Reveal as="li" key={pillar.title} delay={0.08 * i} className="grid grid-cols-[4rem_1fr] gap-4 border-t border-[var(--line)] py-10 md:grid-cols-[6rem_1fr_1.4fr] md:gap-8">
                    <span aria-hidden="true" className="font-display text-4xl font-light italic leading-none text-champagne-400">
                      {pillar.numeral ?? i + 1}
                    </span>
                    <h3 className="font-display text-h3 font-normal">{pillar.title}</h3>
                    <p className="col-start-2 font-light text-ivory-200/75 md:col-start-auto">{pillar.body}</p>
                  </Reveal>
                ))}
              </ol>
            )}
          </div>
        </div>
      </Container>
    </Section>
  )
}
