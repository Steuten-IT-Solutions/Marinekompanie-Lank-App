// Zugriff auf die REST-API des WordPress-Plugins (Cookie-Login + REST-Nonce).

export interface AppConfig {
  restUrl: string;
  nonce: string;
  ajaxUrl: string;
  logoutUrl: string;
  appUrl: string;
  siteUrl: string;
  swUrl: string;
  user: { id: number; name: string; login: string; isSpiess: boolean; canCreateUsers: boolean };
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

export interface MemberSummary {
  id: number;
  name: string;
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
  // /app/ leitet nicht angemeldete Benutzer serverseitig zum Login weiter.
  window.location.href = config?.appUrl ?? '/app/';
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
  retry = true,
): Promise<T> {
  if (!config) {
    throw new ApiError('Die App muss über WordPress (/app/) geöffnet werden.', 0);
  }
  let res: Response;
  try {
    res = await fetch(url(path, query), {
      method,
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': nonce,
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
    return request<T>(method, path, body, query, false);
  }
  if (res.status === 401) {
    toLogin();
  }
  if (!res.ok) {
    throw new ApiError(data?.message ?? `Fehler ${res.status}`, res.status);
  }
  return data as T;
}

export const api = {
  me: () => request<Me>('GET', '/me'),
  myPoints: () => request<PointEntry[]>('GET', '/me/points'),
  myPenalties: () => request<Penalty[]>('GET', '/me/penalties'),
  calendar: () => request<CalendarEvent[]>('GET', '/calendar'),

  catalog: (all = false) => request<CatalogItem[]>('GET', '/catalog', undefined, all ? { all: '1' } : undefined),
  createCatalogItem: (title: string, points: number) =>
    request<CatalogItem>('POST', '/catalog', { title, points }),
  updateCatalogItem: (id: number, data: Partial<Pick<CatalogItem, 'title' | 'points' | 'active' | 'sort'>>) =>
    request<CatalogItem>('PATCH', `/catalog/${id}`, data),

  members: () => request<MemberSummary[]>('GET', '/members'),
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
