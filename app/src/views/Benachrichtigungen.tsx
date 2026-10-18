import { useEffect, useState } from 'react';
import { api, type PushStatus, type PushSubscriptionPrefs } from '../api';
import { browserSupportsPush, currentSubscription, disablePush, enablePush, needsInstallForPush } from '../push';
import { navigate } from '../router';

/** Konto → Benachrichtigungen: Push auf diesem Gerät ein-/ausschalten und Ereignisse wählen. */
export default function Benachrichtigungen({ onToast }: { onToast: (msg: string) => void }) {
  const [status, setStatus] = useState<PushStatus | null>(null);
  const [endpoint, setEndpoint] = useState<string | null>(null);
  const [prefs, setPrefs] = useState<PushSubscriptionPrefs | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const supported = browserSupportsPush();

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const sub = supported ? await currentSubscription().catch(() => null) : null;
      const s = await api.pushStatus(sub?.endpoint).catch(() => null);
      if (cancelled || !s) return;
      setStatus(s);
      // Nur als aktiv zeigen, wenn Browser und Server das Abo kennen.
      if (sub && s.subscription) {
        setEndpoint(sub.endpoint);
        setPrefs(s.subscription);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [supported]);

  if (!status || status.types.length === 0) return null;

  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const enable = () =>
    run(async () => {
      const { sub, prefs: p } = await enablePush(status.public_key!);
      setEndpoint(sub.endpoint);
      setPrefs(p);
      onToast('Benachrichtigungen aktiviert');
    });

  const disable = () =>
    run(async () => {
      await disablePush();
      setEndpoint(null);
      setPrefs(null);
      onToast('Benachrichtigungen ausgeschaltet');
    });

  // Sofort anzeigen, bei einem Fehler auf den vorigen Stand zurück.
  const update = (next: Partial<PushSubscriptionPrefs>) => {
    const before = prefs!;
    setPrefs({ ...before, ...next });
    return run(async () => {
      try {
        setPrefs(await api.pushUpdate(endpoint!, next));
      } catch (err) {
        setPrefs(before);
        throw err;
      }
    });
  };

  const test = () =>
    run(async () => {
      await api.pushTest(endpoint!);
      onToast('Testbenachrichtigung verschickt');
    });

  function toggleType(key: string, on: boolean) {
    const types = status!.types.map((t) => t.key).filter((k) => (k === key ? on : prefs!.types.includes(k)));
    update({ types });
  }

  let body;
  if (!status.supported || !status.public_key) {
    body = <p className="muted small">Der Server kann zurzeit keine Benachrichtigungen verschicken.</p>;
  } else if (needsInstallForPush()) {
    body = (
      <>
        <p className="muted small">
          Auf dem iPhone gibt es Benachrichtigungen nur in der installierten App (ab iOS 16.4). Installiere die App, öffne
          sie über das Wappen auf dem Startbildschirm und schalte die Benachrichtigungen dort ein.
        </p>
        <button className="btn btn-secondary btn-block" onClick={() => navigate('installieren')}>
          So geht&apos;s
        </button>
      </>
    );
  } else if (!supported) {
    body = <p className="muted small">Dieser Browser unterstützt keine Benachrichtigungen.</p>;
  } else if (!prefs) {
    body = (
      <>
        <p className="muted small">Erhalte eine Nachricht aufs Handy, z. B. bei einer neuen Strafe oder neuen Punkten.</p>
        {Notification.permission === 'denied' && (
          <p className="form-error">
            Benachrichtigungen sind für diese App blockiert. Bitte in den Einstellungen des Handys bzw. Browsers erlauben.
          </p>
        )}
        <button className="btn btn-block push-enable" onClick={enable} disabled={busy}>
          {busy ? 'Einen Moment …' : 'Benachrichtigungen aktivieren'}
        </button>
      </>
    );
  } else {
    body = (
      <>
        <p className="muted small">Auf diesem Gerät benachrichtigen bei:</p>
        {status.types.map((t) => (
          <label key={t.key} className="toggle">
            <input
              type="checkbox"
              checked={prefs.types.includes(t.key)}
              disabled={busy}
              onChange={(e) => toggleType(t.key, e.target.checked)}
            />
            {t.label}
          </label>
        ))}
        {prefs.types.includes('strafen') && (
          <label className="toggle">
            <input type="checkbox" checked={prefs.details} disabled={busy} onChange={(e) => update({ details: e.target.checked })} />
            Bei Strafen Betrag und Grund anzeigen (auch auf dem Sperrbildschirm sichtbar)
          </label>
        )}
        <div className="button-row">
          <button className="btn btn-secondary" onClick={test} disabled={busy}>
            Test senden
          </button>
          <button className="btn btn-secondary" onClick={disable} disabled={busy}>
            Ausschalten
          </button>
        </div>
      </>
    );
  }

  return (
    <div className="card account-card push-card">
      <h3>Benachrichtigungen</h3>
      {body}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
