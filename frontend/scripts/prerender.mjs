#!/usr/bin/env node
/**
 * Renders the public routes listed in the backend sitemap to static HTML so that crawlers and
 * link previews receive full content, metadata and JSON-LD without executing JavaScript.
 *
 * Run after `vite build` (see `npm run build:prerender`). Requires:
 *   - the backend API reachable at PRERENDER_API_ORIGIN (default http://127.0.0.1:8080),
 *     serving published content for the target environment;
 *   - Google Chrome or Chromium (CHROME_PATH, or a standard install location);
 *   - VITE_SITE_URL set at build time, so canonical and Open Graph URLs are absolute.
 *
 * Output: dist/<route>/index.html for each route, and dist/app-shell.html (the untouched SPA
 * shell) which the web server must use as the fallback for every other path.
 */
import { spawn, spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { createServer, request as httpRequest } from 'node:http'
import { request as httpsRequest } from 'node:https'
import { tmpdir } from 'node:os'
import { dirname, extname, join, normalize, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

const DIST = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'dist')
const API_ORIGIN = (process.env.PRERENDER_API_ORIGIN ?? 'http://127.0.0.1:8080').replace(/\/$/, '')
const EXTRA_ROUTES = (process.env.PRERENDER_EXTRA_ROUTES ?? '').split(',').map((r) => r.trim()).filter(Boolean)
const ROUTE_TIMEOUT_MS = Number(process.env.PRERENDER_TIMEOUT_MS ?? 20000)
const PROXIED = ['/api/', '/media/', '/sitemap.xml', '/robots.txt']
const SHELL_FILE = 'app-shell.html'

const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css',
  '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.webp': 'image/webp', '.avif': 'image/avif', '.ico': 'image/x-icon', '.woff2': 'font/woff2', '.woff': 'font/woff',
  '.txt': 'text/plain', '.xml': 'application/xml', '.webmanifest': 'application/manifest+json',
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

function fail(message) {
  console.error(`prerender: ${message}`)
  process.exit(1)
}

/* ---------------------------------------------------------------- shell + static server */

if (!existsSync(join(DIST, 'index.html'))) fail('dist/index.html not found. Run `vite build` first.')

const shellPath = join(DIST, SHELL_FILE)
const indexHtml = readFileSync(join(DIST, 'index.html'), 'utf8')
// Re-running on an already prerendered dist must start from the pristine shell, not a snapshot.
const shell = existsSync(shellPath) && indexHtml.includes('data-prerendered') ? readFileSync(shellPath, 'utf8') : indexHtml
if (shell.includes('data-prerendered')) fail('dist/index.html is a snapshot and no pristine app shell exists. Rebuild with `vite build`.')
writeFileSync(shellPath, shell)

function proxy(req, res) {
  const target = new URL(req.url, API_ORIGIN)
  const send = target.protocol === 'https:' ? httpsRequest : httpRequest
  const upstream = send(target, { method: req.method, headers: { ...req.headers, host: target.host } }, (up) => {
    res.writeHead(up.statusCode ?? 502, up.headers)
    up.pipe(res)
  })
  upstream.on('error', () => {
    res.writeHead(502, { 'content-type': 'text/plain' })
    res.end('API unreachable')
  })
  req.pipe(upstream)
}

const server = createServer((req, res) => {
  const pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname)
  if (PROXIED.some((p) => pathname === p || pathname.startsWith(p))) return proxy(req, res)

  const file = normalize(join(DIST, pathname))
  if (file.startsWith(DIST + sep) && existsSync(file) && statSync(file).isFile() && !file.endsWith(`${sep}index.html`)) {
    res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' })
    return res.end(readFileSync(file))
  }
  // Every page is rendered from the pristine shell, never from a snapshot written earlier in this run.
  res.writeHead(200, { 'content-type': MIME['.html'] })
  res.end(shell)
})
await new Promise((r) => server.listen(0, '127.0.0.1', r))
const ORIGIN = `http://127.0.0.1:${server.address().port}`

/* ---------------------------------------------------------------- routes */

async function sitemapRoutes() {
  let xml
  try {
    const response = await fetch(`${API_ORIGIN}/sitemap.xml`)
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    xml = await response.text()
  } catch (error) {
    fail(`could not fetch ${API_ORIGIN}/sitemap.xml (${error.message}). Is the API running? Set PRERENDER_API_ORIGIN.`)
  }
  return [...xml.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/g)].map((m) => new URL(m[1].replace(/&amp;/g, '&')).pathname)
}

function isSafeRoute(route) {
  return route.startsWith('/') && !route.includes('..') && !route.startsWith('/admin') && !route.startsWith('/api/') && /^[\w\-/.~%]*$/.test(route)
}

const routes = [...new Set([...(await sitemapRoutes()), ...EXTRA_ROUTES].map((r) => (r === '/' ? '/' : r.replace(/\/+$/, ''))))]
const rejected = routes.filter((r) => !isSafeRoute(r))
if (rejected.length) console.warn(`prerender: skipping unsafe or private routes: ${rejected.join(', ')}`)
const targets = routes.filter(isSafeRoute)
if (!targets.length) fail('the sitemap listed no public routes.')

/* ---------------------------------------------------------------- Chrome over CDP */

function findChrome() {
  const candidates = [
    process.env.CHROME_PATH,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser',
  ].filter(Boolean)
  const found = candidates.find((c) => existsSync(c))
  if (found) return found
  for (const name of ['google-chrome', 'chromium', 'chromium-browser']) {
    const which = spawnSync('which', [name], { encoding: 'utf8' })
    if (which.status === 0 && which.stdout.trim()) return which.stdout.trim()
  }
  fail('Chrome or Chromium not found. Install it or set CHROME_PATH.')
}

const profile = mkdtempSync(join(tmpdir(), 'prerender-'))
const chrome = spawn(findChrome(), [
  '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check',
  '--disable-extensions', '--disable-background-networking', '--disable-sync', '--mute-audio', '--hide-scrollbars', 'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'] })

process.on('exit', () => {
  if (chrome.exitCode === null) chrome.kill('SIGKILL')
  try {
    rmSync(profile, { recursive: true, force: true, maxRetries: 3 })
  } catch {
    // Temporary profile; the OS cleans the temp directory.
  }
})

const browserWs = await new Promise((resolveWs, reject) => {
  let buffer = ''
  const timer = setTimeout(() => reject(new Error('Chrome did not start within 15s')), 15000)
  chrome.stderr.on('data', (chunk) => {
    buffer += chunk
    const match = buffer.match(/DevTools listening on (ws:\/\/\S+)/)
    if (match) {
      clearTimeout(timer)
      resolveWs(match[1])
    }
  })
  chrome.on('exit', (code) => reject(new Error(`Chrome exited (${code})`)))
}).catch((error) => fail(error.message))

const ws = new WebSocket(browserWs)
await new Promise((r, reject) => {
  ws.addEventListener('open', r)
  ws.addEventListener('error', () => reject(new Error('could not connect to Chrome')))
})
let nextId = 0
const pending = new Map()
const pageErrors = []
ws.addEventListener('message', (event) => {
  const msg = JSON.parse(event.data)
  if (msg.id && pending.has(msg.id)) {
    const { resolveMsg, rejectMsg } = pending.get(msg.id)
    pending.delete(msg.id)
    if (msg.error) rejectMsg(new Error(`${msg.error.message} (${msg.error.code})`))
    else resolveMsg(msg.result)
  }
  if (msg.method === 'Runtime.exceptionThrown') pageErrors.push(msg.params.exceptionDetails.exception?.description ?? msg.params.exceptionDetails.text)
})
const send = (method, params = {}, sessionId) =>
  new Promise((resolveMsg, rejectMsg) => {
    const id = ++nextId
    pending.set(id, { resolveMsg, rejectMsg })
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }))
  })

const { targetId } = await send('Target.createTarget', { url: 'about:blank' })
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true })
const page = (method, params) => send(method, params, sessionId)
const evaluate = async (expression) => {
  const result = await page('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text)
  return result.result.value
}

await page('Page.enable')
await page('Runtime.enable')
await page('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false })
// Reveal animations render statically under reduced motion, so the snapshot contains visible content.
await page('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] })

/* ---------------------------------------------------------------- render */

const SETTLE = `(() => {
  const root = document.getElementById('root')
  const idle = typeof window.__APP_IDLE__ === 'function' && window.__APP_IDLE__()
  return { idle, length: root ? root.innerHTML.length : 0, hasMain: !!document.querySelector('main') }
})()`

const CAPTURE = (route) => `(() => {
  document.querySelectorAll('[data-prerender="skip"]').forEach((el) => el.remove())
  document.querySelectorAll('head title, head meta[name="description"], head meta[name="robots"], head meta[property], head meta[name^="twitter:"], head link[rel="canonical"], head link[rel="alternate"]')
    .forEach((el) => el.setAttribute('data-prerendered', ''))
  document.getElementById('root').setAttribute('data-prerendered', ${JSON.stringify(route)})
  return {
    html: '<!doctype html>\\n' + document.documentElement.outerHTML,
    robots: document.querySelector('meta[name="robots"]')?.dataset.pageRobots ?? document.querySelector('meta[name="robots"]')?.content ?? '',
    canonical: document.querySelector('link[rel="canonical"]')?.href ?? '',
    title: document.title,
  }
})()`

async function render(route) {
  pageErrors.length = 0
  await page('Page.navigate', { url: ORIGIN + route })
  const deadline = Date.now() + ROUTE_TIMEOUT_MS
  let last = -1
  let stable = 0
  while (stable < 3) {
    if (Date.now() > deadline) throw new Error(`did not settle within ${ROUTE_TIMEOUT_MS}ms${pageErrors.length ? `: ${pageErrors[0]}` : ''}`)
    await sleep(150)
    const state = await evaluate(SETTLE).catch(() => null)
    stable = state && state.idle && state.hasMain && state.length > 0 && state.length === last ? stable + 1 : 0
    last = state?.length ?? -1
  }
  return evaluate(CAPTURE(route))
}

function outputPath(route) {
  const file = route === '/' ? join(DIST, 'index.html') : join(DIST, ...route.split('/').filter(Boolean), 'index.html')
  if (!normalize(file).startsWith(DIST + sep)) throw new Error('resolved outside dist')
  return file
}

let failures = 0
let written = 0
for (const route of targets) {
  try {
    const result = await render(route)
    if (result.robots.includes('noindex')) {
      console.warn(`  skip  ${route} (page is noindex, e.g. not found)`)
      continue
    }
    if (result.canonical.startsWith(ORIGIN)) {
      fail(`canonical URL for ${route} points at the temporary prerender server. Set VITE_SITE_URL and rebuild.`)
    }
    const file = outputPath(route)
    mkdirSync(dirname(file), { recursive: true })
    writeFileSync(file, result.html)
    written++
    console.log(`  ok    ${route}  →  ${file.slice(DIST.length + 1)}  (${result.title})`)
  } catch (error) {
    failures++
    console.error(`  fail  ${route}: ${error.message}`)
  }
}

const exited = new Promise((r) => chrome.once('exit', r))
await send('Browser.close').catch(() => {})
await Promise.race([exited, sleep(5000)])
ws.close()
server.close()
console.log(`prerender: ${written} written, ${targets.length - written - failures} skipped, ${failures} failed; fallback shell at dist/${SHELL_FILE}`)
process.exit(failures ? 1 : 0)
