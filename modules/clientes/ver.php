<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/pendientes.php';
session_init();
require_login();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$page_title  = 'Detalle Cliente';
$active_menu = 'clientes';


$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

$pdo = Database::get();

$cliente = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$cliente->execute([$id]);
$c = $cliente->fetch();
if (!$c) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

// Al entrar a revisar la ficha, marcar leídas las notificaciones INFORMATIVAS de
// este cliente para el usuario actual (las accionables se limpian al completar la tarea).
require_once __DIR__ . '/../../core/NotificationService.php';
NotificationService::markSeen((int)auth_user()['id'], $id);

// Cargar nombres de ejecutivos para el modelo de propiedad
$ej_captador   = null;
$ej_responsable = null;
// Captador = quien creó el cliente; si no existe, usar el ejecutivo asignado.
$_captador_id    = $c['created_by_user_id'] ?: $c['ejecutivo_id'];
// Responsable actual; si no existe, usar el ejecutivo asignado.
$_responsable_id = $c['responsible_user_id'] ?: $c['ejecutivo_id'];
if ($_captador_id) {
    $q = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id=?");
    $q->execute([$_captador_id]);
    $ej_captador = $q->fetch();
}
if ($_responsable_id && $_responsable_id != $_captador_id) {
    $q = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id=?");
    $q->execute([$_responsable_id]);
    $ej_responsable = $q->fetch();
} elseif ($_responsable_id && $_responsable_id == $_captador_id) {
    $ej_responsable = $ej_captador;
}

$from = $_GET['from'] ?? 'listado';
$back_url = $from === 'dashboard' ? APP_URL . '/modules/dashboard/index.php' : APP_URL . '/modules/clientes/index.php';
$page_title = $c['razon_social'];

// ISOs del cliente
$isos = $pdo->prepare("
    SELECT ci.*, n.codigo, n.nombre FROM cliente_isos ci
    JOIN normas_iso n ON n.id = ci.norma_iso_id
    WHERE ci.cliente_id = ?
");
$isos->execute([$id]);
$isos = $isos->fetchAll();

// Documentos vigentes
$docs = $pdo->prepare("
    SELECT d.*, u.nombre AS subido_por_nombre FROM documentos_cliente d
    JOIN usuarios u ON u.id = d.subido_por
    WHERE d.cliente_id = ? AND d.es_vigente = 1
    ORDER BY d.tipo_documento
");
$docs->execute([$id]);
$docs = $docs->fetchAll();
$docs_por_tipo = array_column($docs, null, 'tipo_documento');

// Llamadas programadas abiertas de este cliente (historial único: incluye las
// agendadas desde propuestas u otros módulos)
$stmt = $pdo->prepare("
    SELECT ll.*, CONCAT(u.nombre,' ',u.apellido) AS asignado_nombre
    FROM llamadas_programadas ll
    JOIN usuarios u ON u.id = ll.usuario_id
    WHERE ll.cliente_id = ? AND ll.estado IN ('pendiente','notificada')
    ORDER BY ll.fecha_hora ASC
");
$stmt->execute([$id]);
$llamadas_abiertas = $stmt->fetchAll();

// Counts para labels de pestañas (queries ligeras — no se cargan los datos completos)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM certificados WHERE cliente_id=? AND deleted_at IS NULL");
$stmt->execute([$id]); $cnt_certs = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(estado='pendiente_validacion') AS pendientes FROM pagos_cliente WHERE cliente_id=? AND deleted_at IS NULL");
$stmt->execute([$id]); $_row = $stmt->fetch();
$cnt_pagos = (int)($_row['total'] ?? 0);
$cnt_pagos_pend = (int)($_row['pendientes'] ?? 0);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM comunicaciones_cliente WHERE cliente_id=?");
$stmt->execute([$id]); $cnt_coms = (int)$stmt->fetchColumn();

// Total de llamadas del cliente (todos los estados) para el tab "Llamadas"
$stmt = $pdo->prepare("SELECT COUNT(*) FROM llamadas_programadas WHERE cliente_id=?");
$stmt->execute([$id]); $cnt_llamadas = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE cliente_id=? AND deleted_at IS NULL");
$stmt->execute([$id]); $cnt_cots = (int)$stmt->fetchColumn();

// Propuestas aceptadas con sus ISOs y el acta vigente asociada (si existe)
// Acta de Aprobación de Alcance vigente (una por cliente)
$doc_acta_stmt = $pdo->prepare("
    SELECT d.id AS doc_id, d.version AS doc_version, d.nombre_archivo AS doc_nombre,
           d.alcance_texto AS doc_alcance, d.confirmada AS doc_confirmada,
           d.confirmada_at AS doc_confirmada_at, d.cotizacion_id AS doc_cot_id,
           d.estado_acta AS doc_estado, u.nombre AS conf_nombre, u.apellido AS conf_apellido
    FROM documentos_cliente d
    LEFT JOIN usuarios u ON u.id = d.confirmada_por
    WHERE d.cliente_id = ? AND d.tipo_documento = 'acta_alcance' AND d.es_vigente = 1
    ORDER BY d.version DESC
    LIMIT 1
");
$doc_acta_stmt->execute([$id]);
$doc_acta_vigente = $doc_acta_stmt->fetch() ?: null;

// Última cotización aceptada del cliente (fuente de ISOs para el acta)
$ultima_cot_stmt = $pdo->prepare("
    SELECT cot.id AS cot_id, cot.codigo AS cot_codigo
    FROM cotizaciones cot
    WHERE cot.cliente_id = ? AND cot.estado = 'aceptada' AND cot.deleted_at IS NULL
    ORDER BY cot.id DESC
    LIMIT 1
");
$ultima_cot_stmt->execute([$id]);
$ultima_cot = $ultima_cot_stmt->fetch() ?: null;

$isos_ultima_cot = [];
if ($ultima_cot) {
    $isos_stmt = $pdo->prepare("
        SELECT cn.norma_iso_id, n.codigo
        FROM cotizacion_normas cn
        JOIN normas_iso n ON n.id = cn.norma_iso_id
        WHERE cn.cotizacion_id = ?
        ORDER BY n.codigo
    ");
    $isos_stmt->execute([$ultima_cot['cot_id']]);
    $isos_ultima_cot = $isos_stmt->fetchAll();
}

// Todas las normas del sistema (para modal Application Form)
$all_normas_app = $pdo->query("SELECT id, codigo FROM normas_iso ORDER BY id")->fetchAll();

// IDs de normas de propuestas aceptadas (para pre-marcar en Application Form)
$normas_aceptadas_ids_stmt = $pdo->prepare("
    SELECT DISTINCT cn.norma_iso_id
    FROM cotizaciones cot
    JOIN cotizacion_normas cn ON cn.cotizacion_id = cot.id
    WHERE cot.cliente_id = ? AND cot.estado = 'aceptada' AND cot.deleted_at IS NULL
");
$normas_aceptadas_ids_stmt->execute([$id]);
$normas_aceptadas_ids = $normas_aceptadas_ids_stmt->fetchAll(PDO::FETCH_COLUMN);

// Datos para popup de nuevo certificado
$normas_all = $pdo->query("SELECT id, codigo, nombre FROM normas_iso WHERE activo=1 ORDER BY codigo")->fetchAll();
$casas_all  = $pdo->query("SELECT id, nombre FROM casas_certificadoras WHERE activo=1 ORDER BY nombre")->fetchAll();

$tipos_doc = [
    'ficha_ruc' => 'Ficha RUC',
];
$tipos_doc_adicionales = [
    'copia_literal' => 'Copia Literal',
    'logo'          => 'Logo de empresa',
];

$nuevo   = isset($_GET['nuevo']);
$editado   = isset($_GET['editado']);
$doc_ok    = isset($_GET['doc_ok']);
$doc_error = isset($_GET['doc_error']);
$user = auth_user();
require_once __DIR__ . '/../../templates/layout.php';

?>

<style>
  .cliente-header { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); padding:24px 28px; margin-bottom:20px; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
  .cliente-main h2 { font-family:'Inter',sans-serif; font-size:20px; font-weight:700; color:var(--gris-dark); }
  .cliente-meta { display:flex; gap:16px; margin-top:8px; flex-wrap:wrap; }
  .meta-item { font-size:12px; color:var(--gris-texto); display:flex; align-items:center; gap:4px; }
  .meta-item strong { color:var(--gris-dark); }
  .cliente-actions { display:flex; gap:8px; flex-shrink:0; }

  .tabs { display:flex; gap:2px; background:var(--bg-surface2); padding:4px; border-radius:10px; margin-bottom:20px; width:fit-content; }
  .tab { padding:8px 16px; border-radius:7px; font-size:13px; font-weight:500; cursor:pointer; color:var(--gris-texto); border:none; background:none; transition:all 0.15s; font-family:'Inter',sans-serif; }
  .tab.active { background:var(--bg-surface); color:var(--gris-dark); font-weight:600; box-shadow:0 1px 4px rgba(0,0,0,0.08); }
  .tab-panel { display:none; }
  @keyframes tab-spin { to { transform: rotate(360deg); } }

  /* ── Timeline (historial) ── */
  .timeline-wrap { padding:8px 0 24px; }
  .timeline-item { display:flex; gap:16px; padding:0 0 24px; position:relative; }
  .timeline-item:last-child { padding-bottom:0; }
  .timeline-left { display:flex; flex-direction:column; align-items:center; flex-shrink:0; width:32px; }
  .timeline-dot { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; z-index:1; }
  .timeline-line { flex:1; width:2px; background:var(--gris-borde); margin-top:4px; }
  .timeline-item:last-child .timeline-line { display:none; }
  .timeline-body { flex:1; min-width:0; }
  .timeline-card { background:var(--bg-surface,#fff); border:1px solid var(--gris-borde); border-radius:10px; padding:12px 14px; transition:box-shadow 0.15s; }
  .timeline-card:hover { box-shadow:0 2px 10px rgba(0,0,0,0.07); }
  .timeline-header { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:4px; }
  .timeline-badge { display:inline-block; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
  .timeline-ref { font-size:13px; font-weight:600; color:var(--gris-dark); flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .timeline-date { font-size:11px; color:var(--gris-texto); white-space:nowrap; }
  .timeline-estado { font-size:11px; font-weight:600; }
  .timeline-month-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--gris-texto); padding:4px 0 12px; }

  /* Documentos */
  .docs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
  .doc-card { background:var(--bg-surface); border:1.5px solid var(--gris-borde); border-radius:10px; padding:16px; transition:border-color 0.15s; }
  .doc-card:hover { border-color:var(--azul); }
  .doc-card.uploaded { border-color:var(--s-ok-brd); background:var(--s-ok-bg); }
  .doc-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
  .doc-tipo { font-size:12px; font-weight:600; color:var(--gris-texto); }
  .doc-status-ok { width:22px; height:22px; background:var(--verde); border-radius:50%; display:flex; align-items:center; justify-content:center; }
  .doc-status-empty { width:22px; height:22px; background:var(--gris-borde); border-radius:50%; display:flex; align-items:center; justify-content:center; }
  .doc-filename { font-size:11px; color:var(--gris-texto); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:8px; }
  .doc-actions { display:flex; gap:6px; }

  /* Comunicaciones */
  .feed { display:flex; flex-direction:column; gap:0; }
  .feed-item { display:flex; gap:12px; padding:14px 0; border-bottom:1px solid var(--brd); }
  .feed-item:last-child { border-bottom:none; }
  .feed-avatar { width:32px; height:32px; border-radius:50%; background:var(--s-neutral); color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; text-transform:uppercase; }
  .feed-body { flex:1; }
  .feed-meta { font-size:11px; color:var(--gris-texto); margin-bottom:4px; }
  .feed-meta strong { color:var(--gris-dark); }
  .feed-msg { font-size:13px; color:var(--gris-dark); line-height:1.5; }
  .feed-tipo { display:inline-flex; padding:2px 8px; border-radius:100px; font-size:10px; font-weight:600; background:var(--s-neutral-bg); color:var(--s-neutral); margin-left:6px; text-transform:capitalize; }

  .com-form { background:var(--bg-surface2); border-radius:10px; padding:16px; margin-bottom:16px; }
  .com-form textarea { width:100%; padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:8px; font-size:13px; font-family:'Inter',sans-serif; resize:vertical; min-height:72px; outline:none; }
  .com-form textarea:focus { border-color:var(--azul); }
  .com-footer { display:flex; align-items:center; justify-content:space-between; margin-top:10px; gap:10px; }
  .com-tipo-sel { padding:7px 10px; border:1.5px solid var(--gris-borde); border-radius:8px; font-size:12px; font-family:'Inter',sans-serif; outline:none; }

  .alert-nuevo { margin-bottom:20px; }
  /* .btn-ic → canónico (design-system.css / layout_end.php) */

  /* Área de upload inline */
  .doc-upload-area {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    border:1.5px dashed var(--gris-borde); border-radius:8px;
    padding:16px 12px; cursor:pointer; transition:border-color 0.15s, background 0.15s;
    margin-bottom:2px; min-height:60px; user-select:none;
  }
  .doc-upload-area:hover,
  .doc-upload-area.drag-over { border-color:var(--azul); background:var(--azul-light); }
  .doc-card.drag-over-card { border-color:var(--azul) !important; background:var(--azul-light) !important; }
  .doc-card.doc-loading { opacity:0.7; pointer-events:none; }

  .voucher-drop-zone {
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    border:1.5px dashed var(--brd2); border-radius:9px; padding:5px 9px;
    transition:border-color .15s, background .15s;
  }
  .voucher-drop-hint {
    font-size:11px; color:var(--gris-texto); white-space:nowrap; pointer-events:none;
  }
  .voucher-drop-zone.drag-over {
    border-color:var(--ac) !important; background:var(--ac-soft) !important;
  }
  .voucher-drop-zone.drag-over .voucher-drop-hint {
    color:var(--ac); font-weight:600;
  }

  /* ══════════════════════════════════════
     RESPONSIVE MOBILE — clientes/ver.php
  ══════════════════════════════════════ */

  /* ── Header de cliente: stack en móvil ── */
  @media (max-width: 640px) {
    .cliente-header {
      flex-direction: column;
      padding: 14px 16px;
      gap: 12px;
    }
    .cliente-main h2 { font-size: 16px !important; }
    .cliente-meta { gap: 8px 12px; }
    .cliente-actions {
      width: 100%;
      flex-wrap: wrap;
      gap: 6px;
    }
    .cliente-actions .btn,
    .cliente-actions button {
      flex: 1;
      min-width: 72px;
      justify-content: center;
      font-size: 12px !important;
    }
  }

  /* ── Certificados: tabla → tarjetas en móvil ── */
  @media (max-width: 640px) {
    .cert-table { min-width: unset !important; }
    .cert-table thead { display: none; }
    .cert-table, .cert-table tbody { display: block; width: 100%; }
    .cert-table tr {
      display: grid;
      grid-template-columns: 1fr auto;
      grid-template-rows: auto auto auto;
      border-radius: 10px;
      border: 1px solid var(--gris-borde);
      margin-bottom: 8px;
      padding: 12px 14px;
      background: var(--bg-surface);
      gap: 2px 10px;
    }
    .cert-table td {
      display: block;
      padding: 2px 0;
      border: none;
      font-size: 12px;
    }
    .cert-table td::before {
      display: block;
      font-size: 10px;
      font-weight: 700;
      color: var(--gris-texto);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 1px;
    }
    .cert-table td[data-label="Norma"] { grid-column: 1; grid-row: 1; font-size: 14px; font-weight: 700; }
    .cert-table td[data-label="Norma"]::before { content: none; }
    .cert-table td[data-label="Casa"]::before { content: "Casa"; }
    .cert-table td[data-label="Casa"] { grid-column: 1; grid-row: 2; color: var(--gris-texto); }
    .cert-table td[data-label="Vence"]::before { content: "Vence"; }
    .cert-table td[data-label="Vence"] { grid-column: 1; grid-row: 3; }
    .cert-table td[data-label="Estado"] { grid-column: 2; grid-row: 1; text-align: right; }
    .cert-table td[data-label="Estado"]::before { content: none; }
    .cert-table td[data-label="Acciones"] { grid-column: 2; grid-row: 2 / span 2; display: flex; align-items: flex-end; justify-content: flex-end; }
    .cert-table td[data-label="Acciones"]::before { content: none; }
  }

  /* ── Propuestas mini-tabla → tarjetas en móvil ── */
  @media (max-width: 640px) {
    .cots-mini-table { min-width: unset !important; }
    .cots-mini-table thead { display: none; }
    .cots-mini-table, .cots-mini-table tbody { display: block; width: 100%; }
    .cots-mini-table tr {
      display: grid;
      grid-template-columns: 1fr auto;
      border-radius: 10px;
      border: 1px solid var(--gris-borde);
      margin-bottom: 8px;
      padding: 12px 14px;
      background: var(--bg-surface);
      gap: 4px 10px;
    }
    .cots-mini-table td { display: block; padding: 0; border: none; font-size: 12px; }
    .cots-mini-table td[data-label="Numero"] { grid-column: 1; grid-row: 1; font-size: 13px; font-weight: 700; }
    .cots-mini-table td[data-label="Fecha"]  { grid-column: 1; grid-row: 2; color: var(--gris-texto); font-size: 11px; }
    .cots-mini-table td[data-label="Monto"]  { grid-column: 1; grid-row: 3; font-weight: 700; font-size: 14px; margin-top: 2px; }
    .cots-mini-table td[data-label="Estado"] { grid-column: 2; grid-row: 1; text-align: right; }
    .cots-mini-table td[data-label="Ver"]    { grid-column: 2; grid-row: 2 / span 2; display: flex; align-items: flex-end; justify-content: flex-end; }
  }

  /* ── Pagos: reducir padding en móvil ── */
  @media (max-width: 480px) {
    .voucher-drop-zone { flex-direction: column; align-items: flex-start; gap: 6px; }
    .voucher-drop-hint { display: none; }
  }

  /* ── Acta de Aprobación de Alcance: responsive móvil ── */
  @media (max-width: 640px) {
    /* Card: apilado vertical en lugar de fila */
    #acta-card {
      flex-direction: column !important;
      align-items: stretch !important;
      gap: 12px !important;
      padding: 14px !important;
    }

    /* Sección info: ocupa ancho completo, icono + texto no se comprimen */
    #acta-card > div:first-child {
      flex: none !important;
      width: 100%;
      align-items: flex-start !important;
    }

    /* El texto del título puede wrappear sin overflow */
    #acta-card > div:first-child > div:last-child {
      min-width: 0;
      word-break: break-word;
    }

    /* Sección botones: ancho completo, grid 2 columnas */
    #acta-card > div:last-child {
      flex-shrink: 1 !important;
      width: 100%;
      flex-wrap: wrap !important;
      gap: 6px !important;
    }

    /* Cada botón: mitad del ancho (2 por fila), el último solo ocupa lo que quede */
    #acta-card > div:last-child > .btn,
    #acta-card > div:last-child > button {
      flex: 1 1 calc(50% - 3px) !important;
      min-width: 0;
      justify-content: center !important;
      text-align: center;
    }
  }
</style>

<?php
// Certs próximas a vencer (90 días) — query liviana solo con campos necesarios
$_certs_venc_stmt = $pdo->prepare("
    SELECT ce.fecha_vencimiento, ce.estado, n.codigo AS norma_codigo
    FROM certificados ce
    JOIN normas_iso n ON n.id = ce.norma_iso_id
    WHERE ce.cliente_id = ? AND ce.deleted_at IS NULL AND ce.fecha_vencimiento IS NOT NULL
    ORDER BY ce.fecha_vencimiento ASC
");
$_certs_venc_stmt->execute([$id]);
$certs_alerta = array_filter($_certs_venc_stmt->fetchAll(), function($cert) {
    if (in_array($cert['estado'] ?? '', ['vencido','suspendido','cancelado'])) return false;
    $dias = (int)((strtotime($cert['fecha_vencimiento']) - strtotime('today')) / 86400);
    return $dias <= 90;
});
?>
<?php if (!empty($certs_alerta)): ?>
<div class="alert-banner warn" style="flex-direction:column;margin-bottom:16px;">
  <div style="font-size:13px;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <?= count($certs_alerta) === 1 ? '1 certificado próximo a vencer' : count($certs_alerta).' certificados próximos a vencer' ?>
  </div>
  <?php foreach ($certs_alerta as $ca):
    $dias_ca = (int)((strtotime($ca['fecha_vencimiento']) - strtotime('today')) / 86400);
    $color_ca = $dias_ca <= 0 ? 'var(--s-err)' : ($dias_ca <= 30 ? 'var(--s-err)' : 'var(--s-warn)');
  ?>
  <div style="display:flex;align-items:center;justify-content:space-between;font-size:12px;padding:4px 0;border-bottom:1px solid var(--s-warn-brd);">
    <span style="font-weight:600;color:var(--gris-dark);"><?= sanitize($ca['norma_codigo']) ?></span>
    <span style="color:<?= $color_ca ?>;font-weight:600;">
      <?= $dias_ca <= 0 ? 'Vencido' : ($dias_ca === 1 ? 'Vence mañana' : "Vence en $dias_ca días") ?>
    </span>
    <a href="<?= APP_URL ?>/modules/cotizaciones/crear.php?cliente_id=<?= $id ?>" class="btn btn-outline btn-sm" style="font-size:10px;padding:3px 10px;">Renovar</a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<?php if ($doc_ok || $doc_error): ?>
<script>
ggiReady(function() {
  <?php if ($doc_ok): ?>
  showToast('Documento subido exitosamente.', 'ok');
  <?php else: ?>
  showToast('No se encontró el archivo. Puede haber sido eliminado del servidor.', 'error');
  <?php endif; ?>
  var u = new URL(window.location.href);
  u.searchParams.delete('doc_ok');
  u.searchParams.delete('doc_error');
  history.replaceState({}, '', u.toString());
});
</script>
<?php endif; ?>

<?php if (!empty($_GET['portal_error'])): ?>
<div class="alert-banner warn" style="margin-bottom:20px;">
  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
  El portal de este cliente no está activo. Actívalo desde la pestaña Portal en Editar.
</div>
<?php endif; ?>
<?php if (has_role('gerente_general') && ($c['revision_gerente'] ?? 'pendiente') === 'pendiente' && !in_array($c['estado'], ['prospecto','inactivo'])): ?>
<div id="banner-revision" class="alert-banner warn" style="margin-bottom:20px;justify-content:space-between;flex-wrap:wrap;">
  <div style="display:flex;align-items:center;gap:10px;">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
    <div>
      <div style="font-size:13px;font-weight:700;">Pendiente de revisión</div>
      <div style="font-size:12px;margin-top:1px;">Revisa la información del cliente y los documentos cargados antes de iniciar la gestión documentaria.</div>
    </div>
  </div>
  <button id="btn-marcar-revisado" onclick="marcarRevisado()"
    style="display:inline-flex;align-items:center;gap:7px;background:var(--s-warn);color:#fff;border:none;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:'Inter',sans-serif;transition:background .15s;"
    onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    Marcar como revisado
  </button>
</div>
<script>
// (El controlador del Portal está aislado en su propio <script>, tras el panel Portal.)
function marcarRevisado() {
  var btn = document.getElementById('btn-marcar-revisado');
  btn.disabled = true;
  btn.textContent = 'Guardando…';
  var fd = new FormData();
  fd.append('cliente_id', '<?= $id ?>');
  ggiPost('<?= APP_URL ?>/modules/clientes/ajax_revision.php', fd, { silent: true })
  .then(function(d){
    if (d.ok) {
      if (typeof showToast === 'function') showToast('Cliente marcado como revisado.', 'ok');
      // Invalidar caché para que al volver al listado se vea actualizado
      if (typeof window.invalidatePageCache === 'function') {
        window.invalidatePageCache(location.href);
        window.invalidatePageCache(window.APP_URL + '/modules/clientes/index.php');
      }
      if (typeof window._pollBadges === 'function') window._pollBadges();
      // Regresar al listado después de un breve momento para que el toast sea visible
      setTimeout(function() {
        if (typeof window.ggiNavBack === 'function') {
          window.ggiNavBack('<?= $back_url ?>');
        } else {
          location.href = '<?= $back_url ?>';
        }
      }, 900);
    } else {
      btn.disabled = false;
      btn.textContent = 'Marcar como revisado';
      if (typeof showToast === 'function') showToast(d.msg || 'Error al guardar.', 'error');
      else showToast(d.msg || 'Error al guardar.', 'error');
    }
  })
  .catch(function(){
    btn.disabled = false;
    btn.textContent = 'Marcar como revisado';
  });
}
</script>
<?php endif; ?>

<!-- Encabezado del cliente -->
<?php
if ($from === 'dashboard') {
    ggi_breadcrumb([
        ['label' => 'Panel',    'href' => APP_URL.'/modules/dashboard/index.php'],
        ['label' => sanitize($c['razon_social'])],
    ]);
} else {
    ggi_breadcrumb([
        ['label' => 'Empresas', 'href' => APP_URL.'/modules/clientes/index.php', 'ss' => 'ggi_ss_clientes'],
        ['label' => sanitize($c['razon_social'])],
    ]);
}
?>

<div class="cliente-header">
  <div class="cliente-main">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
      <code class="chip chip-neutral" style="font-size:12px;font-weight:700;"><?= sanitize($c['ruc']) ?></code>
      <?php
        $badge = match($c['estado']) { 'activo'=>'badge-green','inactivo'=>'badge-gray','prospecto'=>'badge-amber',default=>'badge-gray' };
      ?>
      <span class="badge <?= $badge ?>" style="display:inline-flex;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:600;"><?= ucfirst($c['estado']) ?></span>
    </div>
    <h2 title="<?= sanitize($c['razon_social']) ?>"><?= sanitize_trunc($c['razon_social'], 55) ?></h2>
    <?php $_pend = gg_empresa_pendientes($pdo, (int)$c['id']); if ($_pend['total'] > 0): ?>
    <button type="button" class="ggi-pend-alert" style="margin:6px 0 2px;" data-pop-hover
            aria-label="<?= $_pend['total'] ?> pendiente<?= $_pend['total'] !== 1 ? 's' : '' ?> de atención. Ver desglose"
            data-ggipop="<?= htmlspecialchars(gg_pendientes_popover_html($_pend), ENT_QUOTES) ?>"
            onclick="ggiChipPop(this)"
            onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();ggiChipPop(this);}">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <?= $_pend['total'] ?> pendiente<?= $_pend['total'] !== 1 ? 's' : '' ?> de atención
    </button>
    <?php endif; ?>
    <div class="cliente-meta">
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/></svg> RUC: <strong><?= sanitize($c['ruc']) ?></strong></div>
      <?php if (!empty($c['num_trabajadores'])): ?>
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Trabajadores: <strong><?= (int)$c['num_trabajadores'] ?></strong></div>
      <?php endif; ?>
      <?php
        // Mostrar "distrito, departamento" si están desglosados; si no, usar el campo dirección.
        $_ubicacion = trim(implode(', ', array_filter([$c['distrito'] ?? '', $c['departamento'] ?? ''])));
        if ($_ubicacion === '') $_ubicacion = trim($c['direccion'] ?? '');
      ?>
      <?php if ($_ubicacion !== ''): ?>
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> <strong><?= sanitize($_ubicacion) ?></strong></div>
      <?php endif; ?>
      <?php if ($c['contacto_nombre']): ?>
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <strong><?= sanitize($c['contacto_nombre'].' '.$c['contacto_apellido']) ?></strong><?= $c['contacto_cargo'] ? ' — '.sanitize($c['contacto_cargo']) : '' ?></div>
      <?php endif; ?>
      <?php if ($c['contacto_telefono']): ?>
      <div class="meta-item"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.62 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <strong><?= sanitize($c['contacto_telefono']) ?></strong><?php if (!empty($c['horario_contacto'])): ?> <span style="color:var(--s-warn-tx,#92400e);font-size:11.5px;">· ⏰ <?= sanitize($c['horario_contacto']) ?></span><?php endif; ?></div>
      <?php endif; ?>
      <?php if ($ej_responsable): ?>
      <div class="meta-item">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Responsable: <strong><?= sanitize($ej_responsable['nombre'].' '.$ej_responsable['apellido']) ?></strong>
        <?php if ($ej_captador && $ej_captador !== $ej_responsable): ?>
          <span style="color:var(--gris-texto);font-weight:400;">· Captador: <?= sanitize($ej_captador['nombre'].' '.$ej_captador['apellido']) ?></span>
        <?php endif; ?>
      </div>
      <?php elseif ($ej_captador): ?>
      <div class="meta-item">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Ejecutivo: <strong><?= sanitize($ej_captador['nombre'].' '.$ej_captador['apellido']) ?></strong>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="cliente-actions">
    <?php if (!empty($docs)): ?>
    <button type="button" id="btn-dl-documentos" onclick="descargarArchivos(<?= $id ?>,'documentos',this)" class="btn btn-outline btn-sm" title="Descargar todos los documentos">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Docs
    </button>
    <?php endif; ?>
    <?php if ($cnt_certs > 0): ?>
    <button type="button" id="btn-dl-certificados" onclick="descargarArchivos(<?= $id ?>,'certificados',this)" class="btn btn-outline btn-sm" title="Descargar todos los certificados">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      Certs
    </button>
    <?php endif; ?>
    <?php if ($c['portal_token']): ?>
    <a href="<?= APP_URL ?>/modules/clientes/portal_link.php?id=<?= $id ?>"
       target="_blank"
       class="btn btn-outline btn-sm" title="Ver portal del cliente"
       style="color:<?= $c['portal_activo'] ? 'var(--s-ok-tx)' : '#94a3b8' ?>;border-color:<?= $c['portal_activo'] ? 'var(--s-ok-brd)' : '#e2e8f0' ?>;background:<?= $c['portal_activo'] ? 'var(--s-ok-bg)' : 'var(--bg-surface2)' ?>;">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Portal
    </a>
    <?php endif; ?>
    <button type="button" class="btn btn-outline btn-sm" onclick="ggiAbrirLlamada()" title="Agendar llamada con recordatorio">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.62 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
      Llamada
    </button>
    <button type="button" class="btn btn-outline btn-sm" onclick="ggiAbrirEmpresa('edit', <?= (int)$id ?>)">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Editar
    </button>
  </div>
</div>

<?php
// Agenda de llamadas: partial compartido (bloque de abiertas + modal + JS).
$_llam_cliente_id = (int)$id;
$_llam_cot_id     = 0;
$_llam_empresa    = $c['razon_social'];
$_llam_horario    = $c['horario_contacto'] ?? '';
$_llam_abiertas   = $llamadas_abiertas;
include __DIR__ . '/_llamada_modal.php';
?>

<!-- Tabs -->
<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:20px;">
<div class="tabs" id="tabs-nav" style="margin-bottom:0;white-space:nowrap;width:max-content;">
  <button type="button" class="tab active" data-tab="documentos"    onclick="window.switchTab('documentos')">Documentos</button>
  <button type="button" class="tab" data-tab="certificados"         onclick="window.switchTab('certificados')">Certificados (<?= $cnt_certs ?>)</button>
  <button type="button" class="tab" data-tab="pagos"                onclick="window.switchTab('pagos')">
    Pagos (<?= $cnt_pagos ?>)
    <?php if ($cnt_pagos_pend > 0 && $user['rol'] === 'gerente_general'): ?>
    <span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;background:var(--s-err);color:#fff;border-radius:50%;font-size:10px;font-weight:700;margin-left:4px;"><?= $cnt_pagos_pend ?></span>
    <?php endif; ?>
  </button>
  <button type="button" class="tab" data-tab="llamadas"             onclick="window.switchTab('llamadas')">Llamadas (<?= $cnt_llamadas ?>)</button>
  <button type="button" class="tab" data-tab="propuestas"           onclick="window.switchTab('propuestas')">Propuestas (<?= $cnt_cots ?>)</button>
  <button type="button" class="tab" data-tab="historial"            onclick="window.switchTab('historial')">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:-1px;margin-right:4px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    Historial
  </button>
  <?php if (has_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial')): ?>
  <button type="button" class="tab" data-tab="portal"               onclick="window.switchTab('portal')">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:-1px;margin-right:4px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    Portal
  </button>
  <?php endif; ?>
</div>
</div>

<!-- Tab: Documentos -->
<div id="tab-documentos" class="tab-panel" style="display:block">

  <!-- Documentos requeridos -->
  <div style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
    Documentos requeridos
  </div>
  <div class="docs-grid" style="margin-bottom:24px;">
    <?php foreach ($tipos_doc as $tipo => $label): ?>
    <?php $doc = $docs_por_tipo[$tipo] ?? null; ?>
    <div class="doc-card <?= $doc ? 'uploaded' : '' ?>" id="doc-card-<?= $tipo ?>">
      <input type="file" id="file-inp-<?= $tipo ?>" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none"
             onchange="subirDocInline(this,'<?= $tipo ?>',<?= $id ?>,<?= (int)($doc['version']??0) ?>)">
      <div class="doc-header">
        <div class="doc-tipo"><?= $label ?></div>
        <?php if ($doc): ?>
        <div class="doc-status-ok" id="doc-status-<?= $tipo ?>"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
        <?php else: ?>
        <div class="doc-status-empty" id="doc-status-<?= $tipo ?>"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="var(--gris-texto)" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
        <?php endif; ?>
      </div>
      <?php if ($doc): ?>
        <div class="doc-filename" id="doc-fname-<?= $tipo ?>" title="<?= sanitize($doc['nombre_archivo']) ?>">
          <?= sanitize($doc['nombre_archivo']) ?>
          <span style="color:var(--gris-texto);font-size:10px;" id="doc-ver-<?= $tipo ?>"> v<?= $doc['version'] ?></span>
        </div>
        <div class="doc-actions">
          <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc['id'] ?>" data-ggi-preview data-src="<?= APP_URL ?>/modules/documentos/stream.php?id=<?= $doc['id'] ?>" data-download-url="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc['id'] ?>&descargar=1" data-title="<?= sanitize($doc['nombre_archivo']) ?>" class="btn btn-outline btn-sm">Ver</a>
          <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('file-inp-<?= $tipo ?>').click()" title="Reemplazar archivo">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Actualizar
          </button>
        </div>
      <?php else: ?>
        <div class="doc-upload-area" onclick="document.getElementById('file-inp-<?= $tipo ?>').click()">
          <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.5;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <span style="font-size:12px;font-weight:600;color:var(--gris-dark);pointer-events:none;">Clic o arrastra aquí</span>
          <span style="font-size:10px;color:var(--gris-texto);margin-top:2px;pointer-events:none;" id="doc-fname-<?= $tipo ?>">PDF, JPG, PNG · máx. 10 MB</span>
        </div>
      <?php endif; ?>
      <div id="doc-progress-<?= $tipo ?>" style="display:none;margin-top:8px;height:3px;background:var(--gris-borde);border-radius:100px;overflow:hidden;">
        <div style="height:100%;background:var(--azul);width:0%;transition:width 0.4s ease;" id="doc-pbar-<?= $tipo ?>"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Documentos adicionales -->
  <div style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
    Adicionales
    <span class="chip chip-neutral chip-sm" style="text-transform:none;letter-spacing:0;">opcionales</span>
  </div>
  <div class="docs-grid">
    <?php foreach ($tipos_doc_adicionales as $tipo => $label): ?>
    <?php $doc = $docs_por_tipo[$tipo] ?? null; ?>
    <div class="doc-card <?= $doc ? 'uploaded' : '' ?>" id="doc-card-<?= $tipo ?>">
      <input type="file" id="file-inp-<?= $tipo ?>" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none"
             onchange="subirDocInline(this,'<?= $tipo ?>',<?= $id ?>,<?= (int)($doc['version']??0) ?>)">
      <div class="doc-header">
        <div class="doc-tipo"><?= $label ?></div>
        <?php if ($doc): ?>
        <div class="doc-status-ok" id="doc-status-<?= $tipo ?>"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
        <?php else: ?>
        <div class="doc-status-empty" id="doc-status-<?= $tipo ?>"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="var(--gris-texto)" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
        <?php endif; ?>
      </div>
      <?php if ($doc): ?>
        <div class="doc-filename" id="doc-fname-<?= $tipo ?>" title="<?= sanitize($doc['nombre_archivo']) ?>">
          <?= sanitize($doc['nombre_archivo']) ?>
          <span style="color:var(--gris-texto);font-size:10px;" id="doc-ver-<?= $tipo ?>"> v<?= $doc['version'] ?></span>
        </div>
        <div class="doc-actions">
          <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc['id'] ?>" data-ggi-preview data-src="<?= APP_URL ?>/modules/documentos/stream.php?id=<?= $doc['id'] ?>" data-download-url="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc['id'] ?>&descargar=1" data-title="<?= sanitize($doc['nombre_archivo']) ?>" class="btn btn-outline btn-sm">Ver</a>
          <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('file-inp-<?= $tipo ?>').click()" title="Reemplazar archivo">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Actualizar
          </button>
        </div>
      <?php else: ?>
        <div class="doc-upload-area" onclick="document.getElementById('file-inp-<?= $tipo ?>').click()">
          <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.5;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <span style="font-size:12px;font-weight:600;color:var(--gris-dark);pointer-events:none;">Clic o arrastra aquí</span>
          <span style="font-size:10px;color:var(--gris-texto);margin-top:2px;pointer-events:none;" id="doc-fname-<?= $tipo ?>">PDF, JPG, PNG · máx. 10 MB</span>
        </div>
      <?php endif; ?>
      <div id="doc-progress-<?= $tipo ?>" style="display:none;margin-top:8px;height:3px;background:var(--gris-borde);border-radius:100px;overflow:hidden;">
        <div style="height:100%;background:var(--azul);width:0%;transition:width 0.4s ease;" id="doc-pbar-<?= $tipo ?>"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($user['rol'] === 'gerente_general'): ?>
  <?php $doc_att = $docs_por_tipo['attendance_sheet'] ?? null; ?>
  <!-- Attendance Sheet — solo Gerente General -->
  <div style="margin-top:28px;">
    <div style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
      Documentos generados
      <span class="chip chip-neutral chip-sm" style="text-transform:none;letter-spacing:0;">sistema</span>
    </div>
    <div id="att-card" style="background:var(--gris-card);border:1px solid var(--gris-borde);border-radius:12px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:36px;height:36px;border-radius:8px;background:var(--s-info-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#6366F1" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        <div>
          <div style="font-weight:600;font-size:13px;color:var(--gris-dark);">Attendance Sheet</div>
          <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;" id="att-meta">
            <?php if ($doc_att): ?>
              v<?= $doc_att['version'] ?> &mdash; <?= sanitize($doc_att['nombre_archivo']) ?>
            <?php else: ?>
              No generado aún
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php if ($doc_att): ?>
        <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_att['id'] ?>" data-ggi-preview data-src="<?= APP_URL ?>/modules/documentos/stream.php?id=<?= $doc_att['id'] ?>" data-download-url="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_att['id'] ?>&descargar=1" data-title="Documento adjunto" class="btn btn-outline btn-sm" id="att-ver-btn">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          Ver
        </a>
        <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_att['id'] ?>&descargar=1" class="btn btn-outline btn-sm" download id="att-dl-btn">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Descargar
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary btn-sm" id="att-gen-btn" onclick="generarAttendance(<?= $id ?>)">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          <?= $doc_att ? 'Regenerar' : 'Generar' ?>
        </button>
      </div>
    </div>
    <div id="att-msg" style="display:none;margin-top:8px;font-size:12px;padding:8px 12px;border-radius:8px;"></div>
  </div>

  <?php
  $doc_app   = $docs_por_tipo['application_form'] ?? null;
  $app_previo = ($doc_app && !empty($doc_app['alcance_texto']))
                ? (json_decode($doc_app['alcance_texto'], true) ?: null)
                : null;
  // Pre-llenado del modal Application Form.
  // CONTACTO (teléfono/correo): la EMPRESA es la fuente de verdad → se prioriza el
  // dato actual de la empresa; solo si está vacío se cae al del form anterior.
  // (Antes, un Application Form previo con correo viejo pisaba el correo recién
  // cargado en la empresa, porque el JS sobreescribe el input con `prevEmail`.)
  $_app_phone = !empty($c['contacto_telefono']) ? $c['contacto_telefono'] : ($app_previo['phone'] ?? '');
  $_app_email = !empty($c['contacto_email'])    ? $c['contacto_email']    : ($app_previo['email'] ?? '');
  // ALCANCE e ISOs: sí se preservan del form anterior (texto autorado / selección).
  $_app_alces = $app_previo['alcance_es'] ?? '';
  $_app_alcen = $app_previo['alcance_en'] ?? '';
  $_app_isos  = array_map('intval', $app_previo['isos'] ?? $normas_aceptadas_ids);
  ?>
  <!-- Application Form — solo Gerente General -->
  <div style="margin-top:12px;">
    <div id="app-card" style="background:var(--gris-card);border:1px solid var(--gris-borde);border-radius:12px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:36px;height:36px;border-radius:8px;background:var(--s-warn-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#F97316" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        <div>
          <div style="font-weight:600;font-size:13px;color:var(--gris-dark);">Application Form</div>
          <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;" id="app-meta">
            <?php if ($doc_app): ?>
              v<?= $doc_app['version'] ?> &mdash; <?= sanitize($doc_app['nombre_archivo']) ?>
            <?php else: ?>
              No generado aún
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php if ($doc_app): ?>
        <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_app['id'] ?>" data-ggi-preview data-src="<?= APP_URL ?>/modules/documentos/stream.php?id=<?= $doc_app['id'] ?>" data-download-url="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_app['id'] ?>&descargar=1" data-title="Documento de aplicación" class="btn btn-outline btn-sm" id="app-ver-btn">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          Ver
        </a>
        <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_app['id'] ?>&descargar=1" class="btn btn-outline btn-sm" download id="app-dl-btn">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Descargar
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary btn-sm" id="app-gen-btn"
                style="background:#F97316;border-color:#F97316;"
                onclick="abrirModalApplication(<?= htmlspecialchars(json_encode($_app_phone), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($_app_email), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($_app_alces), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($_app_alcen), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($_app_isos), ENT_QUOTES, 'UTF-8') ?>)">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          <?= $doc_app ? 'Regenerar' : 'Generar' ?>
        </button>
      </div>
    </div>
    <div id="app-msg" style="display:none;margin-top:8px;font-size:12px;padding:8px 12px;border-radius:8px;"></div>
  </div>

  <?php endif; ?>

  <?php
  $es_ejecutivo    = ($user['rol'] === 'ejecutivo_ventas');
  $puede_gestionar = has_role('gerente_general', 'gerente_operaciones', 'gerente_comercial');
  $acta_confirmada = $doc_acta_vigente && $doc_acta_vigente['doc_confirmada'];
  $acta_es_legacy  = $doc_acta_vigente && !$doc_acta_vigente['doc_cot_id'];
  // Tratar legacy (sin cotizacion_id) como aprobada
  if ($acta_es_legacy) $acta_confirmada = true;
  // Sección visible: gerentes siempre (si hay última cot), ejecutivo si hay acta (borrador o aprobada)
  $seccion_visible = ($puede_gestionar && $ultima_cot) || ($es_ejecutivo && $doc_acta_vigente);
  ?>
  <?php if ($seccion_visible): ?>
  <?php
    $confirmada         = $acta_confirmada;
    $puede_regenerar    = $puede_gestionar;   // gerentes siempre pueden generar/regenerar
    $acta_pendiente_cambios = $doc_acta_vigente && ($doc_acta_vigente['doc_estado'] === 'pendiente_cambios');
    // Responsable con el MISMO fallback que el endpoint (COALESCE responsible_user_id, ejecutivo_id):
    // sin él, en clientes legacy (responsible_user_id NULL) el ejecutivo asignado no veía el
    // botón Aprobar aunque el endpoint sí lo permite → "no podía hacer nada" al generar el acta.
    $es_responsable     = $es_ejecutivo && $user['id'] == $_responsable_id;
    $puede_confirmar    = ($puede_gestionar || $es_responsable) && $doc_acta_vigente && !$confirmada && !$acta_pendiente_cambios;
    $isos_label         = implode(', ', array_column($isos_ultima_cot, 'codigo'));
    // ¿El acta aplica según las normas de las propuestas aceptadas? (si ya hay acta
    // generada, se muestra igual; el "no aplica" solo cambia el estado vacío).
    $acta_aplica        = function_exists('acta_alcance_requerida') ? acta_alcance_requerida($pdo, (int)$id) : true;
  ?>
  <div style="margin-top:<?= $user['rol'] === 'gerente_general' ? '12px' : '28px' ?>;">
    <div style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
      Acta de Aprobación de Alcance
    </div>
    <div id="acta-card" style="background:var(--gris-card);border:1px solid var(--gris-borde);border-radius:12px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:12px;min-width:0;flex:1;">
        <div style="width:36px;height:36px;border-radius:8px;background:<?= $confirmada ? 'var(--s-ok-bg)' : '#F8FAFF' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <?php if ($confirmada): ?>
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#16a34a" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          <?php elseif ($doc_acta_vigente): ?>
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#3b82f6" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <?php else: ?>
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#94a3b8" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <?php endif; ?>
        </div>
        <div style="min-width:0;">
          <div style="font-weight:600;font-size:13px;color:var(--gris-dark);display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            Acta de Aprobación de Alcance
            <?php if ($confirmada): ?>
            <span style="font-size:10px;font-weight:700;background:var(--s-ok-bg);color:var(--s-ok-tx);padding:1px 7px;border-radius:5px;">APROBADA</span>
            <?php if ($acta_es_legacy): ?>
            <span style="font-size:10px;font-weight:600;background:var(--s-info-bg);color:var(--s-info-tx);padding:1px 7px;border-radius:5px;">CARGADO MANUAL</span>
            <?php endif; ?>
            <?php elseif ($acta_pendiente_cambios): ?>
            <span style="font-size:10px;font-weight:700;background:var(--s-warn-bg);color:var(--s-warn-tx);padding:1px 7px;border-radius:5px;">⚠️ CAMBIOS PENDIENTES</span>
            <?php elseif ($doc_acta_vigente): ?>
            <span style="font-size:10px;font-weight:700;background:var(--s-info-bg);color:#1e40af;padding:1px 7px;border-radius:5px;">BORRADOR</span>
            <?php elseif (!$acta_aplica): ?>
            <span style="font-size:10px;font-weight:700;background:var(--gris-bg,#f1f5f9);color:var(--gris-texto,#64748b);padding:1px 7px;border-radius:5px;">NO APLICA</span>
            <?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;">
            <?php if ($doc_acta_vigente): ?>
              <?= sanitize($doc_acta_vigente['doc_nombre']) ?>
              <?php if ($acta_es_legacy): ?>
              &mdash; <em>Cargado manualmente</em>
              <?php elseif ($confirmada && $doc_acta_vigente['doc_confirmada_at']): ?>
              &mdash; Aprobada <?= date('d/m/Y H:i', strtotime($doc_acta_vigente['doc_confirmada_at'])) ?>
              <?php if ($doc_acta_vigente['conf_nombre']): ?>
                por <?= sanitize($doc_acta_vigente['conf_nombre'] . ' ' . $doc_acta_vigente['conf_apellido']) ?>
              <?php endif; ?>
              <?php endif; ?>
            <?php elseif (!$acta_aplica): ?>
              No aplica para las normas de esta empresa.
            <?php else: ?>
              Sin acta generada — ISOs: <?= $isos_label ?: 'sin normas en propuesta' ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex-shrink:0;">
        <?php if ($doc_acta_vigente): ?>
        <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_acta_vigente['doc_id'] ?>" data-ggi-preview data-src="<?= APP_URL ?>/modules/documentos/stream.php?id=<?= $doc_acta_vigente['doc_id'] ?>" data-download-url="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_acta_vigente['doc_id'] ?>&descargar=1" data-title="Acta de alcance" class="btn btn-outline btn-sm">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          Ver
        </a>
        <a href="<?= APP_URL ?>/modules/documentos/ver.php?id=<?= $doc_acta_vigente['doc_id'] ?>&descargar=1" class="btn btn-outline btn-sm" download>
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Descargar
        </a>
        <?php endif; ?>
        <?php if ($puede_regenerar && $isos_ultima_cot && $acta_aplica): ?>
        <button type="button" class="btn btn-primary btn-sm"
                style="background:#16a34a;border-color:#16a34a;"
                onclick="abrirModalActa(<?= $id ?>, <?= (int)($doc_acta_vigente['doc_id'] ?? 0) ?>, <?= htmlspecialchars(json_encode($doc_acta_vigente['doc_alcance'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode(array_column($isos_ultima_cot, 'norma_iso_id')), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode(array_column($isos_ultima_cot, 'codigo')), ENT_QUOTES, 'UTF-8') ?>)">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          <?= $doc_acta_vigente ? 'Regenerar' : 'Generar' ?>
        </button>
        <?php endif; ?>
        <?php if ($puede_confirmar): ?>
        <button type="button" class="btn btn-primary btn-sm"
                style="background:#0f766e;border-color:#0f766e;"
                onclick='confirmarActa(<?= $doc_acta_vigente['doc_id'] ?>, "acta", "acta-msg", "acta-card", <?= json_encode($doc_acta_vigente['doc_alcance'] ?? 'Sin especificar') ?>)'>
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Aprobar
        </button>
        <?php endif; ?>
      </div>
    </div>
    <div id="acta-msg" style="display:none;margin-top:8px;font-size:12px;padding:8px 12px;border-radius:8px;"></div>
    <?php if ($acta_pendiente_cambios && $es_ejecutivo): ?>
    <div style="margin-top:12px;padding:12px;background:var(--s-warn-bg);border:1px solid var(--s-warn-brd);border-radius:8px;border-left:3px solid var(--s-warn);font-size:12px;color:var(--s-warn-tx);">
      ⚠️ <strong>Acta pendiente de regeneración:</strong> Se han solicitado cambios. Espera a que un gerente regenere el acta para poder aprobarla.
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<!-- Tab: Pagos -->
<div id="tab-pagos" class="tab-panel" style="display:none" data-lazy="1">
<div style="text-align:center;padding:48px 20px;color:var(--gris-texto);"><svg style="animation:tab-spin .8s linear infinite;opacity:0.35;" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></div>
</div>

<!-- Modales para validar / rechazar pagos (fuera del tab-panel para mayor estabilidad) -->
<div id="modal-val-pago" class="ggi-modal-overlay" style="z-index:9100;background:rgba(0,0,0,.45);">
  <div style="background:var(--bg-surface);border-radius:14px;padding:28px 28px 22px;max-width:400px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.18);">
    <div style="font-size:15px;font-weight:700;color:var(--gris-dark);margin-bottom:10px;display:flex;align-items:center;gap:8px;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#22c55e" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/><circle cx="12" cy="12" r="10"/></svg>
      Confirmar validación
    </div>
    <p id="modal-val-pago-msg" style="font-size:13px;color:var(--gris-texto);margin:0 0 20px;"></p>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button type="button" id="btn-val-cancelar" class="ggi-btn-confirm-cancel">Cancelar</button>
      <button type="button" id="btn-val-confirmar" style="padding:8px 20px;border-radius:8px;border:none;background:#22c55e;color:#fff;font-size:13px;font-weight:700;cursor:pointer;">Validar pago</button>
    </div>
  </div>
</div>

<div id="modal-rec-pago" class="ggi-modal-overlay" style="z-index:9100;background:rgba(0,0,0,.45);">
  <div style="background:var(--bg-surface);border-radius:14px;padding:28px 28px 22px;max-width:420px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.18);">
    <div style="font-size:15px;font-weight:700;color:var(--gris-dark);margin-bottom:10px;display:flex;align-items:center;gap:8px;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#ef4444" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Rechazar pago
    </div>
    <p id="modal-rec-pago-msg" style="font-size:13px;color:var(--gris-texto);margin:0 0 12px;"></p>
    <textarea id="modal-rec-motivo" rows="3" placeholder="Motivo del rechazo (obligatorio)…" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--gris-borde);border-radius:8px;font-size:13px;resize:vertical;font-family:inherit;"></textarea>
    <div id="modal-rec-error" style="display:none;color:#ef4444;font-size:11px;margin-top:4px;">El motivo es obligatorio.</div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
      <button type="button" id="btn-rec-cancelar" class="ggi-btn-confirm-cancel">Cancelar</button>
      <button type="button" id="btn-rec-confirmar" style="padding:8px 20px;border-radius:8px;border:none;background:#ef4444;color:#fff;font-size:13px;font-weight:700;cursor:pointer;">Rechazar</button>
    </div>
  </div>
</div>

<!-- Tab: Certificados -->
<div id="tab-certificados" class="tab-panel" style="display:none" data-lazy="1">
<div style="text-align:center;padding:48px 20px;color:var(--gris-texto);"><svg style="animation:tab-spin .8s linear infinite;opacity:0.35;" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></div>
</div>

<!-- Tab: Llamadas -->
<div id="tab-llamadas" class="tab-panel" style="display:none" data-lazy="1">
<div style="text-align:center;padding:48px 20px;color:var(--gris-texto);"><svg style="animation:tab-spin .8s linear infinite;opacity:0.35;" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></div>
</div>

<!-- Tab: Propuestas -->
<div id="tab-propuestas" class="tab-panel" style="display:none" data-lazy="1">
<div style="text-align:center;padding:48px 20px;color:var(--gris-texto);"><svg style="animation:tab-spin .8s linear infinite;opacity:0.35;" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></div>
</div>

<!-- Tab: Historial / Timeline -->
<div id="tab-historial" class="tab-panel" style="display:none" data-lazy="1">
<div style="text-align:center;padding:48px 20px;color:var(--gris-texto);"><svg style="animation:tab-spin .8s linear infinite;opacity:0.35;" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></div>
</div>

<?php if (has_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial')): ?>
<!-- Tab: Portal del cliente -->
<div id="tab-portal" class="tab-panel" style="display:none">
  <div style="background:var(--bg-surface);border:1px solid var(--gris-borde);border-radius:12px;padding:22px;max-width:760px;">
    <div style="font-size:14px;font-weight:700;color:var(--azul);display:flex;align-items:center;gap:8px;margin-bottom:8px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Portal de seguimiento del cliente
    </div>
    <p style="font-size:13px;color:var(--gris-texto);margin-bottom:16px;">
      Enlace único y seguro para que <strong><?= sanitize($c['razon_social']) ?></strong> consulte sus certificados, auditorías y envíos sin una cuenta en el sistema.
    </p>
    <?php
      $_ptok = $c['portal_token'] ?? '';
      $_pact = !empty($c['portal_activo']) && $_ptok;
      $_purl = $_pact ? APP_URL.'/portal/?token='.$_ptok : '';
    ?>
    <?php
      $_ic_copy = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
      $_ic_ext  = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
      $_ic_x    = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
      $_ic_ref  = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
    ?>
    <div id="portal-box"><?php if ($_pact): ?>
      <div style="background:var(--s-ok-bg);border:1px solid var(--s-ok-brd,#a7f3d0);border-radius:10px;padding:14px;">
        <div style="font-weight:700;color:var(--s-ok-tx);font-size:13px;">Portal activo</div>
        <div style="font-size:12px;color:var(--s-ok-tx);margin:2px 0 8px;">Comparte este enlace con el cliente.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <input type="text" id="portal-url-input" readonly value="<?= htmlspecialchars($_purl) ?>" style="flex:1;min-width:200px;padding:8px 10px;border:1px solid var(--s-ok-brd,#a7f3d0);border-radius:8px;font-size:12px;background:#fff;color:var(--gris-dark);">
          <button type="button" data-pa="copiar" class="btn btn-outline btn-sm"><?= $_ic_copy ?>Copiar</button>
          <a href="<?= htmlspecialchars($_purl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm"><?= $_ic_ext ?>Ver portal</a>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
        <button type="button" data-pa="desactivar" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#fca5a5;"><?= $_ic_x ?>Desactivar portal</button>
        <button type="button" data-pa="regenerar" class="btn btn-outline btn-sm"><?= $_ic_ref ?>Renovar enlace</button>
      </div>
    <?php else: ?>
      <div style="background:var(--bg-surface2);border:1px solid var(--gris-borde);border-radius:10px;padding:14px;color:var(--gris-texto);font-size:13px;margin-bottom:12px;">El portal está desactivado. Actívalo para generar el enlace del cliente.</div>
      <button type="button" data-pa="activar" class="btn btn-primary btn-sm"><?= $_ic_ref ?>Activar portal</button>
    <?php endif; ?></div>
    <div style="margin-top:18px;border-top:1px solid var(--gris-borde);padding-top:14px;">
      <div style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px;">Qué puede ver el cliente</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
        <div style="border:1px solid var(--gris-borde);border-radius:9px;padding:12px;"><div style="font-size:13px;font-weight:600;color:var(--gris-dark);">Certificados</div><div style="font-size:12px;color:var(--gris-texto);">ISO vigentes y por vencer</div></div>
        <div style="border:1px solid var(--gris-borde);border-radius:9px;padding:12px;"><div style="font-size:13px;font-weight:600;color:var(--gris-dark);">Auditorías</div><div style="font-size:12px;color:var(--gris-texto);">Próximas y pasadas</div></div>
        <div style="border:1px solid var(--gris-borde);border-radius:9px;padding:12px;"><div style="font-size:13px;font-weight:600;color:var(--gris-dark);">Descarga</div><div style="font-size:12px;color:var(--gris-texto);">Archivos de certificados (ES/EN)</div></div>
      </div>
    </div>
  </div>
</div>
<script>
/* Controlador aislado del Portal (independiente del bloque JS grande; listener atado al #portal-box) */
(function(){
  var _act = <?= $_pact ? 'true' : 'false' ?>;
  var _url = <?= $_pact ? json_encode($_purl) : "''" ?>;
  var _cid = <?= (int)$id ?>;
  var IC = {
    copy:'<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
    ext:'<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
    x:'<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    refresh:'<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>'
  };
  function toast(m,t){ if(typeof showToast==='function') showToast(m,t||'ok'); }
  function render(){
    var box=document.getElementById('portal-box'); if(!box) return;
    if(_act && _url){
      var u=_url.replace(/"/g,'&quot;');
      box.innerHTML =
        '<div style="background:var(--s-ok-bg);border:1px solid var(--s-ok-brd,#a7f3d0);border-radius:10px;padding:14px;">'
        + '<div style="font-weight:700;color:var(--s-ok-tx);font-size:13px;">Portal activo</div>'
        + '<div style="font-size:12px;color:var(--s-ok-tx);margin:2px 0 8px;">Comparte este enlace con el cliente.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
        +   '<input type="text" id="portal-url-input" readonly value="'+u+'" style="flex:1;min-width:200px;padding:8px 10px;border:1px solid var(--s-ok-brd,#a7f3d0);border-radius:8px;font-size:12px;background:#fff;color:var(--gris-dark);">'
        +   '<button type="button" data-pa="copiar" class="btn btn-outline btn-sm">'+IC.copy+'Copiar</button>'
        +   '<a href="'+u+'" target="_blank" rel="noopener" class="btn btn-outline btn-sm">'+IC.ext+'Ver portal</a>'
        + '</div></div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">'
        +   '<button type="button" data-pa="desactivar" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#fca5a5;">'+IC.x+'Desactivar portal</button>'
        +   '<button type="button" data-pa="regenerar" class="btn btn-outline btn-sm">'+IC.refresh+'Renovar enlace</button>'
        + '</div>';
    } else {
      box.innerHTML =
        '<div style="background:var(--bg-surface2);border:1px solid var(--gris-borde);border-radius:10px;padding:14px;color:var(--gris-texto);font-size:13px;margin-bottom:12px;">El portal está desactivado. Actívalo para generar el enlace del cliente.</div>'
        + '<button type="button" data-pa="activar" class="btn btn-primary btn-sm">'+IC.refresh+'Activar portal</button>';
    }
  }
  function doAction(pa){
    ggiPost('<?= APP_URL ?>/modules/clientes/portal_toggle.php', { cliente_id: _cid, accion: pa }, { silent: true })
    .then(function(d){
      if(d.ok){
        _act=!!d.portal_activo; _url=d.portal_url||''; render();
        toast(d.msg||'Actualizado.','ok');
        if(pa==='regenerar' && _url && navigator.clipboard){ navigator.clipboard.writeText(_url).then(function(){toast('Nuevo enlace copiado','ok');}).catch(function(){}); }
      } else { toast(d.msg||d.mensaje||'No se pudo actualizar el portal.','error'); }
    })
    .catch(function(){ toast('Error de conexión.','error'); });
  }
  var box=document.getElementById('portal-box');
  if(box && !box.__paBound){
    box.__paBound=true;
    box.addEventListener('click',function(e){
      var el=e.target.closest?e.target.closest('[data-pa]'):null; if(!el) return;
      e.preventDefault();
      var pa=el.getAttribute('data-pa');
      if(pa==='copiar'){
        var inp=document.getElementById('portal-url-input'); if(!inp) return;
        if(navigator.clipboard){ navigator.clipboard.writeText(inp.value).then(function(){toast('Enlace copiado','ok');}).catch(function(){ inp.select(); try{document.execCommand('copy');toast('Enlace copiado','ok');}catch(_){}}); }
        else { inp.select(); try{document.execCommand('copy');toast('Enlace copiado','ok');}catch(_){} }
        return;
      }
      var msgs={activar:'¿Activar el portal del cliente?',desactivar:'¿Desactivar el portal? El enlace dejará de funcionar.',regenerar:'Se generará un enlace nuevo y el anterior dejará de funcionar. ¿Continuar?'};
      if(typeof GGIConfirm!=='undefined' && GGIConfirm.show){ GGIConfirm.show({icon:'warning',title:'Portal del cliente',message:msgs[pa]||'¿Continuar?',confirmText:'Confirmar',onConfirm:function(){doAction(pa);}}); }
      else if(window.confirm(msgs[pa]||'¿Continuar?')){ doAction(pa); }
    });
  }
})();
</script>
<?php endif; ?>


<script>
  window.__doc_cliente_id = <?= $id ?>;
  window.__normas = <?= json_encode(array_map(fn($n) => ['id' => $n['id'], 'label' => $n['codigo'] . ' — ' . $n['nombre']], $normas_all), JSON_UNESCAPED_UNICODE) ?>;
  window.__casas  = <?= json_encode(array_map(fn($c) => ['id' => $c['id'], 'label' => $c['nombre']], $casas_all), JSON_UNESCAPED_UNICODE) ?>;

  // ── Popup: Nuevo certificado ──
  window.abrirNuevoCert = function() {
    var normasOpts = '<option value="">— Selecciona una norma —</option>';
    (window.__normas || []).forEach(function(n) {
      normasOpts += '<option value="' + n.id + '">' + n.label + '</option>';
    });
    var casasOpts = '<option value="">— Sin especificar —</option>';
    (window.__casas || []).forEach(function(c) {
      casasOpts += '<option value="' + c.id + '">' + c.label + '</option>';
    });

    var html = '<form id="form-nuevo-cert" autocomplete="off">'
      + '<div style="display:grid;gap:14px;padding:4px 0;">'

      + '<div>'
      +   '<label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:5px;">Norma ISO <span style="color:#e53e3e">*</span></label>'
      +   '<select name="norma_iso_id" class="form-control" required style="width:100%">' + normasOpts + '</select>'
      + '</div>'

      + '<div>'
      +   '<label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:5px;">Casa certificadora</label>'
      +   '<select name="casa_certificadora_id" class="form-control" style="width:100%">' + casasOpts + '</select>'
      + '</div>'

      + '<div>'
      +   '<label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:5px;">N° de certificado</label>'
      +   '<input type="text" name="numero_certificado" class="form-control" placeholder="Ej: ISO-2024-001" style="width:100%">'
      + '</div>'

      + '<div>'
      +   '<label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:5px;">Fecha de emisión</label>'
      +   '<input type="date" name="fecha_emision" class="form-control" style="width:100%">'
      +   '<p style="font-size:11px;color:var(--gris-texto);margin:4px 0 0;">Las fechas de vencimiento y auditorías se calcularán automáticamente.</p>'
      + '</div>'

      <?php if (has_role('gerente_general','gerente_operaciones')): ?>
      + '<div>'
      +   '<label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:5px;">Estado</label>'
      +   '<select name="estado" class="form-control" style="width:100%">'
      +     '<option value="en_proceso">En proceso</option>'
      +     '<option value="vigente">Vigente</option>'
      +     '<option value="por_vencer">Por vencer</option>'
      +     '<option value="suspendido">Suspendido</option>'
      +   '</select>'
      + '</div>'
      <?php endif; ?>

      + '<div id="nuevo-cert-error" style="display:none;background:var(--s-err-bg);border:1px solid var(--s-err-brd);color:var(--s-err);padding:10px 12px;border-radius:6px;font-size:13px;"></div>'

      + '<div style="display:flex;justify-content:flex-end;gap:10px;padding-top:4px;">'
      +   '<button type="button" class="btn btn-outline" onclick="mantClose()">Cancelar</button>'
      +   '<button type="submit" class="btn btn-primary" id="btn-guardar-cert">'
      +     '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><polyline points="20 6 9 20 4 14"/></svg>'
      +     'Guardar certificado'
      +   '</button>'
      + '</div>'
      + '</div>'
      + '</form>';

    mantOpen('Nuevo certificado', html);

    document.getElementById('form-nuevo-cert').addEventListener('submit', function(e) {
      e.preventDefault();
      var btn = document.getElementById('btn-guardar-cert');
      var errBox = document.getElementById('nuevo-cert-error');
      btn.disabled = true;
      btn.textContent = 'Guardando…';
      errBox.style.display = 'none';

      var fd = new FormData(this);
      fd.append('cliente_id', window.__doc_cliente_id);
      ggiPost('<?= APP_URL ?>/modules/certificados/ajax_crear.php', fd, { silent: true })
      .then(function(data) {
        if (data.ok) {
          mantClose();
          if (typeof showToast === 'function') showToast(data.msg || 'Certificado registrado.', 'ok');
          setTimeout(function() {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', 'certificados');
            window.location.href = url.toString();
          }, 700);
        } else {
          errBox.textContent = data.msg || 'Error al guardar.';
          errBox.style.display = 'block';
          btn.disabled = false;
          btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><polyline points="20 6 9 20 4 14"/></svg>Guardar certificado';
        }
      })
      .catch(function() {
        errBox.textContent = 'Error de conexión. Intenta de nuevo.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><polyline points="20 6 9 20 4 14"/></svg>Guardar certificado';
      });
    });
  };

  // ── Cambio de pestañas (global, sin DOMContentLoaded) ──
  window.switchTab = function(id) {
    document.querySelectorAll('.tab-panel').forEach(function(p) {
      p.style.display = 'none';
      p.classList.remove('active');
    });
    document.querySelectorAll('#tabs-nav .tab').forEach(function(t) {
      t.classList.remove('active');
    });
    var panel = document.getElementById('tab-' + id);
    var btn   = document.querySelector('#tabs-nav [data-tab="' + id + '"]');
    if (panel) { panel.style.display = 'block'; panel.classList.add('active'); }
    if (btn)   btn.classList.add('active');

    // Lazy load: fetch content on first open
    if (panel && panel.dataset.lazy === '1' && panel.dataset.loaded !== '1') {
      panel.dataset.loaded = '1';
      fetch(window.APP_URL + '/modules/clientes/ajax_tab.php?id=<?= $id ?>&tab=' + id, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'   // siempre fresco: evita que el navegador sirva el tab cacheado viejo
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.html !== undefined) {
          panel.innerHTML = data.html;
          // Re-execute scripts injected via innerHTML (browsers skip them by default)
          panel.querySelectorAll('script').forEach(function(s) {
            var ns = document.createElement('script');
            ns.textContent = s.textContent;
            document.body.appendChild(ns);
            ns.remove();
          });
        }
      })
      .catch(function() {
        panel.dataset.loaded = '0'; // allow retry
        panel.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gris-texto);font-size:13px;">Error al cargar. <a href="" onclick="location.reload()" style="color:var(--azul)">Recargar página</a></div>';
      });
    }
  };

  // Marcar un tab lazy para re-fetch. Si está visible, refresca al instante;
  // si no, solo lo invalida para que recargue fresco la próxima vez que se abra
  // (evita cambiar de pestaña al usuario).
  window.reloadTab = function(id) {
    var p = document.getElementById('tab-' + id);
    if (!p) return;
    p.dataset.loaded = '0';
    if (p.classList.contains('active')) window.switchTab(id);
  };

  // Enlazar cada botón de pestaña
  document.querySelectorAll('#tabs-nav .tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
      window.switchTab(this.getAttribute('data-tab'));
    });
  });

  // Abrir la pestaña correcta si viene por URL (?tab=llamadas, etc.)
  var tabParam = new URLSearchParams(window.location.search).get('tab');
  if (tabParam) window.switchTab(tabParam);

ggiReady(function () {

  // ── Toast para alertas estáticas ──
  document.querySelectorAll('.alert-nuevo').forEach(function(el) {
    var msg = el.textContent.trim();
    if (msg && typeof showToast === 'function') {
      showToast(msg, 'ok');
      el.remove();
    }
  });
  document.querySelectorAll('[style*="fff5f5"]').forEach(function(el) {
    var msg = el.textContent.trim();
    if (msg && typeof showToast === 'function') {
      showToast(msg, 'error');
      el.remove();
    }
  });

});
</script>

<script>
// ── Descarga sin navegar (fetch → blob) ──
function descargarArchivos(clienteId, tipo, btn) {
  if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
  fetch(window.APP_URL + '/modules/clientes/descargar_docs.php?id=' + clienteId + '&tipo=' + tipo)
    .then(function(res) {
      if (!res.ok) throw new Error('No hay archivos disponibles para descargar.');
      var cd = res.headers.get('Content-Disposition') || '';
      var m  = cd.match(/filename\*?=(?:UTF-8''|"?)([^";\n]+)/i);
      var fname = m ? decodeURIComponent(m[1]) : (tipo + '_' + clienteId + '.zip');
      return res.blob().then(function(blob) { return { blob: blob, fname: fname }; });
    })
    .then(function(r) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(r.blob);
      a.download = r.fname;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function() { URL.revokeObjectURL(a.href); }, 1000);
    })
    .catch(function(err) {
      if (typeof showToast === 'function') showToast(err.message || 'Error al descargar.', 'error');
    })
    .finally(function() {
      if (btn) { btn.disabled = false; btn.style.opacity = ''; }
    });
}

// Evitar que el navegador abra archivos arrastrados fuera de las tarjetas
document.addEventListener('dragover', function(e) { e.preventDefault(); });
document.addEventListener('drop',     function(e) { e.preventDefault(); });

// ── Drag & drop en tarjetas de documentos y vouchers ──
// Ejecutar inmediatamente (sin DOMContentLoaded) para que funcione
// también en navegación AJAX donde ese evento ya no vuelve a disparar.
(function initDocDragDrop() {
  // ── Vouchers de pago ──
  document.querySelectorAll('[id^="voucher-drop-"]').forEach(function(zone) {
    if (zone.dataset.ddInit) return;
    zone.dataset.ddInit = '1';
    var pagoId = zone.id.replace('voucher-drop-', '');
    var depth  = 0;

    zone.addEventListener('dragover', function(e) { e.preventDefault(); e.stopPropagation(); });
    zone.addEventListener('dragenter', function(e) {
      e.preventDefault(); e.stopPropagation();
      depth++;
      zone.classList.add('drag-over');
    });
    zone.addEventListener('dragleave', function(e) {
      depth--;
      if (depth <= 0) { depth = 0; zone.classList.remove('drag-over'); }
    });
    zone.addEventListener('drop', function(e) {
      e.preventDefault(); e.stopPropagation();
      depth = 0; zone.classList.remove('drag-over');
      var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (!file) return;
      var inp = document.getElementById('inp-voucher-' + pagoId);
      var asignado = false;
      try { var dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; asignado = !!(inp.files && inp.files[0]); } catch(x) {}
      if (asignado) {
        inp.dispatchEvent(new Event('change'));
      } else {
        subirVoucherConArchivo(pagoId, file);
      }
    });
  });

  // ── Tarjetas de documentos ──
  document.querySelectorAll('[id^="doc-card-"]').forEach(function(card) {
    if (card.dataset.ddInit) return; // evitar doble binding
    card.dataset.ddInit = '1';

    var tipo = card.id.replace('doc-card-', '');
    var inp  = document.getElementById('file-inp-' + tipo);
    if (!inp) return;

    card.addEventListener('dragover', function(e) {
      e.preventDefault(); e.stopPropagation();
      card.classList.add('drag-over-card');
      var area = card.querySelector('.doc-upload-area');
      if (area) area.classList.add('drag-over');
    });
    card.addEventListener('dragleave', function(e) {
      if (card.contains(e.relatedTarget)) return;
      card.classList.remove('drag-over-card');
      var area = card.querySelector('.doc-upload-area');
      if (area) area.classList.remove('drag-over');
    });
    card.addEventListener('drop', function(e) {
      e.preventDefault(); e.stopPropagation();
      card.classList.remove('drag-over-card');
      var area = card.querySelector('.doc-upload-area');
      if (area) area.classList.remove('drag-over');
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files[0]) return;
      var file = files[0];
      // Intentar asignar al input y disparar onchange
      var asignado = false;
      try {
        var dt = new DataTransfer();
        dt.items.add(file);
        inp.files = dt.files;
        asignado = (inp.files && inp.files[0]);
      } catch(ex) {}
      if (asignado) {
        inp.dispatchEvent(new Event('change'));
      } else {
        // Fallback: llamar a subirDocInline directamente con el archivo
        subirDocInlineConArchivo(file, tipo, <?= $id ?>);
      }
    });
  });
})();

// Fallback para drag-and-drop cuando DataTransfer API no está disponible
function subirDocInlineConArchivo(file, tipo, clienteId) {
  // Leer versionActual desde el onchange del input
  var inp = document.getElementById('file-inp-' + tipo);
  var version = 0;
  if (inp && inp.getAttribute('onchange')) {
    var m = inp.getAttribute('onchange').match(/,(\d+)\s*\)/);
    if (m) version = parseInt(m[1], 10);
  }
  subirDocInlineFile(file, tipo, clienteId, version);
}

// ── Upload inline por tarjeta ──
async function subirDocInline(input, tipo, clienteId, versionActual) {
  if (!input.files || !input.files[0]) return;
  const file  = input.files[0];
  subirDocInlineFile(file, tipo, clienteId, versionActual);
}

async function subirDocInlineFile(file, tipo, clienteId, versionActual) {
  const card  = document.getElementById('doc-card-' + tipo);
  const pbar  = document.getElementById('doc-progress-' + tipo);
  const fill  = document.getElementById('doc-pbar-' + tipo);
  const fname = document.getElementById('doc-fname-' + tipo);

  // Validación básica en cliente
  const ext = file.name.split('.').pop().toLowerCase();
  if (!['pdf','jpg','jpeg','png','webp'].includes(ext)) {
    if (typeof showToast === 'function') showToast('Formato no permitido. Solo PDF, JPG, PNG o WEBP.', 'error');
    return;
  }
  if (file.size > 10 * 1024 * 1024) {
    if (typeof showToast === 'function') showToast('El archivo supera el límite de 10 MB.', 'error');
    return;
  }

  // Estado cargando
  card.classList.add('doc-loading');
  if (pbar) { pbar.style.display = 'block'; fill.style.width = '30%'; }
  if (fname) fname.textContent = 'Subiendo...';

  const fd = new FormData();
  fd.append('archivo',    file);
  fd.append('tipo',       tipo);
  fd.append('cliente_id', clienteId);

  try {
    if (fill) fill.style.width = '70%';
    const json = await ggiPost(window.APP_URL + '/modules/documentos/subir.php', fd, { silent: true });
    if (fill) fill.style.width = '100%';

    if (json.ok) {
      if (typeof showToast === 'function') showToast('Documento subido correctamente.', 'ok');
      // Invalidar caché AJAX para que el back-button muestre datos frescos
      if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
      // ── DOM update — no reload ──
      card.classList.remove('doc-loading');
      if (pbar) pbar.style.display = 'none';
      const nuevaVersion = json.version   || (versionActual + 1);
      const nuevoNombre  = json.nombre_archivo || file.name;
      const nuevoDocId   = json.doc_id    || 0;
      const appUrl       = window.APP_URL || '';

      if (versionActual === 0) {
        // Primera subida: transformar .doc-upload-area en .doc-filename + .doc-actions
        const uploadArea = card.querySelector('.doc-upload-area');
        if (uploadArea) {
          const fnDiv = document.createElement('div');
          fnDiv.className = 'doc-filename';
          fnDiv.id        = 'doc-fname-' + tipo;
          fnDiv.title     = nuevoNombre;
          fnDiv.textContent = nuevoNombre;
          const verSpan = document.createElement('span');
          verSpan.id    = 'doc-ver-' + tipo;
          verSpan.style.cssText = 'color:var(--gris-texto);font-size:10px;';
          verSpan.textContent   = ' v' + nuevaVersion;
          fnDiv.appendChild(verSpan);
          uploadArea.replaceWith(fnDiv);

          const actDiv = document.createElement('div');
          actDiv.className = 'doc-actions';
          const verBtn = nuevoDocId
            ? '<a href="' + appUrl + '/modules/documentos/ver.php?id=' + nuevoDocId + '" data-ggi-preview data-src="' + appUrl + '/modules/documentos/stream.php?id=' + nuevoDocId + '" data-download-url="' + appUrl + '/modules/documentos/ver.php?id=' + nuevoDocId + '&descargar=1" class="btn btn-outline btn-sm">Ver</a>'
            : '';
          actDiv.innerHTML = verBtn
            + '<button type="button" class="btn btn-outline btn-sm"'
            + ' onclick="document.getElementById(\'file-inp-' + tipo + '\').click()"'
            + ' title="Reemplazar archivo">'
            + '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">'
            + '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
            + '<polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>'
            + ' Actualizar</button>';
          fnDiv.after(actDiv);
        }
        // Icono de estado: vacío → ok
        const statusEl = document.getElementById('doc-status-' + tipo);
        if (statusEl) {
          statusEl.className = 'doc-status-ok';
          statusEl.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>';
        }
        card.classList.add('uploaded');
      } else {
        // Re-subida: actualizar nombre, versión y enlace "Ver"
        const fnEl = document.getElementById('doc-fname-' + tipo);
        if (fnEl) {
          fnEl.title = nuevoNombre;
          // Primer nodo de texto (antes del span de versión)
          if (fnEl.firstChild && fnEl.firstChild.nodeType === Node.TEXT_NODE) {
            fnEl.firstChild.textContent = nuevoNombre;
          } else {
            fnEl.insertBefore(document.createTextNode(nuevoNombre), fnEl.firstChild);
          }
        }
        const verEl = document.getElementById('doc-ver-' + tipo);
        if (verEl) verEl.textContent = ' v' + nuevaVersion;
        if (nuevoDocId) {
          const verLink = card.querySelector('.doc-actions a');
          if (verLink) { verLink.href = appUrl + '/modules/documentos/ver.php?id=' + nuevoDocId; verLink.setAttribute('data-ggi-preview',''); verLink.setAttribute('data-src', appUrl + '/modules/documentos/stream.php?id=' + nuevoDocId); verLink.setAttribute('data-download-url', appUrl + '/modules/documentos/ver.php?id=' + nuevoDocId + '&descargar=1'); }
        }
      }
      // Actualizar input para siguiente subida (nueva versión base)
      var inpEl = document.getElementById('file-inp-' + tipo);
      if (inpEl) inpEl.onchange = function() { subirDocInline(this, tipo, clienteId, nuevaVersion); };
    } else {
      card.classList.remove('doc-loading');
      if (pbar) pbar.style.display = 'none';
      if (fname) fname.textContent = versionActual > 0 ? 'Error — intenta de nuevo' : 'PDF, JPG, PNG · máx. 10 MB';
      if (typeof showToast === 'function') showToast(json.msg || 'Error al subir el documento.', 'error');
    }
  } catch(e) {
    card.classList.remove('doc-loading');
    if (pbar) pbar.style.display = 'none';
    if (fname) fname.textContent = versionActual > 0 ? 'Error — intenta de nuevo' : 'PDF, JPG, PNG · máx. 10 MB';
    if (typeof showToast === 'function') showToast('Error de conexión al subir.', 'error');
  }
  input.value = '';
}

// ── Pagos: helpers de estado ──
var _pagoBadgeEstados = {
  pendiente_voucher:    {label:'Pendiente voucher',    bg:'var(--s-warn-bg)',color:'var(--s-warn-tx)',dot:'#f59e0b'},
  pendiente_validacion: {label:'Pendiente validación', bg:'#f1f5f9',color:'#475569',dot:'#94a3b8'},
  validado:             {label:'Validado',             bg:'var(--s-ok-bg)',color:'var(--s-ok-tx)',dot:'#22c55e'},
  rechazado:            {label:'Rechazado',            bg:'var(--s-err-bg)',color:'#c53030',dot:'#ef4444'},
};

function actualizarPagoBadge(pagoId, nuevoEstado, motivo) {
  var badge = document.getElementById('pago-badge-' + pagoId);
  var ec    = _pagoBadgeEstados[nuevoEstado] || {label:nuevoEstado, bg:'#f8f9fd', color:'#64748b', dot:'#94a3b8'};
  if (badge) {
    badge.style.background = ec.bg;
    badge.style.color      = ec.color;
    var dot = badge.querySelector('.pago-dot');
    if (dot) dot.style.background = ec.dot;
    var lbl = badge.querySelector('.pago-badge-label');
    if (lbl) lbl.textContent = ec.label;
  }
  // Ocultar botones de acción gerente
  var acciones = document.getElementById('pago-acciones-' + pagoId);
  if (acciones) acciones.style.display = 'none';

  // Actualizar/crear barra de alerta al fondo del row
  var row = document.getElementById('pago-row-' + pagoId);
  var alertDiv = document.getElementById('pago-alert-' + pagoId);
  if (nuevoEstado === 'rechazado' && motivo && row) {
    if (!alertDiv) {
      alertDiv = document.createElement('div');
      alertDiv.id = 'pago-alert-' + pagoId;
      row.appendChild(alertDiv);
    }
    alertDiv.style.cssText = 'padding:10px 18px;background:var(--s-err-bg);border-top:1px solid var(--s-err-brd);display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--s-err);';
    alertDiv.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span><strong>Pago rechazado.</strong> Motivo: ' + motivo.replace(/</g,'&lt;') + '</span>';
    // Re-mostrar botón de subida para que puedan re-subir
    var lbl = document.getElementById('lbl-voucher-' + pagoId);
    if (lbl) lbl.style.display = '';
  } else if (nuevoEstado === 'validado' && row) {
    if (alertDiv) alertDiv.remove();
  }
}

// ── Pagos: Subida directa de voucher (sin popup) ──
window.subirVoucherDirecto = async function(pagoId, input) {
  if (!input.files || !input.files[0]) return;
  await subirVoucherConArchivo(pagoId, input.files[0]);
  input.value = '';
};

// Fallback drag-and-drop: sube el archivo directamente sin pasar por input.files
async function subirVoucherConArchivo(pagoId, file) {
  // Fotos de cámara móvil (4-8 MB) → comprimir antes de subir (best-effort)
  if (typeof ggiComprimirImagen === 'function') {
    try { file = await ggiComprimirImagen(file); } catch(e) {}
  }
  var lbl      = document.getElementById('lbl-voucher-' + pagoId);
  var dropZone = document.getElementById('voucher-drop-' + pagoId);
  var span     = lbl ? lbl.querySelector('span') : null;
  var origText = span ? span.textContent : 'Subir voucher';
  if (lbl)  { lbl.style.opacity = '0.6'; lbl.style.pointerEvents = 'none'; }
  if (span) span.textContent = 'Subiendo…';

  var fd = new FormData();
  fd.append('accion',  'subir_voucher');
  fd.append('pago_id', pagoId);
  fd.append('voucher', file);

  try {
    var data = await ggiPost(window.APP_URL + '/modules/pagos/ajax_pago.php', fd, { silent: true });
    if (data.ok) {
      if (typeof showToast === 'function') showToast(data.msg || 'Voucher subido.', 'ok');
      try { actualizarPagoBadge(pagoId, 'pendiente_validacion', null); } catch(e) {}
      // Ocultar zona de drop
      if (dropZone) dropZone.style.display = 'none';
      // Mostrar/crear botón "Ver voucher"
      var verVoucher = document.getElementById('pago-ver-voucher-' + pagoId);
      if (verVoucher) {
        verVoucher.style.display = '';
      } else {
        var anchor = document.createElement('a');
        anchor.id        = 'pago-ver-voucher-' + pagoId;
        anchor.href      = (window.APP_URL || '') + '/modules/pagos/ver_voucher.php?id=' + pagoId;
        anchor.target    = '_blank';
        anchor.setAttribute('data-ggi-preview', ''); anchor.setAttribute('data-title', 'Voucher de pago');
        anchor.className = 'btn btn-outline btn-sm';
        anchor.style.whiteSpace = 'nowrap';
        anchor.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Ver voucher';
        if (dropZone && dropZone.parentElement) {
          dropZone.parentElement.appendChild(anchor);
        }
      }
    } else {
      if (typeof showToast === 'function') showToast(data.msg || 'Error al subir.', 'error');
      if (lbl)  { lbl.style.opacity = '1'; lbl.style.pointerEvents = ''; }
      if (span) span.textContent = origText;
    }
  } catch(err) {
    if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
    if (lbl)  { lbl.style.opacity = '1'; lbl.style.pointerEvents = ''; }
    if (span) span.textContent = origText;
  }
}

// ── Pagos: helpers de modal ──
function _abrirModalValPago(pagoId, tipoLabel) {
  var m = document.getElementById('modal-val-pago');
  if (!m) return;
  var msg = document.getElementById('modal-val-pago-msg');
  if (msg) msg.textContent = '¿Confirmas que el ' + tipoLabel + ' ha sido verificado y es correcto?';
  m.classList.add('open');
  document.getElementById('btn-val-cancelar').onclick = function() { m.classList.remove('open'); };
  document.getElementById('btn-val-confirmar').onclick = async function() {
    m.classList.remove('open');
    var fd = new FormData();
    fd.append('accion', 'validar'); fd.append('pago_id', pagoId);
    try {
      var data = await ggiPost(window.APP_URL + '/modules/pagos/ajax_pago.php', fd, { silent: true });
      if (data.ok) {
        if (typeof showToast === 'function') showToast(data.msg || 'Pago validado.', 'ok');
        try { actualizarPagoBadge(pagoId, 'validado', null); } catch(e) {}
        if (typeof window._pollBadges === 'function') window._pollBadges();
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'Error al validar.', 'error');
      }
    } catch(e) {
      if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
    }
  };
}

function _abrirModalRecPago(pagoId, tipoLabel) {
  var m = document.getElementById('modal-rec-pago');
  if (!m) return;
  var msg = document.getElementById('modal-rec-pago-msg');
  if (msg) msg.textContent = 'Indica el motivo del rechazo del ' + tipoLabel + '. El ejecutivo podrá ver esta nota.';
  var inp = document.getElementById('modal-rec-motivo');
  var err = document.getElementById('modal-rec-error');
  if (inp) inp.value = '';
  if (err) err.style.display = 'none';
  m.classList.add('open');
  document.getElementById('btn-rec-cancelar').onclick = function() { m.classList.remove('open'); };
  document.getElementById('btn-rec-confirmar').onclick = async function() {
    var nota = inp ? inp.value.trim() : '';
    if (!nota) { if (err) err.style.display = 'block'; return; }
    m.classList.remove('open');
    var fd = new FormData();
    fd.append('accion', 'rechazar'); fd.append('pago_id', pagoId); fd.append('notas', nota);
    try {
      var data = await ggiPost(window.APP_URL + '/modules/pagos/ajax_pago.php', fd, { silent: true });
      if (data.ok) {
        if (typeof showToast === 'function') showToast(data.msg || 'Pago rechazado.', 'ok');
        try { actualizarPagoBadge(pagoId, 'rechazado', nota); } catch(e) {}
        if (typeof window._pollBadges === 'function') window._pollBadges();
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'Error al rechazar.', 'error');
      }
    } catch(e) {
      if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
    }
  };
}

// Cerrar modales al clic en el fondo
document.addEventListener('click', function(e) {
  var mv = document.getElementById('modal-val-pago');
  var mr = document.getElementById('modal-rec-pago');
  if (mv && e.target === mv) mv.classList.remove('open');
  if (mr && e.target === mr) mr.classList.remove('open');
});


// ── Pagos: Validar ──
window.validarPago = function(pagoId, tipoLabel) {
  _abrirModalValPago(pagoId, tipoLabel);
};

// ── Pagos: Rechazar ──
window.rechazarPago = function(pagoId, tipoLabel) {
  _abrirModalRecPago(pagoId, tipoLabel);
};

// ── Attendance Sheet ──
window.generarAttendance = function(clienteId) {
  var btn  = document.getElementById('att-gen-btn');
  var msg  = document.getElementById('att-msg');
  var meta = document.getElementById('att-meta');

  btn.disabled = true;
  btn.textContent = 'Generando…';
  msg.style.display = 'none';

  var fd = new FormData();
  fd.append('cliente_id', clienteId);
  ggiPost(window.APP_URL + '/modules/clientes/generar_attendance.php', fd, { silent: true })
  .then(function(data) {
    msg.style.display = 'block';
    if (data.ok) {
      msg.style.background = 'var(--s-ok-bg)';
      msg.style.border     = '1px solid var(--s-ok-brd)';
      msg.style.color      = 'var(--s-ok-tx)';
      msg.innerHTML = '&#10003; ' + data.msg;
      if (meta) meta.innerHTML = 'v' + data.version + ' &mdash; ' + data.nombre;
      btn.textContent = 'Regenerar';
      // Agregar botones Ver / Descargar si no existen
      var card = document.getElementById('att-card');
      if (card && data.doc_id) {
        var base = window.APP_URL + '/modules/documentos/ver.php?id=' + data.doc_id;
        var stream = window.APP_URL + '/modules/documentos/stream.php?id=' + data.doc_id;
        var btnArea = btn.parentNode;
        if (!document.getElementById('att-ver-btn')) {
          var verBtn = document.createElement('a');
          verBtn.href = base; verBtn.id = 'att-ver-btn';
          verBtn.className = 'btn btn-outline btn-sm';
          verBtn.setAttribute('data-ggi-preview', ''); verBtn.setAttribute('data-src', stream); verBtn.setAttribute('data-download-url', base + '&descargar=1'); verBtn.setAttribute('data-title', 'Documento adjunto');
          verBtn.innerHTML = 'Ver';
          btnArea.insertBefore(verBtn, btn);
        } else {
          var _avb = document.getElementById('att-ver-btn');
          _avb.href = base; _avb.setAttribute('data-src', stream);
        }
        if (!document.getElementById('att-dl-btn')) {
          var dlBtn = document.createElement('a');
          dlBtn.href = base + '&descargar=1'; dlBtn.id = 'att-dl-btn';
          dlBtn.className = 'btn btn-outline btn-sm';
          dlBtn.setAttribute('download', '');
          dlBtn.innerHTML = 'Descargar';
          btnArea.insertBefore(dlBtn, btn);
        } else {
          document.getElementById('att-dl-btn').href = base + '&descargar=1';
        }
      }
    } else {
      msg.style.background = 'var(--s-err-bg)';
      msg.style.border     = '1px solid var(--s-err-brd)';
      msg.style.color      = 'var(--s-err-tx)';
      msg.textContent = data.msg || 'Error al generar el documento.';
      btn.textContent = document.getElementById('att-ver-btn') ? 'Regenerar' : 'Generar';
    }
    btn.disabled = false;
  })
  .catch(function() {
    msg.style.display = 'block';
    msg.style.background = 'var(--s-err-bg)';
    msg.style.border     = '1px solid var(--s-err-brd)';
    msg.style.color      = 'var(--s-err-tx)';
    msg.textContent = 'Error de conexión. Intenta nuevamente.';
    btn.disabled = false;
    btn.textContent = document.getElementById('att-ver-btn') ? 'Regenerar' : 'Generar';
  });
};

// ── Application Form ──────────────────────────────────────────────────────────
window.abrirModalApplication = function(prevPhone, prevEmail, prevAlcEs, prevAlcEn, prevIsos) {
  if (prevPhone  !== undefined && prevPhone  !== '') document.getElementById('app-phone').value = prevPhone;
  if (prevEmail  !== undefined && prevEmail  !== '') document.getElementById('app-email').value = prevEmail;
  if (prevAlcEs  !== undefined) document.getElementById('app-alcance-es').value = prevAlcEs;
  if (prevAlcEn  !== undefined) document.getElementById('app-alcance-en').value = prevAlcEn;
  if (Array.isArray(prevIsos) && prevIsos.length > 0) {
    document.querySelectorAll('#modal-application input[name="app_iso[]"]').forEach(function(cb) {
      cb.checked = prevIsos.indexOf(parseInt(cb.value, 10)) >= 0;
      cb.closest('label').style.background   = cb.checked ? 'var(--s-info-bg)' : 'var(--bg-surface2)';
      cb.closest('label').style.borderColor  = cb.checked ? '#6366F1' : 'var(--gris-borde)';
    });
  }
  document.getElementById('modal-application').classList.add('open');
  document.getElementById('app-phone').focus();
};

window.generarApplication = function() {
  var clienteId = document.getElementById('app-cliente-id').value;
  var phone     = document.getElementById('app-phone').value.trim();
  var email     = document.getElementById('app-email').value.trim();
  var alc_es    = document.getElementById('app-alcance-es').value.trim();
  var alc_en    = document.getElementById('app-alcance-en').value.trim();
  var btn       = document.getElementById('app-modal-btn');
  var errEl     = document.getElementById('app-modal-err');

  if (!phone)  { errEl.textContent = 'El teléfono/fax es requerido.'; errEl.style.display='block'; return; }
  if (!email)  { errEl.textContent = 'El email es requerido.'; errEl.style.display='block'; return; }
  if (!alc_es) { errEl.textContent = 'El alcance en español es requerido.'; errEl.style.display='block'; return; }
  if (!alc_en) { errEl.textContent = 'El alcance en inglés es requerido.'; errEl.style.display='block'; return; }
  errEl.style.display = 'none';

  btn.disabled = true;
  btn.textContent = 'Generando...';

  var fd = new FormData();
  fd.append('cliente_id',      clienteId);
  fd.append('phone',           phone);
  fd.append('email',           email);
  fd.append('alcance_espanol', alc_es);
  fd.append('alcance_ingles',  alc_en);
  // ISOs seleccionados
  document.querySelectorAll('#modal-application input[name="app_iso[]"]:checked')
    .forEach(function(cb) { fd.append('isos[]', cb.value); });
  ggiPost(window.APP_URL + '/modules/clientes/generar_application.php', fd, { silent: true })
  .then(function(data) {
    document.getElementById('modal-application').classList.remove('open');
    var msg  = document.getElementById('app-msg');
    var meta = document.getElementById('app-meta');
    var genBtn = document.getElementById('app-gen-btn');
    msg.style.display = 'block';
    if (data.ok) {
      msg.style.background = 'var(--s-ok-bg)';
      msg.style.border     = '1px solid var(--s-ok-brd)';
      msg.style.color      = 'var(--s-ok-tx)';
      msg.innerHTML = '&#10003; ' + data.msg;
      if (meta) meta.innerHTML = 'v' + data.version + ' &mdash; ' + data.nombre;
      if (genBtn) genBtn.textContent = 'Regenerar';
      var card = document.getElementById('app-card');
      if (card && data.doc_id) {
        var base    = window.APP_URL + '/modules/documentos/ver.php?id=' + data.doc_id;
        var stream  = window.APP_URL + '/modules/documentos/stream.php?id=' + data.doc_id;
        var btnArea = genBtn.parentNode;
        if (!document.getElementById('app-ver-btn')) {
          var verBtn = document.createElement('a');
          verBtn.href = base; verBtn.id = 'app-ver-btn';
          verBtn.className = 'btn btn-outline btn-sm';
          verBtn.setAttribute('data-ggi-preview', ''); verBtn.setAttribute('data-src', stream); verBtn.setAttribute('data-download-url', base + '&descargar=1'); verBtn.setAttribute('data-title', 'Documento de aplicación');
          verBtn.innerHTML = 'Ver';
          btnArea.insertBefore(verBtn, genBtn);
        } else {
          var _apb = document.getElementById('app-ver-btn');
          _apb.href = base; _apb.setAttribute('data-src', stream);
        }
        if (!document.getElementById('app-dl-btn')) {
          var dlBtn = document.createElement('a');
          dlBtn.href = base + '&descargar=1'; dlBtn.id = 'app-dl-btn';
          dlBtn.className = 'btn btn-outline btn-sm';
          dlBtn.setAttribute('download', '');
          dlBtn.innerHTML = 'Descargar';
          btnArea.insertBefore(dlBtn, genBtn);
        } else {
          document.getElementById('app-dl-btn').href = base + '&descargar=1';
        }
      }
    } else {
      msg.style.background = 'var(--s-err-bg)';
      msg.style.border     = '1px solid var(--s-err-brd)';
      msg.style.color      = 'var(--s-err-tx)';
      msg.textContent = data.msg || 'Error al generar el documento.';
    }
    btn.disabled = false;
    btn.textContent = 'Generar';
  })
  .catch(function() {
    document.getElementById('modal-application').classList.remove('open');
    var msg = document.getElementById('app-msg');
    msg.style.display = 'block';
    msg.style.background = 'var(--s-err-bg)';
    msg.style.border     = '1px solid var(--s-err-brd)';
    msg.style.color      = 'var(--s-err-tx)';
    msg.textContent = 'Error de conexión. Intenta nuevamente.';
    btn.disabled = false;
    btn.textContent = 'Generar';
  });
};
</script>

<!-- Modal: datos para Application Form -->
<div id="modal-application" class="ggi-modal-overlay" style="z-index:9999;padding:20px;">
  <div style="background:var(--bg-surface);border-radius:14px;padding:28px;max-width:560px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);margin:auto;">
    <h3 style="font-size:16px;font-weight:700;color:var(--gris-dark);margin-bottom:4px;">Application Form</h3>
    <p style="font-size:12px;color:var(--gris-texto);margin-bottom:20px;">Completa los datos para generar el formulario de certificación.</p>
    <input type="hidden" id="app-cliente-id" value="<?= $id ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
      <div>
        <label style="font-size:12px;font-weight:600;color:var(--azul);display:block;margin-bottom:5px;">Teléfono / Fax <span class="req">*</span></label>
        <input type="text" id="app-phone"
          style="width:100%;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;outline:none;"
          value="<?= sanitize($c['contacto_telefono'] ?? '') ?>"
          placeholder="Ej: +51 999 999 999">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:var(--azul);display:block;margin-bottom:5px;">Email <span class="req">*</span></label>
        <input type="email" id="app-email"
          style="width:100%;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;outline:none;"
          value="<?= sanitize($c['contacto_email'] ?? '') ?>"
          placeholder="correo@empresa.com">
      </div>
    </div>

    <div style="margin-bottom:14px;">
      <label style="font-size:12px;font-weight:600;color:var(--azul);display:block;margin-bottom:8px;">Normas a certificar</label>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($all_normas_app as $norma):
            $checked = in_array($norma['id'], $normas_aceptadas_ids) ? 'checked' : '';
        ?>
        <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--tx);background:<?= $checked ? 'var(--ac-soft)' : 'var(--bg-surface2)' ?>;border:1.5px solid <?= $checked ? 'var(--ac)' : 'var(--gris-borde)' ?>;border-radius:7px;padding:5px 10px;cursor:pointer;user-select:none;">
          <input type="checkbox" name="app_iso[]" value="<?= (int)$norma['id'] ?>" <?= $checked ?>
                 style="accent-color:var(--ac);" onchange="var l=this.closest('label');l.style.background=this.checked?'var(--ac-soft)':'var(--bg-surface2)';l.style.borderColor=this.checked?'var(--ac)':'var(--gris-borde)'">
          <?= sanitize($norma['codigo']) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="margin-bottom:14px;">
      <label style="font-size:12px;font-weight:600;color:var(--azul);display:block;margin-bottom:5px;">Alcance en español <span class="req">*</span></label>
      <textarea id="app-alcance-es" rows="3"
        style="width:100%;padding:10px 13px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;resize:vertical;outline:none;"
        placeholder="Ej: Diseño, desarrollo y fabricación de..."></textarea>
    </div>
    <div style="margin-bottom:16px;">
      <label style="font-size:12px;font-weight:600;color:var(--azul);display:block;margin-bottom:5px;">Alcance en inglés <span class="req">*</span></label>
      <textarea id="app-alcance-en" rows="3"
        style="width:100%;padding:10px 13px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;resize:vertical;outline:none;"
        placeholder="Ej: Design, development and manufacturing of..."></textarea>
    </div>
    <div id="app-modal-err" style="display:none;font-size:12px;color:var(--s-err-tx);margin-bottom:10px;"></div>
    <div style="display:flex;gap:10px;">
      <button id="app-modal-btn" type="button" onclick="generarApplication()"
              class="btn btn-primary" style="flex:1;background:#F97316;border-color:#F97316;">
        Generar
      </button>
      <button type="button" onclick="document.getElementById('modal-application').classList.remove('open')"
              class="btn btn-outline" style="flex:1;">Cancelar</button>
    </div>
  </div>
</div>
<script>
document.getElementById('modal-application').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>

<?php if ($puede_gestionar): ?>
<!-- Modal: Acta de Aprobación de Alcance -->
<div id="modal-acta" class="ggi-modal-overlay" style="z-index:9999;padding:20px;">
  <div style="background:var(--bg-surface);border-radius:14px;padding:28px;max-width:580px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);margin:auto;">
    <h3 style="font-size:16px;font-weight:700;color:var(--gris-dark);margin-bottom:2px;">Acta de Aprobación de Alcance</h3>
    <p style="font-size:12px;color:var(--gris-texto);margin-bottom:18px;" id="acta-modal-sub">Propuesta: —</p>
    <input type="hidden" id="acta-cliente-id">

    <div style="margin-bottom:14px;">
      <label style="font-size:12px;font-weight:600;color:var(--azul);display:block;margin-bottom:8px;">Normas ISO a incluir <span class="req">*</span></label>
      <div id="acta-iso-checks" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
    </div>

    <div style="margin-bottom:16px;">
      <label style="font-size:12px;font-weight:600;color:var(--azul);display:block;margin-bottom:5px;">Alcance del sistema de gestión <span class="req">*</span></label>
      <textarea id="acta-alcance" rows="6"
        style="width:100%;padding:10px 13px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;resize:vertical;outline:none;line-height:1.6;"
        placeholder="Describe el alcance del sistema de gestión integrado..."></textarea>
    </div>
    <div id="acta-cambios-solicitados" style="margin-bottom:16px;"></div>
    <div id="acta-modal-err" style="display:none;font-size:12px;color:var(--s-err-tx);margin-bottom:10px;padding:8px 12px;background:var(--s-err-bg);border-radius:8px;border-left:3px solid var(--s-err);"></div>
    <div style="display:flex;gap:10px;">
      <button id="acta-modal-btn" type="button" onclick="generarActa()"
              class="btn btn-primary" style="flex:1;background:#16a34a;border-color:#16a34a;">Generar</button>
      <button type="button" onclick="document.getElementById('modal-acta').classList.remove('open')"
              class="btn btn-outline" style="flex:1;">Cancelar</button>
    </div>
  </div>
</div>
<script>
document.getElementById('modal-acta').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});

window.abrirModalActa = function(clienteId, docId, alcancePrevio, isoIds, isoCodigos) {
  document.getElementById('acta-cliente-id').value = clienteId;
  document.getElementById('acta-modal-err').style.display = 'none';

  var cambiosEl = document.getElementById('acta-cambios-solicitados');
  if (cambiosEl) {
    cambiosEl.innerHTML = '<p style="font-size:12px;color:#6b7280;">Cargando cambios...</p>';
  }

  // Cargar cambios solicitados
  if (docId) {
    fetch(window.APP_URL + '/modules/clientes/obtener_cambios_acta.php?doc_id=' + docId)
      .then(r => r.json())
      .then(data => {
        if (cambiosEl && data.cambios) {
          cambiosEl.innerHTML = '<p style="font-size:12px;color:var(--tx2);margin:0 0 8px;"><strong>Cambios solicitados:</strong></p>' +
            '<div style="background:var(--s-ok-bg);border:1px solid var(--s-ok-brd);border-radius:8px;padding:10px;font-size:12px;color:var(--s-ok-tx);line-height:1.6;max-height:120px;overflow-y:auto;">' +
            data.cambios.split('\n').join('<br>') +
            '</div>';
        } else if (cambiosEl) {
          cambiosEl.innerHTML = '';
        }
      });
  }

  document.getElementById('acta-modal-sub').textContent = 'ISOs de la última propuesta aceptada';

  // Poblar checkboxes de ISOs
  var checks = document.getElementById('acta-iso-checks');
  checks.innerHTML = '';
  isoIds.forEach(function(isoId, idx) {
    var label = document.createElement('label');
    // Tokens → se adapta a modo oscuro (antes var(--s-ok-bg)/#86efac fijos = ilegible en oscuro)
    label.style.cssText = 'display:flex;align-items:center;gap:5px;font-size:12px;background:var(--gr-soft);border:1.5px solid var(--gr);color:var(--tx);border-radius:7px;padding:5px 10px;cursor:pointer;user-select:none;';
    var cb = document.createElement('input');
    cb.type = 'checkbox'; cb.name = 'acta_iso[]'; cb.value = isoId; cb.checked = true;
    cb.style.accentColor = 'var(--gr)';
    cb.addEventListener('change', function() {
      label.style.background = this.checked ? 'var(--gr-soft)' : 'var(--bg-surface2)';
      label.style.borderColor = this.checked ? 'var(--gr)' : 'var(--gris-borde)';
      label.style.color = this.checked ? 'var(--tx)' : 'var(--tx2)';
    });
    var span = document.createElement('span');
    span.textContent = isoCodigos[idx];
    label.appendChild(cb);
    label.appendChild(span);
    checks.appendChild(label);
  });

  // Pre-poblar alcance
  document.getElementById('acta-alcance').value = alcancePrevio || '';
  document.getElementById('acta-modal-btn').textContent = alcancePrevio ? 'Regenerar' : 'Generar';

  document.getElementById('modal-acta').classList.add('open');
  document.getElementById('acta-alcance').focus();
};

window.generarActa = function() {
  var errEl   = document.getElementById('acta-modal-err');
  var btn     = document.getElementById('acta-modal-btn');
  var alcance   = document.getElementById('acta-alcance').value.trim();
  var clienteId = document.getElementById('acta-cliente-id').value;

  errEl.style.display = 'none';
  if (!alcance) { errEl.textContent = 'El alcance es requerido.'; errEl.style.display='block'; return; }

  var isosSeleccionados = Array.from(
    document.querySelectorAll('#acta-iso-checks input[name="acta_iso[]"]:checked')
  ).map(function(cb){ return cb.value; });

  if (!isosSeleccionados.length) {
    errEl.textContent = 'Selecciona al menos una norma ISO.'; errEl.style.display='block'; return;
  }

  btn.disabled = true;
  btn.textContent = 'Generando...';

  var fd = new FormData();
  fd.append('cliente_id', clienteId);
  fd.append('alcance', alcance);
  isosSeleccionados.forEach(function(id){ fd.append('isos[]', id); });
  ggiPost(window.APP_URL + '/modules/clientes/generar_acta_alcance.php', fd, { silent: true })
  .then(function(data) {
    btn.disabled = false;
    btn.textContent = 'Regenerar';
    if (data.ok) {
      document.getElementById('modal-acta').classList.remove('open');
      if (typeof showToast === 'function') showToast(data.msg || 'Acta regenerada correctamente.', 'ok');
      setTimeout(function(){ location.reload(); }, 1500);
    } else {
      errEl.textContent = data.msg || 'Error al generar.';
      errEl.style.display = 'block';
    }
  })
  .catch(function(){
    btn.disabled = false; btn.textContent = 'Regenerar';
    errEl.textContent = 'Error de conexión.'; errEl.style.display='block';
  });
};
</script>
<?php endif; ?>

<script>
window.confirmarActa = function(docId, cotCodigo, msgId, cardId, alcanceData) {
  // Primero mostrar modal de confirmación: ¿Deseas agregar cambios?
  window.abrirConfirmacionCambios(docId, msgId);
};

// Modal reutilizable de confirmación
window.abrirConfirmacionCambios = function(docId, msgId) {
  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;';

  var modal = document.createElement('div');
  modal.style.cssText = 'background:var(--bg-surface);border-radius:14px;padding:28px;max-width:440px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.2);margin:auto;';

  var iconAndContent = document.createElement('div');
  iconAndContent.style.cssText = 'display:flex;gap:16px;align-items:flex-start;margin-bottom:24px;';

  var icon = document.createElement('div');
  icon.style.cssText = 'flex-shrink:0;width:40px;height:40px;border-radius:50%;background:var(--s-warn-bg);display:flex;align-items:center;justify-content:center;';
  icon.innerHTML = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

  var content = document.createElement('div');
  content.style.cssText = 'flex:1;';
  var title = document.createElement('h3');
  title.style.cssText = 'font-size:16px;font-weight:700;color:#1f2937;margin:0 0 8px;';
  title.textContent = '¿Agregar cambios al acta?';
  var desc = document.createElement('p');
  desc.style.cssText = 'font-size:13px;color:#6b7280;margin:0;line-height:1.5;';
  desc.textContent = 'Puedes agregar cambios solicitados por el cliente o aprobar el acta tal como está.';
  content.appendChild(title);
  content.appendChild(desc);

  iconAndContent.appendChild(icon);
  iconAndContent.appendChild(content);

  var footer = document.createElement('div');
  footer.style.cssText = 'display:flex;gap:10px;justify-content:flex-end;';
  var btnNo = document.createElement('button');
  btnNo.type = 'button';
  btnNo.className = 'ggi-btn-confirm-cancel';
  btnNo.style.flex = '1';
  btnNo.textContent = 'No';
  var btnYes = document.createElement('button');
  btnYes.type = 'button';
  btnYes.className = 'ggi-btn-confirm-action';
  btnYes.style.flex = '1';
  btnYes.textContent = 'Sí';
  footer.appendChild(btnNo);
  footer.appendChild(btnYes);

  modal.appendChild(iconAndContent);
  modal.appendChild(footer);
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  var close = function() {
    overlay.remove();
  };

  // Click en overlay para cerrar
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) close();
  });

  // Botón "No" - Aprobar directamente
  btnNo.addEventListener('click', function() {
    close();
    window.aprobarActaDirecto(docId, msgId);
  });

  // Botón "Sí" - Agregar cambios
  btnYes.addEventListener('click', function() {
    close();
    window.solicitarCambiosActa(docId, msgId);
  });
};

// Función para aprobar sin abrir modal de cambios
window.aprobarActaDirecto = function(docId, msgId) {
  var fd = new FormData();
  fd.append('doc_id', docId);
  ggiPost(window.APP_URL + '/modules/clientes/confirmar_acta_alcance.php', fd, { silent: true })
  .then(function(data) {
    if (data.ok) {
      if (typeof showToast === 'function') showToast(data.msg || 'Aprobado correctamente.', 'ok');
      setTimeout(function() { location.reload(); }, 1500);
    } else {
      if (typeof showToast === 'function') showToast(data.msg || 'Error al aprobar.', 'error');
    }
  })
  .catch(function(err) {
    if (typeof showToast === 'function') showToast('Error de conexión: ' + (err.message || 'desconocido'), 'error');
  });
};

window.confirmarActaOld = function(docId, cotCodigo, msgId, cardId, alcanceData) {
  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;';

  var modal = document.createElement('div');
  modal.style.cssText = 'background:var(--bg-surface);border-radius:14px;padding:28px;max-width:580px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.2);margin:auto;';

  var header = document.createElement('div');
  header.innerHTML = '<h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0 0 8px;">Acta de Aprobación de Alcance</h3><p style="font-size:12px;color:#6b7280;margin:0 0 18px;">Revisa el alcance. Si requieres cambios, haz clic en "Agregar cambios".</p>';

  var alcanceLabel = document.createElement('label');
  alcanceLabel.style.cssText = 'font-size:12px;font-weight:600;color:var(--s-info-tx);display:block;margin-bottom:8px;';
  alcanceLabel.textContent = 'Alcance del sistema de gestión (Bloqueado)';

  var alcanceTextarea = document.createElement('textarea');
  alcanceTextarea.id = 'acta-conf-alcance';
  alcanceTextarea.rows = 6;
  alcanceTextarea.disabled = true;
  alcanceTextarea.style.cssText = 'width:100%;padding:10px 13px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:13px;font-family:\'Inter\',system-ui,sans-serif;resize:vertical;outline:none;line-height:1.6;box-sizing:border-box;margin-bottom:16px;background:var(--bg-surface2);color:#6b7280;cursor:not-allowed;';

  // Populate alcance
  if (alcanceData) {
    if (typeof alcanceData === 'string') {
      alcanceTextarea.value = alcanceData;
    } else if (Array.isArray(alcanceData)) {
      alcanceTextarea.value = alcanceData.join('\n');
    }
  }

  var agregarCambiosBtn = document.createElement('button');
  agregarCambiosBtn.type = 'button';
  agregarCambiosBtn.style.cssText = 'width:100%;padding:10px 14px;margin-bottom:16px;border:1.5px solid var(--s-info-tx);background:transparent;color:var(--s-info-tx);border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s;';
  agregarCambiosBtn.textContent = '+ Agregar cambios solicitados';
  agregarCambiosBtn.addEventListener('mouseover', function() { this.style.background = 'var(--s-info-bg)'; });
  agregarCambiosBtn.addEventListener('mouseout', function() { this.style.background = 'transparent'; });

  var footer = document.createElement('div');
  footer.style.cssText = 'display:flex;gap:10px;';
  var btnCancelOld = document.createElement('button');
  btnCancelOld.type = 'button';
  btnCancelOld.className = 'ggi-btn-confirm-cancel acta-btn-cancel';
  btnCancelOld.style.flex = '1';
  btnCancelOld.textContent = 'Cancelar';

  var btnConfirmOld = document.createElement('button');
  btnConfirmOld.type = 'button';
  btnConfirmOld.className = 'acta-btn-confirm';
  btnConfirmOld.style.flex = '1';
  btnConfirmOld.style.padding = '8px 18px';
  btnConfirmOld.style.borderRadius = '8px';
  btnConfirmOld.style.border = 'none';
  btnConfirmOld.style.background = '#16a34a';
  btnConfirmOld.style.color = '#fff';
  btnConfirmOld.style.cursor = 'pointer';
  btnConfirmOld.style.fontWeight = '500';
  btnConfirmOld.style.fontSize = '13px';
  btnConfirmOld.textContent = 'Confirmar Aprobación';

  footer.appendChild(btnCancelOld);
  footer.appendChild(btnConfirmOld);

  modal.appendChild(header);
  modal.appendChild(alcanceLabel);
  modal.appendChild(alcanceTextarea);
  modal.appendChild(agregarCambiosBtn);
  modal.appendChild(footer);
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  // Close function
  var close = function() {
    overlay.remove();
  };

  // Click overlay to close
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) close();
  });

  // Cancel button
  btnCancelOld.addEventListener('click', close);

  // Agregar cambios button
  agregarCambiosBtn.addEventListener('click', function() {
    close();
    window.solicitarCambiosActa(docId, msgId);
  });

  // Confirm button - Solo aprueba sin cambios
  btnConfirmOld.addEventListener('click', function() {
    var fd = new FormData();
    fd.append('doc_id', docId);
    ggiPost(window.APP_URL + '/modules/clientes/confirmar_acta_alcance.php', fd, { silent: true })
    .then(function(data) {
      close();
      if (data.ok) {
        if (typeof showToast === 'function') showToast(data.msg || 'Aprobado correctamente.', 'ok');
        setTimeout(function() { location.reload(); }, 1500);
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'Error al aprobar.', 'error');
      }
    })
    .catch(function(err) {
      close();
      if (typeof showToast === 'function') showToast('Error de conexión: ' + (err.message || 'desconocido'), 'error');
    });
  });
};

window.solicitarCambiosActa = function(docId, msgId) {
  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;';

  var modal = document.createElement('div');
  modal.style.cssText = 'background:var(--bg-surface);border-radius:14px;padding:28px;max-width:580px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.2);margin:auto;';

  var header = document.createElement('div');
  header.innerHTML = '<h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0 0 8px;">Solicitar Cambios al Acta</h3><p style="font-size:12px;color:#6b7280;margin:0 0 18px;">Describe los cambios que el cliente ha solicitado. Los gerentes serán notificados para regenerar el acta.</p>';

  var cambiosLabel = document.createElement('label');
  cambiosLabel.style.cssText = 'font-size:12px;font-weight:600;color:var(--s-info-tx);display:block;margin-bottom:8px;';
  cambiosLabel.textContent = 'Cambios solicitados *';

  var cambiosTextarea = document.createElement('textarea');
  cambiosTextarea.id = 'cambios-acta-textarea';
  cambiosTextarea.rows = 6;
  cambiosTextarea.style.cssText = 'width:100%;padding:10px 13px;border:1.5px solid #d1d5db;border-radius:9px;font-size:13px;font-family:\'Inter\',system-ui,sans-serif;resize:vertical;outline:none;line-height:1.6;box-sizing:border-box;margin-bottom:16px;';
  cambiosTextarea.placeholder = 'Ej: Cliente solicita agregar auditoría de ciberseguridad, extender cronograma a 6 meses, incluir módulo de capacitación...';

  var footer = document.createElement('div');
  footer.style.cssText = 'display:flex;gap:10px;';
  var btnCancelCambios = document.createElement('button');
  btnCancelCambios.type = 'button';
  btnCancelCambios.className = 'ggi-btn-confirm-cancel cambios-btn-cancel';
  btnCancelCambios.style.flex = '1';
  btnCancelCambios.textContent = 'Cancelar';

  var btnSaveCambios = document.createElement('button');
  btnSaveCambios.type = 'button';
  btnSaveCambios.className = 'cambios-btn-save';
  btnSaveCambios.style.flex = '1';
  btnSaveCambios.style.padding = '8px 18px';
  btnSaveCambios.style.borderRadius = '8px';
  btnSaveCambios.style.border = 'none';
  btnSaveCambios.style.background = 'var(--s-info-tx)';
  btnSaveCambios.style.color = '#fff';
  btnSaveCambios.style.cursor = 'pointer';
  btnSaveCambios.style.fontWeight = '500';
  btnSaveCambios.style.fontSize = '13px';
  btnSaveCambios.textContent = 'Guardar y Notificar';

  footer.appendChild(btnCancelCambios);
  footer.appendChild(btnSaveCambios);

  modal.appendChild(header);
  modal.appendChild(cambiosLabel);
  modal.appendChild(cambiosTextarea);
  modal.appendChild(footer);
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  // Close function
  var close = function() {
    overlay.remove();
  };

  // Click overlay to close
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) close();
  });

  // Cancel button
  btnCancelCambios.addEventListener('click', close);

  // Save button
  btnSaveCambios.addEventListener('click', function() {
    var cambios = cambiosTextarea.value.trim();
    if (!cambios) {
      showToast('Por favor, describe los cambios solicitados.', 'error');
      return;
    }

    var fd = new FormData();
    fd.append('doc_id', docId);
    fd.append('cambios_notas', cambios);
    ggiPost(window.APP_URL + '/modules/clientes/solicitar_cambios_acta.php', fd, { silent: true })
    .then(function(data) {
      close();
      if (data.ok) {
        if (typeof showToast === 'function') showToast(data.msg || 'Cambios registrados.', 'ok');
        setTimeout(function() { location.reload(); }, 1500);
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'Error al registrar cambios.', 'error');
      }
    })
    .catch(function(err) {
      if (typeof showToast === 'function') showToast('Error de conexión: ' + (err.message || 'desconocido'), 'error');
      close();
    });
  });
};
</script>

<?php include __DIR__ . '/_empresa_modal.php'; ?>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
