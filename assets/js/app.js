/* app.js — JS global extraído de layout_end.php (bloque principal, auditoría 2026-07).
   Depende de window.APP_URL / CSRF_TOKEN / ACTIVE_MENU (puente inline antes). */

// ── Navegación de paginación canónica (.ggi-pager / helper ggi_pager) ──
// Actualiza un parámetro del query string y recarga. Limpia cursores legados.
// resetPage=true vuelve a la página 1 (al cambiar "ítems por página").
window.ggiPagerGo = function(param, value, resetPage) {
  var u = new URLSearchParams(window.location.search);
  u.set(param, value);
  if (resetPage) u.delete('p');
  u.delete('cursor'); u.delete('prev');
  window.location.search = u.toString();
};
// ── Copiar identificadores (.ggi-copyable / helper ggi_copy) ──
(function () {
  function flash(el) {
    el.classList.add('ggi-copied');
    if (typeof window.showToast === 'function') window.showToast('Copiado', 'ok');
    setTimeout(function () { el.classList.remove('ggi-copied'); }, 1400);
  }
  function fallback(text, el) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;top:-9999px;opacity:0';
    document.body.appendChild(ta); ta.select();
    try { if (document.execCommand('copy')) flash(el); } catch (_) {}
    document.body.removeChild(ta);
  }
  function copy(el) {
    var text = (el.getAttribute('data-ggi-copy') || el.textContent || '').trim();
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { flash(el); }, function () { fallback(text, el); });
    } else { fallback(text, el); }
  }
  document.addEventListener('click', function (e) {
    var el = e.target.closest('.ggi-copyable');
    if (!el) return;
    e.preventDefault(); e.stopPropagation();
    copy(el);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var el = e.target.closest && e.target.closest('.ggi-copyable');
    if (!el) return;
    e.preventDefault(); copy(el);
  });
})();

// Salto de página validado desde el input (clamp 1..max).
window.ggiPagerJump = function(input, pageParam, maxPages) {
  var v = parseInt(input.value, 10);
  if (isNaN(v) || v < 1) v = 1;
  if (v > maxPages) v = maxPages;
  window.ggiPagerGo(pageParam, v, false);
};

// ── Popover genérico para chips "+N" (normas, etc.) ──
// position:fixed → escapa contenedores con overflow. Click/tap/teclado (touch-friendly).
// El disparador debe tener data-ggipop="<html del contenido>".
(function () {
  var _pop = null, _trigger = null, _hoverTimer = null;
  var _canHover = !(window.matchMedia && window.matchMedia('(hover: none)').matches);
  function close() {
    if (!_pop) return;
    _pop.remove(); _pop = null;
    if (_trigger) { _trigger.setAttribute('aria-expanded', 'false'); _trigger = null; }
    document.removeEventListener('mousedown', onDoc, true);
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', close); window.removeEventListener('scroll', close, true);
  }
  function onDoc(e) { if (_pop && !_pop.contains(e.target) && e.target !== _trigger) close(); }
  function onKey(e) { if (e.key === 'Escape') { close(); if (_trigger) _trigger.focus(); } }
  function open(trigger) {
    close();
    _trigger = trigger;
    _pop = document.createElement('div');
    _pop.className = 'ggi-chip-pop';
    _pop.innerHTML = trigger.getAttribute('data-ggipop') || '';
    document.body.appendChild(_pop);
    /* Mantener abierto mientras el puntero está sobre el popover (modo hover) */
    _pop.addEventListener('mouseenter', function () { clearTimeout(_hoverTimer); });
    _pop.addEventListener('mouseleave', function () { _hoverTimer = setTimeout(close, 160); });
    var r = trigger.getBoundingClientRect();
    var pw = _pop.offsetWidth, ph = _pop.offsetHeight;
    var left = Math.min(r.left, window.innerWidth - pw - 10);
    var top  = r.bottom + 6;
    if (top + ph > window.innerHeight - 10) top = r.top - ph - 6;  // abre arriba si no cabe abajo
    _pop.style.left = Math.max(8, left) + 'px';
    _pop.style.top  = Math.max(8, top) + 'px';
    trigger.setAttribute('aria-expanded', 'true');
    setTimeout(function () {
      document.addEventListener('mousedown', onDoc, true);
      document.addEventListener('keydown', onKey, true);
      window.addEventListener('resize', close);
      window.addEventListener('scroll', close, true);
    }, 0);
  }
  /* Click/tap/teclado: toggle (funciona en todas las plataformas) */
  window.ggiChipPop = function (trigger) {
    if (_trigger === trigger) { close(); return; }
    open(trigger);
  };
  /* Hover en escritorio (solo dispositivos con puntero) — mejora, no requisito.
     Se activa en disparadores con [data-pop-hover]. En touch nunca corre. */
  if (_canHover) {
    document.addEventListener('mouseover', function (e) {
      var t = e.target.closest('[data-pop-hover]');
      if (!t) return;
      clearTimeout(_hoverTimer);
      if (_trigger !== t) open(t);
    });
    document.addEventListener('mouseout', function (e) {
      var t = e.target.closest('[data-pop-hover]');
      if (!t) return;
      var to = e.relatedTarget;
      if (to && _pop && _pop.contains(to)) return;       // pasa al popover
      if (to && to.closest && to.closest('[data-pop-hover]') === t) return;
      _hoverTimer = setTimeout(close, 160);
    });
  }
})();

// ── CSRF helper — lee siempre desde <meta name="csrf-token"> como fuente
// de verdad, con window.CSRF_TOKEN como fallback (puede quedar obsoleto tras SPA).
function _csrfToken() {
  var m = document.querySelector('meta[name="csrf-token"]');
  return (m && m.getAttribute('content')) || window.CSRF_TOKEN || '';
}
// Actualiza tanto window.CSRF_TOKEN como la meta tag
function _csrfUpdate(newToken) {
  if (!newToken) return;
  window.CSRF_TOKEN = newToken;
  var m = document.querySelector('meta[name="csrf-token"]');
  if (m) m.setAttribute('content', newToken);
}

/**
 * ggiPost(url, datos, opciones) — ÚNICA forma de hacer POST autenticado.
 *
 * Cada módulo repetía a mano el mismo baile (FormData + _csrf + fetch +
 * parseo + error), con variantes distintas del nombre del campo y de la
 * clave del mensaje de error. Equivocarse en cualquiera de las dos produce
 * un 403 que la interfaz se traga en silencio: la acción no ocurre y el
 * usuario no se entera. Este helper lo centraliza:
 *
 *  - Adjunta el _csrf vigente (campo Y cabecera).
 *  - Marca la petición como AJAX para que el backend responda JSON.
 *  - Absorbe la rotación de token (json.csrf).
 *  - Normaliza el error venga como msg, mensaje o error, y lo MUESTRA.
 *
 * Devuelve siempre un objeto (nunca lanza), con .ok booleano.
 * Pasar {silent:true} para manejar el error por cuenta propia.
 */
window.ggiPost = function (url, datos, opciones) {
  opciones = opciones || {};

  function avisar(m) {
    if (opciones.silent) return;
    if (typeof showToast === 'function') showToast(m, 'error');
  }

  var cabeceras = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': _csrfToken() };
  var cuerpo;

  if (opciones.json) {
    // Endpoints que leen el cuerpo crudo con json_decode(php://input).
    // Ahí el token no puede viajar como campo: va solo en la cabecera,
    // que csrf_verify() también acepta.
    cabeceras['Content-Type'] = 'application/json';
    cuerpo = JSON.stringify(datos || {});
  } else {
    var fd = (datos instanceof FormData) ? datos : new FormData();
    if (!(datos instanceof FormData) && datos) {
      Object.keys(datos).forEach(function (k) { fd.append(k, datos[k]); });
    }
    fd.set('_csrf', _csrfToken());
    cuerpo = fd;
  }

  return fetch(url, {
    method: 'POST',
    body: cuerpo,
    credentials: 'same-origin',
    headers: cabeceras
  })
  .then(function (r) {
    return r.json().then(
      function (j) { return { http: r.ok, j: j }; },
      function ()  { return { http: r.ok, j: { _sinJson: true, _status: r.status } }; }
    );
  })
  .then(function (res) {
    var j = res.j || {};
    if (j.csrf) _csrfUpdate(j.csrf);

    // Solo se considera fallo lo EXPLÍCITO. Los endpoints del sistema no
    // comparten una forma de respuesta única; si se tratara como error todo
    // lo que no traiga ok:true, las respuestas con otra forma dispararían
    // avisos falsos.
    var falla = !res.http || j.ok === false || j.success === false
             || (j.error && j.ok === undefined && j.success === undefined);

    if (falla) {
      var m = j.msg || j.mensaje || j.error
           || (j._sinJson ? 'El servidor respondió algo inesperado (HTTP ' + j._status + ')'
                          : 'No se pudo completar la acción');
      avisar(m);
      j.ok = false;
    } else if (j.ok === undefined) {
      j.ok = true;   // respuesta sin campo de estado → se asume correcta
    }

    return j;
  })
  .catch(function () {
    avisar('Sin conexión con el servidor');
    return { ok: false, msg: 'Sin conexión' };
  });
};


// ── Tema claro/oscuro ──
var TEMA_ICONS = {
  claro:   '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
  oscuro:  '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
  sistema: '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
};
var TEMA_LABELS = {
  claro:   'Tema claro',
  oscuro:  'Tema oscuro',
  sistema: 'Tema automático',
};

function _resolverTemaReal(tema) {
  // Para 'sistema', retorna 'claro' u 'oscuro' según el OS
  if (tema !== 'sistema') return tema;
  return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
    ? 'oscuro' : 'claro';
}

function actualizarUITema(tema) {
  // Botón del header
  var icon = document.getElementById('tema-icon');
  var btn  = document.getElementById('btn-tema');
  if (icon) icon.innerHTML = TEMA_ICONS[tema] || TEMA_ICONS.sistema;
  if (btn)  btn.setAttribute('data-tooltip', TEMA_LABELS[tema] || 'Tema');

  // Mini botones del popup
  document.querySelectorAll('.tema-mini-btn').forEach(function(b) {
    b.classList.toggle('activo', b.dataset.tema === tema);
  });
}

function applyTema(tema) {
  // Transición suave
  var html = document.documentElement;
  html.classList.add('tema-transitioning');
  setTimeout(function() { html.classList.remove('tema-transitioning'); }, 280);

  // El atributo data-tema controla todo — el CSS (via variables) maneja los colores
  var real = _resolverTemaReal(tema);
  if (real === 'oscuro') {
    html.setAttribute('data-tema', 'oscuro');
  } else {
    html.setAttribute('data-tema', 'claro');
    // Limpiar inline backgrounds residuales de versiones anteriores del código
    html.style.removeProperty('background');
    html.style.removeProperty('background-color');
    document.body.style.removeProperty('background');
    document.body.style.removeProperty('background-color');
  }

  localStorage.setItem('ggi_tema', tema);
  actualizarUITema(tema);
  /* Actualizar colores de estado-sel al cambiar de tema */
  if (typeof window.ggiApplySelectColors === 'function') {
    setTimeout(window.ggiApplySelectColors, 0);
  }
  if (typeof window.initEstadoSel === 'function') {
    setTimeout(window.initEstadoSel, 0);
  }
}

function ciclarTema() {
  var actual = localStorage.getItem('ggi_tema') || 'sistema';
  var ciclo  = { claro: 'oscuro', oscuro: 'sistema', sistema: 'claro' };
  applyTema(ciclo[actual] || 'claro');
}

// Escuchar cambios de preferencia del OS (afecta sólo en modo "sistema")
if (window.matchMedia) {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
    if ((localStorage.getItem('ggi_tema') || 'sistema') === 'sistema') {
      applyTema('sistema');
    }
  });
}

// Inicializar UI al cargar
(function() {
  var t = localStorage.getItem('ggi_tema') || 'sistema';
  // Llamar después de que el DOM esté listo
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { actualizarUITema(t); });
  } else {
    actualizarUITema(t);
  }
})();

// ── Menú usuario sidebar ──
function toggleUserMenu() {
  const popup = document.getElementById('user-popup');
  const card  = document.getElementById('user-card-btn');
  const open  = popup.classList.toggle('open');
  card.classList.toggle('open', open);
}
document.addEventListener('click', function(e) {
  const card  = document.getElementById('user-card-btn');
  const popup = document.getElementById('user-popup');
  if (card && popup && !card.contains(e.target) && !popup.contains(e.target)) {
    popup.classList.remove('open');
    card.classList.remove('open');
  }
});

// ── Buscador global ──
(function() {
  const input    = document.getElementById('global-search-input');
  const dropdown = document.getElementById('gs-dropdown');
  if (!input) return;

  const _dark = () => document.documentElement.getAttribute('data-tema') === 'oscuro';
  const TIPO_META_LIGHT = {
    cliente:    { color: '#1A3A8F', bg: '#e8edf8', label: 'Cliente' },
    certificado:{ color: '#2DB140', bg: '#e8f7eb', label: 'Certificado' },
    envio:      { color: '#8b5cf6', bg: '#f5f3ff', label: 'Envío' },
    propuesta: { color: '#10b981', bg: '#d1fae5', label: 'Propuesta' },
  };
  const TIPO_META_DARK = {
    cliente:    { color: '#94a3b8', bg: '#252d3d', label: 'Cliente' },
    certificado:{ color: '#34D399', bg: '#0f3020', label: 'Certificado' },
    envio:      { color: '#a78bfa', bg: '#2e1a4a', label: 'Envío' },
    propuesta: { color: '#34D399', bg: '#0f3020', label: 'Propuesta' },
  };
  const TIPO_META = new Proxy({}, { get: (_, k) => (_dark() ? TIPO_META_DARK : TIPO_META_LIGHT)[k] });
  const ICONOS = {
    empresa:    '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    certificado:'<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>',
    envio:      '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    propuesta: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>',
  };

  let timer = null;
  let ultimaQuery = '';

  // ── Caché localStorage: resultados instantáneos en búsquedas repetidas ───────
  // Guarda las últimas 5 consultas (LRU). Cada entrada expira a los 10 minutos.
  // En la práctica, los mismos clientes/certificados se buscan varias veces al día,
  // por lo que el caché los muestra de inmediato mientras el AJAX actualiza en segundo plano.
  const CACHE_KEY  = 'ggi_gsearch_v1';
  const CACHE_MAX  = 5;
  const CACHE_TTL  = 10 * 60 * 1000; // 10 min en ms

  function cacheRead() {
    try { return JSON.parse(localStorage.getItem(CACHE_KEY) || '[]'); } catch { return []; }
  }
  function cacheWrite(entries) {
    try { localStorage.setItem(CACHE_KEY, JSON.stringify(entries)); } catch {}
  }
  function cacheGet(q) {
    const now = Date.now();
    return cacheRead().find(e => e.q === q && (now - e.ts) < CACHE_TTL) || null;
  }
  function cacheSet(q, data) {
    const now  = Date.now();
    let entries = cacheRead().filter(e => e.q !== q && (now - e.ts) < CACHE_TTL);
    entries.unshift({ q, data, ts: now });
    if (entries.length > CACHE_MAX) entries = entries.slice(0, CACHE_MAX);
    cacheWrite(entries);
  }

  function mostrarLoading() {
    dropdown.style.display = 'block';
    dropdown.innerHTML = '<div class="gs-loading">Buscando...</div>';
  }

  function renderResultados(data, q) {
    if (!data.resultados || data.resultados.length === 0) {
      return `<div class="gs-empty">Sin resultados para "<strong>${q}</strong>"</div>`;
    }
    return data.resultados.map(r => {
      const m = TIPO_META[r.tipo] || { color: '#6b7a99', bg: '#f0f2f8', label: r.tipo };
      const ico = ICONOS[r.icono] || ICONOS.empresa;
      return `<a href="${r.url}" class="gs-item">
        <div class="gs-icon" style="background:${m.bg}">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="${m.color}" stroke-width="2">${ico}</svg>
        </div>
        <div style="flex:1;min-width:0;">
          <div class="gs-titulo">${r.titulo}</div>
          <div class="gs-sub">${r.subtitulo}</div>
        </div>
        <span class="gs-tipo" style="background:${m.bg};color:${m.color}">${m.label}</span>
      </a>`;
    }).join('');
  }

  function mostrarResultados(data, q) {
    dropdown.innerHTML = renderResultados(data, q);
  }

  function buscar(q) {
    if (q === ultimaQuery) return;
    ultimaQuery = q;
    if (q.length < 2) { dropdown.style.display = 'none'; return; }

    // Mostrar caché inmediatamente (si existe) para que el resultado sea instantáneo
    const cached = cacheGet(q);
    if (cached) {
      dropdown.style.display = 'block';
      dropdown.innerHTML = renderResultados(cached, q);
    } else {
      mostrarLoading();
    }

    // Siempre lanzar el AJAX para obtener resultados frescos y actualizar el caché
    fetch(`${window.APP_URL}/modules/ajax_busqueda_global.php?q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => {
        if (input.value.trim() !== q) return; // el usuario ya escribió otra cosa
        cacheSet(q, data);
        mostrarResultados(data, q);
      })
      .catch(() => {
        if (!cached) { // si tenemos caché, no sobreescribir con el error
          dropdown.innerHTML = '<div class="gs-empty">Error al buscar.</div>';
        }
      });
  }

  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { dropdown.style.display = 'none'; ultimaQuery = ''; return; }
    dropdown.style.display = 'block';
    timer = setTimeout(() => buscar(q), 280);
  });

  input.addEventListener('focus', () => {
    const q = input.value.trim();
    if (q.length >= 2) {
      // Si ya hay contenido en el dropdown (sesión actual) → mostrarlo
      if (dropdown.innerHTML) { dropdown.style.display = 'block'; return; }
      // Si hay caché → mostrar inmediatamente y refrescar en segundo plano
      const cached = cacheGet(q);
      if (cached) {
        dropdown.style.display = 'block';
        dropdown.innerHTML = renderResultados(cached, q);
        // Refrescar en background (sin mostrar loading ni resetear ultimaQuery)
        fetch(`${window.APP_URL}/modules/ajax_busqueda_global.php?q=${encodeURIComponent(q)}`)
          .then(r => r.json()).then(data => { cacheSet(q, data); if (input.value.trim() === q) mostrarResultados(data, q); })
          .catch(() => {});
      }
    }
  });

  document.addEventListener('click', e => {
    if (!document.getElementById('gsw').contains(e.target)) {
      dropdown.style.display = 'none';
    }
  });

  // Atajo ⌘K / Ctrl+K → enfocar buscador (delegado a ShortcutManager)
  if (window.ShortcutManager) {
    ShortcutManager.register('ctrl+k', 'global', function(e, info) {
      if (info.context === 'modal') return false; // no interrumpir modales abiertos
      e.preventDefault();
      input.focus();
      if (input.select) input.select();
    }, { description: 'Buscar en el sistema', group: 'General' });
  } else {
    document.addEventListener('keydown', function(e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); input.focus(); input.select(); }
    });
  }

  // Escape → cerrar dropdown de búsqueda (delegado a ShortcutManager)
  if (window.ShortcutManager) {
    ShortcutManager.register('escape', 'search', function() {
      if (dropdown.style.display !== 'none' && dropdown.innerHTML) {
        dropdown.style.display = 'none';
        input.blur();
        return true;
      }
      return false;
    }, { description: 'Cerrar búsqueda', group: 'General' });
  } else {
    // Fallback si ShortcutManager no cargó
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') { dropdown.style.display = 'none'; input.blur(); }
    });
  }
})();

// ── Filtros persistentes (sessionStorage) ──
(function() {
  var form = document.querySelector('.filter-form');
  if (!form) return;

  var key = 'ggi_filters:' + location.pathname;

  // Determina si la URL actual ya tiene filtros activos (ignora "page" y valores vacíos o "0")
  function hasActiveParams(search) {
    var sp = new URLSearchParams(search);
    sp.delete('page');
    var active = false;
    sp.forEach(function(v) { if (v !== '' && v !== '0') active = true; });
    return active;
  }

  if (hasActiveParams(location.search)) {
    // Guardar filtros actuales
    sessionStorage.setItem(key, location.search);
  } else {
    // Restaurar filtros guardados (si los hay)
    var saved = sessionStorage.getItem(key);
    if (saved && hasActiveParams(saved)) {
      location.replace(location.pathname + saved);
      return;
    }
  }

  // Borrar sessionStorage cuando se pulsa "Limpiar filtros"
  document.addEventListener('click', function(e) {
    var link = e.target.closest('a');
    if (!link) return;
    var txt = link.textContent || '';
    if (txt.indexOf('Limpiar') !== -1) {
      sessionStorage.removeItem(key);
    }
  });
})();

// ── Botón "← Volver": usar referrer para preservar filtros ──
(function() {
  var form = document.querySelector('.filter-form');
  if (form) return; // Solo aplica en páginas de detalle (sin filtros)

  var ref = document.referrer;
  if (!ref) {
    // Sin referrer: intentar recuperar de sessionStorage (por si se refrescó la página)
    var stored = sessionStorage.getItem('ggi_back:' + location.href);
    if (stored) ref = stored;
  }
  if (!ref || ref.indexOf(window.APP_URL) !== 0) return;
  if (ref === location.href) return; // Evitar apuntar a sí mismo

  // Guardar en sessionStorage por si el usuario refresca la página de detalle
  sessionStorage.setItem('ggi_back:' + location.href, ref);

  document.querySelectorAll('a.btn-outline, a.btn-outline.btn-sm').forEach(function(link) {
    if (link.textContent.trim().indexOf('← Volver') === 0) {
      link.href = ref;
    }
  });
})();

// ── Sidebar móvil ──
function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('open');
  const ov = document.getElementById('sidebar-overlay');
  ov.style.display = ov.style.display === 'none' ? 'block' : 'none';
}
function closeSidebar() {
  document.querySelector('.sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').style.display = 'none';
}
// A11y (escape-routes): Escape cierra el drawer móvil cuando está abierto.
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  var sb = document.querySelector('.sidebar.open');
  if (sb && window.innerWidth <= 768) { closeSidebar(); }
});

// ── Sidebar desktop colapsable ──
function toggleSidebarDesktop() {
  const root    = document.documentElement;
  const body    = document.body;
  const sidebar = document.querySelector('.sidebar');
  const btn     = document.querySelector('.btn-toggle-desktop svg');

  // En modo embed (iframe de un modal) no hay sidebar → nada que colapsar.
  if (!sidebar) return;

  const collapsed = sidebar.classList.toggle('collapsed');
  body.classList.toggle('sidebar-collapsed', collapsed);

  // Una sola variable CSS conduce header + main + todo el contenido
  const mini = getComputedStyle(root).getPropertyValue('--sidebar-mini').trim() || '56px';
  if (collapsed) {
    root.style.setProperty('--sidebar-current', mini);
    if (btn) btn.style.transform = 'scaleX(-1)';
  } else {
    root.style.removeProperty('--sidebar-current'); // hereda var(--sidebar-w) del :root
    if (btn) btn.style.transform = '';
  }

  // Barra flotante de selección múltiple (módulo envíos)
  const bulkBar = document.getElementById('bulk-bar-mobile');
  if (bulkBar && bulkBar.style.display !== 'none') {
    bulkBar.style.left = collapsed ? '0' : 'var(--sidebar-w)';
  }

  // Clase anti-FOUC ya no es necesaria una vez que JS tomó el control
  root.classList.remove('_sb-pre');

  localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
}

// ── Accesibilidad: auto-asociar labels con sus inputs ──
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.field label:not([for])').forEach(function(label) {
    var field = label.closest('.field');
    if (!field) return;
    var inp = field.querySelector(
      'input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]), select, textarea'
    );
    if (!inp) return;
    if (!inp.id) inp.id = 'lbl_' + Math.random().toString(36).slice(2, 8);
    label.setAttribute('for', inp.id);
  });
});

// Restaurar preferencia al cargar
document.addEventListener('DOMContentLoaded', () => {
  if (localStorage.getItem('sidebar_collapsed') === '1' && window.innerWidth > 768) {
    // El <head> ya fijó --sidebar-current=56px (anti-FOUC).
    // toggleSidebarDesktop aplica las clases CSS y limpia _sb-pre.
    toggleSidebarDesktop();
  } else {
    // Asegurar que _sb-pre no quede activa si localStorage cambió
    document.documentElement.classList.remove('_sb-pre');
    document.documentElement.style.removeProperty('--sidebar-current');
  }
  // Restaurar secciones colapsadas
  ['mant', 'finanzas', 'sistema'].forEach(function(id) {
    if (localStorage.getItem('sidebar_sec_' + id) === '1') {
      var sec = document.getElementById('section-' + id);
      if (sec) sec.classList.add('collapsed');
    }
  });
});
document.querySelectorAll('.nav-item').forEach(el => {
  el.addEventListener('click', () => { if (window.innerWidth <= 768) closeSidebar(); });
});

// A11y: marcar la página actual para lectores de pantalla (no solo visualmente).
// Se invoca al cargar y tras cada navegación SPA (ver swap del router).
function _ggiSyncAriaCurrent() {
  document.querySelectorAll('.nav-item').forEach(function (el) {
    if (el.classList.contains('active')) el.setAttribute('aria-current', 'page');
    else el.removeAttribute('aria-current');
  });
}
_ggiSyncAriaCurrent();

// ── Secciones colapsables del sidebar ──
function toggleSidebarSection(id) {
  var sec = document.getElementById('section-' + id);
  if (!sec) return;
  var isCollapsed = sec.classList.toggle('collapsed');
  localStorage.setItem('sidebar_sec_' + id, isCollapsed ? '1' : '0');
}

// ── Toast (premium) ──────────────────────────────────────────────────────────
// showToast(msg, type, duration)
// type: 'ok' | 'error' | 'warning' | 'info' | 'loading'   (alias: 'success' → 'ok')
// msg:  string  OR  { title, msg }  for title + subtitle
// Returns the toast element (useful for loading → update pattern)
function showToast(msg, type, duration) {
  if (type === undefined)     type     = 'ok';
  if (duration === undefined) duration = 3500;
  if (type === 'success')     type     = 'ok';

  var container = document.getElementById('toast-container');
  if (!container) return null;

  var ICONS = {
    ok:      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    error:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    warning: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    info:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    loading: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="animation:_gt-spin .7s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>',
  };

  // Parse message: plain string or { title, msg/message, action:{label,onClick} }
  var titleText, subText = null, action = null;
  if (msg && typeof msg === 'object') {
    titleText = msg.title  || '';
    subText   = msg.msg    || msg.message || null;
    action    = (msg.action && msg.action.label && typeof msg.action.onClick === 'function') ? msg.action : null;
  } else {
    titleText = String(msg || '');
  }

  var el = document.createElement('div');
  el.className = 'ggi-toast t-' + type;
  // A11y: error/warning se anuncian con urgencia (alert/assertive); el resto, status/polite.
  el.setAttribute('role', (type === 'error' || type === 'warning') ? 'alert' : 'status');
  if (type === 'error' || type === 'warning') el.setAttribute('aria-live', 'assertive');

  var barHtml = (duration > 0 && type !== 'loading')
    ? '<div class="ggi-toast-bar" style="animation-duration:' + duration + 'ms"></div>'
    : '';

  el.innerHTML =
    '<div class="ggi-toast-ico">' + (ICONS[type] || ICONS.ok) + '</div>' +
    '<div class="ggi-toast-body">' +
      '<div class="ggi-toast-title">' + titleText + '</div>' +
      (subText ? '<div class="ggi-toast-sub">' + subText + '</div>' : '') +
    '</div>' +
    (action ? '<button class="ggi-toast-act" type="button">' + action.label + '</button>' : '') +
    '<button class="ggi-toast-x" aria-label="Cerrar" title="Cerrar">&#x2715;</button>' +
    barHtml;

  container.appendChild(el);

  if (action) {
    el.querySelector('.ggi-toast-act').addEventListener('click', function() {
      try { action.onClick(); } catch(e) {}
      _closeToast();
    });
  }

  // ── Close logic ──
  var _timer = null, _remaining = duration, _startedAt = null;

  function _closeToast() {
    if (_timer) { clearTimeout(_timer); _timer = null; }
    el.classList.add('hiding');
    el.addEventListener('animationend', function() { try { el.remove(); } catch(e){} }, { once: true });
  }

  el.querySelector('.ggi-toast-x').addEventListener('click', _closeToast);
  el._ggiClose = _closeToast;

  if (duration > 0 && type !== 'loading') {
    function _startTimer() {
      _startedAt = Date.now();
      _timer = setTimeout(_closeToast, _remaining);
    }
    el.addEventListener('mouseenter', function() {
      if (!_timer) return;
      clearTimeout(_timer); _timer = null;
      _remaining -= (Date.now() - _startedAt);
      var pb = el.querySelector('.ggi-toast-bar');
      if (pb) pb.style.animationPlayState = 'paused';
    });
    el.addEventListener('mouseleave', function() {
      var pb = el.querySelector('.ggi-toast-bar');
      if (pb) pb.style.animationPlayState = 'running';
      _startTimer();
    });
    _startTimer();
  }

  return el; // callers can call el._ggiClose() or replace for loading → done pattern
}

// Alias
window.ggiToast = showToast;



// ════════════════════════════════════════════════════════════
// MODAL HELPERS + MOBILE/PWA NAVIGATION
// ════════════════════════════════════════════════════════════

/* ── Estado interno del modal ── */
var _ggiModalOpen           = false;  // hay un modal abierto
var _ggiModalHistoryPushed  = false;  // pushState fue llamado para este modal
var _ggiModalDirty          = false;  // formulario tiene cambios sin guardar
var _ggiDirtyCallback       = null;   // función a ejecutar tras confirmar salida sucia
var _ggiIgnoreNextPopstate  = false;  // suprimir el next popstate (limpieza silenciosa)

/* ── Detección mobile (se re-evalúa en cada apertura) ── */
function _ggiIsMobile() { return window.innerWidth < 768; }

/* ── A11y de modal: gestión de foco (focus-management + focus trap + restore) ──
   - Al abrir: guarda el disparador y mueve el foco al modal.
   - Mientras abierto: si el foco se fuga fuera del modal (Tab/Shift+Tab), lo
     regresa al botón Cerrar → el foco queda atrapado en el diálogo (incluye el
     iframe embebido, que cuenta como un único punto de tabulación contenido).
   - Al cerrar: restaura el foco al disparador original. */
var _ggiModalTrigger = null;
function _ggiModalFocusIn(e) {
  if (!_ggiModalOpen) return;
  var modal = document.getElementById('mant-modal');
  if (modal && !modal.contains(e.target)) {
    var cb = modal.querySelector('.mant-modal-close');
    if (cb) cb.focus();
  }
}
function _ggiModalFocusActivate(focusClose) {
  _ggiModalTrigger = (document.activeElement && document.activeElement !== document.body)
    ? document.activeElement : _ggiModalTrigger;
  document.addEventListener('focusin', _ggiModalFocusIn);
  // Mover el foco dentro del diálogo. focusClose=true (modal iframe, sin input
  // accesible en el documento padre) enfoca el botón Cerrar; si no, el caller ya
  // movió el foco a su primer campo.
  if (focusClose) {
    setTimeout(function () {
      var cb = document.querySelector('#mant-modal .mant-modal-close');
      if (cb) cb.focus();
    }, 60);
  }
}
function _ggiModalFocusRestore() {
  document.removeEventListener('focusin', _ggiModalFocusIn);
  var t = _ggiModalTrigger;
  _ggiModalTrigger = null;
  if (t && typeof t.focus === 'function' && document.contains(t)) {
    try { t.focus(); } catch (_) {}
  }
}

/* ── A11y: Escape dentro del iframe embed → cerrar el diálogo ──
   Este layout_end.php también se incluye dentro del formulario embed (iframe).
   Cuando el foco está en el formulario, el keydown ocurre en el documento del
   iframe y el padre no puede capturarlo, así que Esc no cerraba. Aquí, solo en
   contexto iframe, reenviamos {ggi:'close'} al padre (que respeta el dirty-check),
   salvo que haya un dropdown/menú interno abierto (lo cierra su propio handler). */
if (window.self !== window.top) {
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.querySelector('.ss-drop[style*="block"], .estado-sel-menu:not([hidden]), .ts-dropdown.is-open, .ggi-row-menu-wrap.open')) return;
    try { window.parent.postMessage({ ggi: 'close' }, '*'); } catch (_) {}
  });
} else {
  // Documento PADRE: Escape cierra el mant-modal abierto. Cubre modales NO-iframe y el caso
  // en que el foco está en el padre (botón Cerrar) y no dentro del iframe embed → antes Esc
  // no hacía nada ("Escape no funciona en varios modales"). ggiModalClose restaura el scroll.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (!_ggiModalOpen) return;
    if (document.querySelector('.ss-drop[style*="block"], .estado-sel-menu:not([hidden]), .ts-dropdown.is-open, .ggi-row-menu-wrap.open, .ms-drop.is-open')) return;
    if (typeof ggiModalClose === 'function') ggiModalClose();
  });
}

/* ── mantOpen: modal pequeño (mantenimiento simple) ── */
function mantOpen(titulo, formHtml) {
  document.getElementById('mant-modal-title').textContent = titulo;
  document.getElementById('mant-modal-body').innerHTML = formHtml;
  document.getElementById('mant-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
  _ggiModalOpen  = true;
  _ggiModalDirty = false;
  if (_ggiIsMobile()) _ggiModalPushState(titulo);
  const first = document.querySelector('#mant-modal-body input:not([type=hidden]), #mant-modal-body select');
  if (first) setTimeout(function(){ first.focus(); }, 50);
  _ggiModalFocusActivate(!first); // trap + restore; enfoca Cerrar solo si no hay campo
}

/* ── mantClose: cierra sin preguntar (usado internamente) ── */
function mantClose() {
  document.getElementById('mant-modal').classList.remove('open');
  var _lgBox = document.querySelector('#mant-modal .mant-modal-box');
  if (_lgBox) _lgBox.classList.remove('mant-modal-lg');
  document.body.style.overflow = '';
  _ggiModalOpen  = false;
  _ggiModalDirty = false;
  _ggiModalHistoryPushed = false;
  // Limpieza del listener de postMessage del iframe embed (punto único de cierre).
  if (_ggiEmbedHandler) { window.removeEventListener('message', _ggiEmbedHandler); _ggiEmbedHandler = null; }
  _ggiModalFocusRestore(); // a11y: quitar trampa de foco y devolverlo al disparador
}

/* ── mantCloseOnBg: cierra al tocar el fondo ── */
function mantCloseOnBg(e) {
  if (e.target !== document.getElementById('mant-modal')) return;
  // En móvil los modales grandes (iframe, pantalla completa) NO cierran al tocar
  // fuera; los popups simples sí (tienen fondo oscurecido visible).
  if (_ggiIsMobile()) {
    var box = document.querySelector('#mant-modal .mant-modal-box');
    if (box && box.classList.contains('mant-modal-lg')) return;
  }
  ggiModalClose();
}

/* ════════════════════════════════════════════════════════════
   ggiModalClose — punto de entrada único para cerrar modal.
   Respeta dirty-check y el historial de navegación.
   ════════════════════════════════════════════════════════════ */
window.ggiModalClose = function(force) {
  if (!_ggiModalOpen) return;

  if (!force && _ggiModalDirty) {
    // Mostrar confirmación de cambios sin guardar
    _ggiDirtyCallback = function() { _ggiDoClose(); };
    document.getElementById('ggi-dirty-banner').classList.add('open');
    return;
  }
  _ggiDoClose();
};

function _ggiDoClose() {
  document.getElementById('ggi-dirty-banner').classList.remove('open');

  // Capturar el flag ANTES de mantClose(): mantClose() resetea
  // _ggiModalHistoryPushed=false, así que leerlo después siempre daría false y
  // la entrada ficticia del historial nunca se limpiaría (quedaba colgada en
  // móvil → navegación/scroll inconsistentes al cerrar con Escape o ←Volver).
  var _hadHistory = _ggiModalHistoryPushed;

  // ── CORRECTO: cerrar el modal PRIMERO (sincrónico, sin flash) ──────────────
  // Luego limpiar el historial silenciosamente si se había hecho pushState.
  // El orden anterior era el inverso (history.back → popstate → mantClose),
  // lo que producía una página en blanco durante la transición.
  mantClose(); // cierre inmediato — el usuario ve el listado al instante

  if (_hadHistory) {
    // Limpiar la entrada ficticia del historial sin efecto visual.
    // _ggiIgnoreNextPopstate evita que el SPA router o nuestro handler reaccionen.
    _ggiIgnoreNextPopstate = true;
    history.back();
  }
}

/* ── Dirty check: banner de confirmación ── */
window.ggiDirtyCancel = function() {
  document.getElementById('ggi-dirty-banner').classList.remove('open');
  _ggiDirtyCallback = null;
};
window.ggiDirtyConfirm = function() {
  document.getElementById('ggi-dirty-banner').classList.remove('open');
  _ggiModalDirty = false;
  if (_ggiDirtyCallback) { _ggiDirtyCallback(); _ggiDirtyCallback = null; }
};

/* ── pushState para capturar el botón Atrás del dispositivo ── */
function _ggiModalPushState(titulo) {
  if (_ggiModalHistoryPushed) return;
  try {
    history.pushState({ ggiModal: true, titulo: titulo || '' }, '', location.href);
    _ggiModalHistoryPushed = true;
  } catch(e) {}
}

/* ── popstate: gestión de historial del modal ──────────────────────────────
   Fase CAPTURA → se ejecuta antes que el SPA router.
   Tres casos posibles:

   A) _ggiIgnoreNextPopstate = true
      → Nosotros llamamos history.back() para limpiar la entrada ficticia.
        El modal ya está cerrado. Sólo suprimir el evento, no navegar.

   B) _ggiModalOpen = true (botón físico Atrás del dispositivo)
      → El usuario presionó el back button nativo con el modal abierto.
        Cerramos el modal; el navegador ya revirtió la URL (misma URL).
        No necesitamos hacer history.back() adicional.

   C) Ninguno de los anteriores
      → Navegación SPA normal; no intervenir.
   ─────────────────────────────────────────────────────────────────────── */
window.addEventListener('popstate', function(ev) {
  if (_ggiIgnoreNextPopstate) {
    // Caso A: limpieza silenciosa después del cierre por botón ← Volver
    ev.stopImmediatePropagation();
    _ggiIgnoreNextPopstate = false;
    return;
  }
  if (_ggiModalOpen) {
    // Caso B: botón físico Atrás con modal abierto
    ev.stopImmediatePropagation();
    _ggiModalHistoryPushed = false; // el historial ya retrocedió
    mantClose();
    return;
  }
  // Caso C: navegación normal — dejar que el SPA router maneje
}, true);

// ── ggiConfirm — Modal de confirmación premium ────────────────────────────
// Uso: const ok = await ggiConfirm('Título', 'Mensaje', { type:'danger', confirmText:'Eliminar' });
// type: 'danger' | 'warning' | 'info' | 'success'   (default: 'danger')
window.ggiConfirm = (function() {
  // Inyectar estilos una sola vez
  function _ensureStyles() {
    if (document.getElementById('_gci-css')) return;
    var s = document.createElement('style');
    s.id = '_gci-css';
    s.textContent =
      '#_gci-ov{position:fixed;inset:0;z-index:199999;display:flex;align-items:center;justify-content:center;padding:20px;' +
        'background:rgba(0,0,0,.48);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);' +
        'animation:_gci-fo .18s ease forwards;}' +
      '#_gci-ov.closing{animation:_gci-fo .16s ease reverse forwards;}' +
      '._gci-box{background:var(--bg-surface,#fff);border:1px solid var(--brd,#e2e8f0);border-radius:20px;' +
        'box-shadow:0 32px 80px rgba(0,0,0,.22),0 8px 24px rgba(0,0,0,.1);' +
        'max-width:396px;width:100%;padding:28px 26px 22px;' +
        'animation:_gci-si .24s cubic-bezier(.16,1,.3,1);' +
        'font-family:"Inter",sans-serif;}' +
      '._gci-hd{display:flex;align-items:flex-start;gap:16px;margin-bottom:22px;}' +
      '._gci-ic{flex-shrink:0;width:44px;height:44px;border-radius:13px;' +
        'display:flex;align-items:center;justify-content:center;}' +
      '._gci-ic.d{background:var(--s-err-bg,#fee2e2);color:var(--s-err,#dc2626);}' +
      '._gci-ic.w{background:var(--s-warn-bg,#fef3c7);color:var(--s-warn,#d97706);}' +
      '._gci-ic.i{background:var(--s-info-bg,#f0f9ff);color:var(--s-info,#0369a1);}' +
      '._gci-ic.s{background:var(--s-ok-bg,#dcfce7);color:var(--s-ok,#059669);}' +
      '._gci-ti{font-size:15.5px;font-weight:750;color:var(--tx,#0f172a);line-height:1.3;letter-spacing:-.02em;margin-bottom:5px;}' +
      '._gci-ms{font-size:13px;color:var(--tx2,#64748b);line-height:1.6;}' +
      '._gci-ft{display:flex;gap:9px;justify-content:flex-end;margin-top:4px;}' +
      '._gci-cn{padding:9px 20px;border-radius:10px;border:1.5px solid var(--brd,#e2e8f0);' +
        'background:var(--bg-surface,#fff);color:var(--tx,#0f172a);' +
        'font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;' +
        'transition:background .14s,border-color .14s;}' +
      '._gci-cn:hover{background:var(--bg-hover,#f1f5f9);border-color:var(--tx3,#94a3b8);}' +
      '._gci-ok{padding:9px 22px;border-radius:10px;border:none;' +
        'font-size:13px;font-weight:700;cursor:pointer;color:#fff;font-family:inherit;' +
        'transition:opacity .14s,transform .1s;letter-spacing:-.01em;}' +
      '._gci-ok:hover{opacity:.88;}._gci-ok:active{transform:scale(.97);}' +
      '._gci-ok.d{background:linear-gradient(135deg,#f87171,#dc2626);}' +
      '._gci-ok.w{background:linear-gradient(135deg,#fbbf24,#d97706);}' +
      '._gci-ok.i{background:linear-gradient(135deg,#60a5fa,#2563eb);}' +
      '._gci-ok.s{background:linear-gradient(135deg,#34d399,#059669);}' +
      '@keyframes _gci-fo{from{opacity:0}to{opacity:1}}' +
      '@keyframes _gci-si{from{opacity:0;transform:scale(.93) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}' +
      /* Táctil: targets ≥44px y más separación entre Cancelar y la acción
         destructiva (Apple HIG / Material) para evitar mis-taps. */
      '@media (hover:none){' +
        '._gci-cn,._gci-ok{min-height:44px;padding-top:11px;padding-bottom:11px;}' +
        '._gci-ft{gap:14px;}' +
      '}';
    document.head.appendChild(s);
  }

  var ICONS = {
    d: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    w: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    i: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    s: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
  };

  return function(title, message, opts) {
    opts = opts || {};
    var rawType     = opts.type        || 'danger';
    var confirmText = opts.confirmText || 'Confirmar';
    var cancelText  = opts.cancelText  || 'Cancelar';

    // Normalize type to single-char key
    var k = { danger:'d', warning:'w', info:'i', success:'s' }[rawType] || 'd';

    _ensureStyles();

    return new Promise(function(resolve) {
      var prev = document.getElementById('_gci-ov');
      if (prev) prev.remove();

      var ov = document.createElement('div');
      ov.id = '_gci-ov';

      ov.innerHTML =
        '<div class="_gci-box">' +
          '<div class="_gci-hd">' +
            '<div class="_gci-ic ' + k + '">' + (ICONS[k]) + '</div>' +
            '<div style="flex:1;min-width:0;">' +
              '<div class="_gci-ti">' + (title   || 'Confirmar acción') + '</div>' +
              '<div class="_gci-ms">' + (message || '') + '</div>' +
            '</div>' +
          '</div>' +
          '<div class="_gci-ft">' +
            '<button class="_gci-cn" id="_gci-cancel">' + cancelText  + '</button>' +
            '<button class="_gci-ok ' + k + '" id="_gci-ok">' + confirmText + '</button>' +
          '</div>' +
        '</div>';

      document.body.appendChild(ov);
      document.getElementById('_gci-ok').focus();

      function done(val) {
        ov.classList.add('closing');
        var box = ov.querySelector('._gci-box');
        if (box) { box.style.transition = 'opacity .14s,transform .14s'; box.style.opacity = '0'; box.style.transform = 'scale(.96) translateY(4px)'; }
        setTimeout(function() { ov.remove(); resolve(val); }, 180);
      }

      document.getElementById('_gci-ok').addEventListener('click',     function() { done(true);  });
      document.getElementById('_gci-cancel').addEventListener('click', function() { done(false); });
      ov.addEventListener('click', function(e) { if (e.target === ov) done(false); });

      // Keyboard: Escape = cancel, Enter on OK button = confirm
      var _kh = function(e) {
        if (e.key === 'Escape') { document.removeEventListener('keydown', _kh); done(false); }
        if (e.key === 'Enter' && document.activeElement === document.getElementById('_gci-ok')) {
          document.removeEventListener('keydown', _kh); done(true);
        }
      };
      document.addEventListener('keydown', _kh);
    });
  };
}());

// ── Modal de formulario embed (iframe + postMessage) ──────────────
// Definido en scope global para que el SPA no rompa la referencia.
var _ggiEmbedHandler = null;

function ggiOpenForm(url, titulo, onSuccess) {
  var modal   = document.getElementById('mant-modal');
  var box     = document.querySelector('#mant-modal .mant-modal-box');
  var body    = document.getElementById('mant-modal-body');
  var titleEl = document.getElementById('mant-modal-title');
  if (!modal || !box || !body) return;

  // Limpiar listener anterior si quedó pendiente
  if (_ggiEmbedHandler) {
    window.removeEventListener('message', _ggiEmbedHandler);
    _ggiEmbedHandler = null;
  }

  // Resetear estado
  _ggiModalDirty = false;
  _ggiModalHistoryPushed = false;

  // Expandir modal al modo formulario
  box.classList.add('mant-modal-lg');
  titleEl.textContent = titulo || '';

  // Montar spinner + iframe en el body del modal.
  body.style.position = 'relative';
  body.innerHTML =
    '<div id="ggi-spin" style="position:absolute;inset:0;display:flex;align-items:center;'
    + 'justify-content:center;gap:8px;font-size:13px;color:var(--gris-texto,#64748b);'
    + 'background:var(--bg-surface,#fff);z-index:2;border-radius:0 0 14px 14px;">'
    + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
    + ' style="animation:ggiSpin .8s linear infinite;flex-shrink:0">'
    + '<path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83'
    + 'M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>'
    + 'Cargando…</div>'
    + '<iframe id="ggi-embed-frame" class="ggi-embed-frame" allowfullscreen'
    + ' style="opacity:0;transition:opacity .25s"></iframe>';

  // Abrir modal
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
  _ggiModalOpen = true;

  // ── MOBILE: push history state para capturar botón Atrás ──
  if (_ggiIsMobile()) {
    _ggiModalPushState(titulo);
  }

  // A11y: guardar disparador, atrapar foco y moverlo al botón Cerrar (el form
  // vive en el iframe, sin punto de foco accesible en el documento padre).
  _ggiModalFocusActivate(true);

  // Asignar src DESPUÉS de que el iframe está en el DOM
  var frame = document.getElementById('ggi-embed-frame');
  var sep   = url.indexOf('?') === -1 ? '?' : '&';
  frame.src = url + sep + 'embed=1';

  // Ocultar spinner cuando el iframe termine de cargar
  frame.addEventListener('load', function onFrameLoad() {
    var sp = document.getElementById('ggi-spin');
    if (sp) sp.remove();
    frame.style.opacity = '1';
    frame.removeEventListener('load', onFrameLoad);
  });

  // Escuchar postMessages desde el iframe
  _ggiEmbedHandler = function (ev) {
    if (!ev.data || typeof ev.data !== 'object') return;

    // ── Formulario tiene cambios sin guardar ──
    if (ev.data.ggi === 'dirty') {
      _ggiModalDirty = !!ev.data.dirty;
      return;
    }

    // ── Usuario pidió cerrar desde dentro del iframe ──
    // NO removemos el listener aquí: si el form está "dirty", ggiModalClose muestra
    // el banner de confirmación y el modal sigue abierto; el listener debe seguir vivo
    // para recibir un guardado/cierre posterior. mantClose lo limpia al cerrar de verdad.
    if (ev.data.ggi === 'close') {
      ggiModalClose();
      return;
    }

    // ── Formulario guardado con éxito ──
    if (ev.data.ggi !== 'created' && ev.data.ggi !== 'updated') return;
    window.removeEventListener('message', _ggiEmbedHandler);
    _ggiEmbedHandler = null;
    _ggiModalDirty = false;          // limpiado: no hay cambios pendientes
    mantClose();

    if (typeof onSuccess === 'function') {
      onSuccess(ev.data);
      return;
    }

    // Navegar al listado destacando la fila recién creada
    var destBase = window.location.pathname + window.location.search;
    try {
      var destUrl = new URL(destBase, window.location.origin);
      if (ev.data.id)  destUrl.searchParams.set('nuevo', ev.data.id);
      if (ev.data.num) destUrl.searchParams.set('num',   ev.data.num || ev.data.numero || '');
      destBase = destUrl.pathname + (destUrl.search || '');
    } catch(_) {}

    if (typeof window.ggiNavigate === 'function') {
      window.ggiNavigate(destBase);
    } else {
      window.location.href = destBase;
    }
  };
  window.addEventListener('message', _ggiEmbedHandler);
}

// ── Motor de mantenimiento ──
function renderMantenimiento(titulo, campos, filas, columnas, editId, msg) {
  const root = document.getElementById('mant-root');
  if (!root) return;

  window.__mant = { titulo, campos, editId, tabla: (window.__mantData && window.__mantData.tabla) || '' };

  function buildForm(isEdit, rowData) {
    const t = isEdit ? titulo : titulo.replace(/^Editar\s+/, 'Nuevo ');
    const fc = campos.map(c => {
      const val = isEdit ? (rowData ? (rowData[c.name] || '') : (c.value || '')) : '';
      const req = c.required ? ' <span style="color:var(--red)">*</span>' : '';

      if (c.type === 'textarea') {
        return `<div class="field"><label>${c.label}${req}</label><textarea name="${c.name}" placeholder="${c.placeholder || ''}">${val}</textarea></div>`;
      }
      if (c.type === 'select') {
        const opts = c.options.map(o => `<option value="${o.value}"${val == o.value ? ' selected' : ''}>${o.label}</option>`).join('');
        return `<div class="field">
          <label>${c.label}${req}</label>
          <div style="position:relative">
            <select name="${c.name}" style="-webkit-appearance:none;appearance:none;padding-right:32px;width:100%;padding:9px 32px 9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;color:var(--gris-dark);background:#fafbfe;outline:none;background-image:url('data:image/svg+xml,%3Csvg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'12\\' height=\\'12\\' viewBox=\\'0 0 24 24\\' fill=\\'none\\' stroke=\\'%236b7a99\\' stroke-width=\\'2.5\\'%3E%3Cpolyline points=\\'6 9 12 15 18 9\\'/%3E%3C/svg%3E');background-repeat:no-repeat;background-position:right 10px center;">
              ${opts}
            </select>
          </div>
        </div>`;
      }
      if (c.type === 'file') {
        // Preview: use c.preview (static) or derive from rowData value + previewBaseUrl (modal edit)
        let previewUrl = c.preview || null;
        if (!previewUrl && val && c.previewBaseUrl) previewUrl = c.previewBaseUrl + val;
        const previewHtml = previewUrl
          ? `<div style="margin-bottom:8px"><img id="mant-file-prev-${c.name}" src="${previewUrl}" alt="Foto actual" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--gris-borde);display:block"></div>`
          : `<div style="margin-bottom:8px"><img id="mant-file-prev-${c.name}" src="" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--gris-borde);display:none"></div>`;
        return `<div class="field">
          <label>${c.label}${req}</label>
          ${previewHtml}
          <input type="file" name="${c.name}" accept="${c.accept || 'image/*'}" style="padding:6px 10px;font-size:12px"
            onchange="(function(e){var f=e.target.files[0];if(!f)return;var img=document.getElementById('mant-file-prev-${c.name}');img.src=URL.createObjectURL(f);img.style.display='block';})(event)">
          ${c.hint ? `<span style="font-size:11px;color:var(--gris-texto)">${c.hint}</span>` : ''}
        </div>`;
      }
      // Campo contraseña con generador automático
      if (c.inputType === 'password') {
        const opcional = !c.required ? ' <span style="font-size:10px;color:var(--gris-texto);font-weight:400;">(opcional)</span>' : '';
        return `<div class="field">
          <label>${c.label}${req}${opcional}</label>
          <div style="display:flex;gap:6px;align-items:center;">
            <div style="position:relative;flex:1;">
              <input type="password" name="${c.name}" id="mant-pass-input" value="${val}" placeholder="${c.placeholder || ''}"
                style="width:100%;padding:9px 36px 9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;color:var(--gris-dark);background:#fafbfe;outline:none;">
              <button type="button" onclick="toggleMantPass()" title="Ver contraseña"
                style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gris-texto);display:flex;align-items:center;padding:2px;">
                <svg id="mant-eye-ico" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <button type="button" onclick="generarPass()" title="Generar contraseña segura"
              style="flex-shrink:0;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;background:#fff;cursor:pointer;font-size:11px;font-weight:600;color:var(--azul);white-space:nowrap;font-family:'Inter',sans-serif;display:flex;align-items:center;gap:5px;">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              Generar
            </button>
          </div>
          <div id="mant-pass-hint" style="font-size:11px;color:var(--gris-texto);margin-top:4px;"></div>
        </div>`;
      }
      // Selector mes+día (sin año)
      if (c.inputType === 'month-day') {
        let _m = 0, _d = 0;
        if (val) {
          const pts = val.split('-');
          if (pts.length === 3) { _m = parseInt(pts[1], 10); _d = parseInt(pts[2], 10); }
          else if (pts.length === 2) { _m = parseInt(pts[0], 10); _d = parseInt(pts[1], 10); }
        }
        const _meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        const _mOpts = '<option value="">— Mes —</option>' + _meses.map((m, i) => {
          const v = String(i + 1).padStart(2, '0');
          return `<option value="${v}"${_m === i + 1 ? ' selected' : ''}>${m}</option>`;
        }).join('');
        const _dOpts = '<option value="">— Día —</option>' + Array.from({length: 31}, (_, i) => {
          const v = String(i + 1).padStart(2, '0');
          return `<option value="${v}"${_d === i + 1 ? ' selected' : ''}>${i + 1}</option>`;
        }).join('');
        const _hidVal = (_m > 0 && _d > 0) ? '2000-' + String(_m).padStart(2,'0') + '-' + String(_d).padStart(2,'0') : '';
        const _hint = c.hint ? `<span style="font-size:11px;color:var(--gris-texto)">${c.hint}</span>` : '';
        const _selStyle = 'flex:1;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:\'Inter\',sans-serif;color:var(--gris-dark);background:#fafbfe;outline:none;';
        return `<div class="field">
          <label>${c.label}${req}</label>
          <input type="hidden" name="${c.name}" id="mant-md-${c.name}" value="${_hidVal}">
          <div style="display:flex;gap:8px;">
            <select id="mant-md-mes-${c.name}" onchange="mantMdUpdate('${c.name}')" style="${_selStyle}">${_mOpts}</select>
            <select id="mant-md-dia-${c.name}" onchange="mantMdUpdate('${c.name}')" style="${_selStyle}max-width:90px;">${_dOpts}</select>
          </div>
          ${_hint}
        </div>`;
      }
      return `<div class="field"><label>${c.label}${req}</label><input type="${c.inputType || 'text'}" name="${c.name}" value="${val}" placeholder="${c.placeholder || ''}"></div>`;
    }).join('');

    const rowId = isEdit ? (rowData ? rowData.id : editId) : '';
    return { titulo: t, html: `
      <form onsubmit="mantAjaxSubmit(event, '${window.location.pathname}', ${rowId || 0})">
        <input type="hidden" name="_csrf"  value="${_csrfToken()}">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id"     value="${rowId}">
        ${fc}
        <div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary btn-sm" id="mant-save-btn">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            ${isEdit ? 'Actualizar' : 'Guardar'}
          </button>
          <button type="button" onclick="mantClose()" class="btn btn-outline btn-sm">Cancelar</button>
        </div>
      </form>` };
  }

  window.__mantBuildForm = buildForm;

  const __mantIconos = {
    normas_iso:          `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>`,
    casas_certificadoras:`<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
    agencias_envio:      `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>`,
    estados_envio:       `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>`,
    usuarios:            `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
  };
  const __mantTabla = (window.__mantData && window.__mantData.tabla) || '';
  const __mantIcono = __mantIconos[__mantTabla] || `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>`;

  const theads = columnas.map(c => `<th>${c}</th>`).join('') + '<th>Estado</th><th></th>';
  const tbody = filas.length === 0
    ? `<tr><td colspan="${columnas.length + 2}" class="empty-state">Sin registros aún.</td></tr>`
    : filas.map(f => {
        const tds = f.cols.map((c, i) => {
          if (i === 0) {
            return `<td><div style="display:flex;align-items:flex-start;gap:8px;"><span class="mobile-icon mant-row-icon">${__mantIcono}</span><span class="mant-first-col">${c || '—'}</span></div></td>`;
          }
          return `<td data-label="${columnas[i] || ''}">${c || '—'}</td>`;
        }).join('');
        const isActivo = f.activo != 0;
        const badge = isActivo ? `<span class="badge-activo">Activo</span>` : `<span class="badge-inactivo">Inactivo</span>`;
        return `<tr data-activo="${isActivo ? '1' : '0'}" data-mant-id="${f.id}" style="${isActivo ? '' : 'opacity:.6;background:var(--gris-bg,#f8f9fd);'}">
          ${tds}<td data-label="Estado">${badge}</td>
          <td style="white-space:nowrap">
            <div style="display:flex;gap:4px;">
              <button type="button" onclick="mantEditRow(${f.id})" class="btn-ic btn-ic-blue" title="Editar">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button type="button" onclick="mantToggleRow(${f.id}, this)" class="btn-ic ${isActivo ? 'btn-ic-amber' : 'btn-ic-green'}" title="${isActivo ? 'Desactivar' : 'Activar'}">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
              </button>
              <button type="button" onclick="mantDeleteRow(${f.id})" class="btn-ic btn-ic-red" title="Eliminar">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
              </button>
            </div>
          </td>
        </tr>`;
      }).join('');

  root.innerHTML = `
    <div class="mant-table-card">
      <div class="mant-card-header">
        <div class="mant-card-title">Registros (${filas.length})</div>
        <button type="button" onclick="mantNuevo()" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nuevo
        </button>
      </div>
      <div style="overflow-x:auto">
        <table class="mant-table">
          <thead><tr>${theads}</tr></thead>
          <tbody>${tbody}</tbody>
        </table>
      </div>
    </div>`;
}

function mantNuevo() {
  if (!window.__mantBuildForm) {
    if (window.__mantData && typeof renderMantenimiento === 'function') {
      var _d = window.__mantData;
      renderMantenimiento(_d.titulo, _d.campos, _d.filas, _d.columnas, _d.editId, _d.msg);
      if (typeof window.__mantPostRender === 'function') window.__mantPostRender();
    }
    if (!window.__mantBuildForm) return;
  }
  const { titulo, html } = window.__mantBuildForm(false, null);
  mantOpen(titulo, html);
}

async function mantEditRow(id) {
  if (!window.__mant) {
    if (window.__mantData && typeof renderMantenimiento === 'function') {
      var _d = window.__mantData;
      renderMantenimiento(_d.titulo, _d.campos, _d.filas, _d.columnas, _d.editId, _d.msg);
      if (typeof window.__mantPostRender === 'function') window.__mantPostRender();
    }
    if (!window.__mant) return;
  }
  try {
    const res  = await fetch(`${window.APP_URL}/modules/mantenimiento/ajax_get.php?tabla=${window.__mant.tabla}&id=${id}`);
    const json = await res.json();
    if (!json.ok) { showToast('Error al cargar datos.', 'error'); return; }
    const { titulo, html } = window.__mantBuildForm(true, json.data);
    mantOpen(titulo, html);
  } catch(e) {
    showToast('Error de conexión.', 'error');
  }
}


// ── Helpers modal de mantenimiento ──
function generarPass() {
  const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%';
  let pass = '';
  for (let i = 0; i < 12; i++) pass += chars[Math.floor(Math.random() * chars.length)];
  const inp = document.getElementById('mant-pass-input');
  if (!inp) return;
  inp.value = pass;
  inp.type  = 'text'; // mostrar para que se vea
  const hint = document.getElementById('mant-pass-hint');
  if (hint) {
    hint.textContent = '✓ Contraseña generada: ' + pass + ' — cópiala antes de guardar';
    hint.style.color = 'var(--verde, #2DB140)';
  }
  // Cambiar ícono del ojo a "ocultar"
  const ico = document.getElementById('mant-eye-ico');
  if (ico) ico.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
}

function mantMdUpdate(name) {
  const m = document.getElementById('mant-md-mes-' + name);
  const d = document.getElementById('mant-md-dia-' + name);
  const h = document.getElementById('mant-md-' + name);
  if (!h) return;
  h.value = (m && d && m.value && d.value) ? '2000-' + m.value + '-' + d.value : '';
}

function toggleMantPass() {
  const inp = document.getElementById('mant-pass-input');
  const ico = document.getElementById('mant-eye-ico');
  if (!inp) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  if (ico) ico.innerHTML = show
    ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}

async function mantAjaxSubmit(e, url, rowId) {
  e.preventDefault();
  const form = e.target;

  // Validar contraseña si el campo existe
  const passInput = form.querySelector('[name="password"]');
  if (passInput) {
    const passVal  = passInput.value.trim();
    const isNew    = !rowId || rowId === 0;
    let passErrDiv = form.querySelector('.mant-pass-err');
    if (!passErrDiv) {
      passErrDiv = document.createElement('div');
      passErrDiv.className = 'mant-pass-err';
      passErrDiv.style.cssText = 'font-size:11px;color:var(--red,#ef4444);margin-top:3px;';
      passInput.closest('.field').appendChild(passErrDiv);
    }
    passErrDiv.textContent = '';
    passInput.style.borderColor = '';
    if (isNew && passVal.length === 0) {
      passErrDiv.textContent = 'La contraseña es requerida para nuevo usuario.';
      passInput.style.borderColor = 'var(--red,#ef4444)';
      passInput.focus();
      return;
    }
    if (passVal.length > 0 && passVal.length < 8) {
      passErrDiv.textContent = 'Mínimo 8 caracteres.';
      passInput.style.borderColor = 'var(--red,#ef4444)';
      passInput.focus();
      return;
    }
  }

  const btn  = form.querySelector('#mant-save-btn');
  const btnOrigText = rowId > 0 ? 'Actualizar' : 'Guardar';
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  // Actualizar el _csrf del form con el token más reciente antes de enviar
  const csrfInput = form.querySelector('[name="_csrf"]');
  if (csrfInput) csrfInput.value = _csrfToken();

  try {
    const formData = new FormData(form);
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    });
    const json = await res.json();

    // Actualizar CSRF si el servidor devuelve token renovado
    if (json.csrf) _csrfUpdate(json.csrf);

    if (json.ok) {
      mantClose();
      showToast(json.msg || 'Guardado exitosamente.', 'ok');
      // Refrescar tabla via AJAX sin recargar la página completa
      _mantRefreshTable();
    } else {
      showToast(json.msg || 'Error al guardar.', 'error');
      btn.disabled = false;
      btn.textContent = btnOrigText;
    }
  } catch(err) {
    showToast('Error de conexión.', 'error');
    btn.disabled = false;
    btn.textContent = btnOrigText;
  }
}

// ── Inicialización del motor de mantenimiento ──
// Se llama tanto en carga directa como en navegación SPA (navigate() lo invoca
// explícitamente). El DOMContentLoaded es seguridad adicional para casos donde
// el script se evaluó antes de que el DOM estuviera listo.
function _initMantUI() {
  if (!window.__mantData) return;
  var d = window.__mantData;
  if (!Array.isArray(d.campos) || !Array.isArray(d.filas)) return; // datos incompletos
  try {
    renderMantenimiento(d.titulo, d.campos, d.filas, d.columnas, d.editId, d.msg);
    if (typeof window.__mantPostRender === 'function') window.__mantPostRender();
  } catch(e) {
    console.error('[GGI] renderMantenimiento error:', e);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _initMantUI);
} else {
  _initMantUI();
}

// ── Toggle activo/inactivo vía AJAX (sin recargar página) ──
async function mantToggleRow(id, btn) {
  const tr      = btn.closest('tr[data-mant-id]');
  const esActivo = tr && tr.dataset.activo === '1';
  const accion   = esActivo ? 'Desactivar' : 'Activar';
  const _confirmed1 = await ggiConfirm(accion + ' registro', '¿' + accion + ' este registro?', { type: esActivo ? 'warning' : 'info', confirmText: accion });
  if (!_confirmed1) return;

  try {
    // silent: los avisos de esta función son propios (incluye "_noaction")
    const json = await ggiPost(window.location.pathname, { accion: 'toggle', id: id }, { silent: true });
    // Si hay un mensaje de aviso (incluyendo "no puedes desactivarte"), mostrarlo y salir
    if (json.msg && (!json.ok || json._noaction)) { showToast(json.msg, json.ok ? 'ok' : 'error'); return; }

    // Actualizar fila sin recarga completa
    const nuevoActivo = !esActivo;
    if (tr) {
      tr.dataset.activo    = nuevoActivo ? '1' : '0';
      tr.style.opacity     = nuevoActivo ? ''  : '0.6';
      tr.style.background  = nuevoActivo ? ''  : 'var(--gris-bg,#f8f9fd)';
      const badgeTd = tr.querySelector('[data-label="Estado"]');
      if (badgeTd) badgeTd.innerHTML = nuevoActivo
        ? '<span class="badge-activo">Activo</span>'
        : '<span class="badge-inactivo">Inactivo</span>';
      btn.className = 'btn-ic ' + (nuevoActivo ? 'btn-ic-amber' : 'btn-ic-green');
      btn.title     = nuevoActivo ? 'Desactivar' : 'Activar';
    }
    // Actualizar datos en memoria y contadores del filtro
    if (window.__mantData && window.__mantData.filas) {
      const f = window.__mantData.filas.find(function(x){ return x.id == id; });
      if (f) f.activo = nuevoActivo ? 1 : 0;
    }
    _usrActualizarContadores();
    showToast(nuevoActivo ? 'Activado correctamente.' : 'Desactivado correctamente.', 'ok');
  } catch(e) {
    showToast('Error de conexión.', 'error');
  }
}

// ── Eliminar registro vía AJAX ──
async function mantDeleteRow(id) {
  const _confirmed2 = await ggiConfirm('Eliminar registro', '¿Eliminar este registro? Esta acción no se puede deshacer.', { type: 'danger', confirmText: 'Eliminar' });
  if (!_confirmed2) return;
  try {
    // silent: esta función muestra sus propios mensajes de éxito y error
    const json = await ggiPost(window.location.pathname, { accion: 'eliminar', id: id }, { silent: true });
    if (json.ok || json.ok === undefined) {
      const tr = document.querySelector('tr[data-mant-id="' + id + '"]');
      if (tr) tr.remove();
      if (window.__mantData && window.__mantData.filas)
        window.__mantData.filas = window.__mantData.filas.filter(function(x){ return x.id != id; });
      _usrActualizarContadores();
      showToast(json.msg || 'Registro eliminado.', 'ok');
    } else {
      showToast(json.msg || 'Error al eliminar.', 'error');
    }
  } catch(e) {
    showToast('Error de conexión.', 'error');
  }
}

// ── Actualiza los contadores del filtro de usuarios ──
function _usrActualizarContadores() {
  var filas = (window.__mantData && window.__mantData.filas) || [];
  var total    = filas.length;
  var activos  = filas.filter(function(f){ return f.activo == 1; }).length;
  var inactivos= filas.filter(function(f){ return f.activo != 1; }).length;
  var bar = document.getElementById('usr-filter-bar');
  if (!bar) return;
  var btns = bar.querySelectorAll('[data-usr-filtro]');
  var counts = [total, activos, inactivos];
  btns.forEach(function(b, i) {
    var span = b.querySelector('span');
    if (span) span.textContent = counts[i];
  });
  // Actualizar contador del card header
  var titulo = document.querySelector('#mant-root .mant-card-title');
  if (titulo) titulo.textContent = 'Registros (' + total + ')';
}

// ── Refrescar tabla de mantenimiento vía AJAX (sin recargar página) ──
async function _mantRefreshTable() {
  try {
    const r = await fetch(window.location.href, { credentials: 'same-origin' });
    if (!r.ok) return;
    const html = await r.text();
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    // Ejecutar los scripts inline del nuevo <main> para actualizar __mantData y hooks
    const newMain = doc.querySelector('.main');
    if (!newMain) return;
    const inlineScripts = Array.from(newMain.querySelectorAll('script:not([src])'));
    inlineScripts.forEach(function(s) {
      try { eval(s.textContent); } catch(ex) {}
    });
    // Re-renderizar tabla con los datos frescos
    if (window.__mantData && window.__mantData.editId !== undefined) {
      window.__mantData.editId = 0; // reset edit mode tras guardar
    }
    if (typeof _initMantUI === 'function') _initMantUI();
  } catch(e) {
    // Si falla el fetch, el toast ya informó el éxito — no hacer nada más
  }
}

// ── Auto-mejorar botones "← Volver" ──
(function() {
  function enhanceVolverButtons() {
    document.querySelectorAll('a.btn-outline, a.btn, a.back-link').forEach(function(el) {
      if (el.classList.contains('btn-back')) return;
      var txt = el.textContent.trim();
      if (txt.indexOf('←') === -1) return;
      el.classList.add('btn-back');
      el.innerHTML = el.innerHTML.replace(/←\s*/g,
        '<svg class="btn-back-icon" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>'
      );
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceVolverButtons);
  } else {
    enhanceVolverButtons();
  }
})();

// ── Modal upload de documentos (popup) ──
function abrirSubirDoc(tipo, label, version) {
  var esAct = version > 0;
  var titulo = (esAct ? 'Actualizar: ' : 'Subir: ') + label;
  var vInfo = esAct
    ? '<div class="modal-version-info"><svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Se guardará como versión v' + (version + 1) + '. La versión anterior queda en el historial.</div>'
    : '';
  mantOpen(titulo,
    vInfo +
    '<form id="modal-doc-form">' +
    '<input type="hidden" name="_csrf" value="' + _csrfToken() + '">' +
    '<input type="hidden" name="cliente_id" value="' + (window.__doc_cliente_id || 0) + '">' +
    '<input type="hidden" name="tipo" value="' + tipo + '">' +
    '<div class="modal-dz" id="modal-dz">' +
      '<svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.45;margin:0 auto 10px;display:block;pointer-events:none;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
      '<div style="font-size:13px;font-weight:600;color:var(--gris-dark);margin-bottom:3px;pointer-events:none;">Arrastra el archivo aquí</div>' +
      '<div style="font-size:11px;color:var(--gris-texto);margin-bottom:12px;pointer-events:none;">PDF, JPG, PNG, WEBP — máx. 10 MB</div>' +
      '<label for="modal-file-inp" class="btn btn-outline btn-sm" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">' +
        '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="pointer-events:none;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
        'Seleccionar archivo' +
      '</label>' +
    '</div>' +
    '<input type="file" id="modal-file-inp" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none" onchange="modalDocPreview(this)">' +
    '<div class="modal-fp" id="modal-fp">' +
      '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
      '<span class="modal-fp-name" id="modal-fp-name"></span>' +
      '<button type="button" onclick="modalDocRemove()" style="background:none;border:none;cursor:pointer;color:var(--gris-texto);padding:2px 6px;font-size:15px;line-height:1;flex-shrink:0;" title="Quitar">✕</button>' +
    '</div>' +
    '<div id="modal-upload-prog" style="display:none;margin-top:12px;">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">' +
        '<span style="font-size:11px;color:var(--gris-texto);">Subiendo...</span>' +
        '<span id="modal-upload-pct" style="font-size:11px;font-weight:700;color:var(--azul);">0%</span>' +
      '</div>' +
      '<div style="height:5px;background:var(--gris-borde);border-radius:999px;overflow:hidden;">' +
        '<div id="modal-upload-bar" style="height:100%;width:0%;background:var(--azul);border-radius:999px;transition:width .1s linear;"></div>' +
      '</div>' +
    '</div>' +
    '<div style="display:flex;gap:8px;margin-top:16px;">' +
      '<button type="submit" class="btn btn-primary btn-sm" id="modal-doc-btn" disabled>' +
        '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir documento' +
      '</button>' +
      '<button type="button" class="btn btn-outline btn-sm" onclick="mantClose()">Cancelar</button>' +
    '</div>' +
    '</form>'
  );

  // ── Drag & drop robusto ──
  // Usamos el modal completo como zona de drop para máxima comodidad.
  // El archivo se guarda en dzFile para recuperarlo en el submit
  // aunque el truco DataTransfer no funcione en algún navegador.
  var dzFile   = null;
  var modal    = document.getElementById('mant-modal');
  var dz       = document.getElementById('modal-dz');
  var dzDepth  = 0; // contador para ignorar dragleave de elementos hijo

  function dzSetActive(on) {
    if (dz) dz.classList.toggle('dragover', on);
    if (modal) modal.classList.toggle('dz-over', on);
  }

  function dzHandleFile(file) {
    if (!file) return;
    dzFile = file;
    // Intentar asignar al input para que new FormData(form) lo incluya
    var inp = document.getElementById('modal-file-inp');
    try { var dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; } catch(x) {}
    modalDocPreview(inp, file);
  }

  if (modal) {
    modal.addEventListener('dragenter', function(e) {
      e.preventDefault();
      dzDepth++;
      dzSetActive(true);
    });
    modal.addEventListener('dragleave', function(e) {
      dzDepth--;
      if (dzDepth <= 0) { dzDepth = 0; dzSetActive(false); }
    });
    modal.addEventListener('dragover', function(e) {
      e.preventDefault(); // necesario para que drop funcione
    });
    modal.addEventListener('drop', function(e) {
      e.preventDefault();
      dzDepth = 0;
      dzSetActive(false);
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) dzHandleFile(f);
    });
  }

  // Submit via XHR (necesario para barra de progreso)
  var form = document.getElementById('modal-doc-form');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var btn      = document.getElementById('modal-doc-btn');
      var progWrap = document.getElementById('modal-upload-prog');
      var progBar  = document.getElementById('modal-upload-bar');
      var progPct  = document.getElementById('modal-upload-pct');

      var fd = new FormData(this);
      // Si DataTransfer falló, inyectar el archivo arrastrado manualmente
      var inp = document.getElementById('modal-file-inp');
      if (dzFile && (!inp || !inp.files || !inp.files[0])) {
        fd.set('archivo', dzFile, dzFile.name);
      }

      btn.disabled = true;
      btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subiendo...';
      if (progWrap) progWrap.style.display = 'block';

      var xhr = new XMLHttpRequest();
      xhr.open('POST', window.APP_URL + '/modules/documentos/subir.php');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      xhr.upload.addEventListener('progress', function(ev) {
        if (!ev.lengthComputable) return;
        var pct = Math.round(ev.loaded / ev.total * 100);
        if (progBar) progBar.style.width = pct + '%';
        if (progPct) progPct.textContent = pct + '%';
      });

      xhr.addEventListener('load', function() {
        if (progWrap) progWrap.style.display = 'none';
        try {
          var json = JSON.parse(xhr.responseText);
          if (json.ok) {
            mantClose();
            showToast(json.msg || 'Documento subido correctamente.', 'ok');
            _mantRefreshTable();
          } else {
            showToast(json.msg || 'Error al subir el documento.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir documento';
          }
        } catch(ex) {
          showToast('Respuesta inválida del servidor.', 'error');
          btn.disabled = false;
          btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir documento';
        }
      });

      xhr.addEventListener('error', function() {
        if (progWrap) progWrap.style.display = 'none';
        showToast('Error de conexión al subir.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir documento';
      });

      xhr.send(fd);
    });
  }
}

function modalDocPreview(input, file) {
  var f = file || (input && input.files && input.files[0]);
  if (!f) return;
  var sz = f.size < 1048576 ? (f.size / 1024).toFixed(1) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB';
  document.getElementById('modal-fp-name').textContent = f.name + ' (' + sz + ')';
  document.getElementById('modal-fp').classList.add('show');
  document.getElementById('modal-dz').style.display = 'none';
  document.getElementById('modal-doc-btn').disabled = false;
}

function modalDocRemove() {
  var inp = document.getElementById('modal-file-inp');
  if (inp) inp.value = '';
  document.getElementById('modal-fp').classList.remove('show');
  document.getElementById('modal-dz').style.display = 'block';
  document.getElementById('modal-doc-btn').disabled = true;
}
