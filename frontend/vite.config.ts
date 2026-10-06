/// <reference types="vitest/config" />
import { existsSync } from 'node:fs'
import { join, resolve, sep } from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv, type Plugin } from 'vite'

/**
 * Makes `vite preview` route like the production web server after `npm run prerender`:
 * /path → dist/path/index.html when a snapshot exists, otherwise dist/app-shell.html.
 */
function prerenderedPreview(): Plugin {
  return {
    name: 'prerendered-preview',
    configurePreviewServer(server) {
      const dist = resolve(server.config.root, server.config.build.outDir)
      server.middlewares.use((req, _res, next) => {
        const url = new URL(req.url ?? '/', 'http://localhost')
        const path = decodeURIComponent(url.pathname)
        if (req.method !== 'GET' || path.includes('.') || /^\/(api|media)\//.test(path)) return next()
        const page = join(dist, path, 'index.html')
        if (page.startsWith(dist + sep) && existsSync(page)) req.url = `${path.replace(/\/$/, '')}/index.html${url.search}`
        else if (existsSync(join(dist, 'app-shell.html'))) req.url = `/app-shell.html${url.search}`
        next()
      })
    },
  }
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backend = env.VITE_DEV_BACKEND ?? 'http://127.0.0.1:8080'

  return {
    plugins: [react(), tailwindcss(), prerenderedPreview()],
    server: {
      port: 5173,
      proxy: {
        '/api': { target: backend, changeOrigin: false },
        '/media': { target: backend, changeOrigin: false },
        '/sitemap.xml': { target: backend, changeOrigin: false },
        '/robots.txt': { target: backend, changeOrigin: false },
      },
    },
    build: {
      target: 'es2022',
      sourcemap: mode !== 'production',
    },
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: ['./src/test/setup.ts'],
      css: false,
    },
  }
})
