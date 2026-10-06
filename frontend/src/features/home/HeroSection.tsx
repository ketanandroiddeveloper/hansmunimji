import { motion, useReducedMotion, useScroll, useTransform } from 'framer-motion'
import { useRef, useState } from 'react'
import type { HomeSections } from '../../lib/types'
import { ButtonLink } from '../../components/ui/Button'
import { Container } from '../../components/ui/Container'
import { ConfidentialitySeal } from '../../components/ui/Ornaments'
import { Picture } from '../../components/ui/Picture'
import { EASE } from '../../components/ui/Reveal'
import { EditorialTitle } from '../../components/ui/Typography'
import { isSnapshotEntry } from '../../lib/snapshot'

const enter = (delay: number, reduce: boolean | null) =>
  reduce
    ? {}
    : {
        initial: { opacity: 0, y: 24 },
        animate: { opacity: 1, y: 0 },
        transition: { duration: 1.2, delay, ease: EASE },
      }

export function HeroSection({ hero, disciplines }: { hero: NonNullable<HomeSections['hero']>; disciplines: string[] }) {
  const ref = useRef<HTMLElement>(null)
  const reduce = useReducedMotion()
  const [fromSnapshot] = useState(isSnapshotEntry)
  const still = reduce || fromSnapshot
  const { scrollYProgress } = useScroll({ target: ref, offset: ['start start', 'end start'] })
  const imageY = useTransform(scrollYProgress, [0, 1], ['0%', reduce ? '0%' : '12%'])
  const textY = useTransform(scrollYProgress, [0, 1], ['0%', reduce ? '0%' : '-8%'])

  return (
    <section ref={ref} aria-labelledby="hero-title" className="grain relative flex min-h-[100svh] flex-col overflow-hidden bg-midnight-950">
      <div aria-hidden="true" className="pointer-events-none absolute inset-0">
        <div className="absolute -right-[10%] top-[8%] h-[70vh] w-[60vw] rounded-full bg-emerald-700/25 blur-[140px]" />
        <div className="absolute -left-[15%] bottom-[-20%] h-[60vh] w-[50vw] rounded-full bg-champagne-400/[0.06] blur-[160px]" />
        <div className="absolute inset-y-0 left-[58%] hidden w-px bg-gradient-to-b from-transparent via-[var(--line)] to-transparent lg:block" />
      </div>

      <Container className="relative flex flex-1 items-center pt-[calc(var(--header-h)+3rem)] pb-16 lg:pb-24">
        <div className="grid w-full items-center gap-16 lg:grid-cols-12 lg:gap-10">
          <motion.div style={{ y: textY }} className="lg:col-span-7 lg:pr-8">
            {hero.eyebrow && (
              <motion.p {...enter(0.1, still)} className="eyebrow flex items-center gap-4">
                <span aria-hidden="true" className="h-px w-10 bg-champagne-400/70" />
                {hero.eyebrow}
              </motion.p>
            )}
            <motion.h1 id="hero-title" {...enter(0.25, still)} className="mt-8 text-display font-light text-ivory-50">
              <EditorialTitle text={hero.title ?? ''} />
            </motion.h1>
            {hero.subtitle && (
              <motion.p {...enter(0.45, still)} className="mt-8 max-w-xl text-body-l font-light text-ivory-200/85">
                {hero.subtitle}
              </motion.p>
            )}
            <motion.div {...enter(0.6, still)} className="mt-12 flex flex-wrap items-center gap-x-10 gap-y-6">
              {hero.primary_cta && (
                <ButtonLink to={hero.primary_cta.href} variant="solid" size="lg" arrow>
                  {hero.primary_cta.label}
                </ButtonLink>
              )}
              {hero.secondary_cta && (
                <ButtonLink to={hero.secondary_cta.href} variant="link">
                  {hero.secondary_cta.label}
                </ButtonLink>
              )}
            </motion.div>
          </motion.div>

          <div className="relative mx-auto w-full max-w-[26rem] lg:col-span-5 lg:max-w-none">
            <motion.div
              initial={still ? false : { opacity: 0, scale: 0.97 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ duration: 1.6, delay: 0.2, ease: EASE }}
              className="relative"
            >
              <div aria-hidden="true" className="pointer-events-none absolute -inset-4 rounded-t-[999px] border border-champagne-400/40 md:-inset-5" />
              <div aria-hidden="true" className="pointer-events-none absolute -inset-9 hidden rounded-t-[999px] border border-[var(--line-faint)] md:block" />
              <div className="relative overflow-hidden rounded-t-[999px] bg-midnight-800" style={{ aspectRatio: '4 / 5.4' }}>
                <motion.div style={{ y: imageY }} className="absolute inset-[-6%_0]">
                  <Picture media={hero.image_media} sizes="(min-width: 1024px) 38vw, 90vw" priority fill />
                </motion.div>
                <div aria-hidden="true" className="absolute inset-0 bg-gradient-to-t from-midnight-950/70 via-midnight-950/5 to-transparent" />
              </div>
            </motion.div>
          </div>
        </div>
      </Container>

      <Container className="relative pb-8">
        <motion.div
          initial={still ? false : { opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ duration: 1.2, delay: 1 }}
          className="flex flex-col gap-6 border-t border-[var(--line-faint)] pt-6 md:flex-row md:items-center md:justify-between"
        >
          <ConfidentialitySeal />
          {disciplines.length > 0 && (
            <ul className="hidden flex-wrap justify-end gap-x-6 gap-y-2 text-[0.68rem] uppercase tracking-[0.26em] text-slate-400 md:flex" aria-label="Areas of practice">
              {disciplines.map((d, i) => (
                <li key={d} className="flex items-center gap-6">
                  {i > 0 && <span aria-hidden="true" className="h-1 w-1 rounded-full bg-champagne-400/60" />}
                  {d}
                </li>
              ))}
            </ul>
          )}
        </motion.div>
      </Container>
    </section>
  )
}
