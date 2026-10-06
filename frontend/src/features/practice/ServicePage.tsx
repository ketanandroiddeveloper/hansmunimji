import { useParams } from 'react-router'
import { PageHeader } from '../../components/layout/PageHeader'
import { Seo, SITE_URL } from '../../components/seo/Seo'
import { ButtonLink } from '../../components/ui/Button'
import { Container, Section } from '../../components/ui/Container'
import { FaqList } from '../../components/ui/FaqList'
import { ConfidentialitySeal } from '../../components/ui/Ornaments'
import { Reveal } from '../../components/ui/Reveal'
import { RichText } from '../../components/ui/RichText'
import { ErrorBlock, isNotFound, LoadingBlock } from '../../components/ui/States'
import { Eyebrow } from '../../components/ui/Typography'
import { formatMoney } from '../../lib/format'
import { useAppointmentTypes, useService, useServices } from '../../lib/queries'
import { breadcrumbLd, faqLd, serviceLd } from '../../lib/structuredData'
import type { AppointmentType, ServiceDetail } from '../../lib/types'
import { NotFoundPage } from '../../pages/NotFoundPage'
import { BOOKING_MODE_LABEL } from '../home/DisciplinesSection'

const FORMAT_LABEL = { google_meet: 'Google Meet', phone: 'Telephone', in_person: 'In person' } as const

function primaryAction(service: ServiceDetail, types: AppointmentType[]) {
  if (service.booking_mode === 'direct' && types.length > 0) {
    return { to: `/consultation?type=${types[0].slug}`, label: 'Book a session' }
  }
  if (service.booking_mode === 'inquiry') {
    return { to: `/private-access?service=${service.slug}`, label: 'Make an inquiry' }
  }
  return { to: `/private-access?service=${service.slug}`, label: 'Request private access' }
}

export default function ServicePage() {
  const { slug = '' } = useParams()
  const { data: service, isPending, isError, error, refetch } = useService(slug)
  const { data: types = [] } = useAppointmentTypes(slug)
  const { data: all = [] } = useServices()

  if (isPending) return <LoadingBlock className="min-h-[80vh]" />
  if (isError) return isNotFound(error) ? <NotFoundPage /> : <ErrorBlock error={error} onRetry={() => refetch()} className="min-h-[80vh]" />

  const action = primaryAction(service, types)
  const index = all.findIndex((s) => s.slug === service.slug)
  const next = index >= 0 && all.length > 1 ? all[(index + 1) % all.length] : null
  const jsonLd = [
    serviceLd(SITE_URL, service),
    breadcrumbLd(SITE_URL, [
      { name: 'Home', path: '/' },
      { name: 'Practice', path: '/practice' },
      { name: service.title, path: `/practice/${service.slug}` },
    ]),
    ...(faqLd(service.faqs) ? [faqLd(service.faqs)!] : []),
  ]

  return (
    <>
      <Seo title={service.title} description={service.summary} image={service.cover?.url} override={service.seo} jsonLd={jsonLd} />
      <PageHeader
        eyebrow={`${service.numeral ? `${service.numeral}. ` : ''}${BOOKING_MODE_LABEL[service.booking_mode]}`}
        title={service.title}
        intro={service.subtitle ? <p className="font-display text-h3 italic text-ivory-200/90">{service.subtitle}</p> : undefined}
        media={service.cover}
        crumbs={[{ label: 'Home', to: '/' }, { label: 'Practice', to: '/practice' }, { label: service.title }]}
      >
        <div className="flex flex-wrap items-center gap-x-10 gap-y-6">
          <ButtonLink to={action.to} variant="solid" size="lg" arrow>
            {action.label}
          </ButtonLink>
          {service.duration_label && <span className="text-[0.68rem] uppercase tracking-[0.26em] text-slate-400">{service.duration_label}</span>}
        </div>
      </PageHeader>

      <Section tone="base">
        <Container>
          <div className="grid gap-16 lg:grid-cols-12 lg:gap-10">
            <div className="lg:col-span-7">
              {service.summary && (
                <Reveal>
                  <p className="font-display text-h2 font-light leading-snug text-ivory-50">{service.summary}</p>
                </Reveal>
              )}
              <Reveal delay={0.1}>
                <RichText html={service.body} className="mt-12" />
              </Reveal>
            </div>

            <aside className="lg:col-span-4 lg:col-start-9" aria-label="At a glance">
              <div className="lg:sticky lg:top-[calc(var(--header-h)+var(--ribbon-h)+2rem)]">
                {service.highlights.length > 0 && (
                  <Reveal>
                    <Eyebrow>At a glance</Eyebrow>
                    <ul className="mt-8 border-t border-[var(--line)]">
                      {service.highlights.map((h) => (
                        <li key={h} className="border-b border-[var(--line)] py-4 font-light text-ivory-200/85">
                          {h}
                        </li>
                      ))}
                    </ul>
                  </Reveal>
                )}

                {types.length > 0 && (
                  <Reveal delay={0.1} className="mt-14">
                    <Eyebrow>Sessions</Eyebrow>
                    <ul className="mt-8 space-y-8">
                      {types.map((t) => (
                        <li key={t.slug} className="border-l border-champagne-400/40 pl-6">
                          <p className="font-display text-2xl text-ivory-50">{t.title}</p>
                          <p className="mt-2 text-sm font-light text-slate-400">
                            {t.duration_minutes} minutes · {t.formats.map((f) => FORMAT_LABEL[f]).join(', ')}
                          </p>
                          {t.prices.length > 0 && <p className="mt-2 text-sm text-ivory-200">{t.prices.map((p) => formatMoney(p)).join(' · ')}</p>}
                        </li>
                      ))}
                    </ul>
                  </Reveal>
                )}

                <ConfidentialitySeal className="mt-14" />
              </div>
            </aside>
          </div>
        </Container>
      </Section>

      {service.offerings.length > 0 && (
        <Section tone="deep">
          <Container>
            <Reveal>
              <Eyebrow>Formats</Eyebrow>
              <h2 className="mt-8 text-h1">Ways to work together.</h2>
            </Reveal>
            <ol className="mt-16 grid gap-px border border-[var(--line)] bg-[var(--line)] md:grid-cols-2">
              {service.offerings.map((o, i) => (
                <Reveal as="li" key={o.title} delay={0.06 * i} className="bg-midnight-950 p-8 md:p-12">
                  <span aria-hidden="true" className="font-display text-lg italic text-champagne-400">
                    {['I', 'II', 'III', 'IV', 'V', 'VI'][i] ?? i + 1}.
                  </span>
                  <h3 className="mt-6 text-h3 font-normal">{o.title}</h3>
                  {o.description && <p className="mt-4 font-light text-ivory-200/80">{o.description}</p>}
                </Reveal>
              ))}
            </ol>
          </Container>
        </Section>
      )}

      {service.faqs.length > 0 && (
        <Section tone="base">
          <Container className="grid gap-12 lg:grid-cols-12">
            <Reveal className="lg:col-span-4">
              <Eyebrow>Questions</Eyebrow>
              <h2 className="mt-8 text-h2">Before you begin.</h2>
            </Reveal>
            <div className="lg:col-span-7 lg:col-start-6">
              <FaqList faqs={service.faqs} />
            </div>
          </Container>
        </Section>
      )}

      <Section tone="raised" spacing="tight">
        <Container className="flex flex-col items-start justify-between gap-10 md:flex-row md:items-center">
          <div>
            <p className="font-display text-h2 text-ivory-50">{service.booking_mode === 'direct' ? 'Ready when you are.' : 'Begin with a conversation.'}</p>
            <p className="mt-3 font-light text-ivory-200/75">
              {service.booking_mode === 'direct'
                ? 'Choose a time that suits you; confirmation is sent once payment is verified.'
                : 'Applications are read personally by the private office and held in confidence.'}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-8">
            <ButtonLink to={action.to} variant="solid" arrow>
              {action.label}
            </ButtonLink>
            {next && (
              <ButtonLink to={`/practice/${next.slug}`} variant="link">
                Next: {next.title}
              </ButtonLink>
            )}
          </div>
        </Container>
      </Section>
    </>
  )
}
