/**
 * Prerendered pages (scripts/prerender.mjs) ship static markup inside #root marked with
 * `data-prerendered="<path>"`. While the live app takes over that first view, entrance
 * animations must not run, or content the visitor is already reading would blink out.
 */
let entry = typeof document !== 'undefined' && document.getElementById('root')?.hasAttribute('data-prerendered') === true

export function isSnapshotEntry(): boolean {
  return entry
}

export function endSnapshotEntry(): void {
  entry = false
}

export function normalisePath(path: string): string {
  return path === '/' ? '/' : path.replace(/\/+$/, '')
}
