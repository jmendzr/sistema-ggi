<?php
// modules/alertas/index.php — Panel de alertas de vencimiento
$page_title  = 'Alertas de vencimiento';
$active_menu = 'alertas';
require_once __DIR__ . '/../../templates/layout.php';
require_role('gerente_general', 'gerente_operaciones');

$pdo = Database::get();

// Cotizaciones enviadas con días transcurridos
$cots_seguimiento = $pdo->query("
    SELECT cot.id, cot.numero, cot.fecha_emision,
           DATEDIFF(CURDATE(), cot.fecha_emision) AS dias,
           COALESCE(cl.razon_social, cot.empresa_nombre) AS empresa,
           cot.monto_total, cot.moneda,
           ej.nombre AS ej_nombre, ej.apellido AS ej_apellido,
           COALESCE(seg_count.total,0) AS total_seg,
           seg_last.created_at AS ultimo_seg
    FROM cotizaciones cot
    LEFT JOIN clientes cl ON cl.id = cot.cliente_id
    LEFT JOIN usuarios ej ON ej.id = cot.ejecutivo_id
    LEFT JOIN (SELECT cotizacion_id, COUNT(*) AS total FROM cotizacion_seguimientos GROUP BY cotizacion_id) seg_count ON seg_count.cotizacion_id = cot.id
    LEFT JOIN cotizacion_seguimientos seg_last ON seg_last.id = (SELECT id FROM cotizacion_seguimientos WHERE cotizacion_id=cot.id ORDER BY created_at DESC LIMIT 1)
    WHERE cot.estado = 'enviada'
      AND cot.fecha_emision IS NOT NULL
    ORDER BY dias DESC
    LIMIT 50
")->fetchAll();
$total_cot_enviadas = count($cots_seguimiento);
$cot_sin_seg = count(array_filter($cots_seguimiento, fn($c) => $c['total_seg'] == 0));

// Certificados próximos con estado de alerta enviada
$certs = $pdo->query("
    SELECT
        c.id, c.numero_certificado, c.fecha_vencimiento,
        DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias,
        n.codigo AS norma,
        cc.nombre AS casa,
        cl.razon_social, cl.contacto_nombre, cl.contacto_apellido, cl.contacto_email,
        cl.contacto_telefono,
        ae90.enviado_at AS alerta_90,
        ae60.enviado_at AS alerta_60,
        ae30.enviado_at AS alerta_30
    FROM certificados c
    JOIN normas_iso n            ON n.id  = c.norma_iso_id
    JOIN casas_certificadoras cc ON cc.id = c.casa_certificadora_id
    JOIN clientes cl             ON cl.id = c.cliente_id
    LEFT JOIN alertas_email ae90 ON ae90.certificado_id = c.id AND ae90.tipo_alerta = '90_dias'
    LEFT JOIN alertas_email ae60 ON ae60.certificado_id = c.id AND ae60.tipo_alerta = '60_dias'
    LEFT JOIN alertas_email ae30 ON ae30.certificado_id = c.id AND ae30.tipo_alerta = '30_dias'
    WHERE c.estado IN ('vigente','por_vencer')
      AND c.fecha_vencimiento > CURDATE()
      AND c.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)
    ORDER BY c.fecha_vencimiento ASC
")->fetchAll();

// Stats rápidas
$total_pendientes = $pdo->query("SELECT COUNT(*) FROM certificados WHERE estado IN ('vigente','por_vencer') AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetchColumn();
$alertas_enviadas = $pdo->query("SELECT COUNT(*) FROM alertas_email WHERE DATE(enviado_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$sin_email        = $pdo->query("SELECT COUNT(*) FROM certificados c JOIN clientes cl ON cl.id=c.cliente_id WHERE c.estado IN ('vigente','por_vencer') AND c.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND (cl.contacto_email IS NULL OR cl.contacto_email='')")->fetchColumn();

// Último log de alertas
$ultimo_log = '';
$log_file   = __DIR__ . '/../../storage/logs/alertas_' . date('Y-m') . '.log';
if (file_exists($log_file)) {
    $lines = array_filter(array_slice(file($log_file), -8));
    $ultimo_log = implode('', $lines);
}
?>
<style>
.stat-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:24px; }
.stat-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); padding:18px 20px; }
.stat-val { font-family:'Inter',sans-serif; font-size:28px; font-weight:700; color:var(--azul); line-height:1; }
.stat-lbl { font-size:12px; color:var(--gris-texto); margin-top:5px; }
.table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; margin-bottom:20px; }
.table-scroll { overflow-x:auto; }
.data-table { min-width:700px; } /* base (width/th/td/hover) → canónica en design-system.css */
.tick { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; }
.tick.ok  { background:var(--s-ok-bg); color:var(--s-ok-tx); }
.tick.pend{ background:var(--bg-surface2); color:#9ca3af; }
.dias-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.log-box { background:#1a1f36; border-radius:10px; padding:16px; font-family:monospace; font-size:11px; color:#9ca3af; white-space:pre-wrap; line-height:1.6; max-height:180px; overflow-y:auto; }
.test-btn { font-size:11px; }
.sin-email { color:#dc2626; font-size:11px; font-style:italic; }
@media(max-width:768px){ .stat-row{grid-template-columns:1fr 1fr} }
@media(max-width:480px){ .stat-row{grid-template-columns:1fr} }
</style>

<div class="page-header" style="margin-bottom:20px">
  <div>
    <h1>Alertas de vencimiento</h1>
    <p>Monitor de emails automáticos enviados al vencer certificados</p>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="<?= APP_URL ?>/modules/alertas/email_preview.php" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      Personalizar email
    </a>
    <button type="button" id="btn-ejecutar" onclick="ejecutarAlertas()" class="btn btn-primary btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      Ejecutar ahora
    </button>
  </div>
</div>

<!-- Modal resultado ejecución -->
<div id="modal-exec" class="ggi-modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ggi-modal-box" style="max-width:520px;">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title" style="color:#16a34a;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#16a34a" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
        Ejecución completada
      </h3>
      <button type="button" class="ggi-modal-close" onclick="document.getElementById('modal-exec').classList.remove('open')">✕</button>
    </div>
    <div class="ggi-modal-body">
      <pre id="modal-exec-log" style="background:#1a1f36;color:#a5f3fc;font-size:11px;border-radius:8px;padding:14px;white-space:pre-wrap;max-height:300px;overflow-y:auto;font-family:monospace;line-height:1.6"></pre>
    </div>
    <div class="ggi-modal-footer">
      <button onclick="document.getElementById('modal-exec').classList.remove('open')" class="ggi-btn-confirm-action" style="width:100%;">Cerrar</button>
    </div>
  </div>
</div>

<script>
async function ejecutarAlertas() {
  const btn = document.getElementById('btn-ejecutar');
  btn.disabled = true;
  btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Ejecutando...';

  try {
    const res  = await fetch('<?= APP_URL ?>/modules/alertas/ejecutar.php');
    const text = await res.text();
    document.getElementById('modal-exec-log').textContent = text;
    document.getElementById('modal-exec').classList.add('open');
  } catch(e) {
    showToast('Error al ejecutar: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Ejecutar ahora';
  }
}
</script>
<style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
<!-- Stats -->
<div class="stat-row">
  <div class="stat-card">
    <div class="stat-val"><?= $total_pendientes ?></div>
    <div class="stat-lbl">Certificados en ventana 90 días</div>
  </div>
  <div class="stat-card">
    <div class="stat-val" style="color:#16a34a"><?= $alertas_enviadas ?></div>
    <div class="stat-lbl">Alertas enviadas (últimos 30 días)</div>
  </div>
  <div class="stat-card">
    <div class="stat-val" style="color:<?= $sin_email>0?'#dc2626':'#16a34a' ?>"><?= $sin_email ?></div>
    <div class="stat-lbl">Sin email de contacto configurado</div>
  </div>
  <div class="stat-card">
    <div class="stat-val" style="color:#3B82F6"><?= $total_cot_enviadas ?></div>
    <div class="stat-lbl">Cotizaciones enviadas (sin cerrar)</div>
  </div>
</div>

<!-- Tabla de certificados próximos -->
<div class="table-wrap">
  <div class="table-scroll">
  <?php if (empty($certs)): ?>
  <div style="text-align:center;padding:40px;color:var(--gris-texto);font-size:13px;">
    <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="display:block;margin:0 auto 10px;opacity:.3"><path d="M20 6L9 17l-5-5"/></svg>
    No hay certificados próximos a vencer en los próximos 120 días
  </div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th scope="col">Empresa</th>
        <th scope="col">Norma</th>
        <th scope="col">Vence</th>
        <th scope="col">Días</th>
        <th scope="col">Contacto</th>
        <th scope="col" style="text-align:center">Alerta 90d</th>
        <th scope="col" style="text-align:center">Alerta 60d</th>
        <th scope="col" style="text-align:center">Alerta 30d</th>
        <th scope="col" style="text-align:center">WhatsApp</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($certs as $c):
      $dias = (int)$c['dias'];
      $dc   = $dias <= 30 ? '#dc2626' : ($dias <= 60 ? '#d97706' : ($dias <= 90 ? '#1A3A8F' : '#6b7280'));
      $bg   = $dias <= 30 ? 'var(--s-err-bg)' : ($dias <= 60 ? 'var(--s-warn-bg)' : ($dias <= 90 ? '#dbeafe' : '#f3f4f6'));
      $nombre_contacto = trim($c['contacto_nombre'].' '.$c['contacto_apellido']);

      // Construir mensaje WhatsApp
      $fecha_vence = date('d/m/Y', strtotime($c['fecha_vencimiento']));
      $msg_wa = rawurlencode(
          "Estimado/a {$nombre_contacto},\n\n" .
          "Le informamos que su certificado *{$c['norma']}* de la empresa *{$c['razon_social']}* " .
          "vencerá el *{$fecha_vence}* (en {$dias} días).\n\n" .
          "Para coordinar el proceso de renovación, comuníquese con nosotros.\n\n" .
          "Group Global ISO S.A.C."
      );
      // Limpiar teléfono: solo dígitos, agregar código país 51 (Perú)
      $tel_raw = preg_replace('/\D/', '', $c['contacto_telefono'] ?? '');
      if (strlen($tel_raw) === 9) $tel_raw = '51' . $tel_raw;
      $wa_url = $tel_raw ? "https://wa.me/{$tel_raw}?text={$msg_wa}" : '';
    ?>
      <tr>
        <td style="font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($c['razon_social']) ?></td>
        <td><strong style="color:var(--azul)"><?= htmlspecialchars($c['norma']) ?></strong></td>
        <td style="white-space:nowrap;font-weight:600"><?= date('d/m/Y', strtotime($c['fecha_vencimiento'])) ?></td>
        <td>
          <span class="dias-badge" style="background:<?= $bg ?>;color:<?= $dc ?>"><?= $dias ?>d</span>
        </td>
        <td>
          <?php if ($c['contacto_email']): ?>
          <div style="font-size:12px;font-weight:600"><?= htmlspecialchars($nombre_contacto) ?></div>
          <div style="font-size:11px;color:var(--gris-texto)"><?= htmlspecialchars($c['contacto_email']) ?></div>
          <?php else: ?>
          <span class="sin-email">Sin email registrado</span>
          <?php endif; ?>
          <?php if ($c['contacto_telefono']): ?>
          <div style="font-size:11px;color:var(--gris-texto);margin-top:2px"><?= htmlspecialchars($c['contacto_telefono']) ?></div>
          <?php endif; ?>
        </td>
        <?php foreach (['alerta_90','alerta_60','alerta_30'] as $campo): ?>
        <td style="text-align:center">
          <?php if ($c[$campo]): ?>
          <span class="tick ok" title="Enviada el <?= date('d/m/Y', strtotime($c[$campo])) ?>">
            <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
          </span>
          <?php else: ?>
          <span class="tick pend" title="No enviada aún">—</span>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
        <td style="text-align:center">
          <?php if ($wa_url): ?>
          <a href="<?= $wa_url ?>" target="_blank" rel="noopener"
             title="Enviar alerta por WhatsApp a <?= htmlspecialchars($nombre_contacto) ?>"
             style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:var(--s-ok-bg);color:var(--s-ok-tx);transition:.15s;text-decoration:none"
             onmouseover="this.style.background='#25D366';this.style.color='#fff'"
             onmouseout="this.style.background='var(--s-ok-bg)';this.style.color='var(--s-ok-tx)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>
            </svg>
          </a>
          <?php else: ?>
          <span style="font-size:11px;color:var(--gris-texto)">Sin tel.</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  </div><!-- /table-scroll -->
</div>

<!-- Último log -->
<?php if ($ultimo_log): ?>
<div style="margin-bottom:8px">
  <span style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px">
    Último log de ejecución
  </span>
</div>
<div class="log-box"><?= htmlspecialchars($ultimo_log) ?></div>
<?php endif; ?>

<div style="margin:24px 0 12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
  <div>
    <h2 style="font-size:16px;font-weight:700;color:var(--gris-dark);margin:0;">Cotizaciones enviadas — seguimiento</h2>
    <p style="font-size:12px;color:var(--gris-texto);margin:3px 0 0;"><?= $cot_sin_seg ?> sin ningún seguimiento registrado · vencen a los 7 días de enviadas</p>
  </div>
  <a href="<?= APP_URL ?>/modules/cotizaciones/seguimiento.php" class="btn btn-outline btn-sm">Ver seguimiento completo →</a>
</div>

<div class="table-wrap">
  <div class="table-scroll">
  <?php if (empty($cots_seguimiento)): ?>
  <div style="text-align:center;padding:32px;color:var(--gris-texto);font-size:13px;">No hay cotizaciones enviadas pendientes.</div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th scope="col">Cotización</th>
        <th scope="col">Empresa</th>
        <th scope="col">Monto</th>
        <th scope="col">Ejecutivo</th>
        <th scope="col" style="text-align:center">Días enviada</th>
        <th scope="col" style="text-align:center">Seguimientos</th>
        <th scope="col" style="text-align:center">Acciones</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($cots_seguimiento as $c):
      $dias = (int)$c['dias'];
      $urgencia_color = $dias >= 5 ? '#dc2626' : ($dias >= 2 ? '#d97706' : '#3B82F6');
      $urgencia_bg    = $dias >= 5 ? 'var(--s-err-bg)' : ($dias >= 2 ? 'var(--s-warn-bg)' : '#dbeafe');
    ?>
      <tr>
        <td><span style="font-family:monospace;font-size:12px;font-weight:700;color:var(--azul)"><?= htmlspecialchars($c['numero']) ?></span></td>
        <td style="font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($c['empresa']) ?></td>
        <td style="white-space:nowrap;font-weight:600"><?= number_format($c['monto_total'],2) ?> <span style="font-size:10px;color:var(--gris-texto)"><?= $c['moneda'] ?></span></td>
        <td style="font-size:12px"><?= htmlspecialchars($c['ej_nombre'].' '.$c['ej_apellido']) ?></td>
        <td style="text-align:center">
          <span class="dias-badge" style="background:<?= $urgencia_bg ?>;color:<?= $urgencia_color ?>"><?= $dias ?>d</span>
        </td>
        <td style="text-align:center">
          <?php if ($c['total_seg'] == 0): ?>
          <span style="font-size:11px;color:#dc2626;font-weight:600">Sin seguimiento</span>
          <?php else: ?>
          <span style="font-size:12px;font-weight:600;color:#10B981"><?= $c['total_seg'] ?> ✓</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center">
          <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $c['id'] ?>"
             style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--s-info-bg);color:var(--azul);text-decoration:none;transition:.15s"
             onmouseover="this.style.background='var(--azul)';this.style.color='#fff'"
             onmouseout="this.style.background='var(--s-info-bg)';this.style.color='var(--azul)'"
             title="Ver cotización">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
