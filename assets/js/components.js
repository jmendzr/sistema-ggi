/* ═══════════════════════════════════════════════════════════════════════════
   GGI DESIGN SYSTEM - Componentes Core JavaScript
   ═══════════════════════════════════════════════════════════════════════════ */

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ 1. CONFIRMDIALOG                                                       ║
   ╚════════════════════════════════════════════════════════════════════════╝ */

// GGIConfirm ahora delega a window.ggiConfirm() (definido en layout_end.php),
// que es el modal estándar usado en todo el sistema (Normas ISO, etc.).
// Así todos los módulos que llaman GGIConfirm.show({...}) obtienen el mismo estilo.
const GGIConfirm = (() => {
  const show = (options = {}) => {
    const {
      icon = 'info',
      title = '¿Confirmar?',
      message = '',
      confirmText = 'Confirmar',
      cancelText = 'Cancelar',
      onConfirm = () => {}
    } = options;

    if (typeof window.ggiConfirm !== 'function') {
      // Fallback al confirm() nativo si ggiConfirm no está disponible
      if (window.confirm(message || title)) onConfirm();
      return;
    }

    window.ggiConfirm(title, message, {
      type: icon,           // 'danger' | 'warning' | 'info' | 'success'
      confirmText: confirmText,
      cancelText: cancelText
    }).then((ok) => {
      if (ok && typeof onConfirm === 'function') onConfirm();
    });
  };

  const close = () => {
    const ov = document.getElementById('_gci-ov');
    if (ov) ov.remove();
  };

  return { show, close };
})();

/**
 * ggiConfirmForm(form, message, opts) — confirmar con el modal canónico y, si
 * el usuario acepta, enviar el formulario. Reemplaza el patrón feo
 * `onsubmit="return confirm('…')"`. Uso: onsubmit="return ggiConfirmForm(this,'¿Borrar?')".
 * Devuelve false SIEMPRE (frena el submit inmediato; el modal lo reenvía al aceptar).
 */
window.ggiConfirmForm = function (form, message, opts) {
  opts = opts || {};
  GGIConfirm.show({
    icon: opts.icon || 'danger',
    title: opts.title || 'Confirmar',
    message: message || '¿Confirmar esta acción?',
    confirmText: opts.confirmText || 'Sí, continuar',
    onConfirm: function () { if (form && typeof form.submit === 'function') form.submit(); }
  });
  return false;
};

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ 2. STATECHANGE                                                         ║
   ╚════════════════════════════════════════════════════════════════════════╝ */

const GGIStateChange = (() => {
  let config = {
    selectSelector: '.ggi-state-input',
    badgeSelector: '.ggi-state-badge',
    confirmBeforeChange: true,
    onChange: null
  };

  const init = (options = {}) => {
    config = { ...config, ...options };

    // Inicializar selects
    document.querySelectorAll(config.selectSelector).forEach(select => {
      select.addEventListener('change', handleSelectChange);
      // Validación al salir del campo
      select.addEventListener('blur', () => validateSelect(select));
    });

    // Inicializar badges
    document.querySelectorAll(config.badgeSelector).forEach(badge => {
      badge.addEventListener('click', handleBadgeClick);
    });
  };

  const handleSelectChange = (e) => {
    const select = e.target;
    const errorEl = select.parentElement?.querySelector('.ggi-state-error');

    if (select.value === '') {
      if (errorEl) {
        errorEl.textContent = 'Selecciona un estado';
        errorEl.classList.add('show');
      }
      select.classList.add('error');
      return;
    }

    // Limpiar error
    if (errorEl) errorEl.classList.remove('show');
    select.classList.remove('error');
  };

  const validateSelect = (select) => {
    if (select.value === '') {
      select.classList.add('error');
      const errorEl = select.parentElement?.querySelector('.ggi-state-error');
      if (errorEl) {
        errorEl.textContent = 'Campo requerido';
        errorEl.classList.add('show');
      }
    }
  };

  const handleBadgeClick = (e) => {
    if (e.target.closest('.ggi-state-chevron')) {
      const badge = e.currentTarget;
      const entityId = badge.dataset.id;
      const entityType = badge.dataset.entity;

      GGIConfirm.show({
        icon: 'info',
        title: 'Cambiar estado',
        message: `¿Cambiar estado de este ${entityType}?`,
        confirmText: 'Cambiar',
        onConfirm: () => {
          // Aquí va la lógica de cambio (debe llamar al backend)
          if (config.onChange) {
            config.onChange({
              entityType,
              entityId,
              oldState: badge.dataset.current,
              newState: null // Será actualizado por el servidor
            });
          }
        }
      });
    }
  };

  return { init, handleSelectChange, validateSelect };
})();

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ 3. DATATABLE                                                           ║
   ╚════════════════════════════════════════════════════════════════════════╝ */

class GGIDataTable {
  constructor(options = {}) {
    this.container = document.querySelector(options.containerId || '.ggi-datatable');
    if (!this.container) {
      console.error('GGIDataTable: container not found');
      return;
    }

    this.apiUrl = options.apiUrl;
    this.pageSize = options.pageSize || 20;
    this.columns = options.columns || [];
    this.onSearch = options.onSearch || null;
    this.onSort = options.onSort || null;
    this.currentPage = 1;
    this.totalPages = 1;
    this.data = [];

    this.init();
  }

  init() {
    this.setupElements();
    this.attachEventListeners();
    this.loadData();
  }

  setupElements() {
    this.searchInput = this.container.querySelector('.ggi-dt-search');
    this.tbody = this.container.querySelector('#dt-body');
    this.emptyState = this.container.querySelector('.ggi-dt-empty');
    this.showingEl = this.container.querySelector('#dt-showing');
    this.pageInfoEl = this.container.querySelector('#dt-page-info');
    this.prevBtn = this.container.querySelector('.ggi-dt-prev');
    this.nextBtn = this.container.querySelector('.ggi-dt-next');
  }

  attachEventListeners() {
    // Búsqueda
    if (this.searchInput) {
      this.searchInput.addEventListener('input', (e) => this.handleSearch(e.target.value));
    }

    // Paginación
    if (this.prevBtn) this.prevBtn.addEventListener('click', () => this.prev());
    if (this.nextBtn) this.nextBtn.addEventListener('click', () => this.next());

    // Sorteo
    this.container.querySelectorAll('.ggi-dt-table th.sorteable').forEach(th => {
      th.addEventListener('click', () => this.handleSort(th));
    });
  }

  async loadData() {
    try {
      if (!this.apiUrl) {
        console.warn('GGIDataTable: apiUrl not provided');
        return;
      }

      const params = new URLSearchParams({
        page: this.currentPage,
        limit: this.pageSize
      });

      const response = await fetch(`${this.apiUrl}?${params}`);
      const result = await response.json();

      this.data = result.data || [];
      this.totalPages = result.totalPages || 1;

      this.render();
    } catch (error) {
      console.error('GGIDataTable: Error loading data', error);
      this.showEmpty();
    }
  }

  render() {
    if (this.data.length === 0) {
      this.showEmpty();
      return;
    }

    if (this.emptyState) this.emptyState.style.display = 'none';

    // Renderizar filas
    this.tbody.innerHTML = this.data.map((row, idx) => {
      const cells = this.columns.map(col => {
        let content = row[col.key];
        if (col.render && typeof col.render === 'function') {
          content = col.render(content, row, idx);
        }
        return `<td>${content}</td>`;
      }).join('');

      return `<tr data-id="${row.id}">${cells}</tr>`;
    }).join('');

    // Actualizar info
    if (this.showingEl) {
      this.showingEl.textContent = this.data.length;
    }
    if (this.pageInfoEl) {
      this.pageInfoEl.textContent = `Página ${this.currentPage} de ${this.totalPages}`;
    }

    // Botones de paginación
    if (this.prevBtn) {
      this.prevBtn.disabled = this.currentPage <= 1;
    }
    if (this.nextBtn) {
      this.nextBtn.disabled = this.currentPage >= this.totalPages;
    }
  }

  showEmpty() {
    if (this.emptyState) this.emptyState.style.display = 'flex';
    if (this.tbody) this.tbody.innerHTML = '';
    if (this.showingEl) this.showingEl.textContent = '0';
  }

  handleSearch(query) {
    this.currentPage = 1;
    if (this.onSearch && typeof this.onSearch === 'function') {
      this.onSearch(query);
    }
    this.loadData();
  }

  handleSort(th) {
    const field = th.dataset.sort;
    const isAsc = th.classList.contains('sorted');
    const direction = isAsc ? 'DESC' : 'ASC';

    // Limpiar otros sorted
    this.container.querySelectorAll('.ggi-dt-table th.sorted').forEach(t => {
      t.classList.remove('sorted');
    });

    // Marcar este como sorted
    th.classList.add('sorted');

    if (this.onSort && typeof this.onSort === 'function') {
      this.onSort(field, direction);
    }

    this.loadData();
  }

  prev() {
    if (this.currentPage > 1) {
      this.currentPage--;
      this.loadData();
    }
  }

  next() {
    if (this.currentPage < this.totalPages) {
      this.currentPage++;
      this.loadData();
    }
  }
}

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ GGIRowMenu — menú contextual (...) reutilizable para filas de listados  ║
   ║ Funciona por delegación global: cualquier .ggi-row-menu-btn dentro de   ║
   ║ un .ggi-row-menu-wrap abre/cierra su .ggi-row-menu adyacente.           ║
   ╚════════════════════════════════════════════════════════════════════════╝ */
const GGIRowMenu = (() => {
  function setExpanded(wrap, val) {
    const btn = wrap.querySelector('.ggi-row-menu-btn');
    if (btn) btn.setAttribute('aria-expanded', val ? 'true' : 'false');
  }
  // Devuelve un menú portaleado a <body> de vuelta a su wrap original.
  function restoreMenu(wrap) {
    const menu = wrap._ggiMenuPortaled;
    if (menu) {
      menu.style.display = '';
      menu.style.left = '';
      menu.style.top  = '';
      wrap.appendChild(menu);
      wrap._ggiMenuPortaled = null;
    }
  }
  function closeAll(except) {
    document.querySelectorAll('.ggi-row-menu-wrap.open').forEach(w => {
      if (w !== except) { w.classList.remove('open'); setExpanded(w, false); restoreMenu(w); }
    });
  }
  function open(wrap) {
    closeAll(wrap);
    wrap.classList.add('open');
    setExpanded(wrap, true);
    const menu = wrap.querySelector('.ggi-row-menu');
    const btn  = wrap.querySelector('.ggi-row-menu-btn');
    if (menu && btn) {
      // PORTAL a <body>: el menú es position:fixed, pero un ancestro con transform
      // (p.ej. la animación de entrada ggiFadeUp de las filas, activa ~0.6s tras
      // cargar/cambiar de página) reubica el containing block del fixed → el menú
      // salía DESCENTRADO. En el body (sin ancestro transformado) el fixed siempre
      // es relativo al viewport. Resuelve el desfase en desktop y móvil/PWA.
      document.body.appendChild(menu);
      wrap._ggiMenuPortaled = menu;
      menu.style.display = 'flex';            // el selector .wrap.open .menu ya no aplica
      const br = btn.getBoundingClientRect();
      const mw = menu.offsetWidth  || 184;   // ya es display:flex → medible
      const mh = menu.offsetHeight || 0;
      let left = br.right - mw;               // alinear bordes derechos
      if (left < 8) left = 8;
      if (left + mw > window.innerWidth - 8) left = window.innerWidth - 8 - mw;
      let top = br.bottom + 4;                // debajo del botón
      if (top + mh > window.innerHeight - 8) top = br.top - mh - 4; // voltear arriba
      if (top < 8) top = 8;
      menu.style.left = left + 'px';
      menu.style.top  = top  + 'px';
    }
  }
  function init() {
    if (window.__ggiRowMenuInit) return;
    window.__ggiRowMenuInit = true;

    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.ggi-row-menu-btn');
      if (btn) {
        e.preventDefault();
        e.stopPropagation();
        const wrap = btn.closest('.ggi-row-menu-wrap');
        if (!wrap) return;
        if (wrap.classList.contains('open')) { wrap.classList.remove('open'); setExpanded(wrap, false); restoreMenu(wrap); }
        else open(wrap);
        return;
      }
      // Click en un ítem del menú → cerrar (la acción/onclick/enlace procede igual).
      if (e.target.closest('.ggi-row-menu-item')) { closeAll(null); return; }
      // Click fuera del menú → cerrar.
      if (!e.target.closest('.ggi-row-menu')) closeAll(null);
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeAll(null);
    });

    // El menú es position:fixed (no sigue el scroll) → cerrarlo al hacer scroll
    // o redimensionar, para que no quede "flotando" desalineado.
    window.addEventListener('scroll', () => {
      if (document.querySelector('.ggi-row-menu-wrap.open')) closeAll(null);
    }, true);
    window.addEventListener('resize', () => closeAll(null));
  }
  return { init, closeAll };
})();

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ Selección visual de filas (solo cosmético — togglea .bulk-selected)      ║
   ║ Idempotente con módulos que ya lo hacen: solo alterna la clase visual.  ║
   ╚════════════════════════════════════════════════════════════════════════╝ */
const GGIRowSelect = (() => {
  function init() {
    if (window.__ggiRowSelectInit) return;
    window.__ggiRowSelectInit = true;
    document.addEventListener('change', (e) => {
      const cb = e.target.closest('.row-chk');
      if (!cb || !cb.closest('.data-table, .ggi-dt-table')) return;
      const tr = cb.closest('tr');
      if (tr) tr.classList.toggle('bulk-selected', cb.checked);
    });
  }
  return { init };
})();

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ Accesibilidad de la barra de acciones masivas (.ggi-bulk-bar)            ║
   ║ La barra se crea dinámicamente por cada módulo. Aquí, de forma global,  ║
   ║ se le añaden los roles/aria para que lectores de pantalla anuncien el   ║
   ║ conteo "N seleccionadas" y reconozcan la barra como toolbar.            ║
   ╚════════════════════════════════════════════════════════════════════════╝ */
const GGIBulkBarA11y = (() => {
  function enhance(bar) {
    if (!bar || bar.dataset.ggiA11y) return;
    bar.dataset.ggiA11y = '1';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Acciones para los registros seleccionados');
    const count = bar.querySelector('.ggi-bulk-count');
    if (count) {
      count.setAttribute('role', 'status');
      count.setAttribute('aria-live', 'polite');
      count.setAttribute('aria-atomic', 'true');
    }
  }
  function init() {
    if (window.__ggiBulkA11yInit) return;
    window.__ggiBulkA11yInit = true;
    // Barras ya presentes
    document.querySelectorAll('.ggi-bulk-bar').forEach(enhance);
    // Barras añadidas dinámicamente (creadas por los scripts de cada módulo)
    const obs = new MutationObserver(muts => {
      for (const m of muts) {
        for (const node of m.addedNodes) {
          if (node.nodeType !== 1) continue;
          if (node.classList && node.classList.contains('ggi-bulk-bar')) enhance(node);
          else if (node.querySelector) node.querySelectorAll('.ggi-bulk-bar').forEach(enhance);
        }
      }
    });
    obs.observe(document.body, { childList: true, subtree: true });
  }
  return { init };
})();

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ GGIFormDraft — autosave de borradores en localStorage (opt-in)          ║
   ║                                                                          ║
   ║ Uso: <form data-ggi-draft="cot-crear">…</form>                          ║
   ║  - Guarda inputs/selects/textareas (debounce 600ms) mientras se escribe.║
   ║  - Al volver con borrador guardado: toast "Restaurar borrador".         ║
   ║  - Limpia al enviar el formulario. Expira a las 48 h.                   ║
   ║  - Ignora password, file, hidden (_csrf) y campos data-ggi-draft-skip.  ║
   ║ Motivo: en móvil la PWA se suspende con frecuencia (llamada, cambio de  ║
   ║ app) y iOS mata la página → sin esto el usuario pierde lo tecleado.     ║
   ╚════════════════════════════════════════════════════════════════════════╝ */
const GGIFormDraft = (() => {
  const PREFIX = 'ggi_draft_';
  const TTL_MS = 48 * 3600 * 1000;

  function _key(form)  { return PREFIX + form.dataset.ggiDraft; }
  function _fields(form) {
    return Array.from(form.elements).filter(el =>
      el.name &&
      !el.disabled &&
      !['password','file','hidden','submit','button'].includes(el.type) &&
      !el.hasAttribute('data-ggi-draft-skip')
    );
  }

  function _collect(form) {
    const data = {};
    _fields(form).forEach(el => {
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) data[el.name] = el.value;
      } else if (el.value !== '') {
        data[el.name] = el.value;
      }
    });
    return data;
  }

  function _save(form) {
    try {
      const data = _collect(form);
      if (Object.keys(data).length === 0) { _clear(form); return; }
      localStorage.setItem(_key(form), JSON.stringify({ ts: Date.now(), data }));
    } catch (e) { /* almacenamiento lleno/bloqueado: el autosave es best-effort */ }
  }

  function _clear(form) {
    try { localStorage.removeItem(_key(form)); } catch (e) {}
  }

  function _restore(form, data) {
    _fields(form).forEach(el => {
      if (!(el.name in data)) return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        el.checked = (el.value === data[el.name]);
      } else {
        el.value = data[el.name];
        // flatpickr (altInput) no refleja .value=; usar su API si existe
        if (el._flatpickr) { try { el._flatpickr.setDate(data[el.name], false); } catch (e) {} }
      }
      // disparar change para selects dependientes (distrito→sede, etc.)
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  function init() {
    document.querySelectorAll('form[data-ggi-draft]').forEach(form => {
      if (form._ggiDraftInit) return; // evita doble enganche en re-init SPA
      form._ggiDraftInit = true;
      const key = _key(form);
      let raw = null;
      try { raw = localStorage.getItem(key); } catch (e) {}

      if (raw) {
        try {
          const saved = JSON.parse(raw);
          if (saved.ts && Date.now() - saved.ts < TTL_MS && saved.data && Object.keys(saved.data).length) {
            if (typeof showToast === 'function') {
              showToast({
                title: 'Tienes un borrador sin guardar',
                msg: 'Se guardó automáticamente la última vez.',
                action: { label: 'Restaurar', onClick: () => _restore(form, saved.data) }
              }, 'info', 10000);
            }
          } else {
            localStorage.removeItem(key); // expirado
          }
        } catch (e) { try { localStorage.removeItem(key); } catch (e2) {} }
      }

      let timer = null;
      form.addEventListener('input',  _queue);
      form.addEventListener('change', _queue);
      function _queue() {
        clearTimeout(timer);
        timer = setTimeout(() => _save(form), 600);
      }

      form.addEventListener('submit', () => {
        clearTimeout(timer);
        _clear(form);
      });
    });
  }

  return { init };
})();

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ ggiComprimirImagen(file) → Promise<File>                                ║
   ║                                                                          ║
   ║ Comprime fotos antes de subirlas: una foto de cámara móvil pesa 4-8 MB  ║
   ║ y en 4G la subida es lenta. Si el archivo es imagen y supera UMBRAL,    ║
   ║ se redimensiona a máx 1600px y se re-encoda a JPEG 0.85 (≈150-400 KB).  ║
   ║ PDFs y archivos pequeños pasan intactos. Ante cualquier error devuelve  ║
   ║ el original (best-effort, nunca bloquea la subida).                     ║
   ╚════════════════════════════════════════════════════════════════════════╝ */
async function ggiComprimirImagen(file, maxPx, calidad) {
  const UMBRAL = 700 * 1024;
  maxPx   = maxPx   || 1600;
  calidad = calidad || 0.85;

  if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) || file.size <= UMBRAL) {
    return file;
  }

  try {
    const url = URL.createObjectURL(file);
    const img = await new Promise((res, rej) => {
      const i = new Image();
      i.onload = () => res(i);
      i.onerror = rej;
      i.src = url;
    });
    URL.revokeObjectURL(url);

    const scale = Math.min(1, maxPx / Math.max(img.naturalWidth, img.naturalHeight));
    const w = Math.round(img.naturalWidth  * scale);
    const h = Math.round(img.naturalHeight * scale);

    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    canvas.getContext('2d').drawImage(img, 0, 0, w, h);

    const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', calidad));
    if (!blob || blob.size === 0 || blob.size >= file.size) return file; // no ganó nada

    const nombre = file.name.replace(/\.(png|webp|jpeg|jpg)$/i, '') + '.jpg';
    return new File([blob], nombre, { type: 'image/jpeg' });
  } catch (e) {
    return file; // best-effort
  }
}
window.ggiComprimirImagen = ggiComprimirImagen;

/* ╔════════════════════════════════════════════════════════════════════════╗
   ║ INICIALIZACIÓN AUTOMÁTICA                                             ║
   ╚════════════════════════════════════════════════════════════════════════╝ */

document.addEventListener('DOMContentLoaded', () => {
  // Inicializar componentes automáticamente
  GGIStateChange.init();
  GGIRowMenu.init();
  GGIRowSelect.init();
  GGIBulkBarA11y.init();
  GGIFormDraft.init();
});

// Formularios que entran por swap SPA (DOMContentLoaded ya pasó)
window.addEventListener('spa:loaded',      () => GGIFormDraft.init());
window.addEventListener('ggi:pagechanged', () => GGIFormDraft.init());
