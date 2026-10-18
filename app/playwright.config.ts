import { defineConfig, devices } from '@playwright/test';

// E2E-Tests gegen eine laufende WordPress-Instanz mit aktivem Plugin.
// Erwartet die Testbenutzer spiess (Rolle Spieß), mariner1, mariner2, anwaerter mit Passwort test1234.
export default defineConfig({
  testDir: './e2e',
  workers: 1,
  timeout: 60_000,
  use: {
    baseURL: process.env.MKL_BASE_URL ?? 'http://localhost:8888',
    ...devices['iPhone 13'],
    browserName: 'chromium',
    launchOptions: process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {},
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
  },
});
