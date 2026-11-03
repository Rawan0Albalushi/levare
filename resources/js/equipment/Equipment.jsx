import { useEffect, useMemo, useRef, useState } from 'react';
import { api } from '../api';
import { ConfirmDialog } from '../Confirm';
import { DownloadIcon, UploadIcon } from '../icons';
import { dateKey } from '../stock/logic';
import { EQUIPMENT_COLUMNS, rowsFromSheet } from './excel';

const EMPTY_FILTERS = {
    port_number: '',
    serial_number: '',
    description: '',
    assigned_well: '',
    location: '',
    condition: '',
    date_from: '',
    date_to: '',
    tested_from: '',
    tested_to: '',
    tested: '',
};

function emptyForm() {
    return {
        port_number: '',
        serial_number: '',
        description: '',
        assigned_well: '',
        recorded_on: dateKey(),
        location: '',
        condition: 'new',
    };
}

function toQuery(filters, page) {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) params.set(key, value);
    });
    if (page > 1) params.set('page', String(page));
    return params.toString();
}

function sameFilters(left, right) {
    return JSON.stringify(left) === JSON.stringify(right);
}

export function EquipmentPage({ lang, e, onNotice }) {
    const fileRef = useRef(null);
    const [draft, setDraft] = useState(EMPTY_FILTERS);
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
    const [summary, setSummary] = useState({ total: 0, new: 0, repaired: 0, untested: 0 });
    const [ready, setReady] = useState(false);
    const [loading, setLoading] = useState(false);
    const [importing, setImporting] = useState(false);
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);
    const query = useMemo(() => toQuery(filters, page), [filters, page]);

    const onNoticeRef = useRef(onNotice);
    const labelRef = useRef(e);
    const filtersRef = useRef(filters);
    onNoticeRef.current = onNotice;
    labelRef.current = e;
    filtersRef.current = filters;

    useEffect(() => {
        const timer = setTimeout(() => {
            if (sameFilters(filtersRef.current, draft)) return;
            setFilters(draft);
            setPage(1);
        }, 250);
        return () => clearTimeout(timer);
    }, [draft]);

    useEffect(() => {
        let active = true;
        setLoading(true);
        api(`/api/equipment${query ? `?${query}` : ''}`)
            .then((payload) => {
                if (!active) return;
                setRows(payload.equipment || []);
                setMeta(payload.meta || { current_page: 1, last_page: 1, total: 0 });
                setSummary(payload.summary || { total: 0, new: 0, repaired: 0, untested: 0 });
            })
            .catch(() => { if (active) onNoticeRef.current(labelRef.current('loadErr')); })
            .finally(() => { if (active) { setLoading(false); setReady(true); } });
        return () => { active = false; };
    }, [query]);

    function patch(partial) {
        const next = { ...draft, ...partial };
        setDraft(next);
        setFilters(next);
        setPage(1);
    }

    function clearFilters() {
        setDraft(EMPTY_FILTERS);
        setFilters(EMPTY_FILTERS);
        setPage(1);
    }

    async function reload() {
        const payload = await api(`/api/equipment${query ? `?${query}` : ''}`);
        setRows(payload.equipment || []);
        setMeta(payload.meta || { current_page: 1, last_page: 1, total: 0 });
        setSummary(payload.summary || { total: 0, new: 0, repaired: 0, untested: 0 });
    }

    async function sendToTest(item) {
        try {
            await api(`/api/equipment/${item.id}/test`, { method: 'POST' });
            await reload();
            onNotice(e('sent'));
        } catch {
            onNotice(e('saveErr'));
        }
    }

    function remove(item) {
        setPendingDelete(item);
    }

    async function confirmRemove() {
        const item = pendingDelete;
        if (!item) return;
        setPendingDelete(null);
        try {
            await api(`/api/equipment/${item.id}`, { method: 'DELETE' });
            await reload();
        } catch {
            onNotice(e('saveErr'));
        }
    }

    async function importFile(file) {
        setImporting(true);
        try {
            const XLSX = await import('xlsx');
            const book = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: true });
            const sheet = book.Sheets[book.SheetNames[0]];
            const matrix = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: true, defval: '' });
            const parsed = rowsFromSheet(matrix);
            if (parsed.errors.length) {
                const reason = { headers: 'headers', tooMany: 'tooMany', port: 'portErr', date: 'dateErr', tested: 'testedErr', condition: 'conditionErr' };
                onNotice(parsed.errors.slice(0, 4).map((error) => (
                    error.code === 'headers' || error.code === 'tooMany'
                        ? e(reason[error.code])
                        : e('row').replace('{row}', error.row).replace('{reason}', e(reason[error.code]))
                )).join(' · '));
                return;
            }
            if (!parsed.records.length) {
                onNotice(e('importErr'));
                return;
            }
            const payload = await api('/api/equipment/import', { method: 'POST', body: JSON.stringify({ rows: parsed.records }) });
            await reload();
            const skipped = Number(payload.skipped || 0);
            onNotice(skipped > 0
                ? `${e('imported')}: ${payload.imported} · ${e('alreadySaved')}: ${skipped}`
                : `${e('imported')}: ${payload.imported}`);
        } catch {
            onNotice(e('importErr'));
        } finally {
            setImporting(false);
        }
    }

    async function exportExcel() {
        const XLSX = await import('xlsx');
        const header = EQUIPMENT_COLUMNS.map((column) => (lang === 'ar' ? column[2] : column[1]));
        const records = [];
        let nextPage = 1;
        let lastPage = 1;
        do {
            const payload = await api(`/api/equipment${toQuery(filters, nextPage) ? `?${toQuery(filters, nextPage)}` : ''}`);
            records.push(...(payload.equipment || []));
            lastPage = payload.meta?.last_page || 1;
            nextPage += 1;
        } while (nextPage <= lastPage && nextPage <= 200);
        const body = records.map((item) => EQUIPMENT_COLUMNS.map(([key]) => {
            if (key === 'condition') return conditionText(item.condition, e);
            return item[key] || '';
        }));
        const sheet = XLSX.utils.aoa_to_sheet([header, ...body]);
        const book = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(book, sheet, 'Equipment');
        XLSX.writeFile(book, 'Levare-equipment.xlsx');
    }

    function downloadTemplate() {
        import('xlsx').then((XLSX) => {
            const header = EQUIPMENT_COLUMNS.map((column) => (lang === 'ar' ? column[2] : column[1]));
            const sample = [lang === 'ar' ? 'P-1001' : 'P-1001', 'SN-1001', lang === 'ar' ? 'مرحلة مضخة' : 'Pump stage', 'Lekhwair-7', dateKey(), 'Rack A', e('new'), ''];
            const sheet = XLSX.utils.aoa_to_sheet([header, sample]);
            const book = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(book, sheet, 'Equipment');
            XLSX.writeFile(book, 'Levare-equipment-template.xlsx');
        });
    }

    const formOpen = creating || editing;

    return (
        <main className="wrap stock-page">
            <div className="st-head">
                <div>
                    <h1>{e('title')}</h1>
                    <p>{e('sub')}</p>
                </div>
                <div className="st-btns">
                    <button type="button" className="btn" onClick={downloadTemplate}>{e('template')}</button>
                    <button type="button" className="btn sm" onClick={exportExcel} aria-label={e('exportExcel')}><DownloadIcon /><span>Excel</span></button>
                    <button type="button" className="btn sm" disabled={importing} aria-label={e('importExcel')} onClick={() => fileRef.current?.click()}><UploadIcon /><span>{importing ? e('importing') : 'Excel'}</span></button>
                    <input ref={fileRef} type="file" accept=".xlsx,.xlsb,.xls,.xlsm" hidden onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) importFile(file); }} />
                    <button type="button" className="btn primary" onClick={() => { setEditing(null); setCreating(true); }}>+ {e('add')}</button>
                </div>
            </div>

            <div className="kpis eq-kpis">
                {[
                    ['all', 'total', summary.total, ''],
                    ['new', 'new', summary.new, 'ok'],
                    ['repaired', 'repaired', summary.repaired, ''],
                    ['untested', 'untested', summary.untested, 'out'],
                ].map(([key, label, value, tone]) => {
                    const on = key === 'all'
                        ? !filters.condition && !filters.tested
                        : key === 'untested'
                            ? filters.tested === 'no'
                            : filters.condition === key;
                    return (
                        <button
                            type="button"
                            key={key}
                            className={`kpi ${tone} ${on ? 'on' : ''}`}
                            aria-pressed={on}
                            onClick={() => {
                                if (key === 'all') patch({ condition: '', tested: '' });
                                else if (key === 'untested') patch({ tested: filters.tested === 'no' ? '' : 'no' });
                                else patch({ condition: filters.condition === key ? '' : key });
                            }}
                        >
                            <span>{e(label)}</span><b className="mono">{value.toLocaleString('en-US')}</b>
                        </button>
                    );
                })}
            </div>

            <div className="eq-filters">
                {[
                    ['port_number', 'port'],
                    ['serial_number', 'serial'],
                    ['description', 'description'],
                    ['assigned_well', 'well'],
                    ['location', 'location'],
                ].map(([key, label]) => (
                    <div key={key}>
                        <label className="flabel" htmlFor={`eq-${key}`}>{e(label)}</label>
                        <input id={`eq-${key}`} value={draft[key]} onChange={(event) => setDraft({ ...draft, [key]: event.target.value })} />
                    </div>
                ))}
                <div>
                    <label className="flabel" htmlFor="eq-condition">{e('condition')}</label>
                    <select id="eq-condition" className="fsel" value={draft.condition} onChange={(event) => patch({ condition: event.target.value })}>
                        <option value="">{e('all')}</option>
                        <option value="new">{e('new')}</option>
                        <option value="repaired">{e('repaired')}</option>
                    </select>
                </div>
                <div className="eq-span-2">
                    <span className="flabel">{e('date')}</span>
                    <div className="eq-range">
                        <input type="date" aria-label={e('from')} value={draft.date_from} onChange={(event) => patch({ date_from: event.target.value })} />
                        <input type="date" aria-label={e('to')} value={draft.date_to} onChange={(event) => patch({ date_to: event.target.value })} />
                    </div>
                </div>
                <div className="eq-span-2">
                    <span className="flabel">{e('testedAt')}</span>
                    <div className="eq-range">
                        <input type="date" aria-label={`${e('testedAt')} ${e('from')}`} value={draft.tested_from} onChange={(event) => patch({ tested_from: event.target.value })} />
                        <input type="date" aria-label={`${e('testedAt')} ${e('to')}`} value={draft.tested_to} onChange={(event) => patch({ tested_to: event.target.value })} />
                    </div>
                </div>
                <div>
                    <label className="flabel" htmlFor="eq-tested">{e('testedState')}</label>
                    <select id="eq-tested" className="fsel" value={draft.tested} onChange={(event) => patch({ tested: event.target.value })}>
                        <option value="">{e('all')}</option>
                        <option value="yes">{e('testedYes')}</option>
                        <option value="no">{e('testedNo')}</option>
                    </select>
                </div>
            </div>
            <div className="eq-bar">
                <span><b className="mono">{meta.total.toLocaleString('en-US')}</b> {e('results')}</span>
                <button type="button" className="btn sm" onClick={clearFilters}>{e('clear')}</button>
            </div>

            {loading && !ready ? <div className="empty-st">{e('loading')}</div> : null}
            {ready && !rows.length ? <div className="empty-st"><p>{e('empty')}</p></div> : null}
            {rows.length ? (
                <div className="twrap">
                    <table className="parts eq-sheet">
                        <thead>
                            <tr>
                                <th>{e('port')}</th>
                                <th>{e('serial')}</th>
                                <th>{e('description')}</th>
                                <th>{e('well')}</th>
                                <th>{e('date')}</th>
                                <th>{e('location')}</th>
                                <th>{e('condition')}</th>
                                <th>{e('testedAt')}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((item) => (
                                <tr key={item.id} tabIndex={0} onClick={() => { setCreating(false); setEditing(item); }} onKeyDown={(event) => { if (event.key === 'Enter') { setCreating(false); setEditing(item); } }}>
                                    <td className="mono">{item.port_number || '—'}</td>
                                    <td className="mono">{item.serial_number || '—'}</td>
                                    <td>{item.description || '—'}</td>
                                    <td>{item.assigned_well || '—'}</td>
                                    <td className="mono">{item.recorded_on || '—'}</td>
                                    <td>{item.location || '—'}</td>
                                    <td><ConditionValue value={item.condition} e={e} /></td>
                                    <td className="mono">{item.tested_at || '—'}</td>
                                    <td>
                                        <div className="eq-actions">
                                            {!item.tested_at ? <button type="button" className="btn sm" onClick={(event) => { event.stopPropagation(); sendToTest(item); }}>{e('sendTest')}</button> : null}
                                            <button type="button" className="btn sm" onClick={(event) => { event.stopPropagation(); setCreating(false); setEditing(item); }}>{e('edit')}</button>
                                            <button type="button" className="btn sm miss" onClick={(event) => { event.stopPropagation(); remove(item); }}>{e('remove')}</button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}
            {meta.last_page > 1 ? (
                <div className="eq-pager">
                    <button type="button" className="btn" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>{e('prev')}</button>
                    <span className="mono">{e('page')} {meta.current_page} / {meta.last_page}</span>
                    <button type="button" className="btn" disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>{e('next')}</button>
                </div>
            ) : null}
            {formOpen ? (
                <EquipmentForm
                    item={editing}
                    e={e}
                    onClose={() => { setCreating(false); setEditing(null); }}
                    onSaved={async () => { setCreating(false); setEditing(null); await reload(); }}
                    onFail={() => onNotice(e('saveErr'))}
                    onTest={editing ? () => sendToTest(editing) : null}
                />
            ) : null}
            {pendingDelete ? (
                <ConfirmDialog
                    title={e('removeTitle')}
                    message={e('removeConfirm')}
                    detail={pendingDelete.port_number}
                    confirmLabel={e('remove')}
                    cancelLabel={e('cancel')}
                    onConfirm={confirmRemove}
                    onClose={() => setPendingDelete(null)}
                />
            ) : null}
        </main>
    );
}

function EquipmentForm({ item, e, onClose, onSaved, onFail, onTest }) {
    const first = useRef(null);
    const [form, setForm] = useState(() => item ? {
        port_number: item.port_number || '',
        serial_number: item.serial_number || '',
        description: item.description || '',
        assigned_well: item.assigned_well || '',
        recorded_on: item.recorded_on || '',
        location: item.location || '',
        condition: item.condition || '',
    } : emptyForm());
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        first.current?.focus();
        const onKey = (event) => { if (event.key === 'Escape') closeRef.current(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    function setField(key, value) {
        setForm((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [key]: '' }));
    }

    async function submit(event) {
        event.preventDefault();
        const next = {};
        if (!form.port_number.trim()) next.port_number = e('needPort');
        if (!form.recorded_on) next.recorded_on = e('needDate');
        if (!form.condition) next.condition = e('conditionErr');
        setErrors(next);
        if (Object.keys(next).length) return;
        setSaving(true);
        try {
            const body = {
                ...form,
                port_number: form.port_number.trim(),
                serial_number: form.serial_number.trim(),
                description: form.description.trim(),
                assigned_well: form.assigned_well.trim(),
                location: form.location.trim(),
            };
            await api(item ? `/api/equipment/${item.id}` : '/api/equipment', {
                method: item ? 'PUT' : 'POST',
                body: JSON.stringify(body),
            });
            await onSaved();
        } catch (error) {
            const server = {};
            Object.entries(error.errors || {}).forEach(([key, messages]) => {
                server[key] = Array.isArray(messages) ? messages[0] : e('saveErr');
            });
            if (Object.keys(server).length) setErrors(server);
            onFail();
            setSaving(false);
        }
    }

    return (
        <div className="modal-bg" onMouseDown={onClose}>
            <div className="modal eq" role="dialog" aria-modal="true" aria-labelledby="eq-form-title" onMouseDown={(event) => event.stopPropagation()}>
                <h2 id="eq-form-title">{item ? e('edit') : e('add')}</h2>
                <form className="form" onSubmit={submit}>
                    <div className="two">
                        <Field label={e('port')} error={errors.port_number}>
                            <input ref={first} value={form.port_number} onChange={(event) => setField('port_number', event.target.value)} required />
                        </Field>
                        <Field label={e('serial')} error={errors.serial_number}>
                            <input className="mono" value={form.serial_number} onChange={(event) => setField('serial_number', event.target.value)} />
                        </Field>
                    </div>
                    <Field label={e('description')} error={errors.description}>
                        <textarea className="eq-desc" value={form.description} onChange={(event) => setField('description', event.target.value)} />
                    </Field>
                    <div className="two">
                        <Field label={e('well')} error={errors.assigned_well}>
                            <input value={form.assigned_well} onChange={(event) => setField('assigned_well', event.target.value)} />
                        </Field>
                        <Field label={e('location')} error={errors.location}>
                            <input value={form.location} onChange={(event) => setField('location', event.target.value)} />
                        </Field>
                    </div>
                    <div className="two">
                        <Field label={e('date')} error={errors.recorded_on}>
                            <input className="mono" type="date" value={form.recorded_on} onChange={(event) => setField('recorded_on', event.target.value)} required />
                        </Field>
                        <Field label={e('condition')} error={errors.condition}>
                            <select className="fsel" value={form.condition} onChange={(event) => setField('condition', event.target.value)}>
                                {form.condition === '' ? <option value=""></option> : null}
                                {form.condition && form.condition !== 'new' && form.condition !== 'repaired' ? <option value={form.condition}>{form.condition}</option> : null}
                                <option value="new">{e('new')}</option>
                                <option value="repaired">{e('repaired')}</option>
                            </select>
                        </Field>
                    </div>
                    <p className="note">{item?.tested_at ? `${e('testedAt')}: ${item.tested_at}` : e('dateHint')}</p>
                    <div className="mfoot">
                        {item && !item.tested_at ? <button type="button" className="btn" onClick={onTest}>{e('sendTest')}</button> : <span />}
                        <div style={{ display: 'flex', gap: 8 }}>
                            <button type="button" className="btn" onClick={onClose}>{e('close')}</button>
                            <button type="submit" className="btn primary" disabled={saving}>{e('save')}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ConditionValue({ value, e }) {
    if (!value) return '—';
    if (value === 'new' || value === 'repaired') {
        return <span className={`badge ${value === 'new' ? 'done' : ''}`}>{conditionText(value, e)}</span>;
    }

    return conditionText(value, e);
}

function conditionText(value, e) {
    if (value === 'repaired') return e('repaired');
    if (value === 'new') return e('new');
    return value || '';
}

function Field({ label, error, children }) {
    return (
        <label>
            <span className="flabel">{label}</span>
            {children}
            {error ? <p className="err" role="alert">{error}</p> : null}
        </label>
    );
}
