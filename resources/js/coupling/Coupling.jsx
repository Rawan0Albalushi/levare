import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { api } from '../api';
import { DownloadIcon, UploadIcon } from '../icons';

const ENDS = ['pump', 'vapro', 'gs', 'intake', 'seal', 'motor'];

let nextKey = 1;

function piece(end = 'intake') {
    nextKey += 1;

    return { key: nextKey, end, pump: '' };
}

function needsModel(end) {
    return end === 'pump' || end === 'vapro';
}

function fill(template, values) {
    return template.replace(/\{(\w+)\}/g, (_, key) => values[key] ?? '');
}

export function CouplingPage({ c }) {
    const [table, setTable] = useState(null);
    const [loadError, setLoadError] = useState(false);
    const [mode, setMode] = useState('single');
    const [fromEnd, setFromEnd] = useState('pump');
    const [fromPump, setFromPump] = useState('');
    const [toEnd, setToEnd] = useState('gs');
    const [toPump, setToPump] = useState('');
    const [pieces, setPieces] = useState(() => [{ key: 1, end: 'pump', pump: '' }, piece('intake')]);
    const [series, setSeries] = useState('');
    const [seriesTouched, setSeriesTouched] = useState(false);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [suggestError, setSuggestError] = useState(false);
    const [importing, setImporting] = useState(false);
    const [importMessage, setImportMessage] = useState(null);
    const fileRef = useRef(null);

    useEffect(() => {
        let active = true;
        api('/api/couplings')
            .then((payload) => {
                if (!active) return;
                setTable(payload);
                setLoadError(false);
            })
            .catch(() => {
                if (active) setLoadError(true);
            });

        return () => { active = false; };
    }, []);

    const pumps = table?.pumps ?? [];
    const pumpByName = useMemo(() => new Map(pumps.map((pump) => [pump.name, pump])), [pumps]);
    const leadingName = mode === 'single'
        ? (needsModel(fromEnd) ? fromPump : toPump)
        : pieces.find((item) => needsModel(item.end) && item.pump)?.pump;
    const leadingPump = pumpByName.get(leadingName || '');
    const activeSeries = seriesTouched ? series : (leadingPump?.series || series);

    useEffect(() => {
        if (!table) return undefined;
        const body = mode === 'single'
            ? singleBody(fromEnd, fromPump, toEnd, toPump, activeSeries)
            : chainBody(pieces, activeSeries);
        if (!body) {
            setResult(null);
            setLoading(false);
            setSuggestError(false);

            return undefined;
        }

        let active = true;
        setResult(null);
        setLoading(true);
        const timer = setTimeout(() => {
            const url = mode === 'single' ? '/api/couplings/suggestions' : '/api/couplings/strings';
            api(url, { method: 'POST', body: JSON.stringify(body) })
                .then((payload) => {
                    if (!active) return;
                    setResult(payload);
                    setSuggestError(false);
                })
                .catch(() => {
                    if (active) setSuggestError(true);
                })
                .finally(() => {
                    if (active) setLoading(false);
                });
        }, 200);

        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [table, mode, fromEnd, fromPump, toEnd, toPump, pieces, activeSeries]);

    async function importFile(file) {
        setImporting(true);
        setImportMessage(null);
        const body = new FormData();
        body.append('file', file);

        try {
            const payload = await api('/api/couplings/import', { method: 'POST', body });
            const fresh = await api('/api/couplings');
            setTable(fresh);
            setLoadError(false);
            setImportMessage({ ok: true, text: fill(c('imported'), payload) });
        } catch (error) {
            setImportMessage({ ok: false, text: error.code === 'format' ? c('importFormat') : c('importErr') });
        } finally {
            setImporting(false);
        }
    }

    async function loadIncluded() {
        setImporting(true);
        setImportMessage(null);

        try {
            const payload = await api('/api/couplings/included', { method: 'POST' });
            const fresh = await api('/api/couplings');
            setTable(fresh);
            setLoadError(false);
            setImportMessage({ ok: true, text: fill(c('imported'), payload) });
        } catch (error) {
            setImportMessage({ ok: false, text: error.code === 'format' ? c('importFormat') : c('importErr') });
        } finally {
            setImporting(false);
        }
    }

    function chooseSeries(value) {
        setSeriesTouched(true);
        setSeries(value);
    }

    function chooseFromEnd(end) {
        setFromEnd(end);
        if (!needsModel(end)) setFromPump('');
    }

    function chooseToEnd(end) {
        setToEnd(end);
        if (!needsModel(end)) setToPump('');
    }

    function choosePieceEnd(key, end) {
        setPieces((current) => current.map((item) => (item.key === key ? { ...item, end, pump: needsModel(end) ? item.pump : '' } : item)));
    }

    function choosePiecePump(key, name) {
        setPieces((current) => current.map((item) => (item.key === key ? { ...item, pump: name } : item)));
    }

    const singleReady = readyEnd(fromEnd, fromPump) && readyEnd(toEnd, toPump);
    const chainReady = pieces.length >= 2 && pieces.every((item) => readyEnd(item.end, item.pump));

    return (
        <main className="wrap coupling" id="coupling">
            <div className="hero">
                <h1>{c('title')}</h1>
                <p>{c('sub')}</p>
                <div className="coupling-import">
                    <a className="btn" href="/api/couplings/template">
                        <DownloadIcon />
                        <span>{c('template')}</span>
                    </a>
                    <button type="button" className="btn" disabled={importing} onClick={() => fileRef.current?.click()}>
                        <UploadIcon />
                        <span>{importing ? c('importing') : c('importExcel')}</span>
                    </button>
                    <input ref={fileRef} type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) importFile(file); }} />
                    {table && pumps.length === 0 ? (
                        <button type="button" className="btn" disabled={importing} onClick={loadIncluded}>{c('importIncluded')}</button>
                    ) : null}
                    {importMessage ? <p className={importMessage.ok ? 'coupling-note' : 'coupling-warn'}>{importMessage.text}</p> : null}
                    <p className="coupling-note">{c('templateHint')}</p>
                </div>
            </div>
            {loadError ? <p className="empty-st">{c('loadErr')}</p> : null}
            {table && pumps.length === 0 ? <p className="empty-st">{c('empty')}</p> : null}
            {table && pumps.length > 0 ? (
                <>
                    <div className="seg coupling-mode" role="group" aria-label={c('title')}>
                        <button type="button" className={mode === 'single' ? 'on' : ''} aria-pressed={mode === 'single'} onClick={() => setMode('single')}>{c('single')}</button>
                        <button type="button" className={mode === 'chain' ? 'on' : ''} aria-pressed={mode === 'chain'} onClick={() => setMode('chain')}>{c('chain')}</button>
                    </div>
                    <SeriesField series={activeSeries} c={c} onChoose={chooseSeries} />
                    {mode === 'single' ? (
                        <div className="coupling-pair">
                            <EndField label={c('from')} end={fromEnd} pump={fromPump} pumps={pumps} c={c} onEnd={chooseFromEnd} onPump={setFromPump} />
                            <EndField label={c('to')} end={toEnd} pump={toPump} pumps={pumps} c={c} onEnd={chooseToEnd} onPump={setToPump} />
                        </div>
                    ) : (
                        <div className="coupling-chain">
                            {pieces.map((item, index) => (
                                <div key={item.key}>
                                    <PieceField
                                        item={item}
                                        pumps={pumps}
                                        c={c}
                                        canRemove={pieces.length > 2}
                                        onEnd={(end) => choosePieceEnd(item.key, end)}
                                        onPump={(name) => choosePiecePump(item.key, name)}
                                        onRemove={() => setPieces((current) => current.filter((row) => row.key !== item.key))}
                                    />
                                    {index < pieces.length - 1 ? <Joint index={index} result={chainReady ? result : null} loading={loading} c={c} /> : null}
                                </div>
                            ))}
                            <div className="coupling-add">
                                {ENDS.map((end) => (
                                    <button type="button" key={end} className="btn" onClick={() => setPieces((current) => [...current, piece(end)])}>{c('add')} {c(end)}</button>
                                ))}
                            </div>
                            {!chainReady ? <p className="coupling-note">{pieces.length < 2 ? c('needTwo') : c('pickModel')}</p> : null}
                        </div>
                    )}
                    {mode === 'single' ? (
                        <section className="coupling-result" aria-live="polite">
                            {suggestError ? <p className="empty-st">{c('suggestErr')}</p> : null}
                            {!singleReady ? <p className="coupling-note">{c('pickModel')}</p> : null}
                            {singleReady && loading ? <p className="coupling-note">{c('searching')}</p> : null}
                            {singleReady && !loading && result?.matches ? (
                                <MatchList
                                    matches={result.matches}
                                    c={c}
                                    hint={fromEnd === 'pump' && toEnd === 'seal' ? c('pumpSeal') : c('none')}
                                />
                            ) : null}
                        </section>
                    ) : null}
                    {mode === 'chain' && suggestError ? <p className="empty-st">{c('suggestErr')}</p> : null}
                </>
            ) : null}
        </main>
    );
}

function singleBody(fromEnd, fromPump, toEnd, toPump, series) {
    if (!readyEnd(fromEnd, fromPump) || !readyEnd(toEnd, toPump)) return null;
    if (!series && !needsModel(fromEnd) && !needsModel(toEnd)) return null;

    return {
        series: series || null,
        from: { end: fromEnd, pump: needsModel(fromEnd) ? fromPump : null },
        to: { end: toEnd, pump: needsModel(toEnd) ? toPump : null },
    };
}

function chainBody(pieces, series) {
    if (pieces.length < 2 || pieces.some((item) => !readyEnd(item.end, item.pump))) return null;
    if (!series && !pieces.some((item) => needsModel(item.end) && item.pump)) return null;

    return {
        series: series || null,
        components: pieces.map((item) => ({
            end: item.end,
            pump: needsModel(item.end) ? item.pump : null,
        })),
    };
}

function readyEnd(end, pump) {
    return !needsModel(end) || pump !== '';
}

function SeriesField({ series, c, onChoose }) {
    return (
        <div className="coupling-series">
            <span className="flabel" id="coupling-series">{c('series')}</span>
            <div className="seg" role="group" aria-labelledby="coupling-series">
                {['400', '538'].map((value) => (
                    <button type="button" key={value} className={series === value ? 'on' : ''} aria-pressed={series === value} onClick={() => onChoose(value)}>{value}</button>
                ))}
            </div>
            <p>{c('seriesHint')}</p>
        </div>
    );
}

function EndField({ label, end, pump, pumps, c, onEnd, onPump }) {
    const selected = pumps.find((item) => item.name === pump);

    return (
        <fieldset className="coupling-end">
            <legend>{label}</legend>
            <EndChoices end={end} c={c} onEnd={onEnd} />
            {needsModel(end) ? <ModelField key={end} end={end} pump={pump} pumps={pumps} c={c} onPump={onPump} /> : null}
            {selected ? <p className="coupling-shaft">{c('shaft')} <b className="mono">{selected.shaft}</b> · {c('series')} <b className="mono">{selected.series}</b></p> : null}
        </fieldset>
    );
}

function PieceField({ item, pumps, c, canRemove, onEnd, onPump, onRemove }) {
    const selected = pumps.find((pump) => pump.name === item.pump);

    return (
        <fieldset className="coupling-end">
            <legend>{c(item.end)}</legend>
            <EndChoices end={item.end} c={c} onEnd={onEnd} />
            {needsModel(item.end) ? <ModelField key={item.end} end={item.end} pump={item.pump} pumps={pumps} c={c} onPump={onPump} /> : null}
            {selected ? <p className="coupling-shaft">{c('shaft')} <b className="mono">{selected.shaft}</b></p> : null}
            {canRemove ? <button type="button" className="btn sm" onClick={onRemove}>{c('remove')}</button> : null}
        </fieldset>
    );
}

function EndChoices({ end, c, onEnd }) {
    return (
        <div className="coupling-ends">
            {ENDS.map((value) => (
                <button type="button" key={value} className={end === value ? 'on' : ''} aria-pressed={end === value} onClick={() => onEnd(value)}>{c(value)}</button>
            ))}
        </div>
    );
}

function ModelField({ end, pump, pumps, c, onPump }) {
    const fieldId = useId();
    const [query, setQuery] = useState('');
    const models = pumps.filter((item) => item.kind === end && (query.trim() === '' || item.name.toLowerCase().includes(query.trim().toLowerCase())));

    return (
        <div className="coupling-model">
            <label className="flabel" id={`${fieldId}-label`} htmlFor={fieldId}>{c('findModel')}</label>
            <input id={fieldId} value={query} onChange={(event) => setQuery(event.target.value)} placeholder={c('findModel')} autoComplete="off" aria-controls={`${fieldId}-list`} />
            <div className="coupling-models" id={`${fieldId}-list`} role="listbox" aria-labelledby={`${fieldId}-label`}>
                {models.length === 0 ? <p>{c('noModel')}</p> : models.map((item) => (
                    <button type="button" role="option" aria-selected={item.name === pump} key={item.name} className={item.name === pump ? 'on' : ''} onClick={() => onPump(item.name)}>
                        <span>{item.name}</span>
                        <b className="mono">{item.shaft}</b>
                    </button>
                ))}
            </div>
        </div>
    );
}

function Joint({ index, result, loading, c }) {
    const joint = result?.joints?.[index];

    return (
        <div className="coupling-joint" aria-live="polite">
            <span>{c('between')}</span>
            {loading && !joint ? <em>{c('searching')}</em> : null}
            {joint ? <MatchList matches={joint.matches} c={c} hint={c('none')} compact /> : null}
        </div>
    );
}

function MatchList({ matches, c, hint, compact = false }) {
    if (matches.length === 0) return <p className={compact ? 'coupling-note' : 'empty-st'}>{hint}</p>;

    return matches.map((match) => <Match key={`${match.part_number}-${match.shaft}`} match={match} c={c} compact={compact} />);
}

function Match({ match, c, compact }) {
    const current = match.numbers.find((number) => number.role === 'current');
    const extras = match.numbers.filter((number) => number.role !== 'current');

    return (
        <article className={`coupling-match ${compact ? 'compact' : ''}`}>
            <p className="coupling-kicker">{c('suggested')}</p>
            <p className="coupling-pn mono">{match.part_number}</p>
            <p className="coupling-desc">{match.description}</p>
            <p className="coupling-shaft">{c('shaft')} <b className="mono">{match.shaft}</b></p>
            {match.description_shaft ? <p className="coupling-warn">{fill(c('shaftCell'), { cell: match.shaft, described: match.description_shaft })}</p> : null}
            {match.used_only_row ? <p className="coupling-warn">{c('onlyRow')}</p> : null}
            {current ? <CatalogNumber number={current} c={c} /> : null}
            {extras.length > 0 ? (
                <ul className="coupling-extras">
                    {extras.map((number) => (
                        <li key={`${number.role}-${number.part_number}`}>
                            <span>{number.role === 'old' ? c('oldPn') : c('otherPn')}</span>
                            <b className="mono">{number.part_number}</b>
                            <CatalogNumber number={number} c={c} />
                        </li>
                    ))}
                </ul>
            ) : null}
        </article>
    );
}

function CatalogNumber({ number, c }) {
    if (!number.found) return <p className="coupling-miss">{c('missingCatalog')}</p>;

    return number.parts.map((part) => (
        <div className="coupling-stock" key={part.id}>
            <p>{c('qty')} <b className="mono">{part.quantity}</b>{part.item_code ? <span className="mono"> {part.item_code}</span> : null}</p>
            {part.locations.length === 0 ? <p>{c('noLoc')}</p> : (
                <ul>
                    {part.locations.map((row) => (
                        <li key={`${row.location}-${row.quantity}`}><span className="mono">{row.location}</span> <b className="mono">{row.quantity}</b></li>
                    ))}
                </ul>
            )}
        </div>
    ));
}
