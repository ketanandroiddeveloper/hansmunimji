import { useEffect, useMemo, useState } from 'react'
import { useLocation } from 'react-router'
import { PageHeader } from '../../components/layout/PageHeader'
import { Seo, SITE_URL } from '../../components/seo/Seo'
import { Container, Section } from '../../components/ui/Container'
import { Picture } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { EmptyState, ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { storedClientAccess } from '../../lib/accessTokens'
import { formatDuration, humanize as label } from '../../lib/format'
import { useAudio, useOptionalPage } from '../../lib/queries'
import { breadcrumbLd } from '../../lib/structuredData'
import type { AudioTrack } from '../../lib/types'
import { Scrubber, useAudioPlayer } from './AudioPlayer'

const DEFAULTS = {
  eyebrow: 'Library',
  title: 'Recordings, for the quiet hours.',
  intro: 'Guided meditations and reflections, to be listened to in stillness. Some recordings are reserved for clients of the practice.',
}

export default function LibraryPage() {
  const page = useOptionalPage('library')
  const audio = useAudio()
  const { hash } = useLocation()
  const [category, setCategory] = useState<string | null>(null)
  const player = useAudioPlayer()
  const hasClientAccess = useMemo(() => storedClientAccess().length > 0, [])

  const intro = { ...DEFAULTS, ...page.data?.sections, title: page.data?.sections?.title ?? page.data?.title ?? DEFAULTS.title }
  const tracks = audio.data ?? []
  const categories = useMemo(() => [...new Set(tracks.map((t) => t.category).filter((c): c is string => Boolean(c)))], [tracks])
  const visible = category ? tracks.filter((t) => t.category === category) : tracks
  const highlighted = hash.replace(/^#/, '')

  useEffect(() => {
    if (!highlighted || !audio.data) return
    document.getElementById(`track-${highlighted}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  }, [highlighted, audio.data])

  return (
    <>
      <Seo
        title="Library"
        description={intro.intro}
        jsonLd={breadcrumbLd(SITE_URL, [
          { name: 'Home', path: '/' },
          { name: 'Library', path: '/library' },
        ])}
      />
      <PageHeader eyebrow={intro.eyebrow} title={intro.title} intro={intro.intro} media={page.data?.sections?.image_media ?? null} crumbs={[{ label: 'Home', to: '/' }, { label: 'Library' }]} />

      <Section tone="base" spacing="tight" className="pb-32">
        <Container>
          {audio.isLoading && <LoadingBlock label="Opening the library" />}
          {audio.isError && <ErrorBlock error={audio.error} onRetry={() => audio.refetch()} />}
          {audio.isSuccess && tracks.length === 0 && (
            <EmptyState title="The library is being prepared.">New recordings will appear here as they are released.</EmptyState>
          )}

          {tracks.length > 0 && (
            <>
              {categories.length > 1 && (
                <div role="group" aria-label="Filter by theme" className="mb-12 flex flex-wrap gap-3">
                  {[null, ...categories].map((c) => (
                    <button
                      key={c ?? 'all'}
                      type="button"
                      aria-pressed={category === c}
                      onClick={() => setCategory(c)}
                      className={`border px-4 py-3 text-[0.66rem] lg:py-2 uppercase tracking-[0.22em] transition-colors ${
                        category === c ? 'border-champagne-400 text-champagne-200' : 'border-[var(--line-strong)] text-ivory-200/70 hover:text-ivory-50'
                      }`}
                    >
                      {c ? label(c) : 'All'}
                    </button>
                  ))}
                </div>
              )}

              <ul className="border-t border-[var(--line)]">
                {visible.map((track, i) => (
                  <TrackRow
                    key={track.slug}
                    track={track}
                    index={i}
                    highlighted={track.slug === highlighted}
                    active={player.state.slug === track.slug}
                    playing={player.state.slug === track.slug && player.state.playing}
                    loading={player.state.slug === track.slug && player.state.loading}
                    error={player.state.error?.slug === track.slug ? player.state.error.message : null}
                    time={player.time}
                    hasClientAccess={hasClientAccess}
                    onToggle={() => player.toggle(track)}
                    onSeek={player.seek}
                    onSkip={player.skip}
                  />
                ))}
              </ul>

              <p className="mt-14 max-w-2xl text-xs font-light leading-relaxed text-slate-400">
                These recordings are offered for reflection and relaxation. They are not a substitute for medical or psychological care. Please do not listen while driving or operating machinery.
              </p>
            </>
          )}
        </Container>
      </Section>
    </>
  )
}

function TrackRow({
  track,
  index,
  highlighted,
  active,
  playing,
  loading,
  error,
  time,
  hasClientAccess,
  onToggle,
  onSeek,
  onSkip,
}: {
  track: AudioTrack
  index: number
  highlighted: boolean
  active: boolean
  playing: boolean
  loading: boolean
  error: string | null
  time: { current: number; duration: number }
  hasClientAccess: boolean
  onToggle: () => void
  onSeek: (s: number) => void
  onSkip: (d: number) => void
}) {
  const locked = track.access === 'clients' && !hasClientAccess
  const unavailable = !track.available
  return (
    <Reveal as="li" delay={Math.min(index, 6) * 0.04} className={`border-b border-[var(--line)] transition-colors duration-700 ${highlighted ? 'bg-champagne-400/[0.04]' : ''}`}>
      <div id={`track-${track.slug}`} className="grid scroll-mt-40 grid-cols-[auto_1fr] items-center gap-6 py-8 md:grid-cols-[auto_5rem_1fr_auto] md:gap-8">
        <button
          type="button"
          onClick={onToggle}
          disabled={unavailable || locked}
          aria-label={unavailable ? `${track.title} — being prepared` : locked ? `${track.title} — reserved for clients` : `${playing ? 'Pause' : 'Play'} ${track.title}`}
          className="flex h-14 w-14 items-center justify-center rounded-full border border-[var(--line-strong)] text-ivory-50 transition-colors duration-500 hover:border-champagne-400 hover:bg-champagne-400 hover:text-midnight-950 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-[var(--line-strong)] disabled:hover:bg-transparent disabled:hover:text-ivory-50"
        >
          {loading ? (
            <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border border-current border-t-transparent" />
          ) : locked ? (
            <svg aria-hidden="true" viewBox="0 0 14 16" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.1">
              <rect x="1.5" y="7" width="11" height="8" rx="1" />
              <path d="M4 7V4.5a3 3 0 0 1 6 0V7" />
            </svg>
          ) : playing ? (
            <svg aria-hidden="true" viewBox="0 0 12 14" className="h-3.5 w-3.5" fill="currentColor">
              <rect x="1" width="3.5" height="14" />
              <rect x="7.5" width="3.5" height="14" />
            </svg>
          ) : (
            <svg aria-hidden="true" viewBox="0 0 12 14" className="ml-0.5 h-3.5 w-3.5" fill="currentColor">
              <path d="M0 0v14l12-7z" />
            </svg>
          )}
        </button>

        <div className="hidden md:block">{track.cover && <Picture media={track.cover} sizes="80px" className="aspect-square overflow-hidden rounded-full" imgClassName="h-full w-full object-cover" />}</div>

        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[0.66rem] uppercase tracking-[0.24em] text-slate-400">
            {track.category && <span>{label(track.category)}</span>}
            {track.access === 'clients' && <span className="text-champagne-200">For clients</span>}
            {unavailable && <span>Being prepared</span>}
          </p>
          <h2 className={`mt-2 font-display text-2xl md:text-3xl ${active ? 'text-champagne-200' : 'text-ivory-50'}`}>{track.title}</h2>
          {track.description && <p className="mt-2 max-w-2xl text-sm font-light leading-relaxed text-ivory-200/70">{track.description}</p>}
          {locked && <p className="mt-3 text-xs font-light text-slate-400">Clients can listen after opening the library from their private booking link in the same browser session.</p>}
        </div>

        <span className="hidden text-sm tabular-nums text-slate-400 md:block">{formatDuration(track.duration_seconds)}</span>
      </div>

      {active && (playing || time.current > 0) && (
        <div className="flex flex-wrap items-center gap-6 pb-8 md:pl-[calc(3.5rem+5rem+4rem)]">
          <button type="button" onClick={() => onSkip(-15)} aria-label="Back 15 seconds" className="-my-3 py-3 text-[0.66rem] uppercase tracking-[0.2em] text-ivory-200/70 hover:text-champagne-200">
            −15s
          </button>
          <div className="min-w-[12rem] flex-1">
            <Scrubber current={time.current} duration={time.duration || track.duration_seconds || 0} onSeek={onSeek} label={track.title} />
          </div>
          <button type="button" onClick={() => onSkip(15)} aria-label="Forward 15 seconds" className="-my-3 py-3 text-[0.66rem] uppercase tracking-[0.2em] text-ivory-200/70 hover:text-champagne-200">
            +15s
          </button>
        </div>
      )}
      {error && (
        <p role="alert" className="pb-6 text-sm text-danger-300 md:pl-[calc(3.5rem+5rem+4rem)]">
          {error}
        </p>
      )}
    </Reveal>
  )
}
