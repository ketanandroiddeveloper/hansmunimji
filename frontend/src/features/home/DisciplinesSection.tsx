import { AnimatePresence, motion } from 'framer-motion'
import { useState } from 'react'
import { Link } from 'react-router'
import type { BookingMode, HomeSections, ServiceSummary } from '../../lib/types'
import { Container, Section } from '../../components/ui/Container'
import { ArchFrame } from '../../components/ui/Picture'
import { EASE, Reveal } from '../../components/ui/Reveal'
import { EditorialTitle, Eyebrow } from '../../components/ui/Typography'

export const BOOKING_MODE_LABEL: Record<BookingMode, string> = {
  direct: 'Book directly',
  application: 'By application',
  inquiry: 'By inquiry',
}

export function DisciplinesSection({ section, services }: { section: NonNullable<HomeSections['disciplines']>; services: ServiceSummary[] }) {
  const [active, setActive] = useState(0)
  const current = services[active] ?? services[0]

  return (
    <Section tone="base" labelledBy="disciplines-title" className="overflow-hidden">
      <Container>
        <div className="grid gap-10 lg:grid-cols-12">
          <Reveal className="lg:col-span-6">
            {section.eyebrow && <Eyebrow>{section.eyebrow}</Eyebrow>}
            <h2 id="disciplines-title" className="mt-8 text-h1">
              <EditorialTitle text={section.title ?? ''} />
            </h2>
          </Reveal>
          {section.intro && (
            <Reveal delay={0.1} className="self-end lg:col-span-4 lg:col-start-9">
              <p className="font-light text-ivory-200/80">{section.intro}</p>
            </Reveal>
          )}
        </div>

        <div className="mt-20 grid gap-16 lg:grid-cols-12 lg:gap-10">
          <ol className="lg:col-span-7">
            {services.map((service, i) => (
              <Reveal as="li" key={service.slug} delay={0.05 * i} className="border-t border-[var(--line)] last:border-b">
                <Link
                  to={`/practice/${service.slug}`}
                  onMouseEnter={() => setActive(i)}
                  onFocus={() => setActive(i)}
                  className="group grid grid-cols-[3rem_1fr_auto] items-start gap-x-4 gap-y-3 py-9 md:grid-cols-[4rem_1fr_auto] md:py-11"
                >
                  <span aria-hidden="true" className="pt-2 font-display text-xl italic text-champagne-400">
                    {service.numeral ?? i + 1}.
                  </span>
                  <span>
                    <span className="block font-display text-[clamp(1.75rem,1.4rem+1.2vw,2.6rem)] leading-[1.1] text-ivory-50 transition-[color,transform] duration-500 ease-expo group-hover:translate-x-2 group-hover:text-champagne-200 group-focus-visible:text-champagne-200">
                      {service.title}
                    </span>
                    {service.subtitle && <span className="mt-3 block max-w-lg font-light text-ivory-200/70">{service.subtitle}</span>}
                    <span className="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-[0.66rem] uppercase tracking-[0.26em] text-slate-400">
                      <span>{BOOKING_MODE_LABEL[service.booking_mode]}</span>
                      {service.duration_label && (
                        <>
                          <span aria-hidden="true" className="text-champagne-400/50">
                            ·
                          </span>
                          <span>{service.duration_label}</span>
                        </>
                      )}
                    </span>
                  </span>
                  <span
                    aria-hidden="true"
                    className="mt-3 flex h-11 w-11 items-center justify-center rounded-full border border-[var(--line-strong)] text-ivory-50 transition-all duration-500 ease-expo group-hover:border-champagne-400 group-hover:bg-champagne-400 group-hover:text-midnight-950"
                  >
                    <svg viewBox="0 0 16 16" className="h-3.5 w-3.5 -rotate-45 transition-transform duration-500 group-hover:rotate-0" fill="none" stroke="currentColor" strokeWidth="1.2">
                      <path d="M1 8h13M9.5 3.5 14 8l-4.5 4.5" />
                    </svg>
                  </span>
                </Link>
              </Reveal>
            ))}
          </ol>

          <div className="hidden lg:col-span-4 lg:col-start-9 lg:block">
            <div className="sticky top-[calc(var(--header-h)+var(--ribbon-h)+3rem)]">
              <div className="relative">
                <AnimatePresence mode="wait" initial={false}>
                  {current && (
                    <motion.div
                      key={current.slug}
                      initial={{ opacity: 0, scale: 1.02 }}
                      animate={{ opacity: 1, scale: 1 }}
                      exit={{ opacity: 0 }}
                      transition={{ duration: 0.6, ease: EASE }}
                    >
                      <ArchFrame media={current.cover} ratio={3 / 4} sizes="30vw" />
                    </motion.div>
                  )}
                </AnimatePresence>
              </div>
              {current?.summary && (
                <AnimatePresence mode="wait" initial={false}>
                  <motion.p
                    key={current.slug}
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0 }}
                    transition={{ duration: 0.5, ease: EASE }}
                    className="mt-10 text-sm font-light leading-relaxed text-ivory-200/75"
                  >
                    {current.summary}
                  </motion.p>
                </AnimatePresence>
              )}
            </div>
          </div>
        </div>
      </Container>
    </Section>
  )
}
