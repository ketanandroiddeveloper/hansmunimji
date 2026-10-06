import { PageHeader } from '../../components/layout/PageHeader'
import { Seo, SITE_URL } from '../../components/seo/Seo'
import { ButtonLink } from '../../components/ui/Button'
import { Container, Section } from '../../components/ui/Container'
import { ArchFrame, Picture } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { RichText } from '../../components/ui/RichText'
import { ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { Eyebrow } from '../../components/ui/Typography'
import { usePractitioner } from '../../lib/queries'
import { breadcrumbLd, personLd } from '../../lib/structuredData'

const KIND_LABEL: Record<string, string> = {
  qualification: 'Qualifications',
  certification: 'Certifications',
  experience: 'Professional experience',
  award: 'Recognition',
  publication: 'Publications',
  speaking: 'Speaking',
  interview: 'Interviews & media',
}

export default function PractitionerPage() {
  const { data: p, isPending, isError, error, refetch } = usePractitioner()

  if (isPending) return <LoadingBlock className="min-h-[80vh]" />
  if (isError) return <ErrorBlock error={error} onRetry={() => refetch()} className="min-h-[80vh]" />

  const name = [p.honorific, p.full_name].filter(Boolean).join(' ')
  const qualificationGroups = Object.entries(p.qualifications).filter(([, items]) => items.length > 0)

  return (
    <>
      <Seo
        title={`About ${p.full_name}`}
        description={p.short_bio}
        image={p.portrait?.url}
        type="profile"
        jsonLd={[
          { '@context': 'https://schema.org', ...personLd(SITE_URL, p) },
          breadcrumbLd(SITE_URL, [
            { name: 'Home', path: '/' },
            { name: 'Practitioner', path: '/practitioner' },
          ]),
        ]}
      />
      <PageHeader
        eyebrow={p.title ?? 'The practitioner'}
        title={name}
        intro={p.short_bio}
        media={p.portrait}
        crumbs={[{ label: 'Home', to: '/' }, { label: 'Practitioner' }]}
      />

      {(p.biography || p.philosophy) && (
        <Section tone="base">
          <Container className="grid gap-16 lg:grid-cols-12 lg:gap-10">
            <div className="lg:col-span-7">
              {p.biography && (
                <Reveal>
                  <Eyebrow>Biography</Eyebrow>
                  <RichText html={p.biography} className="mt-10" />
                </Reveal>
              )}
              {p.philosophy && (
                <Reveal className={p.biography ? 'mt-20' : ''}>
                  <Eyebrow>Philosophy</Eyebrow>
                  <RichText html={p.philosophy} className="mt-10 [&>p:first-child]:font-display [&>p:first-child]:text-h3 [&>p:first-child]:leading-snug [&>p:first-child]:text-ivory-50" />
                </Reveal>
              )}
            </div>
            {p.secondary_image && (
              <Reveal delay={0.1} className="mx-auto w-full max-w-sm lg:col-span-4 lg:col-start-9 lg:max-w-none">
                <div className="lg:sticky lg:top-[calc(var(--header-h)+var(--ribbon-h)+3rem)]">
                  <ArchFrame media={p.secondary_image} ratio={3 / 4} sizes="(min-width: 1024px) 30vw, 80vw" />
                </div>
              </Reveal>
            )}
          </Container>
        </Section>
      )}

      {(p.approach || p.expertise.length > 0) && (
        <Section tone="deep">
          <Container className="grid gap-16 lg:grid-cols-12 lg:gap-10">
            {p.expertise.length > 0 && (
              <Reveal className="lg:col-span-4">
                <Eyebrow>Areas of practice</Eyebrow>
                <ul className="mt-10 border-t border-[var(--line)]">
                  {p.expertise.map((e, i) => (
                    <li key={e} className="flex items-baseline gap-5 border-b border-[var(--line)] py-5">
                      <span aria-hidden="true" className="font-display text-sm italic text-champagne-400">
                        {String(i + 1).padStart(2, '0')}
                      </span>
                      <span className="font-display text-2xl text-ivory-50">{e}</span>
                    </li>
                  ))}
                </ul>
              </Reveal>
            )}
            {p.approach && (
              <Reveal delay={0.1} className="lg:col-span-7 lg:col-start-6">
                <Eyebrow>Approach</Eyebrow>
                <RichText html={p.approach} className="mt-10" />
              </Reveal>
            )}
          </Container>
        </Section>
      )}

      {p.experience.length > 0 && (
        <Section tone="base">
          <Container>
            <Reveal>
              <Eyebrow>Experience</Eyebrow>
            </Reveal>
            <ol className="mt-12 border-l border-[var(--line-strong)] pl-8 md:pl-12">
              {p.experience.map((x, i) => (
                <Reveal as="li" key={`${x.title}-${i}`} delay={0.05 * i} className="relative pb-12 last:pb-0">
                  <span aria-hidden="true" className="absolute -left-[calc(2rem+4.5px)] top-2 h-2 w-2 rounded-full bg-champagne-400 md:-left-[calc(3rem+4.5px)]" />
                  {x.period && <p className="text-[0.66rem] uppercase tracking-[0.26em] text-champagne-400">{x.period}</p>}
                  <p className="mt-2 font-display text-2xl text-ivory-50">{x.title}</p>
                  {x.description && <p className="mt-2 max-w-2xl font-light text-ivory-200/75">{x.description}</p>}
                </Reveal>
              ))}
            </ol>
          </Container>
        </Section>
      )}

      {qualificationGroups.length > 0 && (
        <Section tone="deep">
          <Container>
            <div className="grid gap-16 md:grid-cols-2">
              {qualificationGroups.map(([kind, items]) => (
                <Reveal key={kind}>
                  <Eyebrow>{KIND_LABEL[kind] ?? kind}</Eyebrow>
                  <ul className="mt-8 border-t border-[var(--line)]">
                    {items.map((q) => (
                      <li key={`${q.title}-${q.year ?? ''}`} className="border-b border-[var(--line)] py-5">
                        <p className="flex items-baseline justify-between gap-6">
                          {q.url ? (
                            <a href={q.url} target="_blank" rel="noopener noreferrer" className="font-display text-xl text-ivory-50 underline decoration-champagne-400/30 underline-offset-4 hover:text-champagne-200">
                              {q.title}
                            </a>
                          ) : (
                            <span className="font-display text-xl text-ivory-50">{q.title}</span>
                          )}
                          {q.year && <span className="text-xs tabular-nums text-slate-400">{q.year}</span>}
                        </p>
                        {q.institution && <p className="mt-1 text-sm font-light text-ivory-200/70">{q.institution}</p>}
                        {q.description && <p className="mt-2 text-sm font-light text-slate-400">{q.description}</p>}
                      </li>
                    ))}
                  </ul>
                </Reveal>
              ))}
            </div>
          </Container>
        </Section>
      )}

      {p.gallery.length > 0 && (
        <Section tone="base" spacing="tight">
          <Container>
            <ul className="columns-2 gap-4 md:columns-3">
              {p.gallery.map((m) => (
                <li key={m.id} className="mb-4 break-inside-avoid">
                  <Picture media={m} sizes="(min-width: 768px) 30vw, 45vw" />
                </li>
              ))}
            </ul>
          </Container>
        </Section>
      )}

      <Section tone="raised" spacing="tight">
        <Container className="flex flex-col items-start justify-between gap-8 md:flex-row md:items-center">
          <p className="font-display text-h2 text-ivory-50">Begin with a conversation.</p>
          <div className="flex flex-wrap gap-8">
            <ButtonLink to="/private-access" variant="solid" arrow>
              Request private access
            </ButtonLink>
            <ButtonLink to="/practice" variant="link">
              Explore the practice
            </ButtonLink>
          </div>
        </Container>
      </Section>
    </>
  )
}
