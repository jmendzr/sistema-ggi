<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/cotizaciones/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT cot.*,
           COALESCE(cl.razon_social, cot.empresa_nombre) AS nombre_empresa,
           cl.horario_contacto,
           COALESCE(cl.ruc, cot.empresa_ruc) AS ruc_empresa,
           COALESCE(cl.direccion, cot.empresa_direccion) AS dir_empresa,
           u.nombre AS creado_por_nombre,
           ej.nombre AS ejecutivo_nombre, ej.apellido AS ejecutivo_apellido
    FROM cotizaciones cot
    LEFT JOIN clientes cl   ON cl.id  = cot.cliente_id
    JOIN  usuarios u        ON u.id   = cot.creado_por
    LEFT JOIN usuarios ej   ON ej.id  = cot.ejecutivo_id
    WHERE cot.id = ?
");
$stmt->execute([$id]);
$cot = $stmt->fetch();
if (!$cot) { header('Location: ' . APP_URL . '/modules/cotizaciones/index.php'); exit; }

// Al revisar la propuesta, marcar leídas las notificaciones informativas de su cliente.
require_once __DIR__ . '/../../core/NotificationService.php';
NotificationService::markSeen((int)$user['id'], (int)($cot['cliente_id'] ?? 0));

// ── Auto-vencimiento ───────────────────────────────────────────────────────
// Si tiene fecha_vencimiento, ya pasó y sigue en borrador/enviada → marcar vencida
if (!empty($cot['fecha_vencimiento'])
    && in_array($cot['estado'], ['borrador','enviada'])
    && strtotime($cot['fecha_vencimiento']) < strtotime('today')
) {
    $pdo->prepare("UPDATE cotizaciones SET estado='vencida' WHERE id=?")->execute([$id]);
    log_actividad('propuestas', 'auto_vencimiento', "Propuesta #{$id} marcada como vencida automáticamente");
    // Recargar para reflejar nuevo estado
    header('Location: ' . APP_URL . '/modules/cotizaciones/ver.php?id=' . $id);
    exit;
}

// Normas de esta propuesta
$normas = $pdo->prepare("
    SELECT cn.precio, n.codigo, n.nombre
    FROM cotizacion_normas cn
    JOIN normas_iso n ON n.id = cn.norma_iso_id
    WHERE cn.cotizacion_id = ?
    ORDER BY n.codigo
");
$normas->execute([$id]);
$normas = $normas->fetchAll();

// Propuesta firmada adjunta
$firmada = null;
try {
    $f = $pdo->prepare("SELECT * FROM cotizacion_archivos WHERE cotizacion_id = ? AND tipo = 'firmada' ORDER BY version DESC LIMIT 1");
    $f->execute([$id]);
    $firmada = $f->fetch() ?: null;
} catch (\Throwable $e) { $firmada = null; }

// Seguimientos de esta propuesta
$seguimientos = $pdo->prepare("
    SELECT cs.*, u.nombre, u.apellido
    FROM cotizacion_seguimientos cs
    JOIN usuarios u ON u.id = cs.usuario_id
    WHERE cs.cotizacion_id = ?
    ORDER BY cs.created_at DESC
");
$seguimientos->execute([$id]);
$seguimientos = $seguimientos->fetchAll();

// Historial de estados desde log_actividad
// Se filtra por created_at >= cotizacion.created_at para evitar entradas huérfanas
// de IDs reutilizados tras truncar la tabla
$log_estados = $pdo->prepare("
    SELECT l.descripcion, l.accion, l.created_at, u.nombre, u.apellido
    FROM log_actividad l
    LEFT JOIN usuarios u ON u.id = l.usuario_id
    WHERE l.modulo = 'cotizaciones'
      AND l.accion IN ('crear','estado','estado_bulk','editar','auto_vencimiento')
      AND (l.descripcion LIKE ? OR l.descripcion LIKE ?)
      AND l.created_at >= ?
    ORDER BY l.created_at ASC
");
$log_estados->execute(["%#$id →%", "%#$id %", $cot['created_at']]);
$log_estados = $log_estados->fetchAll();
$dias_enviada = $cot['estado'] === 'enviada' && $cot['fecha_emision']
    ? (int)((strtotime('today') - strtotime($cot['fecha_emision'])) / 86400)
    : null;

// ── Historial de versiones (cotizaciones_historial) ──────────────────────────
$historial_versiones = [];
try {
    $hv = $pdo->prepare("
        SELECT ch.*, u.nombre AS editor_nombre, u.apellido AS editor_apellido
        FROM cotizaciones_historial ch
        LEFT JOIN usuarios u ON u.id = ch.editado_por
        WHERE ch.cotizacion_id = ?
        ORDER BY ch.version_num DESC
    ");
    $hv->execute([$id]);
    $historial_versiones = $hv->fetchAll();
} catch (\Throwable) {}

// Manejo de cambio de estado (solo ventas y gerente)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    // ── AJAX: guardar nota interna ──────────────────────────────────────────
    if ($accion === 'guardar_nota' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json; charset=utf-8');
        $nota_nueva = trim($_POST['notas'] ?? '');
        $pdo->prepare("UPDATE cotizaciones SET notas=? WHERE id=?")
            ->execute([$nota_nueva ?: null, $id]);
        log_actividad('propuestas', 'nota', "Nota interna actualizada en propuesta #$id");
        echo json_encode(['ok' => true, 'notas' => $nota_nueva, 'csrf' => csrf_token()]);
        exit;
    }

    if ($accion === 'guardar_seguimiento') {
        $tipo_seg    = $_POST['tipo_seg'] ?? '';
        $resultado   = $_POST['resultado_seg'] ?? 'sin_respuesta';
        $notas_seg   = trim($_POST['notas_seg'] ?? '');
        $prox_accion = $_POST['proxima_accion_seg'] ?? null;
        $tipos_v     = ['llamada','email','reunion','whatsapp','otro'];
        $result_v    = ['sin_respuesta','interesado','pidio_tiempo','rechazado','aceptado'];
        if (in_array($tipo_seg,$tipos_v) && in_array($resultado,$result_v) && $notas_seg) {
            $pdo->prepare("
                INSERT INTO cotizacion_seguimientos (cotizacion_id, usuario_id, tipo, notas, resultado, proxima_accion)
                VALUES (?,?,?,?,?,?)
            ")->execute([$id, $user['id'], $tipo_seg, $notas_seg, $resultado, $prox_accion ?: null]);
            log_actividad('propuestas','seguimiento',"Seguimiento en propuesta #$id");
        }
        header('Location: ' . APP_URL . '/modules/cotizaciones/ver.php?id=' . $id . '&msg=' . urlencode('Seguimiento registrado.'));
        exit;
    }

}

$nuevo = isset($_GET['nuevo']);


header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$page_title  = 'Propuesta ' . $cot['numero'];
$active_menu = 'propuestas';
require_once __DIR__ . '/../../templates/layout.php';

// Estado config — incluye vencida
$estado_config = [
    'borrador'  => ['color' => 'var(--tx2)', 'label' => 'Borrador'],
    'enviada'   => ['color' => '#3B82F6', 'label' => 'Enviada'],
    'aceptada'  => ['color' => '#10B981', 'label' => 'Aceptada'],
    'rechazada' => ['color' => '#EF4444', 'label' => 'Rechazada'],
    'vencida'   => ['color' => '#F59E0B', 'label' => 'Vencida'],
    'desistida' => ['color' => '#6B7280', 'label' => 'Desistida'],
];
$ec = $estado_config[$cot['estado']] ?? ['color'=>'var(--tx2)','label'=>$cot['estado']];

$pdf_url = !empty($cot['archivo_pdf'])
    ? rtrim(UPLOAD_URL,'/') . '/cotizaciones/' . $cot['archivo_pdf']
    : null;

// Calcular días para vencimiento
$dias_vence = null;
$vence_alerta = false;
if (!empty($cot['fecha_vencimiento']) && in_array($cot['estado'], ['borrador','enviada'])) {
    $dias_vence  = (int)((strtotime($cot['fecha_vencimiento']) - strtotime('today')) / 86400);
    $vence_alerta = $dias_vence <= 5 && $dias_vence >= 0;
}
?>
<style>
  .det-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; margin-bottom:20px; }
  .det-header { padding:22px 28px; border-bottom:1px solid var(--gris-borde); display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
  .det-section { padding:20px 28px; border-bottom:1px solid var(--gris-borde); }
  .det-section:last-child { border-bottom:none; }
  .det-section-title { font-size:12px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:14px; display:flex; align-items:center; gap:7px; }
  .detail-row { display:flex; gap:16px; padding:8px 0; border-bottom:1px solid var(--gris-borde); font-size:13px; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { width:160px; font-weight:600; color:var(--gris-texto); flex-shrink:0; }
  .detail-value { color:var(--gris-dark); flex:1; }
  .norma-row { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border:1px solid var(--gris-borde); border-radius:9px; margin-bottom:8px; }
  .norma-row:last-child { margin-bottom:0; }
  /* Desglose de precios de la cotización */
  .cot-desglose { margin-top:12px; padding:12px 14px; border:1px solid var(--gris-borde); border-radius:10px; background:var(--bg-base); }
  .cot-desglose-row { display:flex; align-items:center; justify-content:space-between; font-size:13px; color:var(--gris-texto); padding:4px 0; }
  .cot-desglose-row > span:last-child { color:var(--gris-dark); font-weight:600; }
  .cot-desglose-desc > span:last-child { color:#c53030; }
  .cot-desglose-total { margin-top:6px; padding-top:10px; border-top:1px solid var(--gris-borde); font-size:14px; }
  .cot-desglose-total > span { color:var(--gris-dark); font-weight:700; }
  .cot-desglose-total > span:last-child { font-size:16px; }
  .vence-chip { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; border:1px solid transparent; }
  /* Seguimiento */
  .seg-panel { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; margin-bottom:20px; }
  .seg-panel-hdr { padding:16px 22px; border-bottom:1px solid var(--gris-borde); display:flex; align-items:center; justify-content:space-between; gap:12px; background:var(--gris-bg); }
  .seg-panel-title { font-size:13px; font-weight:700; color:var(--gris-dark); display:flex; align-items:center; gap:8px; }
  .dias-urgente { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:700; }
  .seg-list { padding:0 22px; }
  .seg-item { display:flex; gap:10px; padding:11px 0; border-bottom:1px dashed var(--gris-borde); }
  .seg-item:last-child { border-bottom:none; }
  .seg-icon { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; margin-top:1px; }
  .seg-body { flex:1; min-width:0; }
  .seg-meta { display:flex; align-items:center; gap:7px; flex-wrap:wrap; margin-bottom:3px; }
  .seg-tag { display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:100px; font-size:10px; font-weight:600; }
  .seg-fecha-txt { font-size:11px; color:var(--gris-texto); }
  .seg-notas-txt { font-size:12px; color:var(--gris-dark); line-height:1.4; }
  .seg-prox { font-size:11px; font-weight:600; margin-top:3px; }
  .seg-empty-txt { padding:16px 22px; font-size:13px; color:var(--gris-texto); font-style:italic; }
  .seg-form-wrap { padding:16px 22px; border-top:1px solid var(--gris-borde); background:var(--gris-bg); }
  .seg-form-title { font-size:12px; font-weight:700; color:var(--gris-dark); margin-bottom:12px; display:flex; align-items:center; gap:6px; }
  .seg-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
  .seg-form-field label { display:block; font-size:10px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px; }
  .seg-form-field select, .seg-form-field input, .seg-form-field textarea {
    width:100%; padding:8px 10px; border:1.5px solid var(--gris-borde); border-radius:8px;
    font-size:12px; font-family:'Inter',sans-serif; outline:none; background:var(--gris-card); color:var(--gris-dark); transition:.15s;
  }
  .seg-form-field select:focus, .seg-form-field input:focus, .seg-form-field textarea:focus { border-color:var(--azul); }
  .seg-form-field textarea { resize:vertical; min-height:60px; }
  /* Modal seguimiento (moved to ggi-modal-* classes in design-system.css) */
  .modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
  .modal-field label { display:block; font-size:10px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
  .modal-field select, .modal-field input, .modal-field textarea {
    width:100%; padding:9px 11px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--gris-card); color:var(--gris-dark); transition:.15s;
  }
  .modal-field select:focus, .modal-field input:focus, .modal-field textarea:focus { border-color:var(--azul); box-shadow:0 0 0 3px rgba(26,58,143,.08); }
  .modal-field textarea { resize:vertical; min-height:72px; }
  @media (max-width:768px) {
    .det-section, .det-header { padding:16px; }
    .detail-row { flex-direction:column; gap:4px; }
    .detail-label { width:auto; }
    /* ── Seguimiento comercial responsive ── */
    .seg-panel { max-width:100%; }
    .seg-panel-hdr { padding:14px 16px; flex-wrap:wrap; }
    .seg-list { padding:0 16px; }
    .seg-form-wrap { padding:14px 16px; }
    .seg-form-grid { grid-template-columns:1fr; gap:10px; }   /* apilar campos */
    .modal-grid    { grid-template-columns:1fr; gap:10px; }
  }
</style>

<?php
ggi_breadcrumb(array_filter([
    ['label' => 'Empresas',   'href' => APP_URL.'/modules/clientes/index.php', 'ss' => 'ggi_ss_clientes'],
    !empty($cot['cliente_id']) ? ['label' => sanitize($cot['nombre_empresa']), 'href' => APP_URL.'/modules/clientes/ver.php?id='.(int)$cot['cliente_id']] : null,
    ['label' => 'Propuestas', 'href' => APP_URL.'/modules/cotizaciones/index.php', 'ss' => 'ggi_ss_cotizaciones'],
    ['label' => sanitize($cot['numero'])],
]));
?>

<!-- Page header -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1><?= sanitize($cot['numero']) ?></h1>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $id ?>&inline=1"
       data-ggi-preview
       data-src="<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $id ?>&inline=1"
       data-download-url="<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $id ?>&dl=1"
       data-title="Propuesta <?= sanitize($cot['numero']) ?>"
       class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      Ver propuesta
    </a>
    <a href="<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $id ?>&dl=1" download
       onclick="event.preventDefault(); ggiDownloadBlob(this.href);"
       class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Descargar PDF
    </a>
    <?php if (in_array($cot['estado'], ['borrador','enviada'])): ?>
    <button type="button" id="btn-editar" onclick="ggiAbrirPropuesta('edit', <?= (int)$id ?>)" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Editar
    </button>
    <button id="btn-aceptar" onclick="confirmarAceptar()"
      style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:var(--gr);color:#fff;border:none;border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;"
      onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      Aceptar
    </button>
    <button id="btn-rechazar" onclick="confirmarRechazar()"
      style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:var(--s-err-bg);color:var(--s-err);border:1.5px solid var(--s-err-brd);border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;"
      onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Rechazar
    </button>
    <?php endif; ?>
    <?php if ($cot['estado'] === 'aceptada' && has_role('gerente_general','gerente_operaciones')): ?>
    <button id="btn-desistir" onclick="confirmarDesistir()"
      style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:var(--s-err-bg);color:var(--s-err);border:1.5px solid var(--s-err-brd);border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;"
      onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M4 4v6h6"/><path d="M20 20a8 8 0 0 0-14.5-6.4L4 10"/></svg>
      Marcar desistida
    </button>
    <?php endif; ?>

    <?php if (!empty($cot['cliente_id'])): ?>
    <button type="button" onclick="ggiAbrirLlamada()" class="btn btn-outline btn-sm" title="Agendar llamada sobre esta propuesta (recordatorio push)">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.62 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
      Llamada
    </button>
    <?php endif; ?>
    <button type="button" onclick="ggiAbrirPropuesta('duplicate', <?= (int)$id ?>)" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      Duplicar
    </button>
  </div>
</div>
<?php include __DIR__ . '/_propuesta_modal.php'; ?>

<?php
// Agenda de llamadas EN ESTA propuesta (modal en sitio, sin salir del módulo).
// La llamada queda ligada a esta cotización pero vive en el historial único
// (llamadas_programadas): la ficha del cliente también la muestra.
if (!empty($cot['cliente_id'])) {
    $_llam_st = $pdo->prepare("
        SELECT ll.*, CONCAT(u.nombre,' ',u.apellido) AS asignado_nombre
        FROM llamadas_programadas ll
        JOIN usuarios u ON u.id = ll.usuario_id
        WHERE ll.cotizacion_id = ? AND ll.estado IN ('pendiente','notificada')
        ORDER BY ll.fecha_hora ASC
    ");
    $_llam_st->execute([(int)$id]);
    $_llam_cliente_id = (int)$cot['cliente_id'];
    $_llam_cot_id     = (int)$id;
    $_llam_empresa    = $cot['nombre_empresa'];
    $_llam_horario    = $cot['horario_contacto'] ?? '';
    $_llam_abiertas   = $_llam_st->fetchAll();
    include __DIR__ . '/../clientes/_llamada_modal.php';
}
?>




<?php if ($vence_alerta): ?>
<div class="alert-banner warn" style="margin-bottom:16px;align-items:center;gap:10px;flex-wrap:nowrap;">
  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <span><?= $dias_vence === 0 ? 'Esta propuesta vence <strong>hoy</strong>.' : "Esta propuesta vence en <strong>$dias_vence día" . ($dias_vence !== 1 ? 's' : '') . "</strong>." ?> Considera actualizar su estado.</span>
</div>
<?php endif; ?>

<!-- Detalle -->
<div class="det-card">
  <div class="det-header">
    <div style="min-width:0;flex:1;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
        <span style="font-size:20px;font-weight:700;color:var(--gris-dark);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;" title="<?= sanitize($cot['nombre_empresa']) ?>"><?= sanitize_trunc($cot['nombre_empresa'], 30) ?></span>
        <span id="badge-estado" style="background:<?= $ec['color'] ?>22;color:<?= $ec['color'] ?>;border:1px solid <?= $ec['color'] ?>55;padding:3px 12px;border-radius:100px;font-size:11px;font-weight:600;"><?= $ec['label'] ?></span>
        <?php if ($dias_vence !== null && !$vence_alerta): ?>
        <span class="vence-chip chip-info">
          <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Vence en <?= $dias_vence ?> día<?= $dias_vence !== 1 ? 's' : '' ?>
        </span>
        <?php endif; ?>
      </div>
      <div style="font-size:13px;color:var(--gris-texto);">
        RUC: <strong style="color:var(--gris-dark);"><?= sanitize($cot['ruc_empresa'] ?? '—') ?></strong>
        <?php if ($cot['tipo_empresa'] === 'nueva' && $cot['estado'] !== 'aceptada'): ?>
        &nbsp;·&nbsp;<span id="chip-tipo-empresa" class="chip chip-warn chip-sm">Prospecto</span>
        <?php else: ?>
        &nbsp;·&nbsp;<span id="chip-tipo-empresa" class="chip chip-ok chip-sm">Empresa registrada</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($cot['monto_total']): ?>
    <div style="text-align:right;flex-shrink:0;white-space:nowrap;">
      <div style="font-size:11px;color:var(--gris-texto);margin-bottom:2px;">Monto total</div>
      <div style="font-size:24px;font-weight:700;color:var(--gris-dark);">S/ <?= number_format($cot['monto_total'], 2) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Info general -->
  <div class="det-section">
    <div class="det-section-title">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Información general
    </div>
    <div class="detail-row"><div class="detail-label">Número</div><div class="detail-value"><code style="color:var(--gris-dark);font-weight:700;"><?= sanitize($cot['numero']) ?></code></div></div>
    <div class="detail-row"><div class="detail-label">Dirección</div><div class="detail-value"><?= sanitize($cot['dir_empresa'] ?: '—') ?></div></div>
    <div class="detail-row">
      <div class="detail-label">Fecha emisión</div>
      <div class="detail-value"><?= $cot['fecha_emision'] ? date('d/m/Y', strtotime($cot['fecha_emision'])) : '—' ?></div>
    </div>
    <div class="detail-row">
      <div class="detail-label">Fecha vencimiento</div>
      <div class="detail-value">
        <?php if ($cot['fecha_vencimiento']): ?>
          <?= date('d/m/Y', strtotime($cot['fecha_vencimiento'])) ?>
          <?php if ($cot['estado'] === 'vencida'): ?>
            <span class="chip chip-warn chip-sm" style="margin-left:6px;">Vencida</span>
          <?php elseif ($dias_vence !== null && $dias_vence <= 5): ?>
            <span class="chip chip-warn chip-sm" style="margin-left:6px;">⚠ En <?= $dias_vence ?> día<?= $dias_vence !== 1 ? 's' : '' ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span style="color:var(--gris-texto);">Sin fecha de vencimiento</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($cot['estado'] === 'aceptada'): ?>
    <div class="detail-row">
      <div class="detail-label">Certificados requeridos</div>
      <div class="detail-value" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span id="fecha-cert-display" data-fecha="<?= sanitize($cot['fecha_requerida_certificados'] ?? '') ?>">
          <?= $cot['fecha_requerida_certificados'] ? date('d/m/Y', strtotime($cot['fecha_requerida_certificados'])) : '<span style="color:var(--gris-texto);">Sin fecha</span>' ?>
        </span>
        <?php if (has_role('gerente_general','gerente_operaciones')): ?>
        <button type="button" class="btn btn-outline btn-sm" style="padding:2px 9px;font-size:11px;" onclick="editarFechaCert()">Editar</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($cot['ejecutivo_nombre']): ?>
    <div class="detail-row">
      <div class="detail-label">Ejecutivo</div>
      <div class="detail-value" style="display:flex;align-items:center;gap:8px;">
        <div style="width:28px;height:28px;border-radius:50%;background:var(--s-neutral);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">
          <?= strtoupper(substr($cot['ejecutivo_nombre'],0,1).substr($cot['ejecutivo_apellido'],0,1)) ?>
        </div>
        <span><?= sanitize($cot['ejecutivo_nombre'].' '.$cot['ejecutivo_apellido']) ?></span>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($cot['motivo_rechazo']): ?>
    <div class="detail-row"><div class="detail-label">Motivo rechazo</div><div class="detail-value" style="color:var(--s-err);"><?= sanitize($cot['motivo_rechazo']) ?></div></div>
    <?php endif; ?>
    <?php if (!empty($cot['motivo_desistimiento'])): ?>
    <div class="detail-row">
      <div class="detail-label">Desistimiento</div>
      <div class="detail-value" style="color:var(--s-err);">
        <?= sanitize($cot['motivo_desistimiento']) ?>
        <?php if (!empty($cot['desistida_at'])): ?>
        <span style="color:var(--gris-texto);font-size:12px;"> — <?= date('d/m/Y', strtotime($cot['desistida_at'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Contacto -->
  <?php if ($cot['contacto_nombre'] || $cot['contacto_telefono'] || $cot['contacto_email']): ?>
  <div class="det-section">
    <div class="det-section-title">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Contacto
    </div>
    <?php if ($cot['contacto_nombre']): ?>
    <div class="detail-row"><div class="detail-label">Nombre</div><div class="detail-value"><?= sanitize(trim($cot['contacto_nombre'] . ' ' . ($cot['contacto_apellido'] ?? ''))) ?></div></div>
    <?php endif; ?>
    <?php if ($cot['contacto_telefono']): ?>
    <div class="detail-row"><div class="detail-label">Teléfono</div><div class="detail-value"><?= sanitize($cot['contacto_telefono']) ?></div></div>
    <?php endif; ?>
    <?php if ($cot['contacto_email']): ?>
    <div class="detail-row"><div class="detail-label">Correo</div><div class="detail-value"><a href="mailto:<?= sanitize($cot['contacto_email']) ?>" style="color:var(--gris-texto);"><?= sanitize($cot['contacto_email']) ?></a></div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Normas -->
  <div class="det-section">
    <div class="det-section-title">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      Normas ISO cotizadas
    </div>
    <?php if (empty($normas)): ?>
    <p style="color:var(--gris-texto);font-size:13px;">Sin normas registradas.</p>
    <?php else: ?>
    <?php
      // Desglose de precios — misma lógica que el PDF (pdf.php)
      $_sym     = ($cot['moneda'] ?? 'PEN') === 'USD' ? 'USD ' : 'S/ ';
      $_sub     = (float)array_sum(array_column($normas, 'precio'));
      $_desc    = (float)($cot['descuento'] ?? 0);
      $_base    = max(0, $_sub - $_desc);
      $_inc_igv = !empty($cot['incluye_igv']);
      $_igv     = $_inc_igv ? round($_base * 0.18, 2) : 0.0;
      $_total   = round($_base + $_igv, 2);
    ?>
    <?php foreach ($normas as $n): ?>
    <div class="norma-row">
      <div>
        <strong style="color:var(--gris-dark);"><?= sanitize($n['codigo']) ?></strong>
        <span style="color:var(--gris-texto);font-size:12px;"> — <?= sanitize($n['nombre']) ?></span>
      </div>
      <?php if ($n['precio']): ?>
      <span class="ggi-num" style="font-weight:600;color:var(--gris-dark);"><?= $_sym . number_format($n['precio'], 2) ?></span>
      <?php else: ?>
      <span style="color:var(--gris-texto);font-size:12px;">Sin precio</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Desglose de precios (sin necesidad de descargar la propuesta) -->
    <div class="cot-desglose">
      <div class="cot-desglose-row">
        <span>Subtotal</span>
        <span class="ggi-num"><?= $_sym . number_format($_sub, 2) ?></span>
      </div>
      <?php if ($_desc > 0): ?>
      <div class="cot-desglose-row cot-desglose-desc">
        <span>Descuento</span>
        <span class="ggi-num">− <?= $_sym . number_format($_desc, 2) ?></span>
      </div>
      <?php endif; ?>
      <div class="cot-desglose-row">
        <span>IGV <?= $_inc_igv ? '(18%)' : '' ?></span>
        <span class="ggi-num"><?= $_inc_igv ? $_sym . number_format($_igv, 2) : 'No afecto' ?></span>
      </div>
      <div class="cot-desglose-row cot-desglose-total">
        <span>Total <span style="font-weight:500;color:var(--gris-texto);font-size:11px;"><?= $_inc_igv ? '· IGV incluido' : '· sin IGV' ?></span></span>
        <span class="ggi-num"><?= $_sym . number_format($_total, 2) ?></span>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Observaciones (cliente) -->
  <?php if (!empty($cot['observaciones'])): ?>
  <div class="det-section">
    <div class="det-section-title">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Observaciones
    </div>
    <p style="font-size:13px;color:var(--gris-dark);line-height:1.6;"><?= nl2br(sanitize($cot['observaciones'])) ?></p>
  </div>
  <?php endif; ?>

  <!-- Nota interna — siempre visible, editable inline para todos los estados -->
  <div class="det-section nota-interna-section" id="nota-interna-section"
       style="border-left:3px solid #f59e0b;padding-left:12px;">
    <div class="det-section-title" style="color:var(--s-warn-tx);display:flex;align-items:center;justify-content:space-between;gap:8px;">
      <span style="display:flex;align-items:center;gap:6px;">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Nota interna
        <span style="font-size:10px;font-weight:400;color:var(--s-warn-tx);opacity:.7;">(solo visible en el sistema)</span>
      </span>
      <button type="button" id="btn-editar-nota" onclick="notaIniciarEdicion()"
        style="background:none;border:none;cursor:pointer;padding:4px 6px;border-radius:6px;color:var(--s-warn-tx);display:flex;align-items:center;gap:4px;font-size:11px;font-weight:600;transition:background .15s;"
        onmouseover="this.style.background='var(--s-warn-bg)'" onmouseout="this.style.background='none'"
        title="Editar nota interna">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Editar
      </button>
    </div>

    <!-- Vista de lectura -->
    <div id="nota-display">
      <?php if (!empty($cot['notas'])): ?>
        <p id="nota-texto" style="font-size:13px;color:var(--gris-dark);line-height:1.6;margin:0;"><?= nl2br(sanitize($cot['notas'])) ?></p>
      <?php else: ?>
        <p id="nota-texto" style="font-size:12px;color:var(--gris-texto);font-style:italic;margin:0;">Sin nota. Haz clic en "Editar" para agregar una.</p>
      <?php endif; ?>
    </div>

    <!-- Editor inline (oculto por defecto) -->
    <div id="nota-editor" style="display:none;margin-top:8px;">
      <textarea id="nota-textarea"
        style="width:100%;min-height:80px;resize:vertical;padding:9px 12px;border:1.5px solid #f59e0b;border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;color:var(--gris-dark);background:var(--s-warn-bg);outline:none;box-sizing:border-box;"
        placeholder="Escribe una nota interna..."><?= htmlspecialchars($cot['notas'] ?? '', ENT_QUOTES) ?></textarea>
      <div style="display:flex;gap:8px;margin-top:8px;align-items:center;">
        <button type="button" onclick="notaGuardar()" id="btn-nota-guardar"
          style="padding:6px 14px;background:var(--s-warn);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;font-family:'Inter',sans-serif;">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Guardar
        </button>
        <button type="button" onclick="notaCancelar()"
          style="padding:6px 14px;background:none;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;color:var(--gris-texto);font-family:'Inter',sans-serif;">
          Cancelar
        </button>
        <span id="nota-saving" style="display:none;font-size:11px;color:var(--s-warn-tx);">Guardando...</span>
      </div>
    </div>
  </div>

  <!-- PDF adjunto -->
  <?php if ($pdf_url): ?>
  <div class="det-section">
    <div class="det-section-title">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="#ef4444" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Documento adjunto
    </div>
    <a href="<?= $pdf_url ?>" data-ggi-preview data-src="<?= $pdf_url ?>" data-download-url="<?= $pdf_url ?>" data-title="Propuesta <?= sanitize($cot['numero']) ?> (PDF adjunto)" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Ver PDF de propuesta
    </a>
  </div>
  <?php endif; ?>


  <!-- Historial de estados -->
  <?php if (!empty($log_estados)): ?>
  <div class="det-section">
    <div class="det-section-title">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Historial de estados
    </div>
    <div style="display:flex;flex-direction:column;gap:0;">
      <?php
      $estado_icons = [
        'crear'             => ['#6B7280','Creada'],
        'estado'            => null, // se parsea del texto
        'estado_bulk'       => null,
        'editar'            => ['#3B82F6','Editada'],
        'auto_vencimiento'  => ['#F59E0B','Vencida (auto)'],
      ];
      $estado_colores = [
        'borrador'  => '#6B7280',
        'enviada'   => '#3B82F6',
        'aceptada'  => '#10B981',
        'rechazada' => '#EF4444',
        'vencida'   => '#F59E0B',
      ];
      foreach ($log_estados as $i => $log):
        // Parsear estado del texto: "Propuesta #X → enviada"
        preg_match('/→\s*(\w+)/', $log['descripcion'], $m);
        $est_nombre = $m[1] ?? null;
        $accion     = $log['accion'] ?? '';
        if ($accion === 'editar') {
          $color = '#3B82F6'; $label = 'Editada';
        } elseif ($accion === 'crear' || str_contains($log['descripcion'], 'creada') || str_contains($log['descripcion'], 'creado')) {
          $color = '#6B7280'; $label = 'Creada';
        } elseif ($accion === 'auto_vencimiento') {
          $color = '#F59E0B'; $label = 'Vencida (auto)';
        } elseif ($est_nombre) {
          $color = $estado_colores[$est_nombre] ?? '#6B7280';
          $label = ucfirst($est_nombre);
        } else {
          $color = '#6B7280'; $label = ucfirst($accion) ?: '—';
        }
        $autor = trim(($log['nombre'] ?? '') . ' ' . ($log['apellido'] ?? '')) ?: 'Sistema';
        $fecha = date('d/m/Y H:i', strtotime($log['created_at']));
        $es_ultimo = $i === count($log_estados) - 1;
      ?>
      <div style="display:flex;align-items:flex-start;gap:12px;padding:8px 0;<?= !$es_ultimo ? 'border-bottom:1px dashed var(--bg-surface2);' : '' ?>">
        <div style="display:flex;flex-direction:column;align-items:center;gap:0;flex-shrink:0;padding-top:2px;">
          <div style="width:10px;height:10px;border-radius:50%;background:<?= $color ?>;flex-shrink:0;"></div>
          <?php if (!$es_ultimo): ?>
          <div style="width:1.5px;height:24px;background:var(--brd);margin-top:2px;"></div>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0;">
          <span style="font-size:12px;font-weight:600;color:<?= $color ?>;"><?= $label ?></span>
          <span style="font-size:11px;color:var(--gris-texto);margin-left:8px;"><?= $fecha ?> · <?= sanitize($autor) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /det-card -->

<!-- ── Historial de versiones (cambios en edición) ──────────────────────── -->
<?php if (!empty($historial_versiones)): ?>
<div class="det-card" id="card-versiones" style="margin-bottom:16px;">
  <div class="det-header" style="padding:18px 24px;cursor:pointer;user-select:none;"
       onclick="(function(btn){var b=document.getElementById('hist-ver-body');var open=b.style.display!=='none';b.style.display=open?'none':'block';btn.querySelector('.hv-chevron').style.transform=open?'rotate(0deg)':'rotate(180deg)';})(this)">
    <div style="display:flex;align-items:center;gap:9px;">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#6B7280" stroke-width="2"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2z"/><path d="M12 6v6l4 2"/></svg>
      <span style="font-size:14px;font-weight:700;color:var(--gris-dark);">Historial de ediciones</span>
      <span style="background:var(--s-info-bg);color:var(--azul);font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;"><?= count($historial_versiones) ?> versión<?= count($historial_versiones) > 1 ? 'es' : '' ?></span>
    </div>
    <svg class="hv-chevron" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="transition:transform .2s;transform:rotate(0deg);color:var(--gris-texto);flex-shrink:0;"><polyline points="6 9 12 15 18 9"/></svg>
  </div>

  <div id="hist-ver-body" style="display:none;">
    <?php foreach ($historial_versiones as $hv_idx => $hv): ?>
    <?php
      $hv_snap   = json_decode($hv['snapshot'], true) ?? [];
      $hv_normas = json_decode($hv['normas_snap'], true) ?? [];
      $hv_editor = trim(($hv['editor_nombre'] ?? '') . ' ' . ($hv['editor_apellido'] ?? '')) ?: 'Sistema';
      $hv_fecha  = date('d/m/Y H:i', strtotime($hv['created_at']));
      $hv_monto  = number_format((float)($hv_snap['monto_total'] ?? 0), 2);
      $hv_estado = $hv_snap['estado'] ?? '—';
      $hv_est_color = ['borrador'=>'#6B7280','enviada'=>'#3B82F6','aceptada'=>'#10B981','rechazada'=>'#EF4444','vencida'=>'#F59E0B'][$hv_estado] ?? '#6B7280';
      $hv_id_panel = 'hv-panel-' . $hv['id'];
    ?>
    <div style="border-top:1px solid var(--gris-borde);padding:14px 24px;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;cursor:pointer;"
           onclick="(function(el){var p=document.getElementById('<?= $hv_id_panel ?>');var open=p.style.display!=='none';p.style.display=open?'none':'block';el.querySelector('.hv-row-chevron').style.transform=open?'rotate(0deg)':'rotate(180deg)';})(this)">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <div style="width:28px;height:28px;background:var(--azul);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <span style="font-size:11px;font-weight:700;color:#fff;">v<?= $hv['version_num'] ?></span>
          </div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--gris-dark);">Versión <?= $hv['version_num'] ?> — antes de editar</div>
            <div style="font-size:11px;color:var(--gris-texto);"><?= $hv_fecha ?> · <?= sanitize($hv_editor) ?></div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:12px;font-weight:600;color:var(--gris-dark);">S/ <?= $hv_monto ?></span>
          <span style="font-size:11px;font-weight:600;background:<?= $hv_est_color ?>22;color:<?= $hv_est_color ?>;border:1px solid <?= $hv_est_color ?>44;padding:2px 9px;border-radius:999px;"><?= ucfirst($hv_estado) ?></span>
          <svg class="hv-row-chevron" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="transition:transform .2s;color:var(--gris-texto);"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </div>

      <!-- Detalle colapsable de la versión -->
      <div id="<?= $hv_id_panel ?>" style="display:none;margin-top:12px;">
        <div style="background:var(--gris-bg);border-radius:9px;padding:14px 16px;">
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 20px;margin-bottom:12px;">
            <div><span style="font-size:11px;color:var(--gris-texto);">Empresa</span><div style="font-size:12px;font-weight:600;color:var(--gris-dark);margin-top:2px;"><?= sanitize(substr($hv_snap['empresa_nombre'] ?? '—', 0, 40)) ?></div></div>
            <div><span style="font-size:11px;color:var(--gris-texto);">Fecha emisión</span><div style="font-size:12px;font-weight:600;color:var(--gris-dark);margin-top:2px;"><?= !empty($hv_snap['fecha_emision']) ? date('d/m/Y',strtotime($hv_snap['fecha_emision'])) : '—' ?></div></div>
            <div><span style="font-size:11px;color:var(--gris-texto);">Vencimiento</span><div style="font-size:12px;font-weight:600;color:var(--gris-dark);margin-top:2px;"><?= !empty($hv_snap['fecha_vencimiento']) ? date('d/m/Y',strtotime($hv_snap['fecha_vencimiento'])) : '—' ?></div></div>
            <div><span style="font-size:11px;color:var(--gris-texto);">Descuento</span><div style="font-size:12px;font-weight:600;color:var(--gris-dark);margin-top:2px;">S/ <?= number_format((float)($hv_snap['descuento'] ?? 0), 2) ?></div></div>
            <div><span style="font-size:11px;color:var(--gris-texto);">Subtotal</span><div style="font-size:12px;font-weight:600;color:var(--gris-dark);margin-top:2px;">S/ <?= number_format((float)($hv_snap['subtotal'] ?? 0), 2) ?></div></div>
            <div><span style="font-size:11px;color:var(--gris-texto);">IGV</span><div style="font-size:12px;font-weight:600;color:var(--gris-dark);margin-top:2px;">S/ <?= number_format((float)($hv_snap['igv'] ?? 0), 2) ?></div></div>
          </div>
          <?php if ($hv_normas): ?>
          <div style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;margin-bottom:7px;">Normas</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($hv_normas as $hn): ?>
            <span style="font-size:11px;background:var(--bg-surface);border:1px solid var(--gris-borde);border-radius:7px;padding:4px 10px;color:var(--gris-dark);">
              <?= sanitize($hn['codigo'] ?? 'Norma') ?> — S/ <?= number_format((float)($hn['precio'] ?? 0), 2) ?>
            </span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if (!empty($hv_snap['observaciones'])): ?>
          <div style="margin-top:10px;font-size:12px;color:var(--gris-texto);border-top:1px solid var(--gris-borde);padding-top:8px;"><strong>Obs:</strong> <?= sanitize(substr($hv_snap['observaciones'],0,200)) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Propuesta Firmada ─────────────────────────────────── -->
<?php
$puede_subir_firmada = in_array($user['rol'], ['gerente_general','gerente_operaciones','ejecutivo_ventas']);
$firmada_url = $firmada ? rtrim(UPLOAD_URL, '/') . '/' . ltrim($firmada['ruta_archivo'], '/') : null;
?>
<div class="det-card" id="card-firmada">
  <div class="det-header" style="padding:18px 24px;">
    <div style="display:flex;align-items:center;gap:9px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      <span style="font-size:14px;font-weight:700;color:var(--gris-dark);">Propuesta firmada</span>
      <?php if ($firmada): ?>
      <span class="chip chip-ok chip-sm">Adjuntada v<?= $firmada['version'] ?></span>
      <?php else: ?>
      <span class="chip chip-warn chip-sm">Sin adjuntar</span>
      <?php endif; ?>
    </div>
    <?php if ($puede_subir_firmada): ?>
    <label id="lbl-subir-firmada" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:var(--azul);color:#fff;border-radius:9px;font-size:12px;font-weight:600;transition:.15s;"
           onmouseover="this.style.background='#122870'" onmouseout="this.style.background='var(--azul)'">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      <?= $firmada ? 'Actualizar' : 'Subir firmada' ?>
      <input type="file" id="inp-firmada" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;" onchange="subirFirmada(this)">
    </label>
    <?php endif; ?>
  </div>
  <!-- Progress bar -->
  <div id="firmada-progress" style="display:none;height:3px;background:var(--gris-borde);border-radius:0 0 0 0;">
    <div id="firmada-pbar" style="height:100%;background:var(--azul);width:0%;transition:width .4s ease;"></div>
  </div>
  <?php if ($firmada): ?>
  <div class="det-section" id="firmada-info-wrap">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <div style="width:38px;height:38px;background:var(--s-ok-bg);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--s-ok)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      </div>
      <div style="flex:1;min-width:0;">
        <div id="firmada-nombre" style="font-size:13px;font-weight:600;color:var(--gris-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($firmada['nombre_archivo']) ?></div>
        <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;">
          Versión <?= $firmada['version'] ?> · <?= date('d/m/Y H:i', strtotime($firmada['created_at'])) ?>
        </div>
      </div>
      <a href="<?= $firmada_url ?>" data-ggi-preview data-src="<?= $firmada_url ?>" data-download-url="<?= $firmada_url ?>" data-title="Propuesta firmada <?= sanitize($cot['numero']) ?>" class="btn btn-outline btn-sm">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        Ver documento
      </a>
    </div>
  </div>
  <?php else: ?>
  <div class="det-section" id="firmada-info-wrap" style="text-align:center;padding:22px 28px;">
    <svg width="30" height="30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="color:var(--gris-texto);opacity:.35;margin:0 auto 8px;display:block;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <p style="font-size:12px;color:var(--gris-texto);">Aún no se ha adjuntado la propuesta firmada.</p>
  </div>
  <?php endif; ?>
</div>

<script>
async function subirFirmada(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const ext  = file.name.split('.').pop().toLowerCase();
  if (!['pdf','jpg','jpeg','png','webp'].includes(ext)) {
    if (typeof showToast === 'function') showToast('Formato no permitido. Solo PDF, JPG, PNG o WEBP.', 'error');
    input.value = ''; return;
  }
  if (file.size > 10 * 1024 * 1024) {
    if (typeof showToast === 'function') showToast('El archivo supera el límite de 10 MB.', 'error');
    input.value = ''; return;
  }
  const lbl   = document.getElementById('lbl-subir-firmada');
  const prog  = document.getElementById('firmada-progress');
  const pbar  = document.getElementById('firmada-pbar');
  const wrap  = document.getElementById('firmada-info-wrap');
  if (lbl)  { lbl.style.opacity = '0.6'; lbl.style.pointerEvents = 'none'; }
  if (prog) { prog.style.display = 'block'; pbar.style.width = '40%'; }

  const fd = new FormData();
  fd.append('archivo', file);
  fd.append('cotizacion_id', '<?= $id ?>');
  fd.append('_csrf', window.CSRF_TOKEN || '');

  try {
    if (pbar) pbar.style.width = '70%';
    const res  = await fetch('<?= APP_URL ?>/modules/cotizaciones/ajax_subir_firmada.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const json = await res.json();
    if (pbar) pbar.style.width = '100%';

    if (json.ok) {
      if (typeof showToast === 'function') showToast('Propuesta firmada adjuntada correctamente.', 'ok');
      // Update badge
      const badge = document.querySelector('#card-firmada .det-header span[style*="background"]');
      if (badge) {
        badge.className = 'chip chip-ok chip-sm';
        badge.textContent = 'Adjuntada v' + (json.version || 1);
      }
      // Update file info block
      if (wrap && json.nombre_archivo) {
        const ts = json.created_at || new Date().toLocaleDateString('es-PE');
        const appUrl = window.APP_URL || '';
        wrap.innerHTML = '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
          + '<div style="width:38px;height:38px;background:var(--s-ok-bg);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">'
          + '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--s-ok)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>'
          + '<div style="flex:1;min-width:0;">'
          + '<div id="firmada-nombre" style="font-size:13px;font-weight:600;color:var(--gris-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + json.nombre_archivo + '</div>'
          + '<div style="font-size:11px;color:var(--gris-texto);margin-top:2px;">Versión ' + (json.version||1) + ' · ' + ts + '</div></div>'
          + '<a href="' + (json.url || '#') + '" target="_blank" class="btn btn-outline btn-sm">'
          + '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>Ver documento</a></div>';
      }
      // Update label button text node (keep SVG, replace last text node)
      if (lbl) {
        const nodes = lbl.childNodes;
        for (let i = nodes.length - 1; i >= 0; i--) {
          if (nodes[i].nodeType === Node.TEXT_NODE) {
            nodes[i].textContent = ' Actualizar';
            break;
          }
        }
      }
    } else {
      if (typeof showToast === 'function') showToast(json.msg || 'Error al subir el archivo.', 'error');
    }
  } catch(e) {
    if (typeof showToast === 'function') showToast('Error de conexión al subir.', 'error');
  } finally {
    if (lbl)  { lbl.style.opacity = '1'; lbl.style.pointerEvents = ''; }
    setTimeout(() => { if (prog) prog.style.display = 'none'; }, 800);
    input.value = '';
  }
}
</script>

<?php
// [color, bg, svg_inner_paths, label] — SVG en vez de emoji (skill: no-emoji-icons)
$tipo_config_seg = [
    'llamada'  => ['#3B82F6','var(--s-info-bg)','<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>','Llamada'],
    'email'    => ['#8B5CF6','var(--s-purple-bg)','<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>','Email'],
    'reunion'  => ['#10B981','var(--s-ok-bg)','<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>','Reunión'],
    'whatsapp' => ['#25D366','var(--s-ok-bg)','<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>','WhatsApp'],
    'otro'     => ['#6B7280','var(--bg-surface2)','<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>','Otro'],
];
$resultado_config_seg = [
    'sin_respuesta' => ['#EF4444','Sin respuesta'],
    'interesado'    => ['#10B981','Interesado'],
    'pidio_tiempo'  => ['#F59E0B','Pidió tiempo'],
    'rechazado'     => ['#6B7280','Rechazado'],
    'aceptado'      => ['#059669','Aceptado'],
];
$puede_seguimiento = in_array($user['rol'], ['gerente_general','gerente_operaciones','ejecutivo_ventas'])
    && $cot['estado'] === 'enviada';
?>

<!-- Panel de seguimiento -->
<?php if (in_array($cot['estado'], ['enviada']) || !empty($seguimientos)): ?>
<div class="seg-panel">
  <div class="seg-panel-hdr">
    <div class="seg-panel-title">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Seguimiento comercial
      <?php if (!empty($seguimientos)): ?>
      <span class="chip chip-neutral chip-sm"><?= count($seguimientos) ?></span>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <?php if ($dias_enviada !== null): ?>
        <?php if ($dias_enviada >= 14): ?>
        <span class="dias-urgente red">
          <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= $dias_enviada ?> días sin respuesta
        </span>
        <?php elseif ($dias_enviada >= 7): ?>
        <span class="dias-urgente warn">
          ⚠ <?= $dias_enviada ?> días sin respuesta
        </span>
        <?php else: ?>
        <span class="dias-urgente info">
          <?= $dias_enviada ?> días enviada
        </span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Historial -->
  <?php if (empty($seguimientos)): ?>
  <div class="seg-empty-txt">Sin seguimientos registrados aún. Registra el primer contacto abajo.</div>
  <?php else: ?>
  <div class="seg-list">
    <?php foreach ($seguimientos as $seg):
      $stc = $tipo_config_seg[$seg['tipo']] ?? ['#6B7280','var(--bg-surface2)','<circle cx="12" cy="12" r="9"/>','Otro'];
      $src = $resultado_config_seg[$seg['resultado']] ?? ['#6B7280','—'];
    ?>
    <div class="seg-item">
      <div class="seg-icon" style="background:<?= $stc[1] ?>;color:<?= $stc[0] ?>"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $stc[2] ?></svg></div>
      <div class="seg-body">
        <div class="seg-meta">
          <span class="seg-tag" style="background:<?= $stc[1] ?>;color:<?= $stc[0] ?>"><?= $stc[3] ?></span>
          <span class="seg-tag" style="background:<?= $src[0] ?>18;color:<?= $src[0] ?>"><?= $src[1] ?></span>
          <span class="seg-fecha-txt"><?= date('d/m/Y H:i', strtotime($seg['created_at'])) ?> · <?= sanitize($seg['nombre'].' '.$seg['apellido']) ?></span>
        </div>
        <?php if ($seg['notas']): ?><div class="seg-notas-txt"><?= sanitize($seg['notas']) ?></div><?php endif; ?>
        <?php if ($seg['proxima_accion']): ?>
        <?php $prox_venc = $seg['proxima_accion'] <= date('Y-m-d'); ?>
        <div class="seg-prox" style="color:<?= $prox_venc ? 'var(--s-err)' : 'var(--s-warn)' ?>">
          📅 Próxima acción: <?= date('d/m/Y', strtotime($seg['proxima_accion'])) ?>
          <?= $prox_venc ? ' — ¡Vencida!' : '' ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Botón abrir modal -->
  <?php if ($puede_seguimiento): ?>
  <div style="padding:14px 22px;border-top:1px solid var(--gris-borde);background:var(--bg-surface2);">
    <button onclick="document.getElementById('modal-seguimiento').classList.add('open')"
      style="display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:var(--azul);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;"
      onmouseover="this.style.background='#122870'" onmouseout="this.style.background='var(--azul)'">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Registrar seguimiento
    </button>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

<script>
ggiReady(() => {
  const p = new URLSearchParams(window.location.search);
  const m = p.get('msg');
  const isNuevo   = p.get('nuevo')   === '1';
  const isEditado = p.get('editado') === '1';
  if (typeof showToast === 'function') {
    if (isNuevo) {
      const _pdfDl = '<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $id ?>&dl=1';
      <?php
        // Nombre del PDF según el formato requerido: {RUC}_{NUMERO}.pdf
        $pdf_ruc      = preg_replace('/[^0-9]/', '', $cot['ruc_empresa'] ?? '');
        $pdf_safe_num = preg_replace('/[^A-Za-z0-9\-_]/', '-', $cot['numero'] ?? '');
        $pdf_nombre   = ($pdf_ruc ? $pdf_ruc . '_' : '') . $pdf_safe_num . '.pdf';
      ?>
      const _pdfNombre = '<?= addslashes($pdf_nombre) ?>';

      // Descarga automática con 400 ms de espera para que la página esté lista
      setTimeout(() => {
        if (typeof ggiDownloadBlob === 'function') {
          ggiDownloadBlob(_pdfDl);
        } else {
          var a = document.createElement('a');
          a.href = _pdfDl; a.download = _pdfNombre; a.style.display = 'none';
          document.body.appendChild(a); a.click(); document.body.removeChild(a);
        }
      }, 400);

      showToast(
        '✅ Propuesta <?= sanitize($cot["numero"]) ?> creada y descargada como <strong>' + _pdfNombre + '</strong>',
        'ok', 8000
      );
    } else if (isEditado) {
      showToast('Cambios guardados correctamente.', 'ok');
    } else if (m) {
      showToast(decodeURIComponent(m), 'ok');
    }
  }
  if (m || isNuevo || isEditado) {
    history.replaceState({}, '', window.location.pathname + '?id=<?= $id ?>');
  }
});

var ESTADO_CONFIG = {
  borrador:  { color: '#6B7280', label: 'Borrador'  },
  enviada:   { color: '#3B82F6', label: 'Enviada'   },
  aceptada:  { color: '#10B981', label: 'Aceptada'  },
  rechazada: { color: '#EF4444', label: 'Rechazada' },
  vencida:   { color: '#F59E0B', label: 'Vencida'   },
  desistida: { color: '#6B7280', label: 'Desistida' },
};

function confirmarAceptar() {
  var m   = document.getElementById('modal-aceptar');
  var inp = document.getElementById('acep-fecha');
  var err = document.getElementById('acep-err');
  var hoy = new Date().toISOString().split('T')[0];
  if (inp) {
    inp.setAttribute('min', hoy);
    inp.value = '';
    if (inp._flatpickr) { inp._flatpickr.set('minDate', hoy); inp._flatpickr.clear(); }
  }
  if (err) err.style.display = 'none';
  if (m) m.classList.add('open');
  setTimeout(function(){ var t = (inp && inp._flatpickr) ? inp._flatpickr.altInput : inp; if (t) t.focus(); }, 80);
}
function cerrarModalAceptar() {
  var m = document.getElementById('modal-aceptar');
  if (m) m.classList.remove('open');
}
function submitAceptar() {
  var inp = document.getElementById('acep-fecha');
  var err = document.getElementById('acep-err');
  var v   = inp ? inp.value : '';
  if (!v) {
    if (err) { err.textContent = 'Selecciona la fecha en que el cliente necesita sus certificados.'; err.style.display = 'block'; }
    return;
  }
  cerrarModalAceptar();
  cambiarEstado('aceptada', v);
}

// ── Editar la fecha de certificados requeridos (propuesta ya aceptada) ──
function editarFechaCert() {
  var m = document.getElementById('modal-fecha-cert');
  var inp = document.getElementById('fcert-fecha');
  var disp = document.getElementById('fecha-cert-display');
  var cur = disp ? (disp.getAttribute('data-fecha') || '') : '';
  var hoy = new Date().toISOString().split('T')[0];
  if (inp) {
    inp.setAttribute('min', hoy);
    if (inp._flatpickr) { inp._flatpickr.set('minDate', hoy); inp._flatpickr.setDate(cur || null, false); }
    else inp.value = cur;
  }
  var err = document.getElementById('fcert-err'); if (err) err.style.display = 'none';
  if (m) m.classList.add('open');
}
function cerrarModalFechaCert() { var m = document.getElementById('modal-fecha-cert'); if (m) m.classList.remove('open'); }
async function submitFechaCert() {
  var inp = document.getElementById('fcert-fecha');
  var err = document.getElementById('fcert-err');
  var v = inp ? inp.value : '';
  if (!v) { if (err) { err.textContent = 'Selecciona una fecha.'; err.style.display = 'block'; } return; }
  var btn = document.getElementById('fcert-save'); if (btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }
  try {
    var tok = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var body = new URLSearchParams({ cot_id: '<?= $id ?>', fecha_requerida_certificados: v, csrf_token: tok });
    var r = await fetch('<?= APP_URL ?>/modules/cotizaciones/ajax_fecha_cert.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': tok }, body
    });
    var data = await r.json();
    if (data.ok) {
      var disp = document.getElementById('fecha-cert-display');
      if (disp) { disp.setAttribute('data-fecha', data.fecha); var d = data.fecha.split('-'); disp.textContent = d[2] + '/' + d[1] + '/' + d[0]; }
      cerrarModalFechaCert();
      if (typeof showToast === 'function') showToast('Fecha actualizada.', 'ok');
    } else if (err) { err.textContent = data.msg || 'Error al guardar.'; err.style.display = 'block'; }
  } catch (e) { if (err) { err.textContent = 'Error de conexión.'; err.style.display = 'block'; } }
  if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; }
}

// ── Desistimiento (propuesta aceptada que el cliente abandonó) ──
async function confirmarDesistir() {
  var m    = document.getElementById('modal-desistir');
  var list = document.getElementById('des-acciones');
  var inp  = document.getElementById('des-motivo');
  var err  = document.getElementById('des-err');
  if (err) err.style.display = 'none';
  if (inp) inp.value = '';
  if (list) list.innerHTML = '<li style="color:var(--gris-texto);">Cargando…</li>';
  if (m) m.classList.add('open');
  try {
    var tok = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var r = await fetch('<?= APP_URL ?>/modules/cotizaciones/ajax_desistir.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': tok },
      body: new URLSearchParams({ cot_id: '<?= $id ?>', preview: '1', csrf_token: tok })
    });
    var data = await r.json();
    if (list) {
      list.innerHTML = '';
      var acciones = (data.ok && data.acciones && data.acciones.length)
        ? data.acciones
        : ['La propuesta pasará a estado Desistida'];
      acciones.forEach(function (a) {
        var li = document.createElement('li');
        li.textContent = a;
        list.appendChild(li);
      });
    }
  } catch (e) {
    if (list) list.innerHTML = '<li style="color:var(--s-err);">No se pudo cargar el detalle.</li>';
  }
  setTimeout(function(){ if (inp) inp.focus(); }, 80);
}
function cerrarModalDesistir() {
  var m = document.getElementById('modal-desistir');
  if (m) m.classList.remove('open');
}
async function submitDesistir() {
  var inp = document.getElementById('des-motivo');
  var err = document.getElementById('des-err');
  var motivo = inp ? inp.value.trim() : '';
  if (motivo.length < 5) {
    if (err) { err.textContent = 'Indica el motivo del desistimiento (mínimo 5 caracteres).'; err.style.display = 'block'; }
    return;
  }
  var btn = document.getElementById('des-save');
  if (btn) { btn.disabled = true; btn.textContent = 'Procesando…'; }
  try {
    var tok = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var r = await fetch('<?= APP_URL ?>/modules/cotizaciones/ajax_desistir.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': tok },
      body: new URLSearchParams({ cot_id: '<?= $id ?>', motivo: motivo, csrf_token: tok })
    });
    var data = await r.json();
    if (data.ok) {
      var msg = 'Propuesta marcada como desistida.';
      if (data.certs_anulados)     msg += ' ' + data.certs_anulados + ' certificado(s) anulado(s).';
      if (data.pagos_anulados)     msg += ' ' + data.pagos_anulados + ' pago(s) pendiente(s) anulado(s).';
      if (data.cliente_inactivado) msg += ' La empresa pasó a Inactivo.';
      location.href = '<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $id ?>&m=' + encodeURIComponent(msg);
      return;
    }
    if (err) { err.textContent = data.msg || 'Error al procesar.'; err.style.display = 'block'; }
  } catch (e) {
    if (err) { err.textContent = 'Error de conexión.'; err.style.display = 'block'; }
  }
  if (btn) { btn.disabled = false; btn.textContent = 'Confirmar desistimiento'; }
}

function confirmarRechazar() {
  GGIConfirm.show({
    title: '¿Rechazar esta propuesta?',
    message: 'Esta acción no se puede deshacer.',
    icon: 'warning',
    confirmColor: '#EF4444',
    onConfirm: () => cambiarEstado('rechazada')
  });
}

async function cambiarEstado(nuevoEstado, fechaRequerida) {
  const btn = nuevoEstado === 'aceptada'
    ? document.getElementById('btn-aceptar')
    : document.getElementById('btn-rechazar');
  const btnOriginalText = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; btn.textContent = 'Procesando…'; }

  // Feedback visual inmediato
  const ecPrev = (() => {
    const b = document.getElementById('badge-estado');
    return b ? { text: b.textContent, bg: b.style.background, color: b.style.color, border: b.style.borderColor } : null;
  })();
  const ecNuevo = ESTADO_CONFIG[nuevoEstado] || { color: '#6B7280', label: nuevoEstado };
  const badge = document.getElementById('badge-estado');
  if (badge) {
    badge.textContent = ecNuevo.label;
    badge.style.background  = ecNuevo.color + '22';
    badge.style.color       = ecNuevo.color;
    badge.style.borderColor = ecNuevo.color + '55';
  }

  try {
    const body = new URLSearchParams({
      cot_id: '<?= $id ?>',
      estado:  nuevoEstado,
      csrf_token: document.querySelector('meta[name="csrf-token"]')?.content || ''
    });
    // Fecha en que el cliente necesita sus certificados (obligatoria al aceptar)
    if (nuevoEstado === 'aceptada' && fechaRequerida) body.set('fecha_requerida_certificados', fechaRequerida);
    const r    = await fetch('<?= APP_URL ?>/modules/cotizaciones/ajax_estado_cot.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
      body
    });
    const data = await r.json();

    if (!data.ok) {
      // Revertir badge si hubo error
      if (ecPrev && badge) {
        badge.textContent = ecPrev.text;
        badge.style.background  = ecPrev.bg;
        badge.style.color       = ecPrev.color;
        badge.style.borderColor = ecPrev.border;
      }
      if (typeof showToast === 'function') showToast(data.msg || 'Error al cambiar estado.', 'error');
      return;
    }

    // ── Ocultar botones Aceptar / Rechazar / Editar ──
    ['btn-aceptar','btn-rechazar','btn-editar'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });

    // ── Si aceptada → actualizar chip empresa ──
    if (nuevoEstado === 'aceptada') {
      // Cambiar chip "Prospecto" → "Empresa registrada"
      const chip = document.getElementById('chip-tipo-empresa');
      if (chip) {
        chip.textContent = 'Empresa registrada';
        chip.style.background   = 'var(--s-ok-bg)';
        chip.style.color        = '#229133';
        chip.style.borderColor  = '#2DB14055';
      }
      const msg = data.certs_creados > 0
        ? `Propuesta aceptada. ${data.certs_creados} certificado(s) creado(s) en proceso.`
        : 'Propuesta aceptada y cliente activado.';
      if (typeof showToast === 'function') showToast(msg, 'ok');
    } else {
      if (typeof showToast === 'function') showToast('Estado actualizado a ' + ecNuevo.label + '.', 'ok');
    }

  } catch(e) {
    if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.textContent = btnOriginalText; }
  }
}
</script>

<!-- Modal: Aceptar propuesta (captura la fecha en que el cliente necesita sus certificados) -->
<div class="ggi-modal-overlay" id="modal-aceptar" onclick="if(event.target===this)cerrarModalAceptar()">
  <div class="ggi-modal-box" style="max-width:430px;">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#059669" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>
        Aceptar propuesta
      </h3>
      <button type="button" class="ggi-modal-close" onclick="cerrarModalAceptar()">✕</button>
    </div>
    <div class="ggi-modal-body">
      <p style="font-size:13px;color:var(--gris-texto);margin:0 0 16px;line-height:1.5;">Se crearán los certificados en proceso automáticamente. Esta acción no se puede deshacer.</p>
      <div class="modal-field">
        <label>¿Para cuándo necesita el cliente sus certificados? <span class="req" style="color:var(--s-err,#dc2626);">*</span></label>
        <input type="date" id="acep-fecha" name="fecha_requerida_certificados">
        <div style="font-size:11px;color:var(--gris-texto);margin-top:5px;">Se avisará al Gerente General por notificación 2 días antes de esta fecha.</div>
        <div id="acep-err" style="display:none;color:var(--s-err,#dc2626);font-size:12px;margin-top:6px;font-weight:600;"></div>
      </div>
    </div>
    <div class="ggi-modal-footer">
      <button type="button" class="ggi-btn-confirm-cancel" onclick="cerrarModalAceptar()">Cancelar</button>
      <button type="button" class="ggi-btn-confirm-action" style="background:#059669;border-color:#059669;" onclick="submitAceptar()">Aceptar propuesta</button>
    </div>
  </div>
</div>

<!-- Modal: Marcar propuesta como desistida (empresa aceptó y luego no respondió) -->
<?php if (has_role('gerente_general','gerente_operaciones')): ?>
<div class="ggi-modal-overlay" id="modal-desistir" onclick="if(event.target===this)cerrarModalDesistir()">
  <div class="ggi-modal-box" style="max-width:460px;">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="var(--s-err)" stroke-width="2.2"><path d="M4 4v6h6"/><path d="M20 20a8 8 0 0 0-14.5-6.4L4 10"/></svg>
        Marcar propuesta como desistida
      </h3>
      <button type="button" class="ggi-modal-close" onclick="cerrarModalDesistir()">✕</button>
    </div>
    <div class="ggi-modal-body">
      <p style="font-size:13px;color:var(--gris-texto);margin:0 0 12px;line-height:1.5;">
        Usa esta acción cuando la empresa aceptó la propuesta y luego desistió
        (dejó de responder). Se revertirá lo generado por la aceptación:
      </p>
      <ul id="des-acciones" style="margin:0 0 16px;padding-left:18px;font-size:12.5px;color:var(--gris-dark);line-height:1.7;"></ul>
      <div class="modal-field">
        <label>Motivo del desistimiento <span class="req" style="color:var(--s-err,#dc2626);">*</span></label>
        <textarea id="des-motivo" rows="3" placeholder="Ej.: Aceptó la propuesta el 15/06 y dejó de responder llamadas y mensajes."></textarea>
        <div id="des-err" style="display:none;color:var(--s-err,#dc2626);font-size:12px;margin-top:6px;font-weight:600;"></div>
      </div>
    </div>
    <div class="ggi-modal-footer">
      <button type="button" class="ggi-btn-confirm-cancel" onclick="cerrarModalDesistir()">Cancelar</button>
      <button type="button" class="ggi-btn-confirm-action" id="des-save" style="background:var(--s-err,#dc2626);border-color:var(--s-err,#dc2626);" onclick="submitDesistir()">Confirmar desistimiento</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal: Editar fecha de certificados requeridos -->
<div class="ggi-modal-overlay" id="modal-fecha-cert" onclick="if(event.target===this)cerrarModalFechaCert()">
  <div class="ggi-modal-box" style="max-width:420px;">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Fecha de certificados requeridos
      </h3>
      <button type="button" class="ggi-modal-close" onclick="cerrarModalFechaCert()">✕</button>
    </div>
    <div class="ggi-modal-body">
      <div class="modal-field">
        <label>¿Para cuándo necesita el cliente sus certificados?</label>
        <input type="date" id="fcert-fecha" name="fecha_requerida_certificados">
        <div style="font-size:11px;color:var(--gris-texto);margin-top:5px;">Al cambiarla, la alerta al Gerente General se reprogramará (2 días antes de la nueva fecha).</div>
        <div id="fcert-err" style="display:none;color:var(--s-err,#dc2626);font-size:12px;margin-top:6px;font-weight:600;"></div>
      </div>
    </div>
    <div class="ggi-modal-footer">
      <button type="button" class="ggi-btn-confirm-cancel" onclick="cerrarModalFechaCert()">Cancelar</button>
      <button type="button" class="ggi-btn-confirm-action" id="fcert-save" onclick="submitFechaCert()">Guardar</button>
    </div>
  </div>
</div>

<!-- Modal: Registrar seguimiento -->
<?php if ($puede_seguimiento): ?>
<div class="ggi-modal-overlay" id="modal-seguimiento" onclick="if(event.target===this)cerrarModal()">
  <div class="ggi-modal-box">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Registrar seguimiento
      </h3>
      <button type="button" class="ggi-modal-close" onclick="cerrarModal()">✕</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="guardar_seguimiento">
      <div class="ggi-modal-body">
        <div class="modal-grid">
          <div class="modal-field">
            <label>Tipo de contacto</label>
            <select name="tipo_seg">
              <option value="llamada">Llamada</option>
              <option value="email">Email</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="reunion">Reunión</option>
              <option value="otro">Otro</option>
            </select>
          </div>
          <div class="modal-field">
            <label>Resultado</label>
            <select name="resultado_seg">
              <option value="sin_respuesta">Sin respuesta</option>
              <option value="interesado">Interesado</option>
              <option value="pidio_tiempo">Pidió más tiempo</option>
              <option value="rechazado">Rechazado</option>
              <option value="aceptado">Aceptado</option>
            </select>
          </div>
        </div>
        <div class="modal-field" style="margin-bottom:12px;">
          <label>Notas del seguimiento</label>
          <textarea name="notas_seg" placeholder="¿Qué ocurrió en este contacto?" required></textarea>
        </div>
        <div class="modal-field">
          <label>Próxima acción (opcional)</label>
          <input type="date" name="proxima_accion_seg" min="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="ggi-modal-footer">
        <button type="button" class="ggi-btn-confirm-cancel" onclick="cerrarModal()">Cancelar</button>
        <button type="submit" class="ggi-btn-confirm-action">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
          Guardar seguimiento
        </button>
      </div>
    </form>
  </div>
</div>
<script>
function cerrarModal() {
  document.getElementById('modal-seguimiento').classList.remove('open');
}
// Escape gestionado por ShortcutManager (MutationObserver detecta .modal-overlay.open)
// Fallback por si ShortcutManager no está disponible
if (!window.ShortcutManager) {
  document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
}
</script>
<?php endif; ?>



<script>
(function () {
  'use strict';

  /* ── Nota interna — inline editor ── */
  function notaIniciarEdicion() {
    document.getElementById('nota-display').style.display  = 'none';
    document.getElementById('nota-editor').style.display   = 'block';
    document.getElementById('btn-editar-nota').style.display = 'none';
    var ta = document.getElementById('nota-textarea');
    if (ta) { ta.focus(); ta.selectionStart = ta.selectionEnd = ta.value.length; }
  }

  function notaCancelar() {
    document.getElementById('nota-display').style.display  = '';
    document.getElementById('nota-editor').style.display   = 'none';
    document.getElementById('btn-editar-nota').style.display = '';
  }

  async function notaGuardar() {
    var ta      = document.getElementById('nota-textarea');
    var btn     = document.getElementById('btn-nota-guardar');
    var saving  = document.getElementById('nota-saving');
    var display = document.getElementById('nota-display');
    var texto   = document.getElementById('nota-texto');

    btn.disabled        = true;
    saving.style.display = 'inline';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var fd = new FormData();
    fd.append('accion', 'guardar_nota');
    fd.append('notas',  ta.value);
    fd.append('_csrf',  csrfToken);

    try {
      var r = await fetch(location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      });
      var d = await r.json();

      if (d.ok) {
        /* Refrescar el CSRF token en el meta tag para futuras peticiones */
        if (d.csrf && csrfMeta) csrfMeta.setAttribute('content', d.csrf);

        /* Actualizar el texto en vista de lectura */
        if (d.notas) {
          /* Convertir saltos de línea a <br> como nl2br */
          var escaped = d.notas
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;')
            .replace(/\n/g, '<br>');
          texto.innerHTML = escaped;
          texto.style.fontStyle = '';
          texto.style.color     = 'var(--gris-dark)';
          texto.style.fontSize  = '13px';
        } else {
          texto.textContent     = 'Sin nota. Haz clic en "Editar" para agregar una.';
          texto.style.fontStyle = 'italic';
          texto.style.color     = 'var(--gris-texto)';
          texto.style.fontSize  = '12px';
          texto.innerHTML       = texto.textContent; /* plain text, no <br> */
        }

        /* También actualizar el textarea para futuras ediciones */
        ta.value = d.notas;

        notaCancelar();

        /* Toast de éxito rápido */
        var toast = document.createElement('div');
        toast.textContent = '✓ Nota guardada';
        toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--s-ok);color:#fff;padding:10px 18px;border-radius:9px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.15);transition:opacity .4s;';
        document.body.appendChild(toast);
        setTimeout(function () { toast.style.opacity = '0'; }, 1800);
        setTimeout(function () { toast.remove(); }, 2250);
      } else {
        alert('No se pudo guardar la nota. Intenta de nuevo.');
      }
    } catch (e) {
      alert('Error de conexión al guardar la nota.');
    } finally {
      btn.disabled         = false;
      saving.style.display = 'none';
    }
  }

  /* Exponer funciones al scope global (llamadas desde onclick="...") */
  window.notaIniciarEdicion = notaIniciarEdicion;
  window.notaGuardar        = notaGuardar;
  window.notaCancelar       = notaCancelar;
})();
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
