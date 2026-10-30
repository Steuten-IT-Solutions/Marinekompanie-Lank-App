// Erzeugt die App-Icons (public/icons) aus dem Logo:
//
//   npm run icons
//
// Quelle ist src/assets/logo.svg, sonst src/assets/logo.png (wie in src/logo.ts).
// Das Logo wird mittig auf den Marine-Hintergrund gesetzt; beim maskierbaren Icon
// kleiner, damit es auch in runden oder abgerundeten Masken vollständig sichtbar bleibt.
import { existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const app = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(app, 'public', 'icons');
const background = '#1f4498'; // wie theme_color im Manifest (class-mkl-app-page.php)

const icons = [
  { file: 'icon-192.png', size: 192, scale: 0.84 },
  { file: 'icon-512.png', size: 512, scale: 0.84 },
  { file: 'apple-touch-icon.png', size: 180, scale: 0.84 },
  { file: 'icon-maskable-512.png', size: 512, scale: 0.62 },
];

const source = ['logo.svg', 'logo.png'].map((f) => join(app, 'src', 'assets', f)).find((f) => existsSync(f));
if (!source) {
  console.error('Fehler: src/assets/logo.svg oder src/assets/logo.png fehlt.');
  process.exit(1);
}

const meta = await sharp(source).metadata();
if (!meta.width || !meta.height) {
  console.error(`Fehler: ${source} hat keine lesbare Größe (beim SVG width/height oder viewBox angeben).`);
  process.exit(1);
}

mkdirSync(out, { recursive: true });
for (const { file, size, scale } of icons) {
  const box = Math.round(size * scale);
  // Ein SVG wird in der Zielauflösung gerastert statt eine kleine Rastergrafik hochzuskalieren.
  const density = meta.format === 'svg' ? Math.min(2400, Math.ceil((72 * box) / Math.min(meta.width, meta.height))) : undefined;
  const logo = await sharp(source, density ? { density } : {})
    .resize(box, box, { fit: 'inside' })
    .png()
    .toBuffer();
  await sharp({ create: { width: size, height: size, channels: 3, background } })
    .composite([{ input: logo, gravity: 'centre' }])
    .png()
    .toFile(join(out, file));
  console.log(`public/icons/${file} (${size}×${size})`);
}
console.log(`Quelle: ${source.slice(app.length + 1)}`);
