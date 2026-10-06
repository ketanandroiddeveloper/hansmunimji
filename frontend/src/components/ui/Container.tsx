import type { ComponentPropsWithoutRef, ElementType, ReactNode } from 'react'

type ContainerProps<T extends ElementType> = {
  as?: T
  children: ReactNode
  className?: string
} & Omit<ComponentPropsWithoutRef<T>, 'as' | 'children' | 'className'>

export function Container<T extends ElementType = 'div'>({ as, children, className = '', ...rest }: ContainerProps<T>) {
  const Tag = (as ?? 'div') as ElementType
  return (
    <Tag className={`mx-auto w-full max-w-site px-6 md:px-10 lg:px-16 ${className}`} {...rest}>
      {children}
    </Tag>
  )
}

type SectionProps = {
  children: ReactNode
  className?: string
  tone?: 'deep' | 'base' | 'raised' | 'charcoal'
  id?: string
  labelledBy?: string
  spacing?: 'default' | 'tight' | 'none'
}

const tones: Record<NonNullable<SectionProps['tone']>, string> = {
  deep: 'bg-midnight-950',
  base: 'bg-midnight-900',
  raised: 'bg-midnight-800',
  charcoal: 'bg-charcoal-800',
}

const spacings: Record<NonNullable<SectionProps['spacing']>, string> = {
  default: 'py-24 md:py-36',
  tight: 'py-16 md:py-24',
  none: '',
}

export function Section({ children, className = '', tone = 'deep', id, labelledBy, spacing = 'default' }: SectionProps) {
  return (
    <section id={id} aria-labelledby={labelledBy} className={`relative ${tones[tone]} ${spacings[spacing]} ${className}`}>
      {children}
    </section>
  )
}
