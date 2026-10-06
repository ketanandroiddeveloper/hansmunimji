import { Link } from 'react-router'
import { PageHeader } from '../../components/layout/PageHeader'
import { Seo, SITE_URL } from '../../components/seo/Seo'
import { Container, Section } from '../../components/ui/Container'
import { Picture } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { useServices } from '../../lib/queries'
import { breadcrumbLd } from '../../lib/structuredData'
import { BOOKING_MODE_LABEL } from '../home/DisciplinesSection'

export default function PracticePage() {
  const { data: services, isPending, isError, error, refetch } = useServices()

  return (
    <>
      <Seo
        title="The practice"
        description="Five private disciplines — meditation, executive advisory, catharsis, in-person concierge meetings and keynote events — each shaped around the person."
        jsonLd={breadcrumbLd(SITE_URL, [
          { name: 'Home', path: '/' },
          { name: 'Practice', path: '/practice' },
        ])}
      />
      <PageHeader
        eyebrow="The practice"
        title="Five disciplines, one intention."
        intro="Each offering is private and shaped around the person. Some can be booked directly; most begin with a short application so that the work fits what you are seeking."
        crumbs={[{ label: 'Home', to: '/' }, { label: 'Practice' }]}
      />
      <Section tone="base" spacing="tight">
        <Container>
          {isPending && <LoadingBlock />}
          {isError && <ErrorBlock error={error} onRetry={() => refetch()} />}
          <ol className="space-y-24 md:space-y-32">
            {(services ?? []).map((s, i) => (
              <Reveal as="li" key={s.slug}>
                <Link to={`/practice/${s.slug}`} className="group grid items-center gap-10 md:grid-cols-12">
                  <div className={`relative overflow-hidden bg-midnight-800 md:col-span-5 ${i % 2 ? 'md:order-2 md:col-start-8' : ''}`} style={{ aspectRatio: '4 / 5' }}>
                    <Picture media={s.cover} sizes="(min-width: 768px) 40vw, 90vw" fill imgClassName="transition-transform duration-[1.6s] ease-expo group-hover:scale-[1.03]" />
                    <div aria-hidden="true" className="absolute inset-0 bg-gradient-to-t from-midnight-950/60 to-transparent" />
                    <div aria-hidden="true" className="absolute inset-4 border border-champagne-400/25" />
                  </div>
                  <div className={`md:col-span-6 ${i % 2 ? 'md:order-1 md:col-start-1' : 'md:col-start-7'}`}>
                    <span aria-hidden="true" className="font-display text-2xl italic text-champagne-400">
                      {s.numeral}.
                    </span>
                    <h2 className="mt-4 text-h1 transition-colors duration-500 group-hover:text-champagne-200">{s.title}</h2>
                    {s.subtitle && <p className="mt-4 font-display text-2xl italic text-ivory-200/80">{s.subtitle}</p>}
                    {s.summary && <p className="mt-8 max-w-lg font-light text-ivory-200/80">{s.summary}</p>}
                    <p className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-[0.66rem] uppercase tracking-[0.26em] text-slate-400">
                      <span>{BOOKING_MODE_LABEL[s.booking_mode]}</span>
                      {s.duration_label && <span>{s.duration_label}</span>}
                      {s.price_display && <span className="text-ivory-200">{s.price_display}</span>}
                    </p>
                    <span className="mt-10 inline-flex items-center gap-3 text-[0.72rem] font-medium uppercase tracking-[0.24em] text-ivory-50">
                      <span className="link-underline pb-1">Discover</span>
                      <svg aria-hidden="true" viewBox="0 0 24 8" className="h-2 w-6 transition-transform duration-500 group-hover:translate-x-1.5" fill="none" stroke="currentColor">
                        <path d="M0 4h23M19.5 0.5 23 4l-3.5 3.5" />
                      </svg>
                    </span>
                  </div>
                </Link>
              </Reveal>
            ))}
          </ol>
        </Container>
      </Section>
    </>
  )
}
