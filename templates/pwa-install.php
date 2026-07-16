<?php
/**
 * templates/pwa-install.php
 * Banner de sugerencia de instalación PWA — GROUP GLOBAL ISO
 * Componente autónomo — no depende del design system de layout.php.
 */
if (!defined('APP_URL')) return;
$_icon_v = @filemtime(__DIR__ . '/../assets/icons/icon-192.png') ?: '1';
?>

<div id="ggi-pwa-banner" hidden aria-live="polite" role="complementary" aria-label="Sugerencia de instalación">

  <!-- Panel A: Android / Chrome / Desktop -->
  <div id="ggi-pwa-panel-a">
    <img src="<?= APP_URL ?>/assets/icons/icon-192.png?v=<?= $_icon_v ?>"
         alt="Group Global ISO" class="gpwa-icon" draggable="false">
    <div class="gpwa-text">
      <span class="gpwa-name">Group Global ISO</span>
      <span class="gpwa-desc">Agrega el acceso rápido a tu inicio</span>
    </div>
    <button id="gpwa-btn-close" class="gpwa-btn-x" type="button" aria-label="Cerrar">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
    <button id="gpwa-btn-install" class="gpwa-btn-install" type="button">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 15V3m0 12l-4-4m4 4l4-4M2 17l.621 2.485A2 2 0 004.561 21h14.878a2 2 0 001.94-1.515L22 17"/>
      </svg>
      Instalar aplicación
    </button>
  </div>

  <!-- Panel B: iOS Safari -->
  <div id="ggi-pwa-panel-b" hidden>
    <div class="gpwa-ios-header">
      <img src="<?= APP_URL ?>/assets/icons/icon-192.png?v=<?= $_icon_v ?>"
           alt="" class="gpwa-icon gpwa-icon-sm" draggable="false" aria-hidden="true">
      <div class="gpwa-text">
        <span class="gpwa-name">Instalar Group Global ISO</span>
        <span class="gpwa-desc">Agrega la app a tu pantalla de inicio</span>
      </div>
      <button id="gpwa-btn-close-ios" class="gpwa-btn-x" type="button" aria-label="Cerrar">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="gpwa-ios-steps">
      <div class="gpwa-ios-step">
        <span class="gpwa-step-num">1</span>
        <span>Toca <strong>Compartir</strong>
          <svg style="display:inline;vertical-align:middle;margin:0 1px" width="13" height="13"
               fill="none" viewBox="0 0 24 24" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/>
            <polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/>
          </svg>
          en la barra de Safari
        </span>
      </div>
      <div class="gpwa-ios-step">
        <span class="gpwa-step-num">2</span>
        <span>Selecciona <strong>"Agregar a inicio"</strong></span>
      </div>
      <div class="gpwa-ios-step">
        <span class="gpwa-step-num">3</span>
        <span>Confirma tocando <strong>"Agregar"</strong></span>
      </div>
    </div>
    <div class="gpwa-ios-arrow" aria-hidden="true">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 5v14m0 0l-5-5m5 5l5-5"/>
      </svg>
    </div>
    <!-- Botón "Ya lo tengo" — descarte permanente para iOS -->
    <div class="gpwa-ios-already">
      <button id="gpwa-btn-already" type="button">Ya lo tengo instalado</button>
    </div>
  </div>

</div>

<style>
/* ════════════════════════════════════════════════════
   Variables propias — autónomo, sin depender del layout
   ════════════════════════════════════════════════════ */
#ggi-pwa-banner {
  --gpwa-bg:      #ffffff;
  --gpwa-brd:     #E2E8F0;
  --gpwa-brd2:    #F0F2F8;
  --gpwa-tx:      #0F172A;
  --gpwa-tx2:     #64748B;
  --gpwa-tx3:     #94A3B8;
  --gpwa-ac:      #1D4ED8;
  --gpwa-ac-h:    #1E40AF;
  --gpwa-ac-soft: #EFF6FF;
  --gpwa-hover:   #F1F5F9;
  --gpwa-shadow:  0 4px 24px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.06);
}
:root[data-tema="oscuro"] #ggi-pwa-banner {
  --gpwa-bg:      #111827;
  --gpwa-brd:     #1E293B;
  --gpwa-brd2:    #1E2D45;
  --gpwa-tx:      #E5E7EB;
  --gpwa-tx2:     #9CA3AF;
  --gpwa-tx3:     #4B5563;
  --gpwa-ac:      #3B82F6;
  --gpwa-ac-h:    #2563EB;
  --gpwa-ac-soft: #1E3058;
  --gpwa-hover:   #1F2937;
  --gpwa-shadow:  0 4px 24px rgba(0,0,0,.40), 0 1px 4px rgba(0,0,0,.20);
}
@media (prefers-color-scheme: dark) {
  :root:not([data-tema="claro"]) #ggi-pwa-banner {
    --gpwa-bg:      #111827;
    --gpwa-brd:     #1E293B;
    --gpwa-brd2:    #1E2D45;
    --gpwa-tx:      #E5E7EB;
    --gpwa-tx2:     #9CA3AF;
    --gpwa-tx3:     #4B5563;
    --gpwa-ac:      #3B82F6;
    --gpwa-ac-h:    #2563EB;
    --gpwa-ac-soft: #1E3058;
    --gpwa-hover:   #1F2937;
    --gpwa-shadow:  0 4px 24px rgba(0,0,0,.40), 0 1px 4px rgba(0,0,0,.20);
  }
}

/* ── Contenedor ── */
#ggi-pwa-banner {
  position: fixed;
  bottom: 0; left: 0; right: 0;
  /* Encima del sidebar (z-index 101) y su overlay */
  z-index: 102000;
  display: flex;
  justify-content: center;
  /* Sin padding lateral — borde a borde en móvil */
  padding: 0;
  pointer-events: none;
  opacity: 0;
  transform: translateY(20px);
  transition: opacity .32s cubic-bezier(.16,1,.3,1),
              transform .32s cubic-bezier(.16,1,.3,1);
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
#ggi-pwa-banner.gpwa-in  { opacity: 1; transform: translateY(0); pointer-events: all; }
#ggi-pwa-banner.gpwa-out { opacity: 0; transform: translateY(16px); pointer-events: none; }

/* ── Forzar que hidden funcione aunque haya display: grid/flex en el ID ── */
#ggi-pwa-panel-a[hidden],
#ggi-pwa-panel-b[hidden] { display: none !important; }

/* ── Tarjeta base — bottom sheet nativo en móvil ── */
#ggi-pwa-panel-a,
#ggi-pwa-panel-b {
  background: var(--gpwa-bg);
  /* Solo borde superior — los lados tocan el viewport */
  border-top: 1px solid var(--gpwa-brd);
  border-left: none; border-right: none; border-bottom: none;
  /* Redondeado solo arriba */
  border-radius: 20px 20px 0 0;
  box-shadow: 0 -4px 24px rgba(0,0,0,.12), 0 -1px 6px rgba(0,0,0,.06);
  width: 100%;
  max-width: 100%;
  overflow: hidden;
}
/* Manija visual (pill) — estilo nativo iOS / Android */
#ggi-pwa-panel-a::before,
#ggi-pwa-panel-b::before {
  content: '';
  display: block;
  width: 36px; height: 4px;
  background: var(--gpwa-tx3);
  border-radius: 2px;
  margin: 10px auto 0;
  opacity: .45;
}
/* En Panel A (grid) hay que hacer que la pill ocupe todas las columnas */
#ggi-pwa-panel-a::before {
  grid-column: 1 / -1;
  grid-row: 1;
  align-self: start;
  margin-bottom: 0;
}
/* En Panel A (grid): icon/texto/X van en fila 2; botón instalar en fila 3 */
#ggi-pwa-panel-a .gpwa-icon  { grid-row: 2; }
#ggi-pwa-panel-a .gpwa-text  { grid-row: 2; }
#ggi-pwa-panel-a .gpwa-btn-x { grid-row: 2; }
.gpwa-btn-install             { grid-row: 3 !important; }

/* ══════════════════════════════════════════════════
   PANEL A — MÓVIL: grid de 2 filas
   Fila 1: [icono] [texto]        [×]
   Fila 2:         [─── Instalar ──────────────]
   ══════════════════════════════════════════════════ */
#ggi-pwa-panel-a {
  display: grid;
  grid-template-columns: 48px 1fr 36px;
  /* fila 1 = pill handle (16px), fila 2 = icono+texto+X, fila 3 = botón instalar */
  grid-template-rows: 16px auto auto;
  column-gap: 10px;
  row-gap: 8px;
  /* top reducido porque ya tiene la manija (::before) */
  padding: 6px 16px max(16px, calc(env(safe-area-inset-bottom, 0px) + 14px));
  align-items: center;
}

.gpwa-icon {
  grid-column: 1;
  grid-row: 1;
  width: 48px; height: 48px;
  border-radius: 12px;
  object-fit: cover; display: block;
  align-self: center;
}
.gpwa-icon-sm { width: 36px; height: 36px; border-radius: 9px; }

.gpwa-text {
  grid-column: 2;
  grid-row: 1;
  display: flex; flex-direction: column; gap: 2px;
  min-width: 0;
}
.gpwa-name {
  font-size: 13px; font-weight: 700;
  color: var(--gpwa-tx); display: block;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  line-height: 1.3;
}
.gpwa-desc {
  font-size: 11.5px; color: var(--gpwa-tx2);
  line-height: 1.3; display: block;
}

/* Botón cerrar — esquina superior derecha */
.gpwa-btn-x {
  grid-column: 3;
  grid-row: 1;
  width: 32px; height: 32px;
  border-radius: 8px;
  background: transparent; border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: var(--gpwa-tx3);
  transition: background .13s, color .13s;
  justify-self: end; align-self: start;
}
.gpwa-btn-x:hover { background: var(--gpwa-hover); color: var(--gpwa-tx2); }

/* Botón instalar — fila 2, ocupa columnas 2 y 3 */
.gpwa-btn-install {
  grid-column: 2 / 4;
  grid-row: 2;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  width: 100%;
  padding: 9px 16px; border-radius: 10px;
  background: var(--gpwa-ac); color: #ffffff;
  font-family: inherit; font-size: 13px; font-weight: 700;
  border: none; cursor: pointer;
  transition: background .15s, box-shadow .15s, transform .1s;
  letter-spacing: -.01em; line-height: 1;
}
.gpwa-btn-install:hover  { background: var(--gpwa-ac-h); box-shadow: 0 3px 12px rgba(29,78,216,.30); }
.gpwa-btn-install:active { transform: scale(.98); }

/* ══════════════════════════════════════════════════
   PANEL A — DESKTOP (≥ 520px): tarjeta flotante
   ══════════════════════════════════════════════════ */
@media (min-width: 520px) {
  /* Vuelve a ser esquina inferior-derecha flotante */
  #ggi-pwa-banner {
    left: auto; right: 20px; bottom: 20px;
    width: auto;
    justify-content: flex-end;
  }
  #ggi-pwa-panel-a,
  #ggi-pwa-panel-b {
    width: 360px; max-width: 360px;
    /* Tarjeta flotante: todos los bordes redondeados */
    border: 1px solid var(--gpwa-brd);
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0,0,0,.14), 0 2px 8px rgba(0,0,0,.06);
  }
  /* Sin manija en desktop */
  #ggi-pwa-panel-a::before,
  #ggi-pwa-panel-b::before { display: none; }

  /* Una fila: icon | texto | botón | × */
  #ggi-pwa-panel-a {
    grid-template-columns: 44px 1fr auto 32px;
    /* Desktop: sin pill → solo 1 fila */
    grid-template-rows: auto;
    padding: 12px 10px 12px 12px;
    row-gap: 0;
    align-items: center;
  }
  /* Mover todo a fila 1 en desktop (pill está oculto) */
  #ggi-pwa-panel-a::before { display: none; }
  #ggi-pwa-panel-a .gpwa-icon  { grid-row: 1; width: 44px; height: 44px; border-radius: 11px; }
  #ggi-pwa-panel-a .gpwa-text  { grid-column: 2; grid-row: 1; }
  #ggi-pwa-panel-a .gpwa-btn-x { grid-column: 4; grid-row: 1; align-self: center; }
  .gpwa-btn-install {
    grid-column: 3 !important; grid-row: 1 !important;
    width: auto; padding: 7px 13px; font-size: 12px; border-radius: 8px;
  }
}

/* ══ Panel iOS ══ */
#ggi-pwa-panel-b { display: flex; flex-direction: column; }

.gpwa-ios-header {
  display: flex; align-items: center; gap: 10px;
  /* top reducido por la manija (::before) */
  padding: 10px 10px 12px 16px;
  border-bottom: 1px solid var(--gpwa-brd2);
}
.gpwa-ios-header .gpwa-text { flex: 1; }

.gpwa-ios-steps {
  padding: 12px 16px 8px;
  display: flex; flex-direction: column; gap: 9px;
}
.gpwa-ios-step {
  display: flex; align-items: center; gap: 10px;
  font-size: 12.5px; color: var(--gpwa-tx2); line-height: 1.35;
}
.gpwa-ios-step strong { color: var(--gpwa-tx); }

.gpwa-step-num {
  width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0;
  background: var(--gpwa-ac-soft); color: var(--gpwa-ac);
  font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}

.gpwa-ios-arrow {
  display: flex; justify-content: center;
  padding: 4px 0 8px;
  color: var(--gpwa-ac); opacity: .45;
}
.gpwa-ios-arrow svg { animation: gpwaArrow 1.4s ease-in-out infinite; }
@keyframes gpwaArrow {
  0%,100% { transform: translateY(0); }
  50%      { transform: translateY(3px); }
}

/* ── "Ya lo tengo instalado" — descarte permanente (iOS) ── */
.gpwa-ios-already {
  padding: 0 16px max(14px, calc(env(safe-area-inset-bottom, 0px) + 10px));
}
#gpwa-btn-already {
  width: 100%;
  padding: 9px 16px; border-radius: 10px;
  background: transparent;
  border: 1.5px solid var(--gpwa-brd);
  color: var(--gpwa-tx2);
  font-family: inherit; font-size: 12px; font-weight: 600;
  cursor: pointer;
  transition: background .13s, color .13s;
}
#gpwa-btn-already:hover { background: var(--gpwa-hover); color: var(--gpwa-tx); }

/* ── Reduced motion ── */
@media (prefers-reduced-motion: reduce) {
  #ggi-pwa-banner { transition-duration: .15s !important; }
  .gpwa-ios-arrow svg { animation: none !important; }
}
</style>

<script>
(function () {
  'use strict';

  var isStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true ||
    document.referrer.includes('android-app://');
  if (isStandalone) return;

  var LS_DISMISS   = 'ggi_pwa_dismiss';
  var LS_INSTALLED = 'ggi_pwa_done';
  var DAYS_30      = 30 * 86400000;
  try {
    if (localStorage.getItem(LS_INSTALLED)) return;
    var ts = localStorage.getItem(LS_DISMISS);
    if (ts && (Date.now() - parseInt(ts, 10)) < DAYS_30) return;
  } catch (e) {}

  var ua          = navigator.userAgent || '';
  var isIOS       = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
  var isSafari    = /Safari/.test(ua) && !/Chrome|CriOS|FxiOS|EdgiOS/.test(ua);
  var isIOSSafari = isIOS && isSafari;

  var banner = document.getElementById('ggi-pwa-banner');
  var panelA = document.getElementById('ggi-pwa-panel-a');
  var panelB = document.getElementById('ggi-pwa-panel-b');
  if (!banner) return;

  var _deferred = null;
  var _shown    = false;

  function show() {
    if (_shown) return;
    _shown = true;
    banner.hidden = false;
    void banner.offsetWidth;
    banner.classList.add('gpwa-in');
  }

  function hide(permanent) {
    banner.classList.replace('gpwa-in', 'gpwa-out');
    setTimeout(function () { banner.hidden = true; }, 350);
    try {
      if (permanent) localStorage.setItem(LS_INSTALLED, '1');
      else           localStorage.setItem(LS_DISMISS, Date.now().toString());
    } catch (e) {}
  }

  if (!isIOSSafari) {
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      _deferred = e;
      setTimeout(show, 4000);
    });
    window.addEventListener('appinstalled', function () { hide(true); });

    var btnI = document.getElementById('gpwa-btn-install');
    if (btnI) btnI.addEventListener('click', function () {
      if (!_deferred) return;
      _deferred.prompt();
      _deferred.userChoice.then(function (r) {
        _deferred = null;
        if (r.outcome === 'accepted') hide(true);
      });
    });
    var btnC = document.getElementById('gpwa-btn-close');
    if (btnC) btnC.addEventListener('click', function () { hide(false); });

  } else {
    panelA.hidden = true;
    panelB.hidden = false;
    setTimeout(show, 5000);
    var btnCI = document.getElementById('gpwa-btn-close-ios');
    if (btnCI) btnCI.addEventListener('click', function () { hide(false); });
    /* "Ya lo tengo instalado" → descarte permanente */
    var btnAlready = document.getElementById('gpwa-btn-already');
    if (btnAlready) btnAlready.addEventListener('click', function () { hide(true); });
  }

})();
</script>
