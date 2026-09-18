import { createHash } from 'node:crypto'
import type { Plugin } from 'vite'

/** Caches application code only. Personal API responses belong in account-scoped IndexedDB. */
export function offlineShell(): Plugin {
  return {
    name: 'selfhandler-offline-shell',
    apply: 'build',
    generateBundle(_options, bundle) {
      const files = Object.keys(bundle).filter(name => /\.(js|css|woff2?|svg|png|ico)$/.test(name)).map(name => `/${name}`).sort()
      files.push('/index.html')
      const version = createHash('sha256').update(JSON.stringify(files)).digest('hex').slice(0, 16)
      this.emitFile({ type: 'asset', fileName: 'sw.js', source: `
const CACHE = 'selfhandler-shell-${version}';
const FILES = ${JSON.stringify(files)};
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(FILES))));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('selfhandler-shell-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) return;
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.open(CACHE).then(cache => cache.match('/index.html'))));
  } else if (FILES.includes(url.pathname)) {
    event.respondWith(caches.open(CACHE).then(async cache => (await cache.match(url.pathname)) || fetch(request)));
  }
});
` })
    },
  }
}
