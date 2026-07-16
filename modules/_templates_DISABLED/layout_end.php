</main>

<style>
/* ── Transición suave entre páginas ── */


/* ── Toast notifications ── */
#toast-container {
  position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
  z-index: 99999; display: flex; flex-direction: column;
  align-items: center; gap: 8px; pointer-events: none;
}
.toast {
  background: #1a1f36; color: #fff;
  padding: 12px 20px; border-radius: 10px;
  font-size: 13px; font-weight: 500;
  font-family: 'Inter', sans-serif;
  display: flex; align-items: center; gap: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.2);
  animation: toastIn 0.3s ease forwards;
  pointer-events: auto; min-width: 200px; max-width: 420px;
}
.toast.ok    { border-left: 4px solid #2DB140; }
.toast.error { border-left: 4px solid #ef4444; }
.toast.hiding { animation: toastOut 0.3s ease forwards; }
@keyframes toastIn  { from { opacity:0; transform:translateY(-16px); } to { opacity:1; transform:translateY(0); } }
@keyframes toastOut { from { opacity:1; transform:translateY(0); } to { opacity:0; transform:translateY(-16px); } }

/* ── Modal mantenimiento ── */
.mant-modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.5); z-index: 9999;
  align-items: center; justify-content: center; padding: 20px;
}
.mant-modal-overlay.open { display: flex; }
.mant-modal-box {
  background: #fff; border-radius: 14px; width: 100%;
  max-width: 500px; max-height: 90vh; overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.mant-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px; border-bottom: 1px solid var(--gris-borde);
  position: sticky; top: 0; background: #fff; z-index: 1;
}
.mant-modal-title { font-family:'Outfit',sans-serif; font-size:15px; font-weight:700; color:var(--azul); }
.mant-modal-close {
  background: none; border: none; cursor: pointer; font-size: 18px;
  color: var(--gris-texto); width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
}
.mant-modal-close:hover { background: var(--gris-bg); }
.mant-modal-body { padding: 22px; }

/* ── Tabla mantenimiento ── */
.mant-table-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
.mant-card-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--gris-borde); }
.mant-card-title { font-family:'Outfit',sans-serif; font-size:14px; font-weight:700; color:var(--azul); }
.mant-table { width:100%; border-collapse:collapse; }
.mant-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; padding:11px 16px; background:#fafbfe; border-bottom:1px solid var(--gris-borde); text-align:left; }
.mant-table td { padding:12px 16px; font-size:13px; border-bottom:1px solid #f0f2f8; vertical-align:middle; }
.mant-table tr:last-child td { border-bottom:none; }
.mant-table tr:hover td { background:#fafbfe; }
.badge-activo   { background:#e8f7eb; color:#229133; padding:3px 9px; border-radius:100px; font-size:11px; font-weight:600; }
.badge-inactivo { background:#f1f5f9; color:#64748b; padding:3px 9px; border-radius:100px; font-size:11px; font-weight:600; }
.alert-ok { display:none; } /* Ocultar alertas estáticas - usamos toasts */
.empty-state { padding:32px; text-align:center; color:var(--gris-texto); font-size:13px; }
.btn-ic { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; border:none; cursor:pointer; text-decoration:none; transition:all 0.15s; flex-shrink:0; }
.btn-ic-blue  { background:#e8edf8; color:#1A3A8F; } .btn-ic-blue:hover  { background:#1A3A8F; color:#fff; }
.btn-ic-amber { background:#fff7e6; color:#b45309; } .btn-ic-amber:hover { background:#f59e0b; color:#fff; }
.btn-ic-green { background:#e8f7eb; color:#229133; } .btn-ic-green:hover { background:#2DB140; color:#fff; }
.btn-ic-red   { background:#fff5f5; color:#dc2626; } .btn-ic-red:hover   { background:#ef4444; color:#fff; }
.field { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
.field label { font-size:12px; font-weight:600; color:var(--azul); }
.field input, .field textarea, .field select {
  padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
  font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark);
  background:#fafbfe; outline:none; width:100%;
  transition:border-color 0.2s; -webkit-appearance:none;
}
.field input:focus, .field textarea:focus, .field select:focus { border-color:var(--azul); background:#fff; }
.field textarea { min-height:72px; resize:vertical; }
@media (max-width:640px) {
  .mant-table thead { display:none; }
  .mant-table, .mant-table tbody, .mant-table tr, .mant-table td { display:block; width:100%; }
  .mant-table tr { border-radius:8px; border:1px solid var(--gris-borde); margin-bottom:8px; padding:12px 14px; background:#fff; }
  .mant-table td { padding:3px 0; border:none; }
}
</style>

<!-- Modal mantenimiento -->
<div class="mant-modal-overlay" id="mant-modal" onclick="mantCloseOnBg(event)">
  <div class="mant-modal-box">
    <div class="mant-modal-header">
      <div class="mant-modal-title" id="mant-modal-title">Nuevo registro</div>
      <button type="button" class="mant-modal-close" onclick="mantClose()">✕</button>
    </div>
    <div class="mant-modal-body" id="mant-modal-body"></div>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<script>
window.APP_URL    = '<?= APP_URL ?>';
window.CSRF_TOKEN = '<?= csrf_token() ?>';

// ── Tema oscuro/claro ──
function toggleTheme() {
  var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  var next   = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('ggi_theme', next);
  document.getElementById('icon-dark').style.display  = next === 'dark'  ? 'none' : '';
  document.getElementById('icon-light').style.display = next === 'light' ? 'none' : '';
}

// Inicializar icono según tema activo
(function(){
  var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  var d = document.getElementById('icon-dark');
  var l = document.getElementById('icon-light');
  if (d && l) {
    d.style.display = isDark ? 'none' : '';
    l.style.display = isDark ? ''     : 'none';
  }
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

// ── Sidebar desktop colapsable ──
function toggleSidebarDesktop() {
  const body    = document.body;
  const sidebar = document.querySelector('.sidebar');
  const header  = document.querySelector('.header');
  const main    = document.querySelector('.main');
  const btn     = document.querySelector('.btn-toggle-desktop svg');
  const collapsed = sidebar.classList.toggle('collapsed');
  body.classList.toggle('sidebar-collapsed', collapsed);

  if (collapsed) {
    header.style.left = '0';
    main.style.marginLeft = '0';
    // Rotar icono
    if (btn) btn.style.transform = 'scaleX(-1)';
  } else {
    header.style.left = 'var(--sidebar-w)';
    main.style.marginLeft = 'var(--sidebar-w)';
    if (btn) btn.style.transform = 'scaleX(1)';
  }
  // Actualizar barra flotante de envíos si existe
  const bulkBar = document.getElementById('bulk-bar-mobile');
  if (bulkBar && bulkBar.style.display !== 'none') {
    bulkBar.style.left = collapsed ? '0' : 'var(--sidebar-w)';
  }
  // Guardar preferencia
  localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
}

// Restaurar preferencia al cargar
ggiReady(() => {
  if (localStorage.getItem('sidebar_collapsed') === '1' && window.innerWidth > 768) {
    toggleSidebarDesktop();
  }
});
document.querySelectorAll('.nav-item').forEach(el => {
  el.addEventListener('click', () => { if (window.innerWidth <= 768) closeSidebar(); });
});

// ── Toast ──
function showToast(msg, type = 'ok', duration = 3500) {
  const icons = {
    ok:    '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    error: '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
  };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `${icons[type] || icons.ok}${msg}`;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(() => {
    t.classList.add('hiding');
    t.addEventListener('animationend', () => t.remove());
  }, duration);
}



// ── Modal helpers ──
function mantOpen(titulo, formHtml) {
  document.getElementById('mant-modal-title').textContent = titulo;
  document.getElementById('mant-modal-body').innerHTML = formHtml;
  document.getElementById('mant-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
  const first = document.querySelector('#mant-modal-body input:not([type=hidden]), #mant-modal-body select');
  if (first) setTimeout(() => first.focus(), 50);
}
function mantClose() {
  document.getElementById('mant-modal').classList.remove('open');
  document.body.style.overflow = '';
}
function mantCloseOnBg(e) {
  if (e.target === document.getElementById('mant-modal')) mantClose();
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
      if (c.type === 'textarea') {
        return `<div class="field"><label>${c.label}${c.required ? ' <span style="color:var(--red)">*</span>' : ''}</label><textarea name="${c.name}" placeholder="${c.placeholder || ''}">${val}</textarea></div>`;
      }
      if (c.type === 'select') {
        const opts = c.options.map(o => `<option value="${o.value}"${val == o.value ? ' selected' : ''}>${o.label}</option>`).join('');
        return `<div class="field"><label>${c.label}${c.required ? ' <span style="color:var(--red)">*</span>' : ''}</label><select name="${c.name}">${opts}</select></div>`;
      }
      return `<div class="field"><label>${c.label}${c.required ? ' <span style="color:var(--red)">*</span>' : ''}</label><input type="${c.inputType || 'text'}" name="${c.name}" value="${val}" placeholder="${c.placeholder || ''}"></div>`;
    }).join('');

    const rowId = isEdit ? (rowData ? rowData.id : editId) : '';
    return { titulo: t, html: `
      <form onsubmit="mantAjaxSubmit(event, '${window.location.pathname}', ${rowId || 0})">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="${rowId}">
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

  const theads = columnas.map(c => `<th scope="col">${c}</th>`).join('') + '<th scope="col">Estado</th><th scope="col"></th>';
  const tbody = filas.length === 0
    ? `<tr><td colspan="${columnas.length + 2}" class="empty-state">Sin registros aún.</td></tr>`
    : filas.map(f => {
        const tds = f.cols.map(c => `<td>${c || '—'}</td>`).join('');
        const badge = f.activo != 0 ? `<span class="badge-activo">Activo</span>` : `<span class="badge-inactivo">Inactivo</span>`;
        return `<tr>
          ${tds}<td>${badge}</td>
          <td style="white-space:nowrap">
            <div style="display:flex;gap:4px;">
              <button type="button" onclick="mantEditRow(${f.id})" class="btn-ic btn-ic-blue" title="Editar">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <a href="?accion=toggle&id=${f.id}" class="btn-ic ${f.activo != 0 ? 'btn-ic-amber' : 'btn-ic-green'}" title="${f.activo != 0 ? 'Desactivar' : 'Activar'}" onclick="return confirm('¿${f.activo != 0 ? 'Desactivar' : 'Activar'} este registro?')">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
              </a>
              <a href="?accion=eliminar&id=${f.id}" class="btn-ic btn-ic-red" title="Eliminar" onclick="return confirm('¿Eliminar? No se puede deshacer.')">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
              </a>
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
  if (!window.__mantBuildForm) return;
  const { titulo, html } = window.__mantBuildForm(false, null);
  mantOpen(titulo, html);
}

async function mantEditRow(id) {
  if (!window.__mant) return;
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


async function mantAjaxSubmit(e, url, rowId) {
  e.preventDefault();
  const form = e.target;
  const btn  = form.querySelector('#mant-save-btn');
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  try {
    const formData = new FormData(form);
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    });
    const json = await res.json();

    if (json.ok) {
      mantClose();
      showToast(json.msg || 'Guardado exitosamente.', 'ok');
      // Refrescar tabla sin recargar la página completa
      _mantRefreshTable();
    } else {
      showToast(json.msg || 'Error al guardar.', 'error');
      btn.disabled = false;
      btn.textContent = rowId > 0 ? 'Actualizar' : 'Guardar';
    }
  } catch(err) {
    showToast('Error de conexión.', 'error');
    btn.disabled = false;
    btn.textContent = rowId > 0 ? 'Actualizar' : 'Guardar';
  }
}

async function _mantRefreshTable() {
  try {
    const r = await fetch(window.location.href, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
      credentials: 'same-origin'
    });
    if (!r.ok) return;
    const html = await r.text();
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const newTable = doc.querySelector('.mant-table-wrap, .table-wrap, [data-mant-wrap]');
    const curTable = document.querySelector('.mant-table-wrap, .table-wrap, [data-mant-wrap]');
    if (newTable && curTable) {
      curTable.innerHTML = newTable.innerHTML;
    }
    const newScript = doc.querySelector('script[data-mant]');
    if (newScript) {
      try { eval(newScript.textContent); } catch(se) { /* silent */ }
    }
  } catch(e) { /* silent fail */ }
}

if (window.__mantData) {
  const d = window.__mantData;
  renderMantenimiento(d.titulo, d.campos, d.filas, d.columnas, d.editId, d.msg);
}
</script>

</body>
</html>
