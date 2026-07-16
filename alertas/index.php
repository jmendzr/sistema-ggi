<?php
// modules/alertas/index.php — Panel de alertas de vencimiento
$page_title  = 'Alertas de vencimiento';
$active_menu = 'alertas';
require_once __DIR__ . '/../../templates/layout.php';
require_role('gerente_general', 'gerente_operaciones');

$pdo = Database::get();

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
.stat-row { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:24px; }
.stat-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); padding:18px 20px; }
.stat-val { font-family:'Outfit',sans-serif; font-size:28px; font-weight:700; color:var(--azul); line-height:1; }
.stat-lbl { font-size:12px; color:var(--gris-texto); margin-top:5px; }
.table-wrap { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; margin-bottom:20px; }
.data-table { width:100%; border-collapse:collapse; }
.data-table th { font-size:10px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.5px; padding:10px 14px; text-align:left; background:#fafbfe; border-bottom:1px solid var(--gris-borde); white-space:nowrap; }
.data-table td { padding:11px 14px; font-size:13px; border-bottom:1px solid #f0f2f8; color:var(--gris-dark); vertical-align:middle; }
.data-table tr:last-child td { border-bottom:none; }
.tick { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; }
.tick.ok  { background:#d1fae5; color:#16a34a; }
.tick.pend{ background:#f0f2f8; color:#9ca3af; }
.dias-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.log-box { background:#1a1f36; border-radius:10px; padding:16px; font-family:monospace; font-size:11px; color:#9ca3af; white-space:pre-wrap; line-height:1.6; max-height:180px; overflow-y:auto; }
.test-btn { font-size:11px; }
.sin-email { color:#dc2626; font-size:11px; font-style:italic; }
@media(max-width:768px){ .stat-row{grid-template-columns:1fr 1fr} }
</style>

<div class="page-header" style="margin-bottom:20px">

  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="<?= APP_URL ?>/modules/alertas/email_preview.php" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      Personalizar email
    </a>
    <a href="<?= APP_URL ?>/cron/alertas_vencimiento.php?token=<?= defined('CRON_TOKEN') ? CRON_TOKEN : '' ?>"
       target="_blank" class="btn btn-primary btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      Ejecutar ahora
    </a>
  </div>
</div>

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
</div>

<!-- Tabla de certificados próximos -->
<div class="table-wrap">
  <?php if (empty($certs)): ?>
  <div style="text-align:center;padding:40px;color:var(--gris-texto);font-size:13px;">
    <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="display:block;margin:0 auto 10px;opacity:.3"><path d="M20 6L9 17l-5-5"/></svg>
    No hay certificados próximos a vencer en los próximos 120 días
  </div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th>Empresa</th>
        <th>Norma</th>
        <th>Vence</th>
        <th>Días</th>
        <th>Contacto</th>
        <th style="text-align:center">Alerta 90d</th>
        <th style="text-align:center">Alerta 60d</th>
        <th style="text-align:center">Alerta 30d</th>
        <th style="text-align:center">WhatsApp</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($certs as $c):
      $dias = (int)$c['dias'];
      $dc   = $dias <= 30 ? '#dc2626' : ($dias <= 60 ? '#d97706' : ($dias <= 90 ? '#1A3A8F' : '#6b7280'));
      $bg   = $dias <= 30 ? '#fee2e2' : ($dias <= 60 ? '#fef3c7' : ($dias <= 90 ? '#dbeafe' : '#f3f4f6'));
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
             style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:#d1fae5;color:#15803d;transition:.15s;text-decoration:none"
             onmouseover="this.style.background='#25D366';this.style.color='#fff'"
             onmouseout="this.style.background='#d1fae5';this.style.color='#15803d'">
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

<!-- Instrucciones cron -->
<div style="background:#fff;border-radius:12px;border:1px solid var(--gris-borde);padding:18px 20px;margin-top:20px">
  <div style="font-size:13px;font-weight:700;color:var(--azul);margin-bottom:10px">
    Configuración del cron en cPanel
  </div>
  <div style="font-size:12px;color:var(--gris-texto);margin-bottom:8px">
    Agrega esta tarea en cPanel → Cron Jobs para que se ejecute todos los días a las 9 AM:
  </div>
  <div style="background:#1a1f36;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:12px;color:#a5f3fc">
    0 9 * * * php /home/groupglo/domains/sistema.groupglobaliso.com/public_html/cron/alertas_vencimiento.php
  </div>
  <div style="font-size:11px;color:var(--gris-texto);margin-top:10px">
    El sistema evita automáticamente reenviar la misma alerta — puedes ejecutarla varias veces sin problema.
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
