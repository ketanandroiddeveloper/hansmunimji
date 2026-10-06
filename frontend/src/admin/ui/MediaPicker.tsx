import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import type { Media } from '../../lib/types'
import { adminApi, errorMessage } from '../lib/adminApi'
import { Dialog } from './Dialog'
import { FieldWrap, Input, SearchInput } from './Fields'
import { AButton, Notice, Pagination, Spinner } from './Primitives'

export function thumbUrl(m: Media): string | undefined {
  const set = m.srcset?.webp ?? m.srcset?.jpg
  return set ? set.split(',')[0].trim().split(' ')[0] : m.url
}

export function MediaThumb({ media, className = 'h-16 w-16' }: { media: Media; className?: string }) {
  const src = thumbUrl(media)
  if (media.mime.startsWith('video/')) {
    return <span className={`${className} flex items-center justify-center bg-midnight-800 text-[0.6rem] uppercase tracking-widest text-slate-400`}>Video</span>
  }
  return src ? (
    <img src={src} alt={media.alt} loading="lazy" className={`${className} bg-midnight-800 object-cover`} style={media.focal_point ? { objectPosition: media.focal_point } : undefined} />
  ) : (
    <span className={`${className} flex items-center justify-center bg-midnight-800 text-[0.6rem] uppercase text-slate-400`}>Private</span>
  )
}

export function MediaUploadForm({ onUploaded, defaultCategory = 'general' }: { onUploaded: (m: Media) => void; defaultCategory?: string }) {
  const [file, setFile] = useState<File | null>(null)
  const [alt, setAlt] = useState('')
  const [caption, setCaption] = useState('')
  const [category, setCategory] = useState(defaultCategory)
  const [error, setError] = useState<string | null>(null)
  const fileId = useId()
  const client = useQueryClient()
  const upload = useMutation({
    mutationFn: () => {
      const form = new FormData()
      form.append('file', file!)
      form.append('alt', alt.trim())
      if (caption.trim()) form.append('caption', caption.trim())
      if (category.trim()) form.append('category', category.trim())
      return adminApi.post<Media>('/admin/media', form)
    },
    onSuccess: (m) => {
      client.invalidateQueries({ queryKey: ['admin', 'media'] })
      setFile(null)
      setAlt('')
      setCaption('')
      onUploaded(m)
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setError(null)
    if (!file) return setError('Choose an image or video to upload.')
    if (alt.trim().length < 3) return setError('Describe the image for screen-reader users (alt text).')
    upload.mutate()
  }

  return (
    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
      <FieldWrap id={fileId} label="File" required hint="JPEG, PNG, WebP or AVIF images are optimised to AVIF/WebP automatically. MP4 video is stored as-is." className="sm:col-span-2">
        <input
          id={fileId}
          type="file"
          accept="image/jpeg,image/png,image/webp,image/avif,video/mp4"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          className="block w-full text-sm text-ivory-200 file:mr-4 file:border file:border-ivory-50/20 file:bg-transparent file:px-3 file:py-1.5 file:text-xs file:uppercase file:tracking-[0.14em] file:text-ivory-50 hover:file:border-champagne-400"
        />
      </FieldWrap>
      <Input label="Alt text" required value={alt} onChange={(e) => setAlt(e.target.value)} maxLength={255} hint="What the image shows, for people who cannot see it." className="sm:col-span-2" />
      <Input label="Caption" value={caption} onChange={(e) => setCaption(e.target.value)} maxLength={500} />
      <Input label="Category" value={category} onChange={(e) => setCategory(e.target.value)} maxLength={60} hint="Lowercase, e.g. portraits, sessions." />
      {error && (
        <div className="sm:col-span-2">
          <Notice tone="error">{error}</Notice>
        </div>
      )}
      <div className="sm:col-span-2">
        <AButton type="submit" variant="primary" loading={upload.isPending}>
          Upload
        </AButton>
      </div>
    </form>
  )
}

export function MediaBrowser({ onSelect, selectedId }: { onSelect: (m: Media) => void; selectedId?: number | null }) {
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [uploading, setUploading] = useState(false)
  const list = useQuery({
    queryKey: ['admin', 'media', 'picker', q, page],
    queryFn: () => adminApi.list<Media>('/admin/media', { query: { q, page, per_page: 24, type: 'image' } }),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SearchInput
          value={q}
          onChange={(v) => {
            setQ(v)
            setPage(1)
          }}
          placeholder="Search alt text or file name"
        />
        <AButton variant="ghost" onClick={() => setUploading((u) => !u)}>
          {uploading ? 'Back to library' : 'Upload new'}
        </AButton>
      </div>
      {uploading ? (
        <MediaUploadForm
          onUploaded={(m) => {
            setUploading(false)
            onSelect(m)
          }}
        />
      ) : list.isLoading ? (
        <Spinner />
      ) : list.isError ? (
        <Notice tone="error">{errorMessage(list.error)}</Notice>
      ) : list.data!.items.length === 0 ? (
        <p className="py-10 text-center text-sm text-slate-400">No images found.</p>
      ) : (
        <>
          <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4">
            {list.data!.items.map((m) => (
              <li key={m.id}>
                <button
                  type="button"
                  onClick={() => onSelect(m)}
                  className={`group block w-full border text-left transition-colors ${m.id === selectedId ? 'border-champagne-400' : 'border-transparent hover:border-ivory-50/40'}`}
                  aria-pressed={m.id === selectedId}
                >
                  <MediaThumb media={m} className="aspect-square w-full" />
                  <span className="block truncate px-1 py-1 text-[0.65rem] text-slate-400">{m.alt}</span>
                </button>
              </li>
            ))}
          </ul>
          <Pagination page={list.data!.meta.page} totalPages={list.data!.meta.total_pages} total={list.data!.meta.total} onPage={setPage} />
        </>
      )}
    </div>
  )
}

export function MediaPicker({ label, value, onChange, error, hint }: { label: ReactNode; value: number | null; onChange: (id: number | null) => void; error?: string; hint?: ReactNode }) {
  const [open, setOpen] = useState(false)
  const id = useId()
  const current = useQuery({
    queryKey: ['admin', 'media', 'item', value],
    queryFn: () => adminApi.get<Media>(`/admin/media/${value}`),
    enabled: value !== null && value > 0,
    staleTime: 60_000,
  })

  return (
    <FieldWrap id={id} label={label} error={error} hint={hint}>
      <div className="flex items-center gap-4 border border-[var(--line-strong)] bg-midnight-950/70 p-2">
        {value && current.data ? <MediaThumb media={current.data} /> : <span className="flex h-16 w-16 items-center justify-center bg-midnight-800 text-[0.6rem] uppercase tracking-widest text-slate-400">None</span>}
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-light text-ivory-200">{value ? (current.data?.alt ?? (current.isError ? 'Image unavailable' : 'Loading…')) : 'No image selected'}</p>
          <div className="mt-1 flex gap-1">
            <AButton id={id} variant="ghost" className="!px-0 !py-1" onClick={() => setOpen(true)}>
              {value ? 'Change' : 'Choose image'}
            </AButton>
            {value !== null && (
              <AButton variant="ghost" className="!py-1 text-slate-400" onClick={() => onChange(null)}>
                Remove
              </AButton>
            )}
          </div>
        </div>
      </div>
      <Dialog open={open} onClose={() => setOpen(false)} title="Media library" wide>
        <MediaBrowser
          selectedId={value}
          onSelect={(m) => {
            onChange(m.id)
            setOpen(false)
          }}
        />
      </Dialog>
    </FieldWrap>
  )
}
