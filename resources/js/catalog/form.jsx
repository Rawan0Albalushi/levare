import { useEffect, useRef, useState } from 'react';
import { api } from '../api';
import { IconButton, TrashIcon } from '../icons';

const CATEGORIES = ['pump', 'seal', 'motor', 'shaft'];

function blankLocation() {
    return { id: null, current: 0, add: '', location: '', condition: '', status: '', reference: '' };
}

function fmtQty(value) {
    return (Math.round(Number(value) * 100) / 100).toLocaleString('en-US');
}

function addedNumber(value) {
    const text = String(value).trim();
    if (!text || Number.isNaN(Number(text))) return null;
    return Number(text);
}

function fromPart(part) {
    const rows = (part?.L || []).map((row) => ({
        id: row[7] || null,
        location: row[0] || '',
        current: Number(row[1]) || 0,
        add: '',
        condition: row[4] || '',
        status: row[5] || '',
        reference: row[6] || '',
    }));

    return {
        item_code: part?.id || '',
        part_number: part?.pn || '',
        description: part?.d || '',
        uom: part?.u || '',
        store_type: part?.t || '',
        categories: (part?.cats || []).filter((item) => CATEGORIES.includes(item)),
        priority: part?.pr || '',
        critical: Boolean(part?.cr),
        notes: part?.no || '',
        locations: rows.length ? rows : [blankLocation()],
    };
}

export function PartForm({ part, t, onClose, onSaved, onFail }) {
    const first = useRef(null);
    const [form, setForm] = useState(() => fromPart(part));
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        first.current?.focus();
        const onKey = (event) => {
            if (event.key === 'Escape') closeRef.current();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    function setField(key, value) {
        setForm((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [key]: '' }));
    }

    function setLocation(index, key, value) {
        setForm((current) => ({
            ...current,
            locations: current.locations.map((row, rowIndex) => (rowIndex === index ? { ...row, [key]: value } : row)),
        }));
        setErrors((current) => ({ ...current, [`locations.${index}.${key}`]: '' }));
    }

    function toggleCategory(category) {
        setForm((current) => ({
            ...current,
            categories: current.categories.includes(category)
                ? current.categories.filter((item) => item !== category)
                : [...current.categories, category],
        }));
        setErrors((current) => ({ ...current, categories: '' }));
    }

    async function submit(event) {
        event.preventDefault();
        const next = {};
        if (!form.description.trim()) next.description = t('needDesc');
        if (!form.item_code.trim() && !form.part_number.trim()) {
            next.item_code = t('needCode');
            next.part_number = t('needCode');
        }
        form.locations.forEach((row, index) => {
            const quantity = String(row.add).trim();
            const used = row.location.trim() || quantity || row.condition.trim();
            if (!used && !row.id) return;
            if (!row.location.trim()) next[`locations.${index}.location`] = t('needLoc');
            if (quantity && Number.isNaN(Number(quantity))) next[`locations.${index}.quantity`] = t('needQty');
            if (quantity && !Number.isNaN(Number(quantity)) && Number(quantity) < 0) next[`locations.${index}.quantity`] = t('needQty');
        });
        setErrors(next);
        if (Object.keys(next).length) return;

        const locations = form.locations.flatMap((row) => {
            const location = row.location.trim();
            const quantity = String(row.add).trim();
            const condition = row.condition.trim();
            if (!row.id && !location && !quantity && !condition) return [];
            const entry = {
                location,
                quantity: quantity === '' ? 0 : Number(quantity),
                condition: condition || null,
                status: row.status || null,
                reference: row.reference || null,
            };
            if (row.id) entry.id = row.id;
            return [entry];
        });

        setSaving(true);
        try {
            const payload = await api(part?.pk ? `/api/parts/${part.pk}` : '/api/parts', {
                method: part?.pk ? 'PUT' : 'POST',
                body: JSON.stringify({
                    item_code: form.item_code.trim(),
                    part_number: form.part_number.trim(),
                    description: form.description.trim(),
                    uom: form.uom.trim(),
                    store_type: form.store_type.trim(),
                    categories: form.categories,
                    priority: form.priority,
                    critical: form.critical,
                    notes: form.notes.trim(),
                    locations,
                }),
            });
            await onSaved(payload.part.pk);
        } catch (error) {
            const server = {};
            Object.entries(error.errors || {}).forEach(([key, messages]) => {
                server[key] = Array.isArray(messages) ? messages[0] : t('savePartErr');
            });
            if (Object.keys(server).length) setErrors(server);
            onFail();
            setSaving(false);
        }
    }

    return (
        <div className="modal-bg" onMouseDown={onClose}>
            <div className="modal eq" role="dialog" aria-modal="true" aria-labelledby="part-form-title" onMouseDown={(event) => event.stopPropagation()}>
                <h2 id="part-form-title">{part ? t('editPart') : t('addPart')}</h2>
                <form className="form" onSubmit={submit}>
                    <div className="two">
                        <Field label={t('partNo')} error={errors.part_number}>
                            <input ref={first} className="mono" value={form.part_number} onChange={(event) => setField('part_number', event.target.value)} />
                        </Field>
                        <Field label={t('itemCode')} error={errors.item_code}>
                            <input className="mono" value={form.item_code} onChange={(event) => setField('item_code', event.target.value)} />
                        </Field>
                    </div>
                    <Field label={t('description')} error={errors.description}>
                        <textarea className="eq-desc" value={form.description} onChange={(event) => setField('description', event.target.value)} required />
                    </Field>
                    <div className="two">
                        <Field label={t('unit')} error={errors.uom}>
                            <input value={form.uom} onChange={(event) => setField('uom', event.target.value)} />
                        </Field>
                        <Field label={t('priority')} error={errors.priority}>
                            <select className="fsel" value={form.priority} onChange={(event) => setField('priority', event.target.value)}>
                                <option value="">{t('noPriority')}</option>
                                {form.priority && !['HIGH', 'MEDIUM', 'LOW'].includes(form.priority) ? <option value={form.priority}>{form.priority}</option> : null}
                                <option value="HIGH">{t('priorityHigh')}</option>
                                <option value="MEDIUM">{t('priorityMedium')}</option>
                                <option value="LOW">{t('priorityLow')}</option>
                            </select>
                        </Field>
                    </div>
                    <div>
                        <span className="flabel">{t('category')}</span>
                        <div className="cat-picks">
                            {CATEGORIES.map((category) => (
                                <label key={category}>
                                    <input type="checkbox" checked={form.categories.includes(category)} onChange={() => toggleCategory(category)} />
                                    <span>{t(category)}</span>
                                </label>
                            ))}
                        </div>
                        {errors.categories ? <p className="err" role="alert">{errors.categories}</p> : null}
                    </div>
                    <Field label={t('storeType')} error={errors.store_type}>
                        <input value={form.store_type} onChange={(event) => setField('store_type', event.target.value)} />
                    </Field>
                    <label className="checkline">
                        <input type="checkbox" checked={form.critical} onChange={(event) => setField('critical', event.target.checked)} />
                        <span>{t('criticalList')}</span>
                    </label>
                    <Field label={t('notes')} error={errors.notes}>
                        <textarea className="eq-desc" value={form.notes} onChange={(event) => setField('notes', event.target.value)} />
                    </Field>
                    <div>
                        <span className="flabel">{t('locations')}</span>
                        {form.locations.some((row) => row.id) ? <p className="note-s">{t('addQtyHint')}</p> : null}
                        <div className="loc-edit">
                            {form.locations.map((row, index) => {
                                const added = addedNumber(row.add);
                                const quantityLabel = row.id ? t('addQty') : t('qty');
                                return (
                                    <div className={`loc-line ${row.id ? 'has-current' : ''}`} key={row.id || `new-${index}`}>
                                        <Field label={index === 0 ? t('location') : ''} error={errors[`locations.${index}.location`]}>
                                            <input value={row.location} aria-label={t('location')} onChange={(event) => setLocation(index, 'location', event.target.value)} />
                                        </Field>
                                        {row.id ? (
                                            <Field label={index === 0 ? t('currentQty') : ''}>
                                                <input className="mono" value={fmtQty(row.current)} readOnly aria-label={t('currentQty')} />
                                            </Field>
                                        ) : null}
                                        <Field label={index === 0 ? quantityLabel : ''} error={errors[`locations.${index}.quantity`]}>
                                            <input className="mono" inputMode="decimal" value={row.add} aria-label={quantityLabel} onChange={(event) => setLocation(index, 'add', event.target.value)} />
                                        </Field>
                                        <Field label={index === 0 ? t('cond') : ''}>
                                            <input value={row.condition} aria-label={t('cond')} onChange={(event) => setLocation(index, 'condition', event.target.value)} />
                                        </Field>
                                        <IconButton className="miss loc-remove" label={t('deletePart')} onClick={() => setForm((current) => ({ ...current, locations: current.locations.filter((_, rowIndex) => rowIndex !== index) }))}><TrashIcon /></IconButton>
                                        {row.id && added !== null ? <p className="qty-after">{t('qtyAfter')}: <b className="mono">{fmtQty(row.current + added)}</b></p> : null}
                                    </div>
                                );
                            })}
                        </div>
                        <button type="button" className="btn sm" style={{ marginTop: 8 }} onClick={() => setForm((current) => ({ ...current, locations: [...current.locations, blankLocation()] }))}>+ {t('addLocation')}</button>
                    </div>
                    <div className="mfoot">
                        <button type="button" className="btn" onClick={onClose}>{t('close')}</button>
                        <button type="submit" className="btn primary" disabled={saving}>{t('save')}</button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <label>
            {label ? <span className="flabel">{label}</span> : null}
            {children}
            {error ? <p className="err" role="alert">{error}</p> : null}
        </label>
    );
}
