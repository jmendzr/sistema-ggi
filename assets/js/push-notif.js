/* push-notif.js — suscripción Web Push (VAPID), extraído de layout_end.php (auditoría 2026-07).
   Depende de window.VAPID_PUBLIC_KEY y window.APP_URL (puente inline). */

(function() {
  if (!('serviceWorker' in navigator)) return;

  // Cuando un Service Worker NUEVO toma control (tras skipWaiting+clients.claim),
  // recargar una vez para que la página quede gobernada por él. Sin esto, un SW
  // viejo sin el bypass de embed seguía activo y el modal mostraba el dashboard.
  var _ggiSWRefreshing = false;
  navigator.serviceWorker.addEventListener('controllerchange', function() {
    if (_ggiSWRefreshing) return;
    _ggiSWRefreshing = true;
    window.location.reload();
  });

  // Purga TODOS los SW + cachés y recarga limpio. Reutilizable.
  function _ggiPurgarSW(reload) {
    return navigator.serviceWorker.getRegistrations()
      .then(function(regs){ return Promise.all(regs.map(function(r){ return r.unregister(); })); })
      .then(function(){ return (window.caches ? caches.keys() : []); })
      .then(function(keys){ return Promise.all((keys || []).map(function(k){ return caches.delete(k); })); })
      .catch(function(){})
      .then(function(){ if (reload) location.replace(location.pathname); });
  }

  // Reset manual: visitar cualquier URL con ?swreset=1.
  if (location.search.indexOf('swreset=1') !== -1) {
    _ggiPurgarSW(true);
    return; // no registrar de nuevo en esta carga
  }

  // ── Auto-reparación: los SW viejos (sin el bypass de embed) NO responden a
  // GET_VERSION. Si el SW que controla la página no responde, está viejo → se
  // purga y recarga UNA vez. Destraba solo el "modal muestra dashboard" sin que
  // el usuario haga nada. Los SW al día (v12+) responden y no se tocan, así que
  // futuros despliegues no requieren cambiar nada aquí.
  (function autoSanarSW() {
    var ctrl = navigator.serviceWorker.controller;
    if (!ctrl) return;                                   // sin SW controlando: nada que sanar
    if (sessionStorage.getItem('_ggiSWHeal')) return;    // ya intentado en esta sesión
    var respondio = false;
    var mc = new MessageChannel();
    mc.port1.onmessage = function() { respondio = true; }; // SW moderno → todo bien
    try { ctrl.postMessage({ type: 'GET_VERSION' }, [mc.port2]); } catch (e) { return; }
    setTimeout(function() {
      if (respondio) return;                              // SW al día: no tocar
      sessionStorage.setItem('_ggiSWHeal', '1');
      _ggiPurgarSW(false).then(function(){ window.location.reload(); });
    }, 1500);
  })();

  var VAPID_PUB = window.VAPID_PUBLIC_KEY;

  // Convierte la clave pública VAPID (base64url) al Uint8Array que exige la API
  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw     = atob(base64);
    var arr     = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  function sendSubToServer(sub, unsubscribe) {
    var s   = sub.toJSON ? sub.toJSON() : sub;
    var body = unsubscribe
      ? JSON.stringify({ unsubscribe: true, endpoint: s.endpoint })
      : JSON.stringify({ endpoint: s.endpoint, p256dh: s.keys.p256dh, auth: s.keys.auth });
    fetch('' + window.APP_URL + '/modules/ajax_push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: body
    }).catch(function() {});
  }

  // Registrar SW. updateViaCache:'none' obliga al navegador a NO usar la caché HTTP
  // al revisar sw.js (clave para que un SW viejo cacheado como immutable se actualice).
  navigator.serviceWorker.register('' + window.APP_URL + '/sw.js', { scope: '/', updateViaCache: 'none' })
    .then(function(reg) {
      // Forzar comprobación de versión nueva en cada carga completa
      // (la navegación SPA no revalida sw.js por sí sola).
      try { reg.update(); } catch (e) {}
      // Actualización silenciosa
      if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      reg.addEventListener('updatefound', function() {
        var sw = reg.installing;
        if (!sw) return;
        sw.addEventListener('statechange', function() {
          if (sw.state === 'installed' && navigator.serviceWorker.controller) {
            sw.postMessage({ type: 'SKIP_WAITING' });
          }
        });
      });

      // ── Suscripción push (solo si Push API disponible y VAPID configurado) ──
      if (!('PushManager' in window) || !VAPID_PUB) return;

      reg.pushManager.getSubscription().then(function(existing) {
        if (existing) {
          // Ya suscrito — re-enviar al servidor por si se reinició la BD
          sendSubToServer(existing, false);
          return;
        }

        // Si el permiso fue denegado, mostrar banner persistente para guiar al usuario
        if (Notification.permission === 'denied') {
          _showPushDeniedBanner();
          return;
        }

        // Mostrar banner amigable en lugar de pedir permiso directamente (mejor UX)
        _showPushPromptBanner(reg);
      }).catch(function() {});
    })
    .catch(function() {});

  // ── Banner: invitar al usuario a activar notificaciones ──────────────────
  function _showPushPromptBanner(reg) {
    // Solo mostrar una vez por sesión
    if (sessionStorage.getItem('ggi_push_prompted')) return;
    sessionStorage.setItem('ggi_push_prompted', '1');

    var b = document.createElement('div');
    b.id = 'ggi-push-banner';
    b.innerHTML =
      '<span style="flex:1">🔔 <strong>Activar notificaciones</strong> — recibe alertas de propuestas y pagos en tu celular</span>' +
      '<button id="ggi-push-yes" style="background:#1A3A8F;color:#fff;border:none;border-radius:7px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">Activar</button>' +
      '<button id="ggi-push-no"  style="background:transparent;border:none;color:#64748B;font-size:12px;cursor:pointer;padding:4px 8px">Ahora no</button>';
    b.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:9999;' +
      'background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;' +
      'display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.12);' +
      'max-width:500px;width:calc(100% - 32px);font-size:13px;color:#1e293b;';

    document.body.appendChild(b);

    document.getElementById('ggi-push-yes').addEventListener('click', function() {
      b.remove();
      Notification.requestPermission().then(function(perm) {
        if (perm !== 'granted') return;
        reg.pushManager.subscribe({
          userVisibleOnly:      true,
          applicationServerKey: urlBase64ToUint8Array(VAPID_PUB),
        }).then(function(sub) {
          sendSubToServer(sub, false);
          _showPushSuccessToast();
        }).catch(function(err) {
          console.warn('[GGI Push] subscribe error:', err);
        });
      });
    });

    document.getElementById('ggi-push-no').addEventListener('click', function() {
      b.remove();
    });

    // Auto-cerrar después de 15 s
    setTimeout(function() { if (b.parentNode) b.remove(); }, 15000);
  }

  // ── Banner: permiso ya denegado, instrucciones para reactivar ───────────
  function _showPushDeniedBanner() {
    if (sessionStorage.getItem('ggi_push_denied_shown')) return;
    sessionStorage.setItem('ggi_push_denied_shown', '1');
    var b = document.createElement('div');
    b.innerHTML =
      '🔕 <strong>Notificaciones bloqueadas.</strong> Para reactivarlas, ve a Configuración del navegador → Permisos del sitio → Notificaciones y permite este sitio.' +
      '<button style="margin-left:12px;background:transparent;border:none;color:#64748B;font-size:12px;cursor:pointer" onclick="this.parentNode.remove()">✕</button>';
    b.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:9999;' +
      'background:#FFF7ED;border:1px solid #FED7AA;border-radius:12px;padding:12px 16px;' +
      'max-width:500px;width:calc(100% - 32px);font-size:13px;color:#92400E;box-shadow:0 4px 20px rgba(0,0,0,.1);';
    document.body.appendChild(b);
    setTimeout(function() { if (b.parentNode) b.remove(); }, 10000);
  }

  function _showPushSuccessToast() {
    var t = document.createElement('div');
    t.textContent = '✅ Notificaciones activadas correctamente';
    t.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:9999;' +
      'background:#065F46;color:#fff;border-radius:10px;padding:10px 20px;font-size:13px;font-weight:600;' +
      'box-shadow:0 4px 20px rgba(0,0,0,.2);';
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 4000);
  }
})();
