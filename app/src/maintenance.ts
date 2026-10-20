// Wartungsmodus: Zustand aus window.MKL_CONFIG, aus /me und aus jeder 503-Antwort mit Code mkl_maintenance.
import { useSyncExternalStore } from 'react';

export interface MaintenanceState {
  on: boolean;
  /** Begründung (reiner Text, Zeilenumbrüche bleiben), leer = keine. */
  reason: string;
}

/** Höchstlänge der Begründung (wie MKL_Maintenance::MAX_REASON). */
export const MAX_MAINTENANCE_REASON = 500;
export const MAINTENANCE_CODE = 'mkl_maintenance';

// Bewusst ohne Import aus api.ts (die API meldet hierher), daher direkt aus window.
let state: MaintenanceState = normalize(window.MKL_CONFIG?.maintenance);
const listeners = new Set<() => void>();

function normalize(raw: Partial<MaintenanceState> | null | undefined): MaintenanceState {
  return { on: !!raw?.on, reason: typeof raw?.reason === 'string' ? raw.reason : '' };
}

export function getMaintenance(): MaintenanceState {
  return state;
}

export function setMaintenance(next: Partial<MaintenanceState> | null | undefined): void {
  const n = normalize(next);
  if (n.on === state.on && n.reason === state.reason) return;
  state = n;
  listeners.forEach((l) => l());
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function useMaintenance(): MaintenanceState {
  return useSyncExternalStore(subscribe, getMaintenance);
}

/** Erkennt die Antwort der Wartungssperre (503, Code mkl_maintenance) und merkt sich den Zustand. */
export function noteMaintenanceResponse(status: number, data: unknown): boolean {
  const d = data as { code?: unknown; data?: { reason?: unknown } } | null;
  if (status !== 503 || !d || d.code !== MAINTENANCE_CODE) return false;
  setMaintenance({ on: true, reason: typeof d.data?.reason === 'string' ? d.data.reason : '' });
  return true;
}

/**
 * Fragt den Server, ob die Wartung noch läuft.
 * @returns 'on' (läuft noch), 'off' (vorbei) oder 'offline' (keine Verbindung).
 */
export async function checkMaintenance(): Promise<'on' | 'off' | 'offline'> {
  const cfg = window.MKL_CONFIG;
  if (!cfg) return 'off';
  let res: Response;
  try {
    res = await fetch(cfg.restUrl.replace(/\/$/, '') + '/me', {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {},
    });
  } catch {
    return 'offline';
  }
  const data = (await res.json().catch(() => null)) as { maintenance?: MaintenanceState } | null;
  if (noteMaintenanceResponse(res.status, data)) return 'on';
  if (res.ok && data?.maintenance?.on) {
    setMaintenance(data.maintenance);
    return 'on';
  }
  // 200 ohne Wartung oder 401 (Gast): vorbei.
  return 'off';
}

/** Für direkte Abrufe (Dokumente unter /app/dokument/): 503 der Wartungssperre erkennen. */
export async function noteMaintenanceFetch(res: Response): Promise<boolean> {
  if (res.status !== 503) return false;
  const data: unknown = await res.clone().json().catch(() => null);
  return noteMaintenanceResponse(res.status, data);
}
