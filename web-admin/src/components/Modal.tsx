import { useRef, useEffect } from 'react';

export default function Modal({ open, title, children, onClose }: { open: boolean; title: string; children: React.ReactNode; onClose: () => void }) {
  const overlay = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const handle = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    if (open) document.addEventListener('keydown', handle);
    return () => document.removeEventListener('keydown', handle);
  }, [open, onClose]);

  if (!open) return null;
  return (
    <div ref={overlay} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 100, display: 'flex', alignItems: 'center', justifyContent: 'center' }} onClick={(e) => e.target === overlay.current && onClose()}>
      <div className="modal-card" style={{ background: '#fff', padding: '1.5rem', borderRadius: '0.5rem', width: '90%', maxWidth: '500px', boxShadow: '0 10px 25px rgba(0,0,0,.15)' }}>
        <div className="modal-header">
          <h3>{title}</h3>
          <button onClick={onClose} style={{ fontSize: '1.2rem', padding: '0.2rem 0.5rem' }}>&times;</button>
        </div>
        {children}
      </div>
    </div>
  );
}
