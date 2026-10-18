// Push-Benachrichtigungen im Browser: Abo beim Push-Dienst anlegen und beim Server hinterlegen.
import { api, config, type PushSubscriptionPrefs } from './api';
import { detectPlatform, isStandalone } from './install';

/** Kann dieser Browser überhaupt Web Push? */
export function browserSupportsPush(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/** iPhone/iPad: Web Push gibt es nur in der installierten App (ab iOS 16.4). */
export function needsInstallForPush(): boolean {
  return detectPlatform() === 'ios' && !isStandalone();
}

function keyToBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const pad = '='.repeat((4 - (base64url.length % 4)) % 4);
  const raw = atob((base64url + pad).replace(/-/g, '+').replace(/_/g, '/'));
  const bytes = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
  return bytes;
}

async function registration(): Promise<ServiceWorkerRegistration> {
  const reg = await navigator.serviceWorker.getRegistration(config!.appUrl);
  if (reg) return reg;
  // Normalerweise registriert main.tsx den Service Worker schon beim Laden.
  return navigator.serviceWorker.register(config!.swUrl, { scope: config!.appUrl });
}

export async function currentSubscription(): Promise<PushSubscription | null> {
  if (!browserSupportsPush()) return null;
  const reg = await registration();
  return reg.pushManager.getSubscription();
}

/**
 * Fragt die Erlaubnis ab und abonniert. Muss direkt aus einem Tipp aufgerufen werden
 * (iOS erlaubt die Abfrage nur nach einer Nutzeraktion).
 */
export async function enablePush(publicKey: string): Promise<{ sub: PushSubscription; prefs: PushSubscriptionPrefs }> {
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error(
      permission === 'denied'
        ? 'Benachrichtigungen sind blockiert. Bitte in den Einstellungen des Handys bzw. Browsers erlauben.'
        : 'Benachrichtigungen wurden nicht erlaubt.',
    );
  }
  const reg = await registration();
  let sub = await reg.pushManager.getSubscription();
  // Ein Abo mit einem anderen Server-Schlüssel (z. B. nach Neuinstallation des Plugins) erneuern.
  if (sub && !sameKey(sub, publicKey)) {
    await sub.unsubscribe();
    sub = null;
  }
  if (!sub) {
    sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(publicKey) });
  }
  const prefs = await api.pushSubscribe(sub.toJSON());
  return { sub, prefs };
}

function sameKey(sub: PushSubscription, publicKey: string): boolean {
  const key = sub.options?.applicationServerKey;
  if (!key) return true;
  const a = new Uint8Array(key);
  const b = keyToBytes(publicKey);
  return a.length === b.length && a.every((v, i) => v === b[i]);
}

/** Auf diesem Gerät ausschalten (Server und Browser). Fehler beim Server werden ignoriert. */
export async function disablePush(): Promise<void> {
  const sub = await currentSubscription();
  if (!sub) return;
  await api.pushUnsubscribe(sub.endpoint).catch(() => undefined);
  await sub.unsubscribe().catch(() => false);
}

/**
 * Beim Start: Ein vorhandenes Abo dem angemeldeten Konto zuordnen (z. B. nach
 * einem Kontowechsel auf dem Gerät oder wenn der Server es verloren hat).
 * Einstellungen eines bestehenden Abos bleiben erhalten.
 */
export async function syncPush(): Promise<void> {
  if (!config?.user || !browserSupportsPush() || Notification.permission !== 'granted') return;
  const sub = await currentSubscription();
  if (sub) await api.pushSubscribe(sub.toJSON());
}
