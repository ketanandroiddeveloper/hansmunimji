import type { ReactNode } from 'react'
import { Link } from 'react-router'
import type { Media } from '../../lib/types'
import { Container } from '../ui/Container'
import { ArchFrame } from '../ui/Picture'
import { Reveal } from '../ui/Reveal'
import { EditorialTitle } from '../ui/Typography'

type Crumb = { label: string; to?: string }

export function PageHeader({
  eyebrow,
  title,
  intro,
  media,
  crumbs,
  children,
}: {
  eyebrow?: string | null
  title: string
  intro?: ReactNode
  media?: Media | null
  crumbs?: Crumb[]
  children?: ReactNode
}) {
  return (
    <header className="grain relative overflow-hidden bg-midnight-950 pb-20 pt-[calc(var(--header-h)+4rem)] md:pb-28 md:pt-[calc(var(--header-h)+6rem)]">
      <div aria-hidden="true" className="pointer-events-none absolute -right-[10%] top-0 h-[60vh] w-[50vw] rounded-full bg-emerald-700/20 blur-[140px]" />
      <Container className="relative">
        {crumbs && crumbs.length > 0 && (
          <nav aria-label="Breadcrumb" className="mb-10">
            <ol className="flex flex-wrap items-center gap-3 text-[0.66rem] uppercase tracking-[0.24em] text-slate-400">
              {crumbs.map((c, i) => (
                <li key={c.label} className="flex items-center gap-3">
                  {i > 0 && <span aria-hidden="true">/</span>}
                  {c.to ? (
                    <Link to={c.to} className="-my-3.5 py-3.5 transition-colors hover:text-ivory-50">
                      {c.label}
                    </Link>
                  ) : (
                    <span aria-current="page" className="text-ivory-200/80">
                      {c.label}
                    </span>
                  )}
                </li>
              ))}
            </ol>
          </nav>
        )}
        <div className={`grid items-end gap-14 ${media ? 'lg:grid-cols-12 lg:gap-10' : ''}`}>
          <Reveal className={media ? 'lg:col-span-7' : 'max-w-4xl'}>
            {eyebrow && (
              <p className="eyebrow flex items-center gap-4">
                <span aria-hidden="true" className="h-px w-10 bg-champagne-400/70" />
                {eyebrow}
              </p>
            )}
            <h1 className="mt-8 text-display font-light">
              <EditorialTitle text={title} />
            </h1>
            {intro && <div className="mt-10 max-w-2xl text-body-l font-light text-ivory-200/85">{intro}</div>}
            {children && <div className="mt-12">{children}</div>}
          </Reveal>
          {media && (
            <Reveal delay={0.15} className="mx-auto w-full max-w-xs lg:col-span-4 lg:col-start-9 lg:max-w-none">
              <ArchFrame media={media} ratio={3 / 4} priority sizes="(min-width: 1024px) 30vw, 70vw" />
            </Reveal>
          )}
        </div>
      </Container>
    </header>
  )
}
