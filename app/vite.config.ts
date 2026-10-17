import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Der Build landet direkt im WordPress-Plugin. WordPress liefert index.html
// unter /app/ aus und biegt die relativen Pfade (base: './') auf das
// Plugin-Verzeichnis um (siehe class-mkl-app-page.php).
export default defineConfig({
  base: './',
  plugins: [react()],
  build: {
    outDir: '../plugin/mk-lank-app/app-dist',
    emptyOutDir: true,
    assetsDir: 'assets',
    // Die PDF-Anzeige (pdf.js) wird erst beim Öffnen eines Dokuments nachgeladen.
    chunkSizeWarningLimit: 600,
    rollupOptions: {
      output: {
        // Der pdf.js-Worker heißt sonst *.mjs; manche Webserver liefern .mjs ohne
        // JavaScript-Typ aus, dann startet der Worker nicht.
        assetFileNames: (info) =>
          info.names?.[0]?.endsWith('.mjs') ? 'assets/[name]-[hash].js' : 'assets/[name]-[hash][extname]',
      },
    },
  },
});
