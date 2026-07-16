function renderMantenimiento(titulo, campos, filas, columnas, editId, msg) {
  const root    = document.getElementById('mant-root');
  const APP_URL = window.APP_URL;
  const CSRF    = window.CSRF_TOKEN || '';

  // ── Helper: genera un mini-form POST para acciones de fila ──
  function postBtn(accion, id, label, iconSvg, extraClass, confirmMsg) {
    const onclickAttr = confirmMsg
      ? `onclick="if(!confirm(${JSON.stringify(confirmMsg)}))return false"`
      : '';
    return `
      <form method="POST" style="display:inline" ${onclickAttr}>
        <input type="hidden" name="_csrf"   value="${CSRF}">
        <input type="hidden" name="accion"  value="${accion}">
        <input type="hidden" name="id"      value="${id}">
        <button type="submit" class="btn-icon ${extraClass || ''}" title="${label}">
          ${iconSvg}
        </button>
      </form>`;
  }

  // Iconos reutilizables
  const icoEdit   = `<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
  const icoToggle = `<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
  const icoDel    = `<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>`;

  // Alert
  const alertHtml = msg ? `<div class="alert-ok"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>${msg}</div>` : '';

  // Formulario
  const formCampos = campos.map(c => {
    const err = '';
    if (c.type === 'textarea') {
      return `<div class="field"><label>${c.label}${c.required?'<span style="color:var(--red);margin-left:2px">*</span>':''}</label><textarea name="${c.name}" placeholder="${c.placeholder||''}">${c.value||''}</textarea></div>`;
    }
    if (c.type === 'select') {
      const opts = c.options.map(o => `<option value="${o.value}" ${c.value==o.value?'selected':''}>${o.label}</option>`).join('');
      return `<div class="field"><label>${c.label}</label><select name="${c.name}">${opts}</select></div>`;
    }
    return `<div class="field"><label>${c.label}${c.required?'<span style="color:var(--red);margin-left:2px">*</span>':''}</label><input type="${c.inputType||'text'}" name="${c.name}" value="${c.value||''}" placeholder="${c.placeholder||''}"></div>`;
  }).join('');

  // Tabla
  const theads = columnas.map(c => `<th>${c}</th>`).join('') + '<th>Estado</th><th></th>';
  const tbody = filas.length === 0
    ? `<tr><td colspan="${columnas.length+2}" class="empty-state">Sin registros aún.</td></tr>`
    : filas.map(f => {
        const tds         = f.cols.map(c => `<td>${c||'—'}</td>`).join('');
        const badge       = f.activo != 0
          ? `<span class="badge-activo">Activo</span>`
          : `<span class="badge-inactivo">Inactivo</span>`;
        const toggleLabel = f.activo != 0 ? 'Desactivar' : 'Activar';
        return `<tr>
          ${tds}
          <td>${badge}</td>
          <td><div class="action-btns">
            <a href="?accion=editar&id=${f.id}" class="btn-icon" title="Editar">${icoEdit}</a>
            ${postBtn('toggle',   f.id, toggleLabel, icoToggle, '',       `¿${toggleLabel} este registro?`)}
            ${postBtn('eliminar', f.id, 'Eliminar',  icoDel,    'danger', '¿Eliminar este registro? Esta acción no se puede deshacer.')}
          </div></td>
        </tr>`;
      }).join('');

  root.innerHTML = `
    ${alertHtml}
    <div class="mant-wrap">
      <div class="mant-table-card">
        <div class="mant-card-header">
          <div class="mant-card-title">Registros (${filas.length})</div>
          ${editId ? `<a href="?" class="btn btn-outline btn-sm">+ Nuevo</a>` : ''}
        </div>
        <div style="overflow-x:auto">
          <table class="mant-table">
            <thead><tr>${theads}</tr></thead>
            <tbody>${tbody}</tbody>
          </table>
        </div>
      </div>
      <div class="mant-form-card">
        <div class="mant-card-header">
          <div class="mant-card-title">${titulo}</div>
        </div>
        <div class="mant-form-body">
          <form method="POST">
            <input type="hidden" name="_csrf"  value="${CSRF}">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id"     value="${editId||''}">
            ${formCampos}
            <div class="form-actions">
              <button type="submit" class="btn btn-primary btn-sm">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                ${editId ? 'Actualizar' : 'Guardar'}
              </button>
              ${editId ? `<a href="?" class="btn btn-outline btn-sm">Cancelar</a>` : ''}
            </div>
          </form>
        </div>
      </div>
    </div>`;
}
