import { useState, type FormEvent } from 'react';
import { api, type MemberDetail, type Promotion, type Rank } from '../api';
import { formatDate } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, TitleBadges, useAsync } from '../ui';
import { RankHistoryList } from './Rang';

const num = (n: number) => n.toLocaleString('de-DE');

/* ------------------------------------------------------------------ */
/* Beförderungsübersicht                                               */
/* ------------------------------------------------------------------ */

/**
 * Für die Spieße: Wer ist fällig, wem fehlt wenig? Sortierung kommt vom Server:
 * fällige (seit wann, älteste zuerst), dann nach fehlenden Punkten, höchster Rang zuletzt.
 */
export function Promotions() {
  const { data, error, loading, reload } = useAsync(() => Promise.all([api.promotions(), api.ranks()]));
  const [onlyBelow, setOnlyBelow] = useState(false);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [list, ranks] = data!;

  const editButton = (
    <div className="button-row manage-events">
      <button className="btn btn-secondary" onClick={() => navigate('spiess/raenge/liste')}>
        Rangliste bearbeiten
      </button>
      <button className="btn btn-secondary" onClick={() => navigate('spiess/raenge/titel')}>
        Titel bearbeiten
      </button>
    </div>
  );
  if (ranks.length === 0) {
    return (
      <div>
        {editButton}
        <Empty>Es sind noch keine Ränge angelegt.</Empty>
      </div>
    );
  }

  const due = list.filter((p) => p.points_to_next !== null && p.points_to_next <= 0);
  const soon = list.filter((p) => p.points_to_next !== null && p.points_to_next > 0);
  const top = list.filter((p) => p.points_to_next === null);
  const below = list.filter((p) => p.below_current_threshold);

  const row = (p: Promotion) => (
    <li key={p.user_id} className="card row clickable" onClick={() => navigate(`spiess/mitglied/${p.user_id}`)}>
      <div className="row-main">
        <div className="row-title">
          {p.name} <TitleBadges titles={p.titles} />
        </div>
        <div className="row-sub">
          {p.rank ? p.rank.title : 'Kein Rang'} · {num(p.points)} Punkte
        </div>
        {p.points_to_next !== null && p.points_to_next <= 0 && p.next_rank && (
          <div className="row-sub promo-due">
            Schwelle für {p.next_rank.title} erreicht
            {p.threshold_reached_since && ` seit ${formatDate(p.threshold_reached_since)}`}
          </div>
        )}
        {p.below_current_threshold && p.rank && (
          <div className="row-sub neg">
            Unter der Schwelle von {p.rank.title} ({num(p.rank.min_points)})
          </div>
        )}
      </div>
      {p.points_to_next !== null && p.points_to_next > 0 && p.next_rank && (
        <div className="row-value promo-missing">
          noch {num(p.points_to_next)}
          <small>bis {p.next_rank.title}</small>
        </div>
      )}
      {p.points_to_next !== null && p.points_to_next <= 0 && (
        <button
          className="btn btn-small"
          onClick={(e) => {
            e.stopPropagation();
            navigate(`spiess/mitglied/${p.user_id}/befoerdern`);
          }}
        >
          Befördern
        </button>
      )}
    </li>
  );

  return (
    <div>
      {editButton}
      <label className="toggle">
        <input type="checkbox" checked={onlyBelow} onChange={(e) => setOnlyBelow(e.target.checked)} />
        Nur „unter Schwelle des aktuellen Rangs“ ({below.length})
      </label>
      {onlyBelow ? (
        <>
          <h3 className="section-title">Unter Schwelle</h3>
          {below.length === 0 ? <Empty>Niemand liegt unter der Schwelle seines Rangs.</Empty> : <ul className="card-list">{below.map(row)}</ul>}
        </>
      ) : (
        <>
          <h3 className="section-title">Beförderung fällig</h3>
          {due.length === 0 ? <Empty>Gerade ist keine Beförderung fällig.</Empty> : <ul className="card-list promo-list">{due.map(row)}</ul>}
          <h3 className="section-title">Steht bald an</h3>
          {soon.length === 0 ? <Empty>Niemand.</Empty> : <ul className="card-list promo-list">{soon.map(row)}</ul>}
          {top.length > 0 && (
            <>
              <h3 className="section-title">Höchster Rang</h3>
              <ul className="card-list promo-list">{top.map(row)}</ul>
            </>
          )}
        </>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Rangliste pflegen                                                   */
/* ------------------------------------------------------------------ */

export function RankList({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.ranks(true));
  const [title, setTitle] = useState('');
  const [minPoints, setMinPoints] = useState('');
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
    const n = Number(minPoints);
    if (!title.trim() || minPoints.trim() === '' || !Number.isInteger(n) || n < 0) {
      onToast('Bitte Bezeichnung und eine ganze Punktzahl ab 0 angeben.');
      return;
    }
    if (await run(() => api.createRank(title.trim(), n), 'Rang angelegt')) {
      setTitle('');
      setMinPoints('');
    }
  }

  function edit(r: Rank, field: 'title' | 'min_points') {
    const next = window.prompt(field === 'title' ? 'Neue Bezeichnung' : `Schwelle für „${r.title}“ (Punkte)`, String(r[field]));
    if (next === null) return;
    if (field === 'title') {
      if (!next.trim()) return onToast('Bitte eine Bezeichnung angeben.');
      run(() => api.updateRank(r.id, { title: next.trim() }), 'Gespeichert');
    } else {
      const n = Number(next);
      if (!Number.isInteger(n) || n < 0) return onToast('Bitte eine ganze Zahl ab 0 angeben.');
      run(() => api.updateRank(r.id, { min_points: n }), 'Gespeichert');
    }
  }

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('spiess/raenge')}>
        ‹ Beförderungen
      </button>
      <form className="form card" onSubmit={add}>
        <div className="field-row">
          <label className="field grow">
            <span>Neuer Rang</span>
            <input type="text" placeholder="z. B. Maat" value={title} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <label className="field narrow">
            <span>Ab Punkten</span>
            <input type="number" inputMode="numeric" min="0" step="1" value={minPoints} onChange={(e) => setMinPoints(e.target.value)} />
          </label>
        </div>
        <button className="btn btn-block" type="submit" disabled={busy}>
          Hinzufügen
        </button>
      </form>
      <p className="muted small">
        Ränge werden nicht automatisch vergeben: Erreicht ein Mitglied die Punkte, befördert es ein Spieß. Änderungen an
        den Schwellen ändern keine bereits vergebenen Ränge.
      </p>
      <ul className="card-list">
        {(data ?? []).map((r) => (
          <li key={r.id} className={`card row ${r.active ? '' : 'inactive'}`}>
            <div className="row-main">
              <div className="row-title">{r.title}</div>
              <div className="row-sub">
                {r.active ? 'aktiv' : 'deaktiviert'} · {r.member_count === 1 ? '1 Mitglied' : `${r.member_count ?? 0} Mitglieder`}
              </div>
              <div className="row-actions">
                <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => edit(r, 'title')}>
                  Umbenennen
                </button>
                <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => edit(r, 'min_points')}>
                  Schwelle ändern
                </button>
                <button
                  className="btn btn-small btn-secondary"
                  disabled={busy}
                  onClick={() => run(() => api.updateRank(r.id, { active: !r.active }), r.active ? 'Deaktiviert' : 'Aktiviert')}
                >
                  {r.active ? 'Deaktivieren' : 'Aktivieren'}
                </button>
                {!r.member_count && (
                  <button
                    className="btn btn-small btn-danger"
                    disabled={busy}
                    onClick={() => window.confirm(`Rang „${r.title}“ löschen?`) && run(() => api.deleteRank(r.id), 'Rang gelöscht')}
                  >
                    Löschen
                  </button>
                )}
              </div>
            </div>
            <div className="row-value">ab {num(r.min_points)}</div>
          </li>
        ))}
        {(data ?? []).length === 0 && <Empty>Noch keine Ränge. Lege oben den ersten an.</Empty>}
      </ul>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Rang eines Mitglieds (Mitgliedsdetails)                             */
/* ------------------------------------------------------------------ */

export function RankControl({
  member,
  startPromote,
  onChanged,
  onToast,
}: {
  member: MemberDetail;
  startPromote: boolean;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const ranks = useAsync(api.ranks);
  const st = member.rank_status;
  const current = st.rank;
  const all = ranks.data ?? [];
  const promoteOptions = all.filter((r) => (!current || r.min_points > current.min_points) && r.min_points <= member.points);
  const demoteOptions = current ? all.filter((r) => r.min_points < current.min_points) : [];
  const canPromote = promoteOptions.length > 0;
  const canDemote = !!current && st.below_current_threshold;

  const [chosenMode, setMode] = useState<'promote' | 'demote' | null>(startPromote ? 'promote' : null);
  // Formular nur, solange die Aktion möglich ist (z. B. nicht mehr nach der Beförderung).
  const mode = (chosenMode === 'promote' && !canPromote) || (chosenMode === 'demote' && !canDemote) ? null : chosenMode;
  const [target, setTarget] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const options = mode === 'promote' ? promoteOptions : demoteOptions;
  // Vorauswahl: nächster Rang beim Befördern, sonst der höchste erreichbare niedrigere Rang.
  const preselect =
    mode === 'promote'
      ? String((promoteOptions.find((r) => r.id === st.next_rank?.id) ?? promoteOptions[0])?.id ?? '')
      : String([...demoteOptions].reverse().find((r) => r.min_points <= member.points)?.id ?? '');
  const value = target ?? preselect;

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const rankId = value ? Number(value) : null;
      await api.setRank(member.id, rankId, reason.trim());
      const title = all.find((r) => r.id === rankId)?.title;
      onToast(mode === 'promote' ? `Befördert: ${title}` : title ? `Degradiert: ${title}` : 'Rang entfernt');
      setMode(null);
      setTarget(null);
      setReason('');
      if (startPromote) navigate(`spiess/mitglied/${member.id}`);
      onChanged();
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (ranks.loading && !ranks.data) return <Loading />;
  if (all.length === 0 && !current) return null;

  return (
    <div className="card rank-control">
      <div className="rank-label">Rang</div>
      <div className="rank-title">{current ? current.title : 'Kein Rang'}</div>
      {st.next_rank && st.points_to_next !== null && st.points_to_next <= 0 ? (
        <div className="rank-next promo-due">
          Schwelle für {st.next_rank.title} erreicht
          {st.threshold_reached_since && ` seit ${formatDate(st.threshold_reached_since)}`}
        </div>
      ) : st.next_rank && st.points_to_next !== null ? (
        <div className="rank-next">
          Noch {num(st.points_to_next)} Punkte bis {st.next_rank.title}
        </div>
      ) : (
        current && <div className="rank-next">Höchster Rang</div>
      )}
      {st.below_current_threshold && current && (
        <div className="rank-next neg">
          Punkte unter der Schwelle von {current.title} ({num(current.min_points)})
        </div>
      )}

      {mode === null ? (
        <div className="button-row">
          <button className="btn" disabled={!canPromote} onClick={() => setMode('promote')}>
            Befördern
          </button>
          <button className="btn btn-warn" disabled={!canDemote} onClick={() => setMode('demote')}>
            Degradieren
          </button>
        </div>
      ) : (
        <form className="form rank-form" onSubmit={save}>
          <label className="field">
            <span>{mode === 'promote' ? 'Befördern zum Rang' : 'Degradieren auf'}</span>
            <select value={value} onChange={(e) => setTarget(e.target.value)}>
              {options.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.title} (ab {num(r.min_points)})
                </option>
              ))}
              {mode === 'demote' && <option value="">Kein Rang</option>}
            </select>
          </label>
          <label className="field">
            <span>Begründung (optional)</span>
            <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
          </label>
          <div className="button-row">
            <button className={`btn ${mode === 'demote' ? 'btn-warn' : ''}`} type="submit" disabled={busy}>
              {mode === 'promote' ? 'Befördern' : 'Degradieren'}
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => {
                setMode(null);
                setTarget(null);
              }}
            >
              Abbrechen
            </button>
          </div>
        </form>
      )}
      {!canPromote && !canDemote && mode === null && (
        <p className="muted small">
          Befördern geht erst ab der Schwelle des nächsten Rangs, Degradieren nur unter der Schwelle des aktuellen Rangs.
        </p>
      )}
      <h3 className="section-title">Rang-Verlauf</h3>
      <RankHistoryList entries={member.rank_history} showBy />
    </div>
  );
}
