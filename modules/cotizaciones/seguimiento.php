<?php
$page_title  = 'Seguimiento de propuestas';
$active_menu = 'propuestas';
require_once __DIR__ . '/../../templates/layout.php';
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas');

$pdo  = Database::get();
$uid  = $user['id'];
$rol  = $user['rol'];

// Filtros
$filtro = $_GET['filtro'] ?? 'urgente'; // urgente | todas | con_proxima
$buscar = trim($_GET['q'] ?? '');

// ── Query base: propuestas enviadas sin respuesta ──
$where  = ["cot.estado = 'enviada'"];
$params = [];

if ($rol === 'ejecutivo_ventas') {
    $where[]  = 'cot.ejecutivo_id = ?';
    $params[] = $uid;
}
if ($buscar) {
    $where[]  = '(cot.empresa_nombre LIKE ? OR cot.numero LIKE ? OR cl.razon_social LIKE ?)';
    $params   = array_merge($params, ["%$buscar%", "%$buscar%", "%$buscar%"]);
}

$dias_cond = match($filtro) {
    'urgente'     => 'AND DATEDIFF(CURDATE(), cot.fecha_emision) >= 7',
    'con_proxima' => 'AND seg_next.proxima_accion IS NOT NULL AND seg_next.proxima_accion <= CURDATE()',
    default       => '', // todas
};

$sql = "
    SELECT
        cot.id, cot.numero, cot.empresa_nombre, cot.empresa_ruc,
        cot.contacto_nombre, cot.contacto_telefono, cot.contacto_email,
        cot.monto_total, cot.moneda, cot.fecha_emision, cot.fecha_vencimiento,
        cot.cliente_id, cot.ejecutivo_id,
        COALESCE(cl.razon_social, cot.empresa_nombre) AS nombre_empresa,
        DATEDIFF(CURDATE(), cot.fecha_emision) AS dias_enviada,
        ej.nombre AS ej_nombre, ej.apellido AS ej_apellido,
        -- Último seguimiento
        seg_last.tipo AS ult_tipo,
        seg_last.resultado AS ult_resultado,
        seg_last.created_at AS ult_fecha,
        seg_last.notas AS ult_notas,
        -- Próxima acción pendiente
        seg_next.proxima_accion,
        seg_next.tipo AS prox_tipo,
        -- Conteo de seguimientos
        COALESCE(seg_count.total, 0) AS total_seguimientos
    FROM cotizaciones cot
    LEFT JOIN clientes cl ON cl.id = cot.cliente_id
    LEFT JOIN usuarios ej ON ej.id = cot.ejecutivo_id
    -- Último seguimiento registrado
    LEFT JOIN cotizacion_seguimientos seg_last
        ON seg_last.id = (
            SELECT id FROM cotizacion_seguimientos
            WHERE cotizacion_id = cot.id
            ORDER BY created_at DESC LIMIT 1
        )
    -- Próxima acción más próxima aún pendiente
    LEFT JOIN cotizacion_seguimientos seg_next
        ON seg_next.id = (
            SELECT id FROM cotizacion_seguimientos
            WHERE cotizacion_id = cot.id AND proxima_accion IS NOT NULL
            ORDER BY proxima_accion ASC LIMIT 1
        )
    -- Conteo total de seguimientos
    LEFT JOIN (
        SELECT cotizacion_id, COUNT(*) AS total
        FROM cotizacion_seguimientos GROUP BY cotizacion_id
    ) seg_count ON seg_count.cotizacion_id = cot.id
    WHERE " . implode(' AND ', $where) . "
    $dias_cond
    ORDER BY dias_enviada DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cotizaciones = $stmt->fetchAll();

// KPIs rápidos para el encabezado
$kpi_total    = count($cotizaciones);
$kpi_urgente  = count(array_filter($cotizaciones, fn($c) => $c['dias_enviada'] >= 7));
$kpi_sin_seg  = count(array_filter($cotizaciones, fn($c) => $c['total_seguimientos'] == 0));
$kpi_proximas = count(array_filter($cotizaciones, fn($c) => $c['proxima_accion'] && $c['proxima_accion'] <= date('Y-m-d')));

// Colores por tipo de seguimiento
$tipo_config = [
    'llamada'  => ['#3B82F6','var(--s-info-bg)','📞','Llamada'],
    'email'    => ['#8B5CF6','var(--s-purple-bg)','✉️','Email'],
    'reunion'  => ['#10B981','var(--s-ok-bg)','🤝','Reunión'],
    'whatsapp' => ['#25D366','var(--s-ok-bg)','💬','WhatsApp'],
    'otro'     => ['#6B7280','var(--bg-surface2)','📌','Otro'],
];
$resultado_config = [
    'sin_respuesta' => ['#EF4444','Sin respuesta'],
    'interesado'    => ['#10B981','Interesado ✓'],
    'pidio_tiempo'  => ['#F59E0B','Pidió tiempo'],
    'rechazado'     => ['#6B7280','Rechazado'],
    'aceptado'      => ['#059669','Aceptado ✓'],
];
?>
<style>
  /* ── KPI strip ── */
  .kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:22px; }
  .kpi { background:var(--bg-surface); border:1px solid var(--gris-borde); border-radius:12px; padding:14px 18px; position:relative; overflow:hidden; }
  .kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--kc); }
  .kpi-val { font-family:'Inter',sans-serif; font-size:28px; font-weight:700; color:var(--gris-dark); line-height:1; }
  .kpi-lbl { font-size:11px; color:var(--gris-texto); margin-top:4px; font-weight:500; }
  .kpi-alert { font-size:11px; font-weight:600; color:var(--kc); margin-top:3px; }

  /* ── Toolbar ── */
  .toolbar { display:flex; align-items:center; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; min-width:200px; max-width:320px; }
  .search-wrap svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:8px 12px 8px 36px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; }
  .search-input:focus { border-color:var(--azul); }
  .tab-group { display:flex; gap:4px; background:var(--bg-surface2); padding:3px; border-radius:10px; }
  .tab-btn { padding:6px 14px; border-radius:7px; font-size:12px; font-weight:600; border:none; cursor:pointer; font-family:'Inter',sans-serif; background:transparent; color:var(--gris-texto); transition:.15s; }
  .tab-btn.active { background:var(--bg-surface); color:var(--azul); box-shadow:0 1px 4px rgba(0,0,0,.1); }

  /* ── Propuesta card ── */
  .cot-card { background:var(--bg-surface); border:1px solid var(--gris-borde); border-radius:14px; margin-bottom:12px; overflow:hidden; transition:box-shadow .15s; }
  .cot-card:hover { box-shadow:0 4px 20px rgba(26,58,143,.08); }
  .cot-card.urgente { border-left:4px solid #EF4444; }
  .cot-card.moderado { border-left:4px solid #F59E0B; }
  .cot-card.ok { border-left:4px solid #10B981; }

  .cot-header { padding:14px 18px; display:flex; align-items:flex-start; justify-content:space-between; gap:12px; cursor:pointer; }
  .cot-header:hover { background:var(--bg-surface2); }
  .cot-empresa { font-weight:700; font-size:14px; color:var(--azul); line-height:1.2; }
  .cot-meta { font-size:11px; color:var(--gris-texto); margin-top:3px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .cot-num { font-family:monospace; font-size:11px; background:var(--s-info-bg); padding:1px 7px; border-radius:5px; color:#475569; border:1px solid #e2e8f0; font-weight:700; }
  .cot-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }

  /* Días badge */
  .dias-badge { text-align:center; min-width:52px; }
  .dias-num { font-family:'Inter',sans-serif; font-size:22px; font-weight:700; line-height:1; }
  .dias-lbl { font-size:9px; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.3px; }

  /* Tags */
  .tag { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; }

  /* ── Detalle expandible ── */
  .cot-detail { display:none; border-top:1px solid var(--bg-surface2); }
  .cot-detail.open { display:block; }

  .cot-info-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; padding:14px 18px; background:var(--bg-surface2); border-bottom:1px solid var(--bg-surface2); }
  .info-field {}
  .info-lbl { font-size:9px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.4px; margin-bottom:3px; }
  .info-val { font-size:13px; font-weight:500; color:var(--gris-dark); }
  .info-val a { color:var(--azul); text-decoration:none; }
  .info-val a:hover { text-decoration:underline; }

  /* Historial de seguimientos */
  .seg-hist { padding:14px 18px; border-bottom:1px solid var(--bg-surface2); }
  .seg-hist-title { font-size:11px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; }
  .seg-item { display:flex; gap:10px; padding:8px 0; border-bottom:1px dashed var(--bg-surface2); }
  .seg-item:last-child { border-bottom:none; }
  .seg-icon { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
  .seg-body { flex:1; min-width:0; }
  .seg-top { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:2px; }
  .seg-fecha { font-size:11px; color:var(--gris-texto); }
  .seg-notas { font-size:12px; color:var(--gris-dark); margin-top:2px; line-height:1.4; }
  .seg-empty { font-size:12px; color:var(--gris-texto); font-style:italic; padding:4px 0; }

  /* Formulario de nuevo seguimiento */
  .seg-form { padding:16px 18px; background:var(--s-info-bg); }
  .seg-form-title { font-size:12px; font-weight:700; color:var(--azul); margin-bottom:12px; display:flex; align-items:center; gap:6px; }
  .form-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
  .form-row.full { grid-template-columns:1fr; }
  .form-field label { display:block; font-size:11px; font-weight:600; color:var(--gris-texto); margin-bottom:4px; text-transform:uppercase; letter-spacing:.3px; }
  .form-field select, .form-field input, .form-field textarea {
    width:100%; padding:8px 10px; border:1.5px solid var(--gris-borde); border-radius:8px;
    font-size:12px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface);
    transition:border-color .15s;
  }
  .form-field select:focus, .form-field input:focus, .form-field textarea:focus { border-color:var(--azul); }
  .form-field textarea { resize:vertical; min-height:64px; }
  .btn-guardar { display:inline-flex; align-items:center; gap:6px; padding:9px 20px; background:var(--azul); color:#fff; border:none; border-radius:9px; font-size:13px; font-weight:600; cursor:pointer; font-family:'Inter',sans-serif; transition:.15s; }
  .btn-guardar:hover { background:#122870; }
  .btn-guardar:disabled { opacity:.5; cursor:not-allowed; }

  /* .empty-state → canónico en design-system.css */

  @media(max-width:768px) {
    .kpi-row { grid-template-columns:repeat(2,1fr); }
    .form-row { grid-template-columns:1fr; }
    .cot-right { flex-wrap:wrap; }
  }
</style>

<?php ggi_breadcrumb([
    ['label' => 'Propuestas',  'href' => APP_URL.'/modules/cotizaciones/index.php', 'ss' => 'ggi_ss_cotizaciones'],
    ['label' => 'Seguimiento'],
]); ?>
<div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Seguimiento de propuestas</h1>
    <p>Propuestas enviadas pendientes de respuesta</p>
  </div>
</div>

<!-- KPIs -->
<div class="kpi-row">
  <div class="kpi" style="--kc:#EF4444;">
    <div class="kpi-val"><?= $kpi_urgente ?></div>
    <div class="kpi-lbl">Con +7 días sin respuesta</div>
    <?php if ($kpi_urgente): ?><div class="kpi-alert">⚠ Requieren acción</div><?php endif; ?>
  </div>
  <div class="kpi" style="--kc:#F59E0B;">
    <div class="kpi-val"><?= $kpi_sin_seg ?></div>
    <div class="kpi-lbl">Sin seguimiento registrado</div>
    <?php if ($kpi_sin_seg): ?><div class="kpi-alert">Nunca contactadas</div><?php endif; ?>
  </div>
  <div class="kpi" style="--kc:#3B82F6;">
    <div class="kpi-val"><?= $kpi_total ?></div>
    <div class="kpi-lbl">Total enviadas activas</div>
  </div>
  <div class="kpi" style="--kc:#8B5CF6;">
    <div class="kpi-val"><?= $kpi_proximas ?></div>
    <div class="kpi-lbl">Acciones vencidas hoy</div>
    <?php if ($kpi_proximas): ?><div class="kpi-alert">Revisar hoy</div><?php endif; ?>
  </div>
</div>

<!-- Toolbar -->
<div class="toolbar">
  <div class="search-wrap">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="text" id="search-input" class="search-input" placeholder="Buscar empresa o N° propuesta..." aria-label="Buscar empresa o N° propuesta..." value="<?= sanitize($buscar) ?>">
  </div>
  <div class="tab-group">
    <button class="tab-btn <?= $filtro==='urgente'?'active':'' ?>" onclick="setFiltro('urgente')">🔴 Urgentes</button>
    <button class="tab-btn <?= $filtro==='todas'?'active':'' ?>" onclick="setFiltro('todas')">Todas</button>
    <button class="tab-btn <?= $filtro==='con_proxima'?'active':'' ?>" onclick="setFiltro('con_proxima')">📅 Acción hoy</button>
  </div>
</div>

<!-- Lista de propuestas -->
<?php if (empty($cotizaciones)): ?>
<div class="empty-state">
  <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 12px;display:block;opacity:.2;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
  <h3 style="font-size:15px;color:var(--gris-dark);margin-bottom:4px;">
    <?= $filtro === 'urgente' ? 'Sin propuestas urgentes — ¡buen trabajo!' : 'Sin propuestas enviadas activas' ?>
  </h3>
  <p style="font-size:13px;">
    <?= $filtro === 'urgente' ? 'Todas las propuestas llevan menos de 7 días.' : 'No hay propuestas en estado "enviada" por el momento.' ?>
  </p>
</div>
<?php else: ?>

<?php foreach ($cotizaciones as $cot):
  $dias = (int)$cot['dias_enviada'];
  $urgencia = $dias >= 14 ? 'urgente' : ($dias >= 7 ? 'moderado' : 'ok');
  $dias_color = $dias >= 14 ? '#EF4444' : ($dias >= 7 ? '#F59E0B' : '#10B981');
  $tc = $tipo_config[$cot['ult_tipo'] ?? ''] ?? null;
  $rc = $resultado_config[$cot['ult_resultado'] ?? ''] ?? null;
  $prox_vencida = $cot['proxima_accion'] && $cot['proxima_accion'] <= date('Y-m-d');

  // Cargar historial completo de seguimientos para esta propuesta
  $segs = $pdo->prepare("
      SELECT cs.*, u.nombre, u.apellido
      FROM cotizacion_seguimientos cs
      JOIN usuarios u ON u.id = cs.usuario_id
      WHERE cs.cotizacion_id = ?
      ORDER BY cs.created_at DESC
      LIMIT 8
  ");
  $segs->execute([$cot['id']]);
  $historial = $segs->fetchAll();
?>
<div class="cot-card <?= $urgencia ?>" id="card-<?= $cot['id'] ?>">

  <!-- Header clickeable -->
  <div class="cot-header" onclick="toggleCard(<?= $cot['id'] ?>)">
    <div style="flex:1;min-width:0;">
      <div class="cot-empresa"><?= sanitize($cot['nombre_empresa']) ?></div>
      <div class="cot-meta">
        <span class="cot-num"><?= sanitize($cot['numero']) ?></span>
        <?php if ($cot['contacto_nombre']): ?>
        <span><?= sanitize($cot['contacto_nombre']) ?></span>
        <?php endif; ?>
        <?php if ($cot['monto_total'] > 0): ?>
        <span style="font-weight:600;color:var(--azul);"><?= $cot['moneda'] === 'USD' ? 'USD' : 'S/' ?> <?= number_format($cot['monto_total'],0) ?></span>
        <?php endif; ?>
        <?php if ($cot['total_seguimientos'] > 0): ?>
        <span style="background:var(--s-info-bg);color:var(--azul);padding:1px 7px;border-radius:100px;font-weight:700;"><?= $cot['total_seguimientos'] ?> seguimiento<?= $cot['total_seguimientos']>1?'s':'' ?></span>
        <?php else: ?>
        <span style="background:var(--s-err-bg);color:var(--s-err-tx);padding:1px 7px;border-radius:100px;font-weight:700;">Sin seguimiento</span>
        <?php endif; ?>
        <?php if ($prox_vencida): ?>
        <span style="background:var(--s-warn-bg);color:var(--s-warn-tx);padding:1px 7px;border-radius:100px;font-weight:700;">📅 Acción pendiente hoy</span>
        <?php endif; ?>
      </div>
      <?php if ($cot['ult_tipo']): ?>
      <div style="display:flex;align-items:center;gap:6px;margin-top:5px;">
        <span style="font-size:10px;color:var(--gris-texto);">Último:</span>
        <?php if ($tc): ?>
        <span class="tag" style="background:<?= $tc[1] ?>;color:<?= $tc[0] ?>;font-size:10px;padding:1px 7px;"><?= $tc[2] ?> <?= $tc[3] ?></span>
        <?php endif; ?>
        <?php if ($rc): ?>
        <span class="tag" style="background:<?= $rc[0] ?>18;color:<?= $rc[0] ?>;font-size:10px;padding:1px 7px;"><?= $rc[1] ?></span>
        <?php endif; ?>
        <span style="font-size:10px;color:var(--gris-texto);"><?= date('d/m/Y', strtotime($cot['ult_fecha'])) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <div class="cot-right">
      <!-- Próxima acción -->
      <?php if ($cot['proxima_accion']): ?>
      <div style="text-align:center;">
        <div style="font-size:9px;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px;">Próxima acción</div>
        <div style="font-size:12px;font-weight:600;color:<?= $prox_vencida ? '#EF4444' : '#F59E0B' ?>;"><?= date('d/m/Y', strtotime($cot['proxima_accion'])) ?></div>
        <?php if ($prox_vencida): ?><div style="font-size:10px;color:#EF4444;font-weight:600;">¡Vencida!</div><?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Días badge -->
      <div class="dias-badge">
        <div class="dias-num" style="color:<?= $dias_color ?>"><?= $dias ?></div>
        <div class="dias-lbl">días</div>
      </div>

      <!-- Chevron -->
      <svg id="chev-<?= $cot['id'] ?>" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="color:var(--gris-texto);transition:.2s;flex-shrink:0;"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
  </div>

  <!-- Detalle expandible -->
  <div class="cot-detail" id="detail-<?= $cot['id'] ?>">

    <!-- Info de contacto -->
    <div class="cot-info-row">
      <div class="info-field">
        <div class="info-lbl">RUC</div>
        <div class="info-val"><?= sanitize($cot['empresa_ruc'] ?? '—') ?></div>
      </div>
      <div class="info-field">
        <div class="info-lbl">Contacto</div>
        <div class="info-val"><?= sanitize($cot['contacto_nombre'] ?? '—') ?></div>
      </div>
      <div class="info-field">
        <div class="info-lbl">Teléfono</div>
        <div class="info-val">
          <?php if ($cot['contacto_telefono']): ?>
          <a href="tel:<?= sanitize($cot['contacto_telefono']) ?>"><?= sanitize($cot['contacto_telefono']) ?></a>
          <?php else: ?>—<?php endif; ?>
        </div>
      </div>
      <div class="info-field">
        <div class="info-lbl">Email</div>
        <div class="info-val">
          <?php if ($cot['contacto_email']): ?>
          <a href="mailto:<?= sanitize($cot['contacto_email']) ?>"><?= sanitize($cot['contacto_email']) ?></a>
          <?php else: ?>—<?php endif; ?>
        </div>
      </div>
      <div class="info-field">
        <div class="info-lbl">Fecha emisión</div>
        <div class="info-val"><?= date('d/m/Y', strtotime($cot['fecha_emision'])) ?></div>
      </div>
      <?php if ($cot['fecha_vencimiento']): ?>
      <div class="info-field">
        <div class="info-lbl">Vence</div>
        <div class="info-val" style="color:<?= $cot['fecha_vencimiento'] < date('Y-m-d') ? '#EF4444' : 'inherit' ?>">
          <?= date('d/m/Y', strtotime($cot['fecha_vencimiento'])) ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="info-field">
        <div class="info-lbl">Ver propuesta</div>
        <div class="info-val">
          <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $cot['id'] ?>" style="font-weight:600;">Ver detalle →</a>
        </div>
      </div>
    </div>

    <!-- Historial de seguimientos -->
    <div class="seg-hist">
      <div class="seg-hist-title">
        <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Historial de seguimiento
      </div>
      <?php if (empty($historial)): ?>
      <div class="seg-empty">Sin seguimientos registrados aún.</div>
      <?php else: ?>
      <?php foreach ($historial as $seg):
        $stc = $tipo_config[$seg['tipo']] ?? ['#6B7280','var(--bg-surface2)','📌','Otro'];
        $src = $resultado_config[$seg['resultado']] ?? ['#6B7280','—'];
      ?>
      <div class="seg-item">
        <div class="seg-icon" style="background:<?= $stc[1] ?>;color:<?= $stc[0] ?>"><?= $stc[2] ?></div>
        <div class="seg-body">
          <div class="seg-top">
            <span class="tag" style="background:<?= $stc[1] ?>;color:<?= $stc[0] ?>;font-size:10px;padding:1px 7px;"><?= $stc[3] ?></span>
            <span class="tag" style="background:<?= $src[0] ?>18;color:<?= $src[0] ?>;font-size:10px;padding:1px 7px;"><?= $src[1] ?></span>
            <span class="seg-fecha"><?= date('d/m/Y H:i', strtotime($seg['created_at'])) ?> · <?= sanitize($seg['nombre'].' '.$seg['apellido']) ?></span>
            <?php if ($seg['proxima_accion']): ?>
            <span style="font-size:10px;background:var(--s-warn-bg);color:var(--s-warn-tx);padding:1px 7px;border-radius:100px;font-weight:600;">📅 Próx. <?= date('d/m/Y', strtotime($seg['proxima_accion'])) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($seg['notas']): ?>
          <div class="seg-notas"><?= sanitize($seg['notas']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Formulario nuevo seguimiento -->
    <div class="seg-form">
      <div class="seg-form-title">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Registrar seguimiento
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>Tipo de contacto</label>
          <select id="tipo-<?= $cot['id'] ?>">
            <option value="llamada">📞 Llamada telefónica</option>
            <option value="email">✉️ Email</option>
            <option value="whatsapp">💬 WhatsApp</option>
            <option value="reunion">🤝 Reunión</option>
            <option value="otro">📌 Otro</option>
          </select>
        </div>
        <div class="form-field">
          <label>Resultado</label>
          <select id="resultado-<?= $cot['id'] ?>">
            <option value="sin_respuesta">Sin respuesta</option>
            <option value="interesado">Interesado ✓</option>
            <option value="pidio_tiempo">Pidió más tiempo</option>
            <option value="rechazado">Rechazado</option>
            <option value="aceptado">Aceptado ✓</option>
          </select>
        </div>
      </div>
      <div class="form-row full">
        <div class="form-field">
          <label>Notas del seguimiento</label>
          <textarea id="notas-<?= $cot['id'] ?>" placeholder="Describe brevemente el resultado del contacto..."></textarea>
        </div>
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>Próxima acción (opcional)</label>
          <input type="date" id="proxima-<?= $cot['id'] ?>" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-field" style="display:flex;align-items:flex-end;">
          <button class="btn-guardar" onclick="guardarSeguimiento(<?= $cot['id'] ?>)" id="btn-<?= $cot['id'] ?>">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Guardar seguimiento
          </button>
        </div>
      </div>
    </div>

  </div><!-- /cot-detail -->
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
var CSRF = window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '';

function toggleCard(id) {
  const detail = document.getElementById('detail-' + id);
  const chev   = document.getElementById('chev-' + id);
  const isOpen = detail.classList.contains('open');
  detail.classList.toggle('open', !isOpen);
  chev.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function setFiltro(f) {
  const params = new URLSearchParams(window.location.search);
  params.set('filtro', f);
  window.location.search = params.toString();
}

// Búsqueda en tiempo real (client-side sobre cards visibles)
document.getElementById('search-input').addEventListener('input', function() {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('.cot-card').forEach(card => {
    const txt = card.querySelector('.cot-empresa').textContent.toLowerCase()
              + card.querySelector('.cot-num').textContent.toLowerCase();
    card.style.display = (!q || txt.includes(q)) ? '' : 'none';
  });
  // Server search on Enter
});
document.getElementById('search-input').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    const params = new URLSearchParams(window.location.search);
    params.set('q', this.value);
    window.location.search = params.toString();
  }
});

async function guardarSeguimiento(cotId) {
  const tipo     = document.getElementById('tipo-' + cotId).value;
  const resultado= document.getElementById('resultado-' + cotId).value;
  const notas    = document.getElementById('notas-' + cotId).value.trim();
  const proxima  = document.getElementById('proxima-' + cotId).value;
  const btn      = document.getElementById('btn-' + cotId);

  if (!notas) {
    if (typeof showToast === 'function') showToast('Escribe una nota sobre el seguimiento.', 'error');
    document.getElementById('notas-' + cotId).focus();
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Guardando...';

  try {
    const body = new URLSearchParams({
      cotizacion_id: cotId, tipo, resultado, notas, proxima_accion: proxima
    });
    const r = await fetch('<?= APP_URL ?>/modules/cotizaciones/ajax_seguimiento.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': CSRF },
      body: body.toString()
    });
    const data = await r.json();
    if (data.ok) {
      if (typeof showToast === 'function') showToast('Seguimiento registrado.', 'ok');
      // Limpiar formulario y añadir entrada al historial sin recargar
      document.getElementById('notas-' + cotId).value = '';
      document.getElementById('proxima-' + cotId) && (document.getElementById('proxima-' + cotId).value = '');
      btn.disabled = false;
      btn.innerHTML = `<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Guardar seguimiento`;
      // Insertar nuevo item en lista de historial si existe
      const lista = document.getElementById('seg-list-' + cotId);
      if (lista && data.item_html) {
        lista.insertAdjacentHTML('afterbegin', data.item_html);
      } else if (lista) {
        const now = new Date();
        const ts  = now.toLocaleDateString('es-PE') + ' ' + now.toLocaleTimeString('es-PE',{hour:'2-digit',minute:'2-digit'});
        lista.insertAdjacentHTML('afterbegin',
          `<div class="seg-item" style="animation:fadeIn .3s ease">
            <div class="seg-icon" style="background:var(--s-ok-bg)"><svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
            <div class="seg-body">
              <div class="seg-meta"><span class="seg-tag" style="background:var(--s-ok-bg);color:var(--s-ok-tx)">${tipo}</span><span class="seg-fecha-txt">${ts}</span></div>
              <div class="seg-notas-txt">${notas}</div>
            </div>
          </div>`
        );
      }
    } else {
      if (typeof showToast === 'function') showToast(data.msg || 'Error al guardar.', 'error');
      btn.disabled = false;
      btn.innerHTML = `<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Guardar seguimiento`;
    }
  } catch(e) {
    if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
    btn.disabled = false;
  }
}

// Auto-abrir la primera card si solo hay urgentes
<?php if ($kpi_urgente === 1 && $filtro === 'urgente'): ?>
ggiReady(() => {
  const first = document.querySelector('.cot-card.urgente');
  if (first) {
    const id = first.id.replace('card-', '');
    toggleCard(id);
  }
});
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
