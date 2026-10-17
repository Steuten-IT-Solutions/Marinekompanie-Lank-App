import { useRef, useState, type FormEvent } from 'react';
import { api, type DocumentInfo } from '../api';
import { formatBytes, formatDate, todayYmd } from '../format';
import { navigate } from '../router';
import { ErrorBox, Loading, useAsync } from '../ui';

/** Höchstgröße wie im Plugin (MKL_Documents::MAX_BYTES). */
const MAX_BYTES = 20 * 1024 * 1024;

/** Admins pflegen hier die Satzung: neue Fassung hochladen, Stand ändern, entfernen. */
export default function DokumenteVerwalten({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.documents);
  const fileInput = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [date, setDate] = useState(todayYmd());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const current: DocumentInfo | undefined = (data ?? []).find((d) => d.key === 'satzung');

  async function upload(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!file) return setFormError('Bitte eine PDF-Datei auswählen.');
    if (file.type && file.type !== 'application/pdf') return setFormError('Bitte eine PDF-Datei auswählen.');
    if (file.size > MAX_BYTES) return setFormError('Die PDF darf höchstens 20 MB groß sein.');
    if (!date) return setFormError('Bitte den Stand angeben.');
    setBusy(true);
    try {
      await api.uploadDocument('satzung', file, date);
      onToast(current ? 'Neue Fassung der Satzung gespeichert' : 'Satzung gespeichert');
      setFile(null);
      if (fileInput.current) fileInput.current.value = '';
      reload();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function changeDate() {
    if (!current) return;
    const next = window.prompt('Neuer Stand (TT.MM.JJJJ)', formatDate(current.date));
    if (next === null) return;
    const m = next.trim().match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if (!m) return onToast('Bitte das Datum als TT.MM.JJJJ angeben.');
    const ymd = `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
    try {
      await api.updateDocument('satzung', ymd);
      onToast('Stand geändert');
      reload();
    } catch (err) {
      onToast((err as Error).message);
    }
  }

  async function remove() {
    if (!current || !window.confirm('Satzung wirklich entfernen? Mitglieder sehen sie dann nicht mehr.')) return;
    try {
      await api.deleteDocument('satzung');
      onToast('Satzung entfernt');
      reload();
    } catch (err) {
      onToast((err as Error).message);
    }
  }

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('dokumente')}>
        ‹ Zu den Dokumenten
      </button>

      <h3 className="section-title">Satzung</h3>
      {current ? (
        <div className="card row">
          <div className="row-main">
            <div className="row-title">Aktuelle Fassung</div>
            <div className="row-sub">
              Stand: {formatDate(current.date)} · {formatBytes(current.size)}
            </div>
            <div className="row-actions">
              <button className="btn btn-small btn-secondary" onClick={changeDate}>
                Stand ändern
              </button>
              <button className="btn btn-small btn-danger" onClick={remove}>
                Entfernen
              </button>
            </div>
          </div>
        </div>
      ) : (
        <p className="muted">Es ist noch keine Satzung hinterlegt.</p>
      )}

      <form className="form card" onSubmit={upload}>
        <h3>{current ? 'Neue Fassung hochladen' : 'Satzung hochladen'}</h3>
        <label className="field">
          <span>PDF-Datei</span>
          <input
            ref={fileInput}
            type="file"
            accept="application/pdf,.pdf"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
        </label>
        <label className="field">
          <span>Stand</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
        </label>
        {current && <p className="muted small">Die neue Fassung ersetzt die bisherige. Ältere Fassungen werden nicht aufbewahrt.</p>}
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}
        <button className="btn btn-block" type="submit" disabled={busy}>
          {busy ? 'Lädt hoch …' : 'Hochladen'}
        </button>
      </form>
    </div>
  );
}
