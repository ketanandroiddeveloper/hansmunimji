import type { BlogDetail, EventDetail, Faq, Practitioner, PublicSettings, ServiceDetail } from './types'

/**
 * JSON-LD builders. Only facts present in the CMS are emitted — no ratings, reviews or
 * credentials are inferred.
 */
export function organizationLd(siteUrl: string, settings: PublicSettings | undefined, practitioner: Practitioner | undefined) {
  const name = settings?.['site.name'] ?? 'Hansmuniji'
  const sameAs = [...(settings?.['social.links'] ?? []).map((s) => s.url), ...(practitioner?.same_as ?? [])]
  const org: Record<string, unknown> = {
    '@type': 'ProfessionalService',
    '@id': `${siteUrl}/#organization`,
    name,
    url: `${siteUrl}/`,
    description: settings?.['site.description'],
  }
  if (settings?.['contact.email']) org.email = settings['contact.email']
  if (settings?.['contact.phone']) org.telephone = settings['contact.phone']
  if (sameAs.length) org.sameAs = Array.from(new Set(sameAs))
  if (practitioner) org.founder = { '@id': `${siteUrl}/practitioner#person` }

  const graph: Record<string, unknown>[] = [
    { '@type': 'WebSite', '@id': `${siteUrl}/#website`, url: `${siteUrl}/`, name, publisher: { '@id': `${siteUrl}/#organization` } },
    org,
  ]
  if (practitioner) graph.push(personLd(siteUrl, practitioner))
  return { '@context': 'https://schema.org', '@graph': graph }
}

export function personLd(siteUrl: string, p: Practitioner) {
  const person: Record<string, unknown> = {
    '@type': 'Person',
    '@id': `${siteUrl}/practitioner#person`,
    name: p.full_name,
    url: `${siteUrl}/practitioner`,
  }
  if (p.title) person.jobTitle = p.title
  if (p.short_bio) person.description = p.short_bio
  if (p.portrait?.url) person.image = p.portrait.url
  if (p.expertise.length) person.knowsAbout = p.expertise
  if (p.same_as.length) person.sameAs = p.same_as
  const credentials = Object.values(p.qualifications).flat()
  if (credentials.length) {
    person.hasCredential = credentials.map((q) => ({
      '@type': 'EducationalOccupationalCredential',
      name: q.title,
      ...(q.institution ? { recognizedBy: { '@type': 'Organization', name: q.institution } } : {}),
    }))
  }
  return person
}

export function serviceLd(siteUrl: string, s: ServiceDetail) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Service',
    name: s.title,
    description: s.summary ?? undefined,
    url: `${siteUrl}/practice/${s.slug}`,
    provider: { '@id': `${siteUrl}/#organization` },
    ...(s.cover?.url ? { image: s.cover.url } : {}),
  }
}

export function faqLd(faqs: Faq[]) {
  if (!faqs.length) return null
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqs.map((f) => ({ '@type': 'Question', name: f.question, acceptedAnswer: { '@type': 'Answer', text: f.answer } })),
  }
}

export function eventLd(siteUrl: string, e: EventDetail) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Event',
    name: e.title,
    description: e.summary ?? undefined,
    startDate: e.starts_at,
    endDate: e.ends_at,
    eventStatus: e.status === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
    eventAttendanceMode: 'https://schema.org/OfflineEventAttendanceMode',
    url: `${siteUrl}/gatherings/${e.slug}`,
    ...(e.cover?.url ? { image: [e.cover.url] } : {}),
    ...(e.city
      ? {
          location: {
            '@type': 'Place',
            name: e.venue ?? e.city.name,
            address: { '@type': 'PostalAddress', addressLocality: e.city.name, addressCountry: e.city.country },
          },
        }
      : {}),
    organizer: { '@id': `${siteUrl}/#organization` },
    ...(e.prices.length > 0
      ? {
          // INR, USD, AED and GBP all use two minor-unit decimals.
          offers: e.prices.map((p) => ({
            '@type': 'Offer',
            price: (p.amount_minor / 100).toFixed(2),
            priceCurrency: p.currency,
            availability:
              e.registration_state === 'full' || e.registration_state === 'waitlist'
                ? 'https://schema.org/SoldOut'
                : e.registration_state === 'not_yet_open'
                  ? 'https://schema.org/PreOrder'
                  : 'https://schema.org/InStock',
            ...(e.registration_opens_at ? { validFrom: e.registration_opens_at } : {}),
            url: `${siteUrl}/gatherings/${e.slug}`,
          })),
        }
      : {}),
  }
}

export function articleLd(siteUrl: string, b: BlogDetail) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: b.title,
    description: b.excerpt ?? undefined,
    datePublished: b.published_at,
    dateModified: b.updated_at,
    mainEntityOfPage: `${siteUrl}/journal/${b.slug}`,
    ...(b.cover?.url ? { image: [b.cover.url] } : {}),
    ...(b.author ? { author: { '@type': 'Person', name: b.author.name } } : {}),
    publisher: { '@id': `${siteUrl}/#organization` },
    ...(b.tags.length ? { keywords: b.tags.join(', ') } : {}),
  }
}

export function breadcrumbLd(siteUrl: string, items: { name: string; path: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, i) => ({ '@type': 'ListItem', position: i + 1, name: item.name, item: `${siteUrl}${item.path}` })),
  }
}
