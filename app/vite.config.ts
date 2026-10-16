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
  },
});
