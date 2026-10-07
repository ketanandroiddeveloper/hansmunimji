import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { Link } from 'react-router'
import { isExternal } from '../../lib/format'

type Variant = 'solid' | 'outline' | 'link' | 'ghost'
type Size = 'md' | 'lg' | 'sm' | 'flush'

const base =
  'group inline-flex items-center justify-center gap-3 text-center font-sans text-[0.72rem] font-medium uppercase tracking-[0.18em] sm:tracking-[0.24em] transition-[color,background-color,border-color,box-shadow] duration-300 ease-expo disabled:cursor-not-allowed disabled:opacity-50'

const variants: Record<Variant, string> = {
  solid: 'bg-champagne-400 text-midnight-950 hover:bg-champagne-200 shadow-[0_0_0_1px_rgba(201,176,122,0.4)]',
  outline: 'border border-ivory-50/25 text-ivory-50 hover:border-champagne-400 hover:text-champagne-200',
  ghost: 'text-ivory-200 hover:text-champagne-200',
  // The text link stays visually compact; the pseudo-element extends its hit area to 44px.
  link: 'relative text-ivory-50 hover:text-champagne-200 after:absolute after:inset-x-0 after:-inset-y-2.5',
}

const sizes: Record<Size, string> = {
  sm: 'px-4 py-3 lg:py-2.5',
  md: 'px-7 py-4',
  lg: 'px-9 py-5',
  /** No horizontal padding, for text-like ghost actions aligned to the content edge. */
  flush: 'py-3 lg:py-2.5',
}

const classesFor = (variant: Variant, size: Size, className: string) => `${base} ${variants[variant]} ${variant === 'link' ? '' : sizes[size]} ${className}`

function Arrow() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 8" className="h-2 w-6 transition-transform duration-500 ease-expo group-hover:translate-x-1.5" fill="none" stroke="currentColor" strokeWidth="1">
      <path d="M0 4h23M19.5 0.5 23 4l-3.5 3.5" />
    </svg>
  )
}

function Content({ children, variant, arrow }: { children: ReactNode; variant: Variant; arrow: boolean }) {
  return (
    <>
      <span className={variant === 'link' ? 'link-underline pb-1' : ''}>{children}</span>
      {arrow && <Arrow />}
    </>
  )
}

type CommonProps = { variant?: Variant; size?: Size; arrow?: boolean; className?: string; children: ReactNode }

export function ButtonLink({ to, variant = 'outline', size = 'md', arrow, className = '', children, ...rest }: CommonProps & { to: string; 'aria-label'?: string }) {
  const showArrow = arrow ?? variant === 'link'
  const classes = classesFor(variant, size, className)
  if (isExternal(to)) {
    const external = /^https?:/i.test(to)
    return (
      <a href={to} className={classes} {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})} {...rest}>
        <Content variant={variant} arrow={showArrow}>
          {children}
        </Content>
      </a>
    )
  }
  return (
    <Link to={to} className={classes} {...rest}>
      <Content variant={variant} arrow={showArrow}>
        {children}
      </Content>
    </Link>
  )
}

export function Button({
  variant = 'solid',
  size = 'md',
  arrow = false,
  className = '',
  children,
  loading = false,
  type = 'button',
  ...rest
}: CommonProps & ButtonHTMLAttributes<HTMLButtonElement> & { loading?: boolean }) {
  return (
    <button type={type} className={classesFor(variant, size, className)} aria-busy={loading || undefined} disabled={rest.disabled || loading} {...rest}>
      {loading && <span aria-hidden="true" className="h-3 w-3 animate-spin rounded-full border border-current border-t-transparent" />}
      <Content variant={variant} arrow={arrow && !loading}>
        {children}
      </Content>
    </button>
  )
}
