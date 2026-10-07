import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it } from 'vitest'
import { SiteHeader } from './SiteHeader'

function renderHeader() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, enabled: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SiteHeader />
        <main id="main">
          <a href="/behind">Behind the menu</a>
        </main>
        <footer>Footer</footer>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('SiteHeader mobile menu', () => {
  it('renders the panel outside the blurred header so it is positioned against the viewport', () => {
    const { container } = renderHeader()
    fireEvent.click(screen.getByRole('button', { name: 'Open menu' }))

    const menu = container.ownerDocument.getElementById('mobile-menu')
    expect(menu).not.toBeNull()
    expect(menu!.closest('header')).toBeNull()
    expect(menu!.className).toContain('fixed')
    expect(menu!.className).toContain('overflow-y-auto')
  })

  it('locks scrolling and makes the page behind inert while open, and restores both on Escape', () => {
    renderHeader()
    const main = document.getElementById('main')!
    const footer = document.querySelector('footer')!

    fireEvent.click(screen.getByRole('button', { name: 'Open menu' }))
    expect(document.body.style.overflow).toBe('hidden')
    expect(main.inert).toBe(true)
    expect(footer.inert).toBe(true)
    expect(screen.getByRole('button', { name: 'Close menu' })).toHaveAttribute('aria-expanded', 'true')

    fireEvent.keyDown(window, { key: 'Escape' })
    expect(document.body.style.overflow).toBe('')
    expect(main.inert).toBe(false)
    expect(footer.inert).toBe(false)
    expect(screen.getByRole('button', { name: 'Open menu' })).toHaveFocus()
  })

  it('keeps every destination reachable from the menu', () => {
    renderHeader()
    fireEvent.click(screen.getByRole('button', { name: 'Open menu' }))
    const nav = screen.getByRole('navigation', { name: 'Mobile' })
    const links = [...nav.querySelectorAll('a')].map((a) => a.getAttribute('href'))
    expect(links).toEqual(['/practice', '/practitioner', '/library', '/gatherings', '/journal', '/private-access', '/consultation'])
  })
})
