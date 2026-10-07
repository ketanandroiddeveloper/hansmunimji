import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it } from 'vitest'
import { humanize } from '../../lib/format'
import { Button, ButtonLink } from './Button'
import { ConfidentialitySeal, Glow } from './Ornaments'

describe('Button sizing', () => {
  it('gives the text-link variant a 44px hit area without padding or !important overrides', () => {
    render(
      <MemoryRouter>
        <ButtonLink to="/practice" variant="link">
          Explore
        </ButtonLink>
      </MemoryRouter>,
    )
    const cls = screen.getByRole('link', { name: /explore/i }).className
    expect(cls).toContain('after:absolute')
    expect(cls).toContain('after:-inset-y-2.5')
    expect(cls).not.toMatch(/(^|\s)!/)
    expect(cls).not.toMatch(/\bpx-\d/)
  })

  it('offers a flush size with touch-height padding and no horizontal padding', () => {
    render(
      <Button variant="ghost" size="flush">
        Back
      </Button>,
    )
    const cls = screen.getByRole('button', { name: /back/i }).className
    expect(cls).toContain('py-3')
    expect(cls).not.toMatch(/\bpx-/)
  })
})

describe('Ornaments', () => {
  it('lets the confidentiality seal wrap on narrow screens', () => {
    render(<ConfidentialitySeal label="Confidential · Encrypted inquiry" />)
    const seal = screen.getByText('Confidential · Encrypted inquiry').closest('p')!
    expect(seal.className).not.toMatch(/(^|\s)whitespace-nowrap/)
    expect(seal.className).toContain('max-w-full')
  })

  it('clips decorative glows to their section', () => {
    const { container } = render(<Glow className="-right-[10%] top-0 h-40 w-40" />)
    const wrapper = container.firstElementChild!
    expect(wrapper.className).toContain('overflow-hidden')
    expect(wrapper.className).toContain('inset-0')
    expect(wrapper).toHaveAttribute('aria-hidden', 'true')
  })
})

describe('humanize', () => {
  it('turns stored slugs into readable labels', () => {
    expect(humanize('guided_meditation')).toBe('Guided meditation')
    expect(humanize('sonic-healing')).toBe('Sonic healing')
    expect(humanize('reflection')).toBe('Reflection')
  })
})
