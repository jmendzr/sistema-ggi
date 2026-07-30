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
<script>
(function () {
  'use strict';

  var mainEl        = document.querySelector('.main');
  var bar           = document.getElementById('nav-progress-bar');
  var barWrap       = document.getElementById('nav-progress');
  var barTimer      = null;
  var pending         = null;   // AbortController del fetch en curso
  var cache           = {};     // href → html(string) | Promise | null(error)
  var __ggiNavDepth   = 0;      // cuántos navigate() hacia adelante se hicieron
  var _focusRestore   = null;   // {id, name} del input a re-enfocar tras swap de búsqueda

  /* ── Barra de progreso ── */
  function startBar() {
    if (!bar || !barWrap) return;
    clearTimeout(barTimer);
    barWrap.style.opacity = '1';
    bar.style.transition = 'none'; bar.style.width = '0%';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        bar.style.transition = 'width 0.4s ease'; bar.style.width = '70%';
      });
    });
  }
  function doneBar() {
    if (!bar || !barWrap) return;
    bar.style.transition = 'width 0.15s ease'; bar.style.width = '100%';
    barTimer = setTimeout(function () {
      barWrap.style.opacity = '0';
      setTimeout(function () { bar.style.width = '0%'; }, 200);
    }, 200);
  }

  /* ── ¿Aplica navegación AJAX? ── */
  function canAjax(href) {
    if (!href || /^(#|javascript:|mailto:|tel:)/i.test(href)) return false;
    try {
      var u = new URL(href, location.href);
      if (u.origin !== location.origin) return false;
      if (u.searchParams.has('dl') || u.searchParams.has('export')) return false;
      if (/\/(logout|login)/i.test(u.pathname)) return false;
      return true;
    } catch (e) { return false; }
  }

  /* ── Obtener HTML (reutiliza prefetch si existe; nunca cachea al ir atrás) ── */
  function getPage(href, signal, forceRefresh) {
    if (!forceRefresh && typeof cache[href] === 'string') return Promise.resolve(cache[href]);
    if (!forceRefresh && cache[href] && typeof cache[href].then === 'function') return cache[href];
    var p = fetch(href, { signal: signal, credentials: 'same-origin', cache: 'no-cache' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) { cache[href] = html; return html; })
      .catch(function (err) { cache[href] = null; throw err; });
    if (!forceRefresh) cache[href] = p;
    return p;
  }

  /* ── Exponer invalidación de caché (llamar tras mutaciones AJAX) ── */
  window.invalidatePageCache = function(href) {
    delete cache[href || location.href];
  };

  /* ── Navegar atrás respetando el historial AJAX ── */
  window.ggiNavBack = function(fallbackHref) {
    if (__ggiNavDepth > 0) {
      history.back();
    } else {
      location.href = fallbackHref || location.href;
    }
  };

  /* ── Navegación contextual: guardar URL de listas y restaurar en breadcrumbs ── */
  (function () {
    var _map = {
      '/modules/clientes/index.php':     'ggi_ss_clientes',
      '/modules/cotizaciones/index.php': 'ggi_ss_cotizaciones',
      '/modules/certificados/index.php': 'ggi_ss_certificados',
      '/modules/envios/index.php':       'ggi_ss_envios',
      '/modules/auditorias/index.php':   'ggi_ss_auditorias',
      '/modules/pagos/index.php':        'ggi_ss_pagos',
    };
    var _path = window.location.pathname;
    if (_map[_path]) {
      try { sessionStorage.setItem(_map[_path], location.href); } catch (e) {}
    }
    // Actualizar hrefs de breadcrumb con el URL guardado (preserva filtros/paginación)
    document.querySelectorAll('[data-bc-ss]').forEach(function (el) {
      try {
        var saved = sessionStorage.getItem(el.getAttribute('data-bc-ss'));
        if (saved) el.href = saved;
      } catch (e) {}
    });
  })();

  /* ── Alias retrocompatible: ggiInitBackLink ya no hace nada (reemplazado por ggi-bc) ── */
  window.ggiInitBackLink = function() {};

  /* ── Navegar programáticamente desde cualquier módulo ── */
  window.ggiNavigate = function(href) { navigate(href); };

  /* ── Construir URL desde un formulario GET ──────────────────────────────
     Regla: los <select> siempre se incluyen aunque su valor sea "" (vacío),
     porque un select vacío es una elección explícita del usuario ("Todos").
     Los inputs de texto vacíos se omiten para mantener URLs limpias.        */
  function buildFormUrl(form) {
    var action = form.getAttribute('action') || location.pathname;
    var url = new URL(action, location.href);
    url.search = '';
    Array.from(form.elements).forEach(function(el) {
      if (!el.name || el.disabled) return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) url.searchParams.set(el.name, el.value);
        return;
      }
      if (el.tagName === 'SELECT') {
        // Siempre incluir selects (valor vacío = "Todos" explícito)
        url.searchParams.set(el.name, el.value);
        return;
      }
      // Inputs de texto: solo incluir si tienen valor
      if (el.value !== '') url.searchParams.set(el.name, el.value);
    });
    return url.pathname + (url.search || '');
  }

  /* ── Buscador con debounce + SPA + preservación de foco ──────────────────
     PROBLEMA RAÍZ: form.submit() (nativo) NO dispara el evento "submit",
     por lo que el interceptor SPA nunca lo ve y el browser hace reload completo.
     SOLUCIÓN: construir la URL aquí mismo y llamar navigate() directamente,
     guardando _focusRestore antes para que el swap restaure el cursor.

     Uso: oninput="ggiSearch('modulo-q', this, 600)"                         */
  window.ggiSearch = function(key, inputEl, delay) {
    window.ggiDebounce(key, function() {
      var form = inputEl ? inputEl.form : null;
      if (!form) return;
      _focusRestore = { id: inputEl.id || '', name: inputEl.name || '' };
      try {
        navigate(buildFormUrl(form));
      } catch(x) {
        _focusRestore = null;
        form.submit();
      }
    }, delay !== undefined ? delay : 600);
  };

  /* ── Navegar ── */
  function navigate(href, isPop) {
    if (!mainEl) { location.href = href; return; }
    if (pending) { pending.abort(); }
    // Liberar botones congelados antes de cada navegación SPA
    window.dispatchEvent(new Event('spa:before'));
    var ac = new AbortController();
    pending = ac;
    startBar();

    getPage(href, ac.signal, isPop)   // popstate siempre refresca (sin caché)
      .then(function (html) {
        if (ac !== pending && !isPop) return; // superado por navegación posterior
        pending = null;

        /* Ajustar profundidad de historial */
        if (isPop) {
          __ggiNavDepth = Math.max(0, __ggiNavDepth - 1);
        } else {
          __ggiNavDepth++;
        }

        var doc = (new DOMParser()).parseFromString(html, 'text/html');
        var newMain = doc.querySelector('.main');
        if (!newMain) { location.href = href; return; } // error/login → normal

        /* URL y título */
        if (!isPop) history.pushState({ ggi: 1 }, doc.title || '', href);
        document.title = doc.title || '';

        /* Actualizar ítem activo del sidebar leyendo el de la respuesta */
        var srcActive = doc.querySelector('.nav-item.active');
        document.querySelectorAll('.nav-item').forEach(function (el) {
          el.classList.remove('active');
        });
        if (srcActive) {
          var srcHref = srcActive.getAttribute('href') || '';
          var match = document.querySelector('.nav-item[href="' + srcHref.replace(/"/g, '\\"') + '"]');
          if (match) match.classList.add('active');
        }
        if (typeof _ggiSyncAriaCurrent === 'function') _ggiSyncAriaCurrent();

        /* Actualizar window.ACTIVE_MENU para que el polling de badges refleje la página actual */
        var _amScript = Array.from(doc.body.querySelectorAll('script:not([src])')).find(function(s) {
          return s.textContent.indexOf('window.ACTIVE_MENU') !== -1;
        });
        if (_amScript) {
          var _amMatch = _amScript.textContent.match(/window\.ACTIVE_MENU\s*=\s*'([^']*)'/);
          if (_amMatch) window.ACTIVE_MENU = _amMatch[1];
        }

        /* Extraer <script> antes del swap */
        var scripts = Array.from(newMain.querySelectorAll('script'));
        scripts.forEach(function (s) { s.remove(); });

        /* Limpiar datos de módulos JS anteriores antes de cargar los nuevos */
        window.__mantData        = null;
        window.__mantBuildForm   = null;
        window.__mantPostRender  = null;
        window.__mant            = null;

        /* Cerrar sidebar móvil si estaba abierto */
        if (typeof closeSidebar === 'function') closeSidebar();

        /* Cerrar modal si estaba abierto (usar mantClose para restaurar overflow) */
        if (typeof mantClose === 'function') {
          try { mantClose(); } catch(e) {}
        } else {
          var _om = document.getElementById('mant-modal');
          if (_om) _om.classList.remove('open');
          document.body.style.overflow = '';
        }

        /* Swap de contenido */
        mainEl.innerHTML = newMain.innerHTML;

        /* Re-inicializar datepickers en el contenido recién insertado (SPA) */
        if (typeof window.ggiInitDatepickers === 'function') window.ggiInitDatepickers(mainEl);

        /* Restaurar foco si la navegación fue originada por un form de búsqueda.
           requestAnimationFrame garantiza que el nuevo DOM ya pintó antes de enfocar. */
        if (_focusRestore) {
          var _fr = _focusRestore;
          _focusRestore = null;
          requestAnimationFrame(function () {
            var el = (_fr.id && document.getElementById(_fr.id))
                  || (_fr.name && document.querySelector('[name="' + _fr.name + '"]'));
            if (el && !el.disabled && !el.readOnly) {
              el.focus({ preventScroll: true });
              try { el.setSelectionRange(el.value.length, el.value.length); } catch (_) {}
            }
          });
        } else {
          /* Scroll al tope solo cuando NO es una búsqueda (evita salto incómodo) */
          window.scrollTo(0, 0);
          document.documentElement.scrollTop = 0;
          document.body.scrollTop = 0;
        }

        /* Ejecutar scripts de la nueva página */
        scripts.forEach(function (old) {
          var s = document.createElement('script');
          Array.from(old.attributes).forEach(function (a) { s.setAttribute(a.name, a.value); });
          s.textContent = old.textContent;
          document.body.appendChild(s);
          if (!old.src) document.body.removeChild(s);
        });

        /* Re-renderizar módulo de mantenimiento si la nueva página lo necesita */
        if (typeof _initMantUI === 'function') _initMantUI();

        /* Re-inicializar TomSelect en los nuevos <select> */
        if (typeof window.initTomSelects === 'function') {
          try { window.initTomSelects(); } catch (e) {}
        }

        /* Re-inicializar avatar chips en el nuevo contenido */
        if (typeof window.initAvatarChips === 'function') {
          try { window.initAvatarChips(mainEl); } catch (e) {}
        }

        /* Re-crear botones × en los buscadores del nuevo contenido */
        if (typeof window.initSearchClearBtns === 'function') {
          try { window.initSearchClearBtns(mainEl); } catch (e) {}
        }

        /* Re-aplicar colores de estado (tema actual) y re-inicializar estado-sel */
        if (typeof window.ggiApplySelectColors === 'function') {
          try { window.ggiApplySelectColors(mainEl); } catch (e) {}
        }
        if (typeof window.initEstadoSel === 'function') {
          try { window.initEstadoSel(mainEl); } catch (e) {}
        }

        doneBar();
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        pending = null;
        doneBar();
        location.href = href; // fallback a navegación normal
      });
  }

  /* ── Interceptar clics (capture para tener prioridad) ── */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || a.hasAttribute('download') || a.target === '_blank' || a.hasAttribute('data-ggi-preview')) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    var href = a.getAttribute('href');
    if (!canAjax(href)) return;

    /* Si es un botón "← Volver" y hay historial AJAX, usar history.back()
       para que el navegador restaure la página/paginación anterior. */
    var txt = (a.textContent || a.innerText || '').trim();
    if (__ggiNavDepth > 0 && (txt.indexOf('Volver') !== -1 || txt.indexOf('←') !== -1 || a.classList.contains('btn-back'))) {
      e.preventDefault();
      history.back();
      return;
    }

    try {
      var url = new URL(href, location.href);
      e.preventDefault();
      navigate(url.href);
    } catch (x) {}
  }, true);

  /* ── Botones atrás / adelante ── */
  window.addEventListener('popstate', function () { navigate(location.href, true); });

  /* ── Prefetch al pasar el cursor ── */
  document.addEventListener('mouseover', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || a.hasAttribute('download') || a.target === '_blank' || a.hasAttribute('data-ggi-preview')) return;
    var href = a.getAttribute('href');
    if (!canAjax(href) || cache[href] != null) return;
    try {
      var url = new URL(href, location.href);
      if (cache[url.href] != null) return;
      cache[url.href] = fetch(url.href, { credentials: 'same-origin', cache: 'no-cache' })
        .then(function (r) { return r.text(); })
        .then(function (html) { cache[url.href] = html; return html; })
        .catch(function () { cache[url.href] = null; });
    } catch (x) {}
  }, { passive: true });

  /* ── Forms GET: interceptar para enrutar por SPA y preservar foco ── */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form) return;

    // POST y formularios especiales: dejar al browser, solo animar barra
    if ((form.method || 'get').toUpperCase() !== 'GET') { startBar(); return; }

    var action = form.getAttribute('action') || location.pathname;
    if (!canAjax(action)) { startBar(); return; }

    e.preventDefault();

    // Guardar elemento con foco para restaurarlo tras el swap
    var active = document.activeElement;
    if (active && active.tagName && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) {
      _focusRestore = { id: active.id || '', name: active.name || '' };
    }

    // Construir URL con datos del formulario
    try {
      navigate(buildFormUrl(form));
    } catch (x) {
      _focusRestore = null;
      form.submit(); // fallback a navegación normal
    }
  }, true); // capture=true → prioridad sobre los submit nativos

  window.addEventListener('pageshow', function () { doneBar(); });

  /* ── Descarga de archivos sin salir de la SPA ── */
  window.ggiDownloadBlob = function(url) {
    // Sincrónico: el clic debe ocurrir dentro del mismo frame de usuario
    // para que Android/Chrome lo acepte como descarga (user-activation).
    var a = document.createElement('a');
    a.href        = url;
    a.download    = ''; // el servidor envía el nombre vía Content-Disposition
    a.rel         = 'noopener';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  };

})();
</script>

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

  function poll() {
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
    .catch(function () {});
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

<script>
/* ══════════════════════════════════════════════════════════════════
   txt-ellipsis interactivo v2 — expandir / contraer + copiar
   Sin necesidad de refrescar página. Funciona en carga inicial,
   SPA navigation, tablas AJAX y contenido dinámico.
   ══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Constantes SVG ── */
  var SVG_COPY  = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="flex-shrink:0"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
  var SVG_CHECK = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>';

  var copyBtn   = null;
  var hoverEl   = null;
  var hideTimer = null;
  var moTimer   = null;   // MutationObserver debounce
  var _ready    = false;  // init called flag

  /* ════════════════════════════════════════
     BOTÓN FLOTANTE COPIAR
     ════════════════════════════════════════ */
  function initCopyBtn() {
    if (document.getElementById('ggi-copy-btn')) return; // ya existe (bfcache)
    copyBtn = document.createElement('button');
    copyBtn.id   = 'ggi-copy-btn';
    copyBtn.type = 'button';
    copyBtn.setAttribute('aria-label', 'Copiar nombre completo');
    copyBtn.title = 'Copiar nombre';
    copyBtn.innerHTML = SVG_COPY;   // ícono discreto (sin texto), pegado al nombre
    document.body.appendChild(copyBtn);
    copyBtn.addEventListener('click',      doCopy);
    copyBtn.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
    copyBtn.addEventListener('mouseleave', scheduleHide);
  }

  function doCopy(e) {
    e.stopPropagation();
    e.preventDefault();
    if (!hoverEl) return;
    /* Prioridad: data-fulltext > data-tip > title > textContent completo */
    var text = (hoverEl.dataset.fulltext || hoverEl.dataset.tip || hoverEl.title || hoverEl.textContent || '').trim();
    if (!text) return;
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(text).then(showCopied, function () { fallbackCopy(text); });
    } else {
      fallbackCopy(text);
    }
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;pointer-events:none';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { if (document.execCommand('copy')) showCopied(); } catch (_) { /* silencioso */ }
    document.body.removeChild(ta);
  }

  function showCopied() {
    if (!copyBtn) return;
    copyBtn.innerHTML = SVG_CHECK;
    copyBtn.classList.add('ggi-copied');
    if (typeof window.showToast === 'function') window.showToast('Nombre copiado', 'ok');
    setTimeout(function () {
      if (copyBtn) {
        copyBtn.innerHTML = SVG_COPY;
        copyBtn.classList.remove('ggi-copied');
      }
    }, 1600);
  }

  /* ════════════════════════════════════════
     POSICIONAMIENTO DEL BOTÓN
     ════════════════════════════════════════ */
  function positionBtn(el) {
    if (!copyBtn) return;
    var rect = el.getBoundingClientRect();
    var bh = 26, bw = 28;   // ícono compacto
    var vw = window.innerWidth  || document.documentElement.clientWidth;
    var vh = window.innerHeight || document.documentElement.clientHeight;
    /* Pegado al final del nombre, en su MISMA línea (centrado vertical).
       No flota sobre otras filas como el chip anterior. */
    var top  = rect.top + (rect.height - bh) / 2;
    var left = rect.right + 6;
    /* Si no cabe a la derecha (nombre llega al borde), ubicarlo a la izquierda */
    if (left + bw > vw - 6) left = rect.left - bw - 6;
    if (left < 4)           left = 4;
    if (top < 4)            top  = 4;
    if (top + bh > vh - 4)  top  = vh - bh - 4;
    copyBtn.style.top  = top  + 'px';
    copyBtn.style.left = left + 'px';
  }

  function scheduleHide() {
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      if (copyBtn) copyBtn.classList.remove('visible');
      hoverEl = null;
    }, 200);
  }

  /* ════════════════════════════════════════
     DETECCIÓN DE OVERFLOW — núcleo
     Mide el overflow REAL comparando scrollWidth vs clientWidth.
     Se invoca en múltiples puntos para garantizar inmediatez.
     ════════════════════════════════════════ */
  function markOverflowing() {
    document.querySelectorAll('.txt-ellipsis').forEach(function (el) {
      var isOverflowing = el.scrollWidth > el.clientWidth + 2;
      if (isOverflowing) {
        el.setAttribute('data-overflows', '1');
        /* Mover el title nativo → data-tip (tooltip estilizado) y QUITAR el title
           para que no aparezca el tooltip nativo del navegador encima (doble sombra).
           Guardamos el texto en data-fulltext para el botón Copiar. */
        var full = el.getAttribute('title') || el.dataset.tip || el.textContent || '';
        if (full) {
          el.setAttribute('data-tip', full.trim());
          el.setAttribute('data-fulltext', full.trim());
          if (el.hasAttribute('title')) el.removeAttribute('title');
        }
      } else {
        el.removeAttribute('data-overflows');
        el.removeAttribute('data-tip');
        /* texto completo visible: el title nativo tampoco aporta → quitarlo */
        if (el.hasAttribute('title')) {
          el.setAttribute('data-fulltext', (el.getAttribute('title') || '').trim());
          el.removeAttribute('title');
        }
      }
    });
  }

  /* ════════════════════════════════════════
     MUTATIONOBSERVER — detecta contenido dinámico
     (tablas recargadas por AJAX, SPA swaps, etc.)
     ════════════════════════════════════════ */
  function initObserver() {
    if (!window.MutationObserver) return;
    var root = document.querySelector('main, #main-content, .main') || document.body;
    var obs  = new MutationObserver(function (mutations) {
      var hasNewNodes = mutations.some(function (m) {
        return m.type === 'childList' && m.addedNodes.length > 0;
      });
      if (!hasNewNodes) return;
      clearTimeout(moTimer);
      moTimer = setTimeout(markOverflowing, 60); /* pequeño delay para que el layout se calcule */
    });
    obs.observe(root, { childList: true, subtree: true });
  }

  /* ════════════════════════════════════════
     EVENTOS DE INTERACCIÓN
     ════════════════════════════════════════ */
  function bindEvents() {
    /* Clic en cualquier parte: ocultar el botón Copiar. NO se expande el nombre
       (eliminado jun 2026): el clic sobre el nombre debe navegar (es un link), y el
       nombre completo ya se ve en el tooltip al hacer hover. */
    document.addEventListener('click', function (e) {
      if (copyBtn && !copyBtn.contains(e.target)) {
        clearTimeout(hideTimer);
        copyBtn.classList.remove('visible');
        hoverEl = null;
      }
    });

    /* Hover: mostrar botón copiar en TODOS los .txt-ellipsis
       (sin importar si está truncado o no — se aplica a toda razón social) */
    document.addEventListener('mouseover', function (e) {
      var el = e.target.closest('.txt-ellipsis');
      if (!el || !copyBtn) return;
      /* Solo mostrar si hay algo que copiar */
      var text = (el.dataset.fulltext || el.dataset.tip || el.title || el.textContent || '').trim();
      if (!text) return;
      clearTimeout(hideTimer);
      hoverEl = el;
      positionBtn(el);
      copyBtn.classList.add('visible');
    });

    document.addEventListener('mouseout', function (e) {
      var el = e.target.closest('.txt-ellipsis');
      if (!el) return;
      var to = e.relatedTarget;
      if (to && copyBtn && (to === copyBtn || copyBtn.contains(to))) return;
      scheduleHide();
    });
  }

  /* ════════════════════════════════════════
     INICIALIZACIÓN ROBUSTA
     Funciona tanto si DOMContentLoaded ya disparó (bfcache, SPA)
     como si todavía no (primera carga normal).
     ════════════════════════════════════════ */
  function init() {
    if (_ready) return;
    _ready = true;

    /* Copiar nombre completo: botón flotante "Copiar" en hover sobre .txt-ellipsis.
       (Reactivado jun 2026 — el usuario quiere copiar nombres.) El click-expandir
       se eliminó para que el clic del nombre navegue sin ambigüedad. */
    initCopyBtn();
    bindEvents();

    /* Escaneo inmediato */
    markOverflowing();
    /* Safety net: segundo escaneo a 80 ms para cuando el navegador
       aún no ha calculado el layout en el primer tick */
    setTimeout(markOverflowing, 80);

    /* Re-escaneo en resize */
    window.addEventListener('resize', debounce(markOverflowing, 220));

    /* Re-escaneo tras navegación SPA (varios eventos posibles) */
    window.addEventListener('spa:loaded',      function () { setTimeout(markOverflowing, 50); });
    window.addEventListener('ggi:pagechanged', function () { setTimeout(markOverflowing, 50); });

    /* Re-escaneo tras carga completa de recursos (imágenes pueden desplazar layout) */
    window.addEventListener('load', debounce(markOverflowing, 120));

    /* MutationObserver para contenido AJAX / dinámico */
    initObserver();

    /* Restaurar al volver con bfcache */
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) {
        /* Ocultar botón copiar que pudo quedar visible antes de navegar */
        if (copyBtn) { clearTimeout(hideTimer); copyBtn.classList.remove('visible'); hoverEl = null; }
        setTimeout(markOverflowing, 60);
      }
    });
  }

  function debounce(fn, ms) {
    var t;
    return function () { clearTimeout(t); t = setTimeout(fn, ms); };
  }

  /* ── Lanzar según estado del DOM ── */
  if (document.readyState === 'loading') {
    /* Parsing aún en curso → esperar DOMContentLoaded */
    document.addEventListener('DOMContentLoaded', init);
  } else {
    /* DOM ya disponible (script cargado async / tras SPA) → ejecutar ahora */
    setTimeout(init, 0);
  }

  /* API pública: llamar desde cualquier módulo tras refrescar una tabla por AJAX */
  window.ggiMarkOverflowing = markOverflowing;

})();
</script>

<!-- ── PWA: Service Worker + Web Push ────────────────────────────────────── -->
<script>
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

  var VAPID_PUB = '<?= VAPID_PUBLIC_KEY ?>';

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
    fetch('<?= APP_URL ?>/modules/ajax_push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: body
    }).catch(function() {});
  }

  // Registrar SW. updateViaCache:'none' obliga al navegador a NO usar la caché HTTP
  // al revisar sw.js (clave para que un SW viejo cacheado como immutable se actualice).
  navigator.serviceWorker.register('<?= APP_URL ?>/sw.js', { scope: '/', updateViaCache: 'none' })
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
</script>

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

<script>
(function() {
  var lb, imgEl, frameEl, pdfEl, bodyEl, zoomBtn, titleEl, dlEl, loadEl, _lastFocus = null;
  var _curUrl = '', _curKind = '';   // para el botón imprimir (image | pdf | iframe)
  var PDFJS_SRC    = <?= json_encode(APP_URL . '/assets/vendor/pdfjs/pdf.min.js') ?>;
  var PDFJS_WORKER = <?= json_encode(APP_URL . '/assets/vendor/pdfjs/pdf.worker.min.js') ?>;
  var _pdfDoc = null, _pdfZoom = false, _pdfToken = 0, _pdfLibPromise = null;
  function refs() {
    lb = document.getElementById('ggi-lightbox');
    if (!lb) return false;
    imgEl   = lb.querySelector('.ggi-lightbox-img');
    frameEl = lb.querySelector('.ggi-lightbox-frame');
    pdfEl   = lb.querySelector('.ggi-lightbox-pdf');
    bodyEl  = lb.querySelector('.ggi-lightbox-body');
    zoomBtn = lb.querySelector('[data-zoom]');
    titleEl = lb.querySelector('.ggi-lightbox-title');
    dlEl    = lb.querySelector('[data-download]');
    loadEl  = lb.querySelector('.ggi-lightbox-loading');
    return true;
  }
  function loadPdfLib() {
    if (window.pdfjsLib) return Promise.resolve();
    if (_pdfLibPromise) return _pdfLibPromise;
    _pdfLibPromise = new Promise(function(res, rej) {
      var s = document.createElement('script');
      s.src = PDFJS_SRC;
      s.onload = function() { try { pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; } catch(e) {} res(); };
      s.onerror = rej;
      document.head.appendChild(s);
    });
    return _pdfLibPromise;
  }
  function _withTimeout(promise, ms) {
    return Promise.race([ promise, new Promise(function (r) { setTimeout(function () { r('__timeout'); }, ms); }) ]);
  }
  function _diag(t) { /* diagnóstico retirado tras validar en iOS */ }
  async function renderPdfPages(token) {
    if (!_pdfDoc) return;
    pdfEl.innerHTML = '';
    var n = _pdfDoc.numPages;
    var bodyW = (bodyEl.clientWidth || window.innerWidth || 800) - 16;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);  // tope 2: un canvas enorme (dpr 3) cuelga en iOS
    var p1 = await _withTimeout(_pdfDoc.getPage(1), 6000);
    if (token !== _pdfToken) return;
    if (!p1 || p1 === '__timeout' || !p1.getViewport) return;
    var vp1   = p1.getViewport({ scale: 1 });
    var scale = bodyW / vp1.width;
    var vpBase = p1.getViewport({ scale: scale });
    // FASE 1: crear YA N páginas (envoltura + canvas) con dims de la pág.1 → aparecen todas
    // las hojas y se puede desplazar de inmediato, aunque el contenido tarde en pintar.
    var pages = [];
    for (var i = 0; i < n; i++) {
      var wrap = document.createElement('div');
      wrap.className = 'ggi-pdf-page';
      wrap.style.width  = vpBase.width + 'px';
      wrap.style.height = vpBase.height + 'px';
      var cv = document.createElement('canvas');
      cv.width  = Math.round(vpBase.width * dpr);
      cv.height = Math.round(vpBase.height * dpr);
      cv.style.width  = vpBase.width + 'px';
      cv.style.height = vpBase.height + 'px';
      wrap.appendChild(cv);
      pdfEl.appendChild(wrap);
      pages.push({ wrap: wrap, cv: cv });
    }
    loadEl.hidden = true;
    // FASE 2: pintar el canvas y montar la CAPA DE TEXTO (seleccionable/copiable) encima.
    for (var k = 1; k <= n; k++) {
      if (token !== _pdfToken) return;
      var page = (k === 1) ? p1 : await _withTimeout(_pdfDoc.getPage(k), 6000);
      if (token !== _pdfToken) return;
      if (!page || page === '__timeout' || !page.getViewport) continue;
      var vp = page.getViewport({ scale: scale });
      var pg = pages[k - 1];
      pg.wrap.style.width  = vp.width + 'px';
      pg.wrap.style.height = vp.height + 'px';
      pg.cv.width  = Math.round(vp.width * dpr);
      pg.cv.height = Math.round(vp.height * dpr);
      pg.cv.style.width  = vp.width + 'px';
      pg.cv.style.height = vp.height + 'px';
      var ctx = pg.cv.getContext('2d'); ctx.scale(dpr, dpr);
      // Pintar el canvas SIN await: su promesa puede colgar (bug pdf.js 3.11) pero el canvas se pinta.
      try { page.render({ canvasContext: ctx, viewport: vp }).promise.catch(function () {}); } catch (e) {}
      // CAPA DE TEXTO (seleccionable/copiable): getTextContent SÍ resuelve; renderTextLayer se dispara
      // sin await (su promesa también puede colgar, pero monta los spans igual).
      if (token !== _pdfToken) return;
      try {
        var tc = await _withTimeout(page.getTextContent(), 6000);
        if (tc && tc !== '__timeout' && tc.items && tc.items.length && pdfjsLib.renderTextLayer) {
          var tl = document.createElement('div');
          tl.className = 'textLayer';
          tl.style.width  = vp.width + 'px';
          tl.style.height = vp.height + 'px';
          tl.style.setProperty('--scale-factor', scale);
          pg.wrap.appendChild(tl);
          try { pdfjsLib.renderTextLayer({ textContentSource: tc, container: tl, viewport: vp, textDivs: [] }).promise.catch(function () {}); } catch (e) {}
        }
      } catch (e) {}
    }
  }
  async function renderPdfData(data, url, token) {
    pdfEl.hidden = false; pdfEl.innerHTML = '';
    bodyEl.classList.add('is-pdf'); lb.classList.add('lb-pdf');
    loadEl.hidden = false;
    _diag('v4 cargando pdf.js…');
    try {
      await loadPdfLib();
      if (token !== _pdfToken) return;
      _diag('v4 getDocument…');
      var doc = await _withTimeout(pdfjsLib.getDocument({ data: data }).promise, 12000);
      if (token !== _pdfToken) { try { doc.destroy(); } catch(e) {} return; }
      if (!doc || doc === '__timeout' || !doc.numPages) throw new Error('getDocument-timeout');
      _pdfDoc = doc;
      await renderPdfPages(token);
      loadEl.hidden = true;
    } catch (e) {
      // pdf.js falló → fallback a iframe (con la URL original)
      if (token !== _pdfToken) return;
      _diag('v4 pdf.js FALLO→iframe: ' + (e && e.message ? e.message : e));
      pdfEl.hidden = true; pdfEl.innerHTML = '';
      bodyEl.classList.remove('is-pdf'); lb.classList.remove('lb-pdf');
      loadEl.hidden = true;
      frameEl.hidden = false; frameEl.src = url;
    }
  }
  function open(url, opts) {
    opts = opts || {};
    if (!lb && !refs()) return;
    if (!url) return;
    _curUrl = url; _curKind = '';
    _lastFocus = document.activeElement;
    titleEl.textContent = opts.title || '';
    dlEl.setAttribute('href', opts.download || url);
    if (opts.filename) dlEl.setAttribute('download', opts.filename); else dlEl.setAttribute('download', '');
    // reset estado
    _pdfToken++; _pdfDoc = null; _pdfZoom = false;
    imgEl.hidden = true; imgEl.classList.remove('zoomed'); imgEl.removeAttribute('src');
    bodyEl.classList.remove('is-zoomed', 'is-pdf'); lb.classList.remove('lb-pdf');
    pdfEl.hidden = true; pdfEl.innerHTML = '';
    frameEl.hidden = true; frameEl.removeAttribute('src');
    if (zoomBtn) zoomBtn.hidden = true;   // se muestra si es imagen o PDF
    loadEl.hidden = false;
    lb.hidden = false;
    lockScroll();
    imgEl.alt = opts.title || 'Vista previa';
    _diag('v4 detectando…');
    // NO usar img.onload/onerror para detectar tipo: iOS Safari carga PDFs DENTRO de un <img>
    // (dispara onload y muestra solo la 1ª página). Obtenemos el content-type real por fetch
    // y a los PDF los pasamos a pdf.js DESDE LOS BYTES (así iOS no los trata como imagen).
    var token = _pdfToken;
    fetch(url, { credentials: 'same-origin' }).then(function (resp) {
      var ct = (resp.headers.get('content-type') || '').toLowerCase();
      return resp.blob().then(function (blob) { return { ct: ct || (blob.type || ''), blob: blob }; });
    }).then(function (res) {
      if (token !== _pdfToken) return;
      var ct = (res.ct || res.blob.type || '').toLowerCase();
      _diag('v4 tipo:' + (ct || '?'));
      if (ct.indexOf('image/') === 0) {
        _curKind = 'image';
        var ou = URL.createObjectURL(res.blob);
        imgEl.onload  = function () { loadEl.hidden = true; imgEl.hidden = false; };
        imgEl.onerror = function () { loadEl.hidden = true; _diag('v4 img ERROR'); };
        imgEl.src = ou;
      } else {
        _curKind = 'pdf';
        res.blob.arrayBuffer().then(function (buf) {
          if (token !== _pdfToken) return;
          renderPdfData(buf, url, token);
        });
      }
    }).catch(function (e) {
      if (token !== _pdfToken) return;
      _curKind = 'iframe';
      _diag('v4 fetch FALLO:' + (e && e.message ? e.message : e) + '→iframe');
      loadEl.hidden = true; frameEl.hidden = false; frameEl.src = url;
    });
    var closeBtn = lb.querySelector('.ggi-lightbox-close');
    if (closeBtn) closeBtn.focus();
  }
  function close() {
    if (!lb || lb.hidden) return;
    lb.hidden = true;
    _pdfToken++; _pdfDoc = null;
    imgEl.removeAttribute('src'); frameEl.removeAttribute('src');
    pdfEl.innerHTML = ''; pdfEl.hidden = true;
    bodyEl.classList.remove('is-pdf', 'is-zoomed'); lb.classList.remove('lb-pdf');
    unlockScroll();
    if (_lastFocus && _lastFocus.focus) _lastFocus.focus();
    _lastFocus = null;
  }
  // El scroll lo hace de forma nativa el propio overlay (.lb-pdf con overflow:auto +
  // -webkit-overflow-scrolling:touch), que es lo fiable en iOS. Solo bloqueamos el scroll
  // de la página de fondo mientras el modal está abierto, sin tocar overflow de <html>.
  function _blockBgScroll(e) {
    // permitir el gesto dentro del overlay (que es el scroller); bloquear fuera
    if (lb && lb.contains(e.target)) return;
    if (e.cancelable) e.preventDefault();
  }
  function lockScroll() {
    document.addEventListener('touchmove', _blockBgScroll, { passive: false });
  }
  function unlockScroll() {
    document.removeEventListener('touchmove', _blockBgScroll, { passive: false });
  }
  // Imprimir el archivo actual (imagen o PDF) mediante un iframe oculto.
  function printCurrent() {
    if (!_curUrl) return;
    var old = document.getElementById('ggi-print-frame');
    if (old) { try { old.parentNode.removeChild(old); } catch (_) {} }
    var f = document.createElement('iframe');
    f.id = 'ggi-print-frame';
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    document.body.appendChild(f);
    var doPrint = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (_) { window.open(_curUrl, '_blank'); }  // fallback (iOS/PWA que bloquee print)
    };
    if (_curKind === 'image') {
      var doc = f.contentWindow.document;
      doc.open();
      doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Imprimir</title>'
        + '<style>@page{margin:10mm}html,body{margin:0;height:100%}'
        + 'img{max-width:100%;max-height:100vh;display:block;margin:0 auto}</style></head>'
        + '<body><img src="' + _curUrl.replace(/"/g, '&quot;') + '"></body></html>');
      doc.close();
      var img = doc.querySelector('img');
      if (img) { img.onload = function () { setTimeout(doPrint, 60); }; }
      else setTimeout(doPrint, 300);
    } else {
      // PDF u otro documento: cargar el archivo directo en el iframe
      f.onload = function () { setTimeout(doPrint, 120); };
      f.src = _curUrl;
    }
  }

  window.ggiLightbox = { open: open, close: close, print: printCurrent };

  // Apertura por delegación: cualquier [data-ggi-preview] (link o botón)
  document.addEventListener('click', function(e) {
    var trg = e.target.closest('[data-ggi-preview]');
    if (trg) {
      // data-src (archivo crudo) tiene prioridad; href queda como fallback sin-JS
      var url = trg.getAttribute('data-src') || trg.getAttribute('href');
      if (url && url !== '#') {
        e.preventDefault();
        open(url, { title: trg.getAttribute('data-title') || trg.getAttribute('title') || '', download: trg.getAttribute('data-download-url') || url });
      }
      return;
    }
    if (!lb || lb.hidden) return;
    if (e.target.closest('[data-print]'))    { e.preventDefault(); printCurrent(); return; }
    if (e.target.closest('[data-download]')) return;      // dejar descargar nativo
    if (e.target.closest('[data-close]'))    { close(); return; }
  });

  // Teclado: Escape cierra; Tab atrapado dentro del diálogo
  document.addEventListener('keydown', function(e) {
    if (!lb || lb.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'Tab') {
      var f = lb.querySelectorAll('button, a[href], iframe');
      f = Array.prototype.filter.call(f, function(el){ return el.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
})();
</script>

<?php include __DIR__ . '/pwa-install.php'; ?>

<script>
/* ── Descargas iOS y Android PWA ────────────────────────────────────────────
 *
 * PROBLEMA DIAGNOSTICADO:
 *   En iOS WKWebView (standalone PWA y Safari móvil), el atributo `download`
 *   se ignora para URLs de servidor. El navegador navega a la URL, recibe
 *   Content-Disposition: attachment, muestra Quick Look, y al cerrar
 *   Quick Look el WebView queda en estado inconsistente (posible reset del
 *   contexto de cookies), provocando pérdida de sesión.
 *
 * SOLUCIÓN (iOS — PWA y Safari):
 *   Interceptar el clic. Descargar con fetch() same-origin (cookie siempre
 *   enviada, sin navegación del WebView). Compartir con Web Share API o
 *   blob URL. El WebView nunca abandona la página → sesión intacta.
 *
 * Android PWA:
 *   window.open(_blank) abre Chrome externo donde attachment funciona nativo.
 * ─────────────────────────────────────────────────────────────────────────── */
(function () {
  /* Detecta iOS aunque Safari tenga "Sitio web para ordenador" activo.
     En ese modo el UA reporta "Macintosh" pero maxTouchPoints sigue siendo > 1. */
  var isIos     = /iP(hone|ad|od)/i.test(navigator.userAgent)
                  || (/Mac/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
  var isAndroid = !isIos && window.matchMedia
                  && window.matchMedia('(display-mode:standalone)').matches;

  /* ── Android PWA ── */
  if (isAndroid) {
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a[href*="descargar=1"]');
      if (!a) return;
      e.preventDefault();
      window.open(a.href, '_blank');
    }, true);
    return;
  }

  /* ── iOS (PWA + Safari) — fetch + Web Share / blob URL ── */
  if (!isIos) return;

  var LOG_EP = '/modules/ajax_pwa_log.php';

  /* Inyectar animación de spinner una sola vez */
  if (!document.getElementById('_ggi_dl_css')) {
    var st = document.createElement('style');
    st.id  = '_ggi_dl_css';
    st.textContent = '@keyframes _ggiSpin{to{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }

  /* Registra en consola + pwa_debug.log (fire-and-forget) */
  function ggiLog(step, detail) {
    var msg = detail ? (step + ' | ' + detail) : step;
    console.log('[GGI-DL]', msg);
    try {
      var fd = new FormData();
      fd.append('msg', msg);
      fetch(LOG_EP, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
    } catch(ignore) {}
  }

  function SPIN(txt) {
    return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"'
      + ' stroke="currentColor" stroke-width="2.5" stroke-linecap="round"'
      + ' style="animation:_ggiSpin .7s linear infinite;vertical-align:-1px;margin-right:4px">'
      + '<path d="M12 2a10 10 0 0 1 10 10"/></svg>' + txt;
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href*="descargar=1"]');
    if (!a || a._ggiBusy) return;
    e.preventDefault();
    e.stopPropagation();

    a._ggiBusy            = true;
    var savedHTML         = a.innerHTML;
    var savedPointer      = a.style.pointerEvents;
    a.style.pointerEvents = 'none';
    var navigateAway      = false;

    /* ── PASO 1 ── */
    ggiLog('PASO_1_CLICK', 'url=' + a.href);
    a.innerHTML = SPIN('Descargando archivo…');

    /* ── PASO 2 ── */
    ggiLog('PASO_2_FETCH_INICIO', 'credentials=same-origin');

    fetch(a.href, { credentials: 'same-origin' })

      /* ── PASO 3 ── */
      .then(function (resp) {
        ggiLog('PASO_3_FETCH_RESPUESTA',
          'status=' + resp.status + ' ok=' + resp.ok + ' type=' + resp.type);

        if (!resp.ok) { ggiLog('PASO_3_ERROR', 'HTTP_' + resp.status); throw new Error('HTTP_' + resp.status); }

        var cd      = resp.headers.get('content-disposition') || '';
        var ct      = resp.headers.get('content-type')        || '';
        var fnMatch = cd.match(/filename="?([^";\r\n]+)"?/i);
        var fname   = fnMatch ? fnMatch[1].trim() : 'documento';
        ggiLog('PASO_3b_HEADERS', 'ct=' + ct + ' | cd=' + cd + ' | fname=' + fname);

        a.innerHTML = SPIN('Preparando archivo…');
        return resp.blob().then(function (blob) { return { blob: blob, fname: fname }; });
      })

      /* ── PASO 4 ── */
      .then(function (r) {
        ggiLog('PASO_4_BLOB', 'size=' + r.blob.size + ' | type=' + r.blob.type + ' | fname=' + r.fname);
        if (r.blob.size === 0) { ggiLog('PASO_4_ERROR', 'blob_vacio'); throw new Error('BLOB_VACIO'); }

        /* ── PASO 5 ── */
        var file = new File([r.blob], r.fname, { type: r.blob.type });
        ggiLog('PASO_5_FILE', 'name=' + file.name + ' size=' + file.size);

        /* ── PASO 6 ── */
        var shareOk = !!(navigator.canShare && navigator.canShare({ files: [file] }));
        ggiLog('PASO_6_CANSHARE',
          'share_fn=' + (typeof navigator.share) +
          ' | canShare_fn=' + (typeof navigator.canShare) +
          ' | result=' + shareOk);

        if (shareOk) {
          /* ── PASO 7 ── */
          ggiLog('PASO_7_SHARE_INICIO', 'title=' + r.fname);
          a.innerHTML = SPIN('Abriendo menú de compartir…');

          return navigator.share({ files: [file], title: r.fname })
            .then(function () {
              /* ── PASO 8 ── */
              ggiLog('PASO_8_SHARE_OK', 'completado');
            })
            .catch(function (shareErr) {
              /* ── PASO 9 ── */
              ggiLog('PASO_9_SHARE_ERROR', 'name=' + shareErr.name + ' | msg=' + shareErr.message);
              if (shareErr.name === 'AbortError') {
                ggiLog('PASO_9b_SHARE_CANCELADO', 'usuario_cerro_share_sheet');
                return;
              }
              ggiLog('PASO_9c_SHARE_FALLO_REAL', 'fallback_a_blob');
              _blobFallback(r.blob, r.fname);
            });
        }

        /* ── PASO 10 ── */
        ggiLog('PASO_10_BLOB_FALLBACK', 'canShare=false');
        _blobFallback(r.blob, r.fname);
      })

      .catch(function (err) {
        var msg = err && err.message ? err.message : String(err);
        if (msg === 'BLOB_VACIO') {
          a.innerHTML = SPIN('Error: archivo vacío');
          setTimeout(function () {
            a.innerHTML = savedHTML; a.style.pointerEvents = savedPointer; a._ggiBusy = false;
          }, 2500);
          return;
        }
        /* ── PASO 11 ── */
        ggiLog('PASO_11_FALLBACK_NAV', 'error=' + msg);
        navigateAway = true;
        a.innerHTML  = SPIN('Error al descargar…');
        setTimeout(function () { window.location.href = a.href; }, 900);
      })

      .finally(function () {
        if (!navigateAway) {
          a.innerHTML = savedHTML; a.style.pointerEvents = savedPointer; a._ggiBusy = false;
        }
      });

    function _blobFallback(blob, fname) {
      try {
        var blobUrl = URL.createObjectURL(blob);
        var tmp = document.createElement('a');
        tmp.href = blobUrl; tmp.download = fname;
        document.body.appendChild(tmp); tmp.click(); document.body.removeChild(tmp);
        ggiLog('PASO_10b_BLOB_CLICK', 'blobUrl_generado_y_clic_disparado');
        setTimeout(function () { URL.revokeObjectURL(blobUrl); }, 6000);
      } catch (blobErr) {
        ggiLog('PASO_10c_BLOB_ERROR', String(blobErr));
      }
    }
  }, true);
})();
</script>

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
