import { Link, useParams } from 'react-router'
import { Seo, SITE_URL } from '../../components/seo/Seo'
import { ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { OrnamentRule } from '../../components/ui/Ornaments'
import { Picture } from '../../components/ui/Picture'
import { Reveal } from '../../components/ui/Reveal'
import { RichText } from '../../components/ui/RichText'
import { ErrorBlock, isNotFound, LoadingBlock } from '../../components/ui/States'
import { formatDate } from '../../lib/format'
import { useBlog } from '../../lib/queries'
import { articleLd, breadcrumbLd } from '../../lib/structuredData'
import { NotFoundPage } from '../../pages/NotFoundPage'

export default function ArticlePage() {
  const slug = useParams().slug ?? ''
  const blog = useBlog(slug)

  if (blog.isLoading) return <LoadingBlock className="min-h-[90vh]" />
  if (blog.isError) return isNotFound(blog.error) ? <NotFoundPage /> : <ErrorBlock className="min-h-[90vh]" error={blog.error} onRetry={() => blog.refetch()} />

  const b = blog.data!
  return (
    <>
      <Seo
        title={b.title}
        description={b.excerpt}
        image={b.cover?.url}
        override={b.seo}
        type="article"
        jsonLd={[
          articleLd(SITE_URL, b),
          breadcrumbLd(SITE_URL, [
            { name: 'Home', path: '/' },
            { name: 'Journal', path: '/journal' },
            { name: b.title, path: `/journal/${b.slug}` },
          ]),
        ]}
      />
      <article>
        <header className="grain relative bg-midnight-950 pb-16 pt-[calc(var(--header-h)+4rem)] md:pt-[calc(var(--header-h)+6rem)]">
          <Container className="max-w-4xl text-center">
            <Reveal>
              <p className="flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-[0.66rem] uppercase tracking-[0.26em] text-slate-400">
                <Link to="/journal" className="-my-3.5 py-3.5 hover:text-ivory-50">
                  Journal
                </Link>
                {b.category && (
                  <>
                    <span aria-hidden="true">·</span>
                    <Link to={`/journal?category=${encodeURIComponent(b.category.slug)}`} className="-my-3.5 py-3.5 text-champagne-400 hover:text-champagne-200">
                      {b.category.name}
                    </Link>
                  </>
                )}
              </p>
              <h1 className="mt-10 text-display font-light">{b.title}</h1>
              {b.excerpt && <p className="mx-auto mt-10 max-w-2xl text-body-l font-light italic text-ivory-200/80">{b.excerpt}</p>}
              <p className="mt-10 text-xs uppercase tracking-[0.22em] text-slate-400">
                {b.author && <span>{b.author.name} · </span>}
                <time dateTime={b.published_at}>{formatDate(b.published_at)}</time>
                {b.reading_minutes && <span> · {b.reading_minutes} min read</span>}
              </p>
            </Reveal>
          </Container>
        </header>

        {b.cover && (
          <div className="bg-midnight-950">
            <Container className="max-w-6xl">
              <div className="relative overflow-hidden bg-midnight-800" style={{ aspectRatio: '16 / 9' }}>
                <Picture media={b.cover} sizes="(min-width: 1280px) 1150px, 95vw" priority fill />
              </div>
            </Container>
          </div>
        )}

        <div className="bg-midnight-950 pb-32 pt-20">
          <Container className="max-w-3xl">
            <RichText html={b.body} className="text-body-l" />
            {b.tags.length > 0 && (
              <ul className="mt-16 flex flex-wrap gap-3" aria-label="Topics">
                {b.tags.map((t) => (
                  <li key={t} className="border border-[var(--line-strong)] px-3 py-1.5 text-[0.62rem] uppercase tracking-[0.22em] text-slate-400">
                    {t}
                  </li>
                ))}
              </ul>
            )}
            <OrnamentRule className="my-20" />
            <div className="flex flex-wrap items-center justify-between gap-6">
              <ButtonLink to="/journal" variant="link">
                More from the journal
              </ButtonLink>
              <ButtonLink to="/private-access" variant="outline" size="sm">
                Request private access
              </ButtonLink>
            </div>
          </Container>
        </div>
      </article>
    </>
  )
}
