/**
 * ShortcutManager — Sistema centralizado de atajos de teclado
 * GGI Sistema · Un único listener keydown, múltiples contextos.
 *
 * API pública:
 *   ShortcutManager.register(combo, context, handler, opts?)
 *   ShortcutManager.initListNav(opts)
 *   ShortcutManager.closeTopModal()
 *   ShortcutManager.pushModal(el, closeFn?)
 *   ShortcutManager.popModal(el?)
 */
(function (global) {
  'use strict';

  /* ═══════════════════════════════════════════════════════════
     REGISTRO
  ═══════════════════════════════════════════════════════════ */
  var _registry  = [];   // { key, ctx, handler, description, group }
  var _modalStack = [];  // elementos overlay apilados

  /* ═══════════════════════════════════════════════════════════
     HELPERS
  ═══════════════════════════════════════════════════════════ */
  function _isInput(el) {
    if (!el) return false;
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
      || el.isContentEditable;
  }

  function _isInForm(el) {
    return !!(el && el.closest && el.closest('form'));
  }

  function _activeModal() {
    // Prioridad: stack gestionado → fallback DOM
    if (_modalStack.length) return _modalStack[_modalStack.length - 1];
    return document.querySelector(
      '.modal-overlay.open, .mant-modal-overlay.open, .ggi-modal-overlay.open, [data-sm-modal].open'
    ) || null;
  }

  function _getContext() {
    if (_activeModal()) return 'modal';
    var active = document.activeElement;
    if (_isInput(active)) return _isInForm(active) ? 'form' : 'search';
    return 'global';
  }

  /* ═══════════════════════════════════════════════════════════
     MODAL STACK
  ═══════════════════════════════════════════════════════════ */
  function _closeTopModal() {
    // 1. Intentar stack gestionado
    if (_modalStack.length) {
      var entry = _modalStack.pop();
      var el    = entry.el || entry;
      var fn    = entry.closeFn;
      if (typeof fn === 'function') { fn(); return true; }
      // El mant-modal (formularios/iframe) DEBE cerrarse con ggiModalClose: restaura
      // el scroll del body (overflow) y respeta el dirty-check. Quitar .open a secas
      // dejaba el scroll bloqueado al cerrar con Escape.
      if (el && el.id === 'mant-modal' && typeof window.ggiModalClose === 'function') {
        window.ggiModalClose(); return true;
      }
      if (el && el.classList) { el.classList.remove('open'); return true; }
    }
    // 2. Fallback: primer overlay abierto en DOM
    var overlay = document.querySelector(
      '.modal-overlay.open, .mant-modal-overlay.open, .ggi-modal-overlay.open'
    );
    if (overlay) {
      if (overlay.id === 'mant-modal' && typeof window.ggiModalClose === 'function') {
        window.ggiModalClose(); return true;
      }
      overlay.classList.remove('open'); return true;
    }
    return false;
  }

  function pushModal(el, closeFn) {
    // Evitar duplicados
    var exists = _modalStack.some(function(e) { return (e.el || e) === el; });
    if (!exists) _modalStack.push({ el: el, closeFn: closeFn || null });
  }

  function popModal(el) {
    if (!el) { _modalStack.pop(); return; }
    _modalStack = _modalStack.filter(function(e) { return (e.el || e) !== el; });
  }

  /* ─── MutationObserver: detectar modales abiertos automáticamente ─── */
  function _watchOverlay(el) {
    if (el._smWatched) return;
    el._smWatched = true;
    var obs = new MutationObserver(function() {
      if (el.classList.contains('open')) {
        pushModal(el, null);
      } else {
        popModal(el);
      }
    });
    obs.observe(el, { attributes: true, attributeFilter: ['class'] });
    if (el.classList.contains('open')) pushModal(el, null);
  }

  function _observeModals() {
    // Observar overlays ya en DOM
    document.querySelectorAll(
      '.modal-overlay, .mant-modal-overlay, .ggi-modal-overlay, [data-sm-modal]'
    ).forEach(_watchOverlay);

    // Observar overlays añadidos dinámicamente
    new MutationObserver(function(mutations) {
      mutations.forEach(function(m) {
        m.addedNodes.forEach(function(node) {
          if (node.nodeType !== 1) return;
          var sel = '.modal-overlay, .mant-modal-overlay, [data-sm-modal]';
          if (node.matches && node.matches(sel)) {
            _watchOverlay(node);
          } else if (node.querySelectorAll) {
            node.querySelectorAll(sel).forEach(_watchOverlay);
          }
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  /* ═══════════════════════════════════════════════════════════
     REGISTRO DE SHORTCUTS
  ═══════════════════════════════════════════════════════════ */
  function register(combo, ctx, handler, opts) {
    opts = opts || {};
    _registry.push({
      key:         combo.toLowerCase(),
      ctx:         ctx || 'global',
      handler:     handler,
      description: opts.description || '',
      group:       opts.group       || 'General',
      hidden:      opts.hidden      || false,
    });
  }

  /* ═══════════════════════════════════════════════════════════
     PARSEAR EVENTO → COMBO STRING
     ej: Ctrl+S → "ctrl+s" | Alt+ArrowLeft → "alt+arrowleft"
  ═══════════════════════════════════════════════════════════ */
  function _parseEvent(e) {
    var parts = [];
    if (e.ctrlKey || e.metaKey) parts.push('ctrl');
    if (e.altKey)               parts.push('alt');
    if (e.shiftKey)             parts.push('shift');
    var k = e.key.toLowerCase();
    if (k !== 'control' && k !== 'alt' && k !== 'shift' && k !== 'meta') {
      parts.push(k === ' ' ? 'space' : k);
    }
    return parts.join('+');
  }

  /* ═══════════════════════════════════════════════════════════
     HANDLER MAESTRO (capture phase)
  ═══════════════════════════════════════════════════════════ */
  function _onKeydown(e) {
    var combo   = _parseEvent(e);
    var active  = document.activeElement;
    var inInput = _isInput(active);
    var ctx     = _getContext();

    // Filtrar candidatos por combo
    var candidates = _registry.filter(function(s) { return s.key === combo; });
    if (!candidates.length) return;

    // En inputs, solo ciertos combos están permitidos
    var allowedInInput = ['escape', 'ctrl+s', 'ctrl+enter', 'ctrl+k', 'enter'];
    if (inInput && allowedInInput.indexOf(combo) === -1) return;

    // Orden de contextos a intentar: contexto actual primero, luego global
    var order;
    if (ctx === 'global') {
      order = ['global'];
    } else if (ctx === 'modal') {
      order = ['modal', 'global'];
    } else if (ctx === 'form') {
      order = ['form', 'global'];
    } else {
      order = [ctx, 'global'];
    }

    for (var i = 0; i < order.length; i++) {
      var tryCtx = order[i];
      for (var j = 0; j < candidates.length; j++) {
        if (candidates[j].ctx === tryCtx) {
          var result = candidates[j].handler(e, { context: ctx, modal: _activeModal() });
          if (result !== false) return; // handler consumió el evento
        }
      }
    }
  }

  /* ═══════════════════════════════════════════════════════════
     SUBMIT INTELIGENTE DE FORMULARIOS
  ═══════════════════════════════════════════════════════════ */
  function _submitForm(form) {
    if (!form || form.dataset.noShortcut) return false;
    // Buscar botón submit principal (no cancelar)
    var btn = form.querySelector('button[type="submit"].btn-primary')
           || form.querySelector('button[type="submit"]:not(.btn-outline):not([data-no-shortcut])')
           || form.querySelector('button[type="submit"]')
           || form.querySelector('input[type="submit"]');
    if (btn) {
      btn.click();
    } else if (form.requestSubmit) {
      form.requestSubmit();
    } else {
      form.submit();
    }
    return true;
  }

  /* ═══════════════════════════════════════════════════════════
     MODAL DE AYUDA (Ctrl+/)
  ═══════════════════════════════════════════════════════════ */
  function _renderHelpModal() {
    var existing = document.getElementById('sm-help-overlay');
    if (existing) {
      existing.classList.add('open');
      return;
    }

    // Agrupar por grupo, excluir sin descripción o hidden
    var groups = {};
    var groupOrder = [];
    _registry.forEach(function(s) {
      if (!s.description || s.hidden) return;
      if (!groups[s.group]) { groups[s.group] = []; groupOrder.push(s.group); }
      // Deduplicar (mismo descripción)
      var dup = groups[s.group].some(function(x) { return x.description === s.description; });
      if (!dup) groups[s.group].push(s);
    });

    var KEY_LABELS = {
      ctrl: 'Ctrl', alt: 'Alt', shift: 'Shift', meta: '⌘',
      escape: 'Esc', enter: 'Enter', delete: 'Del', backspace: '⌫',
      arrowup: '↑', arrowdown: '↓', arrowleft: '←', arrowright: '→',
      ' ': 'Space', '/': '/',
    };

    function renderCombo(combo) {
      return combo.split('+').map(function(k) {
        return '<kbd>' + (KEY_LABELS[k] || k.toUpperCase()) + '</kbd>';
      }).join('<span class="sm-plus">+</span>');
    }

    var body = groupOrder.map(function(group) {
      var rows = groups[group].map(function(s) {
        return '<div class="sm-row">'
          + '<div class="sm-keys">' + renderCombo(s.key) + '</div>'
          + '<div class="sm-desc">' + s.description + '</div>'
          + '</div>';
      }).join('');
      return '<div class="sm-group">'
        + '<div class="sm-group-title">' + group + '</div>'
        + rows
        + '</div>';
    }).join('');

    var overlay = document.createElement('div');
    overlay.id        = 'sm-help-overlay';
    overlay.className = 'mant-modal-overlay';
    overlay.setAttribute('data-sm-modal', '1');
    overlay.innerHTML =
      '<div class="mant-modal-box" style="max-width:480px;width:100%;">'
      + '<div class="mant-modal-header">'
      +   '<div class="mant-modal-title" style="display:flex;align-items:center;gap:8px;">'
      +     '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
      +       '<rect x="2" y="3" width="20" height="14" rx="2"/>'
      +       '<path d="M8 21h8M12 17v4"/>'
      +     '</svg>'
      +     'Atajos de teclado'
      +   '</div>'
      +   '<button type="button" class="mant-modal-close" id="sm-help-close">✕</button>'
      + '</div>'
      + '<div class="mant-modal-body" style="padding:20px 22px;max-height:65vh;overflow-y:auto;">'
      +   body
      + '</div>'
      + '</div>';

    document.body.appendChild(overlay);
    overlay.querySelector('#sm-help-close').addEventListener('click', function() {
      overlay.classList.remove('open');
    });
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) overlay.classList.remove('open');
    });

    requestAnimationFrame(function() { overlay.classList.add('open'); });
  }

  /* ═══════════════════════════════════════════════════════════
     NAVEGACIÓN DE LISTADOS  (utility opt-in)
  ═══════════════════════════════════════════════════════════ */
  /**
   * initListNav({ tableSelector, rowSelector, getLink, onEdit, onDelete, onDuplicate, onNew })
   *
   * tableSelector : selector del contenedor de la tabla (default '.data-table, table')
   * rowSelector   : selector de filas (default 'tbody tr[data-id], tbody tr.data-row')
   * getLink(row)  : fn que retorna la URL a abrir con Enter (default: primer <a> de la fila)
   * onEdit(row)   : fn llamada con Ctrl+E
   * onDelete(row) : fn llamada con Delete
   * onDuplicate(row) : fn llamada con Ctrl+D
   * onNew()       : fn llamada con Ctrl+N (si no hay handler global ya registrado)
   */
  function initListNav(opts) {
    opts = opts || {};
    var tableSelector = opts.tableSelector || '.data-table, table';
    var rowSel        = opts.rowSelector   || 'tbody tr';
    var _active       = null; // fila actualmente seleccionada

    function _rows() {
      var table = document.querySelector(tableSelector);
      if (!table) return [];
      return Array.from(table.querySelectorAll(rowSel)).filter(function(r) {
        return r.offsetParent !== null; // solo visibles
      });
    }

    function _activate(row) {
      if (_active) _active.classList.remove('sm-row-active');
      _active = row;
      if (!row) return;
      row.classList.add('sm-row-active');
      row.setAttribute('tabindex', '-1');
      row.scrollIntoView({ block: 'nearest' });
    }

    function _move(dir) {
      var rows = _rows();
      if (!rows.length) return;
      if (!_active) { _activate(rows[dir > 0 ? 0 : rows.length - 1]); return; }
      var idx = rows.indexOf(_active);
      var next = rows[idx + dir];
      if (next) _activate(next);
    }

    // Registrar atajos de listado
    register('arrowdown', 'list', function(e) {
      if (_isInput(document.activeElement)) return false;
      e.preventDefault();
      _move(1);
    }, { description: 'Fila siguiente', group: 'Listados' });

    register('arrowup', 'list', function(e) {
      if (_isInput(document.activeElement)) return false;
      e.preventDefault();
      _move(-1);
    }, { description: 'Fila anterior', group: 'Listados' });

    register('enter', 'list', function(e) {
      if (!_active || _isInput(document.activeElement)) return false;
      e.preventDefault();
      var link = opts.getLink ? opts.getLink(_active)
               : (_active.querySelector('a[href]') || { href: null }).href;
      if (link) window.location.href = link;
    }, { description: 'Abrir detalle', group: 'Listados' });

    if (opts.onDelete) {
      register('delete', 'list', function(e) {
        if (!_active || _isInput(document.activeElement)) return false;
        opts.onDelete(_active, e);
      }, { description: 'Eliminar fila', group: 'Listados' });
    }

    if (opts.onEdit) {
      register('ctrl+e', 'list', function(e) {
        if (!_active) return false;
        e.preventDefault();
        opts.onEdit(_active, e);
      }, { description: 'Editar fila', group: 'Listados' });
    }

    if (opts.onDuplicate) {
      register('ctrl+d', 'list', function(e) {
        if (!_active) return false;
        e.preventDefault();
        opts.onDuplicate(_active, e);
      }, { description: 'Duplicar fila', group: 'Listados' });
    }

    if (opts.onNew) {
      register('ctrl+n', 'global', function(e) {
        e.preventDefault();
        opts.onNew(e);
      }, { description: 'Nuevo registro', group: 'Listados' });
    }

    // Activar contexto 'list' cuando el usuario hace click en la tabla
    document.addEventListener('click', function(e) {
      var table = document.querySelector(tableSelector);
      if (!table) return;
      if (table.contains(e.target)) {
        var rows = _rows();
        var clicked = rows.find(function(r) { return r.contains(e.target); });
        if (clicked) _activate(clicked);
      }
    });

    // Actualizar contexto
    var _origGetContext = _getContext;
    _getContext = function() {
      var base = _origGetContext();
      if (base !== 'global') return base;
      // Si la tabla está presente y no hay modal, estamos en 'list'
      if (document.querySelector(tableSelector)) return 'list';
      return 'global';
    };
  }

  /* ═══════════════════════════════════════════════════════════
     SHORTCUTS POR DEFECTO DEL SISTEMA
  ═══════════════════════════════════════════════════════════ */
  function _registerDefaults() {

    /* ── ENTER EN MODAL → confirmar acción primaria ── */
    register('enter', 'modal', function(e, info) {
      var modal = (info && info.modal) || _activeModal();
      if (!modal) return false;
      var active = document.activeElement;
      // Textarea dentro del modal: Enter = nueva línea
      if (active && active.tagName === 'TEXTAREA' && modal.contains(active)) return false;
      // Botón ya enfocado: dejar que Enter lo active nativamente
      if (active && active.tagName === 'BUTTON' && modal.contains(active)) return false;
      // Buscar botón de confirmación: primero data-confirm, luego btn-primary
      var btn = modal.querySelector('button[data-confirm]:not(:disabled)')
             || modal.querySelector('button.btn-primary:not(.btn-outline):not([data-no-enter]):not(:disabled)');
      if (btn) { e.preventDefault(); btn.click(); return true; }
      return false;
    }, { description: 'Confirmar acción principal', group: 'Modales' });

    /* ── ESCAPE ── */
    // Modal: cierra el último modal
    register('escape', 'modal', function(e) {
      e.preventDefault();
      _closeTopModal();
    }, { hidden: true }); // sin descripción duplicada, la muestra solo el de 'global'

    // Global: cierra modal si hay, si no nada
    register('escape', 'global', function() {
      return _closeTopModal();
    }, { description: 'Cerrar modal / Cancelar', group: 'General' });

    /* ── GUARDAR (Ctrl+S) ── */
    register('ctrl+s', 'form', function(e) {
      e.preventDefault();
      var form = document.activeElement && document.activeElement.closest
        ? document.activeElement.closest('form') : null;
      if (!form) form = document.querySelector('form:not([data-no-shortcut])');
      return _submitForm(form);
    }, { hidden: true });

    register('ctrl+s', 'global', function(e) {
      e.preventDefault();
      var form = document.querySelector('form:not([data-no-shortcut])');
      return _submitForm(form);
    }, { description: 'Guardar formulario', group: 'Formularios' });

    /* ── GUARDAR Y CONTINUAR (Ctrl+Enter) ── */
    register('ctrl+enter', 'form', function(e) {
      e.preventDefault();
      var form = document.activeElement && document.activeElement.closest
        ? document.activeElement.closest('form') : null;
      return _submitForm(form);
    }, { description: 'Guardar formulario', group: 'Formularios' });

    /* ── BUSCADOR INTERNO (Ctrl+F) ── */
    register('ctrl+f', 'global', function(e) {
      // Prioridad: buscador global del sistema, luego cualquier search input
      var search = document.getElementById('global-search-input')
        || document.querySelector('[data-shortcut-search], .ss-input, input[type="search"]');
      if (search) {
        e.preventDefault();
        search.focus();
        if (search.select) search.select();
        return true;
      }
      return false; // dejar que el navegador maneje Ctrl+F si no hay buscador
    }, { description: 'Enfocar buscador', group: 'General' });

    /* ── AYUDA (Ctrl+/) ── */
    register('ctrl+/', 'global', function(e) {
      e.preventDefault();
      _renderHelpModal();
    }, { description: 'Atajos de teclado', group: 'General' });

    /* ── NAVEGACIÓN (Alt+← / Alt+→) ── */
    register('alt+arrowleft', 'global', function(e) {
      e.preventDefault();
      history.back();
    }, { description: 'Volver atrás', group: 'Navegación' });

    register('alt+arrowright', 'global', function(e) {
      e.preventDefault();
      history.forward();
    }, { description: 'Ir adelante', group: 'Navegación' });
  }

  /* ═══════════════════════════════════════════════════════════
     INICIALIZACIÓN
  ═══════════════════════════════════════════════════════════ */
  function _init() {
    _registerDefaults();
    _observeModals();
    // Capture phase → se ejecuta antes que cualquier listener en bubbling
    document.addEventListener('keydown', _onKeydown, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _init);
  } else {
    _init();
  }

  /* ═══════════════════════════════════════════════════════════
     API PÚBLICA
  ═══════════════════════════════════════════════════════════ */
  global.ShortcutManager = {
    register:      register,
    initListNav:   initListNav,
    closeTopModal: _closeTopModal,
    pushModal:     pushModal,
    popModal:      popModal,
  };

})(window);
