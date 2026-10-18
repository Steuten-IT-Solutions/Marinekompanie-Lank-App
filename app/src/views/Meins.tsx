import { navigate } from '../router';
import MeineAnwesenheit from './MeineAnwesenheit';
import Punkte from './Punkte';
import Rang from './Rang';
import Strafen from './Strafen';

/** „Meins“: eigene Punkte, Dienstgrad, Strafen und Anwesenheit, umschaltbar. */
export default function Meins({ sub }: { sub?: string }) {
  const active = sub === 'strafen' || sub === 'rang' || sub === 'anwesenheit' ? sub : 'punkte';
  return (
    <div>
      <nav className="segmented" aria-label="Meine Daten">
        {(
          [
            ['punkte', 'Punkte'],
            ['rang', 'Dienstgrad'],
            ['strafen', 'Strafen'],
            ['anwesenheit', 'Anwesenheit'],
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
      {active === 'punkte' ? (
        <Punkte />
      ) : active === 'rang' ? (
        <Rang />
      ) : active === 'anwesenheit' ? (
        <MeineAnwesenheit />
      ) : (
        <Strafen />
      )}
    </div>
  );
}
