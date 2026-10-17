import { lazy, Suspense, useEffect, useState } from 'react';
import { api, config, type DocCategory, type DocumentInfo } from '../api';
import { formatBytes, formatDate } from '../format';
import { detectPlatform } from '../install';
import { whenControlled } from '../offline';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

const PdfViewer = lazy(() => import('./PdfViewer'));

/** Dateitypen für die Auswahl beim Hochladen (prüft auch der Server). */
export const ACCEPT = '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp';

const TYPE_LABELS: Record<string, string> = {
  pdf: 'PDF',
  jpg: 'Bild',
  jpeg: 'Bild',
  png: 'Bild',
  doc: 'Word',
  docx: 'Word',
  odt: 'Text',
  xls: 'Excel',
  xlsx: 'Excel',
  ods: 'Tabelle',
  ppt: 'PowerPoint',
  pptx: 'PowerPoint',
  odp: 'Präsentation',
};

export const typeLabel = (doc: DocumentInfo) => TYPE_LABELS[doc.ext] ?? doc.ext.toUpperCase();

/** Gruppiert nach Kategorie (in deren Reihenfolge); Dokumente ohne Kategorie zuletzt. */
export function groupDocuments(docs: DocumentInfo[], categories: DocCategory[]) {
  const groups: { id: number | null; title: string; docs: DocumentInfo[] }[] = [];
  for (const c of categories) {
    const inCat = docs.filter((d) => d.category_id === c.id);
    if (inCat.length > 0) groups.push({ id: c.id, title: c.title, docs: inCat });
  }
  const known = new Set(categories.map((c) => c.id));
  const rest = docs.filter((d) => d.category_id === null || !known.has(d.category_id));
  if (rest.length > 0) groups.push({ id: null, title: groups.length > 0 ? 'Weitere Dokumente' : '', docs: rest });
  return groups;
}

export function DocIcon({ kind }: { kind: DocumentInfo['kind'] }) {
  const paths = {
    pdf: 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM8 13v1.5h8V13H8zm0 3v1.5h8V16H8zm0-6v1.5h4V10H8z',
    bild: 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 11.5V17h14v-2.5l-4-4-3.5 3.5-2-2L5 16.5zM8.5 7a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5z',
    datei: 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM11 11v4.2l-1.6-1.6-1.1 1.1L12 18.4l3.7-3.7-1.1-1.1-1.6 1.6V11h-2z',
  };
  return (
    <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
      <path fill="currentColor" d={paths[kind]} />
    </svg>
  );
}

/** Lädt die Datei über den Service Worker – danach liegt sie offline im Cache. */
async function fetchFile(doc: DocumentInfo): Promise<Blob> {
  await whenControlled();
  let res: Response;
  try {
    res = await fetch(doc.url, { credentials: 'same-origin' });
  } catch {
    throw new Error('Keine Verbindung und noch nicht offline gespeichert.');
  }
  if (!res.ok) throw new Error(res.status === 404 ? 'Das Dokument wurde nicht gefunden.' : `Fehler ${res.status}`);
  return res.blob();
}

/** Liegt die Datei schon im Cache des Service Workers (also offline verfügbar)? */
async function isCached(doc: DocumentInfo): Promise<boolean> {
  try {
    return 'caches' in window && !!(await caches.match(doc.url));
  } catch {
    return false;
  }
}

/** iPhone: über „Teilen“ in Dateien sichern; sonst normaler Download. */
async function download(doc: DocumentInfo): Promise<void> {
  const blob = await fetchFile(doc);
  const name = `${doc.title.replace(/[\\/:*?"<>|]+/g, ' ').trim() || 'Dokument'}.${doc.ext}`;
  const file = new File([blob], name, { type: doc.mime });
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

/** Bild in der App anzeigen (über fetch, damit es offline verfügbar wird). */
function ImageView({ doc }: { doc: DocumentInfo }) {
  const [src, setSrc] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let url: string | null = null;
    let alive = true;
    fetchFile(doc)
      .then((blob) => {
        url = URL.createObjectURL(blob);
        if (alive) setSrc(url);
      })
      .catch((e: Error) => alive && setError(e.message));
    return () => {
      alive = false;
      if (url) URL.revokeObjectURL(url);
    };
  }, [doc]);
  if (error) return <p className="error">{error}</p>;
  if (!src) return <Loading />;
  return <img className="pdf-page" src={src} alt={doc.title} />;
}

export default function Dokumente({ docId, onToast }: { docId?: number; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.documents);
  const [offline, setOffline] = useState<Record<number, boolean>>({});
  const [busy, setBusy] = useState<number | null>(null);

  useEffect(() => {
    let alive = true;
    for (const doc of data?.documents ?? []) {
      isCached(doc).then((ok) => alive && ok && setOffline((o) => ({ ...o, [doc.id]: true })));
    }
    return () => {
      alive = false;
    };
    // Auch nach dem Zurückkehren aus der Anzeige: Geöffnetes liegt jetzt im Cache.
  }, [data, docId]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const docs = data?.documents ?? [];

  if (docId) {
    const open = docs.find((d) => d.id === docId);
    if (!open) return <Empty>Dieses Dokument gibt es nicht (mehr).</Empty>;
    return (
      <div>
        <button className="btn btn-link back" onClick={() => navigate('dokumente')}>
          ‹ Alle Dokumente
        </button>
        <h2 className="member-name">{open.title}</h2>
        <p className="muted small doc-stand">Stand: {formatDate(open.date)}</p>
        {open.kind === 'pdf' ? (
          <Suspense fallback={<Loading />}>
            <PdfViewer url={open.url} title={open.title} />
          </Suspense>
        ) : open.kind === 'bild' ? (
          <ImageView doc={open} />
        ) : (
          <Empty>Dieses Dokument lässt sich nur herunterladen.</Empty>
        )}
      </div>
    );
  }

  async function save(doc: DocumentInfo) {
    setBusy(doc.id);
    try {
      await download(doc);
      if (await isCached(doc)) setOffline((o) => ({ ...o, [doc.id]: true }));
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setBusy(null);
    }
  }

  const groups = groupDocuments(docs, data?.categories ?? []);

  return (
    <div>
      {config?.user?.canManageDocs && (
        <button className="btn btn-secondary btn-block manage-events" onClick={() => navigate('dokumente/verwalten')}>
          Dokumente verwalten
        </button>
      )}
      {docs.length === 0 && <Empty>Es sind noch keine Dokumente hinterlegt.</Empty>}
      {groups.map((g) => (
        <section key={g.id ?? 'ohne'} className="doc-group">
          {g.title && <h3 className="section-title">{g.title}</h3>}
          <ul className="card-list">
            {g.docs.map((doc) => (
              <li key={doc.id} className="card doc-card">
                <div className="doc-head">
                  <span className={`doc-icon doc-icon-${doc.kind}`}>
                    <DocIcon kind={doc.kind} />
                  </span>
                  <div className="row-main">
                    <div className="row-title">
                      {doc.title}
                      {doc.visibility === 'spiess' && <span className="badge badge-level-spiess">Nur Spieße</span>}
                    </div>
                    <div className="row-sub">
                      Stand: {formatDate(doc.date)} · {typeLabel(doc)}, {formatBytes(doc.size)}
                    </div>
                    {offline[doc.id] && <div className="doc-offline">✓ Auch offline verfügbar</div>}
                  </div>
                </div>
                <div className={doc.kind === 'datei' ? 'button-row single' : 'button-row'}>
                  {doc.kind !== 'datei' && (
                    <button className="btn" onClick={() => navigate(`dokumente/${doc.id}`)}>
                      Öffnen
                    </button>
                  )}
                  <button className="btn btn-secondary" disabled={busy === doc.id} onClick={() => save(doc)}>
                    Herunterladen
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
