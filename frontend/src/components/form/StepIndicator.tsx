export function StepIndicator({ steps, current, label = 'Application progress' }: { steps: string[]; current: number; label?: string }) {
  return (
    <nav aria-label={label}>
      <p className="sr-only" aria-live="polite">
        Step {current + 1} of {steps.length}: {steps[current]}
      </p>
      <ol className="grid gap-3" style={{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }}>
        {steps.map((label, i) => {
          const state = i < current ? 'done' : i === current ? 'current' : 'upcoming'
          return (
            <li key={label} aria-current={state === 'current' ? 'step' : undefined}>
              <span className={`block h-px transition-colors duration-700 ${state === 'upcoming' ? 'bg-[var(--line-strong)]' : 'bg-champagne-400'}`} />
              <span className={`mt-4 hidden items-baseline gap-2 text-[0.64rem] uppercase tracking-[0.22em] sm:flex ${state === 'current' ? 'text-champagne-200' : state === 'done' ? 'text-ivory-200/70' : 'text-slate-400/70'}`}>
                <span className="font-display text-sm normal-case italic tracking-normal">{['I', 'II', 'III', 'IV', 'V', 'VI'][i]}.</span>
                {label}
              </span>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
