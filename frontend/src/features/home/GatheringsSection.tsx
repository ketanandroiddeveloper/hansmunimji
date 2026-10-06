import { Link } from 'react-router'
import { dateParts, formatMoney, formatTime } from '../../lib/format'
import type { EventSummary, HomeSections } from '../../lib/types'
import { ButtonLink } from '../../components/ui/Button'
import { Container, Section } from '../../components/ui/Container'
import { Picture } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { EditorialTitle, Eyebrow } from '../../components/ui/Typography'

export function EventRow({ event }: { event: EventSummary }) {
  const parts = dateParts(event.starts_at, event.timezone)
  const place = [event.city?.name, event.venue].filter(Boolean).join(' · ')
  return (
    <Link to={`/gatherings/${event.slug}`} className="group grid grid-cols-[4.5rem_1fr] gap-6 py-8 md:grid-cols-[6rem_1fr_auto] md:items-center md:gap-10">
      <span className="flex flex-col items-start leading-none">
        <span className="font-display text-5xl font-light text-ivory-50">{parts.day}</span>
        <span className="mt-2 text-[0.66rem] uppercase tracking-[0.26em] text-champagne-400">
          {parts.month} {parts.year}
        </span>
      </span>
      <span>
        <span className="block font-display text-[1.9rem] leading-tight text-ivory-50 transition-colors duration-500 group-hover:text-champagne-200">{event.title}</span>
        <span className="mt-2 block text-sm font-light text-ivory-200/70">
          {parts.weekday}, {formatTime(event.starts_at, event.timezone)} local time{place ? ` · ${place}` : ''}
        </span>
      </span>
      <span className="col-start-2 flex flex-wrap items-center gap-x-6 gap-y-2 text-[0.66rem] uppercase tracking-[0.24em] text-slate-400 md:col-start-auto md:flex-col md:items-end">
        {event.status === 'cancelled' ? (
          <span className="text-danger-300">Cancelled</span>
        ) : (
          <>
            {event.price && <span className="text-ivory-200">{formatMoney(event.price)}</span>}
            {event.registration_mode === 'invitation' && <span>By invitation</span>}
            {event.registration_mode === 'application' && <span>By application</span>}
            {event.registration_state === 'not_yet_open' && <span>Opens soon</span>}
            {event.registration_state === 'full' && <span>Fully booked</span>}
            {event.registration_state === 'waitlist' && <span>Waitlist</span>}
            {(event.registration_state === 'open' || event.registration_state === 'application') && event.seats_left !== null && <span>{event.seats_left} places</span>}
          </>
        )}
      </span>
    </Link>
  )
}

export function GatheringsSection({ section, events }: { section: NonNullable<HomeSections['gatherings']>; events: EventSummary[] }) {
  const upcoming = events.slice(0, 3)
  return (
    <Section tone="deep" labelledBy="gatherings-title">
      <Container>
        <div className="grid gap-16 lg:grid-cols-12 lg:gap-10">
          <Reveal className="hidden lg:col-span-4 lg:block">
            <div className="relative overflow-hidden bg-midnight-800" style={{ aspectRatio: '3 / 4' }}>
              <Picture media={section.image_media} sizes="30vw" fill />
              <div aria-hidden="true" className="absolute inset-0 bg-gradient-to-t from-midnight-950/80 via-midnight-950/10 to-transparent" />
              <div aria-hidden="true" className="absolute inset-4 border border-champagne-400/30" />
            </div>
          </Reveal>

          <div className="lg:col-span-7 lg:col-start-6">
            <Reveal>
              {section.eyebrow && <Eyebrow>{section.eyebrow}</Eyebrow>}
              <h2 id="gatherings-title" className="mt-8 text-h1">
                <EditorialTitle text={section.title ?? ''} />
              </h2>
              {section.body && <p className="mt-8 max-w-lg text-body-l font-light text-ivory-200/85">{section.body}</p>}
            </Reveal>

            <div className="mt-14 border-t border-[var(--line)]">
              {upcoming.length > 0 ? (
                <ul>
                  {upcoming.map((event, i) => (
                    <Reveal as="li" key={event.slug} delay={0.06 * i} className="border-b border-[var(--line)]">
                      <EventRow event={event} />
                    </Reveal>
                  ))}
                </ul>
              ) : (
                <p className="border-b border-[var(--line)] py-10 font-display text-2xl italic text-ivory-200/70">New gatherings will be announced here.</p>
              )}
            </div>

            {section.cta && (
              <Reveal delay={0.1} className="mt-12">
                <ButtonLink to={section.cta.href} variant="link">
                  {section.cta.label}
                </ButtonLink>
              </Reveal>
            )}
          </div>
        </div>
      </Container>
    </Section>
  )
}
