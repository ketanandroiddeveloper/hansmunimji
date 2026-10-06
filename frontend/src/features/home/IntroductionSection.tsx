import type { HomeSections, Practitioner } from '../../lib/types'
import { ButtonLink } from '../../components/ui/Button'
import { Container, Section } from '../../components/ui/Container'
import { ArchFrame } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { EditorialTitle, Eyebrow, Paragraphs } from '../../components/ui/Typography'

export function IntroductionSection({ intro, practitioner }: { intro: NonNullable<HomeSections['introduction']>; practitioner?: Practitioner }) {
  return (
    <Section tone="deep" labelledBy="intro-title">
      <Container>
        <div className="grid items-center gap-16 lg:grid-cols-12 lg:gap-10">
          <Reveal className="order-2 lg:order-1 lg:col-span-5">
            <figure className="mx-auto max-w-sm lg:mx-0 lg:max-w-none lg:pr-10">
              <ArchFrame media={intro.image_media} ratio={4 / 5} />
              {practitioner && (
                <figcaption className="mt-10 flex items-baseline justify-between gap-6 border-t border-[var(--line)] pt-5">
                  <span className="font-display text-xl text-ivory-50">{practitioner.full_name}</span>
                  {practitioner.title && <span className="text-right text-[0.68rem] uppercase tracking-[0.24em] text-slate-400">{practitioner.title}</span>}
                </figcaption>
              )}
            </figure>
          </Reveal>

          <div className="order-1 lg:order-2 lg:col-span-6 lg:col-start-7">
            <Reveal>
              {intro.eyebrow && <Eyebrow>{intro.eyebrow}</Eyebrow>}
              <h2 id="intro-title" className="mt-8 text-h1">
                <EditorialTitle text={intro.title ?? ''} />
              </h2>
            </Reveal>
            <Reveal delay={0.12}>
              <Paragraphs text={intro.body} lead className="mt-10 max-w-xl" />
            </Reveal>
            {intro.cta && (
              <Reveal delay={0.2} className="mt-12">
                <ButtonLink to={intro.cta.href} variant="link">
                  {intro.cta.label}
                </ButtonLink>
              </Reveal>
            )}
          </div>
        </div>
      </Container>
    </Section>
  )
}
