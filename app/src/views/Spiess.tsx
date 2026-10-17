import { useMemo, useState, type FormEvent } from 'react';
import { api, LEVEL_LABELS, type CatalogItem, type MemberSummary } from '../api';
import { caps, formatDate, formatEuro, formatPoints, halvedNote, parseEuroToCents, todayYmd } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, MemberPicker, StatCard, TitleBadges, useAsync } from '../ui';
import Einrichtung, { canManageAccounts } from './Einrichtung';
import { Promotions, RankControl } from './SpiessRaenge';
import { TitleControl } from './SpiessTitel';
import { PenaltyRow } from './Strafen';

type Sub = 'mitglieder' | 'punkte' | 'strafe' | 'raenge' | 'einrichtung';

/** Frühere Adressen, die jetzt unter „Einrichtung“ liegen. */
function setupAlias(path: string[]): string[] | null {
  if (path[0] === 'katalog') return ['katalog'];
  if (path[0] === 'neu') return ['mitglieder', 'neu'];
  if (path[0] === 'raenge' && path[1] === 'liste') return ['raenge'];
  if (path[0] === 'raenge' && path[1] === 'titel') return ['titel'];
  return path[0] === 'einrichtung' ? path.slice(1) : null;
}

export default function Spiess({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  const [sub, arg] = [path[0] as Sub | 'mitglied' | undefined, path[1]];
  const setup = setupAlias(path);
  const preselect = arg && /^\d+$/.test(arg) ? [Number(arg)] : [];

  const tabs: { id: Sub; label: string }[] = [
    { id: 'mitglieder', label: 'Mitglieder' },
    { id: 'punkte', label: 'Punkte +' },
    { id: 'strafe', label: 'Strafe +' },
    { id: 'raenge', label: 'Dienstgrade' },
    { id: 'einrichtung', label: 'Einrichtung' },
  ];
  const active: Sub = setup ? 'einrichtung' : sub === 'mitglied' || !sub ? 'mitglieder' : (sub as Sub);

  return (
    <div>
      <nav className="segmented" aria-label="Spieß-Bereich">
        {tabs.map((t) => (
          <button
            key={t.id}
            className={active === t.id ? 'active' : ''}
            onClick={() => navigate(`spiess/${t.id}`)}
            aria-current={active === t.id ? 'page' : undefined}
          >
            {t.label}
          </button>
        ))}
      </nav>
      {setup ? (
        <Einrichtung path={setup} onToast={onToast} />
      ) : sub === 'mitglied' && arg ? (
        <MemberDetailView id={Number(arg)} startPromote={path[2] === 'befoerdern'} onToast={onToast} />
      ) : active === 'punkte' ? (
        <GivePoints key={path.join('/')} preselect={preselect} onToast={onToast} />
      ) : active === 'strafe' ? (
        <GivePenalty key={path.join('/')} preselect={preselect} onToast={onToast} />
      ) : active === 'raenge' ? (
        <Promotions />
      ) : (
        <Members />
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */

function Members() {
  const { data, error, loading, reload } = useAsync(api.members);
  const [query, setQuery] = useState('');
  const list = useMemo(
    () => (data ?? []).filter((m) => m.name.toLowerCase().includes(query.trim().toLowerCase())),
    [data, query],
  );

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  const totalOpen = (data ?? []).filter((m) => m.level !== 'ohne').reduce((s, m) => s + m.open_cents, 0);

  return (
    <div>
      <StatCard label="Offene Strafen gesamt" value={formatEuro(totalOpen)} tone={totalOpen > 0 ? 'warn' : 'good'} />
      <input
        className="search"
        type="search"
        placeholder="Mitglied suchen …"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Mitglied suchen"
      />
      <ul className="card-list">
        {list.map((m) => (
          <li key={m.id} className="card row clickable" onClick={() => navigate(`spiess/mitglied/${m.id}`)}>
            <div className="row-main">
              <div className="row-title">
                {m.name}{' '}
                {m.level !== 'mitglied' && <span className={`badge badge-level-${m.level}`}>{caps(LEVEL_LABELS[m.level])}</span>}
                <TitleBadges titles={m.titles} />
                {m.is_jungmariner && <span className="badge badge-jungmariner">Jungmariner</span>}
              </div>
              <div className="row-sub">
                {m.rank && <>{m.rank.title} · </>}
                {m.points.toLocaleString('de-DE')} Punkte
                {m.open_cents > 0 && <> · <span className="neg">{formatEuro(m.open_cents)} offen</span></>}
              </div>
            </div>
            <span className="chevron" aria-hidden="true">›</span>
          </li>
        ))}
        {list.length === 0 && <Empty>Niemand gefunden.</Empty>}
      </ul>
    </div>
  );
}

/* ------------------------------------------------------------------ */

function MemberDetailView({
  id,
  startPromote,
  onToast,
}: {
  id: number;
  startPromote: boolean;
  onToast: (msg: string) => void;
}) {
  const { data, error, loading, reload } = useAsync(() => api.member(id), [id]);
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    try {
      await action();
      onToast(msg);
      reload();
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const m = data!;

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('spiess/mitglieder')}>
        ‹ Alle Mitglieder
      </button>
      <h2 className="member-name">{m.name}</h2>
      <p className="muted small member-level">
        Rolle: {LEVEL_LABELS[m.level]}
        {canManageAccounts() && (
          <>
            {' · '}
            <button className="btn btn-link inline-link" onClick={() => navigate(`spiess/einrichtung/mitglieder/${m.id}`)}>
              Konto verwalten
            </button>
          </>
        )}
      </p>
      {m.level !== 'ohne' && (
        <JungmarinerToggle key={m.id} id={m.id} initial={m.is_jungmariner} onChanged={reload} onToast={onToast} />
      )}
      <div className="stats">
        <StatCard label="Punkte" value={m.points.toLocaleString('de-DE')} tone="good" />
        <StatCard label="Offen" value={formatEuro(m.open_cents)} tone={m.open_cents > 0 ? 'warn' : 'good'} />
      </div>
      {m.level !== 'ohne' && (
        <div className="button-row">
          <button className="btn" onClick={() => navigate(`spiess/punkte/${m.id}`)}>
            Punkte vergeben
          </button>
          <button className="btn btn-warn" onClick={() => navigate(`spiess/strafe/${m.id}`)}>
            Strafe vergeben
          </button>
        </div>
      )}
      {m.level !== 'ohne' && (
        <RankControl
          key={`${m.id}-${m.rank_status.rank?.id ?? 0}-${m.points}`}
          member={m}
          startPromote={startPromote}
          onChanged={reload}
          onToast={onToast}
        />
      )}
      {m.level !== 'ohne' && <TitleControl member={m} onChanged={reload} onToast={onToast} />}

      <h3 className="section-title">Strafen</h3>
      {m.penalties.length === 0 ? (
        <Empty>Keine Strafen.</Empty>
      ) : (
        <ul className="card-list">
          {m.penalties.map((p) => (
            <PenaltyRow
              key={p.id}
              p={p}
              actions={
                <>
                  {p.status === 'open' ? (
                    <button
                      className="btn btn-small"
                      disabled={busy}
                      onClick={() => run(() => api.setPenaltyStatus(p.id, 'paid'), 'Als bezahlt markiert')}
                    >
                      Bezahlt
                    </button>
                  ) : (
                    <button
                      className="btn btn-small btn-secondary"
                      disabled={busy}
                      onClick={() => run(() => api.setPenaltyStatus(p.id, 'open'), 'Wieder auf offen gesetzt')}
                    >
                      Doch offen
                    </button>
                  )}
                  <button
                    className="btn btn-small btn-danger"
                    disabled={busy}
                    onClick={() =>
                      window.confirm(`Strafe „${p.reason}“ (${formatEuro(p.amount_cents)}) wirklich stornieren?`) &&
                      run(() => api.deletePenalty(p.id), 'Strafe storniert')
                    }
                  >
                    Stornieren
                  </button>
                </>
              }
            />
          ))}
        </ul>
      )}

      <h3 className="section-title">Punkte</h3>
      {m.point_list.length === 0 ? (
        <Empty>Keine Punkte.</Empty>
      ) : (
        <ul className="card-list">
          {m.point_list.map((p) => (
            <li key={p.id} className="card row">
              <div className="row-main">
                <div className="row-title">{p.reason}</div>
                <div className="row-sub">
                  {formatDate(p.date)}
                  {p.created_by_name && ` · von ${p.created_by_name}`}
                  {halvedNote(p)}
                </div>
                <div className="row-actions">
                  <button
                    className="btn btn-small btn-danger"
                    disabled={busy}
                    onClick={() =>
                      window.confirm(`Eintrag „${p.reason}“ (${formatPoints(p.points)}) wirklich stornieren?`) &&
                      run(() => api.deletePoints(p.id), 'Punkte storniert')
                    }
                  >
                    Stornieren
                  </button>
                </div>
              </div>
              <div className={`row-value ${p.points < 0 ? 'neg' : 'pos'}`}>{formatPoints(p.points)}</div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Jungmariner erhalten laut Satzung nur die halbe Punktzahl. Die Markierung gilt
 * für künftige Vergaben; bereits vergebene Punkte bleiben, wie sie sind.
 */
function JungmarinerToggle({
  id,
  initial,
  onChanged,
  onToast,
}: {
  id: number;
  initial: boolean;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [checked, setChecked] = useState(initial);
  const [busy, setBusy] = useState(false);
  async function change(next: boolean) {
    setChecked(next);
    setBusy(true);
    try {
      await api.setJungmariner(id, next);
      onToast(next ? 'Als Jungmariner markiert' : 'Kein Jungmariner mehr');
      onChanged();
    } catch (e) {
      setChecked(!next);
      onToast((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <label className="toggle">
      <input type="checkbox" checked={checked} disabled={busy} onChange={(e) => change(e.target.checked)} />
      Jungmariner (erhält ab jetzt die halbe Punktzahl, Abzüge zählen voll)
    </label>
  );
}

/* ------------------------------------------------------------------ */

function useMembersAndCatalog() {
  return useAsync(() => Promise.all([api.members(), api.catalog()]));
}

function GivePoints({ preselect, onToast }: { preselect: number[]; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useMembersAndCatalog();
  const [selected, setSelected] = useState<number[]>(preselect);
  const [catalogId, setCatalogId] = useState<string>('');
  const [points, setPoints] = useState('');
  const [reason, setReason] = useState('');
  const [date, setDate] = useState(todayYmd());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [members, catalog] = data as [MemberSummary[], CatalogItem[]];
  const n = Number(points);
  const juniors = members.filter((m) => m.is_jungmariner && selected.includes(m.id));

  function chooseCatalog(id: string) {
    setCatalogId(id);
    const item = catalog.find((c) => String(c.id) === id);
    if (item) {
      setPoints(String(item.points));
      setReason(item.title);
    }
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (selected.length === 0) return setFormError('Bitte mindestens ein Mitglied auswählen.');
    if (!Number.isInteger(n) || n === 0) return setFormError('Bitte eine ganze Punktzahl ungleich 0 angeben.');
    if (!reason.trim()) return setFormError('Bitte einen Grund angeben.');
    setBusy(true);
    try {
      await api.givePoints({
        user_ids: selected,
        points: n,
        reason: reason.trim(),
        date,
        ...(catalogId ? { catalog_id: Number(catalogId) } : {}),
      });
      onToast(`${formatPoints(n)} Punkte an ${selected.length} ${selected.length === 1 ? 'Mitglied' : 'Mitglieder'} vergeben`);
      setSelected([]);
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form" onSubmit={submit}>
      <label className="field">
        <span>Vorlage aus dem Punktekatalog</span>
        <select value={catalogId} onChange={(e) => chooseCatalog(e.target.value)}>
          <option value="">– freie Eingabe –</option>
          {catalog.map((c) => (
            <option key={c.id} value={c.id}>
              {c.title} ({formatPoints(c.points)})
            </option>
          ))}
        </select>
      </label>
      <div className="field-row">
        <label className="field">
          <span>Punkte</span>
          <input type="number" inputMode="numeric" step="1" value={points} onChange={(e) => setPoints(e.target.value)} required />
        </label>
        <label className="field">
          <span>Datum</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
        </label>
      </div>
      <label className="field">
        <span>Grund</span>
        <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} required />
      </label>
      <div className="field">
        <span>Mitglieder</span>
        <MemberPicker members={members} selected={selected} onChange={setSelected} />
      </div>
      {juniors.length > 0 && n > 0 && Number.isInteger(n) && (
        <p className="muted small" role="status">
          {juniors.length === 1 ? `${juniors[0].name} ist Jungmariner und erhält` : `${juniors.length} Jungmariner erhalten`} nur die
          halbe Punktzahl: {formatPoints(n / 2)}.
        </p>
      )}
      {formError && <p className="form-error" role="alert">{formError}</p>}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Speichert …' : 'Punkte vergeben'}
      </button>
    </form>
  );
}

function GivePenalty({ preselect, onToast }: { preselect: number[]; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.members);
  const [selected, setSelected] = useState<number[]>(preselect);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [date, setDate] = useState(todayYmd());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    const cents = parseEuroToCents(amount);
    if (selected.length === 0) return setFormError('Bitte mindestens ein Mitglied auswählen.');
    if (cents === null || cents <= 0) return setFormError('Bitte einen gültigen Betrag angeben, z. B. 2,50.');
    if (cents > 100000) return setFormError('Eine einzelne Strafe darf höchstens 1.000 € betragen.');
    if (!reason.trim()) return setFormError('Bitte einen Grund angeben.');
    setBusy(true);
    try {
      await api.givePenalty({ user_ids: selected, amount_cents: cents, reason: reason.trim(), date });
      onToast(`Strafe über ${formatEuro(cents)} an ${selected.length} ${selected.length === 1 ? 'Mitglied' : 'Mitglieder'} vergeben`);
      setSelected([]);
      setAmount('');
      setReason('');
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form" onSubmit={submit}>
      <div className="field-row">
        <label className="field">
          <span>Betrag in €</span>
          <input type="text" inputMode="decimal" placeholder="2,50" value={amount} onChange={(e) => setAmount(e.target.value)} required />
        </label>
        <label className="field">
          <span>Datum</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
        </label>
      </div>
      <label className="field">
        <span>Grund</span>
        <input type="text" maxLength={255} placeholder="z. B. Zu spät beim Antreten" value={reason} onChange={(e) => setReason(e.target.value)} required />
      </label>
      <div className="field">
        <span>Mitglieder</span>
        <MemberPicker members={data ?? []} selected={selected} onChange={setSelected} />
      </div>
      {formError && <p className="form-error" role="alert">{formError}</p>}
      <button className="btn btn-warn btn-block" type="submit" disabled={busy}>
        {busy ? 'Speichert …' : 'Strafe vergeben'}
      </button>
    </form>
  );
}
