<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/envios/index.php'); exit; }

$pdo = Database::get();

$stmt = $pdo->prepare("
    SELECT e.*, cl.razon_social, cl.codigo_interno, cl.ruc, cl.direccion AS dir_fiscal,
           COALESCE(ag.nombre, 'Por asignar') AS agencia, ag.telefono AS agencia_tel, ag.web AS agencia_web,
           ag.sede_modo AS agencia_sede_modo,
           LOWER(ag.nombre) AS agencia_lower,
           es.nombre AS estado_nombre, es.color_hex,
           u.nombre AS registrado_por_nombre,
           agt.nombre AS agente_nombre, agt.apellido AS agente_apellido
    FROM envios e
    JOIN clientes cl             ON cl.id = e.cliente_id
    LEFT JOIN agencias_envio ag  ON ag.id = e.agencia_envio_id
    JOIN estados_envio es        ON es.id = e.estado_envio_id
    JOIN usuarios u              ON u.id  = e.registrado_por
    LEFT JOIN usuarios agt       ON agt.id = e.agente_id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$envio = $stmt->fetch();
if (!$envio) { header('Location: ' . APP_URL . '/modules/envios/index.php'); exit; }

// Al revisar el envío, marcar leídas las notificaciones informativas de su cliente.
require_once __DIR__ . '/../../core/NotificationService.php';
NotificationService::markSeen((int)auth_user()['id'], (int)$envio['cliente_id']);

$certs_stmt = $pdo->prepare("
    SELECT c.id, c.numero_certificado, c.estado,
           n.codigo AS norma_codigo,
           cc.nombre AS casa_certificadora
    FROM envios_certificados ec
    JOIN certificados c          ON c.id  = ec.certificado_id
    JOIN normas_iso n            ON n.id  = c.norma_iso_id
    JOIN casas_certificadoras cc ON cc.id = c.casa_certificadora_id
    WHERE ec.envio_id = ?
");
$certs_stmt->execute([$id]);
$certificados = $certs_stmt->fetchAll();

$user       = auth_user();
$is_olva    = str_contains($envio['agencia_lower'] ?? '', 'olva');
$is_shalom  = str_contains($envio['agencia_lower'] ?? '', 'shalom');
$is_serpost = str_contains($envio['agencia_lower'] ?? '', 'serpost');
$has_code   = !empty($envio['codigo_envio']);
$has_shalom_codes = !empty($envio['numero_orden']) && !empty($envio['codigo_envio']);
$tracking_available = ($is_olva && $has_code) || ($is_shalom && $has_shalom_codes) || ($is_serpost && $has_code);
$tracking_ajax_url  = $is_olva
    ? APP_URL . '/modules/envios/ajax_tracking_olva.php?envio_id='   . $id
    : ($is_serpost
        ? APP_URL . '/modules/envios/ajax_tracking_serpost.php?envio_id=' . $id
        : APP_URL . '/modules/envios/ajax_tracking_shalom.php?envio_id=' . $id);
$tracking_agency       = $is_olva ? 'Olva' : ($is_shalom ? 'Shalom' : ($is_serpost ? 'Serpost' : ''));
$tracking_agency_color = $is_olva ? '#16a34a' : ($is_serpost ? '#1d4ed8' : '#c2410c');

// Gestor logístico puede asumir un envío sin gestor asignado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'asumir_envio') {
    csrf_verify();
    if ($user['rol'] === 'gestor_logistico' && empty($envio['agente_id'])) {
        $pdo->prepare("UPDATE envios SET agente_id=? WHERE id=? AND agente_id IS NULL")
            ->execute([$user['id'], $id]);
        log_actividad('envios', 'asumir', "Gestor #{$user['id']} asumió envío #$id");
    }
    header('Location: ' . APP_URL . '/modules/envios/ver.php?id=' . $id . '&asumido=1');
    exit;
}

$page_title  = 'Detalle de Envío';
$active_menu = 'envios';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  /* ── Layout base ─────────────────────────────────────────────────────────── */
  .env-header      { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); padding:24px 28px; margin-bottom:20px; }
  .info-grid       { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:20px; }
  .info-card       { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); padding:18px 20px; }
  .info-card-label { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
  .info-card-value { font-size:16px; font-weight:700; font-family:'Inter',sans-serif; color:var(--gris-dark); }
  .info-card-sub   { font-size:12px; color:var(--gris-texto); margin-top:2px; }
  .detail-panel    { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; margin-bottom:20px; }
  .detail-panel-header { padding:16px 20px; border-bottom:1px solid var(--gris-borde); font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--gris-dark); display:flex; align-items:center; gap:8px; }
  .detail-row      { display:flex; padding:12px 20px; border-bottom:1px solid var(--bg-surface2); font-size:13px; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label    { font-weight:600; color:var(--gris-texto); width:180px; flex-shrink:0; }
  .detail-value    { color:var(--gris-dark); }
  .cert-badge      { display:inline-flex; align-items:center; gap:6px; background:var(--s-info-bg); color:#475569; border:1px solid #e2e8f0; padding:4px 12px; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none; margin:3px; }
  .cert-badge:hover { background:var(--s-info-bg); }

  /* ── Pestañas ────────────────────────────────────────────────────────────── */
  .tab-nav         { display:flex; gap:4px; margin-bottom:20px; border-bottom:2px solid var(--gris-borde); padding-bottom:0; }
  .tab-btn         { padding:10px 18px; font-size:13px; font-weight:600; color:var(--gris-texto); background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px; cursor:pointer; border-radius:6px 6px 0 0; transition:color .15s, border-color .15s; display:flex; align-items:center; gap:7px; font-family:'Inter',sans-serif; }
  .tab-btn:hover   { color:var(--azul); background:var(--s-info-bg); }
  .tab-btn.active  { color:var(--azul); border-bottom-color:var(--azul); }
  .tab-pane        { display:none; }
  .tab-pane.active { display:block; }

  /* ── Timeline Tracking ───────────────────────────────────────────────────── */
  .trk-wrap        { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; margin-bottom:20px; }
  .trk-header      { padding:18px 22px; border-bottom:1px solid var(--gris-borde); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
  .trk-title       { font-family:'Inter',sans-serif; font-size:15px; font-weight:700; color:var(--gris-dark); display:flex; align-items:center; gap:8px; }
  .trk-meta-grid   { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:0; border-bottom:1px solid var(--gris-borde); }
  .trk-meta-item   { padding:12px 20px; border-right:1px solid var(--gris-borde); font-size:12px; }
  .trk-meta-item:last-child { border-right:none; }
  .trk-meta-label  { color:var(--gris-texto); font-weight:600; text-transform:uppercase; font-size:10px; letter-spacing:.5px; margin-bottom:3px; }
  .trk-meta-val    { color:var(--gris-dark); font-weight:600; font-size:13px; }
  .trk-body        { padding:24px 22px; }
  .trk-estado-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 14px; border-radius:100px; font-size:12px; font-weight:700; }

  /* Timeline */
  .timeline        { position:relative; padding-left:28px; }
  .timeline::before { content:''; position:absolute; left:9px; top:8px; bottom:8px; width:2px; background:var(--gris-borde); border-radius:2px; }
  .tl-item         { position:relative; margin-bottom:20px; }
  .tl-item:last-child { margin-bottom:0; }
  .tl-dot          { position:absolute; left:-28px; top:4px; width:18px; height:18px; border-radius:50%; border:3px solid #fff; box-shadow:0 0 0 2px var(--gris-borde); background:var(--s-info-bg); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .tl-dot.first    { background:var(--azul); box-shadow:0 0 0 2px var(--azul); }
  .tl-dot.entregado { background:#16a34a; box-shadow:0 0 0 2px #16a34a; }
  .tl-dot.devuelto  { background:#dc2626; box-shadow:0 0 0 2px #dc2626; }
  .tl-fecha        { font-size:11px; color:var(--gris-texto); font-weight:600; margin-bottom:3px; }
  .tl-estado       { font-size:13px; font-weight:700; color:var(--gris-dark); font-family:'Inter',sans-serif; }
  .tl-sede         { font-size:12px; color:var(--gris-texto); margin-top:2px; }
  .tl-obs          { font-size:11px; color:#94a3b8; margin-top:3px; line-height:1.4; }

  /* Estado vacío/error */
  .trk-empty       { text-align:center; padding:48px 24px; color:var(--gris-texto); }
  .trk-empty svg   { opacity:.3; margin-bottom:12px; }
  .trk-empty p     { font-size:14px; margin:0; }

  /* Skeleton loader */
  @keyframes shimmer { 0%{background-position:-400px 0} 100%{background-position:400px 0} }
  .skeleton        { border-radius:6px; background: linear-gradient(90deg, var(--bg-surface2) 25%, #e8eaf2 50%, var(--bg-surface2) 75%); background-size:800px 100%; animation:shimmer 1.4s infinite; }

  @media(max-width:768px) {
    .info-grid { grid-template-columns:1fr 1fr; }
    .env-header { padding:16px; }
    .detail-row { flex-direction:column; gap:2px; }
    .detail-label { width:auto; }
    .trk-meta-grid { grid-template-columns:1fr 1fr; }
    .tab-btn { padding:8px 12px; font-size:12px; }
  }
  @media(max-width:480px) { .info-grid { grid-template-columns:1fr; } }
</style>

<?php
ggi_breadcrumb([
    ['label' => 'Empresas', 'href' => APP_URL.'/modules/clientes/index.php',  'ss' => 'ggi_ss_clientes'],
    ['label' => sanitize($envio['razon_social']), 'href' => APP_URL.'/modules/clientes/ver.php?id='.(int)$envio['cliente_id']],
    ['label' => 'Envíos',   'href' => APP_URL.'/modules/envios/index.php',     'ss' => 'ggi_ss_envios'],
    ['label' => sanitize($envio['razon_social']).' — Envío'],
]);
?>

<!-- Header del envío (siempre visible) -->
<div class="env-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
        <span style="background:<?= $envio['color_hex'] ?>22;color:<?= $envio['color_hex'] ?>;border:1px solid <?= $envio['color_hex'] ?>55;padding:3px 12px;border-radius:100px;font-size:12px;font-weight:600;">
          <?= sanitize($envio['estado_nombre']) ?>
        </span>
        <?php foreach ($certificados as $c): ?>
        <span style="background:var(--s-info-bg);color:#475569;border:1px solid #e2e8f0;padding:3px 10px;border-radius:100px;font-size:12px;font-weight:600;">
          <?= sanitize($c['norma_codigo']) ?>
        </span>
        <?php endforeach; ?>
      </div>
      <div style="font-family:'Inter',sans-serif;font-size:20px;font-weight:700;color:var(--gris-dark);">
        <?= sanitize($envio['razon_social']) ?>
      </div>
      <div style="font-size:13px;color:var(--gris-texto);margin-top:4px;">
        RUC: <strong><?= sanitize($envio['ruc']) ?></strong>
        &nbsp;·&nbsp; Agencia: <strong<?= !$envio['agencia_envio_id'] ? ' style="color:var(--s-warn-tx);"' : '' ?>><?= sanitize($envio['agencia']) ?></strong>
        <?php if ($has_code): ?>
        &nbsp;·&nbsp; Guía: <span style="font-family:monospace;color:var(--gris-dark);font-weight:600;"><?= sanitize($envio['codigo_envio']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= APP_URL ?>/modules/envios/editar.php?id=<?= $id ?>" class="btn btn-outline btn-sm">Editar</a>
      <a href="<?= APP_URL ?>/modules/envios/crear.php?duplicar=<?= $id ?>" class="btn btn-outline btn-sm">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="margin-right:3px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        Duplicar
      </a>
    </div>
  </div>
</div>

<!-- Navegación de pestañas -->
<nav class="tab-nav">
  <button class="tab-btn active" data-tab="detalle">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
    Detalle
  </button>
  <button class="tab-btn" data-tab="tracking" id="tab-btn-tracking">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    Tracking en vivo
    <?php if ($tracking_available): ?>
    <span style="background:<?= $tracking_agency_color ?>;color:#fff;font-size:10px;padding:1px 7px;border-radius:100px;margin-left:2px;"><?= $tracking_agency ?></span>
    <?php endif; ?>
  </button>
</nav>

<!-- ─────────────────────────────── TAB 1: DETALLE ─────────────────────────── -->
<div class="tab-pane active" id="tab-detalle">

  <!-- Métricas rápidas -->
  <div class="info-grid">
    <div class="info-card">
      <div class="info-card-label">Fecha de envío</div>
      <div class="info-card-value" style="font-size:15px;"><?= $envio['fecha_envio'] ? formato_fecha($envio['fecha_envio']) : '—' ?></div>
    </div>
    <div class="info-card">
      <div class="info-card-label">Recepción</div>
      <div class="info-card-value" style="font-size:15px;"><?= $envio['fecha_recibido'] ? formato_fecha($envio['fecha_recibido']) : '—' ?></div>
      <?php if (!$envio['fecha_recibido']): ?>
      <div class="info-card-sub" style="color:var(--s-warn-tx);">Pendiente de confirmar</div>
      <?php endif; ?>
    </div>
    <div class="info-card">
      <div class="info-card-label">Días en tránsito</div>
      <?php
        $dias = 0;
        if ($envio['fecha_envio']) {
            $inicio = new DateTime($envio['fecha_envio']);
            $fin    = $envio['fecha_recibido'] ? new DateTime($envio['fecha_recibido']) : new DateTime();
            $dias   = (int)$inicio->diff($fin)->days;
        }
      ?>
      <div class="info-card-value"><?= $dias ?></div>
      <div class="info-card-sub"><?= $envio['fecha_recibido'] ? 'días totales' : 'días transcurridos' ?></div>
    </div>
  </div>

  <!-- Detalle completo -->
  <div class="detail-panel">
    <div class="detail-panel-header">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      Detalle del envío
    </div>

    <?php if (gg_es_estado_no_enviado($envio['estado_nombre']) && !empty($envio['motivo_no_envio'])): ?>
    <div style="display:flex;align-items:flex-start;gap:9px;margin:0 0 14px;padding:10px 13px;border:1px solid var(--s-err,#ef4444);background:var(--s-err-bg);border-radius:10px;font-size:13px;color:var(--s-err,#dc2626);">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      <div>
        <strong>No enviado: <?= sanitize(gg_motivo_no_envio_label($envio['motivo_no_envio'])) ?></strong>
        <?php if (!empty($envio['motivo_no_envio_detalle'])): ?>
        <div style="font-weight:400;margin-top:2px;color:var(--gris-dark);"><?= sanitize($envio['motivo_no_envio_detalle']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($envio['sin_datos_envio'])): ?>
    <div style="display:flex;align-items:center;gap:9px;margin:0 0 14px;padding:10px 13px;border:1px solid var(--s-warn-brd,#fcd34d);background:var(--s-warn-bg);border-radius:10px;font-size:13px;color:var(--s-warn-tx);font-weight:600;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      El cliente no compartió datos de envío
    </div>
    <?php endif; ?>

    <div class="detail-row">
      <div class="detail-label">Empresa</div>
      <div class="detail-value">
        <a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $envio['cliente_id'] ?>" style="color:var(--gris-dark);font-weight:600;">
          <?= sanitize($envio['razon_social']) ?>
        </a>
        <span style="color:var(--gris-texto);font-size:12px;"> · <?= sanitize($envio['ruc']) ?></span>
      </div>
    </div>

    <?php if (!empty($certificados)): ?>
    <div class="detail-row">
      <div class="detail-label">Certificado(s)</div>
      <div class="detail-value">
        <?php foreach ($certificados as $c): ?>
        <a href="<?= APP_URL ?>/modules/certificados/ver.php?id=<?= $c['id'] ?>" class="cert-badge">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
          <?= sanitize($c['norma_codigo']) ?>
          <?= $c['numero_certificado'] ? ' — ' . sanitize($c['numero_certificado']) : '' ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="detail-row">
      <div class="detail-label">Agencia</div>
      <div class="detail-value">
        <?= sanitize($envio['agencia']) ?>
        <?php if ($envio['agencia_tel']): ?> · <span style="color:var(--gris-texto)"><?= sanitize($envio['agencia_tel']) ?></span><?php endif; ?>
        <?php if ($envio['agencia_web']): ?> · <a href="<?= sanitize($envio['agencia_web']) ?>" target="_blank" style="color:var(--gris-texto);font-size:12px;">Sitio web ↗</a><?php endif; ?>
        <?php if (!$envio['agencia_envio_id']): ?><div style="font-size:11px;color:var(--s-warn-tx);margin-top:2px;">Pendiente de asignar agencia</div><?php endif; ?>
      </div>
    </div>

    <div class="detail-row">
      <div class="detail-label">Código de seguimiento</div>
      <div class="detail-value" style="font-family:monospace;font-weight:600;color:var(--gris-dark);"><?= $has_code ? sanitize($envio['codigo_envio']) : '—' ?></div>
    </div>

    <?php if ($envio['numero_orden']): ?>
    <div class="detail-row">
      <div class="detail-label">Número de orden</div>
      <div class="detail-value" style="font-family:monospace;"><?= sanitize($envio['numero_orden']) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($envio['codigo_recojo']): ?>
    <div class="detail-row">
      <div class="detail-label">Código de recojo</div>
      <div class="detail-value" style="font-family:monospace;"><?= sanitize($envio['codigo_recojo']) ?></div>
    </div>
    <?php endif; ?>

    <?php
      // Integrada (Olva/Shalom = sede_modo 'api') → Sede de destino.
      // Manual (resto) → Dirección de destino. Se muestra siempre la fila que corresponde.
      $_es_api   = (($envio['agencia_sede_modo'] ?? 'manual') === 'api');
      $_dash     = '<span style="color:var(--gris-texto);">—</span>';
    ?>
    <?php if ($_es_api): ?>
    <div class="detail-row">
      <div class="detail-label">Sede de destino</div>
      <div class="detail-value"><?= $envio['sede_registro'] ? sanitize($envio['sede_registro']) : $_dash ?></div>
    </div>
    <?php if (!empty($envio['tienda_destino'])): ?>
    <div class="detail-row">
      <div class="detail-label">Punto de recojo</div>
      <div class="detail-value"><?= sanitize($envio['tienda_destino']) ?></div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="detail-row">
      <div class="detail-label">Dirección de destino</div>
      <div class="detail-value"><?= !empty($envio['direccion_destino']) ? sanitize($envio['direccion_destino']) : $_dash ?></div>
    </div>
    <?php endif; ?>

    <?php if ($envio['destinatario']): ?>
    <div class="detail-row">
      <div class="detail-label">Destinatario</div>
      <div class="detail-value">
        <?= sanitize($envio['destinatario']) ?>
        <?php if ($envio['destinatario_dni']): ?> · DNI <span style="font-family:monospace;"><?= sanitize($envio['destinatario_dni']) ?></span><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($envio['comprobante_archivo']): ?>
    <div class="detail-row">
      <div class="detail-label">Comprobante</div>
      <div class="detail-value">
        <a href="<?= rtrim(UPLOAD_URL,'/') . '/' . sanitize($envio['ruc'] ?? 'sin_ruc') . '/envios/' . sanitize($envio['comprobante_archivo']) ?>" target="_blank" data-ggi-preview data-title="Comprobante de envío" class="btn btn-outline btn-sm" style="font-size:12px;">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          Ver comprobante
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($envio['observaciones']): ?>
    <div class="detail-row">
      <div class="detail-label">Observaciones</div>
      <div class="detail-value"><?= sanitize($envio['observaciones']) ?></div>
    </div>
    <?php endif; ?>

    <div class="detail-row">
      <div class="detail-label">Registrado por</div>
      <div class="detail-value"><?= sanitize($envio['registrado_por_nombre']) ?> · <?= date('d/m/Y', strtotime($envio['created_at'])) ?></div>
    </div>

    <div class="detail-row">
      <div class="detail-label">Gestor logístico</div>
      <div class="detail-value">
        <?php if ($envio['agente_nombre']): ?>
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="width:28px;height:28px;border-radius:50%;background:#1D9E75;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;"><?= strtoupper(substr($envio['agente_nombre'],0,1).substr($envio['agente_apellido'],0,1)) ?></div>
            <span><?= sanitize($envio['agente_nombre'].' '.$envio['agente_apellido']) ?></span>
          </div>
        <?php elseif ($user['rol'] === 'gestor_logistico'): ?>
          <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="asumir_envio">
            <button type="submit" style="display:inline-flex;align-items:center;gap:7px;padding:8px 16px;background:#1D9E75;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Asumir este envío
            </button>
          </form>
          <div style="font-size:11px;color:var(--gris-texto);margin-top:5px;">Este envío no tiene gestor asignado. Puedes asumirlo.</div>
        <?php else: ?>
          <span style="color:var(--gris-texto);font-size:13px;">Sin gestor asignado</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /tab-detalle -->

<!-- ─────────────────────────────── TAB 2: TRACKING ──────────────────────── -->
<div class="tab-pane" id="tab-tracking">

  <div class="trk-wrap" id="trk-container">
    <?php if ($tracking_available): ?>
      <div class="trk-header">
        <div class="trk-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Tracking en tiempo real —
          <span style="color:<?= $tracking_agency_color ?>"><?= sanitize($envio['agencia']) ?></span>
        </div>
        <button id="btn-refresh" onclick="cargarTracking(true)" class="btn btn-outline btn-sm" style="display:none;">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Actualizar
        </button>
      </div>
      <div id="trk-body">
        <div style="padding:32px 22px;">
          <div class="skeleton" style="height:16px;width:220px;margin-bottom:12px;"></div>
          <div class="skeleton" style="height:12px;width:160px;margin-bottom:24px;"></div>
          <div class="skeleton" style="height:12px;width:100%;margin-bottom:8px;"></div>
          <div class="skeleton" style="height:12px;width:85%;margin-bottom:8px;"></div>
          <div class="skeleton" style="height:12px;width:70%;margin-bottom:8px;"></div>
          <div class="skeleton" style="height:12px;width:90%;"></div>
        </div>
      </div>
    <?php else: ?>
      <div class="trk-empty">
        <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <p style="font-weight:600;margin-bottom:6px;">Tracking no disponible</p>
        <?php if (!$is_olva && !$is_shalom && !$is_serpost): ?>
        <p style="font-size:13px;">El tracking automático está disponible para <strong>Olva Courier</strong>, <strong>Shalom Envíos</strong> y <strong>Serpost</strong>. Esta guía corresponde a <strong><?= sanitize($envio['agencia']) ?></strong>.</p>
        <?php elseif ($is_shalom): ?>
        <p style="font-size:13px;">Este envío necesita el <strong>N° de Guía</strong> (campo "N° de orden") y el <strong>Código de orden</strong> ("Código de seguimiento") para activar el tracking. <a href="<?= APP_URL ?>/modules/envios/editar.php?id=<?= $id ?>">Completar datos →</a></p>
        <?php else: ?>
        <p style="font-size:13px;">Este envío no tiene código de guía registrado. Agrégalo desde <a href="<?= APP_URL ?>/modules/envios/editar.php?id=<?= $id ?>">Editar</a>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

</div><!-- /tab-tracking -->

<?php if (isset($_GET['asumido'])): ?>
<script>ggiReady(()=>{ if(typeof showToast==='function') showToast('Has asumido este envío correctamente.','ok'); });</script>
<?php endif; ?>

<script>
(function () {
  // ── Tab switching ─────────────────────────────────────────────────────────
  var trkLoaded = false;

  document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('tab-' + btn.dataset.tab).classList.add('active');

      // Cargar tracking al activar la pestaña por primera vez
      if (btn.dataset.tab === 'tracking' && !trkLoaded) {
        trkLoaded = true;
        cargarTracking(false);
      }
    });
  });

  // Si viene ?tab=tracking en la URL, activar directo
  if (new URLSearchParams(location.search).get('tab') === 'tracking') {
    document.querySelector('[data-tab="tracking"]').click();
  }

  // ── Carga de tracking vía AJAX ────────────────────────────────────────────
  var trkAjaxUrl = <?= json_encode($tracking_ajax_url) ?>;

  window.cargarTracking = function (refresh) {
    <?php if (!$tracking_available): ?>return;<?php endif; ?>

    var body = document.getElementById('trk-body');
    if (refresh) {
      body.innerHTML = '<div style="padding:28px 22px;text-align:center;color:var(--gris-texto);font-size:13px;">Consultando API de <?= $tracking_agency ?: 'la agencia' ?>…</div>';
    }

    fetch(trkAjaxUrl)
      .then(function (r) { return r.json(); })
      .then(function (res) {
        document.getElementById('btn-refresh').style.display = 'flex';
        if (!res.ok) {
          body.innerHTML = '<div class="trk-empty"><svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><p>' + res.error + '</p></div>';
          return;
        }
        renderTracking(res.data);
      })
      .catch(function () {
        body.innerHTML = '<div class="trk-empty"><p>Error de conexión. Intente nuevamente.</p></div>';
      });
  };

  function estadoColor(estado) {
    var e = (estado || '').toUpperCase();
    if (e === 'ENTREGADO' || e === 'CONFIRMACION RECOJO') return '#16a34a';
    if (e.includes('DEVOLUCION') || e === 'REZAGADO' || e === 'DEVUELTO') return '#dc2626';
    if (e === 'ASIGNADO' || e.includes('DISTRIBUCION') || e.includes('TIENDA')) return '#2563eb';
    return '#64748b';
  }

  function dotClass(estado, isFirst) {
    if (isFirst) {
      var e = (estado || '').toUpperCase();
      if (e === 'ENTREGADO' || e === 'CONFIRMACION RECOJO') return 'entregado';
      if (e.includes('DEVOLUCION') || e === 'REZAGADO' || e === 'DEVUELTO') return 'devuelto';
      return 'first';
    }
    return '';
  }

  function renderTracking(d) {
    var estadoLabel = d.estado_label || d.estado_olva || '—';
    var esShalom    = d.stepper_type === 'shalom';

    // Metadata superior
    var metaItems = [
      { label: 'Estado actual',  val: '<span style="color:<?= $tracking_agency_color ?>;font-weight:700;">' + estadoLabel + '</span>' },
      { label: 'Origen',         val: d.origen       || '—' },
      { label: 'Destino',        val: d.destino      || '—' },
      { label: 'Destinatario',   val: d.destinatario || '—' },
      { label: 'Contenido',      val: d.contenido    || '—' },
    ];
    if (esShalom && d.monto)      metaItems.push({ label: 'Monto', val: 'S/ ' + d.monto });
    if (!esShalom && d.peso)      metaItems.push({ label: 'Peso',  val: d.peso + ' kg' });
    var metaHtml = metaItems.map(function (m) {
      return '<div class="trk-meta-item"><div class="trk-meta-label">' + m.label + '</div><div class="trk-meta-val">' + m.val + '</div></div>';
    }).join('');

    // Timeline: usar eventos_friendly (filtrados y traducidos) si existen, fallback a todos
    var eventos = (d.eventos_friendly && d.eventos_friendly.length) ? d.eventos_friendly : (d.eventos || []);
    var tlHtml = '';
    if (eventos.length === 0) {
      tlHtml = '<p style="color:var(--gris-texto);font-size:13px;">Sin eventos registrados.</p>';
    } else {
      tlHtml = '<div class="timeline">';
      eventos.forEach(function (ev, i) {
        var dc    = dotClass(ev.estado, i === 0);
        var color = i === 0 ? estadoColor(ev.estado) : '#94a3b8';
        var obs   = (ev.obs || '').replace(/\s*_\|_\s*/g, ' · ');
        var f     = ev.fecha || '';
        var lbl   = ev.label || ev.estado;
        tlHtml += '<div class="tl-item">'
          + '<div class="tl-dot ' + dc + '" style="' + (dc ? 'background:' + color + ';box-shadow:0 0 0 2px ' + color + ';' : '') + '">'
          + (i === 0 ? '<svg width="8" height="8" fill="#fff" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>' : '')
          + '</div>'
          + '<div class="tl-fecha">' + f + (ev.sede ? ' · ' + ev.sede : '') + '</div>'
          + '<div class="tl-estado" style="color:' + color + ';">' + lbl + '</div>'
          + (obs ? '<div class="tl-obs">' + obs + '</div>' : '')
          + '</div>';
      });
      tlHtml += '</div>';
    }

    // Notificación si el estado GGI fue avanzado automáticamente
    var updHtml = '';
    if (d.estado_actualizado && d.nuevo_estado) {
      updHtml = '<div style="margin:14px 22px 0;padding:10px 14px;background:var(--s-ok-bg);border:1px solid #A7F3D0;border-radius:9px;display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:#059669;">'
        + '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>'
        + 'Estado del envío actualizado a: ' + (d.nuevo_estado.nombre || '') + '</div>';
    }

    document.getElementById('trk-body').innerHTML =
      '<div class="trk-meta-grid">' + metaHtml + '</div>'
      + updHtml
      + '<div class="trk-body">' + tlHtml + '</div>';
  }

})();
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
