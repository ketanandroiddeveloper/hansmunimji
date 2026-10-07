import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { AButton } from './Primitives'

/** Native <dialog> modal: focus trapping, Escape and inert background come from the platform. */
export function Dialog({ open, onClose, title, children, footer, wide }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  const ref = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const el = ref.current
    if (!el) return
    if (open && !el.open) el.showModal()
    if (!open && el.open) el.close()
  }, [open])

  return createPortal(
    <dialog
      ref={ref}
      onSubmit={(e) => e.stopPropagation()}
      onClose={onClose}
      onCancel={(e) => {
        e.preventDefault()
        onClose()
      }}
      className={`m-auto w-[calc(100%-2rem)] ${wide ? 'max-w-3xl' : 'max-w-lg'} border border-[var(--line-strong)] bg-midnight-900 p-0 text-ivory-50 backdrop:bg-midnight-950/80 backdrop:backdrop-blur-sm`}
    >
      {open && (
        <div className="flex max-h-[85dvh] flex-col">
          <div className="flex items-center justify-between gap-4 border-b border-[var(--line)] px-6 py-4">
            <h2 className="font-display text-2xl">{title}</h2>
            <button type="button" onClick={onClose} className="-m-4 p-4 text-slate-400 hover:text-ivory-50" aria-label="Close">
              <svg aria-hidden="true" viewBox="0 0 14 14" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="1.4">
                <path d="M1 1l12 12M13 1 1 13" />
              </svg>
            </button>
          </div>
          <div className="overflow-y-auto px-6 py-5">{children}</div>
          {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--line)] px-6 py-4">{footer}</div>}
        </div>
      )}
    </dialog>,
    document.body,
  )
}

type ConfirmOptions = { title: string; body?: ReactNode; confirmLabel?: string; danger?: boolean }
type ConfirmState = ConfirmOptions & { resolve: (ok: boolean) => void }

const ConfirmContext = createContext<((options: ConfirmOptions) => Promise<boolean>) | null>(null)

export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<ConfirmState | null>(null)
  const confirm = useCallback((options: ConfirmOptions) => new Promise<boolean>((resolve) => setState({ ...options, resolve })), [])
  const close = (ok: boolean) => {
    state?.resolve(ok)
    setState(null)
  }
  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <Dialog
        open={state !== null}
        onClose={() => close(false)}
        title={state?.title ?? ''}
        footer={
          <>
            <AButton variant="ghost" onClick={() => close(false)}>
              Cancel
            </AButton>
            <AButton variant={state?.danger ? 'danger' : 'primary'} onClick={() => close(true)} autoFocus>
              {state?.confirmLabel ?? 'Confirm'}
            </AButton>
          </>
        }
      >
        <div className="text-sm font-light leading-relaxed text-ivory-200">{state?.body}</div>
      </Dialog>
    </ConfirmContext.Provider>
  )
}

export function useConfirm() {
  const confirm = useContext(ConfirmContext)
  if (!confirm) throw new Error('useConfirm must be used inside ConfirmProvider')
  return confirm
}
