import { useEffect, useRef, useState } from 'react';
import { api } from '../api';
import { fmtQ } from './logic';

function fmtWhen(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function IssueDialog({ lines, saving, t, onConfirm, onClose }) {
    const cancelRef = useRef(null);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;
    const blocked = lines.some((line) => line.short);

    useEffect(() => {
        cancelRef.current?.focus();
        const onKey = (event) => {
            if (event.key !== 'Escape' || saving) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            closeRef.current();
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, [saving]);

    return (
        <div className="modal-bg confirm-bg" onMouseDown={() => { if (!saving) onClose(); }}>
            <div className="modal confirm issue-confirm" role="alertdialog" aria-modal="true" aria-labelledby="issue-title" onMouseDown={(event) => event.stopPropagation()}>
                <h2 id="issue-title">{t('issueTitle')}</h2>
                <p>{blocked ? t('issueBlocked') : t('issueConfirm')}</p>
                <ul className="issue-list">
                    {lines.map((line) => (
                        <li key={line.n} className={line.short ? 'short' : ''}>
                            <b className="mono">{line.pn || line.id || '—'}</b>
                            <span className="mono">{fmtQ(line.qty)} {line.u}</span>
                            {line.short
                                ? <span>{t('issueOnHand')} <b className="mono">{fmtQ(line.have)}</b></span>
                                : <span className="mono">{line.plan.map((row) => `${row.loc} ${fmtQ(row.qty)}`).join(' · ')}</span>}
                        </li>
                    ))}
                </ul>
                <div className="mfoot">
                    <button type="button" className="btn" ref={cancelRef} onClick={onClose} disabled={saving}>{t('cancel')}</button>
                    <button type="button" className="btn danger" onClick={onConfirm} disabled={blocked || saving || !lines.length}>{t('issuePart')}</button>
                </div>
            </div>
        </div>
    );
}

export function IssueHistory({ assembly, revision, t, onClose }) {
    const closeRef = useRef(null);
    const [issues, setIssues] = useState(null);
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
        api(`/api/assemblies/${encodeURIComponent(assembly.pn)}/issues`)
            .then((payload) => { if (active) setIssues(payload.issues || []); })
            .catch(() => { if (active) { setIssues([]); setFailed(true); } });
        return () => { active = false; };
    }, [assembly.pn, revision]);

    return (
        <div className="modal-bg" onMouseDown={onClose}>
            <div className="modal issue-history" role="dialog" aria-modal="true" aria-labelledby="issue-history-title" onMouseDown={(event) => event.stopPropagation()}>
                <h2 id="issue-history-title">{t('issueHistory')}</h2>
                <p className="mono assembly-pn">{assembly.pn}</p>
                {issues === null ? <p>{t('issueLoading')}</p> : null}
                {failed ? <p>{t('issueErr')}</p> : null}
                {issues && !issues.length && !failed ? <p>{t('issueEmpty')}</p> : null}
                <div className="issue-log">
                    {(issues || []).map((issue) => (
                        <article key={issue.id}>
                            <time className="mono" dateTime={issue.at}>{fmtWhen(issue.at)}</time>
                            <ul className="issue-list">
                                {issue.lines.map((line) => (
                                    <li key={`${issue.id}-${line.n}`}>
                                        <b className="mono">{line.pn || line.id || '—'}</b>
                                        <span className="mono">{fmtQ(line.qty)} {line.u}</span>
                                        <span className="mono">{line.locations.map((row) => `${row.loc} ${fmtQ(row.qty)}`).join(' · ')}</span>
                                    </li>
                                ))}
                            </ul>
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
