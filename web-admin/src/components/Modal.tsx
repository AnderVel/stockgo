import { useRef, useEffect } from 'react'

type ModalProps = {
  open: boolean
  title: string
  children: React.ReactNode
  onClose: () => void
  busy?: boolean
}

export default function Modal({ open, title, children, onClose, busy = false }: ModalProps) {
  const overlay = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const handle = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !busy) onClose()
    }
    if (open) document.addEventListener('keydown', handle)
    return () => document.removeEventListener('keydown', handle)
  }, [busy, onClose, open])

  if (!open) return null
  return (
    <div
      ref={overlay}
      className="modal-backdrop"
      onClick={(event) => event.target === overlay.current && !busy && onClose()}
    >
      <div className="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div className="modal-header">
          <h3 id="modal-title">{title}</h3>
          <button className="icon-button" onClick={onClose} disabled={busy} aria-label="Cerrar">
            ×
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}
