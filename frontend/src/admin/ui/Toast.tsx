import { createContext, useCallback, useContext, useState } from 'react'
import type { ReactNode } from 'react'

type Toast = { id: number; tone: 'success' | 'error'; message: string }

const ToastContext = createContext<((message: string, tone?: Toast['tone']) => void) | null>(null)

let nextId = 1

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const notify = useCallback((message: string, tone: Toast['tone'] = 'success') => {
    const id = nextId++
    setToasts((t) => [...t, { id, tone, message }])
    window.setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), tone === 'error' ? 8000 : 4000)
  }, [])

  return (
    <ToastContext.Provider value={notify}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed bottom-6 right-6 z-[80] flex w-80 flex-col gap-2">
        {toasts.map((t) => (
          <div
            key={t.id}
            role={t.tone === 'error' ? 'alert' : 'status'}
            className={`pointer-events-auto border-l-2 bg-midnight-800 px-4 py-3 text-sm font-light shadow-2xl shadow-black/40 ${t.tone === 'error' ? 'border-danger-300 text-danger-300' : 'border-champagne-400 text-ivory-50'}`}
          >
            {t.message}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const notify = useContext(ToastContext)
  if (!notify) throw new Error('useToast must be used inside ToastProvider')
  return notify
}
