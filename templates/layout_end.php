<?php if (!empty($_ggi_embed)): ?></div><?php else: ?></main><?php endif; ?>



<!-- ═══════════════════════════════════════════════════════════════════════
     PAGINACIÓN GLOBAL — aplica a todos los módulos.
     Los módulos no deben definir sus propios estilos de paginación.
     ═══════════════════════════════════════════════════════════════════════ -->




<!-- ShortcutManager — sistema centralizado de atajos de teclado -->
<script src="<?= APP_URL ?>/assets/js/shortcuts.js?v=<?= @filemtime(__DIR__ . '/../assets/js/shortcuts.js') ?>"></script>

<!-- Modal mantenimiento -->
<div class="mant-modal-overlay" id="mant-modal" onclick="mantCloseOnBg(event)">
  <div class="mant-modal-box" role="dialog" aria-modal="true" aria-labelledby="mant-modal-title">
    <div class="mant-modal-header">
      <div class="mant-modal-title" id="mant-modal-title">Nuevo registro</div>
      <button type="button" class="mant-modal-close" onclick="ggiModalClose()" aria-label="Cerrar">
        <!-- Desktop: X -->
        <span class="ggi-close-icon-desktop" aria-hidden="true">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </span>
        <!-- Mobile: ← Volver -->
        <span class="ggi-close-icon-mobile" style="display:none;align-items:center;gap:5px;font-size:14px;font-weight:600;color:var(--azul,#1A3A8F);">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
          Volver
        </span>
      </button>
    </div>
    <div class="mant-modal-body" id="mant-modal-body"></div>
  </div>
</div>

<!-- Banner: cambios sin guardar (dirty-check) -->
<div id="ggi-dirty-banner" role="dialog" aria-modal="true" aria-labelledby="ggi-dirty-title">
  <div id="ggi-dirty-box">
    <h3 id="ggi-dirty-title">Cambios sin guardar</h3>
    <p>Tienes cambios que no se han guardado. Si sales ahora, se perderán.</p>
    <div class="ggi-dirty-btns">
      <button type="button" class="btn btn-outline" onclick="ggiDirtyCancel()">
        Continuar editando
      </button>
      <button type="button" class="btn" style="background:#ef4444;color:#fff;border-color:#ef4444;" onclick="ggiDirtyConfirm()">
        Salir sin guardar
      </button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container" role="region" aria-label="Notificaciones" aria-live="polite" aria-atomic="false"></div>

<script>
window.APP_URL    = '<?= APP_URL ?>';
window.CSRF_TOKEN = '<?= csrf_token() ?>';
window.ACTIVE_MENU = '<?= addslashes($active_menu ?? '') ?>';
window.VAPID_PUBLIC_KEY = '<?= defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : '' ?>';
</script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>


<script src="<?= APP_URL ?>/assets/js/validacion.js?v=2"></script>


<script>
/* Botón × universal para buscadores de listado.
   Se expone como window.initSearchClearBtns(root) para poder llamarse
   tanto en el DOMContentLoaded inicial como después de cada swap SPA. */
window.initSearchClearBtns = function(root) {
  (root || document).querySelectorAll('.search-wrap').forEach(function(wrap) {
    if (wrap.querySelector('.search-clear-btn')) return; // ya inicializado
    var inp = wrap.querySelector('input[name="q"]');
    if (!inp) return;
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'search-clear-btn'; btn.title = 'Limpiar';
    btn.innerHTML = '<svg width="8" height="8" viewBox="0 0 8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="1" y1="1" x2="7" y2="7"/><line x1="7" y1="1" x2="1" y2="7"/></svg>';
    btn.style.display = inp.value.trim() ? 'block' : 'none';
    btn.addEventListener('click', function() {
      inp.value = '';
      btn.style.display = 'none';
      /* Navegar al pathname sin parámetros → PHP aplica todos sus defaults
         (estado=activo, sin búsqueda, sin filtros). Más limpio que resetear
         cada select manualmente y evita quedarse con filtros residuales. */
      var form = inp.form;
      if (!form) return;
      try {
        var base = new URL(form.getAttribute('action') || location.pathname, location.href).pathname;
        if (typeof window.ggiNavigate === 'function') { window.ggiNavigate(base); return; }
      } catch(x) {}
      form.reset();
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    inp.addEventListener('input', function() {
      btn.style.display = inp.value.trim() ? 'block' : 'none';
    });
    wrap.appendChild(btn);
  });
};
document.addEventListener('DOMContentLoaded', function() { window.initSearchClearBtns(); });
</script>

<script>
/**
 * ggiEliminar(url, formData, onSuccess)
 * ─────────────────────────────────────────────────────────────────────────────
 * Flujo de eliminación con previsualización de dependencias:
 *   1. Llama al endpoint con preview=1  → obtiene info (nombre, estado, deps)
 *   2. Muestra modal con los detalles y la lista de registros afectados
 *   3. Si el usuario confirma → llama con preview=0 → elimina
 *
 * Parámetros:
 *   url        — URL del ajax_eliminar.php
 *   formData   — FormData con los IDs y el _csrf (SIN preview)
 *   onSuccess  — función(row?) llamada tras éxito para quitar la fila del DOM
 *   titulo     — título del modal (opcional)
 *   labelTipo  — "envío", "propuesta", etc. (opcional, para el mensaje genérico)
 */
/**
 * ggiToastUndo(modulo, id, msg) — toast "Deshacer" tras una eliminación lógica.
 * El endpoint ajax_restaurar.php valida whitelist de módulos, ventana de tiempo
 * y autoría (solo quien borró puede deshacer, salvo gerente general).
 * Al deshacer con éxito recarga la página para restaurar la fila.
 */
window.ggiToastUndo = function(modulo, id, msg) {
  if (typeof showToast !== 'function') return;
  showToast({
    title: msg || 'Eliminado correctamente.',
    action: {
      label: 'Deshacer',
      onClick: async function() {
        // ggiPost lee el token vigente de la meta tag: window.CSRF_TOKEN
        // puede haber quedado obsoleto tras una navegación SPA.
        var dd = await ggiPost('<?= APP_URL ?>/modules/ajax_restaurar.php',
                               { modulo: modulo, id: id });
        if (dd.ok) {
          if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
          location.reload();
        }
      }
    }
  }, 'ok', 6000);
};

window.ggiEliminar = async function(url, formData, onSuccess, titulo, labelTipo) {
  // Quitar modal anterior
  var prev = document.getElementById('ggi-del-modal-backdrop');
  if (prev) prev.remove();

  titulo    = titulo    || 'Eliminar registro';
  labelTipo = labelTipo || 'registro';

  // Step 1 — previsualización (no elimina)
  var fdPreview = new FormData();
  formData.forEach(function(v, k) { fdPreview.append(k, v); });
  fdPreview.set('preview', '1');

  // silent: el error se muestra abajo con el mensaje propio de esta función
  var info = await ggiPost(url, fdPreview, { silent: true });

  if (info && !info.ok) {
    if (typeof showToast === 'function') showToast(info.msg || 'Error al verificar el registro.', 'error');
    return;
  }

  // Construir contenido del modal con la info recibida
  var nombre   = (info && (info.empresa || info.numero || info.codigo)) || ('Este ' + labelTipo);
  var estado   = info && info.estado ? ' <span class="badge-gray" style="font-size:10px;">' + info.estado + '</span>' : '';
  var norma    = info && info.norma  ? ' · ' + info.norma : '';
  var ruc      = info && info.ruc    ? ' <span style="font-size:11px;color:var(--tx2);">RUC: ' + info.ruc + '</span>' : '';
  var hayDeps  = info && info.hay_deps;
  var depsList = '';
  if (hayDeps && info.deps && info.deps.length) {
    depsList = '<ul style="margin:8px 0 0 16px;font-size:12px;color:var(--tx2);line-height:1.7;">' +
      info.deps.map(function(d){ return '<li>' + d + '</li>'; }).join('') +
      '</ul>';
  }

  var advertencia = hayDeps
    ? 'Este ' + labelTipo + ' tiene registros asociados que <strong>seguirán existiendo</strong> en el sistema:<br>' + depsList + '<br>Solo se ocultará el ' + labelTipo + ' del listado.'
    : 'El ' + labelTipo + ' desaparecerá del listado. Podrás deshacerlo durante unos segundos desde el aviso.';

  var backdrop = document.createElement('div');
  backdrop.id = 'ggi-del-modal-backdrop';
  backdrop.innerHTML =
    '<div id="ggi-del-modal">' +
      '<h3>' + titulo + '</h3>' +
      '<p style="font-size:13px;color:var(--tx);font-weight:600;margin:0 0 4px;">' + nombre + norma + estado + ruc + '</p>' +
      '<p style="font-size:12px;color:var(--tx2);margin:0 0 16px;">¿Está seguro de eliminar este ' + labelTipo + '?</p>' +
      '<div class="ggi-del-warning">' +
        '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
        '<div>' + advertencia + '</div>' +
      '</div>' +
      '<div class="ggi-del-actions">' +
        '<button class="btn btn-outline btn-sm" id="ggi-del-cancelar">Cancelar</button>' +
        '<button class="btn btn-sm" id="ggi-del-confirmar" style="background:var(--s-err);color:#fff;border-color:var(--s-err)">Sí, eliminar</button>' +
      '</div>' +
    '</div>';

  document.body.appendChild(backdrop);

  function cerrar() { backdrop.remove(); }
  document.getElementById('ggi-del-cancelar').addEventListener('click', cerrar);
  backdrop.addEventListener('click', function(e) { if (e.target === backdrop) cerrar(); });
  document.addEventListener('keydown', function esc(e) {
    if (e.key === 'Escape') { cerrar(); document.removeEventListener('keydown', esc); }
  });

  document.getElementById('ggi-del-confirmar').addEventListener('click', async function() {
    var btn = this; btn.disabled = true; btn.textContent = 'Eliminando…';
    try {
      // Step 2 — eliminar definitivamente
      formData.set('preview', '0');
      // silent: los avisos de esta función son propios (incluye "Deshacer")
      var data = await ggiPost(url, formData, { silent: true });
      if (data.ok) {
        // "Deshacer": módulo desde la URL del endpoint, id desde el primer campo *_id.
        // El endpoint de restauración valida ventana de tiempo y autoría (deleted_by).
        var _undoMod = (url.match(/\/modules\/(\w+)\/ajax_eliminar\.php/) || [])[1] || '';
        var _undoId  = 0;
        formData.forEach(function(v, k) { if (!_undoId && /_id$/.test(k)) _undoId = parseInt(v) || 0; });

        if (_undoMod && _undoId) {
          ggiToastUndo(_undoMod, _undoId, data.msg || 'Eliminado correctamente.');
        } else if (typeof showToast === 'function') {
          showToast(data.msg || 'Eliminado correctamente.', 'ok');
        }
        if (typeof onSuccess === 'function') onSuccess();
        if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'No se pudo eliminar.', 'error');
      }
    } catch(err) {
      if (typeof showToast === 'function') showToast('Error de conexión al eliminar.', 'error');
    }
    cerrar();
  });
};

/**
 * ggiConfirmarEliminar(opts) — API simple (sin preview).
 * Mantener por compatibilidad con código antiguo.
 */
window.ggiConfirmarEliminar = function(opts) {
  var prev = document.getElementById('ggi-del-modal-backdrop');
  if (prev) prev.remove();
  var advertencia = opts.advertencia || 'Esta acción no se puede deshacer desde la aplicación.';
  var backdrop = document.createElement('div');
  backdrop.id = 'ggi-del-modal-backdrop';
  backdrop.innerHTML =
    '<div id="ggi-del-modal">' +
      '<h3>' + (opts.titulo || 'Eliminar registro') + '</h3>' +
      '<p>' + (opts.mensaje || '¿Está seguro?') + '</p>' +
      '<div class="ggi-del-warning">' +
        '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
        '<span>' + advertencia + '</span>' +
      '</div>' +
      '<div class="ggi-del-actions">' +
        '<button class="btn btn-outline btn-sm" id="ggi-del-cancelar">Cancelar</button>' +
        '<button class="btn btn-sm" id="ggi-del-confirmar" style="background:var(--s-err);color:#fff;border-color:var(--s-err)">' + (opts.labelConfirmar || 'Eliminar') + '</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(backdrop);
  function cerrar() { backdrop.remove(); }
  document.getElementById('ggi-del-cancelar').addEventListener('click', cerrar);
  backdrop.addEventListener('click', function(e) { if (e.target === backdrop) cerrar(); });
  document.addEventListener('keydown', function esc(e) { if (e.key==='Escape') { cerrar(); document.removeEventListener('keydown', esc); }});
  document.getElementById('ggi-del-confirmar').addEventListener('click', async function() {
    var btn = this; btn.disabled = true; btn.textContent = 'Eliminando…';
    try { await opts.onConfirmar(); } catch(e) { if (typeof showToast==='function') showToast('Error inesperado.','error'); }
    cerrar();
  });
};
</script>



<script>
/* ── Helpers de color (compartidos por ggiApplySelectColors e initEstadoSel) ── */
(function() {
  function _hexToRgb(hex) {
    hex = hex.trim(); if (hex.charAt(0)!=='#') hex='#'+hex;
    var h = hex.replace('#','');
    if (h.length===3) h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
    if (h.length!==6) return null;
    var r=parseInt(h.substring(0,2),16), g=parseInt(h.substring(2,4),16), b=parseInt(h.substring(4,6),16);
    return (isNaN(r)||isNaN(g)||isNaN(b)) ? null : [r,g,b];
  }
  function _applyBadgeColor(hex, target) {
    var rgb = _hexToRgb(hex); if (!rgb) return;
    var r=rgb[0],g=rgb[1],b=rgb[2];
    var isDark = document.documentElement.getAttribute('data-tema')==='oscuro';
    if (isDark) {
      // Fondo más oscuro (.18) y texto más claro (.52 + blanco .48) para garantizar
      // contraste WCAG AA (≥4.5:1) incluso en tonos medios como el azul. (skill §1)
      target.style.background  = 'rgb('+((r*.18+24*.82)|0)+','+((g*.18+33*.82)|0)+','+((b*.18+47*.82)|0)+')';
      target.style.color       = 'rgb('+((r*.52+255*.48)|0)+','+((g*.52+255*.48)|0)+','+((b*.52+255*.48)|0)+')';
      target.style.borderColor = 'rgba('+r+','+g+','+b+',.38)';
    } else {
      target.style.background  = hex.replace('#','#')+'22';
      target.style.color       = hex;
      target.style.borderColor = hex.replace('#','#')+'55';
    }
  }
  // Exponer para uso en módulos
  window._ggiApplyBadgeColor = _applyBadgeColor;
  window._ggiHexToRgb        = _hexToRgb;
})();

/* ── ggiApplySelectColors ─ aplica colores a estado-sel (y pill-wrap legado) ── */
window.ggiApplySelectColors = function(root) {
  (root || document).querySelectorAll('.estado-sel[data-color]').forEach(function(el) {
    var lbl = el.querySelector('.estado-sel-label');
    if (lbl) window._ggiApplyBadgeColor(el.getAttribute('data-color'), lbl);
  });
  // Backward compat: .estado-pill-wrap todavía en uso
  (root || document).querySelectorAll('.estado-pill-wrap[data-color]').forEach(function(wrap) {
    window._ggiApplyBadgeColor(wrap.getAttribute('data-color'), wrap);
  });
};

/* ── initEstadoSel ─ inicializa los selectores de estado custom ──────────── */
window.initEstadoSel = function(root) {
  (root || document).querySelectorAll('.estado-sel:not([data-sel-init])').forEach(function(el) {
    el.setAttribute('data-sel-init','1');
    var menu = el.querySelector('.estado-sel-menu');
    if (!menu) return;

    /* Abrir / cerrar al hacer clic */
    el.addEventListener('click', function(e) {
      e.stopPropagation();
      var wasOpen = !menu.hidden;
      document.querySelectorAll('.estado-sel-menu:not([hidden])').forEach(function(m){ m.hidden=true; });
      if (!wasOpen) {
        menu.hidden = false;
        /* Flip hacia arriba si el menú se saldría de la ventana */
        var rect = el.getBoundingClientRect();
        if (rect.bottom + 190 > window.innerHeight) {
          menu.style.top='auto'; menu.style.bottom='calc(100% + 5px)';
        } else {
          menu.style.top=''; menu.style.bottom='';
        }
      }
    });

    /* Teclado: Enter/Espacio abren; Escape cierra; flechas navegan */
    el.addEventListener('keydown', function(e) {
      if (e.key==='Enter'||e.key===' ') { e.preventDefault(); el.click(); return; }
      if (e.key==='Escape') { menu.hidden=true; }
    });

    /* Clic en una opción → emitir evento para que el módulo lo maneje */
    menu.querySelectorAll('.estado-sel-option').forEach(function(opt) {
      opt.addEventListener('click', function(e) {
        e.stopPropagation();
        menu.hidden = true;
        el.dispatchEvent(new CustomEvent('estado-sel-change', {
          bubbles: true,
          detail: {
            el: el,
            id: el.getAttribute('data-id'),
            module: el.getAttribute('data-module'),
            value: opt.getAttribute('data-value'),
            color: opt.getAttribute('data-color'),
            label: opt.textContent.trim(),
            prevValue: el.getAttribute('data-value'),
            prevColor: el.getAttribute('data-color'),
            prevLabel: (el.querySelector('.estado-sel-label')||{}).textContent || ''
          }
        }));
      });
    });
  });
};

/* Cerrar cualquier menú abierto al hacer clic fuera */
document.addEventListener('click', function() {
  document.querySelectorAll('.estado-sel-menu:not([hidden])').forEach(function(m){ m.hidden=true; });
});

document.addEventListener('DOMContentLoaded', function() {
  window.ggiApplySelectColors();
  window.initEstadoSel();
});
</script>

<!-- Tom Select (local) -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/tom-select.min.css?v=2.3.1">
<script src="<?= APP_URL ?>/assets/js/tom-select.complete.min.js?v=2.3.1"></script>
<script>
(function() {
  function initTomSelects() {
    document.querySelectorAll('select').forEach(function(el) {
      if (el.tomselect) return;                        // ya inicializado
      if (el.dataset.noSearch !== undefined) return;   // excluido explícitamente
      if (el.closest('#mant-modal')) return;           // modal dinámico
      if (el.offsetWidth === 0) return;                // dentro de display:none, inicializar cuando sea visible
      if (el.options.length <= 8) return;              // pocos items, no necesita búsqueda

      var placeholder = (el.options[0] && el.options[0].value === '')
        ? el.options[0].text
        : '— Seleccionar —';

      new TomSelect(el, {
        maxOptions: null,
        create: false,
        allowEmptyOption: true,
        placeholder: placeholder,
        dropdownParent: 'body',
        onInitialize: function() {
          if (this.wrapper) this.wrapper.style.width = '100%';
          // Forzar apertura al hacer clic aunque ya haya un valor seleccionado
          var ts = this;
          ts.control.addEventListener('mousedown', function(e) {
            if (!ts.isOpen) {
              ts.open();
              e.preventDefault();
              e.stopPropagation();
            }
          });
        },
        onChange: function(value) {
          // Fire native change event so onchange attributes (e.g. autoEjecutivo, checkContacto) still work
          var nativeChange = new Event('change', { bubbles: true });
          el.dispatchEvent(nativeChange);
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTomSelects);
  } else {
    initTomSelects();
  }

  // Exponer globalmente por si algún módulo necesita reinicializar (p.ej. paneles que se muestran)
  window.initTomSelects = initTomSelects;
})();

/* ggiSubmitForm(form) — reemplaza form.submit() en onchange de selects.
   form.submit() nativo NO dispara el evento submit, así el SPA nunca lo intercepta
   y el navegador hace un reload completo. requestSubmit() sí dispara el evento. */
window.ggiSubmitForm = function(form) {
  if (!form) return;
  if (typeof form.requestSubmit === 'function') {
    form.requestSubmit();
  } else {
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }
};
</script>

<!-- ─────────────────────────────────────────────────────────────────────────
     ggiReady() — helper SPA-aware para inicialización de módulos.
     Problema: el SPA runner (navigate) ejecuta los <script> de cada página
     cuando document.readyState ya es 'complete', por lo que los callbacks
     registrados con addEventListener('DOMContentLoaded', fn) NUNCA disparan.
     Solución: ggiReady(fn) llama fn() de inmediato si el DOM ya está listo,
     o lo programa para cuando lo esté en carga directa (primera vez).
     USO: en cualquier módulo, sustituir
       document.addEventListener('DOMContentLoaded', fn)
     por
       ggiReady(fn)
     ───────────────────────────────────────────────────────────────────────── -->
<script>
window.ggiReady = function(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn);
  } else {
    fn();
  }
};

/* ── Debounce por clave: cada buscador tiene su propio timer, nunca se cancelan ──
   Uso: oninput="ggiDebounce('clientes-q', () => this.form.submit(), 600)"
   Si delay se omite, usa 600 ms (estándar del sistema).                        */
window.ggiDebounce = (function () {
  var _timers = Object.create(null);
  return function (key, fn, delay) {
    clearTimeout(_timers[key]);
    _timers[key] = setTimeout(fn, delay !== undefined ? delay : 600);
  };
})();
</script>

<!-- Navegación AJAX: swap de .main sin recargar la página completa.
     El sidebar y header permanecen intactos → cero flash blanco entre secciones. -->
<script src="<?= APP_URL ?>/assets/js/spa-nav.js?v=<?= @filemtime(__DIR__ . '/../assets/js/spa-nav.js') ?>"></script>

<script>
// ── Polling de badges en tiempo real ──────────────────────────────────────
// Consulta el servidor cada 20 s y actualiza los badges sin recargar la página.
// Se pausa automáticamente cuando la pestaña está en segundo plano.
(function () {
  var INTERVAL          = 30000;  // 30 s cuando el tab está visible
  var INTERVAL_HIDDEN   = 300000; // 5 min cuando el tab está oculto (heartbeat mínimo)
  var _timer            = null;
  var _sessionExpired   = false;

  function _setBadge(id, count, skip) {
    var el = document.getElementById(id);
    if (!el) return;
    if (count > 0 && !skip) {
      el.textContent = count > 9 ? '9+' : count;
      el.style.display = '';
    } else {
      el.style.display = 'none';
    }
  }

  // Redirige al login mostrando un aviso claro al usuario
  function _handleSessionExpired() {
    if (_sessionExpired) return; // evitar llamadas múltiples
    _sessionExpired = true;
    clearInterval(_timer);
    // Toast de aviso antes del redirect (2 s para que el usuario lo vea)
    var banner = document.createElement('div');
    banner.innerHTML = '<strong>Sesión expirada.</strong> Redirigiendo al inicio de sesión…';
    banner.style.cssText = [
      'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:99999',
      'background:#dc2626', 'color:#fff', 'text-align:center',
      'padding:calc(14px + env(safe-area-inset-top,0px)) 20px 14px', 'font-size:14px', 'font-family:Inter,sans-serif',
      'box-shadow:0 2px 12px rgba(0,0,0,.3)'
    ].join(';');
    document.body.appendChild(banner);
    setTimeout(function () {
      window.location.href = window.APP_URL + '/modules/auth/login.php';
    }, 2000);
  }

  var _lastPagosCount = -1; // Rastrea cambios para auto-reload en página de pagos
  var _badgesEtag     = '';  // ETag del último poll para evitar procesar respuestas sin cambios

  var _pollInFlight = false;
  function poll() {
    // Al abrir el PWA se disparan DOMContentLoaded Y visibilitychange casi a la
    // vez → dos polls solapados. Con esta guarda solo corre uno a la vez.
    if (_pollInFlight) return;
    _pollInFlight = true;

    var headers = { 'X-Requested-With': 'XMLHttpRequest' };
    if (_badgesEtag) headers['If-None-Match'] = _badgesEtag;

    fetch(window.APP_URL + '/modules/ajax_badges.php', {
      headers: headers,
      credentials: 'same-origin'
    })
    .then(function (r) {
      // HTTP 401 = sesión expirada → redirigir al login
      if (r.status === 401) { _handleSessionExpired(); return null; }
      // 304 Not Modified: badges sin cambios, no hay nada que actualizar
      if (r.status === 304) return null;
      var etag = r.headers.get('ETag');
      if (etag) _badgesEtag = etag;
      return r.ok ? r.json() : null;
    })
    .then(function (d) {
      if (!d || !d.ok) return;
      if (d.expired) { _handleSessionExpired(); return; }
      // Leer _active dinámicamente en cada poll para reflejar la página actual (SPA navigation)
      var _active = window.ACTIVE_MENU || '';
      // Solo actualizar badges de UI cuando el tab está visible (evitar parpadeo en segundo plano)
      if (!document.hidden) {
        _setBadge('nav-badge-cot',   d.cot,   _active === 'propuestas');
        _setBadge('nav-badge-pagos', d.pagos,  _active === 'pagos');
        _setBadge('nav-badge-notif', d.notif,  _active === 'notificaciones');
        // Notificar con toast cuando hay nuevos pagos pendientes (sin recargar)
        if (_active === 'pagos' && _lastPagosCount >= 0 && d.pagos > _lastPagosCount) {
          if (typeof showToast === 'function') showToast('Hay nuevos pagos pendientes de validación.', 'ok');
        }
        _lastPagosCount = d.pagos;
      }
    })
    .catch(function () {})
    .then(function () { _pollInFlight = false; });  // liberar la guarda pase lo que pase
  }

  // Ajustar el intervalo del timer según visibilidad del tab
  function _startTimer() {
    clearInterval(_timer);
    _timer = setInterval(poll, document.hidden ? INTERVAL_HIDDEN : INTERVAL);
  }

  // Exponer globalmente para invocar tras acciones AJAX (validar, rechazar, subir)
  window._pollBadges = poll;

  document.addEventListener('DOMContentLoaded', function () {
    poll(); // Actualizar badges inmediatamente (corrige datos cacheados por el navegador)
    _startTimer();
  });

  // Cambio de visibilidad del tab: ajustar frecuencia del heartbeat
  // • Visible  → poll inmediato + volver a 20 s (el usuario está mirando)
  // • Oculto   → bajar a 5 min (keepalive mínimo para que el GC no borre la sesión)
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      poll(); // ping inmediato al volver: detecta expiración en el acto
    }
    _startTimer(); // ajusta el intervalo
  });

  // Re-sincronizar al volver (bfcache o cache HTTP)
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      // Restauración desde bfcache: reiniciar timer que fue limpiado en pagehide
      _startTimer();
    }
    poll();
  });

  // Detener al salir de la página. Se usa 'pagehide' en vez de 'beforeunload'
  // porque beforeunload desactiva el bfcache del navegador → página en blanco al retroceder.
  window.addEventListener('pagehide', function () {
    clearInterval(_timer);
  });
})();
</script>

<script>
/* ── Bloquear scroll en inputs numéricos ── */
document.addEventListener('wheel', function(e) {
  if (document.activeElement && document.activeElement.type === 'number') {
    document.activeElement.blur();
  }
}, { passive: true });
</script>

<script>
/* ── Avatar chips con iniciales y color ──────────────────────────────
   Busca cualquier .av-chip[data-name] y lo convierte en un chip
   de color con las iniciales del nombre. El color es determinístico
   basado en un hash del nombre, así la misma empresa siempre
   tiene el mismo color.                                              */
(function () {
  var COLORS = [
    '#3B82F6','#8B5CF6','#10B981','#F97316',
    '#EC4899','#0EA5E9','#6366F1','#14B8A6',
    '#E8714A','#F59E0B','#EF4444','#6366F1',
    '#1D4ED8','#9333EA','#059669','#DC2626'
  ];
  function strHash(s) {
    var h = 5381;
    for (var i = 0; i < s.length; i++) { h = ((h << 5) + h) ^ s.charCodeAt(i); }
    return Math.abs(h);
  }
  function initAvatarChips(root) {
    (root || document).querySelectorAll('.av-chip[data-name]:not([data-av-init])').forEach(function(el) {
      var name = (el.dataset.name || '').trim();
      if (!name) { el.textContent = '?'; el.style.background = '#475569'; el.dataset.avInit = '1'; return; }
      var words = name.replace(/[^\w\sáéíóúüñÁÉÍÓÚÜÑ]/g,'').trim().split(/\s+/);
      var initials = words.length >= 2
        ? (words[0][0] + words[words.length - 1][0]).toUpperCase()
        : name.slice(0, 2).toUpperCase();
      el.textContent = initials;
      el.style.background = COLORS[strHash(name) % COLORS.length];
      el.dataset.avInit = '1';
    });
  }
  // Ejecutar de inmediato: el script está al final del body,
  // el DOM ya existe. El listener cubre el caso de navegación con caché.
  initAvatarChips();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { initAvatarChips(); });
  }
  window.initAvatarChips = initAvatarChips;
})();
</script>
<?php
// ── Flash message global (session-based, tras redirect con flash()) ──
$__flash = function_exists('flash_consume') ? flash_consume() : null;
if ($__flash): ?>
<script>
(function() {
  var key = <?= json_encode('_flash_' . $__flash['id']) ?>;
  // localStorage (no sessionStorage): el SW cachea el HTML de la página CON este
  // <script> incrustado; al reabrir la PWA (sesión nueva) sessionStorage está vacío
  // y el toast se re-disparaba en cada apertura. localStorage recuerda el id del
  // flash para siempre → cada mensaje se muestra una sola vez aunque la página esté
  // cacheada por el Service Worker.
  try {
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, '1');
    // Poda simple: evitar crecimiento ilimitado de claves _flash_/_urlmsg_
    var _fk = Object.keys(localStorage).filter(function(k){ return k.indexOf('_flash_')===0 || k.indexOf('_urlmsg_')===0; });
    if (_fk.length > 40) _fk.slice(0, _fk.length - 40).forEach(function(k){ localStorage.removeItem(k); });
  } catch(_) {}
  if (typeof showToast === 'function') {
    showToast(<?= json_encode($__flash['msg']) ?>, <?= json_encode($__flash['type']) ?>);
  } else {
    document.addEventListener('DOMContentLoaded', function() {
      if (typeof showToast === 'function') showToast(<?= json_encode($__flash['msg']) ?>, <?= json_encode($__flash['type']) ?>);
    });
  }
})();
</script>
<?php endif; ?>
<?php
// ── Error de validación de formulario (toast cuando $errors no está vacío) ──
if (!empty($errors)): ?>
<script>
if (typeof showToast === 'function') showToast('Revisa los campos marcados en rojo.', 'error');
</script>
<?php endif; ?>
<script>
// ── Lector global de ?msg= y ?msg_type= en URL (backward-compat, una sola vez) ──
(function() {
  var p = new URLSearchParams(window.location.search);
  var msg = p.get('msg');
  if (!msg) return;
  var key = '_urlmsg_' + btoa(msg).slice(0, 16);
  // localStorage: mismo motivo que el flash — el SW cachea la URL con ?msg= y se
  // re-disparaba en cada apertura. Recordar el mensaje evita el replay.
  try {
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, '1');
  } catch(_) {}
  var type = p.get('msg_type') || 'ok';
  p.delete('msg'); p.delete('msg_type');
  history.replaceState({}, '', window.location.pathname + (p.toString() ? '?' + p.toString() : ''));
  if (typeof showToast === 'function') showToast(decodeURIComponent(msg), type);
})();
</script>

<script>
// ── Loading states globales ───────────────────────────────────────────────────
// Proporciona feedback visual inmediato al usuario cuando:
//   1. Se envía cualquier formulario (botón submit se desactiva + muestra spinner)
//   2. Se hace clic en un botón de acción que no es submit pero podría tardar
//
// No afecta a botones que ya manejan su propio estado (ej: modales, AJAX inline).
// El estado se revierte automáticamente si el navegador vuelve a la página
// (bfcache/popstate) para evitar botones que quedan "atascados".
(function () {
  'use strict';

  var SPINNER_SVG = '<svg style="animation:spin .7s linear infinite;display:inline-block;vertical-align:middle;margin-right:5px;" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';

  // Inyectar keyframe solo una vez
  if (!document.getElementById('_spin_style')) {
    var st = document.createElement('style');
    st.id = '_spin_style';
    st.textContent = '@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }

  function freezeBtn(btn) {
    if (btn.dataset.loading === '1') return;
    btn.dataset.loading   = '1';
    btn.dataset.origHtml  = btn.innerHTML;
    btn.dataset.origWidth = btn.offsetWidth + 'px';
    btn.disabled          = true;
    btn.style.minWidth    = btn.dataset.origWidth;
    btn.style.opacity     = '0.8';
    btn.innerHTML         = SPINNER_SVG + (btn.dataset.loadingText || 'Guardando…');
  }

  function thawBtn(btn) {
    if (btn.dataset.loading !== '1') return;
    btn.dataset.loading  = '0';
    btn.disabled         = false;
    btn.style.opacity    = '';
    btn.style.minWidth   = '';
    if (btn.dataset.origHtml) btn.innerHTML = btn.dataset.origHtml;
  }

  // Liberar todos los botones congelados (por si el usuario vuelve con bfcache)
  function thawAll() {
    document.querySelectorAll('[data-loading="1"]').forEach(thawBtn);
  }

  // Delegar en document para capturar dinámicamente formularios en SPA
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    // No interferir con formularios que ya tienen su propio manejo de estado
    if (form.dataset.noAutoLoading === '1') return;

    // Encontrar el botón que disparó el submit
    var submitter = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    if (!submitter || submitter.dataset.noAutoLoading === '1') return;

    // Si el formulario tiene action AJAX (data-ajax) o se procesa con JS, no congelar
    if (form.dataset.ajax) return;

    freezeBtn(submitter);

    // Timeout de seguridad: si la página no navega en 12 s, liberar el botón
    // (p.ej. validación HTML5 bloqueó el submit, o el servidor respondió con error)
    setTimeout(function () { thawBtn(submitter); }, 12000);
  }, true);

  // Restaurar botones al volver con bfcache
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) thawAll();
  });

  // Restaurar botones en navegación SPA (el main se reemplaza, los botones desaparecen,
  // pero por si acaso queda alguno fuera del main)
  window.addEventListener('spa:before', function () { thawAll(); });

  // Exponer para que código externo pueda congelar/liberar manualmente
  window.freezeBtn = freezeBtn;
  window.thawBtn   = thawBtn;
  window.thawAllBtns = thawAll;
})();
</script>

<script src="<?= APP_URL ?>/assets/js/txt-ellipsis.js?v=<?= @filemtime(__DIR__ . '/../assets/js/txt-ellipsis.js') ?>"></script>

<!-- ── PWA: Service Worker + Web Push ────────────────────────────────────── -->
<script src="<?= APP_URL ?>/assets/js/push-notif.js?v=<?= @filemtime(__DIR__ . '/../assets/js/push-notif.js') ?>"></script>

<!-- ═══════════════════════════════════════════════════════════════════════
     COMPONENTE GLOBAL: ms-drop — Filtro multiselección de estados
     Uso: <div class="ms-drop" id="ms-XXX"> ... </div>
     JS:  msDrop.toggle(id)   — abrir/cerrar
          msDrop.change(id)   — registrar cambio + debounce submit
     ═══════════════════════════════════════════════════════════════════════ -->


<script>
/* ── msDrop: controlador global del componente ms-drop ── */
(function() {
  var _timers = {};  // debounce timers por instancia
  var _open = null;  // id del dropdown actualmente abierto

  window.msDrop = {

    // Abrir / cerrar el dropdown
    toggle: function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      var menu = el.querySelector('.ms-drop-menu');
      if (!menu) return;

      if (_open && _open !== id) {
        msDrop.close(_open);
      }
      var isOpen = el.classList.contains('is-open');
      if (isOpen) {
        msDrop.close(id);
      } else {
        menu.hidden = false;
        el.classList.add('is-open');
        var btn = el.querySelector('.ms-drop-btn');
        if (btn) btn.setAttribute('aria-expanded', 'true');
        _open = id;
      }
    },

    // Cerrar sin submit
    close: function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      var menu = el.querySelector('.ms-drop-menu');
      if (menu) menu.hidden = true;
      el.classList.remove('is-open');
      var btn = el.querySelector('.ms-drop-btn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
      if (_open === id) _open = null;
    },

    // Registrar cambio de checkbox → debounce 420ms → submit
    change: function(id, cb) {
      msDrop._updateLabel(id);
      clearTimeout(_timers[id]);
      _timers[id] = setTimeout(function() {
        msDrop.close(id);
        var form = cb.closest('form');
        if (form) form.submit();
      }, 420);
    },

    // Actualizar el texto del botón según checkboxes marcados
    _updateLabel: function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      var checked = Array.from(el.querySelectorAll('input[type="checkbox"]:checked'));
      var lbl = el.querySelector('.ms-drop-lbl');
      var cnt = el.querySelector('.ms-drop-count');
      if (!lbl) return;

      if (checked.length === 0) {
        lbl.textContent = lbl.dataset.placeholder || 'Todos';
        lbl.classList.add('is-placeholder');
        if (cnt) cnt.hidden = true;
      } else {
        var names = checked.map(function(c) {
          var item = c.closest('.ms-drop-item');
          var span = item ? item.querySelector('span') : null;
          return span ? span.textContent.trim() : c.value;
        });
        // Mostrar hasta 2 nombres, luego "+N más"
        if (names.length <= 2) {
          lbl.textContent = names.join(', ');
        } else {
          lbl.textContent = names.slice(0, 2).join(', ') + ' +' + (names.length - 2) + ' más';
        }
        lbl.classList.remove('is-placeholder');
        if (cnt) { cnt.hidden = false; cnt.textContent = checked.length; }
      }
    }
  };

  // Cerrar al hacer clic fuera de cualquier ms-drop abierto
  document.addEventListener('click', function(e) {
    if (!_open) return;
    var el = document.getElementById(_open);
    if (el && !el.contains(e.target)) {
      // Si hay un timer pendiente, cancelar y hacer submit inmediato
      if (_timers[_open]) {
        clearTimeout(_timers[_open]);
        var form = el.querySelector('input[type="checkbox"]');
        form = form ? form.closest('form') : null;
        msDrop.close(_open);
        if (form) form.submit();
      } else {
        msDrop.close(_open);
      }
    }
  });

  // Cerrar con Escape y devolver el foco al botón activador (a11y)
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && _open) {
      var el = document.getElementById(_open);
      msDrop.close(_open);
      var btn = el && el.querySelector('.ms-drop-btn');
      if (btn) btn.focus();
    }
  });

  // Estado inicial aria-expanded en TODOS los ms-drop (helper o markup inline)
  document.querySelectorAll('.ms-drop-btn').forEach(function(b) {
    if (!b.hasAttribute('aria-expanded')) b.setAttribute('aria-expanded', 'false');
  });
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     GGI Lightbox — visor global de imágenes/PDF en modal (sin salir de la página)
     Uso: <a href="archivo.jpg" data-ggi-preview data-title="Voucher">Ver</a>
          o JS: ggiLightbox.open(url, {title, download, filename})
     Detecta imagen vs PDF automáticamente (img → fallback iframe).
     ═══════════════════════════════════════════════════════════════════════ -->


<div id="ggi-lightbox" class="ggi-lightbox" role="dialog" aria-modal="true" aria-label="Vista previa de archivo" hidden>
  <div class="ggi-lightbox-backdrop" data-close></div>
  <div class="ggi-lightbox-stage">
    <div class="ggi-lightbox-toolbar">
      <span class="ggi-lightbox-title"></span>
      <div class="ggi-lightbox-actions">
        <button type="button" class="ggi-lightbox-btn" data-print aria-label="Imprimir" title="Imprimir">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        </button>
        <a class="ggi-lightbox-btn" data-download href="#" download aria-label="Descargar archivo" title="Descargar">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </a>
        <button type="button" class="ggi-lightbox-btn ggi-lightbox-close" data-close aria-label="Cerrar vista previa" title="Cerrar (Esc)">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
    </div>
    <div class="ggi-lightbox-body">
      <img class="ggi-lightbox-img" alt="" hidden>
      <div class="ggi-lightbox-pdf" hidden></div>
      <iframe class="ggi-lightbox-frame" title="Vista previa de documento" hidden></iframe>
      <div class="ggi-lightbox-loading">Cargando…</div>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/pdf-viewer.js?v=<?= @filemtime(__DIR__ . '/../assets/js/pdf-viewer.js') ?>"></script>

<?php include __DIR__ . '/pwa-install.php'; ?>

<script src="<?= APP_URL ?>/assets/js/pwa-downloads.js?v=<?= @filemtime(__DIR__ . '/../assets/js/pwa-downloads.js') ?>"></script>

<!-- Design System JS — Componentes core reutilizables -->
<script src="<?= APP_URL ?>/assets/js/components.js?v=<?= @filemtime(__DIR__ . '/../assets/js/components.js') ?>"></script>
<!-- Datepicker propio (flatpickr) — calendario moderno alineado al design system -->
<script src="<?= APP_URL ?>/assets/vendor/flatpickr/flatpickr.min.js"></script>
<script src="<?= APP_URL ?>/assets/vendor/flatpickr/es.js"></script>
<script>
(function () {
  // Inicializa flatpickr en los <input type="date"> aún sin inicializar (idempotente).
  // Mantiene el VALOR en formato Y-m-d (lo que espera el backend) y MUESTRA d/m/Y.
  window.ggiInitDatepickers = function (root) {
    if (!window.flatpickr) return;
    if (window.flatpickr.localize && window.flatpickr.l10ns && window.flatpickr.l10ns.es) {
      window.flatpickr.localize(window.flatpickr.l10ns.es);
    }
    var scope = root || document;
    scope.querySelectorAll('input[type="date"]:not([data-fp])').forEach(function (inp) {
      inp.setAttribute('data-fp', '1');
      try {
        flatpickr(inp, {
          dateFormat: 'Y-m-d',   // valor enviado (no cambia el backend)
          altInput:   true,
          altFormat:  'd/m/Y',   // lo que ve el usuario
          allowInput: true,
          disableMobile: false   // en móvil/PWA usa el picker nativo (mejor en táctil)
        });
      } catch (e) { inp.removeAttribute('data-fp'); }
    });
  };
  if (document.readyState !== 'loading') window.ggiInitDatepickers();
  else document.addEventListener('DOMContentLoaded', function () { window.ggiInitDatepickers(); });
})();
</script>
</body>
</html>
