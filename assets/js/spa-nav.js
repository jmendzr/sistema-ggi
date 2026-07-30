/* spa-nav.js — extraído de layout_end.php (auditoría 2026-07). */

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
      .then(function (html) {
        // Opt-out de caché SPA: páginas con datos que cambian por acciones en
        // OTRAS páginas (p. ej. el calendario de llamadas) se marcan con
        // data-spa-nocache para servirse siempre frescas.
        if (html.indexOf('data-spa-nocache') !== -1) { delete cache[href]; }
        else { cache[href] = html; }
        return html;
      })
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
        .then(function (html) {
          if (html.indexOf('data-spa-nocache') !== -1) { delete cache[url.href]; }
          else { cache[url.href] = html; }
          return html;
        })
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
