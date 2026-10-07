import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { SelectInput } from '../../components/form/Fields'
import { api } from '../../lib/api'
import {
  addDays,
  addMonths,
  firstOfMonth,
  lastOfMonth,
  longDateLabel,
  monthGrid,
  monthKey,
  monthLabel,
  monthOf,
  timeZoneOptions,
  todayIn,
  weekdayLabels,
  type IsoDate,
  type Month,
} from './calendar'

export interface Slot {
  starts_at: string
  ends_at: string
  label: string
}

interface DayAvailability {
  date: IsoDate
  slots: Slot[]
}

export type AvailabilityAuth = { inviteToken?: string | null; appointment?: { reference: string; token: string } }

export function SlotPicker({
  typeSlug,
  maxAdvanceDays,
  timezone,
  onTimezoneChange,
  value,
  onChange,
  auth,
  excludeStartsAt,
}: {
  typeSlug: string
  maxAdvanceDays: number
  timezone: string
  onTimezoneChange: (tz: string) => void
  value: Slot | null
  onChange: (slot: Slot | null) => void
  auth?: AvailabilityAuth
  excludeStartsAt?: string | null
}) {
  const today = todayIn(timezone)
  const lastDay = addDays(today, Math.max(1, maxAdvanceDays))
  const [month, setMonth] = useState<Month>(() => monthOf(today))
  const [selectedDate, setSelectedDate] = useState<IsoDate | null>(null)

  const minMonth = monthOf(today)
  const maxMonth = monthOf(lastDay)
  const from = firstOfMonth(month) < today ? today : firstOfMonth(month)
  const to = lastOfMonth(month) > lastDay ? lastDay : lastOfMonth(month)

  const headers: Record<string, string> = {}
  if (auth?.inviteToken) headers['X-Invite-Token'] = auth.inviteToken
  if (auth?.appointment) headers['X-Access-Token'] = auth.appointment.token

  const availability = useQuery({
    queryKey: ['availability', typeSlug, from, to, timezone, auth?.appointment?.reference ?? null, Boolean(auth?.inviteToken)],
    queryFn: () =>
      api.get<DayAvailability[]>('/appointments/availability', {
        query: { type: typeSlug, from, to, timezone, appointment: auth?.appointment?.reference },
        headers,
      }),
    enabled: from <= to,
    staleTime: 30_000,
  })

  const byDate = useMemo(() => {
    const map = new Map<IsoDate, Slot[]>()
    for (const day of availability.data ?? []) {
      const slots = day.slots.filter((s) => s.starts_at !== excludeStartsAt)
      if (slots.length) map.set(day.date, slots)
    }
    return map
  }, [availability.data, excludeStartsAt])

  // Select the first available day once a month's availability arrives.
  useEffect(() => {
    if (!availability.data) return
    if (selectedDate && byDate.has(selectedDate)) return
    const first = [...byDate.keys()].sort()[0] ?? null
    setSelectedDate(first)
  }, [availability.data, byDate, selectedDate])

  // A time zone change re-labels every slot; clear the chosen one.
  useEffect(() => {
    onChange(null)
    setSelectedDate(null)
    setMonth(monthOf(todayIn(timezone)))
  }, [timezone]) // onChange is intentionally excluded: only a zone change should reset the choice.

  const zones = useMemo(() => timeZoneOptions(timezone), [timezone])
  const weekdays = useMemo(() => weekdayLabels(), [])
  const slots = selectedDate ? (byDate.get(selectedDate) ?? []) : []
  const noneThisMonth = availability.isSuccess && byDate.size === 0

  return (
    <div>
      <SelectInput
        label="Times shown in"
        value={timezone}
        onChange={(e) => onTimezoneChange(e.target.value)}
        options={zones}
        required
        className="max-w-md"
      />

      <div className="mt-12 grid gap-12 xl:grid-cols-2 xl:gap-12">
        <div className="max-w-md xl:max-w-none">
          <div className="flex items-center justify-between">
            <p className="font-display text-2xl text-ivory-50" aria-live="polite">
              {monthLabel(month)}
            </p>
            <div className="flex gap-2">
              <MonthButton direction="prev" disabled={monthKey(month) <= monthKey(minMonth)} onClick={() => setMonth((m) => addMonths(m, -1))} />
              <MonthButton direction="next" disabled={monthKey(month) >= monthKey(maxMonth)} onClick={() => setMonth((m) => addMonths(m, 1))} />
            </div>
          </div>

          <table className="mt-8 w-full table-fixed border-collapse" aria-label={`Available days in ${monthLabel(month)}`} aria-busy={availability.isFetching || undefined}>
            <thead>
              <tr>
                {weekdays.map((w) => (
                  <th key={w.long} scope="col" abbr={w.long} className="pb-4 text-center text-[0.66rem] font-medium uppercase tracking-[0.2em] text-slate-400">
                    {w.short}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {monthGrid(month).map((week, i) => (
                <tr key={i}>
                  {week.map((date, j) => {
                    if (!date) return <td key={j} />
                    const available = byDate.has(date)
                    const selected = date === selectedDate
                    const day = Number(date.slice(8))
                    return (
                      <td key={date} className="p-0.5 text-center">
                        <button
                          type="button"
                          disabled={!available}
                          aria-pressed={selected}
                          aria-label={`${longDateLabel(date)}${available ? '' : ', no times available'}`}
                          onClick={() => {
                            setSelectedDate(date)
                            onChange(null)
                          }}
                          className={`relative mx-auto flex aspect-square w-full max-w-12 items-center justify-center rounded-full text-sm tabular-nums transition-colors duration-300 ${
                            selected
                              ? 'bg-champagne-400 text-midnight-950'
                              : available
                                ? 'text-ivory-50 hover:bg-ivory-50/[0.06] hover:text-champagne-200'
                                : 'cursor-default text-slate-400/40'
                          } ${date === today && !selected ? 'ring-1 ring-inset ring-ivory-50/20' : ''}`}
                        >
                          {day}
                          {available && !selected && <span aria-hidden="true" className="absolute bottom-1.5 h-1 w-1 rounded-full bg-champagne-400/80" />}
                        </button>
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="xl:border-l xl:border-[var(--line)] xl:pl-12">
          <p className="text-[0.68rem] font-medium uppercase tracking-[0.24em] text-ivory-200/70">{selectedDate ? longDateLabel(selectedDate) : 'Available times'}</p>
          <div className="mt-6 min-h-40" aria-live="polite">
            {availability.isLoading && <p className="text-sm font-light text-slate-400">Checking availability…</p>}
            {availability.isError && (
              <p role="alert" className="text-sm font-light text-danger-300">
                Availability could not be loaded. Please try again shortly.
              </p>
            )}
            {noneThisMonth && (
              <p className="text-sm font-light leading-relaxed text-slate-400">
                No times remain in {monthLabel(month)}.
                {monthKey(month) < monthKey(maxMonth) && (
                  <>
                    {' '}
                    <button type="button" onClick={() => setMonth((m) => addMonths(m, 1))} className="link-underline text-ivory-50">
                      View the following month
                    </button>
                    .
                  </>
                )}
              </p>
            )}
            {slots.length > 0 && (
              <div role="radiogroup" aria-label="Start time" className="grid grid-cols-[repeat(auto-fill,minmax(6.5rem,1fr))] gap-2">
                {slots.map((slot) => {
                  const checked = value?.starts_at === slot.starts_at
                  return (
                    <button
                      key={slot.starts_at}
                      type="button"
                      role="radio"
                      aria-checked={checked}
                      onClick={() => onChange(slot)}
                      className={`whitespace-nowrap border px-3 py-3 text-sm tabular-nums transition-colors duration-300 ${
                        checked ? 'border-champagne-400 bg-champagne-400/[0.08] text-champagne-200' : 'border-[var(--line-strong)] text-ivory-50 hover:border-ivory-50/40'
                      }`}
                    >
                      {slot.label}
                    </button>
                  )
                })}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

function MonthButton({ direction, disabled, onClick }: { direction: 'prev' | 'next'; disabled: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={direction === 'prev' ? 'Previous month' : 'Next month'}
      className="flex h-11 w-11 items-center justify-center rounded-full border border-[var(--line-strong)] text-ivory-50 transition-colors hover:border-champagne-400 hover:text-champagne-200 disabled:cursor-not-allowed disabled:opacity-30"
    >
      <svg aria-hidden="true" viewBox="0 0 8 12" className={`h-3 w-2 ${direction === 'prev' ? '' : 'rotate-180'}`} fill="none" stroke="currentColor" strokeWidth="1.2">
        <path d="M7 1 2 6l5 5" />
      </svg>
    </button>
  )
}
