import { useQuery } from '@tanstack/react-query'
import { adminApi } from '../../lib/adminApi'
import { utcToZonedInput } from '../../lib/datetime'
import { timeZoneList } from '../../resources/FormField'
import { Input, Select } from '../../ui/Fields'

interface DayAvailability {
  date: string
  slots: { starts_at: string; ends_at: string; label: string }[]
}

export type TimeValue = { date: string; time: string; timezone: string }

/**
 * Administrators may book any time that does not overlap another booking; open slots from the
 * configured availability are offered as shortcuts.
 */
export function TimeChooser({ typeSlug, ignoreId, value, onChange, error }: { typeSlug: string | null; ignoreId?: number; value: TimeValue; onChange: (v: TimeValue) => void; error?: string }) {
  const slots = useQuery({
    queryKey: ['admin', 'availability', typeSlug, value.date, value.timezone, ignoreId],
    queryFn: () => adminApi.get<DayAvailability[]>('/admin/appointments/availability', { query: { type: typeSlug!, from: value.date, to: value.date, timezone: value.timezone, ignore: ignoreId } }),
    enabled: Boolean(typeSlug && /^\d{4}-\d{2}-\d{2}$/.test(value.date) && value.timezone),
  })
  const day = slots.data?.find((d) => d.date === value.date)

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-3">
        <Input label="Date" type="date" required value={value.date} onChange={(e) => onChange({ ...value, date: e.target.value })} />
        <Input label="Start time" type="time" required value={value.time} onChange={(e) => onChange({ ...value, time: e.target.value })} error={error} />
        <Select label="Time zone" required value={value.timezone} onChange={(e) => onChange({ ...value, timezone: e.target.value })} options={timeZoneList().map((z) => ({ value: z, label: z }))} />
      </div>
      {typeSlug && value.date && (
        <div>
          <p className="mb-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Open slots that day</p>
          {slots.isLoading ? (
            <p className="text-xs text-slate-400">Checking availability…</p>
          ) : slots.isError ? (
            <p className="text-xs text-danger-300">Availability could not be loaded. You can still enter a time manually.</p>
          ) : !day || day.slots.length === 0 ? (
            <p className="text-xs text-slate-400">No open slots in the weekly schedule. You can still enter a time manually if it does not overlap another booking.</p>
          ) : (
            <div className="flex flex-wrap gap-2">
              {day.slots.map((s) => {
                const time = utcToZonedInput(s.starts_at, value.timezone).slice(11)
                const active = time === value.time
                return (
                  <button
                    key={s.starts_at}
                    type="button"
                    aria-pressed={active}
                    onClick={() => onChange({ ...value, time })}
                    className={`border px-3 py-1.5 text-xs tabular-nums transition-colors ${active ? 'border-champagne-400 bg-champagne-400/15 text-champagne-200' : 'border-[var(--line-strong)] text-ivory-200 hover:border-champagne-400'}`}
                  >
                    {s.label}
                  </button>
                )
              })}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
