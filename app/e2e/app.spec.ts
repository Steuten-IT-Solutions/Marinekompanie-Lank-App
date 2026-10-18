import { devices, expect, test, type Page } from '@playwright/test';

const PASSWORD = process.env.MKL_TEST_PASSWORD ?? 'test1234';
const SHOTS = process.env.MKL_SCREENSHOT_DIR;

/**
 * Füllt das WordPress-Anmeldeformular aus. WordPress setzt 200 ms nach dem Laden
 * den Fokus auf „Benutzername“ und markiert den Inhalt (wp_attempt_focus). Wer
 * vorher tippt, landet sonst mit dem Passwort im Benutzernamen-Feld.
 */
async function fillLoginForm(page: Page, user: string, password: string) {
  await page.waitForFunction(() => document.activeElement?.id === 'user_login');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', password);
  await expect(page.locator('#user_login')).toHaveValue(user);
}

async function login(page: Page, user: string, password = PASSWORD) {
  await page.context().clearCookies();
  await page.goto('/app/');
  // Gäste sehen die öffentliche Ansicht; angemeldet wird über den Knopf in der Kopfleiste.
  await page.getByRole('link', { name: 'Anmelden' }).click();
  await expect(page).toHaveURL(/wp-login\.php/);
  await fillLoginForm(page, user, password);
  await page.click('#wp-submit');
  await expect(page).toHaveURL(/\/app\/?(#.*)?$/);
  await expect(page.locator('.topbar')).toBeVisible();
}

/** Öffnet „Spieß → Einrichtung → <Unterseite>“ (der Spieß-Bereich muss offen sein). */
async function openSetup(page: Page, title: string) {
  await page.locator('.segmented').getByRole('button', { name: 'Einrichtung' }).click();
  await page.locator('.setup-list .row-title').getByText(title, { exact: true }).click();
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
  await page.getByText(/^Anwaerter/).click();
  await expect(page.getByText('Rolle: Anwärter')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Punkte vergeben' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Zum Mitglied befördern' }).click();
  await expect(page.locator('.toast')).toHaveText('Zum Mitglied befördert');
  await expect(page.getByRole('button', { name: 'Punkte vergeben' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Zum Mitglied befördern' })).toHaveCount(0);

  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Mitglieder verwalten');
  await page.getByText(/^Anwaerter/).click();
  await page.locator('.role-control').getByRole('button', { name: 'Anwärter', exact: true }).click();
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Anwärter');
});

test('Spieß legt Katalog an, vergibt Punkte und Strafen', async ({ page }) => {
  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await shot(page, '5-spiess-mitglieder');

  await openSetup(page, 'Punktekatalog');
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

  // Eine Vorlage lässt sich löschen; die vergebenen Punkte bleiben (siehe Test „Mitglied 1“).
  await openSetup(page, 'Punktekatalog');
  page.once('dialog', (d) => d.accept());
  await page.locator('.row', { hasText: 'Teilnahme Schützenfest' }).getByRole('button', { name: 'Löschen' }).click();
  await expect(page.locator('.toast')).toHaveText('Vorlage gelöscht');
  await expect(page.locator('.row', { hasText: 'Teilnahme Schützenfest' })).toHaveCount(0);

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
  await openSetup(page, 'Mitglieder verwalten');
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByPlaceholder('Vorname Nachname').fill('Neuer Mariner');
  await page.getByRole('button', { name: 'Mitglied', exact: true }).click();
  await page.getByLabel(/^Jungmariner/).check();
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  await expect(page.getByTestId('new-level')).toHaveText('Mitglied');
  await expect(page.getByText('Neuer Mariner ist angelegt')).toBeVisible();
  await expect(page.getByText('Als Jungmariner markiert')).toBeVisible();
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();
  expect(username).toMatch(/^neuer\.mariner\d*$/);
  expect(password).toHaveLength(12);
  await expect(page.getByTestId('new-app-url')).toHaveText(/\/app\/installieren\/$/);
  await shot(page, '8-mitglied-angelegt');

  await login(page, username, password);
  await expect(page.locator('.topbar-sub')).toHaveText('Ahoi, Neuer Mariner');
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.getByText('Als Jungmariner erhältst du laut Satzung die halbe Punktzahl.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Spieß' })).toHaveCount(0);
});

test('Admin legt mehrere Mitglieder auf einmal an', async ({ page }) => {
  const tag = Date.now().toString(36);
  const rank = `Bulkrang ${tag}`;
  const min = 50000 + Math.floor(Math.random() * 40000);
  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  // Eigener Dienstgrad mit einer Schwelle, die sonst niemand nutzt.
  const rankId = await page.evaluate(
    async ({ title, min_points }) => {
      const cfg = window.MKL_CONFIG!;
      const headers = { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' };
      const res = await fetch(cfg.restUrl + 'ranks', { method: 'POST', headers, credentials: 'same-origin', body: JSON.stringify({ title, min_points }) });
      return ((await res.json()) as { id: number }).id;
    },
    { title: rank, min_points: min },
  );

  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Mitglieder verwalten');
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByRole('button', { name: 'Mehrere' }).click();

  const rows = page.getByTestId('bulk-row');
  await expect(rows).toHaveCount(1);
  // Als Anwärter (Standard) gibt es nur Name und E-Mail.
  await expect(rows.nth(0).getByLabel('Punkte')).toHaveCount(0);
  await page.getByRole('button', { name: 'Mitglied', exact: true }).click();

  // Person 1: Punkte und Dienstgrad. Der Dienstgrad steht erst zur Wahl, wenn die Punkte reichen.
  await rows.nth(0).getByLabel('Name').fill('Bulk Eins');
  await rows.nth(0).getByLabel('Punkte').fill(String(min - 1));
  await expect(rows.nth(0).getByLabel('Dienstgrad').locator('option', { hasText: rank })).toHaveCount(0);
  await rows.nth(0).getByLabel('Punkte').fill(String(min));
  await rows.nth(0).getByLabel('Dienstgrad').selectOption({ label: `${rank} (ab ${min.toLocaleString('de-DE')})` });

  // Person 2 über das Plus: Jungmariner. Das neue Namensfeld hat sofort den Fokus.
  await page.getByRole('button', { name: '+ Weitere Person' }).click();
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(1).getByLabel('Name')).toBeFocused();
  await rows.nth(1).getByLabel('Name').fill('Bulk Zwei');
  await rows.nth(1).getByLabel('E-Mail (optional)').fill(`bulk.zwei.${tag}@example.com`);
  await rows.nth(1).getByLabel('Punkte').fill('12');
  await rows.nth(1).getByLabel('Jungmariner').check();

  // Person 3 ohne Namen → Fehler an der Karte, nichts wird angelegt.
  await page.getByRole('button', { name: '+ Weitere Person' }).click();
  await rows.nth(2).getByLabel('E-Mail (optional)').fill(`bulk.drei.${tag}@example.com`);
  await page.getByRole('button', { name: '3 Konten anlegen' }).click();
  await expect(rows.nth(2).getByRole('alert')).toHaveText('Bitte einen Namen angeben.');
  await rows.nth(2).getByLabel('Name').fill('Bulk Drei');
  await expect(rows.nth(2).getByRole('alert')).toHaveCount(0);

  // Eine vierte, leere Karte wird beim Anlegen einfach weggelassen; eine Karte lässt sich entfernen.
  await page.getByRole('button', { name: '+ Weitere Person' }).click();
  await page.getByRole('button', { name: '+ Weitere Person' }).click();
  await rows.nth(4).getByLabel('Name').fill('Wieder weg');
  await page.getByRole('button', { name: 'Person 5 entfernen' }).click();
  await expect(rows).toHaveCount(4);

  await page.getByLabel('Punktestand vom (für alle)').fill('2025-12-31');
  await shot(page, '8b-mehrere-mitglieder');
  await page.getByRole('button', { name: '3 Konten anlegen' }).click();
  await expect(page.getByText('3 Konten sind angelegt')).toBeVisible();
  await expect(page.getByTestId('bulk-level')).toHaveText('Mitglied');
  await expect(page.getByTestId('bulk-app-url')).toHaveText(/\/app\/installieren\/$/);
  const created = page.getByTestId('created-user');
  await expect(created).toHaveCount(3);
  await expect(created.nth(0).getByTestId('created-extras')).toHaveText(`${min.toLocaleString('de-DE')} Punkte · Dienstgrad: ${rank}`);
  await expect(created.nth(1).getByTestId('created-extras')).toHaveText('12 Punkte · ohne Dienstgrad · Jungmariner');
  await expect(created.nth(2).getByTestId('created-extras')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Alle Zugangsdaten kopieren' })).toBeVisible();
  await shot(page, '8c-mehrere-angelegt');

  const username = (await created.nth(1).getByTestId('new-username').textContent())!.trim();
  const password = (await created.nth(1).getByTestId('new-password').textContent())!.trim();
  expect(username).toMatch(/^bulk\.zwei\d*$/);
  expect(password).toHaveLength(12);

  // Dienstgrad wieder abschalten, damit spätere Tests unbeeinflusst bleiben.
  await page.evaluate(async (id) => {
    const cfg = window.MKL_CONFIG!;
    const headers = { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' };
    await fetch(cfg.restUrl + 'ranks/' + id, { method: 'PATCH', headers, credentials: 'same-origin', body: JSON.stringify({ active: false }) });
  }, rankId);

  await login(page, username, password);
  await expect(page.locator('.topbar-sub')).toHaveText('Ahoi, Bulk Zwei');
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.getByText('Als Jungmariner erhältst du laut Satzung die halbe Punktzahl.')).toBeVisible();
});

test('Mehrere anlegen: Server-Fehler wird an der richtigen Person angezeigt', async ({ page }) => {
  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.goto('/app/#/spiess/einrichtung/mitglieder/neu');
  await page.getByRole('button', { name: 'Mehrere' }).click();
  const rows = page.getByTestId('bulk-row');
  await rows.nth(0).getByLabel('Name').fill('Doppelt Eins');
  await rows.nth(0).getByLabel('E-Mail (optional)').fill('doppelt@example.com');
  await page.getByRole('button', { name: '+ Weitere Person' }).click();
  await rows.nth(1).getByLabel('Name').fill('Doppelt Zwei');
  await rows.nth(1).getByLabel('E-Mail (optional)').fill('Doppelt@example.com');
  await page.getByRole('button', { name: '2 Konten anlegen' }).click();
  await expect(rows.nth(1).getByRole('alert')).toContainText('Diese E-Mail-Adresse wird schon verwendet.');
  await expect(rows.nth(0).getByRole('alert')).toHaveCount(0);
  await expect(page.getByText('Konten sind angelegt')).toHaveCount(0);
});

test('Admin legt einen Anwärter an', async ({ page }) => {
  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Mitglieder verwalten');
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByPlaceholder('Vorname Nachname').fill('Neuer Anwaerter');
  // Neue Konten starten als Anwärter.
  await expect(page.getByRole('button', { name: 'Anwärter', exact: true })).toHaveAttribute('aria-pressed', 'true');
  // Anfangsbestand, Dienstgrad und Jungmariner gibt es nur für Mitglieder.
  await expect(page.getByLabel(/^Jungmariner/)).toHaveCount(0);
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  await expect(page.getByTestId('new-level')).toHaveText('Anwärter');
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();

  await login(page, username, password);
  await expect(page.getByText('Kompanieabend')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
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
  await expect(page.locator('.row-sub').first()).toContainText('von Spiess');
  await shot(page, '2-punkte');

  await page.locator('.segmented').getByRole('button', { name: 'Strafen' }).click();
  await expect(page.locator('.stat-value')).toContainText('2,50');
  await expect(page.getByText('Zu spät beim Antreten')).toBeVisible();
  await expect(page.getByText('Mütze vergessen')).toBeVisible();
  await expect(page.locator('.row-sub', { hasText: 'von Spiess' })).toHaveCount(2);
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

test('Jungmariner erhalten bei der Punktevergabe die halbe Punktzahl', async ({ page }) => {
  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.locator('.row', { hasText: 'Mariner2' }).click();
  await page.getByLabel(/^Jungmariner/).check();
  await expect(page.locator('.toast')).toHaveText('Als Jungmariner markiert');

  await page.getByRole('button', { name: 'Punkte vergeben' }).click();
  await page.getByLabel('Punkte', { exact: true }).fill('3');
  await page.getByLabel('Grund').fill('Aufbau Zelt');
  await expect(page.getByText('Mariner2 ist Jungmariner')).toHaveText('Mariner2 ist Jungmariner und erhält nur die halbe Punktzahl: +1,5.');
  await shot(page, '9-jungmariner');
  await page.getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.toast')).toContainText('vergeben');

  await page.getByRole('button', { name: 'Mitglieder' }).click();
  await expect(page.locator('.row', { hasText: 'Mariner2' })).toContainText('Jungmariner');
  await expect(page.locator('.row', { hasText: 'Mariner2' })).toContainText('6,5 Punkte');

  await login(page, 'mariner2');
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.stat-value')).toHaveText('6,5');
  await expect(page.locator('.row', { hasText: 'Aufbau Zelt' })).toContainText('halbe Punktzahl von +3');

  // Zurücksetzen für die folgenden Tests.
  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.locator('.row', { hasText: 'Mariner2' }).click();
  await page.getByLabel(/^Jungmariner/).uncheck();
  await expect(page.locator('.toast')).toHaveText('Kein Jungmariner mehr');
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
  await page.getByLabel('Titel', { exact: true }).fill('Tag der offenen Tür');
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

test('Admin entzieht einem Mitglied die Rolle und gibt sie zurück', async ({ page }) => {
  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Mitglieder verwalten');
  await page.locator('.row', { hasText: 'Mariner2' }).click();
  await page.locator('.role-control').getByRole('button', { name: 'Ohne Rolle' }).click();
  await expect(page.locator('.toast')).toHaveText('Rolle entzogen');
  await shot(page, '13-rolle');

  await login(page, 'mariner2');
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Konto' })).toBeVisible();

  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Mitglieder verwalten');
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
    expect(await page.evaluate(() => (window as unknown as { __prompted: number }).__prompted)).toBe(1);
    // Während der Installation keine Anleitung zeigen, sondern wie es weitergeht.
    const banner = page.locator('.install-hint');
    await expect(banner).toContainText('Die App wird installiert');
    await expect(banner.getByRole('button', { name: "So geht's" })).toHaveCount(0);
    // Ohne Neuladen wechseln, sonst wäre der Installationsstand weg.
    await page.evaluate(() => (window.location.hash = '/installieren'));
    await expect(page.locator('.topbar-title')).toHaveText('App installieren');
    await expect(page.locator('.install-intro h2')).toHaveText('Die App wird installiert …');
    await expect(page.locator('.segmented')).toHaveCount(0);
    await expect(page.locator('.install-step')).toHaveCount(1);
    // Chrome meldet das Ende der Installation über „appinstalled“.
    await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')));
    await expect(page.locator('.install-intro h2')).toHaveText('Fertig! Die App ist installiert');
    await expect(page.locator('.install-intro')).toContainText('auf das Wappen „Marinekompanie“ tippen');
    await shot(page, '15-install-android-fertig');
  });

  test('Abgebrochene Installation führt wieder zur Anleitung', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/app/');
    await expect(page.locator('.topbar')).toBeVisible();
    await page.evaluate(() => {
      const ev = new Event('beforeinstallprompt', { cancelable: true }) as Event & Record<string, unknown>;
      ev.prompt = () => Promise.resolve();
      ev.userChoice = Promise.resolve({ outcome: 'dismissed' });
      window.dispatchEvent(ev);
    });
    await page.locator('.install-hint').getByRole('button', { name: 'Installieren' }).click();
    // Das Angebot gilt nur einmal: danach wieder die Anleitung.
    await expect(page.locator('.install-hint').getByRole('button', { name: "So geht's" })).toBeVisible();
  });
});

test.describe('Samsung Internet', () => {
  const { defaultBrowserType: _ignored, ...pixel } = devices['Pixel 7'];
  test.use({ ...pixel, userAgent: pixel.userAgent.replace(/Chrome\/[\d.]+/, 'SamsungBrowser/29.0 Chrome/136.0.0.0') });

  test('bietet keine Installation an, sondern verweist auf Chrome', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/app/installieren/');
    await expect(page.locator('.topbar-title')).toHaveText('App installieren');
    await page.evaluate(() => {
      const ev = new Event('beforeinstallprompt', { cancelable: true }) as Event & Record<string, unknown>;
      ev.prompt = () => Promise.resolve();
      ev.userChoice = Promise.resolve({ outcome: 'accepted' });
      window.dispatchEvent(ev);
    });
    await expect(page.locator('.install-warn')).toContainText('Samsung-Browser');
    await expect(page.getByRole('link', { name: 'In Chrome öffnen' })).toHaveAttribute(
      'href',
      /^intent:\/\/[^#]+\/app\/installieren\/#Intent;scheme=https?;package=com\.android\.chrome;end$/,
    );
    await expect(page.locator('.install-intro').getByRole('button', { name: 'App installieren' })).toHaveCount(0);
  });
});

test('Angemeldet bleiben ist vorausgewählt und hält rund 400 Tage', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.getByRole('link', { name: 'Anmelden' }).click();
  await expect(page.locator('#rememberme')).toBeChecked();
  await fillLoginForm(page, 'mariner1', PASSWORD);
  await page.click('#wp-submit');
  await expect(page.locator('.topbar')).toBeVisible();
  const cookie = (await page.context().cookies()).find((c) => c.name.startsWith('wordpress_logged_in_'));
  expect(cookie).toBeDefined();
  const days = (cookie!.expires - Date.now() / 1000) / 86400;
  expect(days).toBeGreaterThan(390);
});

/** Kleine, gültige PDF mit einer Textzeile (für Upload und Anzeige). */
function makePdf(text: string): Buffer {
  const stream = `BT /F1 24 Tf 72 760 Td (${text}) Tj ET`;
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  let out = '%PDF-1.4\n';
  const offsets: number[] = [];
  objects.forEach((o, i) => {
    offsets.push(out.length);
    out += `${i + 1} 0 obj\n${o}\nendobj\n`;
  });
  const xref = out.length;
  out += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  out += offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('');
  out += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}

test('Dokumente: Kategorien, Sichtbarkeit, Office-Dateien und Offline-Lesen', async ({ page, context }) => {
  const tag = Date.now().toString(36);
  const kategorie = `Satzung & Ordnungen ${tag}`;
  const protokoll = `Protokoll ${tag}`;

  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  await page.getByRole('button', { name: 'Dokumente' }).click();
  await page.getByRole('button', { name: 'Dokumente verwalten' }).click();

  await page.getByPlaceholder('z. B. Protokolle').fill(kategorie);
  await page.getByRole('button', { name: 'Kategorie hinzufügen' }).click();
  await expect(page.locator('.toast')).toHaveText('Kategorie angelegt');

  // Satzung als PDF für alle Mitglieder; der Titel kommt aus dem Dateinamen.
  await page.getByLabel('Datei', { exact: true }).setInputFiles({ name: 'Satzung.pdf', mimeType: 'application/pdf', buffer: makePdf('Satzung der Marinekompanie') });
  await expect(page.getByLabel('Titel', { exact: true })).toHaveValue('Satzung');
  await page.getByLabel('Kategorie', { exact: true }).selectOption({ label: kategorie });
  await page.getByLabel('Stand').fill('2026-03-01');
  await page.getByRole('button', { name: 'Hochladen' }).click();
  await expect(page.locator('.toast')).toHaveText('„Satzung“ hochgeladen');

  // Word-Datei nur für Spieße.
  await page.getByLabel('Datei', { exact: true }).setInputFiles({
    name: 'protokoll.docx',
    mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    buffer: Buffer.concat([Buffer.from('PK\x03\x04', 'latin1'), Buffer.alloc(64, 0x20)]),
  });
  await page.getByLabel('Titel', { exact: true }).fill(protokoll);
  await page.getByLabel('Sichtbar für').selectOption({ label: 'Nur Spieße' });
  await page.getByRole('button', { name: 'Hochladen' }).click();
  await expect(page.locator('.toast')).toHaveText(`„${protokoll}“ hochgeladen`);

  // Umbenannte Datei wird abgelehnt.
  await page.getByLabel('Datei', { exact: true }).setInputFiles({ name: 'falsch.pdf', mimeType: 'application/pdf', buffer: Buffer.from('keine pdf') });
  await page.getByLabel('Titel', { exact: true }).fill('Falsch');
  await page.getByRole('button', { name: 'Hochladen' }).click();
  await expect(page.locator('.form-error')).toHaveText('Die Datei ist keine gültige .pdf-Datei.');
  await shot(page, '18-dokumente-verwalten');

  // Spieß ohne Admin-Rechte sieht das Protokoll, darf aber nichts pflegen.
  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Dokumente' }).click();
  const prot = page.locator('.doc-card', { hasText: protokoll });
  await expect(prot).toContainText('Nur Spieße');
  await expect(prot.getByRole('button', { name: 'Öffnen' })).toHaveCount(0);
  const dl = page.waitForEvent('download');
  await prot.getByRole('button', { name: 'Herunterladen' }).click();
  expect((await dl).suggestedFilename()).toBe(`${protokoll}.docx`);
  await expect(page.getByRole('button', { name: 'Dokumente verwalten' })).toHaveCount(0);

  // Mitglied: Satzung unter ihrer Kategorie, kein Protokoll.
  await login(page, 'mariner1');
  await page.getByRole('button', { name: 'Dokumente' }).click();
  await expect(page.locator('.doc-group', { hasText: kategorie }).locator('.doc-card', { hasText: 'Satzung' }).first()).toBeVisible();
  await expect(page.locator('.doc-card', { hasText: protokoll })).toHaveCount(0);
  const card = page.locator('.doc-group', { hasText: kategorie }).locator('.doc-card').last();
  await expect(card).toContainText('Stand: 01.03.2026 · PDF');
  await shot(page, '19-dokumente');

  // Erst nach dem Öffnen ist die Satzung offline verfügbar.
  await expect(card.locator('.doc-offline')).toHaveCount(0);
  await card.getByRole('button', { name: 'Öffnen' }).click();
  await expect(page.locator('.pdf-page')).toHaveCount(1);
  await expect(page.locator('.pdf-page')).toHaveAttribute('alt', 'Satzung, Seite 1 von 1');
  await shot(page, '20-satzung');
  await page.getByRole('button', { name: '‹ Alle Dokumente' }).click();
  await expect(card.locator('.doc-offline')).toHaveText('✓ Auch offline verfügbar');

  // Ohne Netz: App neu laden und die Satzung erneut öffnen.
  await context.setOffline(true);
  await page.reload();
  await expect(page.locator('.offline')).toBeVisible();
  await page.locator('.doc-group', { hasText: kategorie }).locator('.doc-card').last().getByRole('button', { name: 'Öffnen' }).click();
  await expect(page.locator('.pdf-page')).toHaveCount(1);
  await context.setOffline(false);

  // Gäste sehen keinen Bereich „Dokumente“ und bekommen die Datei nicht.
  const docUrl = await page.evaluate(async () => {
    const cfg = window.MKL_CONFIG!;
    const res = await fetch(cfg.restUrl + 'documents', { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' });
    return ((await res.json()) as { documents: { url: string }[] }).documents[0].url;
  });
  await page.context().clearCookies();
  await page.goto('/app/');
  await expect(page.getByRole('button', { name: 'Dokumente' })).toHaveCount(0);
  expect((await page.request.get(docUrl)).status()).toBe(401);
});

test('Dienstgrade: Anlage mit Dienstgrad, Beförderungsübersicht, Beförderung durch den Spieß', async ({ page }) => {
  const tag = Date.now().toString(36);
  const gefreiter = `Gefreiter ${tag}`;
  const maat = `Maat ${tag}`;
  const name = `Dienstgrad Tester ${tag}`;

  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  // Dienstgrade früherer Testläufe abschalten, damit die Schwellen frei sind.
  await page.evaluate(async () => {
    const cfg = window.MKL_CONFIG!;
    const headers = { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' };
    const ranks = (await (await fetch(cfg.restUrl + 'ranks?all=1', { headers, credentials: 'same-origin' })).json()) as { id: number }[];
    for (const r of ranks) {
      await fetch(cfg.restUrl + 'ranks/' + r.id, { method: 'PATCH', headers, credentials: 'same-origin', body: JSON.stringify({ active: false }) });
    }
  });

  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Dienstgrade');
  for (const [title, min] of [
    [gefreiter, '10'],
    [maat, '30'],
  ]) {
    await page.getByPlaceholder('z. B. Maat').fill(title);
    await page.getByLabel('Ab Punkten').fill(min);
    await page.getByRole('button', { name: 'Hinzufügen' }).click();
    await expect(page.locator('.toast')).toHaveText('Dienstgrad angelegt');
  }
  await expect(page.locator('.row', { hasText: maat })).toContainText('ab 30');
  await shot(page, '21-raenge-pflegen');

  // Übernahme eines bestehenden Mitglieds mit 12 Punkten als Gefreiter.
  await openSetup(page, 'Mitglieder verwalten');
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByPlaceholder('Vorname Nachname').fill(name);
  await page.getByRole('button', { name: 'Mitglied', exact: true }).click();
  await page.getByLabel('Punkte (Anfangsbestand)').fill('12');
  await expect(page.getByLabel('Dienstgrad').locator('option')).toHaveText(['Kein Dienstgrad', `${gefreiter} (ab 10)`]);
  await page.getByLabel('Dienstgrad').selectOption({ label: `${gefreiter} (ab 10)` });
  await shot(page, '22-mitglied-mit-rang');
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  await expect(page.getByTestId('new-rank')).toHaveText(`Übernommen: 12 Punkte · Dienstgrad: ${gefreiter}`);
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();

  // Spieß bucht 20 Punkte → 32, Schwelle für Maat erreicht.
  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.getByRole('button', { name: 'Punkte +' }).click();
  await page.getByLabel('Punkte', { exact: true }).fill('20');
  await page.getByLabel('Grund').fill('Schützenfest');
  await page.getByLabel(name).check();
  await page.getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.toast')).toContainText('+20 Punkte');

  // Das Mitglied behält seinen Dienstgrad, sieht aber, dass die Beförderung ansteht.
  await login(page, username, password);
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.rank-title')).toHaveText(gefreiter);
  await expect(page.locator('.rank-next')).toHaveText(`Punkte für ${maat} erreicht – die Beförderung erfolgt durch die Spieße.`);
  await shot(page, '23-meins-rang');

  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Dienstgrade' }).click();
  const due = page.locator('.promo-list').first().locator('.row', { hasText: name });
  await expect(due).toContainText(`Schwelle für ${maat} erreicht seit`);
  await shot(page, '24-befoerderungen');
  await due.getByRole('button', { name: 'Befördern' }).click();
  await expect(page.locator('.member-name')).toHaveText(name);
  await expect(page.locator('.rank-form select')).toHaveValue(/\d+/);
  await page.getByLabel('Begründung (optional)').fill('Generalversammlung');
  await page.locator('.rank-form').getByRole('button', { name: 'Befördern' }).click();
  await expect(page.locator('.toast')).toHaveText(`Befördert: ${maat}`);
  await expect(page.locator('.rank-control .rank-title')).toHaveText(maat);
  // Degradieren ist bei ausreichenden Punkten nicht möglich.
  await expect(page.locator('.rank-control').getByRole('button', { name: 'Degradieren' })).toBeDisabled();
  await shot(page, '25-mitglied-rang');

  await login(page, username, password);
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.rank-title')).toHaveText(maat);
  await page.locator('.segmented').getByRole('button', { name: 'Dienstgrad' }).click();
  await expect(page.locator('.rank-mine')).toContainText(maat);
  await expect(page.locator('.rank-history-befoerderung')).toHaveCount(2);
  await expect(page.getByText('Generalversammlung')).toBeVisible();
  await shot(page, '26-meins-rangliste');

  // Fremde Dienstgrade bleiben verborgen.
  const status = await page.evaluate(async () => {
    const cfg = window.MKL_CONFIG!;
    return (await fetch(cfg.restUrl + 'promotions', { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })).status;
  });
  expect(status).toBe(403);
});

test('Titel: Spieß legt Titel an und vergibt ihn, Mitglied sieht ihn zusätzlich zum Dienstgrad', async ({ page }) => {
  const title = `Ehrenkapitän ${Date.now().toString(36)}`;

  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Titel');
  // Die Standardtitel sind vorhanden.
  for (const t of ['Kapitän', 'Ehrenkapitän', 'Ehrenspieß', 'Ehrenmitglied']) {
    await expect(page.locator('.row-title', { hasText: new RegExp(`^${t}$`) })).toHaveCount(1);
  }
  await page.getByLabel('Neuer Titel').fill(title);
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(page.locator('.toast')).toHaveText('Titel angelegt');
  await expect(page.locator('.row', { hasText: title })).toContainText('0 Mitglieder');
  await shot(page, '27-titel-pflegen');

  await page.locator('.segmented').getByRole('button', { name: 'Mitglieder' }).click();
  await page.locator('.row', { hasText: 'Mariner2' }).click();
  await page.getByRole('button', { name: 'Titel ändern' }).click();
  await page.locator('.title-picker').getByLabel(title).check();
  await page.locator('.title-control').getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText(`Titel gespeichert: ${title}`);
  await expect(page.locator('.title-control .badge-title')).toHaveText(title);
  await shot(page, '28-mitglied-titel');

  await page.getByRole('button', { name: '‹ Alle Mitglieder' }).click();
  await expect(page.locator('.row', { hasText: 'Mariner2' }).locator('.badge-title')).toHaveText(title);

  await login(page, 'mariner2');
  await page.getByRole('button', { name: 'Meins' }).click();
  // Der Titel steht auf „Meins → Punkte“ über den Punkten, nicht noch einmal in der Dienstgrad-Karte.
  await expect(page.locator('.my-titles .badge-title')).toHaveText(title);
  await expect(page.locator('.my-titles + .stat')).toBeVisible();
  await shot(page, '29-meins-titel');

  // Aufräumen: Titel wieder entfernen und löschen.
  await login(page, 'spiess');
  const ok = await page.evaluate(async (name) => {
    const cfg = window.MKL_CONFIG!;
    const headers = { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' };
    const get = async (path: string) => (await fetch(cfg.restUrl + path, { headers, credentials: 'same-origin' })).json();
    const members = (await get('members')) as { id: number; name: string }[];
    const m = members.find((x) => x.name === 'Mariner2')!;
    await fetch(cfg.restUrl + `members/${m.id}/titles`, { method: 'PUT', headers, credentials: 'same-origin', body: JSON.stringify({ title_ids: [] }) });
    const t = ((await get('titles')) as { id: number; title: string }[]).find((x) => x.title === name)!;
    return (await fetch(cfg.restUrl + `titles/${t.id}`, { method: 'DELETE', headers, credentials: 'same-origin' })).ok;
  }, title);
  expect(ok).toBe(true);
});

test('Einrichtung: Spieß sieht keine Kontenverwaltung, Admin ändert Namen, Benutzername und Passwort', async ({ page }) => {
  const tag = Date.now().toString(36);

  await login(page, 'spiess');
  await page.getByRole('button', { name: 'Spieß' }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Einrichtung' }).click();
  await expect(page.locator('.setup-list .row-title')).toHaveText(['Punktekatalog', 'Dienstgrade', 'Titel']);
  await page.goto('/app/#/spiess/einrichtung/mitglieder');
  await expect(page.locator('.setup-list .row-title')).toHaveText(['Punktekatalog', 'Dienstgrade', 'Titel']);

  await login(page, 'admin', process.env.MKL_ADMIN_PASSWORD ?? 'admin');
  // Testkonto über die API anlegen.
  const created = await page.evaluate(async (t) => {
    const cfg = window.MKL_CONFIG!;
    const res = await fetch(cfg.restUrl + 'users', {
      method: 'POST',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ name: `Konto ${t}`, username: `konto.${t}` }),
    });
    return (await res.json()) as { id: number; username: string; password: string };
  }, tag);

  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Mitglieder verwalten');
  await shot(page, '30-mitglieder-verwalten');
  await page.locator('.search').fill(`konto.${tag}`);
  await page.locator('.row', { hasText: `Konto ${tag}` }).click();
  await expect(page.locator('.member-name')).toHaveText(`Konto ${tag}`);

  await page.getByLabel('Name', { exact: true }).fill(`Umbenannt ${tag}`);
  await page.getByLabel('Benutzername').fill(`neu.${tag}`);
  await page.getByLabel('E-Mail (optional)').fill(`neu.${tag}@example.com`);
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Änderungen speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');
  await expect(page.locator('.member-name')).toHaveText(`Umbenannt ${tag}`);

  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Passwort zurücksetzen' }).click();
  const password = (await page.getByTestId('reset-password').textContent())!.trim();
  expect(password).toHaveLength(12);
  expect(password).not.toBe(created.password);
  await shot(page, '31-konto-verwalten');

  // Das eigene Konto: Benutzername gesperrt, kein Passwort-Zurücksetzen.
  await page.goto('/app/#/spiess/einrichtung/mitglieder');
  await page.locator('.search').fill('admin');
  await page.locator('.row', { hasText: 'admin@example.com' }).click();
  await expect(page.getByLabel('Benutzername')).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Passwort zurücksetzen' })).toHaveCount(0);

  // Anmelden nur noch mit neuem Benutzernamen und neuem Passwort.
  await login(page, `neu.${tag}`, password);
  await expect(page.locator('.topbar-sub')).toHaveText(`Ahoi, Umbenannt ${tag}`);
});

test('Anwesenheit: Spieß erfasst einen Termin und bucht Punkte, Mitglieder sehen nur die eigene Anwesenheit', async ({ page }) => {
  await login(page, 'spiess');
  // Künftige Termine haben noch keinen Anwesenheits-Knopf.
  await page.getByText('Kompanieabend').click();
  await expect(page.getByRole('button', { name: 'In meinen Kalender' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Anwesenheit' })).toHaveCount(0);

  await page.getByRole('button', { name: 'Spieß' }).click();
  await openSetup(page, 'Punktekatalog');
  await page.getByPlaceholder('z. B. Teilnahme Schützenfest').fill('Arbeitsdienst');
  await page.locator('.field.narrow input').fill('3');
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(page.locator('.toast')).toHaveText('Vorlage angelegt');

  // Vergangener Kalendertermin aus dem Test-Kalender.
  await page.locator('.segmented').getByRole('button', { name: 'Anwesenheit' }).click();
  await page.locator('.row', { hasText: 'Arbeitsdienst Schützenplatz' }).click();
  await expect(page.locator('.member-name')).toHaveText('Arbeitsdienst Schützenplatz');
  // Anfangs ist bei allen „fehlt“ angehakt; je Mitglied ist genau ein Häkchen gesetzt.
  await expect(page.getByLabel('Mariner1: fehlt')).toBeChecked();
  await page.getByLabel('Mariner1: anwesend').check();
  await page.getByLabel('Mariner2: anwesend').check();
  await page.getByLabel('Spiess: anwesend').check();
  await page.getByLabel('Spiess: entschuldigt').check();
  await expect(page.getByLabel('Spiess: anwesend')).not.toBeChecked();
  await expect(page.getByLabel('Mariner1: fehlt')).not.toBeChecked();
  // Anwärter stehen mit in der Liste, bekommen aber keine Punkte.
  await page.getByLabel('Anwaerter: anwesend', { exact: true }).check();
  await page.getByLabel('Neuer Mariner: anwesend').check();
  await expect(page.locator('.attendance-counts')).toContainText('4 anwesend · 1 entschuldigt');
  // Ein Tipp auf die Zahl zeigt die Aufteilung der Gruppe.
  await page.getByRole('button', { name: '4 anwesend' }).click();
  await expect(page.getByRole('tooltip')).toContainText('2 Mitglieder');
  await expect(page.getByRole('tooltip')).toContainText('1 Jungmariner');
  await expect(page.getByRole('tooltip')).toContainText('1 Anwärter');
  await page.getByRole('button', { name: '1 entschuldigt' }).click();
  await expect(page.getByRole('tooltip')).toHaveCount(1);
  await expect(page.getByRole('tooltip')).toContainText('1 Mitglied');
  await page.keyboard.press('Escape');
  await expect(page.getByRole('tooltip')).toHaveCount(0);
  await shot(page, '12-anwesenheit');

  // Punktevorschlag: Formular mit den Anwesenden, Termindatum und passender Vorlage.
  await page.getByLabel('Neuer Mariner: fehlt').check();
  await page.getByRole('button', { name: 'Punkte für 2 Anwesende' }).click();
  await expect(page.locator('.attendance-note')).toContainText('Arbeitsdienst Schützenplatz');
  await expect(page.locator('select')).toHaveValue(/\d+/);
  await expect(page.locator('select option:checked')).toHaveText('Arbeitsdienst (+3)');
  await expect(page.getByLabel('Mariner1')).toBeChecked();
  await expect(page.getByLabel('Mariner2')).toBeChecked();
  await expect(page.getByLabel('Spiess')).not.toBeChecked();
  await shot(page, '13-anwesenheit-punkte');
  await page.getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.toast')).toHaveText('+3 Punkte an 2 Mitglieder vergeben');

  // Zurück in der Liste: gebucht, kein zweiter Vorschlag.
  await expect(page.locator('.member-name')).toHaveText('Arbeitsdienst Schützenplatz');
  await expect(page.locator('.attendance-booked')).toHaveCount(2);
  await expect(page.getByRole('button', { name: 'Punkte für 0 Anwesende' })).toBeDisabled();
  await expect(page.getByText('Punkte bereits gebucht.')).toBeVisible();

  // Strafvorschlag: nur wer unentschuldigt fehlt.
  await page.getByRole('button', { name: /^Strafe für \d+ Unentschuldigte/ }).click();
  await expect(page.getByPlaceholder('z. B. Zu spät beim Antreten')).toHaveValue('Unentschuldigt gefehlt: Arbeitsdienst Schützenplatz');
  await expect(page.getByLabel('Mariner1')).not.toBeChecked();
  await expect(page.getByLabel('Spiess')).not.toBeChecked();
  await page.getByRole('button', { name: '‹ Zurück zur Anwesenheit' }).click();

  // Freier Termin ohne Kalendereintrag.
  await page.getByRole('button', { name: '‹ Anwesenheit' }).click();
  await page.getByPlaceholder('z. B. Arbeitsdienst').fill('Sonderdienst Bootshaus');
  await page.getByRole('button', { name: 'Liste anlegen' }).click();
  await page.getByLabel('Mariner1: entschuldigt').check();
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Anwesenheit gespeichert');
  await page.getByRole('button', { name: '‹ Anwesenheit' }).click();
  await expect(page.locator('.row', { hasText: 'Sonderdienst Bootshaus' })).toContainText('0 anwesend · 1 entschuldigt');
  await expect(page.locator('.row', { hasText: 'Arbeitsdienst Schützenplatz' }).first()).toContainText('Liste vorhanden');

  await login(page, 'mariner1');
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.row', { hasText: 'Arbeitsdienst' })).toContainText('+3');
  await page.locator('.segmented').getByRole('button', { name: 'Anwesenheit' }).click();
  await expect(page.locator('.row', { hasText: 'Arbeitsdienst Schützenplatz' })).toContainText('anwesend');
  await expect(page.locator('.row', { hasText: 'Sonderdienst Bootshaus' })).toContainText('entschuldigt');
  await expect(page.locator('.row', { hasText: 'Arbeitsdienst Schützenplatz' })).toContainText('von Spiess');
  await expect(page.locator('.stat-value')).toHaveText('1');
  await shot(page, '14-meine-anwesenheit');

  await login(page, 'mariner2');
  await page.getByRole('button', { name: 'Meins' }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Anwesenheit' }).click();
  await expect(page.locator('.row', { hasText: 'Arbeitsdienst Schützenplatz' })).toContainText('anwesend');
  await expect(page.locator('.row', { hasText: 'Sonderdienst Bootshaus' })).toContainText('fehlt');
});

test('Benachrichtigungen: iPhone-Hinweis für Mitglieder, Anwärter und Gäste', async ({ page }) => {
  await login(page, 'mariner1');
  await page.goto('/app/#/konto');
  const card = page.locator('.push-card');
  await expect(card).toContainText('nur in der installierten App');
  await expect(card.getByRole('button', { name: 'Benachrichtigungen aktivieren' })).toHaveCount(0);

  await login(page, 'anwaerter');
  await page.goto('/app/#/konto');
  await expect(page.getByText('Rolle: Anwärter')).toBeVisible();
  await expect(card).toContainText('nur in der installierten App');

  // Gäste finden die Benachrichtigungen unter den Terminen (Seite neu laden, nicht nur den Hash wechseln).
  await page.context().clearCookies();
  await page.goto('about:blank');
  await page.goto('/app/#/termine');
  await expect(card).toContainText('nur in der installierten App');
});

test.describe('Benachrichtigungen (Android)', () => {
  const { defaultBrowserType: _ignored, ...pixel } = devices['Pixel 7'];
  test.use({ ...pixel, permissions: ['notifications'] });

  /**
   * Der Test-Chromium hat keinen echten Push-Dienst: PushManager durch ein Gerät mit gültigem
   * P-256-Schlüssel ersetzen. Der Server prüft und speichert das Abo wie bei einem echten Gerät.
   */
  async function fakePushDevice(page: Page) {
    const { createECDH, randomBytes } = await import('node:crypto');
    const ecdh = createECDH('prime256v1');
    const b64u = (b: Buffer) => b.toString('base64url');
    const device = { p256dh: b64u(ecdh.generateKeys()), auth: b64u(randomBytes(16)) };
    await page.addInitScript((dev) => {
      const KEY = 'e2e-push-sub';
      const make = (endpoint: string) => ({
        endpoint,
        options: {},
        toJSON: () => ({ endpoint, keys: { p256dh: dev.p256dh, auth: dev.auth } }),
        unsubscribe: async () => {
          localStorage.removeItem(KEY);
          return true;
        },
      });
      PushManager.prototype.getSubscription = async function () {
        const ep = localStorage.getItem(KEY);
        return (ep ? make(ep) : null) as unknown as PushSubscription;
      };
      PushManager.prototype.subscribe = async function () {
        const ep = 'https://push.example.test/e2e/' + Date.now();
        localStorage.setItem(KEY, ep);
        return make(ep) as unknown as PushSubscription;
      };
    }, device);
  }

  test('Mitglied aktiviert Benachrichtigungen, wählt Ereignisse und schaltet sie aus', async ({ page }) => {
    await fakePushDevice(page);
    await login(page, 'mariner1');
    await page.goto('/app/#/konto');
    const card = page.locator('.push-card');
    await card.getByRole('button', { name: 'Benachrichtigungen aktivieren' }).click();
    await expect(page.locator('.toast')).toHaveText('Benachrichtigungen aktiviert');
    for (const label of ['Neue Strafen', 'Punkte', 'Dienstgrad']) {
      await expect(card.getByLabel(label)).toBeChecked();
    }
    const details = card.getByLabel(/Betrag und Grund/);
    await expect(details).not.toBeChecked();
    await shot(page, '14-benachrichtigungen');

    await card.getByLabel('Punkte').uncheck();
    await expect(card.getByLabel('Punkte')).not.toBeChecked();
    await details.check();
    await expect(details).toBeChecked();

    // Einstellungen liegen auf dem Server.
    await page.reload();
    await expect(card.getByLabel('Neue Strafen')).toBeChecked();
    await expect(card.getByLabel('Punkte')).not.toBeChecked();
    await expect(card.getByLabel(/Betrag und Grund/)).toBeChecked();

    await card.getByRole('button', { name: 'Ausschalten' }).click();
    await expect(page.locator('.toast')).toHaveText('Benachrichtigungen ausgeschaltet');
    await expect(card.getByRole('button', { name: 'Benachrichtigungen aktivieren' })).toBeVisible();
    await page.reload();
    await expect(card.getByRole('button', { name: 'Benachrichtigungen aktivieren' })).toBeVisible();
  });

  test('Gast ohne Anmeldung abonniert Termine und News auf der Termine-Seite', async ({ page }) => {
    await fakePushDevice(page);
    await page.context().clearCookies();
    await page.goto('/app/#/termine');
    const card = page.locator('.push-card');
    await card.getByRole('button', { name: 'Benachrichtigungen aktivieren' }).click();
    await expect(page.locator('.toast')).toHaveText('Benachrichtigungen aktiviert');
    for (const label of ['Neue und geänderte Termine', 'Erinnerung am Vortag (18 Uhr)', 'Neuigkeiten']) {
      await expect(card.getByLabel(label)).toBeChecked();
    }
    await expect(card.getByLabel('Neue Strafen')).toHaveCount(0);
    await card.getByLabel('Neuigkeiten').uncheck();
    await page.reload();
    await expect(card.getByLabel('Neuigkeiten')).not.toBeChecked();
    await expect(card.getByLabel('Neue und geänderte Termine')).toBeChecked();
    await card.getByRole('button', { name: 'Ausschalten' }).click();
    await expect(card.getByRole('button', { name: 'Benachrichtigungen aktivieren' })).toBeVisible();
  });
});
