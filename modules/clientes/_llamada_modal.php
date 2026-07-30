<?php
/**
 * modules/clientes/_llamada_modal.php — Partial reutilizable de agenda de llamadas
 *
 * Cada módulo lo incluye con SU contexto (el modal abre en sitio, sin redirigir),
 * pero todas las llamadas van a la misma tabla llamadas_programadas → historial único
 * (la ficha del cliente siempre las muestra todas, vengan de donde vengan).
 *
 * Variables esperadas antes del include:
 *   $_llam_cliente_id  int    — cliente al que se llamará (requerido)
 *   $_llam_cot_id      int    — cotización ligada (0 si no aplica)
 *   $_llam_empresa     string — razón social a mostrar en el modal
 *   $_llam_horario     ?string— preferencia horario_contacto del cliente
 *   $_llam_abiertas    array  — llamadas abiertas a listar en ESTE contexto
 *                               (cada fila: id, fecha_hora, asignado_nombre, nota)
 */
$_llam_cliente_id = (int)($_llam_cliente_id ?? 0);
$_llam_cot_id     = (int)($_llam_cot_id ?? 0);
$_llam_empresa    = $_llam_empresa ?? '';
$_llam_horario    = $_llam_horario ?? '';
$_llam_abiertas   = $_llam_abiertas ?? [];
if (!$_llam_cliente_id) return;
?>

<?php if (!empty($_llam_abiertas)): ?>
<!-- Llamadas programadas abiertas (contexto actual) -->
<div id="llamadas-box" style="margin:0 0 16px;padding:12px 15px;border:1px solid var(--s-info-brd,#bfdbfe);background:var(--s-info-bg,#eff6ff);border-radius:11px;">
  <div style="font-size:11px;font-weight:700;color:var(--azul);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.62 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
    Llamadas programadas
  </div>
  <?php foreach ($_llam_abiertas as $_ll):
    $_vencida = strtotime($_ll['fecha_hora']) < time(); ?>
  <div id="llamada-row-<?= $_ll['id'] ?>" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:6px 0;border-top:1px solid rgba(0,0,0,.04);">
    <strong style="font-size:13px;color:<?= $_vencida ? 'var(--s-err,#dc2626)' : 'var(--gris-dark)' ?>;">
      <?= date('d/m/Y H:i', strtotime($_ll['fecha_hora'])) ?><?= $_vencida ? ' · vencida' : '' ?>
    </strong>
    <span style="font-size:12px;color:var(--gris-texto);">→ <?= sanitize($_ll['asignado_nombre']) ?></span>
    <?php if (!empty($_ll['nota'])): ?><span style="font-size:12px;color:var(--gris-texto);flex:1;min-width:120px;"><?= sanitize($_ll['nota']) ?></span><?php endif; ?>
    <span style="margin-left:auto;display:flex;gap:6px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="ggiLlamadaAccion(<?= $_ll['id'] ?>,'realizar')" style="font-size:11px;padding:3px 10px;color:var(--s-ok,#059669);">✓ Realizada</button>
      <button type="button" class="btn btn-outline btn-sm" onclick="ggiLlamadaAccion(<?= $_ll['id'] ?>,'cancelar')" style="font-size:11px;padding:3px 10px;">Cancelar</button>
    </span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Modal: agendar llamada con recordatorio push ── -->
<div class="ggi-modal-overlay" id="llamada-modal">
  <div class="ggi-modal-box" style="max-width:430px;" role="dialog" aria-modal="true" aria-label="Agendar llamada">
    <div class="ggi-modal-header"><h3 style="font-size:15px;margin:0;">📞 Agendar llamada</h3></div>
    <div class="ggi-modal-body">
      <p style="font-size:12.5px;color:var(--gris-texto);margin:0 0 12px;">
        Recibirás una notificación push antes de la hora acordada con <strong><?= sanitize($_llam_empresa) ?></strong><?= $_llam_cot_id ? ' (ligada a esta propuesta)' : '' ?>.
      </p>
      <?php if ($_llam_horario !== ''): ?>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:9px 12px;border:1px solid var(--s-warn-brd,#fcd34d);background:var(--s-warn-bg,#fef3c7);border-radius:9px;font-size:12.5px;color:var(--s-warn-tx,#92400e);">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Preferencia del cliente: <strong><?= sanitize($_llam_horario) ?></strong>
      </div>
      <?php endif; ?>
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:5px;">Fecha y hora acordada <span style="color:var(--s-err);">*</span></label>
        <input type="datetime-local" id="llamada-fecha" style="width:100%;padding:10px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:16px;font-family:inherit;background:var(--bg-surface);color:var(--gris-dark);">
      </div>
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:5px;">Avisarme antes</label>
        <select id="llamada-aviso" style="width:100%;padding:10px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:16px;font-family:inherit;background:var(--bg-surface);color:var(--gris-dark);">
          <option value="5">5 minutos antes</option>
          <option value="10">10 minutos antes</option>
          <option value="15" selected>15 minutos antes</option>
          <option value="30">30 minutos antes</option>
          <option value="60">1 hora antes</option>
        </select>
      </div>
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:5px;">Nota (motivo de la llamada)</label>
        <textarea id="llamada-nota" maxlength="500" rows="2" placeholder="Ej.: quiere hablar de la renovación ISO 9001" style="width:100%;padding:10px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:16px;font-family:inherit;resize:vertical;background:var(--bg-surface);color:var(--gris-dark);"></textarea>
      </div>
    </div>
    <div class="ggi-modal-footer">
      <button type="button" class="ggi-btn-confirm-cancel" onclick="ggiCerrarLlamadaModal()">Cancelar</button>
      <button type="button" class="ggi-btn-confirm-action" id="llamada-guardar" onclick="ggiGuardarLlamada()">Agendar</button>
    </div>
  </div>
</div>

<script>
const LLAMADA_CLIENTE_ID = <?= $_llam_cliente_id ?>;
const LLAMADA_COT_ID     = <?= $_llam_cot_id ?>;

function ggiAbrirLlamada() {
  const m = document.getElementById('llamada-modal');
  // Mínimo: dentro de 10 min; default sugerido: mañana 9:00
  const inp = document.getElementById('llamada-fecha');
  const min = new Date(Date.now() + 10 * 60000);
  const pad = n => String(n).padStart(2, '0');
  const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  inp.min = fmt(min);
  if (!inp.value) {
    const man = new Date(); man.setDate(man.getDate() + 1); man.setHours(9, 0, 0, 0);
    inp.value = fmt(man);
  }
  m.classList.add('open');
}
function ggiCerrarLlamadaModal() {
  document.getElementById('llamada-modal').classList.remove('open');
}
document.getElementById('llamada-modal').addEventListener('click', function(e) {
  if (e.target === this) ggiCerrarLlamadaModal();
});

async function ggiGuardarLlamada() {
  const fecha = document.getElementById('llamada-fecha').value;
  if (!fecha) { showToast('Indica fecha y hora.', 'warning'); return; }
  const btn = document.getElementById('llamada-guardar');
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('accion', 'crear');
    fd.append('cliente_id', LLAMADA_CLIENTE_ID);
    if (LLAMADA_COT_ID) fd.append('cotizacion_id', LLAMADA_COT_ID);
    fd.append('fecha_hora', fecha.replace('T', ' '));
    fd.append('aviso_min', document.getElementById('llamada-aviso').value);
    fd.append('nota', document.getElementById('llamada-nota').value.trim());
    const d = await ggiPost('<?= APP_URL ?>/modules/clientes/ajax_llamada.php', fd, { silent: true });
    if (d.ok) {
      showToast(d.mensaje || 'Llamada agendada.', 'ok');
      ggiCerrarLlamadaModal();
      if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
      setTimeout(() => location.reload(), 700); // refrescar el bloque de llamadas
    } else {
      showToast(d.mensaje || 'No se pudo agendar.', 'error');
    }
  } catch (e) {
    showToast('Error de conexión.', 'error');
  }
  btn.disabled = false;
}

async function ggiLlamadaAccion(id, accion) {
  if (accion === 'cancelar') {
    const ok = await ggiConfirm('Cancelar llamada', '¿Cancelar esta llamada programada? No se enviará ningún recordatorio.', { type: 'warning', confirmText: 'Sí, cancelar' });
    if (!ok) return;
  }
  try {
    const fd = new FormData();
    fd.append('accion', accion);
    fd.append('llamada_id', id);
    const d = await ggiPost('<?= APP_URL ?>/modules/clientes/ajax_llamada.php', fd, { silent: true });
    if (d.ok) {
      showToast(d.mensaje, 'ok');
      // Quitar la fila de la cajita superior (llamadas abiertas)
      document.getElementById('llamada-row-' + id)?.remove();
      // Si la cajita se quedó sin filas, ocultarla (no dejar el recuadro vacío)
      const box = document.getElementById('llamadas-box');
      if (box && !box.querySelector('[id^="llamada-row-"]')) box.style.display = 'none';
      // Si está abierto el tab "Llamadas", recargarlo para reflejar el nuevo estado
      if (document.getElementById('tab-llamadas') && typeof window.reloadTab === 'function') {
        window.reloadTab('llamadas');
      }
      if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
    } else {
      showToast(d.mensaje || 'No se pudo actualizar.', 'error');
    }
  } catch (e) {
    showToast('Error de conexión.', 'error');
  }
}
</script>
