import { lazy, Suspense, useEffect, useState } from 'react';
import { api, config, type DocumentInfo } from '../api';
import { formatBytes, formatDate } from '../format';
import { detectPlatform } from '../install';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

const PdfViewer = lazy(() => import('./PdfViewer'));

/** Wartet, bis der Service Worker die Seite steuert (beim allerersten Aufruf dauert das einen Moment). */
async function whenControlled(): Promise<boolean> {
  if (!('serviceWorker' in navigator)) return false;
  if (navigator.serviceWorker.controller) return true;
  return new Promise((resolve) => {
    const timer = setTimeout(() => resolve(false), 8000);
    navigator.serviceWorker.addEventListener(
      'controllerchange',
      () => {
        clearTimeout(timer);
        resolve(true);
      },
      { once: true },
    );
  });
}

/** Lädt die PDF (über den Service Worker, also aus dem Cache, sobald sie einmal geladen ist). */
async function fetchPdf(doc: DocumentInfo): Promise<Blob> {
  let res: Response;
  try {
    res = await fetch(doc.url, { credentials: 'same-origin' });
  } catch {
    throw new Error('Keine Verbindung und noch nicht offline gespeichert.');
  }
  if (!res.ok) throw new Error(`Fehler ${res.status}`);
  return res.blob();
}

/**
 * Lädt die PDF und die Anzeige im Hintergrund über den Service Worker, damit beides
 * auch ohne Netz verfügbar ist. Liefert die PDF zurück (für „Herunterladen“), oder
 * null, wenn sie nicht offline gespeichert werden konnte.
 */
async function keepOffline(doc: DocumentInfo): Promise<Blob | null> {
  if (!(await whenControlled())) return null;
  try {
    const [blob, viewer] = await Promise.all([fetchPdf(doc), import('./PdfViewer')]);
    await viewer.prefetchWorker();
    return blob;
  } catch {
    return null;
  }
}

/**
 * iPhone: über „Teilen“ in Dateien sichern; sonst normaler Download.
 * Teilen muss direkt auf den Tipp folgen, deshalb liegt die PDF möglichst schon bereit.
 */
async function download(doc: DocumentInfo, ready: Blob | undefined): Promise<void> {
  const blob = ready ?? (await fetchPdf(doc));
  const name = `${doc.title}.pdf`;
  const file = new File([blob], name, { type: 'application/pdf' });
  if (detectPlatform() === 'ios' && navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title: doc.title });
      return;
    } catch (e) {
      if ((e as Error).name === 'AbortError') return;
      // Teilen nicht erlaubt → normaler Download.
    }
  }
  const href = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = href;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 60_000);
}

function DocIcon() {
  return (
    <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
      <path
        fill="currentColor"
        d="M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM8 13v1.5h8V13H8zm0 3v1.5h8V16H8zm0-6v1.5h4V10H8z"
      />
    </svg>
  );
}

export default function Dokumente({ docKey, onToast }: { docKey?: string; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.documents);
  const [offline, setOffline] = useState<Record<string, Blob>>({});
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    for (const doc of data ?? []) {
      keepOffline(doc).then((blob) => alive && blob && setOffline((o) => ({ ...o, [doc.key]: blob })));
    }
    return () => {
      alive = false;
    };
  }, [data]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const docs = data ?? [];

  const open = docKey ? docs.find((d) => d.key === docKey) : undefined;
  if (docKey) {
    if (!open) return <Empty>Dieses Dokument gibt es nicht (mehr).</Empty>;
    return (
      <div>
        <button className="btn btn-link back" onClick={() => navigate('dokumente')}>
          ‹ Alle Dokumente
        </button>
        <h2 className="member-name">{open.title}</h2>
        <p className="muted small doc-stand">Stand: {formatDate(open.date)}</p>
        <Suspense fallback={<Loading />}>
          <PdfViewer url={open.url} title={open.title} />
        </Suspense>
      </div>
    );
  }

  async function save(doc: DocumentInfo) {
    setBusy(doc.key);
    try {
      await download(doc, offline[doc.key]);
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setBusy(null);
    }
  }

  return (
    <div>
      {config?.user?.canManageDocs && (
        <button className="btn btn-secondary btn-block manage-events" onClick={() => navigate('dokumente/verwalten')}>
          Dokumente verwalten
        </button>
      )}
      {docs.length === 0 ? (
        <Empty>Es ist noch keine Satzung hinterlegt.</Empty>
      ) : (
        <ul className="card-list">
          {docs.map((doc) => (
            <li key={doc.key} className="card doc-card">
              <div className="doc-head">
                <span className="doc-icon">
                  <DocIcon />
                </span>
                <div className="row-main">
                  <div className="row-title">{doc.title}</div>
                  <div className="row-sub">
                    Stand: {formatDate(doc.date)} · PDF, {formatBytes(doc.size)}
                  </div>
                  {offline[doc.key] && <div className="doc-offline">✓ Auch offline verfügbar</div>}
                </div>
              </div>
              <div className="button-row">
                <button className="btn" onClick={() => navigate(`dokumente/${doc.key}`)}>
                  Öffnen
                </button>
                <button className="btn btn-secondary" disabled={busy === doc.key} onClick={() => save(doc)}>
                  Herunterladen
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
