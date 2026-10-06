import { useParams } from 'react-router'
import { Seo } from '../../components/seo/Seo'
import { Container } from '../../components/ui/Container'
import { RichText } from '../../components/ui/RichText'
import { ErrorBlock, isNotFound, LoadingBlock } from '../../components/ui/States'
import { formatDate } from '../../lib/format'
import { usePage } from '../../lib/queries'
import { NotFoundPage } from '../../pages/NotFoundPage'

export default function LegalPage() {
  const slug = useParams().slug ?? ''
  const page = usePage(slug)

  if (page.isLoading) return <LoadingBlock className="min-h-[90vh]" />
  if (page.isError) return isNotFound(page.error) ? <NotFoundPage /> : <ErrorBlock className="min-h-[90vh]" error={page.error} onRetry={() => page.refetch()} />
  const p = page.data!
  if (p.type !== 'legal') return <NotFoundPage />

  return (
    <>
      <Seo title={p.title} override={p.seo} />
      <article className="bg-midnight-950 pb-32 pt-[calc(var(--header-h)+4rem)] md:pt-[calc(var(--header-h)+6rem)]">
        <Container className="max-w-3xl">
          <p className="eyebrow">Legal</p>
          <h1 className="mt-8 text-h1">{p.title}</h1>
          <p className="mt-6 text-xs uppercase tracking-[0.22em] text-slate-400">
            Last updated <time dateTime={p.updated_at}>{formatDate(p.updated_at)}</time>
          </p>
          <div className="rule mt-12" />
          <RichText html={p.body} className="mt-12" />
        </Container>
      </article>
    </>
  )
}
