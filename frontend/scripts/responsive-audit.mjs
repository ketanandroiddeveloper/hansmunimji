/**
 * Responsive audit: loads every public page at the target viewports in the locally installed Chrome
 * and reports horizontal overflow (with the elements causing it), small touch targets, tiny text,
 * console errors and failed requests. Optionally saves screenshots.
 *
 *   node scripts/responsive-audit.mjs                       # against http://127.0.0.1:5173
 *   AUDIT_BASE=http://127.0.0.1:5174 AUDIT_SHOTS=build/responsive/after node scripts/responsive-audit.mjs
 *
 * Env: AUDIT_BASE, AUDIT_SHOTS (directory; omit for no screenshots), AUDIT_SLICE (split full-page
 * screenshots into parts this many px tall), AUDIT_PAGES (comma-separated paths), AUDIT_VIEWPORTS
 * (comma-separated WxH), AUDIT_JSON (report path), CHROME_PATH.
 * Exits non-zero when any page overflows horizontally. Only ever issues GET navigations.
 */
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { chromium } from 'playwright-core'

const BASE = (process.env.AUDIT_BASE ?? 'http://127.0.0.1:5173').replace(/\/$/, '')
const SHOTS = process.env.AUDIT_SHOTS ?? ''
const JSON_OUT = process.env.AUDIT_JSON ?? ''
const SLICE = Number(process.env.AUDIT_SLICE ?? 0)

const VIEWPORTS = (process.env.AUDIT_VIEWPORTS ?? '320x568,360x640,375x667,390x844,412x915,430x932,667x375,844x390,768x1024,820x1180,1024x768,1280x720,1440x900,1920x1080')
  .split(',')
  .map((v) => {
    const [width, height] = v.split('x').map(Number)
    return { width, height }
  })

const PAGES = (process.env.AUDIT_PAGES ?? '/,/practice,/practice/gravity-celestial-meditation,/practitioner,/private-access,/consultation,/library,/gatherings,/gatherings/demo-full-moon-circle,/journal,/journal/demo-the-quiet-before-the-decision,/legal/privacy-policy,/privacy/requests,/no-such-page,/admin/login')
  .split(',')
  .filter(Boolean)

const touch = (vp) => vp.width < 1024

/** Runs in the page: everything the report needs, measured from the live layout. */
function measure() {
  const vw = document.documentElement.clientWidth
  const describe = (el) => {
    const cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 6).join('.') : ''
    const text = (el.innerText || el.getAttribute('aria-label') || el.getAttribute('alt') || '').trim().replace(/\s+/g, ' ').slice(0, 50)
    return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${cls ? '.' + cls : ''}${text ? ` "${text}"` : ''}`
  }
  const visible = (el) => {
    const s = getComputedStyle(el)
    if (s.visibility === 'hidden' || s.display === 'none' || Number(s.opacity) === 0) return false
    const r = el.getBoundingClientRect()
    return r.width > 0 && r.height > 0
  }
  const clipped = (el) => {
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      const s = getComputedStyle(p)
      if (s.overflowX !== 'visible' || s.contain.includes('paint')) {
        const r = p.getBoundingClientRect()
        if (r.right <= vw + 1 && r.left >= -1) return true
      }
    }
    return false
  }

  const overflow = []
  const scrollWidth = document.documentElement.scrollWidth
  for (const el of document.body.querySelectorAll('*')) {
    if (!visible(el)) continue
    const r = el.getBoundingClientRect()
    if ((r.right > vw + 1 || r.left < -1) && !clipped(el)) {
      if (!overflow.some((o) => o.el.contains(el))) overflow.push({ el, right: Math.round(r.right), left: Math.round(r.left) })
    }
  }

  const targets = []
  for (const el of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button], summary')) {
    if (!visible(el) || el.closest('[aria-hidden=true], .sr-only')) continue
    const r = el.getBoundingClientRect()
    // WCAG 2.5.8 exempts links inside running text.
    const inline = el.tagName === 'A' && getComputedStyle(el).display === 'inline' && el.closest('p, li, dd, label')
    if (inline) continue
    // A positioned ::after can enlarge the hit area beyond the visible box.
    const after = getComputedStyle(el, '::after')
    const hitW = after.position === 'absolute' && after.content !== 'none' ? Math.max(r.width, parseFloat(after.width) || 0) : r.width
    const hitH = after.position === 'absolute' && after.content !== 'none' ? Math.max(r.height, parseFloat(after.height) || 0) : r.height
    if (hitH < 44 || hitW < 44) targets.push({ desc: describe(el), w: Math.round(hitW), h: Math.round(hitH), under24: hitH < 24 || hitW < 24 })
  }

  const small = new Set()
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
  while (walker.nextNode()) {
    const node = walker.currentNode
    if (!node.textContent.trim()) continue
    const el = node.parentElement
    if (!el || !visible(el) || el.closest('.sr-only')) continue
    const size = parseFloat(getComputedStyle(el).fontSize)
    if (size < 12) small.add(`${Math.round(size * 10) / 10}px ${describe(el)}`)
  }

  const headings = [...document.querySelectorAll('h1, h2, h3')].filter(visible).map((h) => {
    const r = h.getBoundingClientRect()
    return { level: h.tagName, size: getComputedStyle(h).fontSize, wide: r.width > vw + 1 }
  })

  return {
    vw,
    scrollWidth,
    overflowPx: scrollWidth - vw,
    overflow: overflow.slice(0, 8).map((o) => ({ desc: describe(o.el), left: o.left, right: o.right })),
    smallTargets: targets.slice(0, 30),
    smallTargetCount: targets.length,
    under24Count: targets.filter((t) => t.under24).length,
    tinyText: [...small].slice(0, 15),
    h1Count: document.querySelectorAll('h1').length,
    headings: headings.slice(0, 3),
    title: document.title,
  }
}

const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: true,
})

const report = []
let failures = 0

for (const vp of VIEWPORTS) {
  const context = await browser.newContext({
    viewport: vp,
    deviceScaleFactor: 1,
    isMobile: touch(vp),
    hasTouch: touch(vp),
    reducedMotion: 'reduce',
  })
  const page = await context.newPage()
  await page.route('**/*', (route) => (route.request().method() === 'GET' || route.request().method() === 'HEAD' ? route.continue() : route.abort()))

  for (const path of PAGES) {
    const errors = []
    const failed = []
    const onConsole = (m) => m.type() === 'error' && errors.push(m.text().slice(0, 160))
    const onPageError = (e) => errors.push(String(e).slice(0, 160))
    const onResponse = (r) => r.status() >= 400 && failed.push(`${r.status()} ${new URL(r.url()).pathname}`)
    const onFailed = (r) => failed.push(`failed ${new URL(r.url()).pathname} ${r.failure()?.errorText ?? ''}`)
    page.on('console', onConsole)
    page.on('pageerror', onPageError)
    page.on('response', onResponse)
    page.on('requestfailed', onFailed)

    await page.goto(BASE + path, { waitUntil: 'networkidle' }).catch((e) => errors.push(`navigation: ${e.message}`))
    // Scroll through once so lazy images load before measuring and capturing.
    await page.evaluate(async () => {
      for (let y = 0; y < document.documentElement.scrollHeight; y += window.innerHeight) {
        window.scrollTo(0, y)
        await new Promise((r) => setTimeout(r, 60))
      }
      window.scrollTo(0, 0)
    })
    await page.waitForLoadState('networkidle').catch(() => {})
    await page.waitForTimeout(300)
    const result = await page.evaluate(measure)

    if (SHOTS) {
      const name = `${path === '/' ? 'home' : path.slice(1).replace(/\//g, '_')}-${vp.width}x${vp.height}`
      mkdirSync(SHOTS, { recursive: true })
      if (SLICE > 0) {
        const height = await page.evaluate(() => document.documentElement.scrollHeight)
        for (let y = 0, i = 1; y < height; y += SLICE, i++) {
          await page.screenshot({ path: join(SHOTS, `${name}-${i}.jpg`), fullPage: true, clip: { x: 0, y, width: vp.width, height: Math.min(SLICE, height - y) }, type: 'jpeg', quality: 70 })
        }
      } else {
        await page.screenshot({ path: join(SHOTS, `${name}.jpg`), fullPage: true, type: 'jpeg', quality: 70 })
      }
    }

    let menu = null
    if (path === '/' && vp.width < 1024) {
      const toggle = page.getByRole('button', { name: 'Open menu' })
      if (await toggle.isVisible().catch(() => false)) {
        await toggle.click()
        await page.waitForTimeout(700)
        menu = await page.evaluate(() => {
          const m = document.getElementById('mobile-menu')
          const r = m?.getBoundingClientRect()
          const links = [...(m?.querySelectorAll('a') ?? [])]
          const last = links.at(-1)?.getBoundingClientRect()
          return {
            open: Boolean(m),
            bodyLocked: getComputedStyle(document.body).overflow === 'hidden',
            menuScrolls: m ? m.scrollHeight > m.clientHeight + 1 && getComputedStyle(m).overflowY !== 'visible' : false,
            contentHeight: m?.scrollHeight ?? 0,
            menuHeight: Math.round(r?.height ?? 0),
            lastLinkReachable: last ? last.bottom <= window.innerHeight + 1 || (m.scrollHeight > m.clientHeight && getComputedStyle(m).overflowY !== 'visible') : false,
            opaque: m ? getComputedStyle(m).backgroundColor !== 'rgba(0, 0, 0, 0)' : false,
            backgroundInert: Boolean(document.getElementById('main')?.inert),
          }
        })
        if (SHOTS) await page.screenshot({ path: join(SHOTS, `menu-${vp.width}x${vp.height}.jpg`), type: 'jpeg', quality: 70 })
        if (menu.menuScrolls) {
          await page.evaluate(() => document.getElementById('mobile-menu')?.scrollTo(0, 1e5))
          menu.lastLinkVisibleAfterScroll = await page.evaluate(() => {
            const last = [...document.querySelectorAll('#mobile-menu a')].at(-1)?.getBoundingClientRect()
            return Boolean(last && last.bottom <= window.innerHeight + 1)
          })
          if (SHOTS) await page.screenshot({ path: join(SHOTS, `menu-${vp.width}x${vp.height}-scrolled.jpg`), type: 'jpeg', quality: 70 })
        }
        await page.keyboard.press('Escape')
        await page.waitForTimeout(500)
      }
    }

    page.off('console', onConsole)
    page.off('pageerror', onPageError)
    page.off('response', onResponse)
    page.off('requestfailed', onFailed)

    const entry = { path, viewport: `${vp.width}x${vp.height}`, ...result, errors, failed, menu }
    report.push(entry)
    if (result.overflowPx > 0 || result.overflow.length > 0) failures++
  }
  await context.close()
}
await browser.close()

if (JSON_OUT) {
  mkdirSync(dirname(JSON_OUT), { recursive: true })
  writeFileSync(JSON_OUT, JSON.stringify(report, null, 2))
}

for (const r of report) {
  const flags = []
  if (r.overflowPx > 0 || r.overflow.length) flags.push(`OVERFLOW +${r.overflowPx}px: ${r.overflow.map((o) => `${o.desc} [${o.left}..${o.right}]`).join(' | ')}`)
  if (r.errors.length) flags.push(`console: ${[...new Set(r.errors)].join(' | ')}`)
  if (r.failed.length) flags.push(`requests: ${[...new Set(r.failed)].join(' | ')}`)
  if (r.menu && (!r.menu.bodyLocked || !r.menu.lastLinkReachable || r.menu.menuHeight === 0 || !r.menu.opaque || !r.menu.backgroundInert || r.menu.lastLinkVisibleAfterScroll === false)) {
    flags.push(`menu: ${JSON.stringify(r.menu)}`)
  }
  if (flags.length) console.log(`${r.viewport.padEnd(9)} ${r.path}\n  ${flags.join('\n  ')}`)
}
const touchRows = report.filter((r) => Number(r.viewport.split('x')[0]) < 1024)
console.log(`\n${report.length} page renders, ${failures} with horizontal overflow.`)
console.log(`Small touch targets (<44px) on touch viewports: ${touchRows.reduce((n, r) => n + r.smallTargetCount, 0)} across ${touchRows.length} renders.`)
console.log(`Of those, below the WCAG 2.2 AA minimum (<24px): ${touchRows.reduce((n, r) => n + (r.under24Count ?? 0), 0)}.`)
const brokenMenus = report.filter((r) => r.menu && (r.menu.menuHeight === 0 || !r.menu.opaque || !r.menu.lastLinkReachable || r.menu.lastLinkVisibleAfterScroll === false))
console.log(`Mobile menu checks: ${report.filter((r) => r.menu).length} viewports, ${brokenMenus.length} with problems.`)
if (brokenMenus.length) failures++
process.exitCode = failures > 0 ? 1 : 0
