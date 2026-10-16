import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import { config } from './api';
import './styles.css';

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
