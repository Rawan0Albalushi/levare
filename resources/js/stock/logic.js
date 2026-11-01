const DAY = 86400000;

export const dateKey = (value = new Date()) => {
    const date = new Date(value);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

export const fromKey = (key) => {
    const [year, month, day] = key.split('-').map(Number);
    return new Date(year, month - 1, day);
};

export const hours = (ms) => {
    const minutes = Math.max(0, Math.floor(ms / 60000));
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
};

export const clock = (ms) => {
    const total = Math.max(0, Math.floor(ms / 1000));
    return [Math.floor(total / 3600), Math.floor(total / 60) % 60, total % 60].map((n) => String(n).padStart(2, '0')).join(':');
};

export function counts(stocktake) {
    const result = { total: stocktake.locs.length, done: 0, emptyOk: 0, found: 0, match: 0, mismatch: 0, missing: 0, unchecked: 0, surplus: stocktake.sur.length, today: 0 };
    const today = dateKey();
    for (const location of stocktake.locs) {
        if (location.done) {
            result.done += 1;
            if (dateKey(location.at) === today) result.today += 1;
        }
        if (location.res === 'empty') result.emptyOk += 1;
        if (location.res === 'found') result.found += 1;
        for (const line of location.lines) result[line.r || 'unchecked'] += 1;
    }
    result.left = result.total - result.done;
    result.pct = result.total ? Math.round((result.done * 100) / result.total) : 0;
    return result;
}

export function plan(stocktake, summary) {
    const start = fromKey(stocktake.start);
    const today = fromKey(dateKey());
    const day = Math.floor((today - start) / DAY) + 1;
    const end = new Date(start.getTime() + (stocktake.days - 1) * DAY);
    const left = Math.max(1, stocktake.days - Math.max(day, 1) + 1);
    return { day, end, left, before: day < 1, over: day > stocktake.days && summary.left > 0, target: Math.ceil(summary.left / left) };
}

export const currentShift = (stocktake) => stocktake.shifts.find((shift) => shift.id === stocktake.open && !shift.end) || null;

export const employeeLabel = (shift) => (shift.nm ? `${shift.emp} · ${shift.nm}` : shift.emp);

export function shiftStat(stocktake, shift) {
    let locations = 0;
    let lines = 0;
    for (const location of stocktake.locs) {
        if (location.done && location.sh === shift.id) locations += 1;
        for (const line of location.lines) if (line.sh === shift.id) lines += 1;
    }
    return { locs: locations, lines, ms: (shift.end ? new Date(shift.end) : new Date()) - new Date(shift.start) };
}

export function finishLocation(stocktake, location) {
    const shift = currentShift(stocktake);
    location.done = true;
    location.at = new Date().toISOString();
    location.sh = shift ? shift.id : null;
    location.by = shift ? employeeLabel(shift) : null;
    if (!location.res) location.res = location.e ? 'empty' : 'counted';
    if (stocktake.locs.every((item) => item.done)) {
        if (shift) shift.end = new Date().toISOString();
        stocktake.open = null;
        stocktake.status = 'done';
        stocktake.finished = new Date().toISOString();
    }
}

export function reopenLocation(stocktake, location) {
    location.done = false;
    location.at = location.by = location.sh = null;
    if (location.res !== 'found') location.res = null;
    if (stocktake.status === 'done') {
        stocktake.status = 'prog';
        stocktake.finished = null;
    }
}

export const countedValue = (line) => (line.r === 'match' ? line.exp : line.r === 'missing' ? 0 : line.r === 'mismatch' ? line.c : null);

export function newId() {
    return `STK-${Math.random().toString(36).slice(2, 10).toUpperCase()}`;
}
