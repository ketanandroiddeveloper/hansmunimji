import { formatAdminDateTime } from '../lib/datetime'
import { HISTORY_SOURCE, labelOf } from '../lib/labels'
import { Badge } from './Primitives'
import type { Tone } from './Primitives'

export interface HistoryEntry {
  from: string | null
  to: string
  source: string
  actor: string | null
  note: string | null
  at: string
}

/** Chronological status changes with who or what caused each one. */
export function StatusHistory({ entries, labels }: { entries: HistoryEntry[]; labels: Record<string, { label: string; tone: Tone }> }) {
  if (entries.length === 0) return <p className="text-sm font-light text-slate-400">No status changes recorded yet.</p>

  return (
    <ol className="space-y-3 text-sm">
      {entries.map((e, i) => {
        const to = labelOf(labels, e.to)
        return (
          <li key={`${e.at}-${i}`} className="border-l border-[var(--line-strong)] pl-3">
            <div className="flex flex-wrap items-center gap-2">
              {e.from && <span className="text-xs text-slate-400">{labelOf(labels, e.from).label} →</span>}
              <Badge tone={to.tone}>{to.label}</Badge>
            </div>
            <p className="mt-1 text-xs text-slate-400">
              {formatAdminDateTime(e.at)} · {e.actor ?? HISTORY_SOURCE[e.source] ?? e.source}
            </p>
            {e.note && <p className="mt-1 text-xs font-light text-ivory-200/80">{e.note}</p>}
          </li>
        )
      })}
    </ol>
  )
}
