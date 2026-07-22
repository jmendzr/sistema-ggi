/**
 * sw.js — Service Worker GGI Sistema
 *
 * Estrategia:
 *   • Activos estáticos (CSS, JS, fuentes, imágenes) → Cache-First:
 *     se sirven desde caché; en segundo plano se actualiza si hay versión nueva.
 *   • Páginas HTML y endpoints PHP → Network-First:
 *     se intenta red; si falla (sin conexión) se muestra una página offline simple.
 *
 * El nombre del caché incluye un número de versión. Al cambiar la versión,
 * el SW antiguo elimina automáticamente los cachés obsoletos en 'activate'.
 */

const CACHE_VERSION  = 'ggi-v39';
const CACHE_STATIC   = CACHE_VERSION + '-static';
const CACHE_PAGES    = CACHE_VERSION + '-pages';

// Activos que se pre-cachean en la instalación del SW.
const PRECACHE_URLS = [
  '/assets/css/tom-select.min.css',
  '/assets/js/tom-select.complete.min.js',
  '/assets/js/shortcuts.js',
  '/assets/js/validacion.js',
  '/assets/js/mantenimiento.js',
  // ── Iconos PWA ──
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/icons/icon-maskable-192.png',
  '/assets/icons/icon-maskable-512.png',
  // ── Identidad corporativa (splash screen) ──
  '/assets/logo-ggi.png',
  // ── iOS Splash Screens ──
  '/assets/splash/splash-640x1136.png',
  '/assets/splash/splash-750x1334.png',
  '/assets/splash/splash-1125x2436.png',
  '/assets/splash/splash-1170x2532.png',
  '/assets/splash/splash-1242x2208.png',
  '/assets/splash/splash-1242x2688.png',
  '/assets/splash/splash-1284x2778.png',
  '/assets/splash/splash-828x1792.png',
  '/assets/splash/splash-1080x2340.png',
  '/assets/splash/splash-1179x2556.png',
  '/assets/splash/splash-1290x2796.png',
  // manifest.php es dinámico por rol — no se pre-cachea
];

// ── Instalación: pre-cachear activos estáticos ──────────────────────────────
self.addEventListener('install', event => {
  self.skipWaiting(); // activar inmediatamente sin esperar a que se cierren pestañas viejas
  event.waitUntil(
    caches.open(CACHE_STATIC).then(cache => {
      return Promise.allSettled(
        PRECACHE_URLS.map(url =>
          cache.add(url).catch(() => {/* ignorar si el activo no existe aún */})
        )
      );
    })
  );
});

// ── Activación: limpiar cachés de versiones anteriores ─────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k.startsWith('ggi-') && k !== CACHE_STATIC && k !== CACHE_PAGES)
          .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Intercepción de peticiones ──────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // Solo interceptar peticiones del mismo origen
  if (url.origin !== self.location.origin) return;

  // Nunca cachear: endpoints AJAX, uploads, exports, auth, PDFs
  const skipPatterns = [
    '/ajax_', '/ajax/', 'ajax_',
    '/modules/ajax', 'subir.php', 'exportar.php', 'pdf.php',
    '/modules/auth/', 'descargar', 'portal_', '/cron/',
    'login.php', 'logout.php',
  ];
  if (skipPatterns.some(p => url.pathname.includes(p))) return;
  // Descargas directas (?descargar=1) — el browser debe manejarlas nativamente
  if (url.searchParams.has('descargar')) return;
  // Formularios embebidos en modal (?embed=1) — son navegaciones de iframe dinámicas:
  // deben ir directo a la red (con credenciales y manejo nativo de redirección).
  // Si el SW las intercepta, el redirect:'manual' de la navegación produce un
  // opaqueRedirect que el navegador sigue y puede terminar en login→dashboard.
  if (url.searchParams.has('embed')) return;
  if (req.method !== 'GET') return;

  // Activos estáticos (CSS, JS, fuentes, imágenes) → Cache-First
  const isStatic = url.pathname.startsWith('/assets/') ||
                   url.pathname.match(/\.(css|js|woff2?|ttf|svg|png|jpg|jpeg|webp|ico)$/i);

  if (isStatic) {
    event.respondWith(cacheFirst(req, CACHE_STATIC));
    return;
  }

  // Páginas PHP → Network-First con fallback offline
  event.respondWith(networkFirst(req));
});

// ── Cache-First: sirve desde caché; actualiza en segundo plano ─────────────
async function cacheFirst(req, cacheName) {
  const cache    = await caches.open(cacheName);
  const cached   = await cache.match(req);
  if (cached) {
    // Actualizar en background para la próxima visita (stale-while-revalidate)
    fetch(req).then(res => { if (res && res.ok) cache.put(req, res.clone()); }).catch(() => {});
    return cached;
  }
  try {
    const res = await fetch(req);
    if (res && res.ok) cache.put(req, res.clone());
    return res;
  } catch {
    return new Response('', { status: 503 });
  }
}

// ── Network-First: intenta red; cae a caché si falla ───────────────────────
async function networkFirst(req) {
  const cache = await caches.open(CACHE_PAGES);
  try {
    const res = await fetch(req);
    if (res && res.ok && res.status < 400) {
      // Guardar en caché de páginas solo respuestas HTML exitosas
      const ct = res.headers.get('content-type') || '';
      if (ct.includes('text/html')) cache.put(req, res.clone());
    }
    return res;
  } catch {
    const cached = await cache.match(req);
    if (cached) return cached;
    // Respuesta offline mínima
    return new Response(offlinePage(), {
      status: 200,
      headers: { 'Content-Type': 'text/html; charset=utf-8' }
    });
  }
}

// ── Push: mostrar notificación cuando el servidor envía un push ────────────
self.addEventListener('push', event => {
  if (!event.data) return;

  let data = {};
  try { data = event.data.json(); } catch { data = { title: 'GGI Sistema', body: event.data.text() }; }

  const title = data.title || 'GGI Sistema';
  const tag   = data.tag   || ('ggi-' + Date.now());

  // Componer cuerpo: evento en primera línea (si viene), luego el detalle
  // El título ya contiene el nombre de empresa para que sea el texto prominente en iOS
  const bodyParts = [];
  if (data.subtitle) bodyParts.push(data.subtitle);
  if (data.body)     bodyParts.push(data.body);
  const bodyText = bodyParts.join('\n');

  const options = {
    body:    bodyText,
    icon:    data.icon  || '/assets/icons/icon-192.png',
    badge:   data.badge || '/assets/icons/icon-192.png',
    data:  { url: data.url || '/modules/dashboard/index.php' },
    vibrate: [200, 100, 200],
    requireInteraction: false,
    tag:      tag,
    renotify: true,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

// ── Push: al tocar la notificación → abrir/enfocar la URL indicada ─────────
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : '/modules/dashboard/index.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(wins => {
      // Intentar enfocar cualquier ventana ya abierta del sitio
      for (const win of wins) {
        if ('focus' in win) {
          // Si ya está en la URL destino, solo enfocar; si no, navegar y enfocar
          if (win.url === url) return win.focus();
          return win.navigate(url).then(w => w && w.focus());
        }
      }
      // Ninguna ventana abierta → abrir nueva pestaña
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

// ── Mensaje desde el cliente para activar SW inmediatamente ────────────────
self.addEventListener('message', event => {
  if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
  // Reportar la versión para que la página detecte SW viejos y se auto-repare.
  if (event.data?.type === 'GET_VERSION' && event.ports && event.ports[0]) {
    event.ports[0].postMessage({ version: CACHE_VERSION });
  }
});

// ── Página offline inline ───────────────────────────────────────────────────
function offlinePage() {
  return `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sin conexión — GGI Sistema</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
           display:flex; align-items:center; justify-content:center; min-height:100vh;
           margin:0; background:#F1F5F9; color:#0F172A; }
    .card { background:#fff; border-radius:16px; padding:40px 32px; text-align:center;
            box-shadow:0 4px 24px rgba(0,0,0,.08); max-width:360px; width:100%; }
    .icon { font-size:48px; margin-bottom:16px; }
    h1 { font-size:20px; font-weight:700; margin-bottom:8px; color:#1A3A8F; }
    p { font-size:14px; color:#64748B; line-height:1.5; margin-bottom:24px; }
    button { background:#1A3A8F; color:#fff; border:none; border-radius:9px;
             padding:11px 24px; font-size:14px; font-weight:600; cursor:pointer; }
    button:hover { background:#122870; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">📡</div>
    <h1>Sin conexión</h1>
    <p>No hay conexión a Internet en este momento.<br>Revisa tu red e intenta de nuevo.</p>
    <button onclick="window.location.reload()">Reintentar</button>
  </div>
</body>
</html>`;
}
