import { api, type Me, type RankHistoryEntry, type TitleRef } from '../api';
import { formatDate } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, TitleBadges, useAsync } from '../ui';

const num = (n: number) => n.toLocaleString('de-DE');

/** Eigene Titel als eigene Karte; ohne Titel wird nichts angezeigt. */
export function MyTitles({ titles }: { titles: TitleRef[] }) {
  if (titles.length === 0) return null;
  return (
    <div className="card rank-card my-titles">
      <div className="rank-label">{titles.length === 1 ? 'Mein Titel' : 'Meine Titel'}</div>
      <TitleBadges titles={titles} />
    </div>
  );
}

/**
 * Eigener Dienstgrad mit Fortschritt zum nächsten Dienstgrad und eigene Titel.
 * Ohne angelegte Dienstgrade werden nur die Titel angezeigt, ohne Titel dann gar nichts.
 * Mit `showTitles={false}` bleiben die Titel weg (wenn die Seite sie schon anderswo zeigt).
 */
export function RankProgress({ me, link = false, showTitles = true }: { me: Me; link?: boolean; showTitles?: boolean }) {
  if (!me.rank && !me.next_rank) {
    return showTitles ? <MyTitles titles={me.titles} /> : null;
  }
  const base = me.rank?.min_points ?? 0;
  let progress = 1;
  let text: string;
  if (!me.next_rank) {
    text = 'Höchster Dienstgrad erreicht.';
  } else if ((me.points_to_next ?? 0) <= 0) {
    text = `Punkte für ${me.next_rank.title} erreicht – die Beförderung erfolgt durch die Spieße.`;
  } else {
    const span = me.next_rank.min_points - base;
    progress = span > 0 ? Math.min(1, Math.max(0, (me.points - base) / span)) : 0;
    text = `Noch ${num(me.points_to_next!)} ${me.points_to_next === 1 ? 'Punkt' : 'Punkte'} bis ${me.next_rank.title}`;
  }
  return (
    <div className={`card rank-card ${link ? 'clickable' : ''}`} onClick={link ? () => navigate('meins/rang') : undefined}>
      <div className="rank-label">Mein Dienstgrad</div>
      <div className="rank-title">{me.rank ? me.rank.title : 'Noch kein Dienstgrad'}</div>
      {showTitles && <TitleBadges titles={me.titles} />}
      {me.next_rank && (
        <div
          className="progress"
          role="progressbar"
          aria-label={`Fortschritt bis ${me.next_rank.title}`}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={Math.round(progress * 100)}
        >
          <span style={{ width: `${progress * 100}%` }} />
        </div>
      )}
      <div className="rank-next">{text}</div>
      {link && <div className="rank-more">Dienstgrade und Verlauf ›</div>}
    </div>
  );
}

/** Verlauf von Beförderungen und Degradierungen. */
export function RankHistoryList({ entries, showBy = false }: { entries: RankHistoryEntry[]; showBy?: boolean }) {
  if (entries.length === 0) return <Empty>Noch keine Beförderung.</Empty>;
  return (
    <ul className="card-list">
      {entries.map((h) => (
        <li key={h.id} className={`card row rank-history-${h.kind}`}>
          <div className="row-main">
            <div className="row-title">
              {h.kind === 'befoerderung' ? 'Befördert' : 'Degradiert'} {h.to_title ? `zum Dienstgrad ${h.to_title}` : 'auf „kein Dienstgrad“'}
            </div>
            <div className="row-sub">
              {formatDate(h.changed_at.slice(0, 10))}
              {h.from_title && ` · vorher ${h.from_title}`} · {num(h.points)} Punkte
              {showBy && h.changed_by_name && ` · von ${h.changed_by_name}`}
            </div>
            {h.reason && <div className="row-sub">{h.reason}</div>}
          </div>
        </li>
      ))}
    </ul>
  );
}

/** „Meins → Dienstgrad“: eigener Dienstgrad, Dienstgrade mit Schwellen und eigener Verlauf. */
export default function Rang() {
  const { data, error, loading, reload } = useAsync(() => Promise.all([api.me(), api.ranks(), api.myRankHistory()]));

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [me, ranks, history] = data!;

  return (
    <div>
      <RankProgress me={me} />
      <h2 className="section-title">Dienstgrade</h2>
      {ranks.length === 0 ? (
        <Empty>Es sind noch keine Dienstgrade angelegt.</Empty>
      ) : (
        <ul className="card-list rank-list">
          {ranks.map((r) => (
            <li key={r.id} className={`card row ${me.rank?.id === r.id ? 'rank-mine' : ''}`}>
              <div className="row-main">
                <div className="row-title">{r.title}</div>
                {me.rank?.id === r.id && <div className="row-sub">Dein Dienstgrad</div>}
              </div>
              <div className="row-value">ab {num(r.min_points)}</div>
            </li>
          ))}
        </ul>
      )}
      <p className="muted small">Wer die Punkte erreicht hat, wird von den Spießen offiziell befördert.</p>
      <h2 className="section-title">Mein Verlauf</h2>
      <RankHistoryList entries={history} />
    </div>
  );
}
