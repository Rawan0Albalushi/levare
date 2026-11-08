const SERIES = /\b(272|319|338|362|375|400|406|418|456|513|538|540|562|675|725|862)\b/;
const CAT_ORDER = { pump: 0, seal: 1, motor: 2, shaft: 3, other: 4 };
const AR_WORDS = {
    'مضخة': 'pump', 'مضخه': 'pump', 'سيل': 'seal', 'موتور': 'motor', 'محرك': 'motor', 'شافت': 'shaft', 'عمود': 'shaft',
    'هاوسنج': 'housing', 'هوسنج': 'housing', 'جلبة': 'sleeve', 'جلبه': 'sleeve', 'سليف': 'sleeve', 'سبيسر': 'spacer',
    'بيرنج': 'bearing', 'بيرنق': 'bearing', 'رولمان': 'bearing', 'امبلر': 'impeller', 'ديفيوزر': 'diffuser',
    'مسمار': 'bolt', 'برغي': 'screw', 'واشر': 'washer', 'روتر': 'rotor', 'ستاتر': 'stator', 'هيد': 'head', 'بيس': 'base',
    'كابل': 'cable', 'شريط': 'tape',
};
const CAT_WORD = {
    pump: 'pump', pumps: 'pump', seal: 'seal', seals: 'seal', motor: 'motor', motors: 'motor', shaft: 'shaft',
    housing: 'shaft', other: 'other', obsolete: 'other', 'متقادمة': 'other', 'متقادم': 'other', critical: 'crit',
    'مهمة': 'crit', 'المهمة': 'crit',
};

export const fmtQ = (n) => (Math.round(Number(n) * 100) / 100).toLocaleString('en-US');

export function splitNotes(value) {
    const notes = [];
    const source = String(value || '');
    const main = source.replace(/\(([^()]*)\)?/g, (_match, note) => {
        if (note && note.trim()) notes.push(note.trim());
        return ' ';
    }).replace(/\s+/g, ' ').trim();

    return { main: main || source, notes };
}

function cap(value) {
    return value ? value[0].toUpperCase() + value.slice(1) : value;
}

function prepare(part, index) {
    const next = { ...part, k: index, cats: part.cats || [], L: (part.L || []).filter((row) => row[0] || row[1]) };
    next.dl = String(next.d || '').toLowerCase().replace(/^с/, 'c');
    next.q = next.L.reduce((sum, row) => sum + (+row[1] || 0), 0);
    next.cat = next.cats[0] || 'other';
    next.dk = String(next.d || '').replace(/\s+/g, ' ').trim();
    const head = next.dl.split(',')[0].replace(/\bs\/a\b/g, '').trim();
    next.series = (String(next.d || '').match(SERIES) || [''])[0];
    next.fam = [head, next.series].join('|');
    next.famLabel = cap(head) + (next.series ? ' · ' + next.series : '');
    next.hay = [next.id, next.pn, next.dl, next.L.map((row) => row[0] + ' ' + (row[5] || '')).join(' '), next.us, next.t, next.no, next.an].join(' ').toLowerCase();
    return next;
}

export function prepareCatalog(raw) {
    const parts = raw.map(prepare);
    const byDescription = new Map();
    for (const part of parts) {
        if (!byDescription.has(part.dk)) byDescription.set(part.dk, []);
        byDescription.get(part.dk).push(part);
    }
    for (const group of byDescription.values()) {
        group.sort((a, b) => b.q - a.q);
        for (const part of group) part.sib = group;
    }
    return { parts };
}

export const inCat = (part, category) => category === 'all'
    || (category === 'crit' ? part.cats.length > 0 : category === 'other' ? !part.cats.length : part.cats.includes(category));

export function tokens(query) {
    return query.toLowerCase().split(/\s+/).filter(Boolean).map((word) => AR_WORDS[word] || word);
}

function matches(part, words) {
    if (words.length === 1 && CAT_WORD[words[0]]) return inCat(part, CAT_WORD[words[0]]);
    return words.every((word) => part.hay.includes(word) || (CAT_WORD[word] && inCat(part, CAT_WORD[word])));
}

const stockOk = (part, stock) => stock === 'all' || (stock === 'in' ? part.q > 0 : part.q <= 0);
const order = (a, b) => (CAT_ORDER[a.cat] - CAT_ORDER[b.cat]) || a.fam.localeCompare(b.fam) || String(a.id || a.pn).localeCompare(String(b.id || b.pn), 'en', { numeric: true });

export function searchParts(parts, state) {
    const words = tokens(state.q);
    const list = parts.filter((part) => inCat(part, state.cat) && stockOk(part, state.stock) && matches(part, words));
    if (words.length) {
        const query = state.q.trim().toLowerCase();
        const relevance = (part) => (part.id.toLowerCase() === query || part.pn.toLowerCase() === query ? 0 : part.id.startsWith(query) || part.pn.startsWith(query) ? 1 : 2);
        list.sort((a, b) => relevance(a) - relevance(b) || order(a, b));
    } else {
        list.sort(order);
    }
    return list;
}

export function categoryCounts(parts, state) {
    const words = tokens(state.q);
    const counts = { all: 0, crit: 0, pump: 0, seal: 0, motor: 0, shaft: 0, other: 0 };
    for (const part of parts) {
        if (!stockOk(part, state.stock) || !matches(part, words)) continue;
        counts.all += 1;
        if (part.cats.length) {
            counts.crit += 1;
            for (const category of part.cats) counts[category] += 1;
        } else {
            counts.other += 1;
        }
    }
    return counts;
}

export function catalogStats(parts) {
    const locations = new Set();
    let inStock = 0;
    for (const part of parts) {
        if (part.q > 0) inStock += 1;
        for (const row of part.L) if (row[0]) locations.add(String(row[0]).toUpperCase());
    }
    return { items: parts.length, inStock, out: parts.length - inStock, locations: locations.size };
}

export function uniqDesc(list) {
    const seen = new Set();
    return list.filter((part) => !seen.has(part.sib) && seen.add(part.sib));
}

export function groupsOf(list, limit) {
    const groups = [];
    const index = new Map();
    const total = new Map();
    for (const part of list) {
        const key = part.cat + '|' + part.fam;
        total.set(key, (total.get(key) || 0) + 1);
    }
    for (const part of list.slice(0, limit)) {
        const key = part.cat + '|' + part.fam;
        if (!index.has(key)) {
            index.set(key, groups.length);
            groups.push({ label: part.famLabel, cat: part.cat, n: total.get(key), items: [] });
        }
        groups[index.get(key)].items.push(part);
    }
    return groups;
}

export function locsOf(group) {
    const merged = new Map();
    for (const part of group) {
        for (const row of part.L) {
            const key = (row[0] || '—') + '\u0000' + part.k;
            if (!merged.has(key)) merged.set(key, { loc: row[0] || '—', part, q: 0 });
            merged.get(key).q += +row[1] || 0;
        }
    }
    return [...merged.values()].sort((a, b) => (b.q > 0) - (a.q > 0) || b.q - a.q);
}

export function altList(part) {
    return (part.sib || []).filter((item) => item !== part && item.q > 0).map((item) => ({ q: item, why: ['sameDesc'] }));
}

function lev(a, b) {
    if (Math.abs(a.length - b.length) > 2) return 9;
    const row = Array.from({ length: b.length + 1 }, (_, index) => index);
    for (let i = 1; i <= a.length; i += 1) {
        let previous = row[0];
        row[0] = i;
        for (let j = 1; j <= b.length; j += 1) {
            const current = row[j];
            row[j] = Math.min(row[j] + 1, row[j - 1] + 1, previous + (a[i - 1] === b[j - 1] ? 0 : 1));
            previous = current;
        }
    }
    return row[b.length];
}

export function nearCodes(parts, code) {
    const needle = code.toLowerCase();
    const out = [];
    for (const part of parts) {
        for (const value of [part.id, part.pn]) {
            if (!value) continue;
            const distance = lev(needle, value.toLowerCase());
            if (distance <= 2) out.push({ part, value, distance });
        }
    }
    out.sort((a, b) => a.distance - b.distance || b.part.q - a.part.q);
    const seen = new Set();
    return out.filter((item) => !seen.has(item.value) && seen.add(item.value)).slice(0, 8);
}

export function looseMatches(parts, query) {
    const words = tokens(query);
    return parts
        .map((part) => ({ part, n: words.filter((word) => part.hay.includes(word)).length }))
        .filter((item) => item.n)
        .sort((a, b) => b.n - a.n || b.part.q - a.part.q)
        .slice(0, 6);
}

export function buildLocations(parts) {
    const map = new Map();
    for (const part of parts) {
        const quantities = new Map();
        for (const row of part.L) {
            const name = String(row[0] || '').trim();
            if (name) quantities.set(name, (quantities.get(name) || 0) + (+row[1] || 0));
        }
        for (const [name, quantity] of quantities) {
            let location = map.get(name);
            if (!location) {
                location = { name, lines: [], qty: 0, cats: new Set() };
                map.set(name, location);
            }
            if (part.cats.length) part.cats.forEach((category) => location.cats.add(category));
            else location.cats.add('other');
            if (quantity > 0) {
                location.lines.push({ pn: part.pn, id: part.id, d: part.d, u: part.u, exp: Math.round(quantity * 100) / 100 });
                location.qty += quantity;
            }
        }
    }
    const locations = [...map.values()].sort((a, b) => a.name.localeCompare(b.name, 'en', { numeric: true }));
    for (const location of locations) {
        location.filled = location.lines.length > 0;
        location.hay = (location.name + ' ' + location.lines.map((line) => `${line.pn} ${line.id}`).join(' ')).toLowerCase();
    }
    return locations;
}

export const zoneOf = (location) => (String(location).trim().match(/^[A-Za-z]+\d?/) || ['#'])[0].toUpperCase();
export const sumQ = (group) => group.reduce((sum, part) => sum + part.q, 0);
export const mainCode = (part, fallback) => part.pn || part.id || fallback;
