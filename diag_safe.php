<?php http_response_code(200); ?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>Diag safe-area</title>
  <style>
    :root { --st: env(safe-area-inset-top, 0px); --sb: env(safe-area-inset-bottom, 0px);
            --sl: env(safe-area-inset-left, 0px); --sr: env(safe-area-inset-right, 0px); }
    * { margin:0; box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,sans-serif; background:#0B0F14; color:#E5E7EB;
           min-height:100vh; min-height:100dvh; }
    /* Franja roja que ocupa exactamente el safe-area-top: si es >0 se ve una barra roja arriba */
    .band { position:fixed; top:0; left:0; right:0; height:var(--st); background:#EF4444; z-index:10; }
    .wrap { padding:calc(var(--st) + 24px) 24px 24px; }
    h1 { font-size:18px; margin-bottom:16px; }
    .row { display:flex; justify-content:space-between; padding:14px 0; border-bottom:1px solid #1E293B; font-size:16px; }
    .row b { color:#60A5FA; font-variant-numeric:tabular-nums; font-size:20px; }
    .note { margin-top:20px; font-size:13px; color:#9CA3AF; line-height:1.5; }
    .mode { margin-top:12px; padding:10px 14px; background:#111827; border-radius:8px; font-size:14px; }
  </style>
</head>
<body>
  <div class="band"></div>
  <div class="wrap">
    <h1>Diagnóstico safe-area (isla dinámica)</h1>
    <div class="row"><span>safe-area-inset-top</span><b id="t">–</b></div>
    <div class="row"><span>safe-area-inset-bottom</span><b id="b">–</b></div>
    <div class="row"><span>safe-area-inset-left</span><b id="l">–</b></div>
    <div class="row"><span>safe-area-inset-right</span><b id="r">–</b></div>
    <div class="mode" id="mode">display-mode: …</div>
    <div class="note">
      Si <b>top</b> sale <b>0px</b> aquí (con la franja roja invisible), tu iPhone no está
      reportando el inset a la web → el problema no es el código sino el modo de la PWA.
      Si <b>top</b> sale ~50–60px (franja roja visible arriba), el inset SÍ llega y el fix debe funcionar.
      Dime los cuatro números y lo del display-mode.
    </div>
  </div>
  <script>
    function px(prop){
      var d=document.createElement('div');
      d.style.cssText='position:fixed;top:0;left:0;width:0;height:0;padding-top:env('+prop+',0px);visibility:hidden;';
      document.body.appendChild(d);
      var v=getComputedStyle(d).paddingTop; d.remove(); return v;
    }
    document.getElementById('t').textContent = px('safe-area-inset-top');
    document.getElementById('b').textContent = px('safe-area-inset-bottom');
    document.getElementById('l').textContent = px('safe-area-inset-left');
    document.getElementById('r').textContent = px('safe-area-inset-right');
    var standalone = (window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    document.getElementById('mode').textContent = 'standalone (app instalada): ' + (standalone ? 'SÍ' : 'NO (navegador)');
  </script>
</body>
</html>
