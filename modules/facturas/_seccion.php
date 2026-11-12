<?php
/**
 * modules/facturas/_seccion.php — Sección embebible de facturas de un cliente.
 *
 * Requiere que $cliente_id y $pdo estén definidos en el scope de inclusión.
 * Solo visible para gerente_general y gerente_operaciones.
 *
 * Uso:
 *   include __DIR__ . '/../../modules/facturas/_seccion.php';
 */

if (!has_role('gerente_general', 'gerente_operaciones', 'administracion_finanzas')) return;

$_facturas = $pdo->prepare("
    SELECT f.*, u.nombre AS subido_nombre, u.apellido AS subido_apellido
    FROM facturas_cliente f
    LEFT JOIN usuarios u ON u.id = f.subido_por
    WHERE f.cliente_id = ?
    ORDER BY f.created_at DESC
");
$_facturas->execute([$cliente_id]);
$_lista_facturas = $_facturas->fetchAll(\PDO::FETCH_ASSOC);
?>

<div class="card" id="seccion-facturas" style="margin-top:24px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="width:36px;height:36px;background:var(--s-info-bg);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="#1D4ED8" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/>
          <line x1="16" y1="17" x2="8" y2="17"/>
          <polyline points="10 9 9 9 8 9"/>
        </svg>
      </div>
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:700;color:var(--gris-dark);">Facturas</h3>
        <p style="margin:0;font-size:12px;color:var(--gris-texto);"><?= count($_lista_facturas) ?> factura<?= count($_lista_facturas) !== 1 ? 's' : '' ?> registrada<?= count($_lista_facturas) !== 1 ? 's' : '' ?></p>
      </div>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="ggiAbrirSubirFactura()">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Cargar factura
    </button>
  </div>

  <?php if (empty($_lista_facturas)): ?>
  <div style="padding:32px 20px;text-align:center;color:var(--gris-texto);">
    <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="opacity:.4;margin-bottom:10px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <p style="font-size:13px;margin:0;">Aún no se han cargado facturas para este cliente.</p>
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;min-width:500px;">
      <thead>
        <tr style="background:var(--bg-surface2);">
          <th scope="col" style="padding:9px 14px;font-size:10px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--gris-borde);">N° Factura</th>
          <th scope="col" style="padding:9px 14px;font-size:10px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--gris-borde);">Fecha</th>
          <th scope="col" style="padding:9px 14px;font-size:10px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--gris-borde);">Subido por</th>
          <th scope="col" style="padding:9px 14px;font-size:10px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;text-align:center;border-bottom:1px solid var(--gris-borde);">WhatsApp</th>
          <th scope="col" style="padding:9px 14px;font-size:10px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--gris-borde);"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($_lista_facturas as $_f): ?>
        <tr style="border-bottom:1px solid var(--bg-surface2);">
          <td style="padding:11px 14px;font-size:13px;font-weight:600;color:var(--gris-dark);font-family:monospace;">
            <?= sanitize($_f['numero_factura']) ?>
          </td>
          <td style="padding:11px 14px;font-size:12px;color:var(--gris-texto);">
            <?= formato_fecha($_f['created_at']) ?>
          </td>
          <td style="padding:11px 14px;font-size:12px;color:var(--gris-texto);">
            <?= sanitize($_f['subido_nombre'] . ' ' . $_f['subido_apellido']) ?>
          </td>
          <td style="padding:11px 14px;text-align:center;">
            <?php if ($_f['wa_enviado']): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;background:var(--s-ok-bg);color:var(--s-ok-tx);border-radius:100px;font-size:10px;font-weight:700;padding:3px 8px;">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="#22C55E"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
              Enviado
            </span>
            <?php else: ?>
            <span style="font-size:11px;color:var(--gris-texto);">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:11px 14px;text-align:right;">
            <a href="<?= APP_URL ?>/<?= sanitize($_f['archivo_path']) ?>"
               target="_blank"
               class="btn-ic btn-ic-blue"
               title="Ver PDF">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Modal subir factura -->
<div id="modal-factura" class="ggi-modal-overlay" onclick="if(event.target===this)ggiCerrarFactura()">
  <div class="ggi-modal-box" style="max-width:440px;">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">Cargar factura</h3>
      <button type="button" class="ggi-modal-close" onclick="ggiCerrarFactura()">✕</button>
    </div>

    <form id="form-factura" onsubmit="ggiSubirFactura(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="cliente_id" value="<?= $cliente_id ?>">

      <div class="ggi-modal-body">
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:12px;font-weight:600;color:var(--gris-dark);margin-bottom:5px;">N° de Factura <span class="req">*</span></label>
          <input type="text" name="numero_factura" required placeholder="Ej: F001-00123"
                 style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--gris-borde);border-radius:8px;font-size:13px;">
        </div>

        <div style="margin-bottom:20px;">
          <label style="display:block;font-size:12px;font-weight:600;color:var(--gris-dark);margin-bottom:5px;">Archivo PDF <span class="req">*</span></label>
          <input type="file" name="factura" accept=".pdf,application/pdf" required
                 style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid var(--gris-borde);border-radius:8px;font-size:13px;background:var(--bg-surface2);">
          <p style="margin:4px 0 0;font-size:11px;color:var(--gris-texto);">Solo PDF · máx. 10 MB</p>
        </div>

        <div id="factura-msg" style="display:none;margin-bottom:14px;padding:10px 12px;border-radius:8px;font-size:13px;"></div>
      </div>

      <div class="ggi-modal-footer">
        <button type="button" class="ggi-btn-confirm-cancel" onclick="ggiCerrarFactura()">Cancelar</button>
        <button type="submit" id="btn-subir-factura" class="ggi-btn-confirm-action">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
          Cargar y enviar por WhatsApp
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function ggiAbrirSubirFactura() {
  document.getElementById('modal-factura').classList.add('open');
}
function ggiCerrarFactura() {
  document.getElementById('modal-factura').classList.remove('open');
  document.getElementById('form-factura').reset();
  document.getElementById('factura-msg').style.display = 'none';
}
async function ggiSubirFactura(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-subir-factura');
  const msg = document.getElementById('factura-msg');
  btn.disabled = true;
  btn.textContent = 'Enviando…';
  msg.style.display = 'none';

  try {
    const fd = new FormData(e.target);
    const res = await fetch('<?= APP_URL ?>/modules/facturas/subir.php', { method: 'POST', body: fd });
    const data = await res.json();

    msg.style.display = 'block';
    if (data.ok) {
      msg.style.background = 'var(--s-ok-bg)'; msg.style.color = 'var(--s-ok-tx)'; msg.style.border = '1px solid var(--s-ok-brd)';
      msg.textContent = data.msg;
      setTimeout(() => { ggiCerrarFactura(); location.reload(); }, 2000);
    } else {
      msg.style.background = 'var(--s-err-bg)'; msg.style.color = 'var(--s-err-tx)'; msg.style.border = '1px solid var(--s-err-brd)';
      msg.textContent = data.msg || 'Error al cargar la factura';
      btn.disabled = false;
      btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg> Cargar y enviar por WhatsApp';
    }
  } catch {
    msg.style.display = 'block';
    msg.style.background = 'var(--s-err-bg)'; msg.style.color = '#DC2626'; msg.style.border = '1px solid var(--s-err-brd)';
    msg.textContent = 'Error de conexión. Intenta de nuevo.';
    btn.disabled = false;
    btn.innerHTML = 'Cargar y enviar por WhatsApp';
  }
}
</script>
