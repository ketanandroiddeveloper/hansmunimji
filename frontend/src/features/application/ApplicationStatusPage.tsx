import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useParams } from 'react-router'
import { Seo } from '../../components/seo/Seo'
import { ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { OrnamentRule } from '../../components/ui/Ornaments'
import { LoadingBlock } from '../../components/ui/States'
import { captureAccessToken } from '../../lib/accessTokens'
import { api, ApiError } from '../../lib/api'
import { formatDate } from '../../lib/format'

type Status = { reference: string; status: string; submitted_at: string | null; message: string }

const LABEL: Record<string, string> = {
  draft: 'Not yet submitted',
  submitted: 'Received',
  under_review: 'Under review',
  info_requested: 'Information requested',
  approved: 'Approved',
  invited: 'Invitation sent',
  converted: 'Consultation arranged',
  rejected: 'Closed',
  archived: 'Closed',
}

const ORDER = ['submitted', 'under_review', 'approved', 'invited', 'converted']

export default function ApplicationStatusPage() {
  const { reference = '' } = useParams()
  const [token] = useState(() => captureAccessToken('application', reference))
  const { data, error, isPending } = useQuery({
    queryKey: ['application-status', reference],
    queryFn: () => api.get<Status>(`/applications/${encodeURIComponent(reference)}`, { headers: { 'X-Access-Token': token ?? '' } }),
    enabled: Boolean(token),
    staleTime: 0,
  })

  const shell = (children: React.ReactNode) => (
    <>
      <Seo title="Application status" noindex />
      <section className="grain relative flex min-h-[90vh] items-center bg-midnight-950 pb-24 pt-[calc(var(--header-h)+4rem)]">
        <Container className="max-w-3xl text-center">{children}</Container>
      </section>
    </>
  )

  if (!token)
    return shell(
      <>
        <h1 className="text-h1">Status link required.</h1>
        <p className="mx-auto mt-6 max-w-md font-light text-ivory-200/75">For your privacy, the status of an application can only be viewed from the private link sent to your email.</p>
      </>,
    )
  if (isPending) return <LoadingBlock className="min-h-[90vh]" />
  if (error)
    return shell(
      <>
        <h1 className="text-h1">We could not find that application.</h1>
        <p className="mx-auto mt-6 max-w-md font-light text-ivory-200/75">{error instanceof ApiError ? error.message : 'Please try again later.'}</p>
      </>,
    )

  const progress = ORDER.indexOf(data.status)

  return shell(
    <>
      <p className="eyebrow">Application {data.reference}</p>
      <h1 className="mt-8 text-display font-light">{LABEL[data.status] ?? data.status}</h1>
      {data.submitted_at && <p className="mt-6 text-sm text-slate-400">Submitted {formatDate(data.submitted_at)}</p>}
      {progress >= 0 && (
        <ol className="mx-auto mt-14 grid max-w-2xl grid-cols-5 gap-3" aria-label="Progress">
          {ORDER.map((s, i) => (
            <li key={s}>
              <span className={`block h-px ${i <= progress ? 'bg-champagne-400' : 'bg-[var(--line-strong)]'}`} />
              <span className={`mt-3 block text-[0.6rem] uppercase tracking-[0.22em] ${i <= progress ? 'text-ivory-200' : 'text-slate-400/70'}`}>{LABEL[s]}</span>
            </li>
          ))}
        </ol>
      )}
      <OrnamentRule className="mx-auto my-14 w-64" />
      <p className="mx-auto max-w-xl text-body-l font-light text-ivory-200/85">{data.message}</p>
      <div className="mt-14">
        <ButtonLink to="/" variant="link">
          Return home
        </ButtonLink>
      </div>
    </>,
  )
}
