<?php
/**
 * templates/pwa-splash.php
 * PWA Splash Screen — GROUP GLOBAL ISO
 *
 * Incluir justo después de <body> en layout.php y login.php.
 * Solo se muestra en modo PWA standalone (instalada en el dispositivo)
 * y únicamente en el primer acceso de cada sesión de navegación.
 *
 * No bloquea el render ni añade peticiones extra al servidor.
 */
if (!defined('APP_URL')) return; // Seguridad: solo incluir desde un contexto válido
$_splash_logo_v = @filemtime(__DIR__ . '/../assets/logo-ggi.png') ?: '1';
?>

<!-- ══════════════════════════════════════════════════════════════
     PWA SPLASH SCREEN — GROUP GLOBAL ISO
     Se oculta automáticamente cuando el contenido termina de cargar.
     ══════════════════════════════════════════════════════════════ -->
<div id="ggi-splash" aria-hidden="true" role="presentation">
  <div class="ggi-splash-inner">

    <!-- Logo corporativo -->
    <div class="ggi-splash-logo-wrap">
      <img
        src="<?= APP_URL ?>/assets/logo-ggi.png?v=<?= $_splash_logo_v ?>"
        alt="Group Global ISO"
        class="ggi-splash-logo"
        draggable="false"
        loading="eager"
      >
    </div>

    <!-- Nombre de la empresa -->
    <div class="ggi-splash-name">GROUP GLOBAL ISO</div>

    <!-- Tagline -->
    <div class="ggi-splash-tag">Sistema Integral de Gestión</div>

    <!-- Indicador de carga -->
    <div class="ggi-splash-loader-wrap" aria-hidden="true">
      <div class="ggi-splash-loader-bar"></div>
    </div>

  </div>
</div>

<style>
/* ════════════════════════════════════════════════════════════════
   GGI PWA SPLASH SCREEN — Identidad Corporativa
   ════════════════════════════════════════════════════════════════ */

/* En modo PWA el html/body debe ser azul para que la barra de
   estado no muestre un flash blanco antes de que el splash cargue */
@media (display-mode: standalone) {
  html, body { background: #1630C5 !important; }
}

/* ── Contenedor principal ── */
#ggi-splash {
  position: fixed;
  inset: 0;
  z-index: 999999;

  /* Fondo azul corporativo — constante en light y dark */
  background: linear-gradient(145deg, #1A3A8F 0%, #1630C5 60%, #1A3A8F 100%);

  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;

  /* Safe area iPhone notch / Dynamic Island */
  padding-top:    env(safe-area-inset-top,    0px);
  padding-bottom: env(safe-area-inset-bottom, 0px);
  padding-left:   env(safe-area-inset-left,   0px);
  padding-right:  env(safe-area-inset-right,  0px);

  /* Transición de salida */
  opacity: 1;
  visibility: visible;
  transition: opacity 0.45s cubic-bezier(.4,0,.2,1),
              visibility 0.45s cubic-bezier(.4,0,.2,1);
  will-change: opacity;
  pointer-events: all;
  -webkit-tap-highlight-color: transparent;
  user-select: none;
  touch-action: none;
}

/* ── Fase: desvaneciendo ── */
#ggi-splash.ggi-splash-out {
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
}

/* ── Fase: eliminado del flujo ── */
#ggi-splash.ggi-splash-gone {
  display: none !important;
}

/* ── Contenido centrado ── */
.ggi-splash-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  padding: 0 40px;
  max-width: 380px;
}

/* ── Logo ── */
.ggi-splash-logo-wrap {
  width: 210px;
  max-width: 64vw;
  margin-bottom: 26px;
  animation: ggiSplashLogoIn 0.70s cubic-bezier(.16,1,.3,1) both;
}
.ggi-splash-logo {
  width: 100%;
  height: auto;
  display: block;
  /* Invertir a blanco sobre fondo azul */
  filter: brightness(0) invert(1);
  object-fit: contain;
  -webkit-user-drag: none;
}

/* ── Nombre empresa ── */
.ggi-splash-name {
  font-family: 'Inter', 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: #FFFFFF;
  letter-spacing: 0.13em;
  text-transform: uppercase;
  margin-bottom: 7px;
  text-align: center;
  animation: ggiSplashTextIn 0.55s 0.24s cubic-bezier(.16,1,.3,1) both;
}

/* ── Tagline ── */
.ggi-splash-tag {
  font-family: 'Inter', 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  font-size: 11.5px;
  font-weight: 400;
  color: rgba(255, 255, 255, 0.65);
  letter-spacing: 0.04em;
  margin-bottom: 44px;
  text-align: center;
  animation: ggiSplashTextIn 0.55s 0.38s cubic-bezier(.16,1,.3,1) both;
}

/* ── Indicador de carga (barra pulsante) ── */
.ggi-splash-loader-wrap {
  width: 44px;
  height: 3px;
  background: rgba(255, 255, 255, 0.15);
  border-radius: 100px;
  overflow: hidden;
  animation: ggiSplashTextIn 0.4s 0.55s ease both;
}
.ggi-splash-loader-bar {
  width: 100%;
  height: 100%;
  background: rgba(255, 255, 255, 0.70);
  border-radius: 100px;
  transform-origin: left center;
  animation: ggiSplashPulse 1.5s 0.7s ease-in-out infinite;
}

/* ════════════════════
   Keyframes
════════════════════ */
@keyframes ggiSplashLogoIn {
  from {
    opacity: 0;
    transform: scale(0.86) translateY(10px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

@keyframes ggiSplashTextIn {
  from {
    opacity: 0;
    transform: translateY(9px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes ggiSplashPulse {
  0%   { opacity: 0.40; transform: scaleX(0.45) translateX(0%); }
  50%  { opacity: 1.00; transform: scaleX(1.00) translateX(0%); }
  100% { opacity: 0.40; transform: scaleX(0.45) translateX(0%); }
}

/* ── Respeta reduced-motion ── */
@media (prefers-reduced-motion: reduce) {
  .ggi-splash-logo-wrap,
  .ggi-splash-name,
  .ggi-splash-tag,
  .ggi-splash-loader-wrap { animation: none !important; }
  .ggi-splash-loader-bar  { animation: ggiSplashPulse 2.5s 0.3s ease-in-out infinite; }
  #ggi-splash { transition-duration: 0.25s !important; }
}
</style>

<script>
/* ══════════════════════════════════════════════════════════
   GGI PWA SPLASH — Controlador
   Muestra el splash solo en modo standalone PWA (instalada).
   Se oculta automáticamente al terminar la carga de la página.
   ══════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var splash = document.getElementById('ggi-splash');
  if (!splash) return;

  /* ── 1. Detectar modo PWA standalone ── */
  var isStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true ||          // Safari iOS
    document.referrer.includes('android-app://');    // TWA Android

  /* ── 2. Detectar primer acceso de esta sesión ─────────────────
     sessionStorage se limpia al cerrar el navegador/app,
     por lo que cada nueva apertura del icono muestra el splash.  */
  var SESSION_KEY  = 'ggi_pwa_session';
  var isFirstLoad  = false;

  try {
    if (!sessionStorage.getItem(SESSION_KEY)) {
      isFirstLoad = true;
      sessionStorage.setItem(SESSION_KEY, '1');
    }
  } catch (e) {
    /* Si sessionStorage no está disponible, mostrar siempre */
    isFirstLoad = true;
  }

  /* ── 3. Si no aplica, ocultar al instante (sin transición) ── */
  if (!isStandalone || !isFirstLoad) {
    splash.classList.add('ggi-splash-gone');
    return;
  }

  /* ── 4. Ocultar el splash ── */
  var _dismissed = false;

  function hideSplash() {
    if (_dismissed) return;
    _dismissed = true;

    splash.classList.add('ggi-splash-out');

    /* Remover del DOM después de la transición CSS (0.45s) */
    setTimeout(function () {
      splash.classList.add('ggi-splash-gone');
    }, 500);
  }

  /* ── 5. Estrategia temporal ────────────────────────────────
     - Mínimo 1 200 ms: la animación del logo necesita ~700 ms,
       más el tiempo para que el usuario perciba la marca.
     - Máximo 3 000 ms: nunca bloquear más de 3 s.
     - En cuanto el documento esté listo, esperar el mínimo
       restante y ocultar.                                     */
  var MIN_MS  = 1200;
  var MAX_MS  = 3000;
  var t0      = Date.now();

  /* Fallback de seguridad */
  var safetyTimer = setTimeout(hideSplash, MAX_MS);

  function onReady() {
    clearTimeout(safetyTimer);
    var elapsed  = Date.now() - t0;
    var waitMore = Math.max(0, MIN_MS - elapsed);
    setTimeout(hideSplash, waitMore);
  }

  if (document.readyState === 'complete') {
    onReady();
  } else {
    window.addEventListener('load', onReady, { once: true });
  }

})();
</script>
