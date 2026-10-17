import { api } from '../api';
import { formatDate, formatPoints, halvedNote } from '../format';
import { Empty, ErrorBox, Loading, StatCard, useAsync } from '../ui';

export default function Punkte() {
  const { data, error, loading, reload } = useAsync(() => Promise.all([api.me(), api.myPoints()]));

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [me, points] = data!;

  return (
    <div>
      <StatCard label="Meine Punkte" value={me.points.toLocaleString('de-DE')} tone="good" />
      {me.is_jungmariner && (
        <p className="muted small">Als Jungmariner erhältst du laut Satzung die halbe Punktzahl.</p>
      )}
      <h2 className="section-title">Verlauf</h2>
      {points.length === 0 ? (
        <Empty>Noch keine Punkte – das ändert sich beim nächsten Antreten!</Empty>
      ) : (
        <ul className="card-list">
          {points.map((p) => (
            <li key={p.id} className="card row">
              <div className="row-main">
                <div className="row-title">{p.reason}</div>
                <div className="row-sub">
                  {formatDate(p.date)}
                  {halvedNote(p)}
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
