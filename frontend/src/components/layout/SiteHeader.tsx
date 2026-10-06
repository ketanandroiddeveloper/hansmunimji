import { AnimatePresence, motion } from 'framer-motion'
import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, useLocation } from 'react-router'
import { useSettings } from '../../lib/queries'
import { ButtonLink } from '../ui/Button'
import { LotusMark } from '../ui/Ornaments'

export const NAV = [
  { to: '/practice', label: 'Practice' },
  { to: '/practitioner', label: 'Practitioner' },
  { to: '/library', label: 'Library' },
  { to: '/gatherings', label: 'Gatherings' },
  { to: '/journal', label: 'Journal' },
] as const

export function Wordmark({ className = '' }: { className?: string }) {
  const { data } = useSettings()
  return (
    <Link to="/" className={`group flex items-center gap-3 ${className}`} aria-label={`${data?.['site.name'] ?? 'Hansmuniji'} — home`}>
      <LotusMark className="h-6 w-6 text-champagne-400 transition-colors duration-500 group-hover:text-champagne-200" />
      <span className="font-display text-[1.65rem] leading-none tracking-[0.02em] text-ivory-50">{data?.['site.name'] ?? 'Hansmuniji'}</span>
    </Link>
  )
}

export function SiteHeader() {
  const [scrolled, setScrolled] = useState(false)
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  const menuRef = useRef<HTMLDivElement>(null)
  const toggleRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 24)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  useEffect(() => setOpen(false), [pathname])

  useEffect(() => {
    if (!open) return
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    menuRef.current?.querySelector<HTMLElement>('a')?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false)
        toggleRef.current?.focus()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = previous
      window.removeEventListener('keydown', onKey)
    }
  }, [open])

  const solid = scrolled || open

  return (
    <header
      className={`fixed inset-x-0 z-50 transition-[background-color,border-color,backdrop-filter] duration-500 ease-expo ${
        solid ? 'border-b border-[var(--line-faint)] bg-midnight-950/85 backdrop-blur-xl' : 'border-b border-transparent bg-transparent'
      }`}
      style={{ top: 'var(--ribbon-h)' }}
    >
      <div className="mx-auto flex h-[var(--header-h)] w-full max-w-site items-center justify-between px-6 md:px-10 lg:px-16">
        <Wordmark />

        <nav aria-label="Primary" className="hidden lg:block">
          <ul className="flex items-center gap-10">
            {NAV.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  className={({ isActive }) =>
                    `link-underline pb-1 text-[0.7rem] font-medium uppercase tracking-[0.24em] transition-colors duration-300 ${
                      isActive ? 'text-champagne-200 [background-size:100%_1px]' : 'text-ivory-200/80 hover:text-ivory-50'
                    }`
                  }
                >
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        <div className="flex items-center gap-4">
          <div className="hidden sm:block">
            <ButtonLink to="/private-access" variant="outline" size="sm">
              Private access
            </ButtonLink>
          </div>
          <button
            ref={toggleRef}
            type="button"
            className="relative flex h-11 w-11 items-center justify-center lg:hidden"
            aria-expanded={open}
            aria-controls="mobile-menu"
            aria-label={open ? 'Close menu' : 'Open menu'}
            onClick={() => setOpen((v) => !v)}
          >
            <span className={`absolute h-px w-6 bg-ivory-50 transition-transform duration-500 ease-expo ${open ? 'rotate-45' : '-translate-y-1'}`} />
            <span className={`absolute h-px w-6 bg-ivory-50 transition-transform duration-500 ease-expo ${open ? '-rotate-45' : 'translate-y-1'}`} />
          </button>
        </div>
      </div>

      <AnimatePresence>
        {open && (
          <motion.div
            id="mobile-menu"
            ref={menuRef}
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.4 }}
            className="fixed inset-x-0 bottom-0 bg-midnight-950 lg:hidden"
            style={{ top: 'calc(var(--header-h) + var(--ribbon-h))' }}
          >
            <nav aria-label="Mobile" className="flex h-full flex-col justify-between px-6 pb-10 pt-8 md:px-10">
              <ul className="space-y-1">
                {NAV.map((item, i) => (
                  <motion.li
                    key={item.to}
                    initial={{ opacity: 0, y: 16 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.05 * i + 0.1, duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
                    className="border-b border-[var(--line-faint)]"
                  >
                    <NavLink to={item.to} className="flex items-baseline justify-between py-5 font-display text-4xl text-ivory-50">
                      {item.label}
                      <span className="font-display text-base italic text-champagne-400">{['I', 'II', 'III', 'IV', 'V'][i]}</span>
                    </NavLink>
                  </motion.li>
                ))}
              </ul>
              <div className="space-y-4">
                <ButtonLink to="/private-access" variant="solid" className="w-full">
                  Request private access
                </ButtonLink>
                <ButtonLink to="/consultation" variant="outline" className="w-full">
                  Book a consultation
                </ButtonLink>
              </div>
            </nav>
          </motion.div>
        )}
      </AnimatePresence>
    </header>
  )
}
