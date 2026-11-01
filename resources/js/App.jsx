import { useEffect, useMemo, useState } from 'react';
import { NavLink, Route, Routes, useNavigate } from 'react-router-dom';
import { api } from './api';
import { prepareCatalog } from './catalog/logic';
import { CatalogPage } from './catalog/ui';
import { MenuIcon, PartsIcon, StockIcon } from './icons';
import { stockText, text } from './i18n';
import { StocktakePage } from './stock/Stocktake';

export default function App() {
    const [lang, setLang] = useState(() => localStorage.getItem('levare-lang') || 'ar');
    const [catalog, setCatalog] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [menu, setMenu] = useState(false);
    const navigate = useNavigate();
    const t = (key) => text[lang][key];
    const s = (key) => stockText[lang][key];

    useEffect(() => {
        document.documentElement.lang = lang;
        document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
        localStorage.setItem('levare-lang', lang);
    }, [lang]);

    useEffect(() => {
        document.body.classList.toggle('side-on', menu);
    }, [menu]);

    useEffect(() => {
        let active = true;
        api('/api/catalog')
            .then((payload) => { if (active) setCatalog(payload); })
            .catch(() => { if (active) setError(lang === 'ar' ? 'تعذر تحميل القطع من الخادم.' : 'The parts catalog could not be loaded.'); });
        return () => { active = false; };
    }, []);

    const prepared = useMemo(() => (catalog ? prepareCatalog(catalog.parts) : null), [catalog]);
    const source = catalog ? [catalog.source?.store, catalog.source?.critical].filter(Boolean).join(' · ') : '';

    return (
        <>
            <aside className={`sidebar ${menu ? 'on' : ''}`}>
                <a className="logo" href="/" aria-label="Levare" onClick={(event) => { event.preventDefault(); setMenu(false); navigate('/'); }}>
                    <img src="/logo.svg" alt="Levare" />
                </a>
                <nav className="snav">
                    <NavLink to="/" end className={({ isActive }) => (isActive ? 'on' : '')} onClick={() => setMenu(false)}>
                        <PartsIcon /><span>{t('navParts')}</span>
                    </NavLink>
                    <NavLink to="/stock" className={({ isActive }) => (isActive ? 'on' : '')} onClick={() => setMenu(false)}>
                        <StockIcon /><span>{t('navStock')}</span>
                    </NavLink>
                </nav>
                <div className="sfoot">
                    <button type="button" className="lang" onClick={() => setLang(lang === 'ar' ? 'en' : 'ar')}>{lang === 'ar' ? 'English' : 'عربي'}</button>
                    <div className="sub">{t('storeName')}</div>
                </div>
            </aside>
            {menu ? <div className="side-bg" onClick={() => setMenu(false)} /> : null}
            <header className="top">
                <div className="wrap">
                    <button type="button" className="burger" aria-label="menu" onClick={() => setMenu(true)}><MenuIcon /></button>
                    <b className="tsub">{t('storeName')}</b>
                </div>
            </header>
            {notice ? <div className="wrap"><div className="notice miss"><p>{notice}</p></div></div> : null}
            {!prepared && !error ? <div className="boot"><strong>Levare</strong><span>{lang === 'ar' ? 'جاري تحميل المخزون…' : 'Loading the store…'}</span></div> : null}
            {error ? <div className="boot"><strong>Levare</strong><span>{error}</span></div> : null}
            {prepared ? (
                <Routes>
                    <Route path="/" element={<CatalogPage parts={prepared.parts} byPn={prepared.byPn} lang={lang} t={t} whyLabels={text[lang].why} onNotice={setNotice} />} />
                    <Route path="/stock/*" element={<StocktakePage parts={prepared.parts} lang={lang} t={t} s={s} onNotice={setNotice} />} />
                </Routes>
            ) : null}
            {source ? <p className="wrap src">{t('dataFrom')}: {source}</p> : null}
        </>
    );
}
