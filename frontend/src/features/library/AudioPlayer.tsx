import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError } from '../../lib/api'
import { storedClientAccess } from '../../lib/accessTokens'
import { formatDuration } from '../../lib/format'
import type { AudioTrack } from '../../lib/types'

type StreamUrl = { url: string; expires_in: number }

/** Requests a short-lived signed URL, trying any client credentials held in this session. */
async function requestStream(track: AudioTrack): Promise<string> {
  if (track.access === 'public') {
    return (await api.post<StreamUrl>(`/audio/${encodeURIComponent(track.slug)}/stream`, {})).url
  }
  const credentials = storedClientAccess()
  let lastError: unknown = new ApiError(403, 'forbidden', 'This recording is reserved for clients of the practice.')
  for (const c of credentials) {
    try {
      return (await api.post<StreamUrl>(`/audio/${encodeURIComponent(track.slug)}/stream`, { reference: c.reference }, { headers: { 'X-Access-Token': c.token } })).url
    } catch (e) {
      lastError = e
      if (!(e instanceof ApiError && e.status === 403)) break
    }
  }
  throw lastError
}

export type PlayerState = { slug: string | null; playing: boolean; loading: boolean; error: { slug: string; message: string } | null }

/**
 * One shared <audio> element for the whole library. Signed URLs expire after a few minutes, so a
 * stream error transparently fetches a fresh URL and resumes from the same position.
 */
export function useAudioPlayer() {
  const audioRef = useRef<HTMLAudioElement | null>(null)
  const trackRef = useRef<AudioTrack | null>(null)
  const retried = useRef(false)
  const [state, setState] = useState<PlayerState>({ slug: null, playing: false, loading: false, error: null })
  const [time, setTime] = useState({ current: 0, duration: 0 })

  useEffect(() => {
    const audio = new Audio()
    audio.preload = 'metadata'
    audioRef.current = audio
    const onTime = () => setTime({ current: audio.currentTime, duration: Number.isFinite(audio.duration) ? audio.duration : 0 })
    const onPlay = () => setState((s) => ({ ...s, playing: true, loading: false }))
    const onPause = () => setState((s) => ({ ...s, playing: false }))
    const onWaiting = () => setState((s) => ({ ...s, loading: true }))
    const onPlaying = () => setState((s) => ({ ...s, loading: false }))
    const onError = async () => {
      const track = trackRef.current
      if (!track || retried.current) {
        setState((s) => ({ ...s, playing: false, loading: false, error: track ? { slug: track.slug, message: 'The recording could not be played. Please try again.' } : null }))
        return
      }
      retried.current = true
      const position = audio.currentTime
      try {
        audio.src = await requestStream(track)
        audio.currentTime = position
        await audio.play()
      } catch {
        setState((s) => ({ ...s, playing: false, loading: false, error: { slug: track.slug, message: 'The recording could not be played. Please try again.' } }))
      }
    }
    audio.addEventListener('timeupdate', onTime)
    audio.addEventListener('durationchange', onTime)
    audio.addEventListener('play', onPlay)
    audio.addEventListener('pause', onPause)
    audio.addEventListener('ended', onPause)
    audio.addEventListener('waiting', onWaiting)
    audio.addEventListener('playing', onPlaying)
    audio.addEventListener('error', onError)
    return () => {
      audio.pause()
      audio.removeAttribute('src')
      audio.load()
      audio.removeEventListener('timeupdate', onTime)
      audio.removeEventListener('durationchange', onTime)
      audio.removeEventListener('play', onPlay)
      audio.removeEventListener('pause', onPause)
      audio.removeEventListener('ended', onPause)
      audio.removeEventListener('waiting', onWaiting)
      audio.removeEventListener('playing', onPlaying)
      audio.removeEventListener('error', onError)
    }
  }, [])

  const toggle = useCallback(async (track: AudioTrack) => {
    const audio = audioRef.current
    if (!audio) return
    if (trackRef.current?.slug === track.slug && audio.src) {
      if (audio.paused) await audio.play().catch(() => undefined)
      else audio.pause()
      return
    }
    audio.pause()
    trackRef.current = track
    retried.current = false
    setTime({ current: 0, duration: track.duration_seconds ?? 0 })
    setState({ slug: track.slug, playing: false, loading: true, error: null })
    try {
      audio.src = await requestStream(track)
      await audio.play()
    } catch (e) {
      setState({ slug: track.slug, playing: false, loading: false, error: { slug: track.slug, message: e instanceof ApiError ? e.message : 'The recording could not be played. Please try again.' } })
    }
  }, [])

  const seek = useCallback((seconds: number) => {
    const audio = audioRef.current
    if (audio && Number.isFinite(seconds)) audio.currentTime = Math.max(0, Math.min(seconds, audio.duration || seconds))
  }, [])

  const skip = useCallback((delta: number) => {
    const audio = audioRef.current
    if (audio) audio.currentTime = Math.max(0, Math.min(audio.currentTime + delta, audio.duration || audio.currentTime + delta))
  }, [])

  return { state, time, toggle, seek, skip }
}

export function Scrubber({ current, duration, onSeek, label }: { current: number; duration: number; onSeek: (s: number) => void; label: string }) {
  const pct = duration > 0 ? (current / duration) * 100 : 0
  return (
    <div className="flex items-center gap-4">
      <span className="w-12 text-right text-xs tabular-nums text-slate-400">{formatDuration(current) || '0:00'}</span>
      <input
        type="range"
        min={0}
        max={Math.max(1, Math.floor(duration))}
        step={1}
        value={Math.floor(current)}
        onChange={(e) => onSeek(Number(e.target.value))}
        aria-label={`Seek within ${label}`}
        aria-valuetext={`${formatDuration(current) || '0:00'} of ${formatDuration(duration) || 'unknown length'}`}
        className="h-px flex-1 cursor-pointer appearance-none bg-[var(--line-strong)] accent-champagne-400 [&::-webkit-slider-thumb]:h-3 [&::-webkit-slider-thumb]:w-3 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-champagne-400"
        style={{ backgroundImage: `linear-gradient(to right, var(--color-champagne-400) ${pct}%, transparent ${pct}%)` }}
      />
      <span className="w-12 text-xs tabular-nums text-slate-400">{formatDuration(duration) || '—'}</span>
    </div>
  )
}
