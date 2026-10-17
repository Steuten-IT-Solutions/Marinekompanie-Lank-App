// Zugriff auf die REST-API des WordPress-Plugins (Cookie-Login + REST-Nonce).

export type Level = 'admin' | 'spiess' | 'mitglied' | 'gast';

export const LEVEL_LABELS: Record<Level, string> = {
  admin: 'Admin',
  spiess: 'Spieß',
  mitglied: 'Mitglied',
  gast: 'Gast',
};

export interface AppUser {
  id: number;
  name: string;
  login: string;
  level: Level;
  isMember: boolean;
  isSpiess: boolean;
  canCreateUsers: boolean;
  canManageEvents: boolean;
  canPromoteUsers: boolean;
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
  points: number;
  open_cents: number;
}

export interface PointEntry {
  id: number;
  user_id: number;
  points: number;
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
  points: number;
  open_cents: number;
}

export interface MemberDetail extends MemberSummary {
  point_list: PointEntry[];
  penalties: Penalty[];
}

export interface CreatedUser {
  id: number;
  name: string;
  username: string;
  email: string;
  /** Startpasswort – wird nur in dieser einen Antwort geliefert. */
  password: string;
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
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
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

  members: (all = false) => request<MemberSummary[]>('GET', '/members', undefined, all ? { all: '1' } : undefined),
  setRole: (id: number, level: Exclude<Level, 'admin'>) =>
    request<{ id: number; level: Level }>('PATCH', `/members/${id}/role`, { level }),
  member: (id: number) => request<MemberDetail>('GET', `/members/${id}`),
  /** Ändert das eigene Passwort; danach gilt ein neues Anmelde-Cookie, daher Nonce erneuern. */
  changePassword: async (current_password: string, new_password: string) => {
    await request<{ changed: boolean }>('POST', '/me/password', { current_password, new_password });
    await refreshNonce();
  },
  createUser: (data: { name: string; username?: string; email?: string }) =>
    request<CreatedUser>('POST', '/users', data),

  givePoints: (data: { user_ids: number[]; points?: number; catalog_id?: number; reason?: string; date?: string }) =>
    request<PointEntry[]>('POST', '/points', data),
  deletePoints: (id: number) => request<{ deleted: boolean }>('DELETE', `/points/${id}`),

  givePenalty: (data: { user_ids: number[]; amount_cents: number; reason: string; date?: string }) =>
    request<Penalty[]>('POST', '/penalties', data),
  setPenaltyStatus: (id: number, status: 'open' | 'paid') =>
    request<Penalty>('PATCH', `/penalties/${id}`, { status }),
  deletePenalty: (id: number) => request<{ deleted: boolean }>('DELETE', `/penalties/${id}`),
};
