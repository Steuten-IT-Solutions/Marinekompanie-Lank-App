import { expect, test, type Page } from '@playwright/test';

const PASSWORD = process.env.MKL_TEST_PASSWORD ?? 'test1234';
const SHOTS = process.env.MKL_SCREENSHOT_DIR;

async function login(page: Page, user: string, password = PASSWORD) {
  await page.context().clearCookies();
  await page.goto('/app/');
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
  await shot(page, '1-termine');

  // Direkter Aufruf des Spieß-Bereichs fällt auf Termine zurück.
  await page.goto('/app/#/spiess');
  await expect(page.locator('.topbar-title')).toHaveText('Termine');
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
  await page.getByRole('button', { name: 'Punkte' }).click();
  await expect(page.locator('.stat-value')).toHaveText('5');
  await expect(page.getByText('Teilnahme Schützenfest')).toBeVisible();
  await shot(page, '2-punkte');

  await page.getByRole('button', { name: 'Strafen' }).click();
  await expect(page.locator('.stat-value')).toContainText('2,50');
  await expect(page.getByText('Zu spät beim Antreten')).toBeVisible();
  await expect(page.getByText('Mütze vergessen')).toBeVisible();
  await shot(page, '3-strafen');
});

test('Mitglied 2 sieht keine fremden Strafen und keine Verwaltungsdaten', async ({ page }) => {
  await login(page, 'mariner2');
  await page.getByRole('button', { name: 'Strafen' }).click();
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
  await page.getByRole('button', { name: 'Punkte' }).click();
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
