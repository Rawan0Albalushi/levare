const HEADERS = [
    ['port_number', ['port number', 'port no', 'port no.', 'port', 'رقم المنفذ', 'منفذ']],
    ['serial_number', ['serial number', 'serial no', 'serial no.', 'serial', 'الرقم التسلسلي', 'سيريال']],
    ['description', ['description', 'desc', 'الوصف']],
    ['assigned_well', ['assigned well', 'well', 'البئر المعين', 'البئر المعيّن', 'البئر']],
    ['recorded_on', ['date', 'التاريخ']],
    ['location', ['location', 'الموقع']],
    ['condition', ['condition', 'الحالة']],
    ['tested_at', ['date of test', 'test date', 'تاريخ الفحص', 'تاريخ الاختبار']],
];

const ALIASES = HEADERS
    .flatMap(([key, names]) => names.map((name) => [name, key]))
    .sort((a, b) => b[0].length - a[0].length);

export const EQUIPMENT_COLUMNS = [
    ['port_number', 'Port number', 'رقم المنفذ'],
    ['serial_number', 'Serial number', 'الرقم التسلسلي'],
    ['description', 'Description', 'الوصف'],
    ['assigned_well', 'Assigned well', 'البئر المعيّن'],
    ['recorded_on', 'Date', 'التاريخ'],
    ['location', 'Location', 'الموقع'],
    ['condition', 'Condition', 'الحالة'],
    ['tested_at', 'Date of test', 'تاريخ الفحص'],
];

function headerName(value) {
    return String(value ?? '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function matchHeader(value) {
    const name = headerName(value);
    return ALIASES.find(([alias]) => alias === name)?.[1] ?? null;
}

function pad(value) {
    return String(value).padStart(2, '0');
}

function isRealDate(iso) {
    const [year, month, day] = iso.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
}

function excelSerial(value) {
    const utc = new Date(Math.round((value - 25569) * 86400 * 1000));
    if (Number.isNaN(utc.getTime())) return null;
    const iso = `${utc.getUTCFullYear()}-${pad(utc.getUTCMonth() + 1)}-${pad(utc.getUTCDate())}`;
    return isRealDate(iso) ? iso : null;
}

export function normalizeDate(value) {
    if (value == null || value === '') return '';
    if (value instanceof Date) {
        if (Number.isNaN(value.getTime())) return null;
        const iso = `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
        return isRealDate(iso) ? iso : null;
    }
    if (typeof value === 'number') return excelSerial(value);
    const text = String(value).trim();
    if (!text) return '';
    const iso = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (iso) {
        const next = `${iso[1]}-${pad(iso[2])}-${pad(iso[3])}`;
        return isRealDate(next) ? next : null;
    }
    const dmy = text.match(/^(\d{1,2})[/.\\-](\d{1,2})[/.\\-](\d{4})$/);
    if (dmy) {
        const next = `${dmy[3]}-${pad(dmy[2])}-${pad(dmy[1])}`;
        return isRealDate(next) ? next : null;
    }
    return null;
}

export function normalizeCondition(value) {
    const text = String(value ?? '').trim().toLowerCase();
    if (!text) return '';
    if (['new', 'جديد', 'جديدة', 'n'].includes(text)) return 'new';
    if (['repaired', 'repair', 'مصلح', 'مصلحة', 'r'].includes(text)) return 'repaired';
    return null;
}

function asText(value) {
    if (value == null || value instanceof Date) return '';
    if (typeof value === 'number' && Number.isFinite(value)) {
        return Number.isInteger(value) ? String(value) : String(value);
    }
    return String(value).trim();
}

export function rowsFromSheet(matrix) {
    const limit = Math.min(matrix.length, 8);
    let header = null;
    for (let index = 0; index < limit; index += 1) {
        const mapped = (matrix[index] || []).map(matchHeader);
        if (mapped.includes('port_number')) {
            header = { index, mapped };
            break;
        }
    }
    if (!header) return { records: [], errors: [{ row: 1, code: 'headers' }] };
    if (matrix.length - header.index - 1 > 5000) return { records: [], errors: [{ row: 1, code: 'tooMany' }] };

    const records = [];
    const errors = [];
    for (let index = header.index + 1; index < matrix.length; index += 1) {
        const cells = matrix[index] || [];
        const draft = {};
        header.mapped.forEach((key, column) => {
            if (key && draft[key] == null) draft[key] = cells[column];
        });
        const textFields = ['port_number', 'serial_number', 'description', 'assigned_well', 'location'];
        if (textFields.every((key) => asText(draft[key]) === '') && normalizeDate(draft.recorded_on) === '' && normalizeDate(draft.tested_at) === '' && String(draft.condition ?? '').trim() === '') {
            continue;
        }
        const row = index + 1;
        const recorded = normalizeDate(draft.recorded_on);
        const tested = normalizeDate(draft.tested_at);
        const condition = normalizeCondition(draft.condition);
        if (recorded === null) errors.push({ row, code: 'date' });
        if (tested === null) errors.push({ row, code: 'tested' });
        if (condition === null) errors.push({ row, code: 'condition' });
        if (recorded === null || tested === null || condition === null) continue;
        records.push({
            port_number: asText(draft.port_number),
            serial_number: asText(draft.serial_number),
            description: asText(draft.description),
            assigned_well: asText(draft.assigned_well),
            recorded_on: recorded || null,
            location: asText(draft.location),
            condition: condition || null,
            tested_at: tested || null,
        });
    }

    return { records, errors };
}
