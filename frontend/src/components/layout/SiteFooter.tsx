import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { usePublishedPages, useServices, useSettings } from '../../lib/queries'
import { Container } from '../ui/Container'
import { ConfidentialitySeal, OrnamentRule } from '../ui/Ornaments'
import { NAV, Wordmark } from './SiteHeader'

const FOOTER_LINK = 'link-underline inline-block py-3 transition-colors hover:text-ivory-50 lg:inline lg:py-0 lg:pb-0.5'
const LEGAL_LINK = 'inline-block py-3.5 transition-colors hover:text-ivory-50 lg:inline lg:py-0'

function FooterColumn({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div>
      <p className="eyebrow mb-6">{title}</p>
      <ul className="text-sm font-light text-ivory-200/75 lg:space-y-3">{children}</ul>
    </div>
  )
}

function FooterLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <li>
      <Link to={to} className={FOOTER_LINK}>
        {children}
      </Link>
    </li>
  )
}

export function SiteFooter() {
  const { data: settings } = useSettings()
  const { data: services } = useServices()
  const { data: pages } = usePublishedPages()
  const legal = (pages ?? []).filter((p) => p.type === 'legal')
  const email = settings?.['contact.email']
  const phone = settings?.['contact.phone']
  const social = settings?.['social.links'] ?? []
  const name = settings?.['site.name'] ?? 'Hansmuniji'

  return (
    <footer className="grain relative bg-midnight-950 pt-24 pb-10" aria-labelledby="footer-heading">
      <h2 id="footer-heading" className="sr-only">
        Site information
      </h2>
      <Container>
        <OrnamentRule className="mb-20" />
        <div className="grid gap-16 lg:grid-cols-12">
          <div className="lg:col-span-4">
            <Wordmark />
            <p className="mt-6 max-w-sm font-display text-2xl leading-snug text-ivory-50/90 italic">{settings?.['site.tagline']}</p>
            <ConfidentialitySeal className="mt-8" label="Private · By application" />
          </div>

          <div className="grid gap-12 sm:grid-cols-3 lg:col-span-8">
            <FooterColumn title="Practice">
              {(services ?? []).map((s) => (
                <FooterLink key={s.slug} to={`/practice/${s.slug}`}>
                  {s.title}
                </FooterLink>
              ))}
            </FooterColumn>
            <FooterColumn title="Explore">
              {NAV.filter((n) => n.to !== '/practice').map((n) => (
                <FooterLink key={n.to} to={n.to}>
                  {n.label}
                </FooterLink>
              ))}
              <FooterLink to="/consultation">Book a consultation</FooterLink>
            </FooterColumn>
            <FooterColumn title="Private office">
              <FooterLink to="/private-access">Request private access</FooterLink>
              {email && (
                <li>
                  <a href={`mailto:${email}`} aria-label={email} className={`${FOOTER_LINK} lg:whitespace-nowrap`}>
                    {email.split('@')[0]}
                    <span className="inline-block">@{email.split('@').slice(1).join('@')}</span>
                  </a>
                </li>
              )}
              {phone && (
                <li>
                  <a href={`tel:${phone.replace(/\s+/g, '')}`} className={`${FOOTER_LINK} whitespace-nowrap`}>
                    {phone}
                  </a>
                </li>
              )}
              {social.map((s) => (
                <li key={s.url}>
                  <a href={s.url} target="_blank" rel="noopener noreferrer" className={FOOTER_LINK}>
                    {s.label}
                  </a>
                </li>
              ))}
            </FooterColumn>
          </div>
        </div>

        <div className="mt-24 flex flex-col gap-6 border-t border-[var(--line-faint)] pt-8 text-xs font-light text-slate-400 md:flex-row md:items-center md:justify-between">
          <p>
            © {new Date().getFullYear()} {name}. All rights reserved.
          </p>
          <ul className="flex flex-wrap gap-x-8 lg:gap-y-3">
            {legal.map((p) => (
              <li key={p.slug}>
                <Link to={`/legal/${p.slug}`} className={LEGAL_LINK}>
                  {p.title}
                </Link>
              </li>
            ))}
            <li>
              <Link to="/privacy/requests" className={LEGAL_LINK}>
                Your data
              </Link>
            </li>
          </ul>
        </div>
      </Container>
    </footer>
  )
}
