import { devices, expect, test, type Page } from '@playwright/test';

// Wartungsmodus (Issue #56): Admin schaltet ein, Mitglied sieht die Wartungsseite, offline
// gespeicherte Daten bleiben; nach dem Ende geht es mit „Erneut versuchen“ normal weiter.

const PASSWORD = process.env.MKL_TEST_PASSWORD ?? 'test1234';
const ADMIN_PASSWORD = process.env.MKL_ADMIN_PASSWORD ?? 'admin';
// Zeichen wie < und & erscheinen als Text, der Zeilenumbruch bleibt.
const REASON = 'Neue Version wird eingespielt.\nGegen 20 Uhr geht es weiter – Punkte < 5 & Strafen bleiben.';

async function login(page: Page, user: string, password: string) {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.getByRole('link', { name: 'Anmelden' }).click();
  await expect(page).toHaveURL(/wp-login\.php/);
  // WordPress setzt nach dem Laden den Fokus auf „Benutzername“ (wp_attempt_focus).
  await page.waitForFunction(() => document.activeElement?.id === 'user_login');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', password);
  await expect(page.locator('#user_login')).toHaveValue(user);
  await page.click('#wp-submit');
  await expect(page).toHaveURL(/\/app\/?(#.*)?$/);
  await expect(page.locator('.topbar')).toBeVisible();
}

/** Adressen im Daten-Cache des Service Workers. */
async function cachedUrls(page: Page): Promise<string[]> {
  return page.evaluate(async () => {
    const cache = await caches.open('mkl-data-v1');
    return (await cache.keys()).map((r) => r.url);
  });
}

/** Schaltet die Wartung über die API aus (Aufräumen, falls der Test vorher scheitert). */
async function forceOff(page: Page) {
  await page.evaluate(async () => {
    const cfg = window.MKL_CONFIG!;
    await fetch(cfg.restUrl + 'admin/maintenance', {
      method: 'POST',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ on: false }),
    });
  });
}

test('Wartungsmodus: Admin schaltet ein, Mitglied sieht die Wartungsseite, offline bleiben die Daten', async ({ page, context, browser }, testInfo) => {
  // Mitglied: Daten laden, damit sie offline im Service Worker liegen.
  await login(page, 'mariner1', PASSWORD);
  await page.waitForFunction(() => !!navigator.serviceWorker?.controller);
  await page.goto('/app/#/meins');
  await page.reload();
  await expect(page.locator('.stat-value').first()).toBeVisible();
  await expect.poll(async () => (await cachedUrls(page)).some((u) => u.includes('/me/points'))).toBe(true);
  const before = await cachedUrls(page);

  // Admin in einem eigenen Browser-Kontext (eigene Anmeldung).
  const { defaultBrowserType: _ignored, ...iphone } = devices['iPhone 13'];
  const adminContext = await browser.newContext({ ...iphone, baseURL: testInfo.project.use.baseURL, locale: 'de-DE', timezoneId: 'Europe/Berlin' });
  const admin = await adminContext.newPage();
  try {
    await login(admin, 'admin', ADMIN_PASSWORD);
    await admin.getByRole('button', { name: 'Spieß' }).click();
    await admin.locator('.segmented').getByRole('button', { name: 'Einrichtung' }).click();
    await admin.locator('.setup-list .row-title').getByText('Wartungsmodus', { exact: true }).click();

    const reasonField = admin.getByLabel('Begründung (optional)');
    await reasonField.fill(REASON);
    await expect(admin.locator('.maintenance-counter')).toHaveText(`${REASON.length}/500`);
    await expect(reasonField).toHaveAttribute('maxlength', '500');

    let dialogText = '';
    admin.once('dialog', (d) => {
      dialogText = d.message();
      void d.accept();
    });
    await admin.getByRole('switch', { name: 'Wartungsmodus' }).click();
    await expect(admin.getByRole('switch', { name: 'Wartungsmodus' })).toBeChecked();
    await expect(admin.locator('.toast')).toHaveText('Wartungsmodus eingeschaltet');
    expect(dialogText).toContain('Alle außer Admins können die App dann nicht mehr nutzen.');

    // Admin nutzt die App weiter, mit rotem Hinweis auf jeder Seite.
    const banner = admin.locator('.maintenance-banner');
    await expect(banner).toContainText('Wartungsmodus aktiv – nur Admins können die App nutzen');
    await expect(banner.locator('.maintenance-reason')).toContainText('Neue Version wird eingespielt.');
    await admin.getByRole('button', { name: 'Termine' }).click();
    await expect(banner).toBeVisible();
    await admin.reload();
    await expect(admin.locator('.maintenance-banner')).toBeVisible();
    await expect(admin.locator('.tabbar')).toBeVisible();

    // Mitglied: Wartungsseite mit Begründung (Zeilenumbruch bleibt, Sonderzeichen als Text).
    await page.reload();
    await expect(page.getByText('Die App wird gerade gewartet. Bitte versuche es später noch einmal.')).toBeVisible();
    const reason = page.locator('.maintenance-page .maintenance-reason');
    expect(await reason.innerText()).toBe(REASON);
    await expect(page.locator('.maintenance-page img.maintenance-logo')).toBeVisible();
    await expect(page.getByRole('link', { name: /Als Admin anmelden/ })).toBeVisible();
    await expect(page.locator('.tabbar')).toHaveCount(0);

    // API und Dokumente antworten mit 503; der Cache wird dabei nicht überschrieben.
    const status = await page.evaluate(async () => {
      const cfg = window.MKL_CONFIG!;
      const res = await fetch(cfg.restUrl + 'me/points', { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' });
      return { status: res.status, code: ((await res.json()) as { code: string }).code };
    });
    expect(status).toEqual({ status: 503, code: 'mkl_maintenance' });
    expect(await cachedUrls(page)).toEqual(expect.arrayContaining(before));

    // „Erneut versuchen“ während der Wartung: bleibt auf der Wartungsseite.
    await page.getByRole('button', { name: 'Erneut versuchen' }).click();
    await expect(page.getByRole('status')).toHaveText('Die Wartung läuft noch.');

    // Gäste sehen die Wartungsseite ebenfalls, mit Anmeldung für Admins.
    const guestContext = await browser.newContext({ ...iphone, baseURL: testInfo.project.use.baseURL, locale: 'de-DE' });
    const guest = await guestContext.newPage();
    await guest.goto('/app/');
    await expect(guest.getByText('Die App wird gerade gewartet. Bitte versuche es später noch einmal.')).toBeVisible();
    await expect(guest.locator('.maintenance-reason')).toContainText('Punkte < 5 & Strafen bleiben.');
    await expect(guest.getByRole('link', { name: 'Als Admin anmelden' })).toBeVisible();
    await guestContext.close();

    // Offline-Fassung: Der Service Worker hat die normale App-Seite behalten (nicht die Wartungsseite)
    // und die gespeicherten Daten nicht angetastet. (Playwrights setOffline wirkt nicht auf Abrufe des
    // Service Workers, daher wird der Cache direkt geprüft.)
    const offlinePage = await page.evaluate(async () => {
      const res = await caches.match(new URL('/app/', location.href).href);
      return res ? await res.text() : '';
    });
    expect(offlinePage).toContain('"maintenance":{"on":false');
    expect(await cachedUrls(page)).toEqual(expect.arrayContaining(before));

    // Admin beendet die Wartung über den Hinweis.
    await admin.locator('.maintenance-banner').getByRole('button', { name: 'Beenden' }).click();
    await expect(admin.locator('.toast')).toHaveText('Wartungsmodus beendet');
    await expect(admin.locator('.maintenance-banner')).toHaveCount(0);

    // Mitglied: „Erneut versuchen“ → normale App mit den Daten.
    await page.getByRole('button', { name: 'Erneut versuchen' }).click();
    await expect(page.locator('.maintenance-page')).toHaveCount(0);
    await expect(page.locator('.topbar-sub')).toHaveText('Ahoi, Mariner1');
    await expect(page.locator('.tabbar')).toBeVisible();
    await expect(page.locator('.stat-value').first()).toBeVisible();
  } finally {
    await context.setOffline(false);
    await forceOff(admin).catch(() => undefined);
    await adminContext.close();
  }
});
