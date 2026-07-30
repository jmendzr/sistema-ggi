/* txt-ellipsis.js — extraído de layout_end.php (auditoría 2026-07). */

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
