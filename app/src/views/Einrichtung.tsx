import { useEffect, useMemo, useState, type FormEvent } from 'react';
import {
  api,
  config,
  isMemberLevel,
  LEVEL_LABELS,
  MAX_BULK_USERS,
  type Account,
  type CreatedAccount,
  type CreatedUser,
  type Level,
  type NewUserInput,
} from '../api';
import { caps, formatPoints, todayYmd } from '../format';
import { navigate } from '../router';
import { BackToSetup, Empty, ErrorBox, Loading, useAsync } from '../ui';
import { RankList } from './SpiessRaenge';
import { TitleList } from './SpiessTitel';

/**
 * „Spieß → Einrichtung“: Punktekatalog, Dienstgrade und Titel (Spieße und Admins)
 * sowie „Mitglieder verwalten“ (nur Admins).
 */
export default function Einrichtung({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  const [page, arg] = path;
  const canAccounts = canManageAccounts();
  if (page === 'katalog') return <Catalog onToast={onToast} />;
  if (page === 'raenge') return <RankList onToast={onToast} />;
  if (page === 'titel') return <TitleList onToast={onToast} />;
  if (page === 'mitglieder' && canAccounts) {
    if (arg === 'neu' && config?.user?.canCreateUsers) return <NewMember />;
    if (arg && /^\d+$/.test(arg)) return <AccountDetail id={Number(arg)} onToast={onToast} />;
    return <Accounts />;
  }

  const pages: { id: string; title: string; text: string }[] = [
    { id: 'katalog', title: 'Punktekatalog', text: 'Vorlagen für die Punktevergabe' },
    { id: 'raenge', title: 'Dienstgrade', text: 'Dienstgrade mit ihrer Mindestpunktzahl' },
    { id: 'titel', title: 'Titel', text: 'Kapitän, Ehrenkapitän, Ehrenspieß, Ehrenmitglied …' },
    ...(canAccounts
      ? [{ id: 'mitglieder', title: 'Mitglieder verwalten', text: 'Anlegen, Rollen, Namen, Passwörter – nur für Admins' }]
      : []),
  ];
  return (
    <ul className="card-list setup-list">
      {pages.map((p) => (
        <li key={p.id} className="card row clickable" onClick={() => navigate(`spiess/einrichtung/${p.id}`)}>
          <div className="row-main">
            <div className="row-title">{p.title}</div>
            <div className="row-sub">{p.text}</div>
          </div>
          <span className="chevron" aria-hidden="true">›</span>
        </li>
      ))}
    </ul>
  );
}

/** Mitglieder verwalten dürfen Admins (WordPress-Capability edit_users). */
export function canManageAccounts() {
  return !!config?.user?.canEditUsers;
}

/* ------------------------------------------------------------------ */
/* Punktekatalog                                                       */
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
      <BackToSetup />
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

/* ------------------------------------------------------------------ */
/* Mitglieder verwalten (Admins)                                       */
/* ------------------------------------------------------------------ */

function Accounts() {
  const { data, error, loading, reload } = useAsync(api.accounts);
  const [query, setQuery] = useState('');
  const list = useMemo(() => {
    const q = query.trim().toLowerCase();
    return (data ?? []).filter((a) => a.name.toLowerCase().includes(q) || a.username.toLowerCase().includes(q));
  }, [data, query]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  return (
    <div>
      <BackToSetup />
      {config?.user?.canCreateUsers && (
        <button className="btn btn-block add-member" onClick={() => navigate('spiess/einrichtung/mitglieder/neu')}>
          + Mitglied anlegen
        </button>
      )}
      <input
        className="search"
        type="search"
        placeholder="Name oder Benutzername …"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Konto suchen"
      />
      <p className="muted small">Alle Konten der Webseite, auch solche ohne Rolle in der App.</p>
      <ul className="card-list">
        {list.map((a) => (
          <li key={a.id} className="card row clickable" onClick={() => navigate(`spiess/einrichtung/mitglieder/${a.id}`)}>
            <div className="row-main">
              <div className="row-title">
                {a.name}{' '}
                {a.level !== 'mitglied' && <span className={`badge badge-level-${a.level}`}>{caps(LEVEL_LABELS[a.level])}</span>}
              </div>
              <div className="row-sub">
                {a.username}
                {a.email && ` · ${a.email}`}
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

function AccountDetail({ id, onToast }: { id: number; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.accounts);
  const account = data?.find((a) => a.id === id);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (!account) return <ErrorBox message="Konto nicht gefunden." />;

  return (
    <div>
      <BackToSetup to="spiess/einrichtung/mitglieder" label="Mitglieder verwalten" />
      <h2 className="member-name">{account.name}</h2>
      <RoleControl id={account.id} level={account.level} onChanged={reload} onToast={onToast} />
      {isMemberLevel(account.level) && (
        <button className="btn btn-secondary btn-block account-points" onClick={() => navigate(`spiess/mitglied/${account.id}`)}>
          Punkte, Strafen, Dienstgrad und Titel ›
        </button>
      )}
      {account.editable ? (
        <>
          <AccountForm key={`${account.id}-${account.name}-${account.username}-${account.email}`} account={account} onSaved={reload} onToast={onToast} />
          {!account.is_self && <PasswordReset account={account} onToast={onToast} />}
        </>
      ) : (
        <p className="muted small">Dieses Konto kannst du nicht bearbeiten.</p>
      )}
    </div>
  );
}

function AccountForm({ account, onSaved, onToast }: { account: Account; onSaved: () => void; onToast: (msg: string) => void }) {
  const [name, setName] = useState(account.name);
  const [username, setUsername] = useState(account.username);
  const [email, setEmail] = useState(account.email);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const changed = name !== account.name || username !== account.username || email !== account.email;

  async function save(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!name.trim()) return setFormError('Bitte einen Namen angeben.');
    if (
      username.trim() !== account.username &&
      !window.confirm(`Benutzername in „${username.trim()}“ ändern? ${account.name} wird dabei auf allen Geräten abgemeldet und meldet sich danach mit dem neuen Benutzernamen an.`)
    )
      return;
    setBusy(true);
    try {
      await api.updateAccount(account.id, {
        ...(name.trim() !== account.name ? { name: name.trim() } : {}),
        ...(username.trim() !== account.username ? { username: username.trim() } : {}),
        ...(email.trim() !== account.email ? { email: email.trim() } : {}),
      });
      onToast('Gespeichert');
      onSaved();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card account-form" onSubmit={save}>
      <label className="field">
        <span>Name</span>
        <input type="text" autoComplete="off" value={name} onChange={(e) => setName(e.target.value)} required />
      </label>
      <label className="field">
        <span>Benutzername</span>
        <input
          type="text"
          autoComplete="off"
          autoCapitalize="none"
          value={username}
          disabled={account.is_self}
          onChange={(e) => setUsername(e.target.value)}
        />
      </label>
      {account.is_self && <p className="muted small">Den eigenen Benutzernamen kann man hier nicht ändern.</p>}
      <label className="field">
        <span>E-Mail (optional)</span>
        <input type="email" autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} />
      </label>
      {formError && <p className="form-error" role="alert">{formError}</p>}
      <button className="btn btn-block" type="submit" disabled={busy || !changed}>
        {busy ? 'Speichert …' : 'Änderungen speichern'}
      </button>
    </form>
  );
}

function PasswordReset({ account, onToast }: { account: Account; onToast: (msg: string) => void }) {
  const [busy, setBusy] = useState(false);
  const [password, setPassword] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  async function reset() {
    if (!window.confirm(`Neues Startpasswort für ${account.name} erzeugen? Das alte Passwort gilt dann nicht mehr und ${account.name} wird auf allen Geräten abgemeldet.`))
      return;
    setBusy(true);
    try {
      setPassword((await api.resetPassword(account.id)).password);
      setCopied(false);
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (password) {
    const text = `Kompanie-App: ${config?.appUrl ?? ''}\nBenutzername: ${account.username}\nPasswort: ${password}`;
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
        <h2>Neues Startpasswort</h2>
        <p className="muted">
          Das Passwort wird <strong>nur jetzt</strong> angezeigt. Bitte notieren oder kopieren und persönlich weitergeben.
        </p>
        <dl className="credentials">
          <dt>Benutzername</dt>
          <dd>{account.username}</dd>
          <dt>Passwort</dt>
          <dd data-testid="reset-password">{password}</dd>
        </dl>
        <button className="btn" onClick={copy}>
          {copied ? 'Kopiert ✓' : 'Zugangsdaten kopieren'}
        </button>
      </div>
    );
  }

  return (
    <div className="card account-password">
      <div className="rank-label">Passwort</div>
      <p className="muted small">Hat {account.name} das Passwort vergessen, erzeugt die App ein neues Startpasswort.</p>
      <button className="btn btn-warn btn-block" disabled={busy} onClick={reset}>
        Passwort zurücksetzen
      </button>
    </div>
  );
}

/** Admins legen hier die Rolle in der App fest (ohne Rolle / Anwärter / Mitglied / Spieß). */
export
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
        {(['ohne', 'anwaerter', 'mitglied', 'spiess'] as const).map((l) => (
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
  const [mode, setMode] = useState<'einzeln' | 'mehrere'>('einzeln');
  return (
    <div>
      <button type="button" className="btn btn-link back" onClick={() => navigate('spiess/einrichtung/mitglieder')}>
        ‹ Mitglieder verwalten
      </button>
      <div className="segmented small new-member-mode" role="group" aria-label="Anlegen">
        {(
          [
            ['einzeln', 'Einzeln'],
            ['mehrere', 'Mehrere'],
          ] as const
        ).map(([id, label]) => (
          <button key={id} type="button" className={mode === id ? 'active' : ''} aria-pressed={mode === id} onClick={() => setMode(id)}>
            {label}
          </button>
        ))}
      </div>
      {mode === 'einzeln' ? <NewSingleMember /> : <NewMembersBulk />}
    </div>
  );
}

const NEW_LEVELS = ['ohne', 'anwaerter', 'mitglied', 'spiess'] as const;

function LevelPicker({ level, onChange }: { level: Exclude<Level, 'admin'>; onChange: (l: Exclude<Level, 'admin'>) => void }) {
  return (
    <div className="field">
      <span>Rolle in der App</span>
      <div className="segmented small" role="group" aria-label="Rolle in der App">
        {NEW_LEVELS.map((l) => (
          <button
            key={l}
            type="button"
            className={level === l ? 'active' : ''}
            aria-pressed={level === l}
            aria-label={LEVEL_LABELS[l]}
            onClick={() => onChange(l)}
          >
            {caps(LEVEL_LABELS[l])}
          </button>
        ))}
      </div>
    </div>
  );
}

function credentialsText(u: Pick<CreatedUser, 'username' | 'password'>) {
  return `Kompanie-App: ${config?.appUrl ?? ''}\nBenutzername: ${u.username}\nPasswort: ${u.password}`;
}

async function copyText(text: string) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

function NewSingleMember() {
  const ranks = useAsync(api.ranks);
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [level, setLevel] = useState<Exclude<Level, 'admin'>>('anwaerter');
  const [jungmariner, setJungmariner] = useState(false);
  const [points, setPoints] = useState('');
  const [pointsDate, setPointsDate] = useState(todayYmd());
  const [chosenRank, setRankId] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedUser | null>(null);
  const [copied, setCopied] = useState(false);

  const initialPoints = points.trim() === '' ? 0 : Number(points);
  // Nur Dienstgrade, deren Schwelle der Anfangsbestand erreicht (prüft auch der Server).
  const reachable = (ranks.data ?? []).filter((r) => Number.isInteger(initialPoints) && r.min_points <= initialPoints);
  const rankId = reachable.some((r) => String(r.id) === chosenRank) ? chosenRank : '';
  // Anfangsbestand, Dienstgrad und Jungmariner gibt es nur für Mitglieder (prüft auch der Server).
  const memberRole = isMemberLevel(level);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!name.trim()) return setFormError('Bitte einen Namen angeben.');
    if (memberRole && points.trim() !== '' && (!Number.isInteger(initialPoints) || initialPoints < 0))
      return setFormError('Bitte als Anfangsbestand eine ganze Punktzahl ab 0 angeben.');
    setBusy(true);
    try {
      setCreated(
        await api.createUser({
          name: name.trim(),
          ...(username.trim() ? { username: username.trim() } : {}),
          ...(email.trim() ? { email: email.trim() } : {}),
          level,
          ...(memberRole && initialPoints > 0 ? { initial_points: initialPoints, initial_points_date: pointsDate } : {}),
          ...(memberRole && rankId ? { rank_id: Number(rankId) } : {}),
          ...(memberRole && jungmariner ? { jungmariner: true } : {}),
        }),
      );
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (created) {
    const copy = async () => setCopied(await copyText(credentialsText(created)));
    return (
      <div className="card created">
        <h2>{created.name} ist angelegt</h2>
        {created.is_jungmariner && <p className="muted">Als Jungmariner markiert: erhält die halbe Punktzahl.</p>}
        <p className="muted">
          Das Startpasswort wird <strong>nur jetzt</strong> angezeigt. Bitte notieren oder kopieren und persönlich
          weitergeben.
        </p>
        <dl className="credentials">
          <dt>App</dt>
          <dd>{config?.appUrl}</dd>
          <dt>Rolle</dt>
          <dd data-testid="new-level">{LEVEL_LABELS[created.level]}</dd>
          <dt>Benutzername</dt>
          <dd data-testid="new-username">{created.username}</dd>
          <dt>Passwort</dt>
          <dd data-testid="new-password">{created.password}</dd>
        </dl>
        {(created.points > 0 || created.rank) && (
          <p className="muted small" data-testid="new-rank">
            Übernommen: {created.points.toLocaleString('de-DE')} Punkte · Dienstgrad: {created.rank ? created.rank.title : 'keiner'}
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
              setJungmariner(false);
              setLevel('anwaerter');
            }}
          >
            Weiteres Mitglied
          </button>
        </div>
        <button className="btn btn-link" onClick={() => navigate('spiess/einrichtung/mitglieder')}>
          ‹ Zur Kontenliste
        </button>
      </div>
    );
  }

  return (
    <form className="form" onSubmit={submit}>
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
      <LevelPicker level={level} onChange={setLevel} />
      {memberRole && (
        <>
          <label className="toggle">
            <input type="checkbox" checked={jungmariner} onChange={(e) => setJungmariner(e.target.checked)} />
            Jungmariner (erhält die halbe Punktzahl, Abzüge zählen voll)
          </label>
          <fieldset className="fieldset">
            <legend>Übernahme (optional)</legend>
            <p className="muted small">Für bestehende Mitglieder: aktuellen Punktestand und Dienstgrad eintragen. Neue Mitglieder starten ohne Dienstgrad bei 0 Punkten.</p>
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
              <span>Dienstgrad</span>
              <select value={rankId} onChange={(e) => setRankId(e.target.value)} disabled={reachable.length === 0}>
                <option value="">Kein Dienstgrad</option>
                {reachable.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.title} (ab {r.min_points.toLocaleString('de-DE')})
                  </option>
                ))}
              </select>
            </label>
            {(ranks.data ?? []).length > reachable.length && (
              <p className="muted small">Zur Auswahl stehen nur Dienstgrade, deren Schwelle die eingetragenen Punkte erreichen.</p>
            )}
          </fieldset>
        </>
      )}
      <p className="muted small">Das Konto bekommt die gewählte Rolle und ein zufälliges Startpasswort.</p>
      {formError && <p className="form-error" role="alert">{formError}</p>}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Legt an …' : 'Mitglied anlegen'}
      </button>
    </form>
  );
}

/**
 * Eine Zeile pro Person: „Vorname Nachname“, optional mit E-Mail-Adresse dahinter
 * (getrennt durch Komma, Semikolon oder Tabulator, z. B. aus einer Tabelle kopiert).
 */
function parseMemberLines(text: string): { users: Pick<NewUserInput, 'name' | 'email'>[]; errors: string[] } {
  const users: Pick<NewUserInput, 'name' | 'email'>[] = [];
  const errors: string[] = [];
  text.split(/\r?\n/).forEach((raw, i) => {
    const line = raw.trim();
    if (!line) return;
    const parts = line.split(/[\t;,]/).map((p) => p.trim()).filter(Boolean);
    const emails = parts.filter((p) => p.includes('@'));
    const names = parts.filter((p) => !p.includes('@'));
    const name = names.join(' ').replace(/\s+/g, ' ');
    if (emails.length > 1) errors.push(`Zeile ${i + 1}: bitte nur eine E-Mail-Adresse angeben.`);
    else if (!name) errors.push(`Zeile ${i + 1}: Name fehlt.`);
    else if (names.length > 1) errors.push(`Zeile ${i + 1}: bitte als „Vorname Nachname“ ohne Komma schreiben.`);
    else users.push({ name, ...(emails[0] ? { email: emails[0] } : {}) });
  });
  return { users, errors };
}

function NewMembersBulk() {
  const [text, setText] = useState('');
  const [level, setLevel] = useState<Exclude<Level, 'admin'>>('anwaerter');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedAccount[] | null>(null);
  const [copied, setCopied] = useState<number | 'alle' | null>(null);
  const { users, errors } = useMemo(() => parseMemberLines(text), [text]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (errors.length) return setFormError(errors[0]);
    if (users.length === 0) return setFormError('Bitte mindestens einen Namen eintragen.');
    if (users.length > MAX_BULK_USERS) return setFormError(`Bitte höchstens ${MAX_BULK_USERS} auf einmal anlegen.`);
    setBusy(true);
    try {
      setCreated(await api.createUsers(users, level));
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (created) {
    const all = created.map((u) => `${u.name}\n${credentialsText(u)}`).join('\n\n');
    return (
      <div className="card created">
        <h2>{created.length === 1 ? '1 Konto ist angelegt' : `${created.length} Konten sind angelegt`}</h2>
        <p className="muted">
          Die Startpasswörter werden <strong>nur jetzt</strong> angezeigt. Bitte notieren oder kopieren und jeder Person
          persönlich weitergeben.
        </p>
        <button className="btn btn-block" onClick={async () => setCopied((await copyText(all)) ? 'alle' : null)}>
          {copied === 'alle' ? 'Alle kopiert ✓' : 'Alle Zugangsdaten kopieren'}
        </button>
        <p className="muted small">
          App: {config?.appUrl} · Rolle: <span data-testid="bulk-level">{created[0] ? LEVEL_LABELS[created[0].level] : ''}</span>
        </p>
        <ul className="created-list">
          {created.map((u) => (
            <li key={u.id} data-testid="created-user">
              <div className="created-name">{u.name}</div>
              <dl className="credentials">
                <dt>Benutzername</dt>
                <dd data-testid="new-username">{u.username}</dd>
                <dt>Passwort</dt>
                <dd data-testid="new-password">{u.password}</dd>
              </dl>
              <button
                className="btn btn-small btn-secondary"
                aria-label={`Zugangsdaten von ${u.name} kopieren`}
                onClick={async () => setCopied((await copyText(credentialsText(u))) ? u.id : null)}
              >
                {copied === u.id ? 'Kopiert ✓' : 'Kopieren'}
              </button>
            </li>
          ))}
        </ul>
        <button className="btn btn-link" onClick={() => navigate('spiess/einrichtung/mitglieder')}>
          ‹ Zur Kontenliste
        </button>
      </div>
    );
  }

  return (
    <form className="form" onSubmit={submit}>
      <label className="field">
        <span>Eine Person pro Zeile</span>
        <textarea
          rows={8}
          autoComplete="off"
          spellCheck={false}
          placeholder={'Max Müller\nErika Mustermann, erika@example.de'}
          value={text}
          onChange={(e) => setText(e.target.value)}
        />
      </label>
      <p className="muted small">
        Pro Zeile „Vorname Nachname“, optional mit Komma und E-Mail-Adresse dahinter. Eine Liste aus Excel mit den
        Spalten Name und E-Mail kann direkt hineinkopiert werden. Benutzernamen werden aus den Namen gebildet. Enthält
        eine Zeile einen Fehler, wird niemand angelegt.
      </p>
      <LevelPicker level={level} onChange={setLevel} />
      <p className="muted small">
        Alle bekommen diese Rolle und je ein eigenes zufälliges Startpasswort.
        {isMemberLevel(level) && ' Anfangsbestand, Dienstgrad und Jungmariner lassen sich nur beim einzelnen Anlegen eintragen.'}
      </p>
      {users.length > 0 && (
        <div className="bulk-preview" aria-live="polite">
          <span className="field-label">Erkannt: {users.length === 1 ? '1 Person' : `${users.length} Personen`}</span>
          <ul>
            {users.map((u, i) => (
              <li key={i}>
                {u.name}
                {u.email && <span className="muted"> · {u.email}</span>}
              </li>
            ))}
          </ul>
        </div>
      )}
      {errors.length > 0 && (
        <ul className="form-error bulk-errors" role="alert">
          {errors.map((e) => (
            <li key={e}>{e}</li>
          ))}
        </ul>
      )}
      {formError && !errors.includes(formError) && <p className="form-error" role="alert">{formError}</p>}
      <button className="btn btn-block" type="submit" disabled={busy || errors.length > 0 || users.length === 0}>
        {busy ? 'Legt an …' : users.length > 1 ? `${users.length} Konten anlegen` : 'Konten anlegen'}
      </button>
    </form>
  );
}
