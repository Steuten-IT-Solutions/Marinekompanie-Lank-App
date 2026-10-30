// Das Logo liegt als src/assets/logo.svg oder src/assets/logo.png vor; ein SVG hat Vorrang.
// Die App-Icons (public/icons) entstehen daraus mit „npm run icons“.
const found = import.meta.glob<string>('./assets/logo.{svg,png}', { eager: true, query: '?url', import: 'default' });

const logoUrl = found['./assets/logo.svg'] ?? found['./assets/logo.png'];
if (!logoUrl) throw new Error('Logo fehlt: src/assets/logo.svg oder src/assets/logo.png anlegen.');

export default logoUrl;
