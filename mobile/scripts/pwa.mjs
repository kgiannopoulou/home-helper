// Turns the Expo web export (dist/) into an installable Progressive Web App.
//
//   npx expo export -p web && node scripts/pwa.mjs
//
// iPhones install it from Safari (Share → Add to Home Screen), so the family gets the app
// without Apple's paid developer membership. Android Chrome offers "Install app" too.
// It writes manifest.webmanifest, sized icons and a small service worker, and adds the
// matching tags to dist/index.html.
import { createRequire } from 'node:module';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { generateImageAsync } = require('@expo/image-utils');

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'dist');
const indexPath = join(dist, 'index.html');
if (!existsSync(indexPath)) {
  console.error('dist/index.html not found. Run `npx expo export -p web` first.');
  process.exit(1);
}

const { expo } = JSON.parse(readFileSync(join(root, 'app.json'), 'utf8'));
const web = expo.web ?? {};
const theme = web.themeColor ?? '#4F46E5';
const background = web.backgroundColor ?? '#ffffff';
const version = `${expo.version}-${Date.now().toString(36)}`;

async function icon(size, file, padding = 0) {
  const inner = Math.round(size * (1 - padding));
  const { source } = await generateImageAsync(
    { projectRoot: root, cacheType: 'pwa' },
    { src: join(root, 'assets/icon.png'), width: size, height: size, resizeMode: 'contain', backgroundColor: background, ...(padding ? { width: inner, height: inner } : {}) },
  );
  if (padding) {
    // Maskable icons keep the logo inside the middle 80%: pad by drawing on a bigger canvas.
    const { compositeImagesAsync, createSquareAsync } = require('@expo/image-utils');
    const canvas = await createSquareAsync({ size, color: background });
    writeFileSync(join(dist, file), await compositeImagesAsync({ foreground: source, background: canvas, x: (size - inner) / 2, y: (size - inner) / 2 }));
  } else {
    writeFileSync(join(dist, file), source);
  }
}

await icon(192, 'icon-192.png');
await icon(512, 'icon-512.png');
await icon(512, 'icon-maskable-512.png', 0.2);
await icon(180, 'apple-touch-icon.png');

const manifest = {
  name: web.name ?? expo.name,
  short_name: web.shortName ?? expo.name,
  description: 'Shopping, kitchen, money, chores and planning for your household.',
  start_url: '/',
  scope: '/',
  display: web.display ?? 'standalone',
  orientation: 'portrait',
  theme_color: theme,
  background_color: background,
  lang: web.lang ?? 'en',
  icons: [
    { src: '/icon-192.png', sizes: '192x192', type: 'image/png' },
    { src: '/icon-512.png', sizes: '512x512', type: 'image/png' },
    { src: '/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
  ],
};
writeFileSync(join(dist, 'manifest.webmanifest'), JSON.stringify(manifest, null, 2));

// Cache the app shell so it opens offline (the data is already offline-first in AsyncStorage).
// Only same-origin GETs are cached: the household server's API is never cached here.
const sw = `const CACHE = 'home-helper-${version}';
self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(['/', '/manifest.webmanifest', '/icon-192.png'])).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;
  if (req.mode === 'navigate') {
    // Network first for pages, so a new release shows up; the cached shell when offline.
    e.respondWith(fetch(req).then((res) => { const copy = res.clone(); caches.open(CACHE).then((c) => c.put('/', copy)); return res; }).catch(() => caches.match('/')));
    return;
  }
  // Build files have content hashes in their names, so cache first is safe.
  e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
    if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
    return res;
  })));
});
`;
writeFileSync(join(dist, 'sw.js'), sw);

let html = readFileSync(indexPath, 'utf8');
if (!html.includes('manifest.webmanifest')) {
  const head = [
    '<link rel="manifest" href="/manifest.webmanifest" />',
    `<meta name="theme-color" content="${theme}" />`,
    '<link rel="apple-touch-icon" href="/apple-touch-icon.png" />',
    '<meta name="apple-mobile-web-app-capable" content="yes" />',
    '<meta name="mobile-web-app-capable" content="yes" />',
    `<meta name="apple-mobile-web-app-title" content="${manifest.short_name}" />`,
    '<meta name="apple-mobile-web-app-status-bar-style" content="default" />',
  ].join('\n    ');
  const register = `<script>if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));</script>`;
  html = html.replace('</head>', `    ${head}\n  </head>`).replace('</body>', `  ${register}\n  </body>`);
  writeFileSync(indexPath, html);
}
console.log(`PWA ready in dist/ (cache ${version}).`);
