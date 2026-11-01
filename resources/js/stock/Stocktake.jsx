import { useEffect, useMemo, useState } from 'react';
import { Link, Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api';
import { buildLocations, fmtQ, zoneOf } from '../catalog/logic';
import { Notes } from '../catalog/ui';
import { BackIcon, ChevronIcon, ClockIcon } from '../icons';
import { clock, counts, currentShift, dateKey, employeeLabel, finishLocation, fromKey, hours, newId, plan, reopenLocation, shiftStat } from './logic';

export function StocktakePage({ parts, lang, t, s, onNotice }) {
    const locations = useMemo(() => buildLocations(parts), [parts]);
    const [items, setItems] = useState([]);
    const [employees, setEmployees] = useState({});
    const navigate = useNavigate();

    useEffect(() => {
        let active = true;
        api('/api/stocktakes').then((payload) => { if (active) setItems(payload.stocktakes || []); }).catch(() => {});
        api('/api/employees').then((payload) => {
            if (!active) return;
            const map = {};
            for (const employee of payload.employees || []) map[employee.employee_number] = employee.name;
            setEmployees(map);
        }).catch(() => {});
        return () => { active = false; };
    }, []);

    async function save(document, creating = false) {
        const payload = creating
            ? await api('/api/stocktakes', { method: 'POST', body: JSON.stringify(document) })
            : await api(`/api/stocktakes/${document.id}`, { method: 'PUT', body: JSON.stringify(document) });
        setItems((current) => {
            const next = current.filter((item) => item.id !== payload.stocktake.id);
            return [payload.stocktake, ...next];
        });
        return payload.stocktake;
    }

    return (
        <Routes>
            <Route index element={<Home locations={locations} items={items} lang={lang} t={t} s={s} onCreate={async (document) => { const saved = await save(document, true); navigate(`/stock/${saved.id}`); }} />} />
            <Route path=":id" element={<Session items={items} employees={employees} setEmployees={setEmployees} locations={locations} parts={parts} lang={lang} t={t} s={s} onSave={(document) => save(document)} onNotice={onNotice} />} />
        </Routes>
    );
}

function Home({ locations, items, t, s, onCreate }) {
    const [filter, setFilter] = useState('all');
    const [category, setCategory] = useState('all');
    const [query, setQuery] = useState('');
    const [limit, setLimit] = useState(150);
    const [creating, setCreating] = useState(false);
    const section = locations.filter((location) => category === 'all' || location.cats.has(category));
    const filled = section.filter((location) => location.filled).length;
    const shown = section.filter((location) => (filter === 'all' || (filter === 'filled' ? location.filled : !location.filled)) && (!query || location.hay.includes(query.trim().toLowerCase())));
    const sorted = [...items].sort((a, b) => (a.status === 'prog' ? 0 : 1) - (b.status === 'prog' ? 0 : 1) || String(b.created).localeCompare(String(a.created)));

    return (
        <main className="wrap">
            <div className="st-head">
                <div><h1>{s('title')}</h1><p>{s('sub')}</p></div>
                <div className="st-btns"><button type="button" className="btn primary" onClick={() => setCreating(true)}>+ {s('new')}</button></div>
            </div>
            <h2 className="h2">{s('stkList')}</h2>
            {sorted.length ? (
                <div className="stk-list">
                    {sorted.map((item) => {
                        const summary = counts(item);
                        const progress = plan(item, summary);
                        return (
                            <Link className="stk-row" to={`/stock/${item.id}`} key={item.id}>
                                <div><div className="id mono">{item.name || item.id}</div><small className="mono">{item.name ? `${item.id} · ` : ''}{fromKey(item.start).toLocaleDateString()}</small></div>
                                <div className="badges"><span className={`badge ${item.status}`}>{s(item.status)}</span>{item.status === 'prog' ? <span className={`badge ${progress.over ? 'cancel' : ''}`}>{progress.before ? s('notStarted') : progress.over ? s('overdue') : `${progress.day}/${item.days}`}</span> : null}</div>
                                <div><div className="prow" style={{ margin: '0 0 6px' }}><span>{summary.done} / {summary.total}</span><b className="mono">{summary.pct}%</b></div><div className="bar"><i style={{ width: `${summary.pct}%` }} /></div></div>
                                <div className="chev"><ChevronIcon /></div>
                            </Link>
                        );
                    })}
                </div>
            ) : <div className="empty-st"><p>{s('none')}</p></div>}
            <p className="note-s">{s('storeNote')}</p>
            <h2 className="h2" style={{ marginTop: 30 }}>{s('locTitle')}</h2>
            <div className="kpis">
                {[['all', 'locTotal', section.length, ''], ['filled', 'locFilled', filled, 'ok'], ['empty', 'locEmpty', section.length - filled, 'out']].map(([key, label, value, tone]) => (
                    <button type="button" key={key} className={`kpi ${tone} ${filter === key ? 'on' : ''}`} onClick={() => { setFilter(key); setLimit(150); }}>
                        <span>{s(label)}</span><b className="mono">{value.toLocaleString('en-US')}</b>
                    </button>
                ))}
            </div>
            <div className="filters">
                <div className="grow"><div className="findbox"><input value={query} placeholder={s('locSearch')} onChange={(event) => { setQuery(event.target.value); setLimit(150); }} /></div></div>
                <div>
                    <select className="fsel" value={category} onChange={(event) => setCategory(event.target.value)}>
                        <option value="all">{s('allSections')}</option>
                        {['pump', 'seal', 'motor', 'shaft', 'other'].map((key) => <option key={key} value={key}>{t(key)}</option>)}
                    </select>
                </div>
            </div>
            <div className="twrap">
                <table className="parts">
                    <thead><tr><th>{s('loc')}</th><th>{s('result')}</th><th>{s('colLines')}</th><th>{s('qtyT')}</th><th>{s('pn')}</th></tr></thead>
                    <tbody>
                        {shown.slice(0, limit).map((location) => (
                            <tr key={location.name}>
                                <td className="mono"><b><Notes value={location.name} /></b></td>
                                <td>{location.filled ? <span className="rchip r-match">{s('locFilled')}</span> : <span className="rchip r-missing">{s('locEmpty')}</span>}</td>
                                <td className="mono">{location.lines.length}</td>
                                <td className="mono">{location.filled ? fmtQ(location.qty) : '0'}</td>
                                <td className="mono small">{location.lines.slice(0, 3).map((line) => line.pn || line.id).join(' · ')}{location.lines.length > 3 ? ' …' : ''}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {!shown.length ? <div className="empty-st">{s('nothing')}</div> : null}
            {shown.length > limit ? <button type="button" className="more-btn" onClick={() => setLimit((value) => value + 150)}>{s('more')}</button> : null}
            {creating ? <NewStocktake locations={locations} t={t} s={s} onClose={() => setCreating(false)} onCreate={onCreate} /> : null}
        </main>
    );
}

function NewStocktake({ locations, t, s, onClose, onCreate }) {
    const [name, setName] = useState('');
    const [days, setDays] = useState(15);
    const [start, setStart] = useState(dateKey());
    const [category, setCategory] = useState('all');
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(() => new Set());
    const pool = locations.filter((location) => category === 'all' || location.cats.has(category));
    const shown = pool.filter((location) => !query || location.name.toLowerCase().includes(query.trim().toLowerCase()));
    const chosen = selected.size ? pool.filter((location) => selected.has(location.name)) : pool;
    const zones = new Map();
    for (const location of shown) {
        const zone = zoneOf(location.name);
        zones.set(zone, (zones.get(zone) || 0) + 1);
    }

    function toggle(nameToToggle) {
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(nameToToggle)) next.delete(nameToToggle);
            else next.add(nameToToggle);
            return next;
        });
    }

    async function create() {
        if (!chosen.length) return;
        let id = newId();
        const document = {
            v: 2, id, name: name.trim(), days: Math.max(1, Math.min(365, Number(days) || 15)), start, cat: category,
            created: new Date().toISOString(), status: 'prog', open: null, finished: null, shifts: [], sur: [],
            locs: chosen.map((location) => ({
                n: location.name, e: !location.filled,
                lines: location.lines.map((line) => ({ ...line, r: null, c: null, by: null, sh: null, at: null })),
                done: false, res: null, by: null, sh: null, at: null,
            })),
        };
        await onCreate(document);
    }

    return (
        <div className="modal-bg">
            <div className="modal">
                <h2>{s('new')}</h2>
                <div className="form">
                    <div><label className="flabel">{s('name')}</label><input value={name} onChange={(event) => setName(event.target.value)} /></div>
                    <div className="two">
                        <div><label className="flabel">{s('days')}</label><input className="mono" type="number" min="1" max="365" value={days} onChange={(event) => setDays(event.target.value)} /></div>
                        <div><label className="flabel">{s('start')}</label><input className="mono" type="date" value={start} onChange={(event) => setStart(event.target.value)} /></div>
                    </div>
                    <div><label className="flabel">{s('section')}</label>
                        <select className="fsel" value={category} onChange={(event) => { setCategory(event.target.value); setSelected(new Set()); }}>
                            <option value="all">{s('allSections')}</option>
                            {['pump', 'seal', 'motor', 'shaft', 'other'].map((key) => <option key={key} value={key}>{t(key)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="flabel">{s('locs')}</label>
                        <input placeholder={s('pickSearch')} value={query} onChange={(event) => setQuery(event.target.value)} />
                        <div className="zones">{[...zones].sort((a, b) => b[1] - a[1]).slice(0, 24).map(([zone, count]) => <button type="button" key={zone} onClick={() => shown.filter((location) => zoneOf(location.name) === zone).forEach((location) => toggle(location.name))}>{zone} <small>{count}</small></button>)}</div>
                        <div className="locpick">
                            {shown.slice(0, 400).map((location) => (
                                <label key={location.name}>
                                    <input type="checkbox" checked={selected.has(location.name)} onChange={() => toggle(location.name)} />
                                    <span className="mono"><Notes value={location.name} /></span>
                                    <small>{location.filled ? location.lines.length : s('locEmpty')}</small>
                                </label>
                            ))}
                        </div>
                    </div>
                </div>
                <div className="mfoot">
                    <b>{chosen.length.toLocaleString('en-US')} · {chosen.reduce((sum, location) => sum + location.lines.length, 0).toLocaleString('en-US')}</b>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button type="button" className="btn" onClick={onClose}>{s('close')}</button>
                        <button type="button" className="btn primary" onClick={create}>{s('create')}</button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function Session({ items, employees, setEmployees, parts, lang, s, t, onSave, onNotice }) {
    const { id } = useParams();
    const source = items.find((item) => item.id === id);
    const [draft, setDraft] = useState(null);
    const [view, setView] = useState({ tab: 'locs', q: '', st: 'pending', open: null, limit: 120, edit: null });
    const [shiftOpen, setShiftOpen] = useState(false);
    const [surplusFor, setSurplusFor] = useState(null);
    const stocktake = draft || source;

    useEffect(() => { setDraft(null); }, [id]);
    useEffect(() => {
        const timer = setInterval(() => {
            const node = document.getElementById('shiftClock');
            if (node) node.textContent = clock(Date.now() - new Date(node.dataset.start));
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    if (!stocktake) return <main className="wrap"><div className="empty-st">{s('nothing')}</div></main>;

    const summary = counts(stocktake);
    const progress = plan(stocktake, summary);
    const shift = currentShift(stocktake);
    const locked = stocktake.status !== 'prog';
    const query = view.q.trim().toLowerCase();
    const shown = stocktake.locs.filter((location) => {
        if (query) return location.n.toLowerCase().includes(query) || location.lines.some((line) => `${line.pn} ${line.id}`.toLowerCase().includes(query));
        return view.st === 'all' || (view.st === 'done') === Boolean(location.done);
    });

    function commit(next) {
        setDraft(next);
        onSave(next).catch(() => onNotice(s('saveErr')));
    }

    function requireShift() {
        if (currentShift(stocktake)) return false;
        setShiftOpen(true);
        return true;
    }

    function mark(locationName, lineIndex, result, counted) {
        if (requireShift()) return;
        const next = structuredClone(stocktake);
        const location = next.locs.find((item) => item.n === locationName);
        const line = location.lines[lineIndex];
        const active = currentShift(next);
        Object.assign(line, { r: result, c: counted, at: new Date().toISOString(), sh: active?.id || null, by: active ? employeeLabel(active) : null });
        if (!location.done && location.lines.every((item) => item.r)) finishLocation(next, location);
        setView((current) => ({ ...current, edit: null }));
        commit(next);
    }

    return (
        <main className="wrap">
            <div className="st-head">
                <div><h1>{stocktake.name || stocktake.id}</h1><p className="mono">{stocktake.name ? `${stocktake.id} · ` : ''}{stocktake.cat === 'all' ? s('allSections') : t(stocktake.cat)}</p></div>
                <div className="st-btns">
                    <Link className="btn" to="/stock"><BackIcon />{s('back')}</Link>
                    <button type="button" className="btn" onClick={() => window.print()}>{s('checklist')}</button>
                    <button type="button" className="btn" onClick={() => exportStocktake(stocktake, s)}>{s('export')}</button>
                    {stocktake.status === 'prog' ? <button type="button" className="btn danger" onClick={() => { if (window.confirm(s('cancelConfirm'))) { const next = structuredClone(stocktake); const active = currentShift(next); if (active) active.end = new Date().toISOString(); next.open = null; next.status = 'cancel'; commit(next); } }}>{s('cancelBtn')}</button> : null}
                </div>
            </div>
            {stocktake.status === 'done' ? <div className="notice" style={{ background: 'var(--ok-bg)', borderColor: '#bfe8d4' }}><h3 style={{ color: 'var(--ok)' }}>✓ {s('finished')}</h3><p>{s('finishedOn')}: {stocktake.finished ? new Date(stocktake.finished).toLocaleString(lang === 'ar' ? 'ar-OM' : 'en-GB') : ''}</p></div> : null}
            {stocktake.status === 'prog' ? (shift
                ? <div className="shiftbar live"><span className="pulse" /><div><b>{s('shiftOpen')}</b> — <b className="mono">{employeeLabel(shift)}</b></div><div><ClockIcon /><b className="mono" id="shiftClock" data-start={shift.start}>{clock(Date.now() - new Date(shift.start))}</b></div><div>{s('shiftLocs')}: <b className="mono">{shiftStat(stocktake, shift).locs}</b></div><span className="grow" /><button type="button" className="btn danger" onClick={() => { const next = structuredClone(stocktake); const active = currentShift(next); if (active) active.end = new Date().toISOString(); next.open = null; commit(next); }}>{s('closeShift')}</button></div>
                : <div className="shiftbar"><div>{s('needShift')}</div><span className="grow" /><button type="button" className="btn primary" onClick={() => setShiftOpen(true)}><ClockIcon />{s('openShift')}</button></div>) : null}
            <div className="panel">
                <div className="badges"><span className={`badge ${stocktake.status}`}>{s(stocktake.status)}</span></div>
                <div className="prow"><span>{summary.done.toLocaleString('en-US')} / {summary.total.toLocaleString('en-US')}</span><b className="mono">{summary.pct}%</b></div>
                <div className="bar"><i style={{ width: `${summary.pct}%` }} /></div>
                <div className="sgrid">
                    {[['locDoneS', summary.done, 'c-match'], ['locLeft', summary.left, ''], ['emptyOk', summary.emptyOk, ''], ['foundS', summary.found, 'c-surplus'], ['todayDone', summary.today, ''], ['target', stocktake.status === 'prog' ? progress.target : '—', 'c-brand']].map(([key, value, tone]) => (
                        <div key={key}><div className="l">{s(key)}</div><div className={`v mono ${tone}`}>{typeof value === 'number' ? value.toLocaleString('en-US') : value}</div></div>
                    ))}
                </div>
            </div>
            <div className="seg" style={{ marginBottom: 14 }}>
                {[['locs', 'tabLocs'], ['shifts', 'tabShifts'], ['lines', 'tabLines']].map(([key, label]) => <button type="button" key={key} className={view.tab === key ? 'on' : ''} onClick={() => setView((current) => ({ ...current, tab: key, limit: 120 }))}>{s(label)}</button>)}
            </div>
            {view.tab === 'locs' ? (
                <>
                    <div className="filters">
                        <div className="grow"><div className="findbox"><input className="mono" placeholder={s('find')} value={view.q} onChange={(event) => setView((current) => ({ ...current, q: event.target.value }))} /></div></div>
                        <div className="seg">{['pending', 'done', 'all'].map((key) => <button type="button" key={key} className={view.st === key ? 'on' : ''} onClick={() => setView((current) => ({ ...current, st: key }))}>{s('st' + key[0].toUpperCase() + key.slice(1))}</button>)}</div>
                    </div>
                    {shown.slice(0, view.limit).map((location) => {
                        const open = view.open === location.n;
                        return (
                            <div className={`lrow ${location.done ? 'done' : ''} ${open ? 'open' : ''}`} key={location.n}>
                                <button type="button" className="lhead" onClick={() => setView((current) => ({ ...current, open: current.open === location.n ? null : location.n, edit: null }))}>
                                    <span className="chev"><ChevronIcon /></span>
                                    <b className="mono lname2"><Notes value={location.n} /></b>
                                    <span className="badge">{location.e ? s('sysEmpty') : location.lines.length}</span>
                                    <span className="grow" />
                                    <span className={`rchip ${location.done ? 'r-match' : ''}`}>{location.done ? s('doneS') : s('pendingS')}</span>
                                </button>
                                {open ? (
                                    <div className="ldetail">
                                        {location.e && !location.done && location.res !== 'found' ? (
                                            <div className="acts">
                                                <button type="button" className="btn primary" onClick={() => { if (requireShift()) return; const next = structuredClone(stocktake); const target = next.locs.find((item) => item.n === location.n); target.res = 'empty'; finishLocation(next, target); commit(next); }}>{s('isEmpty')}</button>
                                                <button type="button" className="btn warn" onClick={() => { if (!requireShift()) setSurplusFor(location.n); }}>{s('hasItems')}</button>
                                            </div>
                                        ) : location.lines.map((line, index) => (
                                            <div className={`line ${line.r ? 'is-' + line.r : ''}`} key={`${location.n}-${index}`}>
                                                <div className="info">
                                                    <div className="pnb mono">{line.pn || line.id || '—'}{line.pn && line.id ? <small> ID {line.id}</small> : null}</div>
                                                    <div className="meta ltr"><Notes value={line.d} /></div>
                                                    <div className="meta">{s('expected')}: <b className="mono">{fmtQ(line.exp)} {line.u || ''}</b></div>
                                                </div>
                                                <div className="acts">
                                                    {line.r ? <><span className={`rchip r-${line.r}`}>{s(line.r)}</span>{locked ? null : <button type="button" className="btn sm" onClick={() => { const next = structuredClone(stocktake); const target = next.locs.find((item) => item.n === location.n); Object.assign(target.lines[index], { r: null, c: null, at: null, sh: null, by: null }); if (target.done) reopenLocation(next, target); commit(next); }}>{s('undo')}</button>}</> : locked ? null : (
                                                        <>
                                                            <button type="button" className="btn primary" onClick={() => mark(location.n, index, 'match', line.exp)}>✓ {s('present')}</button>
                                                            <button type="button" className="btn warn" onClick={() => setView((current) => ({ ...current, edit: `${location.n}\u0000${index}` }))}>⚠ {s('mismatch')}</button>
                                                            <button type="button" className="btn miss" onClick={() => mark(location.n, index, 'missing', 0)}>{s('missing')}</button>
                                                        </>
                                                    )}
                                                    {view.edit === `${location.n}\u0000${index}` ? <CountEditor expected={line.exp} saveLabel={s('save')} onSave={(value) => mark(location.n, index, value === line.exp ? 'match' : value === 0 ? 'missing' : 'mismatch', value)} /> : null}
                                                </div>
                                            </div>
                                        ))}
                                        {!locked && (!location.e || location.res === 'found' || location.done) ? <button type="button" className="btn sm" onClick={() => { if (!requireShift()) setSurplusFor(location.n); }}>{s('addFound')}</button> : null}
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                    {shown.length > view.limit ? <button type="button" className="more-btn" onClick={() => setView((current) => ({ ...current, limit: current.limit + 120 }))}>{s('more')}</button> : null}
                </>
            ) : null}
            {view.tab === 'shifts' ? <ShiftTable stocktake={stocktake} s={s} lang="en" /> : null}
            {view.tab === 'lines' ? <LinesTable stocktake={stocktake} s={s} limit={view.limit} onMore={() => setView((current) => ({ ...current, limit: current.limit + 120 }))} /> : null}
            {shiftOpen ? <ShiftForm s={s} employees={employees} onClose={() => setShiftOpen(false)} onStart={(employee, name) => {
                const next = structuredClone(stocktake);
                const shiftId = `SH${Date.now().toString(36)}`;
                next.shifts.push({ id: shiftId, emp: employee, nm: name, start: new Date().toISOString(), end: null });
                next.open = shiftId;
                if (name) {
                    setEmployees((current) => ({ ...current, [employee]: name }));
                    api('/api/employees', { method: 'PUT', body: JSON.stringify({ employee_number: employee, name }) }).catch(() => {});
                }
                commit(next);
                setShiftOpen(false);
            }} /> : null}
            {surplusFor ? <SurplusForm location={surplusFor} parts={parts} s={s} onClose={() => setSurplusFor(null)} onSave={(entry) => {
                if (requireShift()) return;
                const next = structuredClone(stocktake);
                const active = currentShift(next);
                next.sur.push({ ...entry, by: active ? employeeLabel(active) : '', sh: active?.id || null, at: new Date().toISOString() });
                const location = next.locs.find((item) => item.n === entry.loc);
                if (location?.e && !location.done) { location.res = 'found'; finishLocation(next, location); }
                commit(next);
                setSurplusFor(null);
            }} /> : null}
            <div id="printArea">
                <h1>{stocktake.name || stocktake.id}</h1>
                {stocktake.locs.map((location) => (
                    <section key={location.n}>
                        <h2>{location.n}{location.e ? ` — ${s('sysEmpty')}` : ''}</h2>
                        {location.lines.length ? (
                            <table><thead><tr><th>{s('pn')}</th><th>{s('desc')}</th><th>{s('expected')}</th><th>{s('counted')}</th></tr></thead>
                                <tbody>{location.lines.map((line, index) => <tr key={index}><td>{line.pn}</td><td>{line.d}</td><td>{fmtQ(line.exp)}</td><td></td></tr>)}</tbody></table>
                        ) : <p>☐ {s('isEmpty')}</p>}
                    </section>
                ))}
            </div>
        </main>
    );
}

function CountEditor({ expected, saveLabel, onSave }) {
    const [value, setValue] = useState(expected);
    return (
        <>
            <input className="qin mono" type="number" min="0" step="any" value={value} onChange={(event) => setValue(event.target.value)} />
            <button type="button" className="btn sm primary" onClick={() => { const parsed = parseFloat(value); if (!Number.isNaN(parsed) && parsed >= 0) onSave(parsed); }}>{saveLabel}</button>
        </>
    );
}

function ShiftForm({ s, employees, onClose, onStart }) {
    const [employee, setEmployee] = useState('');
    const [name, setName] = useState('');
    return (
        <div className="modal-bg">
            <div className="modal">
                <h2>{s('openShift')}</h2>
                <div className="form">
                    <div><label className="flabel">{s('empNo')}</label><input className="mono" value={employee} onChange={(event) => { setEmployee(event.target.value); if (employees[event.target.value.trim()]) setName(employees[event.target.value.trim()]); }} /></div>
                    <div><label className="flabel">{s('empName')}</label><input value={name} onChange={(event) => setName(event.target.value)} /></div>
                </div>
                <div className="mfoot"><span /><div style={{ display: 'flex', gap: 8 }}><button type="button" className="btn" onClick={onClose}>{s('close')}</button><button type="button" className="btn primary" onClick={() => { if (!employee.trim()) return; onStart(employee.trim(), name.trim()); }}>{s('startShift')}</button></div></div>
            </div>
        </div>
    );
}

function SurplusForm({ location, parts, s, onClose, onSave }) {
    const [pn, setPn] = useState('');
    const [qty, setQty] = useState(1);
    const part = parts.find((item) => item.pn === pn.trim());
    return (
        <div className="modal-bg">
            <div className="modal">
                <h2>{s('surTitle')}</h2>
                <p className="note-s">{s('surNote')}</p>
                <div className="form">
                    <div><label className="flabel">{s('loc')}</label><input className="mono" value={location} readOnly /></div>
                    <div><label className="flabel">{s('pn')}</label><input className="mono" value={pn} onChange={(event) => setPn(event.target.value)} /><div className="note-s ltr">{part?.d || ''}</div></div>
                    <div><label className="flabel">{s('qty')}</label><input className="mono" type="number" min="0" step="any" value={qty} onChange={(event) => setQty(event.target.value)} /></div>
                </div>
                <div className="mfoot"><span /><div style={{ display: 'flex', gap: 8 }}><button type="button" className="btn" onClick={onClose}>{s('close')}</button><button type="button" className="btn primary" onClick={() => { const counted = parseFloat(qty); if (!pn.trim() || Number.isNaN(counted) || counted <= 0) return; onSave({ pn: pn.trim(), id: part?.id || '', d: part?.d || '', u: part?.u || '', loc: location, c: counted }); }}>{s('save')}</button></div></div>
            </div>
        </div>
    );
}

function ShiftTable({ stocktake, s }) {
    if (!stocktake.shifts.length) return <div className="empty-st">{s('noShifts')}</div>;
    return (
        <div className="twrap">
            <table className="parts">
                <thead><tr><th>{s('colEmp')}</th><th>{s('colStart')}</th><th>{s('colEnd')}</th><th>{s('colHours')}</th><th>{s('colLocs')}</th><th>{s('colLines')}</th></tr></thead>
                <tbody>
                    {[...stocktake.shifts].reverse().map((shift) => {
                        const stat = shiftStat(stocktake, shift);
                        return <tr key={shift.id}><td><b className="mono">{shift.emp}</b> {shift.nm}</td><td>{new Date(shift.start).toLocaleString()}</td><td>{shift.end ? new Date(shift.end).toLocaleString() : s('openNow')}</td><td className="mono">{hours(stat.ms)}</td><td className="mono"><b>{stat.locs}</b></td><td className="mono">{stat.lines}</td></tr>;
                    })}
                </tbody>
            </table>
        </div>
    );
}

function LinesTable({ stocktake, s, limit, onMore }) {
    const rows = [];
    for (const location of stocktake.locs) {
        for (const line of location.lines) rows.push({ loc: location.n, ...line });
        if (!location.lines.length) rows.push({ loc: location.n, pn: '', d: s('sysEmpty'), exp: 0, r: location.res || 'unchecked' });
    }
    for (const extra of stocktake.sur) rows.push({ loc: extra.loc, pn: extra.pn, d: extra.d, exp: 0, c: extra.c, r: 'surplus' });
    return (
        <>
            <div className="twrap">
                <table className="parts">
                    <thead><tr><th>{s('loc')}</th><th>{s('pn')}</th><th>{s('desc')}</th><th>{s('expected')}</th><th>{s('result')}</th></tr></thead>
                    <tbody>{rows.slice(0, limit).map((row, index) => <tr key={index}><td className="mono"><b><Notes value={row.loc} /></b></td><td className="mono">{row.pn || '—'}</td><td className="ltr td-d"><Notes value={row.d || ''} /></td><td className="mono">{fmtQ(row.exp || 0)}</td><td><span className={`rchip ${row.r ? 'r-' + row.r : ''}`}>{s(row.r || 'unchecked')}</span></td></tr>)}</tbody>
                </table>
            </div>
            {rows.length > limit ? <button type="button" className="more-btn" onClick={onMore}>{s('more')}</button> : null}
        </>
    );
}

async function exportStocktake(stocktake, s) {
    const XLSX = await import('xlsx');
    const rows = [[s('loc'), s('pn'), s('desc'), s('expected'), s('counted'), s('result')]];
    for (const location of stocktake.locs) {
        if (!location.lines.length) rows.push([location.n, '', s('sysEmpty'), 0, location.res === 'empty' ? 0 : '', s(location.res === 'empty' ? 'emptyConfirmed' : 'unchecked')]);
        for (const line of location.lines) rows.push([location.n, line.pn, line.d, line.exp, line.c ?? '', s(line.r || 'unchecked')]);
    }
    for (const extra of stocktake.sur) rows.push([extra.loc, extra.pn, extra.d, 0, extra.c, s('surplus')]);
    const sheet = XLSX.utils.aoa_to_sheet(rows);
    const book = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(book, sheet, stocktake.id.slice(0, 31));
    XLSX.writeFile(book, `${stocktake.id}.xlsx`);
}
