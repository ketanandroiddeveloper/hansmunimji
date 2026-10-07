import { Link, useSearchParams } from 'react-router'
import { PageHeader } from '../../components/layout/PageHeader'
import { Seo, SITE_URL } from '../../components/seo/Seo'
import { Container, Section } from '../../components/ui/Container'
import { Picture } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { EmptyState, ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { formatDate } from '../../lib/format'
import { useBlogs, useOptionalPage } from '../../lib/queries'
import { breadcrumbLd } from '../../lib/structuredData'
import type { BlogSummary } from '../../lib/types'

const DEFAULTS = {
  eyebrow: 'Journal',
  title: 'Notes, on the inner life of leadership.',
  intro: 'Reflections on stillness, attention and the quiet disciplines behind clear decisions.',
}

export default function JournalPage() {
  const page = useOptionalPage('journal')
  const [params, setParams] = useSearchParams()
  const current = Math.max(1, Number(params.get('page')) || 1)
  const category = params.get('category') ?? undefined
  const blogs = useBlogs(current, category)
  const intro = { ...DEFAULTS, ...page.data?.sections, title: page.data?.sections?.title ?? page.data?.title ?? DEFAULTS.title }
  const totalPages = Number(blogs.data?.meta?.total_pages ?? 1)
  const posts = blogs.data?.data ?? []
  const [lead, ...rest] = current === 1 && !category ? posts : [undefined, ...posts]

  const go = (n: number) => {
    const next = new URLSearchParams(params)
    if (n <= 1) next.delete('page')
    else next.set('page', String(n))
    setParams(next)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  return (
    <>
      <Seo
        title={current > 1 ? `Journal — page ${current}` : 'Journal'}
        description={intro.intro}
        jsonLd={breadcrumbLd(SITE_URL, [
          { name: 'Home', path: '/' },
          { name: 'Journal', path: '/journal' },
        ])}
      />
      <PageHeader eyebrow={intro.eyebrow} title={intro.title} intro={intro.intro} media={page.data?.sections?.image_media ?? null} crumbs={[{ label: 'Home', to: '/' }, { label: 'Journal' }]} />

      <Section tone="base" spacing="tight" className="pb-32">
        <Container>
          {category && (
            <p className="mb-12 flex items-center gap-4 text-sm font-light text-ivory-200/80">
              Showing: <span className="text-champagne-200">{posts[0]?.category?.name ?? category}</span>
              <Link to="/journal" className="link-underline text-xs uppercase tracking-[0.2em] text-slate-400">
                Clear
              </Link>
            </p>
          )}
          {blogs.isLoading && <LoadingBlock />}
          {blogs.isError && <ErrorBlock error={blogs.error} onRetry={() => blogs.refetch()} />}
          {blogs.isSuccess && posts.length === 0 && <EmptyState title="The journal will open soon.">New writing will be published here.</EmptyState>}

          {lead && <LeadPost post={lead} />}

          {rest.length > 0 && (
            <ul className={`grid gap-x-10 gap-y-20 sm:grid-cols-2 lg:grid-cols-3 ${lead ? 'mt-24 border-t border-[var(--line)] pt-20' : ''}`}>
              {rest.map((post, i) => post && <PostCard key={post.slug} post={post} index={i} />)}
            </ul>
          )}

          {totalPages > 1 && (
            <nav aria-label="Journal pages" className="mt-24 flex items-center justify-between border-t border-[var(--line)] pt-8 text-[0.7rem] uppercase tracking-[0.24em]">
              <button type="button" disabled={current <= 1} onClick={() => go(current - 1)} className="-my-3 py-3 text-ivory-200/80 hover:text-champagne-200 disabled:invisible">
                ← Newer
              </button>
              <span className="text-slate-400">
                Page {current} of {totalPages}
              </span>
              <button type="button" disabled={current >= totalPages} onClick={() => go(current + 1)} className="-my-3 py-3 text-ivory-200/80 hover:text-champagne-200 disabled:invisible">
                Older →
              </button>
            </nav>
          )}
        </Container>
      </Section>
    </>
  )
}

function Meta({ post }: { post: BlogSummary }) {
  return (
    <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[0.66rem] uppercase tracking-[0.24em] text-slate-400">
      {post.category && <span className="text-champagne-400">{post.category.name}</span>}
      <time dateTime={post.published_at}>{formatDate(post.published_at)}</time>
      {post.reading_minutes && <span>{post.reading_minutes} min read</span>}
    </p>
  )
}

function LeadPost({ post }: { post: BlogSummary }) {
  return (
    <Reveal>
      <Link to={`/journal/${post.slug}`} className="group grid items-center gap-10 lg:grid-cols-12">
        <div className="relative overflow-hidden bg-midnight-800 lg:col-span-7" style={{ aspectRatio: '16 / 10' }}>
          <Picture media={post.cover} sizes="(min-width: 1024px) 55vw, 95vw" fill imgClassName="transition-transform duration-[1.6s] ease-expo group-hover:scale-[1.03]" />
        </div>
        <div className="lg:col-span-5">
          <Meta post={post} />
          <h2 className="mt-6 text-h2 transition-colors duration-500 group-hover:text-champagne-200">{post.title}</h2>
          {post.excerpt && <p className="mt-6 font-light leading-relaxed text-ivory-200/80">{post.excerpt}</p>}
          <span className="mt-8 inline-block text-[0.7rem] uppercase tracking-[0.24em] text-champagne-200">Read →</span>
        </div>
      </Link>
    </Reveal>
  )
}

function PostCard({ post, index }: { post: BlogSummary; index: number }) {
  return (
    <Reveal as="li" delay={(index % 3) * 0.06}>
      <Link to={`/journal/${post.slug}`} className="group block">
        <div className="relative overflow-hidden bg-midnight-800" style={{ aspectRatio: '4 / 3' }}>
          <Picture media={post.cover} sizes="(min-width: 1024px) 30vw, (min-width: 640px) 45vw, 95vw" fill imgClassName="transition-transform duration-[1.6s] ease-expo group-hover:scale-[1.03]" />
        </div>
        <div className="mt-8">
          <Meta post={post} />
          <h3 className="mt-4 font-display text-[1.75rem] leading-tight text-ivory-50 transition-colors duration-500 group-hover:text-champagne-200">{post.title}</h3>
          {post.excerpt && <p className="mt-4 line-clamp-3 text-sm font-light leading-relaxed text-ivory-200/70">{post.excerpt}</p>}
        </div>
      </Link>
    </Reveal>
  )
}
