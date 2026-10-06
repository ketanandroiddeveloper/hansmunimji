import { motion, useReducedMotion } from 'framer-motion'
import { useState, type ReactNode } from 'react'
import { isSnapshotEntry } from '../../lib/snapshot'

const EASE = [0.22, 1, 0.36, 1] as const

type RevealProps = {
  children: ReactNode
  delay?: number
  y?: number
  className?: string
  as?: 'div' | 'li' | 'section' | 'article'
  duration?: number
}

/** Slow, short-distance reveal on first view. Renders statically under reduced motion and on a prerendered entry view. */
export function Reveal({ children, delay = 0, y = 20, className, as = 'div', duration = 0.85 }: RevealProps) {
  const reduce = useReducedMotion()
  const [fromSnapshot] = useState(isSnapshotEntry)
  const Tag = motion[as]
  if (reduce || fromSnapshot) {
    const Static = as
    return <Static className={className}>{children}</Static>
  }
  return (
    <Tag
      className={className}
      initial={{ opacity: 0, y }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, margin: '0px 0px -10% 0px' }}
      transition={{ duration, delay, ease: EASE }}
    >
      {children}
    </Tag>
  )
}

export { EASE }
