import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { splitNotes, fmtQ, locsOf, mainCode, sumQ, altList, similarParts, searchParts, categoryCounts, catalogStats, uniqDesc, groupsOf, nearCodes, looseMatches } from './logic';
import { CategoryGlyph, PartGlyph, PinIcon, SearchIcon, CardsIcon, TableIcon, DownloadIcon, UploadIcon } from '../icons';

export function Notes({ value }) {
    const { main, notes } = splitNotes(value);
    return <>{main}{notes.map((note) => <span className="side" key={note}>{note}</span>)}</>;
}

function Qty({ part, outLabel }) {
    if (part.q > 0) return <span className="qty ok mono">{fmtQ(part.q)} {part.u || ''}</span>;
    return <span className="qty out">{outLabel} · 0</span>;
}

function Badges({ part, t }) {
    return (
        <>
            {part.pr ? <span className={`pr ${part.pr}`}>{part.pr}</span> : null}
            {part.w ? <span className="pr warn" title={t('belowMin')}>{t('belowMinShort')}</span> : null}
        </>
    );
}

function Locations({ group, max = 3, t }) {
    const rows = locsOf(group);
    const many = group.length > 1;
    if (!rows.length) return <div className="loclist empty"><PinIcon />{t('noLocShort')}</div>;
    return (
        <div className="loclist">
            {rows.slice(0, max).map((row) => (
                <div className={`locrow ${row.q > 0 ? '' : 'zero'}`} key={`${row.loc}-${row.part.k}`}>
                    <span className="lname"><PinIcon /><span className="mono"><Notes value={row.loc} /></span></span>
                    <span className="lq mono">{many ? <span className="pntag">{mainCode(row.part, t('noCode'))}</span> : null}{fmtQ(row.q)}</span>
                </div>
            ))}
            {rows.length > max ? <div className="lmore">+ {rows.length - max}</div> : null}
        </div>
    );
}

function PartCard({ part, t, lang, onOpen }) {
    const group = part.sib;
    return (
        <div className={`card ${part.q > 0 ? 'in' : 'out'}`} role="button" tabIndex={0} onClick={() => onOpen(part)} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); onOpen(part); } }}>
            <div className="row1">
                <div className="thumb"><PartGlyph part={part} /></div>
                <div className="ids">
                    <div className="lbl">{t(part.pn ? 'partNo' : 'itemCode')}</div>
                    <div className="id mono">{mainCode(part, t('noCode'))}</div>
                    {part.pn && part.id ? <div className="pn">{t('itemCode')}: <span className="mono">{part.id}</span></div> : null}
                    {(part.pr || part.w) ? <div className="bdg"><Badges part={part} t={t} /></div> : null}
                </div>
                <Qty part={part} outLabel={t('out')} />
            </div>
            <div className="desc"><Notes value={part.d} /></div>
            {group.length > 1 ? (
                <div className="pns">
                    <div className="pnh">{lang === 'ar' ? `نفس الوصف بالضبط — ${group.length} أرقام قطع` : `Exactly the same description — ${group.length} part numbers`}</div>
                    {group.map((item) => (
                        <div className={`pnrow ${item === part ? 'cur' : ''}`} key={item.k} onClick={(event) => { event.stopPropagation(); onOpen(item); }}>
                            <span><b className="mono">{mainCode(item, t('noCode'))}</b>{item.pn && item.id ? <small className="mono"> {t('idShort')} {item.id}</small> : null}</span>
                            <Qty part={item} outLabel={t('out')} />
                        </div>
                    ))}
                </div>
            ) : null}
            <Locations group={group} t={t} />
        </div>
    );
}

function Mini({ part, why, whyLabels, t, onOpen }) {
    return (
        <button type="button" className="card" onClick={() => onOpen(part)}>
            <div className="thumb"><PartGlyph part={part} /></div>
            <div className="ids">
                <div className="id mono">{mainCode(part, t('noCode'))}{part.pn && part.id ? <small className="mono"> {t('idShort')} {part.id}</small> : null}</div>
                <div className="desc"><Notes value={part.d} /></div>
                {why ? <div className="why">{why.map((item) => whyLabels[item] || item).join(' · ')}</div> : null}
            </div>
            <Qty part={part} outLabel={t('out')} />
        </button>
    );
}

export function CatalogPage({ parts, byPn, lang, t, whyLabels, onNotice }) {
    const [query, setQuery] = useState('');
    const [cat, setCat] = useState('all');
    const [stock, setStock] = useState('all');
    const [view, setView] = useState(() => localStorage.getItem('levare-view') || 'cards');
    const [limit, setLimit] = useState(120);
    const [open, setOpen] = useState(null);
    const [importing, setImporting] = useState(false);
    const fileRef = useRef(null);
    const state = { q: query, cat, stock };
    const list = useMemo(() => searchParts(parts, state), [parts, query, cat, stock]);
    const cards = useMemo(() => uniqDesc(list), [list]);
    const counts = useMemo(() => categoryCounts(parts, state), [parts, query, stock]);
    const stats = useMemo(() => catalogStats(parts), [parts]);
    const groups = useMemo(() => groupsOf(cards, limit), [cards, limit]);
    const exact = query.trim() && parts.find((part) => part.id.toLowerCase() === query.trim().toLowerCase() || part.pn.toLowerCase() === query.trim().toLowerCase());
    const stockShare = stats.items ? Math.round((stats.inStock / stats.items) * 100) : 0;

    useEffect(() => {
        const onKey = (event) => {
            if (event.key === 'Escape') setOpen(null);
            if (event.key === '/' && document.activeElement?.id !== 'q') {
                event.preventDefault();
                document.getElementById('q')?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    async function importFiles(fileList) {
        const body = new FormData();
        [...fileList].forEach((file) => body.append('files[]', file));
        setImporting(true);
        try {
            const response = await fetch('/api/catalog/import', { method: 'POST', body, headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || t('importErr'));
            onNotice(`${t('importOk')}: ${payload.imported}`);
            window.location.reload();
        } catch (error) {
            onNotice(error.message || t('importErr'));
            setImporting(false);
        }
    }

    async function exportExcel() {
        const XLSX = await import('xlsx');
        const rows = [['Item Code', 'Part Number', 'Description', 'UOM', 'Location', 'Quantity', 'Condition', 'Last Update', 'Status', 'Reference', 'Type', 'Section', 'Priority']];
        for (const part of list) {
            const base = [part.id, part.pn, part.d, part.u];
            const tail = [part.t || '', (part.cats || []).join(' / '), part.pr || ''];
            if (!part.L.length) rows.push([...base, '', '', '', '', '', '', ...tail]);
            for (const row of part.L) rows.push([...base, row[0], row[1], row[4] || '', row[3] || '', row[5] || '', row[6] || '', ...tail]);
        }
        const sheet = XLSX.utils.aoa_to_sheet(rows);
        const book = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(book, sheet, 'Parts');
        XLSX.writeFile(book, `Levare-parts.xlsx`);
    }

    return (
        <main className="wrap" id="catalog">
            <section className="hero">
                <h1>{t('title')}</h1>
                <p>{t('subtitle')}</p>
                <div className={`search ${query ? 'has' : ''}`}>
                    <SearchIcon />
                    <input id="q" type="search" autoComplete="off" spellCheck="false" placeholder={t('placeholder')} value={query} onChange={(event) => { setQuery(event.target.value); setLimit(120); }} />
                    <button type="button" className="clear" aria-label="clear" onClick={() => setQuery('')}>×</button>
                </div>
            </section>

            <section className="deck" aria-label={t('deckLabel')}>
                <div className="stats">
                    {[
                        [stats.items, 'statParts', 'items'],
                        [stats.inStock, 'statStock', 'stock'],
                        [stats.out, 'statOut', 'depleted'],
                        [stats.locations, 'statLocs', 'places'],
                    ].map(([value, key, tone]) => (
                        <div className={`stat ${tone}`} key={key}>
                            <b className="mono">{value.toLocaleString('en-US')}</b>
                            <span className="sl">{t(key)}</span>
                        </div>
                    ))}
                </div>
                <div className="meter">
                    <div
                        className="track"
                        role="img"
                        aria-label={`${stats.inStock.toLocaleString('en-US')} ${t('statStock')}, ${stats.out.toLocaleString('en-US')} ${t('statOut')}`}
                    >
                        <i style={{ width: `${stockShare}%` }} />
                    </div>
                    <span className="cap"><b className="mono">{stockShare}%</b> {t('statStock')}</span>
                </div>

                <nav className="cats" aria-label={t('category')}>
                    {Object.entries(counts).map(([key, count]) => (
                        <button
                            type="button"
                            className={`cat ${cat === key ? 'on' : ''}`}
                            key={key}
                            aria-pressed={cat === key}
                            onClick={() => { setCat(key); setLimit(120); }}
                        >
                            <span className="cat-ico"><CategoryGlyph name={key} /></span>
                            <span className="cat-txt">
                                <span className="n">{t(key)}</span>
                                <span className="c mono">{count.toLocaleString('en-US')}</span>
                            </span>
                        </button>
                    ))}
                </nav>

                <div className="toolbar">
                    <div className="count"><b className="mono">{list.length.toLocaleString('en-US')}</b> <span>{t('results')}</span></div>
                    <div className="tools">
                        <div className="seg stock" role="group" aria-label={t('stockState')}>
                            {[['all', 'stockAll'], ['in', 'stockIn'], ['out', 'stockOut']].map(([key, label]) => (
                                <button type="button" key={key} className={stock === key ? 'on' : ''} aria-pressed={stock === key} onClick={() => { setStock(key); setLimit(120); }}>
                                    {key !== 'all' ? <span className={`dot ${key === 'in' ? 'ok' : 'out'}`} /> : null}
                                    <span>{t(label)}</span>
                                </button>
                            ))}
                        </div>
                        <div className="seg" role="group" aria-label={t('viewMode')}>
                            <button type="button" className={view === 'cards' ? 'on' : ''} aria-pressed={view === 'cards'} onClick={() => { setView('cards'); localStorage.setItem('levare-view', 'cards'); }}><CardsIcon /><span>{t('viewCards')}</span></button>
                            <button type="button" className={view === 'table' ? 'on' : ''} aria-pressed={view === 'table'} onClick={() => { setView('table'); localStorage.setItem('levare-view', 'table'); }}><TableIcon /><span>{t('viewTable')}</span></button>
                        </div>
                    </div>
                    <div className="fileacts">
                        <button type="button" className="btn sm" onClick={exportExcel} aria-label={t('exportExcel')}><DownloadIcon /><span>Excel</span></button>
                        <button type="button" className="btn sm" disabled={importing} aria-label={t('importExcel')} onClick={() => fileRef.current?.click()}><UploadIcon /><span>{importing ? t('importing') : 'Excel'}</span></button>
                        <input ref={fileRef} type="file" accept=".xlsx,.xlsb,.xls,.xlsm" multiple hidden onChange={(event) => { const files = event.target.files; event.target.value = ''; if (files?.length) importFiles(files); }} />
                    </div>
                </div>
            </section>

            <Notice query={query} exact={exact} list={list} parts={parts} byPn={byPn} t={t} lang={lang} whyLabels={whyLabels} onOpen={setOpen} onQuery={setQuery} />

            {view === 'table' ? (
                <div className="twrap">
                    <table className="parts">
                        <thead><tr><th></th><th>{t('partNo')}</th><th>{t('itemCode')}</th><th>{t('description')}</th><th>{t('qty')}</th><th>{t('locations')}</th><th>{t('priority')}</th></tr></thead>
                        <tbody>
                            {groups.map((group) => (
                                <Fragment key={`${group.cat}-${group.label}`}>
                                    <tr className="gh"><td colSpan={7}><div className="ghead"><h2>{group.label}</h2>{cat === 'all' ? <span className="k cat-k">{t(group.cat)}</span> : null}<span className="k">{group.n}</span></div></td></tr>
                                    {group.items.map((part) => (
                                        <tr className={part.q > 0 ? 'in' : 'out'} key={part.k} tabIndex={0} onClick={() => setOpen(part)} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); setOpen(part); } }}>
                                            <td><div className="thumb"><PartGlyph part={part} /></div></td>
                                            <td className="mono">{part.sib.map((item) => <div key={item.k}><b>{item.pn || '—'}</b></div>)}</td>
                                            <td className="mono">{part.sib.map((item) => <div key={item.k}>{item.id || '—'}</div>)}</td>
                                            <td className="ltr td-d"><Notes value={part.d} /></td>
                                            <td>{part.sib.map((item) => <div className="qline" key={item.k}><Qty part={item} outLabel={t('out')} /></div>)}</td>
                                            <td><Locations group={part.sib} max={4} t={t} /></td>
                                            <td><Badges part={part} t={t} /></td>
                                        </tr>
                                    ))}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : groups.map((group) => (
                <section className="group" key={`${group.cat}-${group.label}`}>
                    <div className="ghead"><h2>{group.label}</h2>{cat === 'all' ? <span className="k cat-k">{t(group.cat)}</span> : null}<span className="k">{group.n}</span><span className="ln" /></div>
                    <div className="grid">{group.items.map((part) => <PartCard key={part.k} part={part} t={t} lang={lang} onOpen={setOpen} />)}</div>
                </section>
            ))}
            {cards.length > limit ? <button type="button" className="more-btn" onClick={() => setLimit((value) => value + 120)}>{t('showMore')}</button> : null}
            {open ? <PartDrawer part={open} parts={parts} byPn={byPn} t={t} whyLabels={whyLabels} onOpen={setOpen} onClose={() => setOpen(null)} /> : null}
        </main>
    );
}

function Notice({ query, exact, list, parts, byPn, t, lang, whyLabels, onOpen, onQuery }) {
    const q = query.trim();
    if (exact && exact.q <= 0) {
        let alts = altList(exact, parts, byPn);
        const same = alts.length && alts[0].why[0] === 'sameDesc';
        if (same) alts = alts.filter((item) => item.why[0] !== 'near');
        return (
            <div className="notice out">
                <h3>{lang === 'ar' ? `القطعة ${q} نفدت من المخزن` : `${q} is out of stock`}</h3>
                <p>{same ? t('sameDescText') : alts.length ? t('outText') : t('noAlt')}</p>
                {alts.length ? <div className="mini" style={{ marginTop: 12 }}>{alts.map((item) => <Mini key={item.q.k} part={item.q} why={item.why} whyLabels={whyLabels} t={t} onOpen={onOpen} />)}</div> : null}
            </div>
        );
    }
    if (q && !list.length) {
        const looksCode = /^[\w\-./]*\d[\w\-./]*$/.test(q) && q.length >= 4;
        const near = looksCode ? nearCodes(parts, q) : [];
        const loose = looseMatches(parts, q);
        return (
            <div className="notice miss">
                <h3>{lang === 'ar' ? `ما لقينا «${q}»` : `No match for “${q}”`}</h3>
                {near.length ? <p>{t('missText')}</p> : null}
                {near.length ? <div className="near">{near.map((item) => <button type="button" className="mono" key={item.value} onClick={() => onQuery(item.value)}>{item.value}</button>)}</div> : null}
                {loose.length ? <><p style={{ marginTop: 12 }}>{t('missAlt')}</p><div className="mini" style={{ marginTop: 10 }}>{loose.map((item) => <Mini key={item.part.k} part={item.part} whyLabels={whyLabels} t={t} onOpen={onOpen} />)}</div></> : (!near.length ? <p>{t('nothing')}</p> : null)}
            </div>
        );
    }
    return null;
}

function Fact({ label, children }) {
    if (children === undefined || children === null || children === '') return null;
    return <div className="fact"><div className="l">{label}</div><div className="v">{children}</div></div>;
}

export function PartDrawer({ part, parts, byPn, t, whyLabels, onOpen, onClose }) {
    const alts = altList(part, parts, byPn);
    const similar = similarParts(part, alts);
    const group = part.sib;
    const many = group.length > 1;
    const rows = group.flatMap((item) => item.L.map((location) => [item, location]));
    const columns = [[0, 'location', true], [2, 'qty', true], [4, 'cond'], [3, 'date'], [5, 'status'], [6, 'reference']].filter(([index, , keep]) => keep || rows.some(([, location]) => location[index]));

    return (
        <>
            <div className="scrim on" onClick={onClose} />
            <aside className="drawer on" aria-hidden="false">
                <div className="dhead"><button type="button" className="x" onClick={onClose} aria-label="close">×</button></div>
                <div className="dbody">
                    <div className="dhero">
                        <div className="thumb"><PartGlyph part={part} /></div>
                        <div className="ids">
                            <div className="lbl">{t(part.pn ? 'partNo' : 'itemCode')} <Badges part={part} t={t} /></div>
                            <div className="id mono">{mainCode(part, t('noCode'))}</div>
                            {part.pn && part.id ? <div className="pn">{t('itemCode')}: <span className="mono">{part.id}</span></div> : null}
                            <div style={{ marginTop: 8 }}><Qty part={part} outLabel={t('out')} /></div>
                        </div>
                    </div>
                    <div className="ddesc"><Notes value={part.d} /></div>
                    <div className="sec">{t('locations')}</div>
                    <Locations group={group} max={99} t={t} />
                    <div className="sec">{t('secInfo')}</div>
                    <div className="facts">
                        <Fact label={t('category')}>{part.cats.length ? part.cats.map((item) => t(item)).join(' · ') : t('other')}</Fact>
                        <Fact label={t('unit')}>{part.u || '—'}</Fact>
                        <Fact label={t('qtyThis')}><span className="mono">{fmtQ(part.q)} {part.u || ''}</span></Fact>
                        {many ? <Fact label={t('qtyAll')}><span className="mono">{fmtQ(sumQ(group))} {part.u || ''}</span></Fact> : null}
                        <Fact label={t('locCount')}><span className="mono">{new Set(part.L.map((row) => row[0]).filter(Boolean)).size}</span></Fact>
                        {part.t ? <div className="fact wide"><div className="l">{t('storeType')}</div><div className="v"><span className="ltr" dir="ltr">{part.t}</span></div></div> : null}
                    </div>
                    <div className="sec">{t('locDetails')} <span className="k">{rows.length}</span></div>
                    {rows.length ? (
                        <div className="twrap">
                            <table className="loc">
                                <thead><tr>{many ? <th>{t('partNo')}</th> : null}{columns.map(([, key]) => <th key={key}>{t(key)}</th>)}</tr></thead>
                                <tbody>
                                    {rows.map(([item, location], index) => (
                                        <tr key={`${item.k}-${index}`}>
                                            {many ? <td className="mono"><b>{mainCode(item, t('noCode'))}</b></td> : null}
                                            {columns.map(([column]) => column === 0
                                                ? <td className="mono" key={column}><b><Notes value={location[0] || '—'} /></b></td>
                                                : column === 2
                                                    ? <td className={`mono ${location[1] > 0 ? '' : 'z'}`} key={column}>{location[2] || fmtQ(location[1])}</td>
                                                    : <td className="mono" key={column}>{location[column] || '—'}</td>)}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : <p style={{ color: 'var(--muted)' }}>{t('noLoc')}</p>}
                    {part.cr ? (
                        <>
                            <div className="sec">{t('secCrit')}</div>
                            <div className="facts">
                                <Fact label={t('priority')}>{part.pr ? <span className={`pr ${part.pr}`}>{part.pr}</span> : ''}</Fact>
                                <Fact label={t('min')}>{part.mn ? <span className="mono">{part.mn}</span> : ''}</Fact>
                                <Fact label={t('critStock')}>{part.cs ? <span className="mono">{part.cs}</span> : ''}</Fact>
                                <Fact label={t('reused')}>{part.ru ? <span className="mono">{part.ru}</span> : ''}</Fact>
                                <Fact label={t('single')}>{part.se || ''}</Fact>
                                <Fact label={t('plan90')}>{part.p90 ? <span className="mono">{part.p90}</span> : ''}</Fact>
                                <Fact label={t('plan6')}>{part.p6 ? <span className="mono">{part.p6}</span> : ''}</Fact>
                                <Fact label={t('backup')}>{part.bk ? <span className="mono">{part.bk}</span> : ''}</Fact>
                                {part.us ? <div className="fact wide"><div className="l">{t('usedIn')}</div><div className="v"><span className="ltr" dir="ltr">{part.us}</span></div></div> : null}
                                {part.no ? <div className="fact wide"><div className="l">{t('notes')}</div><div className="v"><span className="ltr" dir="ltr">{part.no}</span></div></div> : null}
                            </div>
                        </>
                    ) : null}
                    <div className={part.q <= 0 ? 'alt-box' : ''} style={part.q <= 0 ? { marginTop: 18 } : undefined}>
                        <div className="sec">{t('alternatives')} <span className="k">{alts.length}</span></div>
                        {alts.length ? <div className="mini">{alts.map((item) => <Mini key={item.q.k} part={item.q} why={item.why} whyLabels={whyLabels} t={t} onOpen={onOpen} />)}</div> : <p style={{ color: 'var(--muted)' }}>{t('noAlt')}</p>}
                    </div>
                    {similar.length ? <><div className="sec">{t('similar')} <span className="k">{part.famLabel}</span></div><div className="mini">{similar.map((item) => <Mini key={item.k} part={item} whyLabels={whyLabels} t={t} onOpen={onOpen} />)}</div></> : null}
                </div>
            </aside>
        </>
    );
}
