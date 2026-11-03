import { useEffect, useRef } from 'react';

export function ConfirmDialog({ title, message, detail, confirmLabel, cancelLabel, onConfirm, onClose }) {
    const cancelRef = useRef(null);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        cancelRef.current?.focus();
        const onKey = (event) => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            closeRef.current();
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, []);

    return (
        <div className="modal-bg confirm-bg" onMouseDown={onClose}>
            <div className="modal confirm" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-text" onMouseDown={(event) => event.stopPropagation()}>
                <h2 id="confirm-title">{title}</h2>
                <p id="confirm-text">{message}</p>
                {detail ? <p className="confirm-detail mono">{detail}</p> : null}
                <div className="mfoot">
                    <button type="button" className="btn" ref={cancelRef} onClick={onClose}>{cancelLabel}</button>
                    <button type="button" className="btn danger" onClick={onConfirm}>{confirmLabel}</button>
                </div>
            </div>
        </div>
    );
}
