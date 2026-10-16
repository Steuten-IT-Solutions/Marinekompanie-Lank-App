import { useCallback, useEffect, useState } from 'react';
import { config } from './api';
import logoUrl from './assets/logo.png';
import { caps } from './format';
import { navigate, useRoute } from './router';
import Galerie from './views/Galerie';
import Konto from './views/Konto';
import Meins from './views/Meins';
import News from './views/News';
import Spiess from './views/Spiess';
import Termine from './views/Termine';
import TermineVerwalten from './views/TermineVerwalten';

type Tab = 'termine' | 'news' | 'galerie' | 'meins' | 'spiess';
type View = Tab | 'konto';

const TITLES: Record<View, string> = {
  termine: 'Termine',
  news: 'Neuigkeiten',
  galerie: 'Galerie',
  meins: 'Meins',
  spiess: 'Spieß',
  konto: 'Mein Konto',
};

const TAB_LABELS: Record<Tab, string> = {
  termine: 'Termine',
  news: 'News',
  galerie: 'Galerie',
  meins: 'Meins',
  spiess: 'Spieß',
};

/** Alte Links (#punkte, #strafen) aus Version 0.1 weiter unterstützen. */
const ALIASES: Record<string, string[]> = {
  punkte: ['meins', 'punkte'],
  strafen: ['meins', 'strafen'],
};

function Icon({ name }: { name: Tab }) {
  const paths: Record<Tab, string> = {
    termine: 'M7 2v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm-2 7h14v10H5V9z',
    news: 'M4 4h13v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2V4zm15 4h1a1 1 0 0 1 1 1v9a2 2 0 0 1-4 0V8h2zM7 7v4h7V7H7zm0 6v1.5h7V13H7zm0 3v1.5h7V16H7z',
    galerie: 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 11.5V17h14v-2.5l-4-4-3.5 3.5-2-2L5 16.5zM8.5 7a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5z',
    meins: 'M12 2l2.9 6.3 6.9.7-5.2 4.6 1.5 6.8L12 17l-6.1 3.4 1.5-6.8L2.2 9l6.9-.7L12 2z',
    spiess: 'M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm0 5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zm-4 9.5c.8-1.6 2.3-2.5 4-2.5s3.2.9 4 2.5',
  };
  return (
    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
      <path d={paths[name]} fill="currentColor" />
    </svg>
  );
}

function isStandalone(): boolean {
  return (
    window.matchMedia?.('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  );
}

function InstallHint() {
  const key = 'mkl-install-hint-dismissed';
  const [hidden, setHidden] = useState(() => {
    try {
      return isStandalone() || localStorage.getItem(key) === '1';
    } catch {
      return isStandalone();
    }
  });
  if (hidden) return null;
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const dismiss = () => {
    try {
      localStorage.setItem(key, '1');
    } catch {
      /* egal */
    }
    setHidden(true);
  };
  return (
    <div className="install-hint" role="note">
      <span>
        <strong>Als App installieren:</strong>{' '}
      {ios ? (
        <>In Safari unten auf „Teilen“ <span aria-hidden="true">⬆︎</span> tippen und „Zum Home-Bildschirm“ wählen.</>
      ) : (
        <>Im Browser-Menü <span aria-hidden="true">⋮</span> „App installieren“ bzw. „Zum Startbildschirm hinzufügen“ wählen.</>
      )}
      </span>
      <button className="btn btn-link" onClick={dismiss} aria-label="Hinweis schließen">
        ✕
      </button>
    </div>
  );
}

async function logout() {
  try {
    if ('caches' in window) {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
    }
  } finally {
    window.location.href = config!.logoutUrl;
  }
}

export default function App() {
  const rawPath = useRoute();
  const path = ALIASES[rawPath[0]] ?? rawPath;
  const user = config?.user ?? null;
  const isMember = !!user?.isMember;
  const isSpiess = !!user?.isSpiess;
  const allowed: Record<View, boolean> = {
    termine: true,
    news: true,
    galerie: true,
    meins: isMember,
    spiess: isSpiess,
    konto: !!user,
  };
  const requested = (path[0] as View) || 'termine';
  const tab: View = requested in TITLES && allowed[requested] ? requested : 'termine';

  const [toast, setToast] = useState<string | null>(null);
  const [online, setOnline] = useState(navigator.onLine);
  const showToast = useCallback((msg: string) => setToast(msg), []);

  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(null), 3500);
    return () => clearTimeout(t);
  }, [toast]);

  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => {
      window.removeEventListener('online', on);
      window.removeEventListener('offline', off);
    };
  }, []);

  if (!config) {
    return (
      <main className="content">
        <p className="error">Die App muss über die Webseite unter /app/ geöffnet werden.</p>
      </main>
    );
  }

  const tabs = (['termine', 'news', 'galerie', 'meins', 'spiess'] as Tab[]).filter((t) => allowed[t]);
  const albumId = tab === 'galerie' && /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;
  const newsId = tab === 'news' && /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;

  return (
    <div className="app">
      <header className="topbar">
        <img src={logoUrl} alt="" className="logo" />
        <div className="topbar-text">
          <div className="topbar-title">{caps(TITLES[tab])}</div>
          <div className="topbar-sub">{user ? `Ahoi, ${user.name}` : 'Marinekompanie 1973 Lank'}</div>
        </div>
        {user ? (
          <button
            className={`btn btn-link topbar-account ${tab === 'konto' ? 'active' : ''}`}
            onClick={() => navigate('konto')}
            aria-current={tab === 'konto' ? 'page' : undefined}
          >
            Konto
          </button>
        ) : (
          <a className="btn btn-link topbar-account" href={config.loginUrl}>
            Anmelden
          </a>
        )}
      </header>

      {!online && <div className="offline">Offline – angezeigt werden die zuletzt geladenen Daten.</div>}
      <InstallHint />

      <main className="content">
        {tab === 'termine' &&
          (path[1] === 'verwalten' && user?.canManageEvents ? <TermineVerwalten onToast={showToast} /> : <Termine />)}
        {tab === 'news' && <News id={newsId} />}
        {tab === 'galerie' && <Galerie albumId={albumId} />}
        {tab === 'meins' && <Meins sub={path[1]} />}
        {tab === 'spiess' && <Spiess path={path.slice(1)} onToast={showToast} />}
        {tab === 'konto' && <Konto onToast={showToast} onLogout={logout} />}
      </main>

      {toast && (
        <div className="toast" role="status" aria-live="polite">
          {toast}
        </div>
      )}

      <nav className="tabbar" aria-label="Hauptnavigation">
        {tabs.map((t) => (
          <button
            key={t}
            className={tab === t ? 'active' : ''}
            aria-label={TAB_LABELS[t]}
            onClick={() => navigate(t)}
            aria-current={tab === t ? 'page' : undefined}
          >
            <Icon name={t} />
            <span>{caps(TAB_LABELS[t])}</span>
          </button>
        ))}
      </nav>
    </div>
  );
}
