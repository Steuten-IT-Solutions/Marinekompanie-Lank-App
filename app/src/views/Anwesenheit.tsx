import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import {
  api,
  ATTENDANCE_LABELS,
  type AttendanceDetail,
  type AttendanceEvent,
  type AttendanceInput,
  type AttendanceMember,
  type AttendanceStatus,
  type AttendanceSummary,
} from '../api';
import { formatDate, formatEventWhen, todayYmd } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

/** Spalten der Liste; je Mitglied ist genau ein Häkchen gesetzt. */
const STATUS_COLUMNS: AttendanceStatus[] = ['anwesend', 'entschuldigt', 'fehlt'];

/** Spieß → Anwesenheit: #/spiess/anwesenheit[/<id> | /termin/<key>] */
export default function Anwesenheit({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  if (path[0] === 'termin' && path[1]) return <EventAttendance key={path[1]} eventKey={path[1]} onToast={onToast} />;
  if (path[0] && /^\d+$/.test(path[0])) return <SavedAttendance key={path[0]} id={Number(path[0])} onToast={onToast} />;
  return <Overview onToast={onToast} />;
}

export function countsText(c: Record<AttendanceStatus, number>): string {
  return `${c.anwesend} anwesend · ${c.entschuldigt} entschuldigt · ${c.fehlt} fehlt`;
}

/** Aufteilung einer Gruppe; Jungmariner zählen nicht zusätzlich als Mitglieder. */
export function breakdown(group: AttendanceMember[]): string[] {
  const anw = group.filter((m) => m.is_anwaerter).length;
  const jung = group.filter((m) => !m.is_anwaerter && m.is_jungmariner).length;
  const members = group.length - anw - jung;
  return [`${members} ${members === 1 ? 'Mitglied' : 'Mitglieder'}`, `${jung} Jungmariner`, `${anw} Anwärter`];
}

/**
 * Zähler „4 anwesend · 1 entschuldigt · 2 fehlt“. Ein Tipp auf eine Zahl zeigt,
 * wie viele davon Mitglieder, Jungmariner und Anwärter sind.
 */
function StatusCounts({ members, statuses }: { members: AttendanceMember[]; statuses: Record<number, AttendanceStatus> }) {
  const [open, setOpen] = useState<AttendanceStatus | null>(null);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onPointer = (e: PointerEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(null);
    };
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(null);
    document.addEventListener('pointerdown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="attendance-counts" ref={ref} role="status">
      {STATUS_COLUMNS.map((st, i) => {
        const group = members.filter((m) => statuses[m.id] === st);
        const isOpen = open === st;
        return (
          <span key={st} className={`count-item count-item-${i}`}>
            {i > 0 && <span aria-hidden="true"> · </span>}
            <button
              type="button"
              className="count-button"
              aria-expanded={isOpen}
              aria-describedby={isOpen ? `count-tip-${st}` : undefined}
              onClick={() => setOpen(isOpen ? null : st)}
            >
              {group.length} {ATTENDANCE_LABELS[st]}
            </button>
            {isOpen && (
              <span className="count-tooltip" role="tooltip" id={`count-tip-${st}`}>
                {breakdown(group).map((line) => (
                  <span key={line}>{line}</span>
                ))}
              </span>
            )}
          </span>
        );
      })}
    </div>
  );
}

/** Punkte und Strafen gibt es nur für Mitglieder, nicht für Anwärter. */
export function canBook(m: AttendanceMember): boolean {
  return !m.is_anwaerter;
}

/* ------------------------------------------------------------------ */

const EVENTS_SHOWN = 8;

async function loadOverview(): Promise<[AttendanceEvent[] | string, AttendanceSummary[]]> {
  // Ohne erreichbaren Kalender bleiben freie Termine und die letzten Listen nutzbar.
  const events = api.attendanceEvents().catch((e: Error) => e.message);
  return Promise.all([events, api.attendanceLists()]);
}

function Overview({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(loadOverview);
  const [showAll, setShowAll] = useState(false);
  const [title, setTitle] = useState('');
  const [date, setDate] = useState(todayYmd());
  const [busy, setBusy] = useState(false);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [events, lists] = data!;

  async function createFree(e: FormEvent) {
    e.preventDefault();
    if (!title.trim()) return onToast('Bitte einen Titel angeben.');
    setBusy(true);
    try {
      const list = await api.saveAttendance({ source: 'frei', title: title.trim(), date, entries: [] });
      navigate(`spiess/anwesenheit/${list.id}`);
    } catch (err) {
      onToast((err as Error).message);
      setBusy(false);
    }
  }

  return (
    <div className="attendance">
      <h2 className="section-title">Termin aus dem Kalender</h2>
      {typeof events === 'string' ? (
        <ErrorBox message={events} onRetry={reload} />
      ) : events.length === 0 ? (
        <Empty>Keine Termine in den letzten 90 Tagen.</Empty>
      ) : (
        <>
          <ul className="card-list">
            {(showAll ? events : events.slice(0, EVENTS_SHOWN)).map((ev) => (
              <li
                key={ev.key}
                className="card row clickable"
                onClick={() => navigate(ev.list_id ? `spiess/anwesenheit/${ev.list_id}` : `spiess/anwesenheit/termin/${ev.key}`)}
              >
                <div className="row-main">
                  <div className="row-title">
                    {ev.title} {ev.list_id && <span className="badge badge-list">Liste vorhanden</span>}
                  </div>
                  <div className="row-sub">{formatEventWhen(ev)}</div>
                </div>
                <span className="chevron" aria-hidden="true">›</span>
              </li>
            ))}
          </ul>
          {!showAll && events.length > EVENTS_SHOWN && (
            <button className="btn btn-link" onClick={() => setShowAll(true)}>
              Ältere Termine anzeigen ({events.length - EVENTS_SHOWN})
            </button>
          )}
        </>
      )}

      <form className="form card free-event" onSubmit={createFree}>
        <h3>Freier Termin</h3>
        <p className="muted small">Für Termine, die nicht im Kalender stehen, z. B. einen spontanen Arbeitsdienst.</p>
        <div className="field-row">
          <label className="field grow">
            <span>Titel</span>
            <input type="text" maxLength={191} placeholder="z. B. Arbeitsdienst" value={title} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <label className="field">
            <span>Datum</span>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
          </label>
        </div>
        <button className="btn btn-block" type="submit" disabled={busy}>
          {busy ? 'Legt an …' : 'Liste anlegen'}
        </button>
      </form>

      <h2 className="section-title">Letzte Listen</h2>
      {lists.length === 0 ? (
        <Empty>Noch keine Anwesenheitslisten.</Empty>
      ) : (
        <ul className="card-list">
          {lists.map((l) => (
            <li key={l.id} className="card row clickable" onClick={() => navigate(`spiess/anwesenheit/${l.id}`)}>
              <div className="row-main">
                <div className="row-title">{l.title}</div>
                <div className="row-sub">
                  {formatDate(l.date)} · {countsText(l.counts)}
                </div>
              </div>
              <span className="chevron" aria-hidden="true">›</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */

/** Noch nicht gespeicherte Liste zu einem Kalendertermin. */
function EventAttendance({ eventKey, onToast }: { eventKey: string; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => Promise.all([api.attendanceEvents(), api.members({ anwaerter: true })]));
  const event = data?.[0].find((ev) => ev.key === eventKey);

  // Gibt es schon eine Liste, direkt dorthin.
  useEffect(() => {
    if (event?.list_id) navigate(`spiess/anwesenheit/${event.list_id}`);
  }, [event?.list_id]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (!event) return <ErrorBox message="Diesen Termin gibt es in den letzten 90 Tagen nicht (mehr)." />;
  if (event.list_id) return <Loading />;

  const members: AttendanceMember[] = data![1].map((m) => ({
    id: m.id,
    name: m.name,
    is_anwaerter: m.level === 'anwaerter',
    is_jungmariner: m.is_jungmariner,
    status: 'fehlt',
    booked: false,
  }));
  return (
    <Editor
      list={{ source: 'kalender', event_key: event.key, title: event.title, date: event.date }}
      when={formatEventWhen(event)}
      members={members}
      onToast={onToast}
    />
  );
}

function SavedAttendance({ id, onToast }: { id: number; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.attendance(id), [id]);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  return <Editor key={data!.updated_at} list={data!} members={data!.members} onToast={onToast} />;
}

type EditorList =
  | AttendanceDetail
  | { source: 'kalender'; event_key: string; title: string; date: string; id?: undefined; updated_by_name?: undefined };

function Editor({
  list,
  when,
  members,
  onToast,
}: {
  list: EditorList;
  when?: string;
  members: AttendanceMember[];
  onToast: (msg: string) => void;
}) {
  const [statuses, setStatuses] = useState<Record<number, AttendanceStatus>>(() =>
    Object.fromEntries(members.map((m) => [m.id, m.status])),
  );
  const [title, setTitle] = useState(list.title);
  const [date, setDate] = useState(list.date);
  const [dirty, setDirty] = useState(!list.id);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const free = list.source === 'frei';

  const filtered = useMemo(
    () => members.filter((m) => m.name.toLowerCase().includes(query.trim().toLowerCase())),
    [members, query],
  );
  const counts = { anwesend: 0, entschuldigt: 0, fehlt: 0 };
  for (const m of members) counts[statuses[m.id]]++;
  const toBook = members.filter((m) => statuses[m.id] === 'anwesend' && !m.booked && canBook(m)).length;
  const presentBookable = members.filter((m) => statuses[m.id] === 'anwesend' && canBook(m)).length;
  const toPenalize = members.filter((m) => statuses[m.id] === 'fehlt' && canBook(m)).length;

  function change(next: Record<number, AttendanceStatus>) {
    setStatuses(next);
    setDirty(true);
  }

  /** Speichert die Liste und liefert ihre ID. */
  async function save(): Promise<number | null> {
    if (free && !title.trim()) {
      onToast('Bitte einen Titel angeben.');
      return null;
    }
    const meta = list.id ? { id: list.id } : { source: 'kalender' as const, event_key: list.event_key };
    const body: AttendanceInput = {
      ...meta,
      title: free ? title.trim() : list.title,
      date: free ? date : list.date,
      entries: members.map((m) => ({ user_id: m.id, status: statuses[m.id] })),
    };
    setBusy(true);
    try {
      const saved = await api.saveAttendance(body);
      setDirty(false);
      return saved.id;
    } catch (e) {
      onToast((e as Error).message);
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function saveAndStay() {
    const id = await save();
    if (id === null) return;
    onToast('Anwesenheit gespeichert');
    if (!list.id) navigate(`spiess/anwesenheit/${id}`);
  }

  async function saveAndGo(target: 'punkte' | 'strafe') {
    const id = dirty || !list.id ? await save() : list.id;
    if (id !== null) navigate(`spiess/${target}/anwesenheit/${id}`);
  }

  async function remove() {
    if (!list.id || !window.confirm(`Anwesenheitsliste „${list.title}“ wirklich löschen? Gebuchte Punkte bleiben erhalten.`)) return;
    setBusy(true);
    try {
      await api.deleteAttendance(list.id);
      onToast('Liste gelöscht');
      navigate('spiess/anwesenheit');
    } catch (e) {
      onToast((e as Error).message);
      setBusy(false);
    }
  }

  return (
    <div className="attendance">
      <button className="btn btn-link back" onClick={() => navigate('spiess/anwesenheit')}>
        ‹ Anwesenheit
      </button>
      {free ? (
        <div className="field-row attendance-meta">
          <label className="field grow">
            <span>Titel</span>
            <input
              type="text"
              maxLength={191}
              value={title}
              onChange={(e) => {
                setTitle(e.target.value);
                setDirty(true);
              }}
            />
          </label>
          <label className="field">
            <span>Datum</span>
            <input
              type="date"
              value={date}
              onChange={(e) => {
                setDate(e.target.value);
                setDirty(true);
              }}
            />
          </label>
        </div>
      ) : (
        <>
          <h2 className="member-name">{list.title}</h2>
          <p className="muted small">{when ?? formatDate(list.date)}</p>
        </>
      )}
      <StatusCounts members={members} statuses={statuses} />

      <div className="picker">
        <div className="picker-head">
          <input
            type="search"
            placeholder="Mitglied suchen …"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            aria-label="Mitglied suchen"
          />
          <button
            type="button"
            className="btn btn-link nowrap"
            onClick={() => change({ ...statuses, ...Object.fromEntries(filtered.map((m) => [m.id, 'anwesend' as const])) })}
          >
            Alle anwesend
          </button>
        </div>
        <div className="attendance-grid attendance-head" aria-hidden="true">
          <span />
          {STATUS_COLUMNS.map((st) => (
            <span key={st} className={`attendance-col attendance-col-${st}`}>
              {ATTENDANCE_LABELS[st]}
            </span>
          ))}
        </div>
        <ul className="picker-list attendance-list">
          {filtered.map((m) => (
            <li key={m.id} className="attendance-grid" role="radiogroup" aria-label={m.name}>
              <span className="attendance-name">
                <span>
                  {m.name}
                  {m.is_anwaerter && <span className="badge badge-level-anwaerter">Anwärter</span>}
                  {m.is_jungmariner && !m.is_anwaerter && <span className="badge badge-jungmariner">Jungmariner</span>}
                </span>
                {m.booked && <span className="attendance-booked">Punkte gebucht</span>}
              </span>
              {STATUS_COLUMNS.map((st) => (
                <span key={st} className="attendance-cell">
                  <input
                    type="radio"
                    className={`attendance-check attendance-check-${st}`}
                    name={`att-${m.id}`}
                    checked={statuses[m.id] === st}
                    onChange={() => change({ ...statuses, [m.id]: st })}
                    aria-label={`${m.name}: ${ATTENDANCE_LABELS[st]}`}
                  />
                </span>
              ))}
            </li>
          ))}
          {filtered.length === 0 && <li className="muted">Niemand gefunden.</li>}
        </ul>
      </div>

      <button className="btn btn-block attendance-save" disabled={busy || !dirty} onClick={saveAndStay}>
        {busy ? 'Speichert …' : dirty ? 'Speichern' : 'Gespeichert'}
      </button>
      <div className="button-row">
        <button className="btn" disabled={busy || toBook === 0} onClick={() => saveAndGo('punkte')}>
          Punkte für {toBook} {toBook === 1 ? 'Anwesenden' : 'Anwesende'}
        </button>
        <button className="btn btn-warn" disabled={busy || toPenalize === 0} onClick={() => saveAndGo('strafe')}>
          Strafe für {toPenalize} {toPenalize === 1 ? 'Unentschuldigten' : 'Unentschuldigte'}
        </button>
      </div>
      {toBook === 0 && presentBookable > 0 && <p className="muted small">Punkte bereits gebucht.</p>}
      {list.id && (
        <p className="muted small">
          {list.updated_by_name && <>Zuletzt gespeichert von {list.updated_by_name}. </>}
          <button className="btn btn-link inline-link danger-link" disabled={busy} onClick={remove}>
            Liste löschen
          </button>
        </p>
      )}
    </div>
  );
}
