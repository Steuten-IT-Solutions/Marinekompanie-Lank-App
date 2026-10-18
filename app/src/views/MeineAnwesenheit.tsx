import { api, ATTENDANCE_LABELS } from '../api';
import { formatDate } from '../format';
import { Empty, ErrorBox, Loading, StatCard, useAsync } from '../ui';

/** „Meins → Anwesenheit“: nur die eigene Anwesenheit. */
export default function MeineAnwesenheit() {
  const { data, error, loading, reload } = useAsync(api.myAttendance);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;
  const present = list.filter((a) => a.status === 'anwesend').length;

  return (
    <div>
      <StatCard label={`von ${list.length} ${list.length === 1 ? 'Termin' : 'Terminen'} anwesend`} value={String(present)} tone="good" />
      <h2 className="section-title">Verlauf</h2>
      {list.length === 0 ? (
        <Empty>Noch keine Anwesenheit erfasst.</Empty>
      ) : (
        <ul className="card-list">
          {list.map((a) => (
            <li key={a.list_id} className="card row">
              <div className="row-main">
                <div className="row-title">{a.title}</div>
                <div className="row-sub">
                  {formatDate(a.date)}
                  {a.updated_by_name && ` · von ${a.updated_by_name}`}
                </div>
              </div>
              <span className={`badge badge-att-${a.status}`}>{ATTENDANCE_LABELS[a.status]}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
