// Zugriff auf die REST-API des WordPress-Plugins (Cookie-Login + REST-Nonce).

/** Rolle eines angemeldeten Kontos. Gäste (ohne Anmeldung) haben keine Rolle und kein Konto. */
export type Level = 'admin' | 'spiess' | 'mitglied' | 'anwaerter' | 'ohne';

export const LEVEL_LABELS: Record<Level, string> = {
  admin: 'Admin',
  spiess: 'Spieß',
  mitglied: 'Mitglied',
  anwaerter: 'Anwärter',
  ohne: 'Ohne Rolle',
};

/** Nur Mitglieder (inkl. Spieß/Admin) haben Punkte, Strafen, Dienstgrad und Titel. */
export function isMemberLevel(level: Level): boolean {
  return level !== 'ohne' && level !== 'anwaerter';
}

export interface AppUser {
  id: number;
  name: string;
  login: string;
  level: Level;
  isMember: boolean;
  /** Interner Kalender (Anwärter und Mitglieder). */
  canSeeCalendar: boolean;
  isSpiess: boolean;
  canCreateUsers: boolean;
  canManageEvents: boolean;
  canManageDocs: boolean;
  canPromoteUsers: boolean;
  /** Konten bearbeiten (Name, Benutzername, E-Mail, Passwort) – Admins. */
  canEditUsers: boolean;
}

export interface AppConfig {
  restUrl: string;
  /** Leer für Gäste ohne Anmeldung. */
  nonce: string;
  ajaxUrl: string;
  loginUrl: string;
  logoutUrl: string;
  appUrl: string;
  siteUrl: string;
  swUrl: string;
  /** null = Gast ohne Anmeldung. */
  user: AppUser | null;
}

declare global {
  interface Window {
    MKL_CONFIG?: AppConfig;
  }
}

export const config: AppConfig | undefined = window.MKL_CONFIG;

export interface Me {
  id: number;
  name: string;
  is_spiess: boolean;
  is_jungmariner: boolean;
  /** Kann halbe Punkte enthalten (Jungmariner), z. B. 2.5. */
  points: number;
  open_cents: number;
  /** Zugewiesener Dienstgrad (setzen die Spieße), null = noch kein Dienstgrad. */
  rank: RankRef | null;
  next_rank: RankRef | null;
  /** Fehlende Punkte bis zum nächsten Dienstgrad; ≤ 0 = Schwelle erreicht, null = höchster Dienstgrad. */
  points_to_next: number | null;
  /** Ehrentitel und Ämter, z. B. Kapitän (vergeben die Spieße, zusätzlich zum Dienstgrad). */
  titles: TitleRef[];
}

export interface TitleRef {
  id: number;
  title: string;
}

export interface Title extends TitleRef {
  sort: number;
  /** Anzahl der Mitglieder mit diesem Titel. */
  member_count: number;
}

export interface RankRef {
  id: number;
  title: string;
  min_points: number;
}

export interface Rank extends RankRef {
  active: boolean;
  /** Nur für Spieße (?all=1). */
  member_count?: number;
}

export interface RankStatus {
  rank: RankRef | null;
  next_rank: RankRef | null;
  points_to_next: number | null;
  reachable_rank: RankRef | null;
  threshold_reached_since: string | null;
  below_current_threshold: boolean;
}

export interface Promotion extends RankStatus {
  user_id: number;
  name: string;
  points: number;
  titles: TitleRef[];
}

export interface RankHistoryEntry {
  id: number;
  from_title: string | null;
  to_title: string | null;
  kind: 'befoerderung' | 'degradierung';
  points: number;
  reason: string;
  changed_at: string;
  changed_by_name: string | null;
}

export type DocKind = 'pdf' | 'bild' | 'datei';
export type DocVisibility = 'mitglieder' | 'spiess';

export interface DocumentInfo {
  id: number;
  title: string;
  category_id: number | null;
  /** Stand (YYYY-MM-DD). */
  date: string;
  /** mitglieder = alle Mitglieder, spiess = nur Spieße und Admins. */
  visibility: DocVisibility;
  ext: string;
  /** pdf/bild werden in der App angezeigt, datei nur heruntergeladen. */
  kind: DocKind;
  mime: string;
  size: number;
  version: string;
  /** Geschützte Adresse unter /app/dokument/ (offline über den Service Worker). */
  url: string;
  updated_at: string;
}

export interface DocCategory {
  id: number;
  title: string;
  sort: number;
}

export interface DocumentMeta {
  title: string;
  category_id: number | null;
  date: string;
  visibility: DocVisibility;
}

export interface PointEntry {
  id: number;
  user_id: number;
  /** Gutgeschriebene Punkte; bei halved die Hälfte von full_points. */
  points: number;
  /** Vergebene Punktzahl vor der Halbierung. */
  full_points: number;
  /** Jungmariner: es zählt nur die halbe Punktzahl. */
  halved: boolean;
  reason: string;
  catalog_id: number | null;
  date: string;
  created_by_name: string | null;
}

export interface Penalty {
  id: number;
  user_id: number;
  amount_cents: number;
  reason: string;
  date: string;
  status: 'open' | 'paid';
  paid_at: string | null;
  created_by_name: string | null;
}

export interface CalendarEvent {
  id: string;
  title: string;
  start: string;
  end: string;
  all_day: boolean;
  location: string;
  description: string;
}

export interface CatalogItem {
  id: number;
  title: string;
  points: number;
  active: boolean;
  sort: number;
}

export interface PublicEvent extends Omit<CalendarEvent, 'id'> {
  id: number;
  start_date: string;
  start_time: string | null;
  end_date: string | null;
  end_time: string | null;
  url: string;
}

export interface EventInput {
  title: string;
  start_date: string;
  start_time: string;
  end_date: string;
  end_time: string;
  location: string;
  description: string;
  url: string;
}

export interface NewsItem {
  id: number;
  title: string;
  date: string;
  excerpt: string;
  image: string | null;
  link: string;
  content?: string;
}

export interface GalleryAlbumSummary {
  id: number;
  title: string;
  count: number;
  intern_count: number;
  cover: string | null;
}

export interface GalleryYear {
  year: string;
  albums: GalleryAlbumSummary[];
}

export interface GalleryPhoto {
  id: number;
  thumb: string;
  large: string;
  width: number;
  height: number;
  intern: boolean;
  name: string;
}

export interface GalleryAlbum {
  id: number;
  title: string;
  year: string;
  photos: GalleryPhoto[];
}

export interface MemberSummary {
  id: number;
  name: string;
  level: Level;
  is_spiess: boolean;
  is_jungmariner: boolean;
  points: number;
  open_cents: number;
  rank: RankRef | null;
  titles: TitleRef[];
}

export interface MemberDetail extends Omit<MemberSummary, 'rank'> {
  point_list: PointEntry[];
  penalties: Penalty[];
  rank_status: RankStatus;
  rank_history: RankHistoryEntry[];
}

export interface CreatedUser {
  id: number;
  name: string;
  username: string;
  email: string;
  level: Level;
  /** Startpasswort – wird nur in dieser einen Antwort geliefert. */
  password: string;
  points: number;
  rank: RankRef | null;
  is_jungmariner: boolean;
}

/** Eine Person bei „Mehrere anlegen“; Rolle und Datum des Anfangsbestands gelten für alle. */
export type BulkUserInput = Omit<NewUserInput, 'level' | 'initial_points_date'>;

/** Höchstzahl für „Mehrere anlegen“ (wie MKL_REST::MAX_BULK_USERS). */
export const MAX_BULK_USERS = 50;

/** Konto für „Mitglieder verwalten“ (nur Admins). */
export interface Account {
  id: number;
  name: string;
  username: string;
  email: string;
  level: Level;
  is_self: boolean;
  /** Darf der angemeldete Admin dieses Konto bearbeiten? */
  editable: boolean;
}

export interface NewUserInput {
  name: string;
  username?: string;
  email?: string;
  initial_points?: number;
  initial_points_date?: string;
  rank_id?: number;
  jungmariner?: boolean;
  /** Standard auf dem Server: Anwärter. */
  level?: Exclude<Level, 'admin'>;
}

export class ApiError extends Error {
  constructor(message: string, public status: number) {
    super(message);
  }
}

let nonce = config?.nonce ?? '';

function toLogin(): never {
  window.location.href = config?.loginUrl ?? '/wp-login.php';
  throw new ApiError('Sitzung abgelaufen – bitte neu anmelden.', 401);
}

export async function refreshNonce(): Promise<void> {
  const sep = config!.ajaxUrl.includes('?') ? '&' : '?';
  const res = await fetch(`${config!.ajaxUrl}${sep}action=rest-nonce`, { credentials: 'same-origin' });
  const text = (await res.text()).trim();
  if (!res.ok || !text || text === '0' || text === '-1') {
    toLogin();
  }
  nonce = text;
}

function url(path: string, query?: Record<string, string>): string {
  const base = config!.restUrl.replace(/\/$/, '');
  let u = base + path;
  if (query) {
    const qs = new URLSearchParams(query).toString();
    u += (u.includes('?') ? '&' : '?') + qs;
  }
  return u;
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  query?: Record<string, string>,
): Promise<T> {
  return (await requestFull<T>(method, path, body, query)).data;
}

async function requestFull<T>(
  method: string,
  path: string,
  body?: unknown,
  query?: Record<string, string>,
  retry = true,
): Promise<{ data: T; headers: Headers }> {
  if (!config) {
    throw new ApiError('Die App muss über WordPress (/app/) geöffnet werden.', 0);
  }
  let res: Response;
  try {
    res = await fetch(url(path, query), {
      method,
      credentials: 'same-origin',
      headers: {
        ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
        ...(body instanceof Blob
          ? { 'Content-Type': body.type }
          : body !== undefined
            ? { 'Content-Type': 'application/json' }
            : {}),
      },
      body: body instanceof Blob ? body : body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError('Keine Verbindung. Bitte später erneut versuchen.', 0);
  }

  const data = await res.json().catch(() => null);

  if (res.status === 403 && retry && data?.code === 'rest_cookie_invalid_nonce') {
    await refreshNonce();
    return requestFull<T>(method, path, body, query, false);
  }
  if (res.status === 401 && config.user) {
    toLogin();
  }
  if (!res.ok) {
    throw new ApiError(data?.message ?? `Fehler ${res.status}`, res.status);
  }
  return { data: data as T, headers: res.headers };
}

export const api = {
  me: () => request<Me>('GET', '/me'),
  myPoints: () => request<PointEntry[]>('GET', '/me/points'),
  myPenalties: () => request<Penalty[]>('GET', '/me/penalties'),
  myRankHistory: () => request<RankHistoryEntry[]>('GET', '/me/rank-history'),
  calendar: () => request<CalendarEvent[]>('GET', '/calendar'),

  events: () => request<PublicEvent[]>('GET', '/events'),
  createEvent: (data: EventInput) => request<PublicEvent>('POST', '/events', data),
  updateEvent: (id: number, data: EventInput) => request<PublicEvent>('PATCH', `/events/${id}`, data),
  deleteEvent: (id: number) => request<{ deleted: boolean }>('DELETE', `/events/${id}`),

  news: async (page = 1) => {
    const { data, headers } = await requestFull<NewsItem[]>('GET', '/news', undefined, { page: String(page) });
    return { items: data, totalPages: Number(headers.get('X-WP-TotalPages') ?? 1) };
  },
  newsItem: (id: number) => request<NewsItem>('GET', `/news/${id}`),

  gallery: () => request<{ years: GalleryYear[] }>('GET', '/gallery'),
  galleryAlbum: (id: number) => request<GalleryAlbum>('GET', `/gallery/${id}`),

  catalog: (all = false) => request<CatalogItem[]>('GET', '/catalog', undefined, all ? { all: '1' } : undefined),
  createCatalogItem: (title: string, points: number) =>
    request<CatalogItem>('POST', '/catalog', { title, points }),
  updateCatalogItem: (id: number, data: Partial<Pick<CatalogItem, 'title' | 'points' | 'active' | 'sort'>>) =>
    request<CatalogItem>('PATCH', `/catalog/${id}`, data),
  deleteCatalogItem: (id: number) => request<{ deleted: boolean }>('DELETE', `/catalog/${id}`),

  members: (opts: { all?: boolean; anwaerter?: boolean } = {}) =>
    request<MemberSummary[]>('GET', '/members', undefined, {
      ...(opts.all ? { all: '1' } : {}),
      ...(opts.anwaerter ? { anwaerter: '1' } : {}),
    }),
  /** Anwärter zum Mitglied befördern (Spieß und Admin). */
  promote: (id: number) => request<{ id: number; level: Level }>('POST', `/members/${id}/promote`),
  setRole: (id: number, level: Exclude<Level, 'admin'>) =>
    request<{ id: number; level: Level }>('PATCH', `/members/${id}/role`, { level }),
  member: (id: number) => request<MemberDetail>('GET', `/members/${id}`),
  setJungmariner: (id: number, jungmariner: boolean) =>
    request<{ id: number; is_jungmariner: boolean }>('PATCH', `/members/${id}/jungmariner`, { jungmariner }),
  /** Ändert das eigene Passwort; danach gilt ein neues Anmelde-Cookie, daher Nonce erneuern. */
  changePassword: async (current_password: string, new_password: string) => {
    await request<{ changed: boolean }>('POST', '/me/password', { current_password, new_password });
    await refreshNonce();
  },
  createUser: (data: NewUserInput) => request<CreatedUser>('POST', '/users', data),
  /** Legt mehrere Konten mit derselben Rolle an; bei einem Fehler wird niemand angelegt. */
  createUsers: (data: { users: BulkUserInput[]; level: Exclude<Level, 'admin'>; initial_points_date?: string }) =>
    request<CreatedUser[]>('POST', '/users/bulk', data),
  accounts: () => request<Account[]>('GET', '/accounts'),
  updateAccount: (id: number, data: Partial<Pick<Account, 'name' | 'username' | 'email'>>) =>
    request<Account>('PATCH', `/accounts/${id}`, data),
  /** Neues Startpasswort; wird nur in dieser Antwort geliefert. */
  resetPassword: (id: number) =>
    request<{ id: number; name: string; username: string; password: string }>('POST', `/accounts/${id}/password`),

  givePoints: (data: { user_ids: number[]; points?: number; catalog_id?: number; reason?: string; date?: string }) =>
    request<PointEntry[]>('POST', '/points', data),
  deletePoints: (id: number) => request<{ deleted: boolean }>('DELETE', `/points/${id}`),

  givePenalty: (data: { user_ids: number[]; amount_cents: number; reason: string; date?: string }) =>
    request<Penalty[]>('POST', '/penalties', data),
  setPenaltyStatus: (id: number, status: 'open' | 'paid') =>
    request<Penalty>('PATCH', `/penalties/${id}`, { status }),
  deletePenalty: (id: number) => request<{ deleted: boolean }>('DELETE', `/penalties/${id}`),

  ranks: (all = false) => request<Rank[]>('GET', '/ranks', undefined, all ? { all: '1' } : undefined),
  createRank: (title: string, min_points: number) => request<Rank>('POST', '/ranks', { title, min_points }),
  updateRank: (id: number, data: Partial<Pick<Rank, 'title' | 'min_points' | 'active'>>) =>
    request<Rank>('PATCH', `/ranks/${id}`, data),
  deleteRank: (id: number) => request<{ deleted: boolean }>('DELETE', `/ranks/${id}`),
  promotions: () => request<Promotion[]>('GET', '/promotions'),

  titles: () => request<Title[]>('GET', '/titles'),
  createTitle: (title: string) => request<Title>('POST', '/titles', { title }),
  renameTitle: (id: number, title: string) => request<Title>('PATCH', `/titles/${id}`, { title }),
  deleteTitle: (id: number) => request<{ deleted: boolean }>('DELETE', `/titles/${id}`),
  /** Ersetzt die Titel eines Mitglieds; [] entfernt alle. */
  setTitles: (id: number, title_ids: number[]) =>
    request<{ titles: TitleRef[] }>('PUT', `/members/${id}/titles`, { title_ids }),
  /** Befördern/Degradieren; rank_id null = kein Dienstgrad. */
  setRank: (id: number, rank_id: number | null, reason: string) =>
    request<{ rank_status: RankStatus; rank_history: RankHistoryEntry[] }>('PUT', `/members/${id}/rank`, {
      rank_id,
      reason,
    }),

  documents: () => request<{ categories: DocCategory[]; documents: DocumentInfo[] }>('GET', '/documents'),
  /** Neues Dokument hochladen (Datei als Request-Body, Angaben als Query-Parameter). */
  createDocument: (file: File, meta: DocumentMeta) =>
    request<DocumentInfo>('POST', '/documents', new Blob([file], { type: 'application/octet-stream' }), {
      filename: file.name,
      title: meta.title,
      category_id: meta.category_id ? String(meta.category_id) : '',
      date: meta.date,
      visibility: meta.visibility,
    }),
  /** Neue Fassung: ersetzt nur die Datei. */
  replaceDocumentFile: (id: number, file: File) =>
    request<DocumentInfo>('POST', `/documents/${id}/file`, new Blob([file], { type: 'application/octet-stream' }), {
      filename: file.name,
    }),
  updateDocument: (id: number, data: Partial<DocumentMeta>) => request<DocumentInfo>('PATCH', `/documents/${id}`, data),
  deleteDocument: (id: number) => request<{ deleted: boolean }>('DELETE', `/documents/${id}`),
  createDocCategory: (title: string) => request<DocCategory>('POST', '/document-categories', { title }),
  updateDocCategory: (id: number, title: string) => request<DocCategory>('PATCH', `/document-categories/${id}`, { title }),
  deleteDocCategory: (id: number) => request<{ deleted: boolean }>('DELETE', `/document-categories/${id}`),
};
