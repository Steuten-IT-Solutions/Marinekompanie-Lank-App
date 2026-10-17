import { useState, type FormEvent } from 'react';
import { api, type MemberDetail } from '../api';
import { BackToSetup, Empty, ErrorBox, Loading, TitleBadges, useAsync } from '../ui';

/* ------------------------------------------------------------------ */
/* Titelliste pflegen                                                  */
/* ------------------------------------------------------------------ */

export function TitleList({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.titles);
  const [title, setTitle] = useState('');
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    try {
      await action();
      onToast(msg);
      reload();
      return true;
    } catch (e) {
      onToast((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function add(e: FormEvent) {
    e.preventDefault();
    if (!title.trim()) {
      onToast('Bitte eine Bezeichnung angeben.');
      return;
    }
    if (await run(() => api.createTitle(title.trim()), 'Titel angelegt')) {
      setTitle('');
    }
  }

  function rename(id: number, current: string) {
    const next = window.prompt('Neue Bezeichnung', current);
    if (next === null) return;
    if (!next.trim()) return onToast('Bitte eine Bezeichnung angeben.');
    run(() => api.renameTitle(id, next.trim()), 'Gespeichert');
  }

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  return (
    <div>
      <BackToSetup />
      <form className="form card" onSubmit={add}>
        <label className="field">
          <span>Neuer Titel</span>
          <input type="text" maxLength={191} placeholder="z. B. Ehrenmitglied" value={title} onChange={(e) => setTitle(e.target.value)} />
        </label>
        <button className="btn btn-block" type="submit" disabled={busy}>
          Hinzufügen
        </button>
      </form>
      <p className="muted small">
        Titel wie Kapitän oder Ehrenmitglied werden zusätzlich zum Dienstgrad angezeigt und hängen nicht von Punkten ab. Vergeben
        werden sie in den Mitgliedsdetails.
      </p>
      <ul className="card-list">
        {(data ?? []).map((t) => (
          <li key={t.id} className="card row">
            <div className="row-main">
              <div className="row-title">{t.title}</div>
              <div className="row-sub">{t.member_count === 1 ? '1 Mitglied' : `${t.member_count} Mitglieder`}</div>
              <div className="row-actions">
                <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => rename(t.id, t.title)}>
                  Umbenennen
                </button>
                {t.member_count === 0 && (
                  <button
                    className="btn btn-small btn-danger"
                    disabled={busy}
                    onClick={() => window.confirm(`Titel „${t.title}“ löschen?`) && run(() => api.deleteTitle(t.id), 'Titel gelöscht')}
                  >
                    Löschen
                  </button>
                )}
              </div>
            </div>
          </li>
        ))}
        {(data ?? []).length === 0 && <Empty>Noch keine Titel. Lege oben den ersten an.</Empty>}
      </ul>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Titel eines Mitglieds (Mitgliedsdetails)                            */
/* ------------------------------------------------------------------ */

export function TitleControl({
  member,
  onChanged,
  onToast,
}: {
  member: MemberDetail;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const titles = useAsync(api.titles);
  const [editing, setEditing] = useState(false);
  const [chosen, setChosen] = useState<number[]>([]);
  const [busy, setBusy] = useState(false);
  const all = titles.data ?? [];

  function start() {
    setChosen(member.titles.map((t) => t.id));
    setEditing(true);
  }

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const { titles: saved } = await api.setTitles(member.id, chosen);
      onToast(saved.length === 0 ? 'Titel entfernt' : `Titel gespeichert: ${saved.map((t) => t.title).join(', ')}`);
      setEditing(false);
      onChanged();
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (titles.loading && !titles.data) return <Loading />;
  if (titles.error) return <ErrorBox message={titles.error} onRetry={titles.reload} />;
  if (all.length === 0 && member.titles.length === 0) return null;

  return (
    <div className="card title-control">
      <div className="rank-label">Titel</div>
      {member.titles.length > 0 ? <TitleBadges titles={member.titles} /> : <div className="muted">Keine Titel</div>}
      {editing ? (
        <form className="form rank-form" onSubmit={save}>
          <ul className="picker-list title-picker">
            {all.map((t) => (
              <li key={t.id}>
                <label>
                  <input
                    type="checkbox"
                    checked={chosen.includes(t.id)}
                    onChange={() => setChosen((c) => (c.includes(t.id) ? c.filter((x) => x !== t.id) : [...c, t.id]))}
                  />
                  {t.title}
                </label>
              </li>
            ))}
          </ul>
          <div className="button-row">
            <button className="btn" type="submit" disabled={busy}>
              Speichern
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setEditing(false)}>
              Abbrechen
            </button>
          </div>
        </form>
      ) : (
        <button className="btn btn-secondary btn-block title-edit" onClick={start}>
          Titel ändern
        </button>
      )}
    </div>
  );
}
