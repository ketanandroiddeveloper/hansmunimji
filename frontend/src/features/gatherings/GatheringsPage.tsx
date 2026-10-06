import { useState } from 'react'
import { PageHeader } from '../../components/layout/PageHeader'
import { Seo, SITE_URL } from '../../components/seo/Seo'
import { Container, Section } from '../../components/ui/Container'
import { Reveal } from '../../components/ui/Reveal'
import { EmptyState, ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { useEvents, useOptionalPage } from '../../lib/queries'
import { breadcrumbLd } from '../../lib/structuredData'
import { EventRow } from '../home/GatheringsSection'

const DEFAULTS = {
  eyebrow: 'Gatherings',
  title: 'Gatherings, held in stillness.',
  intro: 'Full-moon circles, retreats and evenings of reflection — intimate in size, announced here first.',
}

export default function GatheringsPage() {
  const page = useOptionalPage('gatherings')
  const [when, setWhen] = useState<'upcoming' | 'past'>('upcoming')
  const events = useEvents({ when })
  const intro = { ...DEFAULTS, ...page.data?.sections, title: page.data?.sections?.title ?? page.data?.title ?? DEFAULTS.title }

  return (
    <>
      <Seo
        title="Gatherings"
        description={intro.intro}
        jsonLd={breadcrumbLd(SITE_URL, [
          { name: 'Home', path: '/' },
          { name: 'Gatherings', path: '/gatherings' },
        ])}
      />
      <PageHeader eyebrow={intro.eyebrow} title={intro.title} intro={intro.intro} media={page.data?.sections?.image_media ?? null} crumbs={[{ label: 'Home', to: '/' }, { label: 'Gatherings' }]} />
      <Section tone="base" spacing="tight" className="pb-32">
        <Container>
          <div role="group" aria-label="Show gatherings" className="mb-10 flex gap-8 border-b border-[var(--line)]">
            {(['upcoming', 'past'] as const).map((w) => (
              <button
                key={w}
                type="button"
                aria-pressed={when === w}
                onClick={() => setWhen(w)}
                className={`-mb-px border-b pb-4 text-[0.7rem] uppercase tracking-[0.24em] transition-colors ${when === w ? 'border-champagne-400 text-champagne-200' : 'border-transparent text-slate-400 hover:text-ivory-50'}`}
              >
                {w === 'upcoming' ? 'Upcoming' : 'Past'}
              </button>
            ))}
          </div>

          {events.isLoading && <LoadingBlock />}
          {events.isError && <ErrorBlock error={events.error} onRetry={() => events.refetch()} />}
          {events.isSuccess && events.data.length === 0 && (
            <EmptyState title={when === 'upcoming' ? 'New gatherings will be announced here.' : 'No past gatherings to show yet.'} />
          )}
          {events.isSuccess && events.data.length > 0 && (
            <ul className="border-t border-[var(--line)]">
              {events.data.map((e, i) => (
                <Reveal as="li" key={e.slug} delay={Math.min(i, 6) * 0.05} className="border-b border-[var(--line)]">
                  <EventRow event={e} />
                </Reveal>
              ))}
            </ul>
          )}
        </Container>
      </Section>
    </>
  )
}
