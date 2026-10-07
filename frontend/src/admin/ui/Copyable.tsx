import { useToast } from './Toast'

export function Copyable({ value }: { value: string }) {
  const notify = useToast()
  return (
    <span className="flex items-center gap-2">
      <code className="min-w-0 break-all font-mono text-xs text-ivory-200">{value}</code>
      <button
        type="button"
        className="shrink-0 text-[0.6rem] uppercase tracking-[0.16em] text-champagne-200 hover:underline"
        onClick={() => navigator.clipboard.writeText(value).then(() => notify('Copied.'), () => notify('Could not copy.', 'error'))}
      >
        Copy
      </button>
    </span>
  )
}
