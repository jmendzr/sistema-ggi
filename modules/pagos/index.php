<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas');
$page_title  = 'Pagos';
$active_menu = 'pagos';

$pdo  = Database::get();
$user = auth_user();
$es_ejecutivo = $user['rol'] === 'ejecutivo_ventas';

// ── Tracking de última visita (persiste en DB para sobrevivir cierres de sesión) ──
$_pag_sess_key = 'pag_last_visit_' . $user['id'];
if (!isset($_SESSION[$_pag_sess_key])) {
    // Sesión nueva: cargar desde DB
    $row = $pdo->prepare('SELECT ultima_visita_pagos FROM usuarios WHERE id = ?');
    $row->execute([$user['id']]);
    $dbval = $row->fetchColumn();
    $_SESSION[$_pag_sess_key] = $dbval ?: date('Y-m-d H:i:s', strtotime('-30 days'));
}
$pag_last_visit = $_SESSION[$_pag_sess_key];
$now = date('Y-m-d H:i:s');
$_SESSION[$_pag_sess_key] = $now;
// Persistir en DB
$pdo->prepare('UPDATE usuarios SET ultima_visita_pagos = ? WHERE id = ?')->execute([$now, $user['id']]);

// ── Filtros ──
$_estados_validos = ['pendiente_voucher', 'pendiente_validacion', 'validado', 'rechazado'];

// Multi-selección: acepta ?estado[]=v1&estado[]=v2 O legado ?estado=v
$_est_raw    = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$estados_sel = array_values(array_filter($_est_raw, fn($v) => in_array($v, $_estados_validos)));
$estado_fil  = count($estados_sel) === 1 ? $estados_sel[0] : ''; // compat legado

$search  = trim($_GET['q'] ?? '');
// Meses (multiselect, formato YYYY-MM)
$mes_sel = array_values(array_filter((array)($_GET['mes'] ?? []), fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v)));

$page = max(1, (int)($_GET['p'] ?? 1));
$_pp_opts = [10, 20, 30];
$_pp_req  = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$per      = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $per;

// ── Highlight pago específico desde notificación ──
$highlight_id = (int)($_GET['pago_id'] ?? 0);

// ── Query ──
$where  = ['p.deleted_at IS NULL', 'c.deleted_at IS NULL', 'cot.deleted_at IS NULL'];
$params = [];

if ($es_ejecutivo) {
    $where[] = '(cot.ejecutivo_id = ? OR c.ejecutivo_id = ? OR c.responsible_user_id = ? OR c.created_by_user_id = ?)';
    $params[] = $user['id']; $params[] = $user['id'];
    $params[] = $user['id']; $params[] = $user['id'];
}
if (!empty($estados_sel)) {
    $ph = implode(',', array_fill(0, count($estados_sel), '?'));
    $where[]  = "p.estado IN ($ph)";
    $params   = array_merge($params, $estados_sel);
}
if ($search) {
    $where[] = '(c.razon_social LIKE ? OR c.ruc LIKE ? OR cot.numero LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s]);
}
if ($mes_sel) {
    $ph = implode(',', array_fill(0, count($mes_sel), '?'));
    $where[] = "DATE_FORMAT(COALESCE(p.fecha_pago_decl, DATE(p.created_at)), '%Y-%m') IN ($ph)";
    $params = array_merge($params, $mes_sel);
}

$where_sql = implode(' AND ', $where);

$count_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM pagos_cliente p
    JOIN clientes c ON c.id = p.cliente_id
    JOIN cotizaciones cot ON cot.id = p.cotizacion_id
    WHERE $where_sql
");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$pages  = max(1, ceil($total / $per));
$offset = ($page - 1) * $per;

$stmt = $pdo->prepare("
    SELECT p.*,
           c.razon_social AS empresa, c.ruc,
           cot.numero AS cot_numero,
           u_ej.nombre AS ejecutivo_nombre, u_ej.apellido AS ejecutivo_apellido,
           u_sub.nombre AS subido_nombre,
           u_val.nombre AS validado_nombre
    FROM pagos_cliente p
    JOIN clientes c ON c.id = p.cliente_id
    JOIN cotizaciones cot ON cot.id = p.cotizacion_id
    LEFT JOIN usuarios u_ej ON u_ej.id = COALESCE(cot.ejecutivo_id, c.ejecutivo_id)
    LEFT JOIN usuarios u_sub ON u_sub.id = p.subido_por
    LEFT JOIN usuarios u_val ON u_val.id = p.validado_por
    WHERE $where_sql
    ORDER BY p.cotizacion_id DESC, p.tipo ASC
    LIMIT $per OFFSET $offset
");
$stmt->execute($params);
$pagos = $stmt->fetchAll();

// ── KPIs (incluye IGV proporcional al monto de cada pago validado) ──
$kpi_where_parts = ['1=1'];
$kpi_params = [];
if ($es_ejecutivo) {
    $kpi_where_parts[] = '(cot.ejecutivo_id = ? OR c.ejecutivo_id = ? OR c.responsible_user_id = ? OR c.created_by_user_id = ?)';
    $kpi_params[] = $user['id']; $kpi_params[] = $user['id'];
    $kpi_params[] = $user['id']; $kpi_params[] = $user['id'];
}
if ($mes_sel) {
    $ph = implode(',', array_fill(0, count($mes_sel), '?'));
    $kpi_where_parts[] = "DATE_FORMAT(COALESCE(p.fecha_pago_decl, DATE(p.created_at)), '%Y-%m') IN ($ph)";
    $kpi_params = array_merge($kpi_params, $mes_sel);
}
$kpi_where_sql = implode(' AND ', $kpi_where_parts);

$kpi_stmt = $pdo->prepare("
    SELECT
      SUM(CASE WHEN p.estado='pendiente_validacion' THEN 1 ELSE 0 END) AS pendientes,
      SUM(CASE WHEN p.estado='validado' THEN 1 ELSE 0 END)             AS validados,
      SUM(CASE WHEN p.estado='rechazado' THEN 1 ELSE 0 END)            AS rechazados,
      SUM(CASE WHEN p.estado='validado' AND p.moneda='PEN' THEN p.monto ELSE 0 END) AS total_pen,
      SUM(CASE WHEN p.estado='validado' AND p.moneda='USD' THEN p.monto ELSE 0 END) AS total_usd,
      SUM(CASE WHEN p.estado='validado' AND p.moneda='PEN'
               THEN ROUND(p.monto * 18.0 / 118.0, 2)
               ELSE 0 END) AS igv_pen,
      SUM(CASE WHEN p.estado='validado' AND p.moneda='USD'
               THEN ROUND(p.monto * 18.0 / 118.0, 2)
               ELSE 0 END) AS igv_usd
    FROM pagos_cliente p
    JOIN clientes c ON c.id = p.cliente_id
    JOIN cotizaciones cot ON cot.id = p.cotizacion_id
    WHERE $kpi_where_sql
");
$kpi_stmt->execute($kpi_params);
$kpi = $kpi_stmt->fetch();

// ── Meses disponibles para el selector ──
$_meses_where = $es_ejecutivo
    ? "WHERE (cot.ejecutivo_id = ? OR c.ejecutivo_id = ? OR c.responsible_user_id = ? OR c.created_by_user_id = ?)"
    : "";
$meses_stmt = $pdo->prepare("
    SELECT DISTINCT DATE_FORMAT(COALESCE(p.fecha_pago_decl, DATE(p.created_at)), '%Y-%m') AS mes
    FROM pagos_cliente p
    JOIN clientes c ON c.id = p.cliente_id
    JOIN cotizaciones cot ON cot.id = p.cotizacion_id
    $_meses_where
    ORDER BY mes DESC
    LIMIT 24
");
$_meses_params = $es_ejecutivo ? [$user['id'], $user['id'], $user['id'], $user['id']] : [];
$meses_stmt->execute($_meses_params);
$meses_disponibles = $meses_stmt->fetchAll(PDO::FETCH_COLUMN);

$meses_nombres = [
    '01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril',
    '05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto',
    '09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'
];
function _label_mes(string $ym, array $nombres): string {
    [$y, $m] = explode('-', $ym);
    return ($nombres[$m] ?? $m) . ' ' . $y;
}

// Pre-computar ANTES de layout.php que llama session_write_close()
$_es_gerente = has_role('gerente_general', 'gerente_operaciones');

require_once __DIR__ . '/../../templates/layout.php';
?>

<style>
  /* .kpi-grid/.kpi-card/.kpi-label/.kpi-value(+modificadores)/.kpi-sub → canónicos en design-system.css.
     Aquí solo se conserva el override móvil (3 columnas por tener 5 KPIs), más abajo. */

  .toolbar { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; align-items:center; }
  .filter-select { padding:8px 12px; border:1.5px solid var(--gris-borde); border-radius:8px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--gris-card,#fff); color:var(--gris-dark); cursor:pointer; }
  .search-inp { padding:8px 12px; border:1.5px solid var(--gris-borde); border-radius:8px; font-size:13px; font-family:'Inter',sans-serif; outline:none; flex:1; min-width:200px; background:var(--gris-card,#fff); color:var(--gris-dark); }
  .search-inp:focus, .filter-select:focus { border-color:var(--azul); }

  .pagos-table { width:100%; border-collapse:collapse; font-size:13px; }
  .pagos-table th { background:var(--gris-bg,#f8f9fd); font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.4px; padding:10px 14px; text-align:left; border-bottom:1px solid var(--gris-borde); }
  .pagos-table td { padding:13px 14px; border-bottom:1px solid var(--gris-borde); color:var(--gris-dark); vertical-align:middle; }
  .pagos-table tr:last-child td { border-bottom:none; }
  .pagos-table tr:hover td { background:var(--s-info-bg); }
  .pagos-table tr.highlight-row td { background:var(--s-warn-bg); }

  .badge-estado { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; }
  .be-pendiente_voucher    { background:var(--s-warn-bg); color:var(--s-warn-tx); }
  .be-pendiente_validacion { background:var(--s-info-bg); color:#475569; }
  .be-validado             { background:var(--s-ok-bg); color:var(--s-ok-tx); }
  .be-rechazado            { background:var(--s-err-bg); color:#c53030; }
  /* Dark mode */
  :root[data-tema="oscuro"] .be-pendiente_voucher    { background:#451a03 !important; color:#fcd34d !important; }
  :root[data-tema="oscuro"] .be-pendiente_validacion { background:#1e293b !important; color:#94a3b8 !important; }
  :root[data-tema="oscuro"] .be-validado             { background:#14532d !important; color:#86efac !important; }
  :root[data-tema="oscuro"] .be-rechazado            { background:#450a0a !important; color:#fca5a5 !important; }

  .btn-acc { display:inline-flex; align-items:center; gap:4px; padding:5px 10px; border-radius:7px; font-size:12px; font-weight:600; border:none; cursor:pointer; text-decoration:none; transition:opacity .15s; }
  .btn-acc:hover { opacity:.85; }
  .btn-acc-green { background:#16a34a; color:#fff; }
  .btn-acc-red   { background:#c53030; color:#fff; }
  .btn-acc-blue  { background:var(--s-info-bg); color:var(--s-info-tx); }
  .btn-acc-amber { background:var(--s-warn-bg); color:var(--s-warn-tx); border:1px solid #fed7aa; }

  .pago-cell { display:flex; flex-direction:column; gap:4px; }
  .pago-cell-top { display:flex; align-items:center; gap:6px; flex-wrap:nowrap; overflow:hidden; }
  .pago-cell-monto { font-weight:700; color:var(--gris-dark); font-size:14px; line-height:1.1; white-space:nowrap; flex-shrink:0; }
  .pago-cell-acciones { display:flex; gap:4px; flex-wrap:wrap; margin-top:2px; }
  .pago-actualizado { font-size:10px; font-weight:600; color:#d97706; display:inline-flex; align-items:center; gap:3px; }

  /* .empty-state → canónico en design-system.css */

  .filter-select option { font-size:13px; }
  .mes-badge { display:inline-flex; align-items:center; gap:5px; background:var(--s-info-bg); color:#475569; padding:4px 10px; border-radius:100px; font-size:11px; font-weight:600; }

  @media (max-width: 768px) {
    /* KPIs compactas: 3 por fila (5 recuadros → 2 filas) */
    .kpi-grid { grid-template-columns: repeat(3, 1fr); gap:8px; margin-bottom:14px; }
    .kpi-card { padding:10px 10px; }
    .kpi-label { font-size:9px; letter-spacing:.3px; margin-bottom:3px; }
    .kpi-value { font-size:15px; white-space:normal; word-break:break-word; line-height:1.15; }
    .kpi-sub { display:none; }  /* subtítulo secundario: se oculta en móvil para compactar */
    /* Filtros colapsables canónicos (.ggi-filtros, global): la búsqueda va a ancho completo */
    .toolbar .search-inp { flex:1 1 100%; min-width:0; width:100%; }
    /* Cards responsive para pagos-table */
    .pagos-table thead { display:none; }
    .pagos-table, .pagos-table tbody, .pagos-table tr, .pagos-table td { display:block; width:100%; }
    .pagos-table tr { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); margin-bottom:10px; padding:12px 14px; }
    .pagos-table td { padding:4px 0; border-bottom:none; font-size:13px; }
    .pagos-table td:nth-child(3) { display:none; } /* Ocultar Ejecutivo en mobile */
    .pagos-table td:nth-child(2) { font-weight:700; font-size:12px; color:var(--azul); padding-bottom:6px; }
    .pagos-table td:nth-child(4), .pagos-table td:nth-child(5) { padding-top:8px; border-top:1px solid var(--gris-borde); }
    .pago-cell-top { flex-wrap:wrap; }
  }
  @media (max-width: 480px) {
    /* Mantener 3 columnas compactas (5 recuadros en 2 filas) también en pantallas pequeñas */
    .kpi-grid { grid-template-columns: repeat(3, 1fr); gap:7px; }
    .kpi-card { padding:8px 8px; }
    .kpi-value { font-size:14px; }
    .kpi-label { font-size:8.5px; }
  }
</style>

<!-- KPIs -->
<div class="kpi-grid ggi-stagger">
  <div class="kpi-card">
    <div class="kpi-label">Pendientes validación</div>
    <div class="kpi-value ggi-num <?= (int)$kpi['pendientes'] ? 'amber' : 'muted' ?>"><?= number_format((int)$kpi['pendientes']) ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Validados</div>
    <div class="kpi-value ggi-num <?= (int)$kpi['validados'] ? 'green' : 'muted' ?>"><?= number_format((int)$kpi['validados']) ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Rechazados</div>
    <div class="kpi-value ggi-num <?= (int)$kpi['rechazados'] ? 'red' : 'muted' ?>"><?= number_format((int)$kpi['rechazados']) ?></div>
  </div>
  <?php if (!$es_ejecutivo): ?>
  <div class="kpi-card">
    <div class="kpi-label">Total cobrado (S/)</div>
    <div class="kpi-value blue ggi-num">S/ <?= number_format((float)$kpi['total_pen'], 2) ?></div>
    <?php if ($mes_sel): ?>
    <div class="kpi-sub"><?= count($mes_sel) === 1 ? _label_mes($mes_sel[0], $meses_nombres) : count($mes_sel) . ' meses' ?></div>
    <?php endif; ?>
  </div>
  <?php if ((float)$kpi['total_usd'] > 0): ?>
  <div class="kpi-card">
    <div class="kpi-label">Total cobrado (USD)</div>
    <div class="kpi-value blue ggi-num">USD <?= number_format((float)$kpi['total_usd'], 2) ?></div>
  </div>
  <?php endif; ?>
  <?php if ((float)$kpi['igv_pen'] > 0): ?>
  <div class="kpi-card">
    <div class="kpi-label">IGV cobrado (S/)</div>
    <div class="kpi-value teal ggi-num">S/ <?= number_format((float)$kpi['igv_pen'], 2) ?></div>
    <div class="kpi-sub">18% incluido en pagos validados</div>
  </div>
  <?php endif; ?>
  <?php if ((float)$kpi['igv_usd'] > 0): ?>
  <div class="kpi-card">
    <div class="kpi-label">IGV cobrado (USD)</div>
    <div class="kpi-value teal ggi-num">USD <?= number_format((float)$kpi['igv_usd'], 2) ?></div>
    <div class="kpi-sub">18% incluido en pagos validados</div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Page header con botón export -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Pagos</h1>
    <p><?= $total ?> pago<?= $total !== 1 ? 's' : '' ?> encontrado<?= $total !== 1 ? 's' : '' ?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <?php $_pag_exp = 'q='.urlencode($search); foreach ($mes_sel as $_m) $_pag_exp .= '&mes[]='.urlencode($_m); foreach ($estados_sel as $_es) $_pag_exp .= '&estado[]='.urlencode($_es); ?>
    <a href="<?= APP_URL ?>/modules/pagos/exportar.php?<?= $_pag_exp ?>"
       class="btn btn-outline btn-sm" title="Exportar a CSV">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      CSV
    </a>
  </div>
</div>

<!-- Toolbar (patrón canónico global: .ggi-filtros colapsables en móvil) -->
<?php $_nfiltros = (!empty($mes_sel) ? 1 : 0) + (!empty($estados_sel) ? 1 : 0); ?>
<div class="toolbar">
<form method="GET" action="" id="form-filtros" class="<?= $_nfiltros ? 'filtros-open' : '' ?>" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
  <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Buscar empresa, RUC, propuesta…" class="search-inp" oninput="ggiSearch('pagos-q',this,10000)" onkeydown="if(event.key==='Enter'){event.preventDefault();ggiSearch('pagos-q',this,0)}">

  <button type="button" class="ggi-filtros-toggle" onclick="this.closest('form').classList.toggle('filtros-open')" aria-label="Mostrar u ocultar filtros">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
    Filtros<?php if ($_nfiltros): ?> <span class="ggi-filtros-badge"><?= $_nfiltros ?></span><?php endif; ?>
  </button>

  <div class="ggi-filtros">
  <!-- Filtro por mes -->
  <?php
    $_mes_opts = [];
    foreach ($meses_disponibles as $m) $_mes_opts[(string)$m] = _label_mes($m, $meses_nombres);
    echo ggi_msdrop('ms-pago-mes', 'mes', $_mes_opts, $mes_sel, ['placeholder' => 'Todos los meses', 'aria' => 'Filtrar por mes']);
  ?>

  <!-- Filtro por estado — multiselección -->
  <?php
  $_pago_estados = [
    'pendiente_validacion' => ['label'=>'Pendiente validación', 'cls'=>'is-muted'],
    'pendiente_voucher'    => ['label'=>'Pendiente voucher',    'cls'=>'is-warn'],
    'validado'             => ['label'=>'Validado',             'cls'=>'is-ok'],
    'rechazado'            => ['label'=>'Rechazado',            'cls'=>'is-err'],
  ];
  $_pago_lbl = empty($estados_sel) ? 'Todos los estados'
    : implode(', ', array_map(fn($v) => $_pago_estados[$v]['label'] ?? $v, $estados_sel));
  ?>
  <div class="ms-drop" id="ms-pago-estado">
    <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('ms-pago-estado')" aria-haspopup="listbox" aria-label="Filtrar por estado">
      <span class="ms-drop-lbl <?= empty($estados_sel)?'is-placeholder':'' ?>"><?= htmlspecialchars($_pago_lbl) ?></span>
      <?php if (!empty($estados_sel)): ?><span class="ms-drop-count"><?= count($estados_sel) ?></span><?php endif; ?>
      <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <div class="ms-drop-menu" hidden>
      <?php foreach ($_pago_estados as $_val => $_cfg): ?>
      <label class="ms-drop-item">
        <input type="checkbox" name="estado[]" value="<?= $_val ?>"
               <?= in_array($_val, $estados_sel) ? 'checked' : '' ?>
               onchange="msDrop.change('ms-pago-estado', this)">
        <span class="ms-drop-dot <?= $_cfg['cls'] ?>"></span>
        <span><?= $_cfg['label'] ?></span>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  </div><!-- /.ggi-filtros -->

  <?php if ($search || !empty($estados_sel) || $mes_sel): ?>
  <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
  <?php endif; ?>
</form>
</div>

<!-- Tabla -->
<div style="background:var(--gris-card,#fff);border-radius:12px;border:1px solid var(--gris-borde);overflow:hidden;">
  <?php if (empty($pagos)): ?>
  <div class="empty-state">
    <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" style="margin:0 auto 12px;display:block;opacity:0.2;"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    <p>No hay pagos que coincidan con los filtros.</p>
  </div>
  <?php else: ?>
  <?php
    $pagos_grupos = [];
    $cot_order    = [];
    foreach ($pagos as $p) {
        if (!isset($pagos_grupos[$p['cotizacion_id']])) {
            $cot_order[] = $p['cotizacion_id'];
            $pagos_grupos[$p['cotizacion_id']] = [];
        }
        $pagos_grupos[$p['cotizacion_id']][] = $p;
    }
    $umbral_reciente = strtotime($pag_last_visit);
    $GLOBALS['_pag_umbral_ts'] = $umbral_reciente;
    $GLOBALS['_user_rol']      = $user['rol'];   // disponible dentro de _render_pago_cell

    function _render_pago_cell(array $p, bool $es_ej, string $app_url): string {
        $msym  = $p['moneda'] === 'USD' ? 'USD ' : 'S/ ';
        $dots  = ['pendiente_voucher'=>'#f59e0b','pendiente_validacion'=>'#3b82f6','validado'=>'#22c55e','rechazado'=>'#ef4444'];
        $lbls  = ['pendiente_voucher'=>'Sin voucher','pendiente_validacion'=>'Por validar','validado'=>'Validado','rechazado'=>'Rechazado'];
        $dot   = $dots[$p['estado']] ?? '#94a3b8';
        $lbl   = $lbls[$p['estado']] ?? $p['estado'];
        $ts    = $GLOBALS['_pag_umbral_ts'] ?? strtotime('-30 days');
        $recent = $p['fecha_validacion'] && strtotime($p['fecha_validacion']) >= $ts
                  && in_array($p['estado'], ['validado','rechazado']);
        $new_upload = !$p['fecha_validacion'] && $p['fecha_subida']
                  && strtotime($p['fecha_subida']) >= $ts
                  && $p['estado'] === 'pendiente_validacion';
        $pid = (int)$p['id'];

        $html  = '<div class="pago-cell">';
        // Monto + badge en MISMA línea (compacto). Antes apilados verticalmente.
        $html .= '<div class="pago-cell-top">';
        $html .= '<span class="pago-cell-monto ggi-num">' . $msym . number_format($p['monto'], 2) . '</span>';
        $html .= '<span class="badge-estado be-' . htmlspecialchars($p['estado']) . '">';
        $html .= '<span style="width:6px;height:6px;border-radius:50%;background:' . $dot . ';display:inline-block;"></span> ' . $lbl;
        $html .= '</span>';
        $html .= '</div>';
        if ($recent) {
            $color = $p['estado'] === 'validado' ? 'var(--s-ok-tx)' : '#c53030';
            $html .= '<span class="pago-actualizado" style="color:' . $color . '"><svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Actualizado</span>';
        } elseif ($new_upload) {
            $html .= '<span class="pago-actualizado"><svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Nuevo voucher</span>';
        }
        if ($p['notas_validacion'] && $p['estado'] === 'rechazado') {
            $nota_short = mb_strimwidth($p['notas_validacion'], 0, 35, '…');
            $html .= '<div data-rechazo="1" style="font-size:10px;color:#c53030;" title="' . htmlspecialchars($p['notas_validacion']) . '">' . htmlspecialchars($nota_short) . '</div>';
        }
        $html .= '<div id="acc-' . $pid . '" class="pago-cell-acciones">';
        if ($p['voucher_path']) {
            $html .= '<a href="' . $app_url . '/modules/pagos/ver_voucher.php?id=' . $pid . '" target="_blank" data-ggi-preview data-title="Voucher de pago" class="btn-acc btn-acc-blue" data-voucher-link="1">';
            $html .= '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Voucher</a>';
        }
        if (in_array($p['estado'], ['pendiente_voucher','rechazado'])) {
            $html .= '<label id="lbl-upload-' . $pid . '" class="btn-acc btn-acc-amber" style="cursor:pointer;">';
            $html .= '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir';
            $html .= '<input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;" onchange="subirVoucherTabla(' . $pid . ', this)"></label>';
        }
        if ($p['estado'] === 'pendiente_validacion' && !$es_ej) {
            $html .= '<button type="button" class="btn-acc btn-acc-green" onclick="validarPagoGerente(' . $pid . ')">';
            $html .= '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Validar</button>';
            $html .= '<button type="button" class="btn-acc btn-acc-red" onclick="rechazarPagoGerente(' . $pid . ')">';
            $html .= '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rechazar</button>';
        }
        // Botón eliminar: solo gerente_general y solo para estados no validados
        $user_rol = $GLOBALS['_user_rol'] ?? '';
        if (in_array($p['estado'], ['pendiente_voucher','rechazado']) && $user_rol === 'gerente_general') {
            $html .= '<button type="button" class="btn-ic btn-ic-red" title="Eliminar pago" aria-label="Eliminar pago" style="width:28px;height:28px;" ';
            $html .= 'onclick="ggiEliminarPago(' . $pid . ', \'' . addslashes($p['tipo']) . '\', \'' . addslashes($p['monto'] . ' ' . $p['moneda']) . '\')">';
            $html .= '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6m4-6v6"/><path d="M9 6V4h6v2"/></svg></button>';
        }
        $html .= '</div></div>';
        return $html;
    }
  ?>
  <div style="overflow-x:auto;">
  <table class="pagos-table">
    <thead>
      <tr>
        <th scope="col">Empresa</th>
        <th scope="col">Propuesta</th>
        <th scope="col">Ejecutivo</th>
        <th scope="col" style="width:200px;">Pago inicial 50%</th>
        <th scope="col" style="width:200px;">Pago final 50%</th>
      </tr>
    </thead>
    <tbody class="ggi-stagger">
    <?php foreach ($cot_order as $cot_id):
      $grupo = $pagos_grupos[$cot_id];
      $pm    = [];
      foreach ($grupo as $pg) $pm[$pg['tipo']] = $pg;
      $pi = $pm['inicial'] ?? null;
      $pf = $pm['final']   ?? null;
      $rep = $pi ?? $pf;
      $es_hl = $highlight_id && (($pi && $pi['id'] == $highlight_id) || ($pf && $pf['id'] == $highlight_id));
      $tiene_update = false;
      foreach ($grupo as $pg) {
          if ($pg['fecha_validacion'] && strtotime($pg['fecha_validacion']) >= $umbral_reciente && in_array($pg['estado'],['validado','rechazado'])) { $tiene_update = true; break; }
          if (!$pg['fecha_validacion'] && $pg['fecha_subida'] && strtotime($pg['fecha_subida']) >= $umbral_reciente && $pg['estado']==='pendiente_validacion') { $tiene_update = true; break; }
      }
    ?>
    <tr id="cot-row-<?= $cot_id ?>" class="<?= $es_hl ? 'highlight-row' : '' ?>"
        style="<?= $tiene_update ? 'border-left:3px solid #94a3b8;' : '' ?>">
      <td style="max-width:190px;">
        <a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $rep['cliente_id'] ?>&tab=pagos"
           class="row-link" style="display:block;"><span class="txt-ellipsis" title="<?= htmlspecialchars($rep['empresa']) ?>"><?= sanitize($rep['empresa']) ?></span></a>
        <div style="font-size:11px;color:var(--gris-texto);"><?= ggi_copy($rep['ruc']) ?></div>
      </td>
      <td style="font-size:12px;color:var(--gris-texto);font-weight:600;"><?= sanitize($rep['cot_numero']) ?></td>
      <td style="font-size:12px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= $rep['ejecutivo_nombre'] ? htmlspecialchars($rep['ejecutivo_nombre'].' '.$rep['ejecutivo_apellido']) : '' ?>"><?= $rep['ejecutivo_nombre'] ? sanitize($rep['ejecutivo_nombre'].' '.$rep['ejecutivo_apellido']) : '—' ?></td>
      <td data-pago-id="<?= $pi ? $pi['id'] : '' ?>" style="vertical-align:top;padding:10px 14px;">
        <?= $pi ? _render_pago_cell($pi, $es_ejecutivo, APP_URL) : '<span style="color:var(--gris-texto);font-size:12px;">—</span>' ?>
      </td>
      <td data-pago-id="<?= $pf ? $pf['id'] : '' ?>" style="vertical-align:top;padding:10px 14px;">
        <?= $pf ? _render_pago_cell($pf, $es_ejecutivo, APP_URL) : '<span style="color:var(--gris-texto);font-size:12px;">—</span>' ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- Paginación -->
  <?php if ($total > 0): ?>
    <?= ggi_pager($total, $page, $per, [
        'per_page_options' => [10, 20, 30],
        'noun'             => 'pago',
        'noun_plural'      => 'pagos',
    ]) ?>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Modal Validar Pago -->
<div id="modal-validar" class="modal-overlay">
  <div style="background:var(--bg-surface);border-radius:14px;padding:28px 28px 22px;max-width:400px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.18);">
    <div style="font-size:15px;font-weight:700;color:var(--gris-dark);margin-bottom:10px;display:flex;align-items:center;gap:8px;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#22c55e" stroke-width="2.5"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
      Confirmar validación
      <button type="button" class="modal-x-close" style="margin-left:auto;" onclick="cerrarModalValidar()" title="Cerrar"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <p style="font-size:13px;color:var(--gris-texto);margin:0 0 20px;">¿Confirmas que el pago fue verificado y es correcto?</p>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button type="button" onclick="cerrarModalValidar()" class="ggi-btn-confirm-cancel">Cancelar</button>
      <button type="button" id="btn-confirmar-validar" data-confirm style="padding:8px 20px;border-radius:8px;border:none;background:#22c55e;color:#fff;font-size:13px;font-weight:700;cursor:pointer;">Validar pago</button>
    </div>
  </div>
</div>

<!-- Modal Rechazar Pago -->
<div id="modal-rechazar" class="modal-overlay">
  <div style="background:var(--bg-surface);border-radius:14px;padding:28px 28px 22px;max-width:420px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.18);">
    <div style="font-size:15px;font-weight:700;color:var(--gris-dark);margin-bottom:10px;display:flex;align-items:center;gap:8px;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#ef4444" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/><circle cx="12" cy="12" r="10"/></svg>
      Rechazar pago
      <button type="button" class="modal-x-close" style="margin-left:auto;" onclick="cerrarModalRechazar()" title="Cerrar"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <p style="font-size:13px;color:var(--gris-texto);margin:0 0 12px;">Indica el motivo del rechazo. El ejecutivo podrá ver esta nota.</p>
    <textarea id="inp-motivo-rechazo" rows="3" placeholder="Motivo del rechazo..." style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--gris-borde);border-radius:8px;font-size:13px;resize:vertical;"></textarea>
    <div id="err-motivo-rechazo" style="display:none;color:#ef4444;font-size:11px;margin-top:4px;">El motivo es obligatorio.</div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
      <button type="button" onclick="cerrarModalRechazar()" class="ggi-btn-confirm-cancel">Cancelar</button>
      <button type="button" id="btn-confirmar-rechazar" data-confirm style="padding:8px 20px;border-radius:8px;border:none;background:#ef4444;color:#fff;font-size:13px;font-weight:700;cursor:pointer;">Rechazar</button>
    </div>
  </div>
</div>

<script>
function ggiEliminarPago(id, tipo, monto) {
  ggiConfirmarEliminar({
    titulo:      'Eliminar pago',
    mensaje:     'Se eliminará el pago de <strong>' + monto + '</strong> (tipo: ' + tipo + ').',
    advertencia: 'El pago desaparecerá del listado. Podrás deshacerlo durante unos segundos desde el aviso.',
    reversible:  false,
    labelConfirmar: 'Sí, eliminar',
    onConfirmar: async function() {
      const fd = new FormData();
      fd.append('pago_id', id);
      fd.append('_csrf', document.querySelector('[name="_csrf"]')?.value || '');
      const r    = await fetch('<?= APP_URL ?>/modules/pagos/ajax_eliminar.php', { method:'POST', headers:{'X-CSRF-TOKEN':window.CSRF_TOKEN||''}, body: fd });
      const data = await r.json();
      if (data.ok) {
        if (typeof ggiToastUndo === 'function') ggiToastUndo('pagos', id, data.msg);
        else if (typeof showToast === 'function') showToast(data.msg, 'ok');
        // Quitar la fila del pago o recargar
        const cell = document.querySelector('[data-pago-id="' + id + '"]');
        if (cell) cell.closest('tr')?.remove();
        if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'No se pudo eliminar.', 'error');
      }
    }
  });
}

var _badgeDots   = {validado:'#22c55e', rechazado:'#ef4444', pendiente_validacion:'#3b82f6', pendiente_voucher:'#f59e0b'};
var _badgeLabels = {validado:'Validado', rechazado:'Rechazado', pendiente_validacion:'Por validar', pendiente_voucher:'Sin voucher'};
var _esGerente   = <?= $_es_gerente ? 'true' : 'false' ?>;

function _getCellByPagoId(pagoId) {
  return document.querySelector('[data-pago-id="' + pagoId + '"]') || null;
}

function subirVoucherTabla(pagoId, input) {
  if (!input.files || !input.files[0]) return;
  var lbl = document.getElementById('lbl-upload-' + pagoId);
  if (lbl) lbl.style.opacity = '0.6';
  var fd = new FormData();
  fd.append('accion', 'subir_voucher');
  fd.append('pago_id', pagoId);
  fd.append('voucher', input.files[0]);
  fd.append('_csrf', window.CSRF_TOKEN || '');
  fetch(window.APP_URL + '/modules/pagos/ajax_pago.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok) {
        if (typeof showToast === 'function') showToast(d.msg || 'Voucher subido.', 'ok');
        try { _actualizarFilaPago(pagoId, 'pendiente_validacion', ''); } catch(e) {}
        if (typeof window._pollBadges === 'function') window._pollBadges();
        if (lbl) lbl.style.display = 'none';
        var acc = document.getElementById('acc-' + pagoId);
        if (acc && !acc.querySelector('[data-voucher-link]')) {
          var a = document.createElement('a');
          a.href = (window.APP_URL || '') + '/modules/pagos/ver_voucher.php?id=' + pagoId;
          a.target = '_blank';
          a.className = 'btn-acc btn-acc-blue';
          a.setAttribute('data-voucher-link', '1');
          a.setAttribute('data-ggi-preview', ''); a.setAttribute('data-title', 'Voucher de pago');
          a.innerHTML = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Voucher';
          acc.prepend(a);
        }
      } else {
        if (typeof showToast === 'function') showToast(d.msg || 'Error al subir.', 'error');
        if (lbl) lbl.style.opacity = '1';
      }
    })
    .catch(function() {
      if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
      if (lbl) lbl.style.opacity = '1';
    });
  input.value = '';
}

function _actualizarFilaPago(pagoId, nuevoEstado, motivo) {
  var cell = _getCellByPagoId(pagoId);
  if (!cell) return;
  var badge = cell.querySelector('.badge-estado');
  if (badge) {
    badge.className = 'badge-estado be-' + nuevoEstado;
    badge.innerHTML = '<span style="width:6px;height:6px;border-radius:50%;background:' + (_badgeDots[nuevoEstado]||'#94a3b8') + ';display:inline-block;"></span> ' + (_badgeLabels[nuevoEstado]||nuevoEstado);
  }
  // Mostrar/ocultar botones Validar y Rechazar
  if (nuevoEstado === 'pendiente_validacion' && _esGerente) {
    var acc = document.getElementById('acc-' + pagoId);
    if (acc) {
      var btnV = acc.querySelector('.btn-acc-green');
      var btnR = acc.querySelector('.btn-acc-red');
      if (!btnV) {
        btnV = document.createElement('button');
        btnV.type = 'button'; btnV.className = 'btn-acc btn-acc-green';
        btnV.innerHTML = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Validar';
        btnV.onclick = function(){ validarPagoGerente(pagoId); };
        acc.appendChild(btnV);
      } else { btnV.style.display = ''; }
      if (!btnR) {
        btnR = document.createElement('button');
        btnR.type = 'button'; btnR.className = 'btn-acc btn-acc-red';
        btnR.innerHTML = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rechazar';
        btnR.onclick = function(){ rechazarPagoGerente(pagoId); };
        acc.appendChild(btnR);
      } else { btnR.style.display = ''; }
    }
  } else {
    cell.querySelectorAll('.btn-acc-green, .btn-acc-red').forEach(function(b){ b.style.display='none'; });
  }
  if (nuevoEstado === 'rechazado' && motivo) {
    var notaEl = cell.querySelector('[data-rechazo]');
    if (!notaEl) {
      notaEl = document.createElement('div');
      notaEl.setAttribute('data-rechazo','1');
      notaEl.style.cssText = 'font-size:10px;color:#c53030;margin-top:3px;';
      if (badge) badge.insertAdjacentElement('afterend', notaEl);
    }
    notaEl.textContent = motivo.length > 40 ? motivo.substring(0,40)+'…' : motivo;
  }
  var lbl = document.getElementById('lbl-upload-' + pagoId);
  if (lbl) lbl.style.display = ['pendiente_voucher','rechazado'].includes(nuevoEstado) ? '' : 'none';
}

var _pagoIdPendiente = null;

function cerrarModalValidar() {
  var m = document.getElementById('modal-validar');
  if (m) m.classList.remove('open');
  _pagoIdPendiente = null;
}
function cerrarModalRechazar() {
  var m = document.getElementById('modal-rechazar');
  if (m) { m.classList.remove('open'); }
  var inp = document.getElementById('inp-motivo-rechazo');
  if (inp) inp.value = '';
  var err = document.getElementById('err-motivo-rechazo');
  if (err) err.style.display = 'none';
  _pagoIdPendiente = null;
}

function validarPagoGerente(pagoId) {
  _pagoIdPendiente = pagoId;
  var m = document.getElementById('modal-validar');
  if (!m) return;
  m.classList.add('open');
  document.getElementById('btn-confirmar-validar').onclick = function() {
    cerrarModalValidar();
    var fd = new FormData();
    fd.append('accion','validar'); fd.append('pago_id', pagoId); fd.append('_csrf', window.CSRF_TOKEN||'');
    fetch(window.APP_URL+'/modules/pagos/ajax_pago.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.ok){
          if(typeof showToast==='function') showToast(d.msg||'Pago validado.','ok');
          try{_actualizarFilaPago(pagoId,'validado','');}catch(e){}
          if(typeof window._pollBadges==='function') window._pollBadges();
          if(typeof window.invalidatePageCache==='function') window.invalidatePageCache(location.href);
        } else { if(typeof showToast==='function') showToast(d.msg||'Error.','error'); }
      })
      .catch(function(){ if(typeof showToast==='function') showToast('Error de conexión.','error'); });
  };
}

function rechazarPagoGerente(pagoId) {
  _pagoIdPendiente = pagoId;
  var m = document.getElementById('modal-rechazar');
  if (!m) return;
  var inp = document.getElementById('inp-motivo-rechazo');
  if (inp) inp.value = '';
  var err = document.getElementById('err-motivo-rechazo');
  if (err) err.style.display = 'none';
  m.classList.add('open');
  document.getElementById('btn-confirmar-rechazar').onclick = function() {
    var nota = document.getElementById('inp-motivo-rechazo').value.trim();
    if (!nota) {
      var errEl = document.getElementById('err-motivo-rechazo');
      if (errEl) errEl.style.display = 'block';
      return;
    }
    cerrarModalRechazar();
    var fd = new FormData();
    fd.append('accion','rechazar'); fd.append('pago_id',pagoId); fd.append('notas',nota); fd.append('_csrf',window.CSRF_TOKEN||'');
    fetch(window.APP_URL+'/modules/pagos/ajax_pago.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.ok){
          if(typeof showToast==='function') showToast(d.msg||'Rechazado.','ok');
          try{_actualizarFilaPago(pagoId,'rechazado',nota);}catch(e){}
          if(typeof window._pollBadges==='function') window._pollBadges();
          if(typeof window.invalidatePageCache==='function') window.invalidatePageCache(location.href);
        } else { if(typeof showToast==='function') showToast(d.msg||'Error.','error'); }
      })
      .catch(function(){ if(typeof showToast==='function') showToast('Error de conexión.','error'); });
  };
}

// Cerrar modales al hacer clic fuera
document.addEventListener('click', function(e) {
  var mv = document.getElementById('modal-validar');
  var mr = document.getElementById('modal-rechazar');
  if (mv && mv.classList.contains('open') && e.target === mv) cerrarModalValidar();
  if (mr && mr.classList.contains('open') && e.target === mr) cerrarModalRechazar();
});

<?php if ($highlight_id): ?>
ggiReady(function() {
  var cell = document.querySelector('[data-pago-id="<?= $highlight_id ?>"]');
  if (cell) { cell.closest('tr').scrollIntoView({behavior:'smooth', block:'center'}); }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
