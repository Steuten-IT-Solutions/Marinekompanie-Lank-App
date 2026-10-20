import { useState, type FormEvent } from 'react';
import { api, config } from '../api';
import logoUrl from '../assets/logo.png';
import { checkMaintenance, MAX_MAINTENANCE_REASON, useMaintenance } from '../maintenance';
import { BackToSetup, ErrorBox, Loading, useAsync } from '../ui';

const CONFIRM_ON = 'Wartungsmodus einschalten? Alle außer Admins können die App dann nicht mehr nutzen.';

/** Begründung als reiner Text; Zeilenumbrüche bleiben (CSS white-space: pre-line). */
function Reason({ text }: { text: string }) {
  if (!text) return null;
  return <p className="maintenance-reason">{text}</p>;
}

/** Was alle außer Admins während der Wartung sehen. */
export function MaintenancePage() {
  const { reason } = useMaintenance();
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  async function retry() {
    setBusy(true);
    setNote(null);
    const result = await checkMaintenance();
    if (result === 'off') {
      window.location.reload();
      return;
    }
    setBusy(false);
    setNote(result === 'offline' ? 'Keine Verbindung. Bitte später erneut versuchen.' : 'Die Wartung läuft noch.');
  }

  return (
    <main className="maintenance-page">
      <img src={logoUrl} alt="" className="maintenance-logo" />
      <h1>Wartung</h1>
      <p className="maintenance-text">Die App wird gerade gewartet. Bitte versuche es später noch einmal.</p>
      <Reason text={reason} />
      <button className="btn btn-block" onClick={() => void retry()} disabled={busy}>
        {busy ? 'Prüft …' : 'Erneut versuchen'}
      </button>
      {note && (
        <p className="muted small" role="status" aria-live="polite">
          {note}
        </p>
      )}
      {config && (
        <a className="btn btn-link maintenance-login" href={config.loginUrl}>
          {config.user ? 'Als Admin anmelden (anderes Konto)' : 'Als Admin anmelden'}
        </a>
      )}
    </main>
  );
}

/** Roter Hinweis für Admins auf jeder Seite, solange die Wartung läuft. */
export function MaintenanceBanner({ onToast }: { onToast: (msg: string) => void }) {
  const { on, reason } = useMaintenance();
  const [busy, setBusy] = useState(false);
  if (!on) return null;

  async function end() {
    setBusy(true);
    try {
      await api.setMaintenance(false);
      onToast('Wartungsmodus beendet');
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="maintenance-banner" role="alert">
      <div className="maintenance-banner-text">
        <strong>Wartungsmodus aktiv – nur Admins können die App nutzen</strong>
        {reason && <span className="maintenance-reason">{reason}</span>}
      </div>
      <button className="btn btn-small maintenance-banner-btn" onClick={() => void end()} disabled={busy}>
        Beenden
      </button>
    </div>
  );
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const ACTION_LABELS = { on: 'eingeschaltet', off: 'beendet', reason: 'Begründung geändert' } as const;

/** „Spieß → Einrichtung → Wartungsmodus“ (nur Admins). */
export function MaintenanceSettings({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.maintenance());
  const live = useMaintenance();
  const [reason, setReason] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? 'Fehler'} onRetry={reload} />;

  // Über den Banner beendet? Dann gilt der aktuelle Zustand.
  const on = live.on;
  const text = reason ?? (on ? live.reason : '');

  async function run(next: boolean, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      await api.setMaintenance(next, next ? text : undefined);
      setReason(null);
      reload();
      onToast(msg);
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function toggle() {
    if (on) {
      void run(false, 'Wartungsmodus beendet');
    } else if (window.confirm(CONFIRM_ON)) {
      void run(true, 'Wartungsmodus eingeschaltet');
    }
  }

  function save(e: FormEvent) {
    e.preventDefault();
    if (on) void run(true, 'Begründung gespeichert');
    else toggle();
  }

  return (
    <div>
      <BackToSetup />
      <form className="form card" onSubmit={save}>
        <h3>Wartungsmodus</h3>
        <p className="muted small">
          Solange er aktiv ist, können nur Admins die App nutzen. Alle anderen sehen eine Wartungsseite mit der Begründung.
          Offline gespeicherte Daten bleiben auf den Geräten, die Webseite bleibt normal erreichbar.
        </p>
        <label className="toggle maintenance-switch">
          <input type="checkbox" role="switch" checked={on} onChange={toggle} disabled={busy} />
          <span>Wartungsmodus</span>
        </label>
        <label className="field">
          <span>Begründung (optional)</span>
          <textarea
            rows={3}
            maxLength={MAX_MAINTENANCE_REASON}
            value={text}
            onChange={(e) => setReason(e.target.value)}
            placeholder="z. B. Umstellung auf die neue Version"
          />
        </label>
        <div className="muted small maintenance-counter" aria-live="polite">
          {text.length}/{MAX_MAINTENANCE_REASON}
        </div>
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}
        {on && (
          <button className="btn btn-block" type="submit" disabled={busy || text === live.reason}>
            Begründung speichern
          </button>
        )}
      </form>

      {data.log.length > 0 && (
        <div className="card">
          <h3>Verlauf</h3>
          <ul className="maintenance-log">
            {data.log.map((entry, i) => (
              <li key={i}>
                <div>
                  {formatTime(entry.time)} –{' '}
                  {ACTION_LABELS[entry.action] ?? entry.action}
                  {entry.by_name && <> von {entry.by_name}</>}
                </div>
                <Reason text={entry.reason} />
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
