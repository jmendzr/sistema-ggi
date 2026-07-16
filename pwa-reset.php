<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Limpiar caché</title>
<style>
body { font-family: -apple-system, sans-serif; display: flex; align-items: center;
       justify-content: center; min-height: 100vh; margin: 0; background: #f1f5f9; }
.box { background: #fff; border-radius: 16px; padding: 32px 28px; text-align: center;
       box-shadow: 0 4px 24px rgba(0,0,0,.1); max-width: 360px; width: 90%; }
.icon { font-size: 40px; margin-bottom: 12px; }
h2 { margin: 0 0 8px; font-size: 17px; color: #0f172a; }
p  { margin: 0 0 18px; font-size: 13px; color: #64748b; line-height: 1.5; }
ul { text-align:left; font-size:12px; color:#475569; margin:0 0 18px; padding-left:18px; line-height:1.7; }
a  { display: inline-block; background: #1d4ed8; color: #fff; padding: 11px 24px;
     border-radius: 9px; text-decoration: none; font-size: 14px; font-weight: 700; }
</style>
</head>
<body>
<div class="box">
  <div class="icon" id="ico">⏳</div>
  <h2 id="msg">Limpiando caché…</h2>
  <p id="sub">Desregistrando Service Worker y borrando cachés.</p>
  <ul id="log"></ul>
  <a href="/modules/dashboard/index.php?_fresh=1" id="btn" style="display:none">Entrar al sistema actualizado</a>
</div>
<script>
(async function () {
  var log = document.getElementById('log');
  function step(t) { var li = document.createElement('li'); li.textContent = t; log.appendChild(li); }

  try {
    // 1) Desregistrar TODOS los service workers
    if ('serviceWorker' in navigator) {
      var regs = await navigator.serviceWorker.getRegistrations();
      for (var r of regs) { await r.unregister(); }
      step('Service Workers desregistrados: ' + regs.length);
    } else { step('Service Worker no soportado (ok)'); }

    // 2) Borrar TODO el Cache Storage (ggi-v15, ggi-v16, etc.)
    if ('caches' in window) {
      var keys = await caches.keys();
      for (var k of keys) { await caches.delete(k); }
      step('Cachés borradas: ' + (keys.length ? keys.join(', ') : 'ninguna'));
    } else { step('Cache Storage no soportado (ok)'); }

    // 3) Limpiar flags PWA/banner y caché de filtros del SPA
    try {
      localStorage.removeItem('ggi_pwa_dismiss');
      localStorage.removeItem('ggi_pwa_done');
      Object.keys(sessionStorage).forEach(function (k) {
        if (k.indexOf('ggi_') === 0 || k.indexOf('_ggi') === 0) sessionStorage.removeItem(k);
      });
      step('Flags y filtros de sesión limpiados');
    } catch (e) {}

    document.getElementById('ico').textContent = '✅';
    document.getElementById('msg').textContent = 'Caché eliminada';
    document.getElementById('sub').textContent = 'Pulsa el botón. Al entrar, el sistema cargará todo de cero.';
    document.getElementById('btn').style.display = 'inline-block';
  } catch (e) {
    document.getElementById('ico').textContent = '❌';
    document.getElementById('msg').textContent = 'Error';
    document.getElementById('sub').textContent = 'No se pudo limpiar: ' + e.message;
    step(String(e));
  }
})();
</script>
</body>
</html>
