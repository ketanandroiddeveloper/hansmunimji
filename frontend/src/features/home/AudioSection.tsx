import { Link } from 'react-router'
import { formatDuration } from '../../lib/format'
import type { AudioTrack, HomeSections } from '../../lib/types'
import { ButtonLink } from '../../components/ui/Button'
import { Container, Section } from '../../components/ui/Container'
import { ArchFrame } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { EditorialTitle, Eyebrow } from '../../components/ui/Typography'

function Waveform() {
  const bars = [0.35, 0.6, 0.9, 0.5, 0.75, 1, 0.65, 0.4, 0.8, 0.55, 0.3, 0.7, 0.95, 0.6, 0.45, 0.85, 0.5, 0.35]
  return (
    <div aria-hidden="true" className="flex h-10 items-center gap-[3px]">
      {bars.map((h, i) => (
        <span key={i} className="w-[2px] rounded-full bg-champagne-400/60" style={{ height: `${h * 100}%` }} />
      ))}
    </div>
  )
}

export function AudioSection({ section, tracks }: { section: NonNullable<HomeSections['audio']>; tracks: AudioTrack[] }) {
  const featured = tracks.filter((t) => t.available).slice(0, 3)
  return (
    <Section tone="charcoal" labelledBy="audio-title" className="overflow-hidden">
      <Container>
        <div className="grid items-center gap-16 lg:grid-cols-12 lg:gap-10">
          <div className="lg:col-span-6">
            <Reveal>
              {section.eyebrow && <Eyebrow>{section.eyebrow}</Eyebrow>}
              <h2 id="audio-title" className="mt-8 text-h1">
                <EditorialTitle text={section.title ?? ''} />
              </h2>
              {section.body && <p className="mt-8 max-w-lg text-body-l font-light text-ivory-200/85">{section.body}</p>}
            </Reveal>

            {featured.length > 0 && (
              <ul className="mt-12 border-t border-[var(--line)]">
                {featured.map((track, i) => (
                  <Reveal as="li" key={track.slug} delay={0.06 * i} className="border-b border-[var(--line)]">
                    <Link to={`/library#${track.slug}`} className="group flex items-center gap-6 py-6">
                      <span
                        aria-hidden="true"
                        className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-[var(--line-strong)] transition-colors duration-500 group-hover:border-champagne-400 group-hover:bg-champagne-400 group-hover:text-midnight-950"
                      >
                        <svg viewBox="0 0 12 14" className="ml-0.5 h-3 w-3" fill="currentColor">
                          <path d="M0 0v14l12-7z" />
                        </svg>
                      </span>
                      <span className="flex-1">
                        <span className="block font-display text-2xl text-ivory-50 transition-colors group-hover:text-champagne-200">{track.title}</span>
                        {track.category && <span className="mt-1 block text-[0.66rem] uppercase tracking-[0.24em] text-slate-400">{track.category}</span>}
                      </span>
                      <span className="text-xs tabular-nums text-slate-400">
                        {track.access === 'clients' ? 'Clients' : formatDuration(track.duration_seconds)}
                      </span>
                    </Link>
                  </Reveal>
                ))}
              </ul>
            )}

            {section.cta && (
              <Reveal delay={0.15} className="mt-12 flex items-center gap-8">
                <ButtonLink to={section.cta.href} variant="link">
                  {section.cta.label}
                </ButtonLink>
                {featured.length === 0 && <Waveform />}
              </Reveal>
            )}
          </div>

          <Reveal delay={0.1} className="mx-auto w-full max-w-sm lg:col-span-4 lg:col-start-9 lg:max-w-none">
            <ArchFrame media={section.image_media} ratio={3 / 4} sizes="(min-width: 1024px) 30vw, 80vw" />
          </Reveal>
        </div>
      </Container>
    </Section>
  )
}
