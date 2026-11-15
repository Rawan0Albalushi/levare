import { useEffect, useRef, useState } from 'react';
import { api } from '../api';
import { fmtQ } from './logic';

function fmtWhen(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const ACTIONS = { set: 'qtySet', add: 'qtyAdd', open: 'qtyOpen', remove: 'qtyRemove' };

export function QuantityHistory({ part, t, onClose }) {
    const closeRef = useRef(null);
    const [rows, setRows] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        closeRef.current?.focus();
        const onKey = (event) => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            onClose();
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, [onClose]);

    useEffect(() => {
        let active = true;
        setFailed(false);
        api(`/api/parts/${part.pk}/adjustments`)
            .then((payload) => { if (active) setRows(payload.adjustments || []); })
            .catch(() => { if (active) { setRows([]); setFailed(true); } });
        return () => { active = false; };
    }, [part.pk]);

    return (
        <div className="modal-bg" onMouseDown={onClose}>
            <div className="modal issue-history" role="dialog" aria-modal="true" aria-labelledby="qty-history-title" onMouseDown={(event) => event.stopPropagation()}>
                <h2 id="qty-history-title">{t('qtyHistory')}</h2>
                <p className="mono assembly-pn">{part.pn || part.id}</p>
                {rows === null ? <p>{t('issueLoading')}</p> : null}
                {failed ? <p>{t('qtyHistoryErr')}</p> : null}
                {rows && !rows.length && !failed ? <p>{t('qtyHistoryEmpty')}</p> : null}
                <div className="issue-log">
                    {(rows || []).map((row) => (
                        <article key={row.id}>
                            <time className="mono" dateTime={row.at}>{fmtWhen(row.at)}</time>
                            <p className="qty-move">
                                <b className="mono">{row.location || '—'}</b>
                                <span>{t(ACTIONS[row.action] || 'qtySet')}</span>
                                <span className="mono">{fmtQ(row.before)} → {fmtQ(row.after)}</span>
                            </p>
                        </article>
                    ))}
                </div>
                <div className="mfoot">
                    <button type="button" className="btn" ref={closeRef} onClick={onClose}>{t('close')}</button>
                </div>
            </div>
        </div>
    );
}
