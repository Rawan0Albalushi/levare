function Svg({ children, className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {children}
        </svg>
    );
}

export function PartsIcon() {
    return <Svg><path d="M12 3 21 8v8l-9 5-9-5V8zM3 8l9 5 9-5M12 13v8" /></Svg>;
}
export function StockIcon() {
    return <Svg><path d="M9 4h6v3H9zM8 5H5v16h14V5h-3M8 12l2 2 4-4M8 17h8" /></Svg>;
}
export function SearchIcon() {
    return <Svg className="ico"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.6-3.6" /></Svg>;
}
export function PinIcon() {
    return <Svg><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z" /><circle cx="12" cy="9.5" r="2.5" /></Svg>;
}
export function CardsIcon() {
    return <Svg><rect x="3" y="3" width="7.5" height="7.5" rx="2" /><rect x="13.5" y="3" width="7.5" height="7.5" rx="2" /><rect x="3" y="13.5" width="7.5" height="7.5" rx="2" /><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2" /></Svg>;
}
export function TableIcon() {
    return <Svg><path d="M4 6h16M4 12h16M4 18h16" /></Svg>;
}
export function BackIcon() {
    return <Svg><path d="M19 12H5M11 6l-6 6 6 6" /></Svg>;
}
export function ChevronIcon() {
    return <Svg><path d="m9 6 6 6-6 6" /></Svg>;
}
export function ClockIcon() {
    return <Svg><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></Svg>;
}
export function MenuIcon() {
    return <Svg><path d="M4 7h16M4 12h16M4 17h16" /></Svg>;
}

const part = (body) => (
    <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <g dangerouslySetInnerHTML={{ __html: body }} />
    </svg>
);

const PARTS = {
    sleeve: '<ellipse cx="32" cy="18" rx="16" ry="7"/><ellipse cx="32" cy="18" rx="8" ry="3.4"/><path d="M16 18v26c0 3.9 7.2 7 16 7s16-3.1 16-7V18"/>',
    ring: '<ellipse cx="32" cy="30" rx="22" ry="10"/><ellipse cx="32" cy="30" rx="10" ry="4.4"/><path d="M10 30v5c0 5.5 9.8 10 22 10s22-4.5 22-10v-5"/>',
    shaft: '<path d="M8 40 48 14"/><path d="M14 47 54 21"/>',
    housing: '<path d="M8 24h44M8 40h44"/><ellipse cx="52" cy="32" rx="4" ry="8"/><ellipse cx="8" cy="32" rx="4" ry="8"/>',
    bearing: '<circle cx="32" cy="32" r="22"/><circle cx="32" cy="32" r="10"/>',
    impeller: '<circle cx="32" cy="32" r="22"/><circle cx="32" cy="32" r="6"/><path d="M32 26c4-8 12-10 18-8M38 32c8 4 10 12 8 18M32 38c-4 8-12 10-18 8M26 32c-8-4-10-12-8-18"/>',
    flange: '<ellipse cx="32" cy="20" rx="22" ry="8"/><path d="M10 20v6c0 4.4 9.8 8 22 8s22-3.6 22-8v-6"/><path d="M20 32v14c0 3.3 5.4 6 12 6s12-2.7 12-6V32"/>',
    bolt: '<path d="M22 10h20l5 8-5 8H22l-5-8z"/><path d="M26 26v26h12V26"/>',
    coil: '<rect x="10" y="16" width="44" height="32" rx="6"/><path d="M18 16v32M26 16v32M34 16v32M42 16v32"/>',
    plug: '<rect x="18" y="22" width="26" height="22" rx="4"/><path d="M24 22v-9M38 22v-9"/>',
    seal: '<path d="M14 12h36M14 52h36"/><path d="M18 12c-4 4 4 6 0 10s4 6 0 10M46 12c4 4-4 6 0 10s-4 6 0 10"/><path d="M32 8v48"/>',
    tool: '<path d="M40 8a10 10 0 0 0-9 14L10 43a5 5 0 0 0 7 7l21-21a10 10 0 0 0 14-9l-6 6-6-2-2-6z"/>',
    box: '<path d="M32 8 54 19v26L32 56 10 45V19z"/><path d="M10 19l22 11 22-11M32 30v26"/>',
    all: '<rect x="9" y="9" width="20" height="20" rx="5"/><rect x="35" y="9" width="20" height="20" rx="5"/><rect x="9" y="35" width="20" height="20" rx="5"/><rect x="35" y="35" width="20" height="20" rx="5"/>',
    crit: '<path d="M32 8l7 15 16 2-12 11 3 16-14-8-14 8 3-16L9 25l16-2z"/>',
};

const ICON_BY_WORD = [
    [/^(sleeve|bushing|bush|spacer|collar|adapter sleeve)/, 'sleeve'],
    [/^(washer|shim|ring|o-ring|oring|gasket|snap|retaining|retainer|circlip)/, 'ring'],
    [/^shaft/, 'shaft'], [/^(housing|tube|pipe|body)/, 'housing'],
    [/^(bearing|thrust)/, 'bearing'], [/^(impeller|diffuser|stage|inducer)/, 'impeller'],
    [/^(head|base|crossover|сrossover|coupling|flange|adapter|cap|plate|cover|discharge|intake)/, 'flange'],
    [/^(bolt|screw|pin|nut|stud|key|rivet|plug, |set screw)/, 'bolt'],
    [/^(rotor|stator|motor)/, 'coil'], [/^(wye|connector|cable|lead|wire|fuse|pothead|terminal|plug|block)/, 'plug'],
    [/^(seal|mechanical|bag|bellow|elastomer|protector|labyrinth)/, 'seal'],
    [/^(wrench|tool|tape|spanner|clamp|gauge|socket)/, 'tool'],
];

export function PartGlyph({ part: item }) {
    const key = ICON_BY_WORD.find(([pattern]) => pattern.test(item?.dl || ''))?.[1] || 'box';
    return part(PARTS[key]);
}

export function CategoryGlyph({ name }) {
    const key = { all: 'all', crit: 'crit', pump: 'impeller', seal: 'seal', motor: 'coil', shaft: 'shaft', other: 'box' }[name] || 'box';
    return part(PARTS[key]);
}
