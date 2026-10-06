import type { HomeSections } from '../../lib/types'
import { ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { ConfidentialitySeal } from '../../components/ui/Ornaments'
import { Picture } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { EditorialTitle } from '../../components/ui/Typography'

export function InquirySection({ section, hasDirectBooking }: { section: NonNullable<HomeSections['inquiry']>; hasDirectBooking: boolean }) {
  return (
    <section aria-labelledby="inquiry-title" className="relative isolate overflow-hidden bg-midnight-950 py-32 md:py-48">
      <div aria-hidden="true" className="absolute inset-0 -z-10">
        <Picture media={section.image_media} sizes="100vw" fill imgClassName="grayscale-[35%]" alt="" />
        <div className="absolute inset-0 bg-midnight-950/80" />
        <div className="absolute inset-0 bg-gradient-to-b from-midnight-950 via-transparent to-midnight-950" />
      </div>
      <Container>
        <Reveal className="mx-auto max-w-3xl text-center">
          {section.eyebrow && <p className="eyebrow">{section.eyebrow}</p>}
          <h2 id="inquiry-title" className="mt-8 text-display font-light">
            <EditorialTitle text={section.title ?? ''} />
          </h2>
          {section.body && <p className="mx-auto mt-10 max-w-xl text-body-l font-light text-ivory-200/85">{section.body}</p>}
          <div className="mt-14 flex flex-col items-center justify-center gap-8 sm:flex-row sm:gap-12">
            {section.cta && (
              <ButtonLink to={section.cta.href} variant="solid" size="lg" arrow>
                {section.cta.label}
              </ButtonLink>
            )}
            {hasDirectBooking && (
              <ButtonLink to="/consultation" variant="link">
                Or book a consultation
              </ButtonLink>
            )}
          </div>
          <ConfidentialitySeal className="mt-14 justify-center" />
        </Reveal>
      </Container>
    </section>
  )
}
