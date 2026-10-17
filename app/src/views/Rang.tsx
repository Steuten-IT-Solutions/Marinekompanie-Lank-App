import { api, type Me, type RankHistoryEntry } from '../api';
import { formatDate } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, TitleBadges, useAsync } from '../ui';

const num = (n: number) => n.toLocaleString('de-DE');

/**
 * Eigener Rang mit Fortschritt zum nächsten Rang und eigene Titel.
 * Ohne angelegte Ränge werden nur die Titel angezeigt, ohne Titel dann gar nichts.
 */
export function RankProgress({ me, link = false }: { me: Me; link?: boolean }) {
  if (!me.rank && !me.next_rank) {
    if (me.titles.length === 0) return null;
    return (
      <div className="card rank-card">
        <div className="rank-label">{me.titles.length === 1 ? 'Mein Titel' : 'Meine Titel'}</div>
        <TitleBadges titles={me.titles} />
      </div>
    );
  }
  const base = me.rank?.min_points ?? 0;
  let progress = 1;
  let text: string;
  if (!me.next_rank) {
    text = 'Höchster Rang erreicht.';
  } else if ((me.points_to_next ?? 0) <= 0) {
    text = `Punkte für ${me.next_rank.title} erreicht – die Beförderung erfolgt durch die Spieße.`;
  } else {
    const span = me.next_rank.min_points - base;
    progress = span > 0 ? Math.min(1, Math.max(0, (me.points - base) / span)) : 0;
    text = `Noch ${num(me.points_to_next!)} ${me.points_to_next === 1 ? 'Punkt' : 'Punkte'} bis ${me.next_rank.title}`;
  }
  return (
    <div className={`card rank-card ${link ? 'clickable' : ''}`} onClick={link ? () => navigate('meins/rang') : undefined}>
      <div className="rank-label">Mein Rang</div>
      <div className="rank-title">{me.rank ? me.rank.title : 'Noch kein Rang'}</div>
      <TitleBadges titles={me.titles} />
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
      {link && <div className="rank-more">Rangliste und Verlauf ›</div>}
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
              {h.kind === 'befoerderung' ? 'Befördert' : 'Degradiert'} {h.to_title ? `zum Rang ${h.to_title}` : 'auf „kein Rang“'}
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

/** „Meins → Rang“: eigener Rang, Rangliste mit Schwellen und eigener Verlauf. */
export default function Rang() {
  const { data, error, loading, reload } = useAsync(() => Promise.all([api.me(), api.ranks(), api.myRankHistory()]));

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [me, ranks, history] = data!;

  return (
    <div>
      <RankProgress me={me} />
      <h2 className="section-title">Rangliste</h2>
      {ranks.length === 0 ? (
        <Empty>Es sind noch keine Ränge angelegt.</Empty>
      ) : (
        <ul className="card-list rank-list">
          {ranks.map((r) => (
            <li key={r.id} className={`card row ${me.rank?.id === r.id ? 'rank-mine' : ''}`}>
              <div className="row-main">
                <div className="row-title">{r.title}</div>
                {me.rank?.id === r.id && <div className="row-sub">Dein Rang</div>}
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
