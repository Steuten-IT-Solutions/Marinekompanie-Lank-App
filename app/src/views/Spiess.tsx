import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { api, config, LEVEL_LABELS, type CatalogItem, type CreatedUser, type Level, type MemberSummary } from '../api';
import { caps, formatDate, formatEuro, formatPoints, parseEuroToCents, todayYmd } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, MemberPicker, StatCard, TitleBadges, useAsync } from '../ui';
import { Promotions, RankControl, RankList } from './SpiessRaenge';
import { TitleControl, TitleList } from './SpiessTitel';
import { PenaltyRow } from './Strafen';

type Sub = 'mitglieder' | 'punkte' | 'strafe' | 'raenge' | 'katalog';

export default function Spiess({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  const [sub, arg] = [path[0] as Sub | 'mitglied' | 'neu' | undefined, path[1]];
  const preselect = arg && /^\d+$/.test(arg) ? [Number(arg)] : [];

  const tabs: { id: Sub; label: string }[] = [
    { id: 'mitglieder', label: 'Mitglieder' },
    { id: 'punkte', label: 'Punkte +' },
    { id: 'strafe', label: 'Strafe +' },
    { id: 'raenge', label: 'Ränge' },
    { id: 'katalog', label: 'Katalog' },
  ];
  const active: Sub = sub === 'mitglied' || sub === 'neu' || !sub ? 'mitglieder' : (sub as Sub);

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
      {sub === 'mitglied' && arg ? (
        <MemberDetailView id={Number(arg)} startPromote={path[2] === 'befoerdern'} onToast={onToast} />
      ) : sub === 'neu' && config?.user?.canCreateUsers ? (
        <NewMember />
      ) : active === 'punkte' ? (
        <GivePoints key={path.join('/')} preselect={preselect} onToast={onToast} />
      ) : active === 'strafe' ? (
        <GivePenalty key={path.join('/')} preselect={preselect} onToast={onToast} />
      ) : active === 'raenge' ? (
        arg === 'liste' ? <RankList onToast={onToast} /> : arg === 'titel' ? <TitleList onToast={onToast} /> : <Promotions />
      ) : active === 'katalog' ? (
        <Catalog onToast={onToast} />
      ) : (
        <Members />
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */

function Members() {
  const canPromote = !!config?.user?.canPromoteUsers;
  const [showWithoutRole, setShowWithoutRole] = useState(false);
  const { data, error, loading, reload } = useAsync(() => api.members(showWithoutRole), [showWithoutRole]);
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
      {config?.user?.canCreateUsers && (
        <button className="btn btn-block add-member" onClick={() => navigate('spiess/neu')}>
          + Mitglied anlegen
        </button>
      )}
      <input
        className="search"
        type="search"
        placeholder="Mitglied suchen …"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Mitglied suchen"
      />
      {canPromote && (
        <label className="toggle">
          <input type="checkbox" checked={showWithoutRole} onChange={(e) => setShowWithoutRole(e.target.checked)} />
          Auch Konten ohne Rolle anzeigen (um ihnen eine Rolle zu geben)
        </label>
      )}
      <ul className="card-list">
        {list.map((m) => (
          <li key={m.id} className="card row clickable" onClick={() => navigate(`spiess/mitglied/${m.id}`)}>
            <div className="row-main">
              <div className="row-title">
                {m.name}{' '}
                {m.level !== 'mitglied' && <span className={`badge badge-level-${m.level}`}>{caps(LEVEL_LABELS[m.level])}</span>}
                <TitleBadges titles={m.titles} />
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
      <RoleControl id={m.id} level={m.level} onChanged={reload} onToast={onToast} />
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

/** Admins legen hier die Rolle in der App fest (ohne Rolle / Mitglied / Spieß). */
function RoleControl({
  id,
  level,
  onChanged,
  onToast,
}: {
  id: number;
  level: Level;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  // Die gewählte Rolle wird sofort angezeigt (optimistisch); schlägt das Speichern fehl,
  // springt die Auswahl auf den vorherigen Stand zurück.
  const [shown, setShown] = useState(level);
  const [pending, setPending] = useState<Level | null>(null);
  useEffect(() => setShown(level), [level]);
  if (!config?.user?.canPromoteUsers) {
    return <p className="muted small member-level">Rolle: {LEVEL_LABELS[level]}</p>;
  }
  if (level === 'admin' || id === config.user.id) {
    return (
      <p className="muted small member-level">
        Rolle: {LEVEL_LABELS[level]}
        {level === 'admin' && ' (wird im WordPress-Backend verwaltet)'}
      </p>
    );
  }
  async function change(next: Exclude<Level, 'admin'>) {
    if (next === shown || pending) return;
    const previous = shown;
    setShown(next);
    setPending(next);
    try {
      await api.setRole(id, next);
      onToast(next === 'ohne' ? 'Rolle entzogen' : `Rolle geändert: ${LEVEL_LABELS[next]}`);
      onChanged();
    } catch (e) {
      setShown(previous);
      onToast((e as Error).message);
    } finally {
      setPending(null);
    }
  }
  return (
    <div className="role-control">
      <span className="field-label">Rolle in der App</span>
      <div className="segmented small" role="group" aria-label="Rolle in der App" aria-busy={!!pending}>
        {(['ohne', 'mitglied', 'spiess'] as const).map((l) => (
          <button
            key={l}
            className={[shown === l ? 'active' : '', pending === l ? 'pending' : ''].join(' ').trim()}
            aria-pressed={shown === l}
            aria-label={LEVEL_LABELS[l]}
            onClick={() => change(l)}
          >
            {caps(LEVEL_LABELS[l])}
          </button>
        ))}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ */

function NewMember() {
  const ranks = useAsync(api.ranks);
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [points, setPoints] = useState('');
  const [pointsDate, setPointsDate] = useState(todayYmd());
  const [chosenRank, setRankId] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedUser | null>(null);
  const [copied, setCopied] = useState(false);

  const initialPoints = points.trim() === '' ? 0 : Number(points);
  // Nur Ränge, deren Schwelle der Anfangsbestand erreicht (prüft auch der Server).
  const reachable = (ranks.data ?? []).filter((r) => Number.isInteger(initialPoints) && r.min_points <= initialPoints);
  const rankId = reachable.some((r) => String(r.id) === chosenRank) ? chosenRank : '';

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!name.trim()) return setFormError('Bitte einen Namen angeben.');
    if (points.trim() !== '' && (!Number.isInteger(initialPoints) || initialPoints < 0))
      return setFormError('Bitte als Anfangsbestand eine ganze Punktzahl ab 0 angeben.');
    setBusy(true);
    try {
      setCreated(
        await api.createUser({
          name: name.trim(),
          ...(username.trim() ? { username: username.trim() } : {}),
          ...(email.trim() ? { email: email.trim() } : {}),
          ...(initialPoints > 0 ? { initial_points: initialPoints, initial_points_date: pointsDate } : {}),
          ...(rankId ? { rank_id: Number(rankId) } : {}),
        }),
      );
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (created) {
    const text = `Kompanie-App: ${config?.appUrl ?? ''}\nBenutzername: ${created.username}\nPasswort: ${created.password}`;
    const copy = async () => {
      try {
        await navigator.clipboard.writeText(text);
        setCopied(true);
      } catch {
        setCopied(false);
      }
    };
    return (
      <div className="card created">
        <h2>{created.name} ist angelegt</h2>
        <p className="muted">
          Das Startpasswort wird <strong>nur jetzt</strong> angezeigt. Bitte notieren oder kopieren und persönlich
          weitergeben.
        </p>
        <dl className="credentials">
          <dt>App</dt>
          <dd>{config?.appUrl}</dd>
          <dt>Benutzername</dt>
          <dd data-testid="new-username">{created.username}</dd>
          <dt>Passwort</dt>
          <dd data-testid="new-password">{created.password}</dd>
        </dl>
        {(created.points > 0 || created.rank) && (
          <p className="muted small" data-testid="new-rank">
            Übernommen: {created.points.toLocaleString('de-DE')} Punkte · Rang: {created.rank ? created.rank.title : 'keiner'}
          </p>
        )}
        <div className="button-row">
          <button className="btn" onClick={copy}>
            {copied ? 'Kopiert ✓' : 'Zugangsdaten kopieren'}
          </button>
          <button
            className="btn btn-secondary"
            onClick={() => {
              setCreated(null);
              setCopied(false);
              setName('');
              setUsername('');
              setEmail('');
              setPoints('');
              setRankId('');
            }}
          >
            Weiteres Mitglied
          </button>
        </div>
        <button className="btn btn-link" onClick={() => navigate('spiess/mitglieder')}>
          ‹ Zur Mitgliederliste
        </button>
      </div>
    );
  }

  return (
    <form className="form" onSubmit={submit}>
      <button type="button" className="btn btn-link back" onClick={() => navigate('spiess/mitglieder')}>
        ‹ Alle Mitglieder
      </button>
      <label className="field">
        <span>Name</span>
        <input type="text" autoComplete="off" placeholder="Vorname Nachname" value={name} onChange={(e) => setName(e.target.value)} required />
      </label>
      <label className="field">
        <span>Benutzername (optional)</span>
        <input
          type="text"
          autoComplete="off"
          autoCapitalize="none"
          placeholder="wird sonst aus dem Namen gebildet"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
        />
      </label>
      <label className="field">
        <span>E-Mail (optional)</span>
        <input type="email" autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} />
      </label>
      <fieldset className="fieldset">
        <legend>Übernahme (optional)</legend>
        <p className="muted small">Für bestehende Mitglieder: aktuellen Punktestand und Rang eintragen. Neue Mitglieder starten ohne Rang bei 0 Punkten.</p>
        <div className="field-row">
          <label className="field">
            <span>Punkte (Anfangsbestand)</span>
            <input type="number" inputMode="numeric" min="0" step="1" placeholder="0" value={points} onChange={(e) => setPoints(e.target.value)} />
          </label>
          <label className="field">
            <span>Stand vom</span>
            <input type="date" value={pointsDate} onChange={(e) => setPointsDate(e.target.value)} />
          </label>
        </div>
        <label className="field">
          <span>Rang</span>
          <select value={rankId} onChange={(e) => setRankId(e.target.value)} disabled={reachable.length === 0}>
            <option value="">Kein Rang</option>
            {reachable.map((r) => (
              <option key={r.id} value={r.id}>
                {r.title} (ab {r.min_points.toLocaleString('de-DE')})
              </option>
            ))}
          </select>
        </label>
        {(ranks.data ?? []).length > reachable.length && (
          <p className="muted small">Zur Auswahl stehen nur Ränge, deren Schwelle die eingetragenen Punkte erreichen.</p>
        )}
      </fieldset>
      <p className="muted small">Das Konto bekommt die Rolle „Mitglied“ und ein zufälliges Startpasswort.</p>
      {formError && <p className="form-error" role="alert">{formError}</p>}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Legt an …' : 'Mitglied anlegen'}
      </button>
    </form>
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
    const n = Number(points);
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

/* ------------------------------------------------------------------ */

function Catalog({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.catalog(true));
  const [title, setTitle] = useState('');
  const [points, setPoints] = useState('');
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
    const n = Number(points);
    if (!title.trim() || !Number.isInteger(n)) {
      onToast('Bitte Titel und ganze Punktzahl angeben.');
      return;
    }
    if (await run(() => api.createCatalogItem(title.trim(), n), 'Vorlage angelegt')) {
      setTitle('');
      setPoints('');
    }
  }

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  return (
    <div>
      <form className="form card" onSubmit={add}>
        <div className="field-row">
          <label className="field grow">
            <span>Neue Vorlage</span>
            <input type="text" placeholder="z. B. Teilnahme Schützenfest" value={title} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <label className="field narrow">
            <span>Punkte</span>
            <input type="number" inputMode="numeric" step="1" value={points} onChange={(e) => setPoints(e.target.value)} />
          </label>
        </div>
        <button className="btn btn-block" type="submit" disabled={busy}>
          Hinzufügen
        </button>
      </form>
      <ul className="card-list">
        {(data ?? []).map((c) => (
          <li key={c.id} className={`card row ${c.active ? '' : 'inactive'}`}>
            <div className="row-main">
              <div className="row-title">{c.title}</div>
              <div className="row-sub">{c.active ? 'aktiv' : 'deaktiviert'}</div>
              <div className="row-actions">
                <button
                  className="btn btn-small btn-secondary"
                  disabled={busy}
                  onClick={() => {
                    const next = window.prompt(`Punkte für „${c.title}“`, String(c.points));
                    if (next === null) return;
                    const n = Number(next);
                    if (!Number.isInteger(n)) return onToast('Bitte eine ganze Zahl angeben.');
                    run(() => api.updateCatalogItem(c.id, { points: n }), 'Gespeichert');
                  }}
                >
                  Punkte ändern
                </button>
                <button
                  className="btn btn-small btn-secondary"
                  disabled={busy}
                  onClick={() => run(() => api.updateCatalogItem(c.id, { active: !c.active }), c.active ? 'Deaktiviert' : 'Aktiviert')}
                >
                  {c.active ? 'Deaktivieren' : 'Aktivieren'}
                </button>
                <button
                  className="btn btn-small btn-danger"
                  disabled={busy}
                  onClick={() =>
                    window.confirm(`Vorlage „${c.title}“ wirklich löschen? Bereits vergebene Punkte bleiben erhalten.`) &&
                    run(() => api.deleteCatalogItem(c.id), 'Vorlage gelöscht')
                  }
                >
                  Löschen
                </button>
              </div>
            </div>
            <div className="row-value pos">{formatPoints(c.points)}</div>
          </li>
        ))}
        {(data ?? []).length === 0 && <Empty>Noch keine Vorlagen. Lege oben die erste an.</Empty>}
      </ul>
    </div>
  );
}
