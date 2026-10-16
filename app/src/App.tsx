import { useCallback, useEffect, useState } from 'react';
import { config } from './api';
import logoUrl from './assets/logo.png';
import { navigate, useRoute } from './router';
import Punkte from './views/Punkte';
import Spiess from './views/Spiess';
import Strafen from './views/Strafen';
import Termine from './views/Termine';

type Tab = 'termine' | 'punkte' | 'strafen' | 'spiess';

const TITLES: Record<Tab, string> = {
  termine: 'Termine',
  punkte: 'Meine Punkte',
  strafen: 'Meine Strafen',
  spiess: 'Spieß',
};

function Icon({ name }: { name: Tab }) {
  const paths: Record<Tab, string> = {
    termine: 'M7 2v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm-2 7h14v10H5V9z',
    punkte: 'M12 2l2.9 6.3 6.9.7-5.2 4.6 1.5 6.8L12 17l-6.1 3.4 1.5-6.8L2.2 9l6.9-.7L12 2z',
    strafen: 'M3 6h18v12H3V6zm2 2v8h14V8H5zm7 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z',
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
  const path = useRoute();
  const isSpiess = !!config?.user.isSpiess;
  const requested = (path[0] as Tab) || 'termine';
  const tab: Tab = requested in TITLES && (requested !== 'spiess' || isSpiess) ? requested : 'termine';

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

  const tabs: Tab[] = isSpiess ? ['termine', 'punkte', 'strafen', 'spiess'] : ['termine', 'punkte', 'strafen'];

  return (
    <div className="app">
      <header className="topbar">
        <img src={logoUrl} alt="" className="logo" />
        <div className="topbar-text">
          <div className="topbar-title">{TITLES[tab]}</div>
          <div className="topbar-sub">Ahoi, {config.user.name}</div>
        </div>
        <button className="btn btn-link topbar-logout" onClick={logout}>
          Abmelden
        </button>
      </header>

      {!online && <div className="offline">Offline – angezeigt werden die zuletzt geladenen Daten.</div>}
      <InstallHint />

      <main className="content">
        {tab === 'termine' && <Termine />}
        {tab === 'punkte' && <Punkte />}
        {tab === 'strafen' && <Strafen />}
        {tab === 'spiess' && <Spiess path={path.slice(1)} onToast={showToast} />}
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
            onClick={() => navigate(t)}
            aria-current={tab === t ? 'page' : undefined}
          >
            <Icon name={t} />
            <span>{t === 'spiess' ? 'Spieß' : TITLES[t].replace('Meine ', '')}</span>
          </button>
        ))}
      </nav>
    </div>
  );
}
