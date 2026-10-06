import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useId, useState } from 'react'
import type { MouseEvent } from 'react'
import { useSearchParams } from 'react-router'
import type { Media } from '../../lib/types'
import { adminApi, errorMessage } from '../lib/adminApi'
import { useDebounced } from '../resources/ResourceListPage'
import { Dialog, useConfirm } from '../ui/Dialog'
import { FieldWrap, FilterSelect, Input, SearchInput } from '../ui/Fields'
import { MediaThumb, MediaUploadForm, thumbUrl } from '../ui/MediaPicker'
import { AButton, ErrorNote, Notice, PageHeader, Pagination, Spinner } from '../ui/Primitives'
import { useToast } from '../ui/Toast'

const TYPES = [
  { value: 'image', label: 'Images' },
  { value: 'video', label: 'Video' },
]

function MediaDetail({ media, onClose }: { media: Media; onClose: () => void }) {
  const notify = useToast()
  const confirm = useConfirm()
  const client = useQueryClient()
  const fileId = useId()
  const [alt, setAlt] = useState(media.alt)
  const [caption, setCaption] = useState(media.caption ?? '')
  const [category, setCategory] = useState(media.category ?? 'general')
  const [focal, setFocal] = useState(media.focal_point ?? '')
  const [error, setError] = useState<string | null>(null)
  const isImage = media.mime.startsWith('image/')
  const src = thumbUrl(media)

  const done = (message: string) => {
    client.invalidateQueries({ queryKey: ['admin', 'media'] })
    notify(message)
  }
  const save = useMutation({
    mutationFn: () => adminApi.put<Media>(`/admin/media/${media.id}`, { alt: alt.trim(), caption: caption.trim() || null, category: category.trim(), focal_point: focal || null }),
    onSuccess: () => {
      done('Saved.')
      onClose()
    },
    onError: (e) => setError(errorMessage(e)),
  })
  const replace = useMutation({
    mutationFn: (file: File) => {
      const form = new FormData()
      form.append('file', file)
      return adminApi.post<Media>(`/admin/media/${media.id}/replace`, form)
    },
    onSuccess: () => done('File replaced. Pages using it now show the new version.'),
    onError: (e) => setError(errorMessage(e)),
  })
  const remove = useMutation({
    mutationFn: () => adminApi.delete(`/admin/media/${media.id}`),
    onSuccess: () => {
      done('Deleted.')
      onClose()
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const pickFocal = (e: MouseEvent<HTMLButtonElement>) => {
    const r = e.currentTarget.getBoundingClientRect()
    const x = Math.round(((e.clientX - r.left) / r.width) * 100)
    const y = Math.round(((e.clientY - r.top) / r.height) * 100)
    setFocal(`${Math.min(100, Math.max(0, x))}% ${Math.min(100, Math.max(0, y))}%`)
  }
  const [fx, fy] = (focal || '50% 50%').split(' ').map((v) => parseInt(v, 10))

  const submit = () => {
    setError(null)
    if (alt.trim().length < 3) return setError('Alt text should describe the image (at least 3 characters).')
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(category.trim())) return setError('Category must be lowercase letters, numbers and hyphens, e.g. portraits.')
    save.mutate()
  }

  const onDelete = async () => {
    const ok = await confirm({
      title: 'Delete this file?',
      body: 'It is removed from every page, article or event that uses it, and the optimised versions are deleted. This cannot be undone.',
      confirmLabel: 'Delete',
      danger: true,
    })
    if (ok) remove.mutate()
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Media details"
      wide
      footer={
        <>
          <AButton variant="danger" className="mr-auto" loading={remove.isPending} onClick={onDelete}>
            Delete
          </AButton>
          <AButton variant="ghost" onClick={onClose}>
            Close
          </AButton>
          <AButton variant="primary" loading={save.isPending} onClick={submit}>
            Save
          </AButton>
        </>
      }
    >
      <div className="grid gap-6 md:grid-cols-[1fr_1fr]">
        <div>
          {isImage && src ? (
            <>
              <button type="button" onClick={pickFocal} className="relative block w-full cursor-crosshair" aria-label="Set the focal point by clicking the important part of the image">
                <img src={src} alt={media.alt} className="block w-full bg-midnight-800" />
                <span aria-hidden="true" className="pointer-events-none absolute h-6 w-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-champagne-200 shadow-[0_0_0_1px_rgba(0,0,0,0.6)]" style={{ left: `${fx}%`, top: `${fy}%` }} />
              </button>
              <p className="mt-2 text-xs text-slate-400">
                Click the image to set the focal point kept in view when it is cropped{focal ? ` (${focal})` : ''}.
                {focal && (
                  <button type="button" className="ml-2 text-champagne-200 hover:underline" onClick={() => setFocal('')}>
                    Reset
                  </button>
                )}
              </p>
            </>
          ) : (
            <MediaThumb media={media} className="aspect-video w-full" />
          )}
          <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-xs text-slate-400">
            <dt>Type</dt>
            <dd>{media.mime}</dd>
            {media.width && (
              <>
                <dt>Size</dt>
                <dd>
                  {media.width} × {media.height}px
                </dd>
              </>
            )}
            {media.srcset && (
              <>
                <dt>Formats</dt>
                <dd className="uppercase">{Object.keys(media.srcset).join(', ')}</dd>
              </>
            )}
            {media.url && (
              <>
                <dt>URL</dt>
                <dd className="truncate">
                  <a href={media.url} target="_blank" rel="noopener noreferrer" className="text-champagne-200 hover:underline">
                    Open original ↗
                  </a>
                </dd>
              </>
            )}
          </dl>
        </div>
        <div className="space-y-4">
          <Input label="Alt text" required maxLength={255} value={alt} onChange={(e) => setAlt(e.target.value)} hint="What the image shows, for people who cannot see it." />
          <Input label="Caption" maxLength={500} value={caption} onChange={(e) => setCaption(e.target.value)} />
          <Input label="Category" required maxLength={60} value={category} onChange={(e) => setCategory(e.target.value)} />
          <FieldWrap id={fileId} label="Replace file" hint="Keeps the same ID, so every page using it updates.">
            <input
              id={fileId}
              type="file"
              accept={isImage ? 'image/jpeg,image/png,image/webp,image/avif' : 'video/mp4'}
              disabled={replace.isPending}
              onChange={(e) => {
                const f = e.target.files?.[0]
                if (f) replace.mutate(f)
                e.target.value = ''
              }}
              className="block w-full text-sm text-ivory-200 file:mr-4 file:border file:border-ivory-50/20 file:bg-transparent file:px-3 file:py-1.5 file:text-xs file:uppercase file:tracking-[0.14em] file:text-ivory-50 hover:file:border-champagne-400"
            />
          </FieldWrap>
          {replace.isPending && <p className="text-xs text-slate-400">Uploading and optimising…</p>}
          {error && <Notice tone="error">{error}</Notice>}
        </div>
      </div>
    </Dialog>
  )
}

export default function MediaPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page') ?? 1))
  const category = params.get('category') ?? ''
  const type = params.get('type') ?? ''
  const [q, setQ] = useState(params.get('q') ?? '')
  const debounced = useDebounced(q, 400)
  const [uploading, setUploading] = useState(false)
  const [selected, setSelected] = useState<Media | null>(null)

  const set = (changes: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(changes)) v ? next.set(k, v) : next.delete(k)
    if (!('page' in changes)) next.delete('page')
    setParams(next, { replace: true })
  }
  useEffect(() => {
    if ((params.get('q') ?? '') !== debounced) set({ q: debounced })
  }, [debounced])

  const list = useQuery({
    queryKey: ['admin', 'media', 'library', page, category, type, debounced],
    queryFn: () => adminApi.list<Media>('/admin/media', { query: { page, category, type, q: debounced || undefined } }),
    placeholderData: keepPreviousData,
  })
  const categories = (list.data?.meta.categories as string[] | undefined) ?? []

  return (
    <>
      <PageHeader
        title="Media library"
        description="Images are converted to AVIF and WebP at several sizes on upload. Only upload images you have the rights and the subjects’ consent to publish."
        actions={
          <AButton variant="primary" onClick={() => setUploading(true)}>
            Upload
          </AButton>
        }
      />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <SearchInput value={q} onChange={setQ} placeholder="Alt text or file name" label="Search media" />
        <FilterSelect label="Category" value={category} onChange={(v) => set({ category: v })} options={categories.map((c) => ({ value: c, label: c }))} />
        <FilterSelect label="Type" value={type} onChange={(v) => set({ type: v })} options={TYPES} />
      </div>
      {list.isLoading ? (
        <Spinner />
      ) : list.isError ? (
        <ErrorNote error={list.error} onRetry={() => list.refetch()} />
      ) : list.data!.items.length === 0 ? (
        <p className="py-16 text-center text-sm font-light text-slate-400">No media found.</p>
      ) : (
        <>
          <ul className={`grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6 ${list.isFetching ? 'opacity-60' : ''}`}>
            {list.data!.items.map((m) => (
              <li key={m.id}>
                <button type="button" onClick={() => setSelected(m)} className="group block w-full border border-[var(--line)] text-left transition-colors hover:border-champagne-400/60">
                  <MediaThumb media={m} className="aspect-square w-full" />
                  <span className="block truncate px-2 pt-2 text-xs font-light text-ivory-200">{m.alt}</span>
                  <span className="block px-2 pb-2 text-[0.6rem] uppercase tracking-[0.14em] text-slate-400">{m.category}</span>
                </button>
              </li>
            ))}
          </ul>
          <Pagination page={list.data!.meta.page} totalPages={list.data!.meta.total_pages} total={list.data!.meta.total} onPage={(p) => set({ page: String(p) })} />
        </>
      )}
      <Dialog open={uploading} onClose={() => setUploading(false)} title="Upload media" wide>
        <MediaUploadForm defaultCategory={category || 'general'} onUploaded={() => setUploading(false)} />
      </Dialog>
      {selected && <MediaDetail key={selected.id} media={selected} onClose={() => setSelected(null)} />}
    </>
  )
}
