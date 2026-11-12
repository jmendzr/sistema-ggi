<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas','gerente_comercial', 'administracion_finanzas');
$pdo = Database::get();

// POST: mark as paid (gerentes only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'pagar' && in_array($user['rol'], ['gerente_general', 'gerente_operaciones', 'administracion_finanzas'])) {
        $bono_ids = array_filter(array_map('intval', explode(',', $_POST['bono_ids'] ?? '')));
        if ($bono_ids) {
            $ph = implode(',', array_fill(0, count($bono_ids), '?'));
            $pdo->prepare("UPDATE bonos_ejecutivos SET estado='pagado', fecha_pago=CURDATE(), pagado_at=NOW(), pagado_por=? WHERE id IN ($ph)")
                ->execute(array_merge([$user['id']], array_values($bono_ids)));
            log_actividad('bonos', 'pagar', 'Marcó ' . count($bono_ids) . ' bono(s) como pagados');
        }
    }
    header('Location: ' . APP_URL . '/modules/bonos/index.php?ok=1');
    exit;
}

// Filters
$filtro_ej_sel     = array_values(array_filter(array_map('intval', (array)($_GET['ejecutivo'] ?? [])), fn($v) => $v > 0));
$filtro_estado_sel = array_values(array_filter((array)($_GET['estado'] ?? []), fn($v) => in_array($v, ['pendiente','pagado'], true)));
// ── Consulta por MES en curso ────────────────────────────────────────────────
// Los bonos se consultan y pagan por el mes actual; NO se mezclan meses. Los
// meses anteriores se ven en el Informe de Pago y su historial.
$_meses_lbl = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mes_actual = date('Y-m');
// Mes seleccionado en el selector (?mes=YYYY-MM). Por defecto, el mes en curso.
// Cada mes se consulta por separado; NO se mezclan meses en una sola lista.
$mes_sel    = (isset($_GET['mes']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['mes'])) ? $_GET['mes'] : $mes_actual;
$mes_ini    = $mes_sel . '-01';
$mes_fin    = date('Y-m-t', strtotime($mes_ini));
$mes_label  = $_meses_lbl[(int)substr($mes_sel, 5, 2)] . ' ' . substr($mes_sel, 0, 4);
// Por defecto solo PENDIENTES del mes; ?estado[]=pagado para ver también los pagados.
if (!$filtro_estado_sel) $filtro_estado_sel = ['pendiente'];

$where  = ['1=1'];
$params = [];

if ($user['rol'] === 'ejecutivo_ventas') {
    // El ejecutivo ve solo SUS propios bonos. El Gerente Comercial ve todos
    // (solo lectura): no cae aquí, pasa al filtro opcional de abajo.
    $where[]  = 'be.ejecutivo_id = ?';
    $params[] = $user['id'];
} elseif ($filtro_ej_sel) {
    $ph = implode(',', array_fill(0, count($filtro_ej_sel), '?'));
    $where[]  = "be.ejecutivo_id IN ($ph)";
    $params   = array_merge($params, $filtro_ej_sel);
}
// Solo el MES seleccionado (por created_at del bono). No se mezclan meses.
// El filtro de estado se aplica después de agrupar.
$where[]  = 'DATE(be.created_at) BETWEEN ? AND ?';
$params[] = $mes_ini;
$params[] = $mes_fin;
$sql_where = implode(' AND ', $where);

// Build bonos query — guard against missing tables
$bonos = [];
try {
    $stmt = $pdo->prepare("
        SELECT be.*,
               u.nombre AS ej_nombre, u.apellido AS ej_apellido,
               n.codigo AS norma_codigo,
               cot.numero AS cot_numero,
               pu.nombre AS pagador_nombre, pu.apellido AS pagador_apellido
        FROM bonos_ejecutivos be
        JOIN usuarios u   ON u.id  = be.ejecutivo_id
        JOIN normas_iso n ON n.id  = be.norma_iso_id
        JOIN cotizaciones cot ON cot.id = be.cotizacion_id
        LEFT JOIN usuarios pu ON pu.id  = be.pagado_por
        WHERE $sql_where
        ORDER BY be.created_at DESC
    ");
    $stmt->execute($params);
    $bonos = $stmt->fetchAll();
} catch (\PDOException $e) {
    // Table may not exist yet; will show empty state
    $bonos = [];
}

// Totals
$total_pendiente = 0;
$total_pagado    = 0;
foreach ($bonos as $b) {
    if ($b['estado'] === 'pendiente') $total_pendiente += (float)$b['monto'];
    if ($b['estado'] === 'pagado')    $total_pagado    += (float)$b['monto'];
}

// Paid THIS month
$total_pagado_mes = 0;
$mes_actual = date('Y-m');
foreach ($bonos as $b) {
    if ($b['estado'] === 'pagado' && $b['fecha_pago'] && str_starts_with($b['fecha_pago'], $mes_actual)) {
        $total_pagado_mes += (float)$b['monto'];
    }
}

// Group by propuesta + ejecutivo for display
$bonos_grupos = [];
foreach ($bonos as $b) {
    $key = $b['cotizacion_id'] . '_' . $b['ejecutivo_id'];
    if (!isset($bonos_grupos[$key])) {
        $bonos_grupos[$key] = [
            'cotizacion_id'  => $b['cotizacion_id'],
            'cot_numero'     => $b['cot_numero'] ?? ('#' . $b['cotizacion_id']),
            'ejecutivo_id'   => $b['ejecutivo_id'],
            'ej_nombre'      => $b['ej_nombre'],
            'ej_apellido'    => $b['ej_apellido'],
            'normas'         => [],
            'monto_total'    => 0,
            'ids'            => [],
            'pendiente_ids'  => [],
            'estado'         => 'pagado',
            'fecha_pago'     => null,
            'created_at'     => $b['created_at'],
            'pagador_nombre' => null,
        ];
    }
    $g = &$bonos_grupos[$key];
    // Deduplicate by norma_iso_id (prevents display duplicates if DB has duplicate rows)
    if (!isset($g['normas'][$b['norma_iso_id']])) {
        $g['normas'][$b['norma_iso_id']] = $b['norma_codigo'];
        $g['monto_total'] += (float)$b['monto'];
    }
    $g['ids'][]        = (int)$b['id'];
    if ($b['estado'] === 'pendiente') {
        $g['estado'] = 'pendiente';
        $g['pendiente_ids'][] = (int)$b['id'];
    }
    if ($b['estado'] === 'pagado' && $b['fecha_pago']) {
        $g['fecha_pago']     = $b['fecha_pago'];
        $g['pagador_nombre'] = $b['pagador_nombre'];
    }
    unset($g);
}

// Filtrar grupos por estado después de agrupar (garantiza estado real del grupo)
if ($filtro_estado_sel) {
    $bonos_grupos = array_filter($bonos_grupos, fn($g) => in_array($g['estado'], $filtro_estado_sel, true));
}

// Última liquidación: cuándo se marcaron bonos como pagados por última vez.
$ultima_liq = null;
try {
    $ultima_liq = $pdo->query("
        SELECT be.fecha_pago, be.pagado_at,
               NULLIF(TRIM(CONCAT(COALESCE(pu.nombre,''),' ',COALESCE(pu.apellido,''))),'') AS pagador
        FROM bonos_ejecutivos be LEFT JOIN usuarios pu ON pu.id = be.pagado_por
        WHERE be.estado='pagado' AND be.fecha_pago IS NOT NULL
        ORDER BY COALESCE(be.pagado_at, be.fecha_pago) DESC, be.id DESC LIMIT 1
    ")->fetch();
} catch (\PDOException $e) { $ultima_liq = null; }

$es_gerente = in_array($user['rol'], ['gerente_general', 'gerente_operaciones', 'administracion_finanzas']);
// El Gerente Comercial VE todo (columnas de ejecutivo, filtro, pagado-por) pero
// NO tiene acciones (pagar/informe): esas siguen atadas a $es_gerente.
$ver_todos  = $es_gerente || $user['rol'] === 'gerente_comercial';
$ejecutivos_lista = $ver_todos
    ? $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol IN ('ejecutivo_ventas','gerente_comercial') ORDER BY nombre")->fetchAll()
    : [];

// ── Meses seleccionables ─────────────────────────────────────────────────────
// El mes en curso + meses pasados que TODAVÍA tienen bonos PENDIENTES (sin pagar).
// Respeta la visibilidad: un ejecutivo solo ve los meses donde él tiene pendientes.
try {
    $mesesArgs = [];
    $mesesSql  = "SELECT DISTINCT DATE_FORMAT(be.created_at,'%Y-%m') AS periodo
                    FROM bonos_ejecutivos be
                   WHERE be.estado = 'pendiente'";
    if ($user['rol'] === 'ejecutivo_ventas') { $mesesSql .= " AND be.ejecutivo_id = ?"; $mesesArgs[] = $user['id']; }
    $mesesSql .= " ORDER BY periodo DESC";
    $mq = $pdo->prepare($mesesSql); $mq->execute($mesesArgs);
    $meses_opciones = $mq->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (\PDOException $e) { $meses_opciones = []; }
// El mes en curso y el seleccionado siempre deben poder elegirse.
foreach ([$mes_actual, $mes_sel] as $_m) if (!in_array($_m, $meses_opciones, true)) $meses_opciones[] = $_m;
rsort($meses_opciones);

$page_title  = 'Bonos de Ventas';
$active_menu = 'bonos';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  /* KPI strip */
  .kpi-strip { display:flex; gap:14px; margin-bottom:22px; flex-wrap:wrap; }
  .kpi-card { flex:1; min-width:180px; background:var(--gris-card); border:1px solid var(--gris-borde);
              border-radius:12px; padding:16px 20px; display:flex; align-items:center; gap:14px; }
  .kpi-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .kpi-icon.amber { background:var(--s-warn-bg); color:var(--s-warn-tx); }
  .kpi-icon.green { background:var(--s-ok-bg); color:#229133; }
  .kpi-body { min-width:0; }
  .kpi-label { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:3px; }
  .kpi-value { font-family:'Inter',sans-serif; font-size:22px; font-weight:700; color:var(--gris-dark); line-height:1; }
  .kpi-sub { font-size:11px; color:var(--gris-texto); margin-top:3px; }

  /* Toolbar */
  .toolbar { display:flex; align-items:center; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
  .filter-select { padding:8px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px;
                   font-family:'Inter',sans-serif; background:var(--gris-card); color:var(--gris-dark);
                   outline:none; cursor:pointer; }
  .filter-select:focus { border-color:var(--azul); }

  /* Table */
  .table-wrap { background:var(--gris-card); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .table-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px;
                  border-bottom:1px solid var(--gris-borde); gap:12px; flex-wrap:wrap; }
  .table-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:700; color:var(--gris-dark); }
  /* .data-table base → canónico en design-system.css */
  .data-table th.check-col { width:36px; text-align:center; }
  /* Tags de normas: helper global ggi_tags() + .ggi-tag* (design-system.css) */
  .cot-num { display:inline-block; background:var(--bg-surface2); color:var(--gris-dark);
             font-size:11px; font-weight:700; padding:2px 8px; border-radius:6px; font-family:'Inter',sans-serif; }

  /* Status badges */
  .badge { display:inline-block; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:700; white-space:nowrap; }
  .badge-pendiente { background:var(--s-warn-bg); color:var(--s-warn-tx); }
  .badge-pagado    { background:var(--s-ok-bg); color:var(--s-ok-tx); }

  /* Monto */
  .monto-cell { font-family:'Inter',sans-serif; font-weight:700; font-size:14px; white-space:nowrap; }
  .monto-pendiente { color:var(--s-warn-tx); }
  .monto-pagado    { color:var(--s-ok-tx); }

  /* Checkbox */
  .row-check { width:16px; height:16px; cursor:pointer; accent-color:var(--azul); }

  /* Bulk action bar */
  .bulk-bar { display:none; align-items:center; gap:10px; padding:10px 18px;
              background:var(--s-info-bg); border-top:1px solid var(--gris-borde);
              border-bottom:1px solid var(--gris-borde); flex-wrap:wrap; }
  .bulk-bar.show { display:flex; }
  .bulk-count { font-size:13px; font-weight:600; color:var(--gris-dark); }

  /* .btn / .btn-primary / .btn-success / .btn-outline / .btn-sm → globales (layout.php / design-system.css) */

  /* Alert */
  .alert { padding:12px 16px; border-radius:9px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; margin-bottom:18px; }
  .alert-ok { background:var(--s-ok-bg); border:1px solid #b7e4c0; color:var(--s-ok-tx); }

  /* Pagador info */
  .pagador-info { font-size:11px; color:var(--gris-texto); margin-top:2px; }

  /* ── Mobile responsive ── */
  @media (max-width: 768px) {
    /* KPI strip: 1 columna en lugar de fila */
    .kpi-strip { flex-direction:column; gap:10px; margin-bottom:14px; }
    .kpi-card { min-width:unset; padding:12px 14px; gap:10px; }
    .kpi-icon { width:36px; height:36px; border-radius:8px; }
    .kpi-value { font-size:18px; }

    /* Filtros: apilados */
    .toolbar form { flex-direction:column !important; gap:8px; }
    .filter-select { width:100%; }

    /* Tabla → tarjetas */
    .data-table { min-width: unset !important; }
    .table-scroll-wrap { overflow-x:visible !important; }
    .data-table thead { display:none; }
    .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; box-sizing:border-box; }
    .data-table tr {
      background:var(--gris-card); border-radius:12px; border:1px solid var(--gris-borde);
      margin-bottom:10px; padding:12px 14px; position:relative;
    }
    .data-table tr:hover td { background:transparent; }
    .data-table td { padding:4px 0; border-bottom:none; font-size:13px; }
    /* Ocultar check-col y fecha en móvil */
    .data-table td:nth-child(1) { display:none; }     /* checkbox */
    .data-table td:nth-child(5) { display:none; }     /* fecha */
    .data-table td:nth-child(6) { display:none; }     /* pagado por */
    /* Monto y estado: en línea */
    .data-table td:nth-child(3), .data-table td:nth-child(4) { display:inline-block; width:auto; padding:3px 0; }
    .data-table td:nth-child(4) { float:right; }
    /* Propuesta: separador inferior */
    .data-table td:nth-child(2) { padding-bottom:8px; border-bottom:1px solid var(--gris-borde); margin-bottom:4px; }

    /* Presets rápidos: full width */
    .preset-btns { flex-wrap:wrap; }
    .preset-btns .btn-sm { flex:1; min-width:80px; justify-content:center; }

    /* Table-header */
    .table-header { flex-direction:column; align-items:stretch; gap:8px; }
  }

  /* Dark mode — usa los tokens del sistema (templates/layout.php) para mantener
     consistencia con los demás módulos. Antes usaba hexes hardcodeados (#252838,
     #3c4060, etc.) que no coincidían con --bg-surface / --brd globales. */
  :root[data-tema="oscuro"] .kpi-card { background:var(--bg-surface) !important; border-color:var(--brd) !important; }
  :root[data-tema="oscuro"] .kpi-icon.amber { background:var(--s-warn-bg) !important; color:var(--s-warn) !important; }
  :root[data-tema="oscuro"] .kpi-icon.green { background:var(--s-ok-bg) !important; color:var(--s-ok) !important; }
  :root[data-tema="oscuro"] .kpi-value { color:var(--tx) !important; }
  :root[data-tema="oscuro"] .filter-select { background:var(--bg-input) !important; border-color:var(--brd) !important; color:var(--tx) !important; }
  :root[data-tema="oscuro"] .table-wrap { background:var(--bg-surface) !important; border-color:var(--brd) !important; }
  :root[data-tema="oscuro"] .table-header { border-color:var(--brd) !important; }
  :root[data-tema="oscuro"] .data-table th { background:var(--bg-th) !important; border-color:var(--brd) !important; color:var(--tx2) !important; }
  :root[data-tema="oscuro"] .data-table td { border-color:var(--brd2) !important; color:var(--tx) !important; }
  :root[data-tema="oscuro"] .data-table tr:hover td { background:var(--bg-hover) !important; }
  :root[data-tema="oscuro"] .cot-num { background:var(--bg-surface2) !important; color:var(--tx) !important; }
  :root[data-tema="oscuro"] .badge-pendiente { background:var(--s-warn-bg) !important; color:var(--s-warn) !important; }
  :root[data-tema="oscuro"] .badge-pagado    { background:var(--s-ok-bg) !important; color:var(--s-ok) !important; }
  :root[data-tema="oscuro"] .monto-pendiente { color:var(--s-warn) !important; }
  :root[data-tema="oscuro"] .monto-pagado    { color:var(--s-ok) !important; }
  :root[data-tema="oscuro"] .bulk-bar { background:var(--bg-surface2) !important; border-color:var(--brd) !important; }
  :root[data-tema="oscuro"] .bulk-count { color:var(--ac-tx) !important; }
  :root[data-tema="oscuro"] .alert-ok { background:var(--s-ok-bg) !important; border-color:var(--s-ok-brd) !important; color:var(--s-ok) !important; }
</style>

<!-- Page Header -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Bonos de Ventas</h1>
    <p><?= count($bonos_grupos) ?> propuesta<?= count($bonos_grupos) !== 1 ? 'es' : '' ?> con bonos</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <?php if (in_array($user['rol'], ['gerente_general', 'gerente_operaciones', 'administracion_finanzas'])): ?>
    <a href="<?= APP_URL ?>/modules/bonos/informe_pago.php" class="btn btn-outline">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
      Informe de pago
    </a>
    <?php endif; ?>
    <?php if ($user['rol'] === 'gerente_general'): ?>
    <a href="<?= APP_URL ?>/modules/bonos/config.php" class="btn btn-outline">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M12 2v2M12 20v2M2 12h2M20 12h2M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41"/></svg>
      Configurar
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if (isset($_GET['ok'])): ?>
<script>
ggiReady(function() {
  showToast('Operación completada correctamente.', 'ok');
  history.replaceState({}, '', window.location.pathname);
});
</script>
<?php endif; ?>

<!-- KPI Strip -->
<div class="kpi-strip">
  <div class="kpi-card">
    <div class="kpi-icon amber">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="kpi-body">
      <div class="kpi-label">Pendiente de pago</div>
      <div class="kpi-value" style="color:var(--s-warn-tx);">S/ <?= number_format($total_pendiente, 2) ?></div>
      <div class="kpi-sub"><?= count(array_filter($bonos, fn($b) => $b['estado'] === 'pendiente')) ?> bono(s) por liquidar</div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon green">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div class="kpi-body">
      <div class="kpi-label">Pagado este mes</div>
      <div class="kpi-value" style="color:var(--s-ok-tx);">S/ <?= number_format($total_pagado_mes, 2) ?></div>
      <div class="kpi-sub">Total acumulado: S/ <?= number_format($total_pagado, 2) ?></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="toolbar" style="flex-direction:column;align-items:stretch;gap:10px;">
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
    <form method="GET" id="form-filtros" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <label title="Consultar bonos de otro mes (incluye meses con pendientes sin pagar)"
             style="display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;color:var(--gris-dark);background:var(--gris-bg,#f1f5f9);border:1px solid var(--gris-borde);border-radius:9px;padding:7px 11px 7px 13px;cursor:pointer;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <select name="mes" onchange="this.form.submit()" aria-label="Mes de los bonos"
                style="border:0;background:transparent;font-size:13px;font-weight:700;color:var(--gris-dark);cursor:pointer;outline:none;padding:0;margin:0;">
          <?php foreach ($meses_opciones as $_mo):
            $_lbl = $_meses_lbl[(int)substr($_mo, 5, 2)] . ' ' . substr($_mo, 0, 4);
          ?>
          <option value="<?= sanitize($_mo) ?>" <?= $_mo === $mes_sel ? 'selected' : '' ?>><?= sanitize($_lbl . ($_mo === $mes_actual ? ' (actual)' : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($ver_todos && $ejecutivos_lista): ?>
      <?php
        $_bej_opts = [];
        foreach ($ejecutivos_lista as $ej) $_bej_opts[(string)$ej['id']] = $ej['nombre'] . ' ' . $ej['apellido'];
        echo ggi_msdrop('ms-bono-ejec', 'ejecutivo', $_bej_opts, $filtro_ej_sel, ['placeholder' => 'Todos los ejecutivos', 'aria' => 'Filtrar por ejecutivo']);
      ?>
      <?php endif; ?>
      <?php
        echo ggi_msdrop('ms-bono-estado', 'estado', [
          'pendiente' => ['label' => 'Pendiente', 'cls' => 'is-warn'],
          'pagado'    => ['label' => 'Pagado',    'cls' => 'is-ok'],
        ], $filtro_estado_sel, ['placeholder' => 'Estado', 'aria' => 'Filtrar por estado']);
      ?>
      <?php if ($filtro_ej_sel || $filtro_estado_sel !== ['pendiente'] || $mes_sel !== $mes_actual): ?>
      <a href="<?= APP_URL ?>/modules/bonos/index.php" class="btn btn-outline btn-sm">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>
  <?php if ($ultima_liq && !empty($ultima_liq['fecha_pago'])):
    $_ul_ts  = !empty($ultima_liq['pagado_at']) ? $ultima_liq['pagado_at'] : $ultima_liq['fecha_pago'];
    $_ul_fmt = !empty($ultima_liq['pagado_at']) ? 'd/m/Y H:i' : 'd/m/Y';
  ?>
  <div style="font-size:12px;color:var(--gris-texto);">
    ✓ Última liquidación: <strong style="color:var(--gris-dark);"><?= sanitize(date($_ul_fmt, strtotime($_ul_ts))) ?></strong><?php if (!empty($ultima_liq['pagador'])): ?> · por <?= sanitize($ultima_liq['pagador']) ?><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Bonos Table -->
<form method="POST" id="form-pagar">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="pagar">
  <input type="hidden" name="bono_ids" id="bono_ids_input" value="">

  <div class="table-wrap">
    <div class="table-header">
      <div class="table-title">Bonos · <?= sanitize($mes_label) ?></div>
      <?php if ($es_gerente): ?>
      <button type="button" class="btn btn-success btn-sm" id="btn-pagar" style="display:none;" onclick="submitPagar()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Marcar como pagado (<span id="selected-count">0</span>)
      </button>
      <?php endif; ?>
    </div>

    <?php if ($es_gerente): ?>
    <!-- Bulk action bar -->
    <div class="bulk-bar" id="bulk-bar">
      <span class="bulk-count"><span id="bulk-count-text">0</span> seleccionado(s)</span>
      <button type="button" class="btn btn-success btn-sm" onclick="submitPagar()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Marcar como pagado
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="clearSelection()">Cancelar</button>
    </div>
    <?php endif; ?>

    <?php if ($bonos_grupos): ?>
    <div class="table-scroll-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <table class="data-table" style="min-width:500px;">
      <thead>
        <tr>
          <?php if ($es_gerente): ?>
          <th scope="col" class="check-col">
            <input type="checkbox" id="check-all" title="Seleccionar pendientes" style="cursor:pointer;accent-color:var(--azul);" onchange="toggleAll(this)">
          </th>
          <?php endif; ?>
          <?php if ($ver_todos): ?><th scope="col">Ejecutivo</th><?php endif; ?>
          <th scope="col">Propuesta</th>
          <th scope="col" style="text-align:right;">Monto</th>
          <th scope="col" style="text-align:center;">Estado</th>
          <th scope="col">Fecha</th>
          <?php if ($ver_todos): ?><th scope="col">Pagado por</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bonos_grupos as $g): ?>
        <tr data-estado="<?= $g['estado'] ?>">
          <?php if ($es_gerente): ?>
          <td style="text-align:center;">
            <?php if ($g['estado'] === 'pendiente' && $g['pendiente_ids']): ?>
            <input type="checkbox" class="row-check bono-check" data-ids="<?= implode(',', $g['pendiente_ids']) ?>" onchange="updateSelection()">
            <?php else: ?>
            <span style="color:var(--gris-texto);font-size:12px;">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <?php if ($ver_todos): ?>
          <td>
            <span style="font-weight:600;"><?= sanitize($g['ej_nombre'] . ' ' . $g['ej_apellido']) ?></span>
          </td>
          <?php endif; ?>
          <td>
            <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $g['cotizacion_id'] ?>" class="row-link" style="font-family:'Inter',sans-serif;font-weight:700;font-size:12px;">
              <?= sanitize($g['cot_numero']) ?>
            </a>
            <div style="margin-top:3px;display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
              <?= ggi_tags($g['normas'], ['max' => 3, 'noun' => 'normas de la propuesta']) ?>
            </div>
          </td>
          <td style="text-align:right;white-space:nowrap;">
            <span class="monto-cell ggi-num monto-<?= $g['estado'] ?>">
              S/ <?= number_format($g['monto_total'], 2) ?>
            </span>
          </td>
          <td style="text-align:center;">
            <span class="badge badge-<?= $g['estado'] ?>">
              <?= $g['estado'] === 'pendiente' ? 'Pendiente' : 'Pagado' ?>
            </span>
          </td>
          <td style="color:var(--gris-texto);font-size:12px;">
            <?php if ($g['estado'] === 'pagado' && $g['fecha_pago']): ?>
              <?= date('d/m/Y', strtotime($g['fecha_pago'])) ?>
            <?php else: ?>
              <?= date('d/m/Y', strtotime($g['created_at'])) ?>
            <?php endif; ?>
          </td>
          <?php if ($ver_todos): ?>
          <td>
            <?php if ($g['estado'] === 'pagado' && $g['pagador_nombre']): ?>
              <span style="font-size:12px;color:var(--gris-texto);"><?= sanitize($g['pagador_nombre']) ?></span>
            <?php else: ?>
              <span style="color:var(--gris-texto);font-size:12px;">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div><!-- /overflow-x scroll wrapper -->
    <?php else: ?>
    <div class="empty-state" style="padding:48px;text-align:center;color:var(--gris-texto);">
      <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:0.35;display:block;margin-left:auto;margin-right:auto;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <p style="font-weight:600;margin-bottom:4px;">No hay bonos registrados</p>
      <p style="font-size:12px;">Los bonos se generan automáticamente al aceptar una propuesta.</p>
    </div>
    <?php endif; ?>
  </div><!-- /table-wrap -->

</form>

<?php if ($es_gerente): ?>
<script>
function _collectIds() {
  var checks = document.querySelectorAll('.bono-check:checked');
  var ids = [];
  checks.forEach(function(c) {
    (c.dataset.ids || c.value).split(',').forEach(function(id) { if (id) ids.push(id.trim()); });
  });
  return ids;
}

function updateSelection() {
  var checks = document.querySelectorAll('.bono-check:checked');
  var count = checks.length;
  document.getElementById('selected-count').textContent = count;
  document.getElementById('bulk-count-text').textContent = count;
  var btnPagar = document.getElementById('btn-pagar');
  var bulkBar  = document.getElementById('bulk-bar');
  if (count > 0) {
    btnPagar.style.display = 'inline-flex';
    bulkBar.classList.add('show');
  } else {
    btnPagar.style.display = 'none';
    bulkBar.classList.remove('show');
  }
}

function toggleAll(master) {
  var checks = document.querySelectorAll('.bono-check');
  checks.forEach(function(c) { c.checked = master.checked; });
  updateSelection();
}

function clearSelection() {
  document.querySelectorAll('.bono-check').forEach(function(c) { c.checked = false; });
  var master = document.getElementById('check-all');
  if (master) master.checked = false;
  updateSelection();
}

async function submitPagar() {
  var ids = _collectIds();
  if (!ids.length) return;
  var cnt = document.querySelectorAll('.bono-check:checked').length;
  var ok = await ggiConfirm('Marcar como pagadas', '¿Marcar ' + cnt + ' bono(s) como pagados? Esta acción no se puede deshacer.', { type: 'warning', confirmText: 'Sí, marcar pagados' });
  if (!ok) return;
  document.getElementById('bono_ids_input').value = ids.join(',');
  document.getElementById('form-pagar').submit();
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
