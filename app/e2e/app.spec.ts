import { devices, expect, test, type Page } from '@playwright/test';

const PASSWORD = process.env.MKL_TEST_PASSWORD ?? 'test1234';
const SHOTS = process.env.MKL_SCREENSHOT_DIR;

async function login(page: Page, user: string, password = PASSWORD) {
  await page.context().clearCookies();
  await page.goto('/app/');
  // Gäste sehen die öffentliche Ansicht; angemeldet wird über den Knopf in der Kopfleiste.
  await page.getByRole('link', { name: 'Anmelden' }).click();
  await expect(page).toHaveURL(/wp-login\.php/);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');
  await expect(page).toHaveURL(/\/app\/?(#.*)?$/);
  await expect(page.locator('.topbar')).toBeVisible();
}

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png` });
}

test.describe.configure({ mode: 'serial' });

test('Mitglied sieht Termine, aber keinen Spieß-Bereich', async ({ page }) => {
  await login(page, 'mariner1');
  await expect(page.getByText('Kompanieabend')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Spieß' })).toHaveCount(0);
  await page.getByText('Kompanieabend').click();
  await expect(page.getByText('Antreten in Uniform.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'In meinen Kalender' })).toBeVisible();
  await page.waitForTimeout(300); // Einblend-Animation abwarten
  await shot(page, '1-termine');
  if (SHOTS) {
    await page.emulateMedia({ colorScheme: 'dark' });
    await shot(page, '1-termine-dunkel');
    await page.emulateMedia({ colorScheme: 'light' });
  }

  // Direkter Aufruf des Spieß-Bereichs fällt auf Termine zurück.
  await page.goto('/app/#/spiess');
  await expect(page.locator('.topbar-title')).toHaveText('Termine');
});

test('Anwärter sieht interne Termine, aber weder Meins noch Spieß-Bereich', async ({ page }) => {
  await login(page, 'anwaerter');
  // Kompanieabend steht nur im internen Kalender, nicht in der öffentlichen Liste.
  await expect(page.getByText('Kompanieabend')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Spieß' })).toHaveCount(0);
  await page.goto('/app/#/konto');
  await expect(page.getByText('Rolle: Anwärter')).toBeVisible();
});

test('Spieß befördert Anwärter, Admin stuft wieder zurück', async ({ page }) => {
  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.getByText('Anwaerter').click();
  await expect(page.getByText('Rolle: Anwärter')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Punkte vergeben' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Zum Mitglied befördern' }).click();
  await expect(page.locator('.toast')).toHaveText('Zum Mitglied befördert');
  await expect(page.getByRole('button', { name: 'Punkte vergeben' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Zum Mitglied befördern' })).toHaveCount(0);

  await login(page, 'admin', 'admin');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.getByText('Anwaerter').click();
  await page.getByRole('button', { name: 'Anwärter', exact: true }).click();
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Anwärter');
});

test('Spieß legt Katalog an, vergibt Punkte und Strafen', async ({ page }) => {
  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await shot(page, '5-spiess-mitglieder');

  await page.getByRole('button', { name: 'Katalog' }).click();
  await page.getByPlaceholder('z. B. Teilnahme Schützenfest').fill('Teilnahme Schützenfest');
  await page.locator('.field.narrow input').fill('5');
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(page.locator('.toast')).toHaveText('Vorlage angelegt');

  await page.getByRole('button', { name: 'Punkte +' }).click();
  await page.locator('select').selectOption({ label: 'Teilnahme Schützenfest (+5)' });
  await page.getByLabel('Mariner1').check();
  await page.getByLabel('Mariner2').check();
  await shot(page, '6-spiess-punkte');
  await page.getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.toast')).toHaveText('+5 Punkte an 2 Mitglieder vergeben');

  await page.getByRole('button', { name: 'Strafe +' }).click();
  await page.getByPlaceholder('2,50').fill('2,50');
  await page.getByPlaceholder('z. B. Zu spät beim Antreten').fill('Zu spät beim Antreten');
  await page.getByLabel('Mariner1').check();
  await page.getByRole('button', { name: 'Strafe vergeben' }).click();
  await expect(page.locator('.toast')).toContainText('2,50');

  await page.getByPlaceholder('2,50').fill('5');
  await page.getByPlaceholder('z. B. Zu spät beim Antreten').fill('Mütze vergessen');
  await page.getByLabel('Mariner1').check();
  await page.getByRole('button', { name: 'Strafe vergeben' }).click();
  await expect(page.locator('.toast')).toContainText('5,00');

  // „Mütze vergessen“ als bezahlt markieren.
  await page.getByRole('button', { name: 'Mitglieder' }).click();
  await page.locator('.row', { hasText: 'Mariner1' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mariner1');
  await page.locator('.row', { hasText: 'Mütze vergessen' }).getByRole('button', { name: 'Bezahlt' }).click();
  await expect(page.locator('.toast')).toHaveText('Als bezahlt markiert');
  await expect(page.locator('.stat', { hasText: 'Offen' }).locator('.stat-value')).toContainText('2,50');
  await shot(page, '7-spiess-mitglied');
});

test('Admin legt ein Mitglied an, das sich mit dem Startpasswort anmelden kann', async ({ page }) => {
  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByPlaceholder('Vorname Nachname').fill('Neuer Mariner');
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  await expect(page.getByText('Neuer Mariner ist angelegt')).toBeVisible();
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();
  expect(username).toMatch(/^neuer\.mariner\d*$/);
  expect(password).toHaveLength(12);
  await shot(page, '8-mitglied-angelegt');

  await login(page, username, password);
  await expect(page.locator('.topbar-sub')).toHaveText('Ahoi, Neuer Mariner');
  await expect(page.getByRole('button', { name: 'Spieß' })).toHaveCount(0);
});

test('Spieß ohne Admin-Rechte sieht keine Funktion zum Anlegen', async ({ page }) => {
  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await expect(page.locator('.stat')).toBeVisible();
  await expect(page.getByRole('button', { name: '+ Mitglied anlegen' })).toHaveCount(0);
});

test('Mitglied 1 sieht eigene Punkte und Strafen', async ({ page }) => {
  await login(page, 'mariner1');
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.stat-value')).toHaveText('5');
  await expect(page.getByText('Teilnahme Schützenfest')).toBeVisible();
  await shot(page, '2-punkte');

  await page.locator('.segmented').getByRole('button', { name: 'Strafen' }).click();
  await expect(page.locator('.stat-value')).toContainText('2,50');
  await expect(page.getByText('Zu spät beim Antreten')).toBeVisible();
  await expect(page.getByText('Mütze vergessen')).toBeVisible();
  await shot(page, '3-strafen');
});

test('Mitglied 2 sieht keine fremden Strafen und keine Verwaltungsdaten', async ({ page }) => {
  await login(page, 'mariner2');
  await page.getByRole('button', { name: 'Meins' }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Strafen' }).click();
  await expect(page.locator('.stat-value')).toContainText('0,00');
  await expect(page.getByText('Zu spät beim Antreten')).toHaveCount(0);

  const status = await page.evaluate(async () => {
    const cfg = window.MKL_CONFIG!;
    const res = await fetch(cfg.restUrl + 'members', { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' });
    return res.status;
  });
  expect(status).toBe(403);
});

test('Mitglied ändert sein Passwort und bleibt angemeldet', async ({ page }) => {
  const NEW = 'Anker-Lank-1973';
  await login(page, 'mariner2');
  await page.getByRole('button', { name: 'Konto' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Mein Konto');

  await page.getByLabel('Aktuelles Passwort').fill('falsch');
  await page.getByLabel(/^Neues Passwort \(/).fill(NEW);
  await page.getByLabel('Neues Passwort wiederholen').fill(NEW);
  await page.getByRole('button', { name: 'Passwort ändern' }).click();
  await expect(page.locator('.form-error')).toHaveText('Das aktuelle Passwort ist falsch.');

  await page.getByLabel('Aktuelles Passwort').fill(PASSWORD);
  await page.getByRole('button', { name: 'Passwort ändern' }).click();
  await expect(page.locator('.toast')).toContainText('Passwort geändert');
  await shot(page, '9-konto');

  // Sitzung bleibt gültig: Daten laden weiterhin.
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.stat-label')).toHaveText('Meine Punkte');

  // Neue Anmeldung nur mit dem neuen Passwort.
  await login(page, 'mariner2', NEW);

  // Zurücksetzen, damit die Tests wiederholbar bleiben.
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByLabel('Aktuelles Passwort').fill(NEW);
  await page.getByLabel(/^Neues Passwort \(/).fill(PASSWORD);
  await page.getByLabel('Neues Passwort wiederholen').fill(PASSWORD);
  await page.getByRole('button', { name: 'Passwort ändern' }).click();
  await expect(page.locator('.toast')).toContainText('Passwort geändert');
});

test('Admin pflegt öffentliche Termine', async ({ page }) => {
  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Öffentliche Termine verwalten' }).click();
  await page.getByRole('button', { name: '+ Öffentlichen Termin anlegen' }).click();
  const d = new Date(Date.now() + 10 * 86400000);
  const ymd = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  await page.getByLabel('Titel').fill('Tag der offenen Tür');
  await page.getByLabel('Datum').fill(ymd);
  await page.getByLabel('Beginn (optional)').fill('14:00');
  await page.getByLabel('Ort').fill('Vereinsheim');
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Termin angelegt');
  await expect(page.locator('.row', { hasText: 'Tag der offenen Tür' })).toBeVisible();
  await shot(page, '10-termine-verwalten');
});

test('Gast ohne Anmeldung sieht öffentliche Termine und News', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await expect(page).toHaveURL(/\/app\/?$/);
  await expect(page.getByRole('link', { name: 'Anmelden' })).toBeVisible();
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await expect(page.getByText('Kompanieabend')).toHaveCount(0); // interner Google-Termin
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Spieß' })).toHaveCount(0);
  await shot(page, '11-gast-termine');

  await page.getByRole('button', { name: 'News' }).click();
  await expect(page.locator('.news-card').first()).toBeVisible();
  await page.locator('.news-card').first().click();
  await expect(page.getByRole('link', { name: 'Auf der Webseite öffnen' })).toBeVisible();
  await shot(page, '12-gast-news');

  const status = await page.evaluate(async () => {
    const res = await fetch(window.MKL_CONFIG!.restUrl + 'me/points', { credentials: 'same-origin' });
    return res.status;
  });
  expect(status).toBe(401);
});

test('Admin stuft ein Mitglied zum Gast herab und zurück', async ({ page }) => {
  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.locator('.row', { hasText: 'Mariner2' }).click();
  await page.locator('.role-control').getByRole('button', { name: 'Gast' }).click();
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Gast');
  await shot(page, '13-rolle');

  await login(page, 'mariner2');
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Konto' })).toBeVisible();

  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.getByLabel('Auch Gast-Konten anzeigen').check();
  await page.locator('.row', { hasText: 'Mariner2' }).click();
  await page.locator('.role-control').getByRole('button', { name: 'Mitglied' }).click();
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Mitglied');
});

test('Galerie: Gäste sehen öffentliche Bilder, Mitglieder zusätzlich interne', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/#/galerie');
  await expect(page.locator('.album-card', { hasText: 'Schützenfest' })).toBeVisible();
  await expect(page.locator('.album-card', { hasText: 'Kameradschaftsabend' })).toHaveCount(0);
  await page.locator('.album-card', { hasText: 'Schützenfest' }).click();
  await expect(page.locator('.photo')).toHaveCount(2);
  await expect(page.locator('.photo-lock')).toHaveCount(0);
  await page.locator('.photo').first().click();
  await expect(page.locator('.lb-count')).toHaveText('1 / 2');
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('.lb-count')).toHaveText('2 / 2');
  await shot(page, '15-galerie-lightbox');
  await page.keyboard.press('Escape');
  await expect(page.locator('.lightbox')).toHaveCount(0);

  await login(page, 'mariner1');
  await page.getByRole('button', { name: 'Galerie' }).click();
  await expect(page.locator('.album-card', { hasText: 'Kameradschaftsabend' })).toBeVisible();
  await shot(page, '16-galerie-alben');
  await page.locator('.album-card', { hasText: 'Schützenfest' }).click();
  await expect(page.locator('.photo')).toHaveCount(3);
  await expect(page.locator('.photo-lock')).toHaveCount(1);
  const internSrc = await page.locator('.photo', { has: page.locator('.photo-lock') }).locator('img').getAttribute('src');
  expect(internSrc).toContain('/app/foto/');
  const asMember = await page.request.get(internSrc!);
  expect(asMember.status()).toBe(200);
  expect(asMember.headers()['content-type']).toBe('image/jpeg');
  await shot(page, '17-galerie-album');

  await page.context().clearCookies();
  const asGuest = await page.request.get(internSrc!);
  expect(asGuest.status()).toBe(401);
});

test('iPhone: Banner führt zur bebilderten Installationsanleitung', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.evaluate(() => localStorage.removeItem('mkl-install-hint-dismissed'));
  await page.reload();
  const banner = page.locator('.install-hint');
  await expect(banner).toContainText('Die App aufs Handy holen');
  await shot(page, '12-install-banner');
  await banner.getByRole('button', { name: "So geht's" }).click();
  await expect(page.locator('.topbar-title')).toHaveText('App installieren');
  await expect(page.getByText('Zum Home-Bildschirm', { exact: false }).first()).toBeVisible();
  await expect(page.locator('.install-step')).toHaveCount(4);
  await expect(page.getByRole('button', { name: 'iPhone / iPad' })).toHaveAttribute('aria-pressed', 'true');
  await shot(page, '13-install-ios');
  await page.getByRole('button', { name: 'Android' }).click();
  await expect(page.locator('.install-step')).toHaveCount(3);

  // Eigene Adresse zum Weitergeben öffnet direkt die Anleitung.
  await page.goto('/app/installieren/');
  await expect(page.locator('.topbar-title')).toHaveText('App installieren');
  await expect(page).toHaveURL(/\/app\/#\/installieren$/);
  await expect(page.locator('.install-share code')).toHaveText(/\/app\/installieren\/$/);

  // Ausblenden bleibt gespeichert.
  await page.goto('/app/#/termine');
  await page.getByRole('button', { name: 'Hinweis schließen' }).click();
  await page.reload();
  await expect(page.locator('.topbar')).toBeVisible();
  await expect(page.locator('.install-hint')).toHaveCount(0);

  // Angemeldete finden die Anleitung zusätzlich im Konto.
  await login(page, 'mariner1');
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByRole('button', { name: 'App installieren' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('App installieren');
});

test.describe('Android', () => {
  const { defaultBrowserType: _ignored, ...pixel } = devices['Pixel 7'];
  test.use(pixel);

  test('Installieren-Knopf öffnet den Installationsdialog des Browsers', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/app/');
    await expect(page.locator('.topbar')).toBeVisible();
    // Chrome meldet die Installierbarkeit über „beforeinstallprompt“ – hier nachgestellt.
    await page.evaluate(() => {
      const w = window as unknown as { __prompted: number };
      w.__prompted = 0;
      const ev = new Event('beforeinstallprompt', { cancelable: true }) as Event & Record<string, unknown>;
      ev.prompt = () => {
        w.__prompted++;
        return Promise.resolve();
      };
      ev.userChoice = Promise.resolve({ outcome: 'accepted' });
      window.dispatchEvent(ev);
    });
    const button = page.locator('.install-hint').getByRole('button', { name: 'Installieren' });
    await expect(button).toBeVisible();
    await shot(page, '14-install-android');
    await button.click();
    await expect(page.locator('.toast')).toContainText('Die App wird installiert');
    expect(await page.evaluate(() => (window as unknown as { __prompted: number }).__prompted)).toBe(1);
    // Das Angebot gilt nur einmal: danach wieder die Anleitung.
    await expect(page.locator('.install-hint').getByRole('button', { name: "So geht's" })).toBeVisible();
  });
});

test('Angemeldet bleiben ist vorausgewählt und hält rund 400 Tage', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.getByRole('link', { name: 'Anmelden' }).click();
  await expect(page.locator('#rememberme')).toBeChecked();
  await page.fill('#user_login', 'mariner1');
  await page.fill('#user_pass', PASSWORD);
  await page.click('#wp-submit');
  await expect(page.locator('.topbar')).toBeVisible();
  const cookie = (await page.context().cookies()).find((c) => c.name.startsWith('wordpress_logged_in_'));
  expect(cookie).toBeDefined();
  const days = (cookie!.expires - Date.now() / 1000) / 86400;
  expect(days).toBeGreaterThan(390);
});
