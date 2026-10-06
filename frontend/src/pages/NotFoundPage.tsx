import { isRouteErrorResponse, useRouteError } from 'react-router'
import { Seo } from '../components/seo/Seo'
import { ButtonLink } from '../components/ui/Button'
import { Container } from '../components/ui/Container'
import { OrnamentRule } from '../components/ui/Ornaments'

export function NotFoundPage({ title = 'This page could not be found.', body = 'The page may have moved, or the link may be incomplete.' }: { title?: string; body?: string }) {
  return (
    <>
      <Seo title="Not found" noindex />
      <Container className="flex min-h-[80vh] flex-col items-center justify-center py-32 text-center">
        <p className="eyebrow">404</p>
        <h1 className="mt-6 max-w-2xl text-h1">{title}</h1>
        <p className="mt-6 max-w-md font-light text-ivory-200/75">{body}</p>
        <OrnamentRule className="my-12 w-48" />
        <ButtonLink to="/" variant="link">
          Return home
        </ButtonLink>
      </Container>
    </>
  )
}

export function RouteErrorPage() {
  const error = useRouteError()
  if (isRouteErrorResponse(error) && error.status === 404) return <NotFoundPage />
  return (
    <Container className="flex min-h-[80vh] flex-col items-center justify-center py-32 text-center">
      <Seo title="Something went wrong" noindex />
      <p className="eyebrow">Unexpected error</p>
      <h1 className="mt-6 max-w-2xl text-h1">Something went wrong.</h1>
      <p className="mt-6 max-w-md font-light text-ivory-200/75">Please refresh the page. If the problem continues, contact the private office.</p>
      {import.meta.env.DEV && error instanceof Error && <pre className="mt-8 max-w-3xl overflow-auto whitespace-pre-wrap text-left text-xs text-danger-300">{error.stack ?? error.message}</pre>}
      <div className="mt-12 flex gap-8">
        <button type="button" onClick={() => window.location.reload()} className="text-[0.72rem] font-medium uppercase tracking-[0.24em] text-champagne-200">
          Refresh
        </button>
        <ButtonLink to="/" variant="link">
          Return home
        </ButtonLink>
      </div>
    </Container>
  )
}
