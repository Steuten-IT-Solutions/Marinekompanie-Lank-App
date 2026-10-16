import { navigate } from '../router';
import Punkte from './Punkte';
import Strafen from './Strafen';

/** „Meins“: eigene Punkte und Strafen, umschaltbar. */
export default function Meins({ sub }: { sub?: string }) {
  const active = sub === 'strafen' ? 'strafen' : 'punkte';
  return (
    <div>
      <nav className="segmented" aria-label="Meine Daten">
        {(
          [
            ['punkte', 'Punkte'],
            ['strafen', 'Strafen'],
          ] as const
        ).map(([id, label]) => (
          <button
            key={id}
            className={active === id ? 'active' : ''}
            onClick={() => navigate(`meins/${id}`)}
            aria-current={active === id ? 'page' : undefined}
          >
            {label}
          </button>
        ))}
      </nav>
      {active === 'punkte' ? <Punkte /> : <Strafen />}
    </div>
  );
}
