import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
// Früh laden: fängt das Android-Installationsangebot ab, bevor React startet.
import { isStandalone } from './install';
import App from './App';
import { config } from './api';
// Schrift lokal gebündelt (kein Abruf von Google-Servern, DSGVO).
import '@fontsource/oswald/latin-500.css';
import '@fontsource/oswald/latin-600.css';
import '@fontsource/oswald/latin-700.css';
// latin-ext enthält das große ẞ (wird nur geladen, wenn es auf der Seite vorkommt).
import '@fontsource/oswald/latin-ext-500.css';
import '@fontsource/oswald/latin-ext-600.css';
import '@fontsource/oswald/latin-ext-700.css';
import './styles.css';

// Teilbarer Link /app/installieren/ → Anleitung in der App (#/installieren).
if (/\/installieren\/?$/.test(window.location.pathname) && config) {
  history.replaceState(null, '', config.appUrl + '#/installieren');
}
// Vom Home-Bildschirm gestartet: nicht auf der Anleitung landen, sondern bei den Terminen.
if (isStandalone() && window.location.hash.startsWith('#/installieren')) {
  history.replaceState(null, '', window.location.pathname);
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);

const cfg = config;
if ('serviceWorker' in navigator && cfg) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(cfg.swUrl, { scope: cfg.appUrl }).catch(() => {
      /* Ohne Service Worker läuft die App trotzdem, nur nicht offline. */
    });
  });
}
