<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas');

$pdo = Database::get();

// ── Tracking última visita ────────────────────────────────────────────────────
try {
    $pdo->prepare('UPDATE usuarios SET ultima_visita_propuestas = CURDATE() WHERE id = ?')
        ->execute([$user['id']]);
    // Invalidar el caché de sesión que usa ajax_badges.php para la misma columna,
    // así el siguiente poll lee el valor actualizado y apaga el badge de inmediato.
    $_SESSION['uv_propuestas_' . $user['id']] = date('Y-m-d');
} catch (\Exception $e) {}

$buscar     = trim($_GET['q'] ?? '');
$ejec_sel   = array_values(array_filter(array_map('intval', (array)($_GET['ejecutivo'] ?? [])), fn($v) => $v > 0));

// Multi-selección de estados: acepta ?estado[]=v1&estado[]=v2 O legado ?estado=v
$_est_raw    = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$estados_sel = array_values(array_filter($_est_raw, fn($v) => $v !== ''));
// Nota: 'activa' es un pseudo-estado que expande a borrador+enviada
$cliente_fil= 0; // disabled
$mes_fil    = $_GET['mes'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $mes_fil)) $mes_fil = '';
// Propuesta recién creada (desde crear.php o modal embed)
$_nuevo_id  = (int)($_GET['nuevo'] ?? 0);
$_nuevo_num = trim($_GET['num'] ?? '');
$_pp_opts   = [10, 20, 30];
$_pp_req    = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$limit      = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $limit;

// ── Paginación server-side offset + COUNT (estándar global ggi_pager) ──
$page = max(1, (int)($_GET['p'] ?? 1));

$where  = ['1=1', 'cot.deleted_at IS NULL'];  // Excluir propuestas eliminadas
$params = [];

// Ejecutivo_ventas ve sus cotizaciones propias O cotizaciones de clientes donde es
// ejecutivo, responsable actual o captador original.
if ($user['rol'] === 'ejecutivo_ventas') {
    $where[]  = '(cot.ejecutivo_id = ? OR cl.ejecutivo_id = ? OR cl.responsible_user_id = ? OR cl.created_by_user_id = ?)';
    $params[] = $user['id'];
    $params[] = $user['id'];
    $params[] = $user['id'];
    $params[] = $user['id'];
}
if ($buscar) {
    $where[]  = '(cot.numero LIKE ? OR cot.empresa_nombre LIKE ? OR cot.empresa_ruc LIKE ? OR cl.razon_social LIKE ?)';
    $params[] = "%$buscar%"; $params[] = "%$buscar%";
    $params[] = "%$buscar%"; $params[] = "%$buscar%";
}
if (!empty($estados_sel)) {
    // 'activa' pseudo-estado → expande a borrador + enviada
    $_vals_sql = [];
    foreach ($estados_sel as $_sv) {
        if ($_sv === 'activa') {
            $_vals_sql[] = 'borrador';
            $_vals_sql[] = 'enviada';
        } else {
            $_vals_sql[] = $_sv;
        }
    }
    $_vals_sql = array_unique($_vals_sql);
    $ph = implode(',', array_fill(0, count($_vals_sql), '?'));
    $where[]  = "cot.estado IN ($ph)";
    $params   = array_merge($params, $_vals_sql);
}
if ($ejec_sel) {
    $ph = implode(',', array_fill(0, count($ejec_sel), '?'));
    $where[]  = "cot.ejecutivo_id IN ($ph)";
    $params   = array_merge($params, $ejec_sel);
}
if ($cliente_fil) {
    $where[]  = 'cot.cliente_id = ?';
    $params[] = $cliente_fil;
}
if ($mes_fil) {
    $where[]  = "cot.fecha_emision >= ? AND cot.fecha_emision < ?";
    $params[] = $mes_fil . '-01';
    $params[] = date('Y-m-d', strtotime('+1 month', strtotime($mes_fil . '-01')));
}

$sql_where = implode(' AND ', $where);

$total_s = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones cot LEFT JOIN clientes cl ON cl.id=cot.cliente_id WHERE $sql_where");
$total_s->execute($params);
$total_rows = (int)$total_s->fetchColumn();

$total_pages = max(1, (int)ceil($total_rows / $limit));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT cot.*,
           COALESCE(cl.razon_social, cot.empresa_nombre) AS nombre_empresa,
           COALESCE(cl.ruc, cot.empresa_ruc) AS ruc_empresa,
           cl.estado AS cliente_estado,
           ej.nombre AS ejecutivo_nombre, ej.apellido AS ejecutivo_apellido,
           DATEDIFF(CURDATE(), cot.fecha_emision) AS dias_enviada
    FROM cotizaciones cot
    LEFT JOIN clientes cl ON cl.id = cot.cliente_id
    JOIN usuarios u ON u.id = cot.creado_por
    LEFT JOIN usuarios ej ON ej.id = cot.ejecutivo_id
    WHERE $sql_where
    ORDER BY cot.created_at DESC, cot.id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$cotizaciones = $stmt->fetchAll();

$ejecutivos_lista = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='ejecutivo_ventas' ORDER BY nombre")->fetchAll();

$cot_ids = array_column($cotizaciones, 'id');
$normas_map = [];
$seg_count_map = [];
if ($cot_ids) {
    $ph = implode(',', array_fill(0, count($cot_ids), '?'));
    $nq = $pdo->prepare("SELECT cn.cotizacion_id, n.codigo FROM cotizacion_normas cn JOIN normas_iso n ON n.id=cn.norma_iso_id WHERE cn.cotizacion_id IN ($ph) ORDER BY n.codigo");
    $nq->execute($cot_ids);
    foreach ($nq->fetchAll() as $row) $normas_map[$row['cotizacion_id']][] = $row['codigo'];

    $sq = $pdo->prepare("SELECT cotizacion_id, COUNT(*) AS cnt FROM cotizacion_seguimientos WHERE cotizacion_id IN ($ph) GROUP BY cotizacion_id");
    $sq->execute($cot_ids);
    foreach ($sq->fetchAll() as $row) $seg_count_map[$row['cotizacion_id']] = (int)$row['cnt'];
}

$estado_config = [
    // Colores alineados al design system global (--ac, --s-ok, --s-err, --s-warn)
    'borrador'  => ['color'=>'var(--tx2)','label'=>'Borrador'],
    'enviada'   => ['color'=>'#1D4ED8','label'=>'Enviada'],   // --ac
    'aceptada'  => ['color'=>'#059669','label'=>'Aceptada'],  // --s-ok
    'rechazada' => ['color'=>'#DC2626','label'=>'Rechazada'], // --s-err
    'vencida'   => ['color'=>'#D97706','label'=>'Vencida'],   // --s-warn
    'desistida' => ['color'=>'var(--tx2)','label'=>'Desistida'], // aceptó y luego no respondió
];
$_meses_es = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$_meses_es_full = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$_mes_label = '';
if ($mes_fil) {
    [$_my, $_mm] = explode('-', $mes_fil);
    $_mes_label = ($_meses_es_full[(int)$_mm] ?? $mes_fil) . ' ' . $_my;
}

$page_title  = 'Propuestas';
$active_menu = 'propuestas';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .toolbar { display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; max-width:360px; }
  .search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); color:var(--gris-dark); }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); color:var(--gris-dark); cursor:pointer; }
  .table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  /* .data-table, .btn-ic, .action-btns, .empty-state → canónicos en design-system.css */
  /* Tags de normas: helper global ggi_tags() + .ggi-tag* (design-system.css) */
  .badge-tipo { display:inline-block; padding:1px 6px; border-radius:100px; font-size:10px; font-weight:600; }
  /* Paginación: estilos en layout_end.php */
  /* estado-sel y estado-badge: globales en layout_end.php */
  :root[data-tema="oscuro"] .estado-badge[data-estado="modificar"]   { background:#431407 !important; color:#fdba74 !important; }
  .td-estado { text-align:center; }
  .mobile-icon { display:none; }
  /* ── Bulk checkbox columns ── */
  .td-chk { width:36px; padding:0 6px 0 14px !important; }
  .th-chk { width:36px; padding:0 6px 0 14px !important; }
  .row-chk { width:15px; height:15px; cursor:pointer; accent-color:var(--ac,#1d4ed8); }

  /* ── Highlight de propuesta recién creada ── */
  @keyframes cot-highlight-in {
    0%   { background:var(--s-ok-bg); box-shadow: inset 3px 0 0 #16a34a; }
    70%  { background:var(--s-ok-bg); box-shadow: inset 3px 0 0 #16a34a; }
    100% { background:transparent; box-shadow: none; }
  }
  .cot-row-new td { animation: cot-highlight-in 3s ease forwards; }
  .cot-row-new td:first-child { border-radius: 0; }

  /* Banner de éxito recién creada */
  .cot-success-banner {
    display:flex; align-items:center; gap:12px;
    background:var(--s-ok-bg); border:1px solid var(--s-ok-brd); border-radius:10px;
    padding:12px 18px; margin-bottom:16px;
    animation: fadeDown 0.3s ease;
  }
  @keyframes fadeDown {
    from { opacity:0; transform:translateY(-8px); }
    to   { opacity:1; transform:translateY(0); }
  }
  .cot-success-banner svg { color:#16a34a; flex-shrink:0; }
  .cot-success-banner-text { flex:1; font-size:13px; color:var(--s-ok-tx); font-weight:500; }
  .cot-success-banner-text strong { font-weight:700; color:var(--s-ok-tx); }
  .cot-success-banner-close {
    background:none; border:none; cursor:pointer;
    color:#6b7280; padding:2px 6px; border-radius:5px;
    font-size:16px; line-height:1; flex-shrink:0;
    transition:background .12s;
  }
  .cot-success-banner-close:hover { background:var(--s-ok-bg); color:var(--s-ok-tx); }

  @media (max-width: 768px) {
    .td-estado { text-align:left; }
    .mobile-icon { display:inline-block; }
    .data-table thead { display:none; }
    .data-table { min-width:unset !important; }
    .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
    .data-table tr {
      background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde);
      margin-bottom:10px; padding:14px 16px;
    }
    .data-table tr:hover td { background:transparent; }
    .data-table td { padding:5px 0; border-bottom:none; font-size:13px; }
    .data-table td:nth-child(1),
    .data-table td:nth-child(2),
    .data-table td:nth-child(4),
    .data-table td:nth-child(5) { display:none; }
    .data-table td:last-child { margin-top:10px; padding-top:10px; border-top:1px solid var(--gris-borde); }
    .action-btns { gap:8px; }
    .btn-ic { width:36px; height:36px; }
    .toolbar { flex-direction:column; align-items:stretch; }
    .toolbar form { flex-direction:column; flex-wrap:nowrap; }
    .search-wrap { max-width:100%; }
    .filter-select { width:100%; }
    .estado-sel { max-width:100% !important; }
    .estado-sel-menu { left:0; transform:none; }
    .estado-badge { display:inline-block; }
    .data-table td:nth-child(3)::before {
      content: attr(data-num);
      display: block;
      font-size: 10px;
      font-weight: 700;
      color: var(--gris-texto);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 4px;
    }
    /* Paginación responsive: en layout_end.php */
  }
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Propuestas</h1>
    <p><?= $total_rows ?> propuesta<?= $total_rows !== 1 ? 'es' : '' ?> registrada<?= $total_rows !== 1 ? 's' : '' ?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <a href="<?= APP_URL ?>/modules/cotizaciones/pipeline.php" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="4" height="18"/><rect x="10" y="8" width="4" height="13"/><rect x="17" y="5" width="4" height="16"/></svg>
      Pipeline
    </a>
    <?php $_cot_exp = 'q='.urlencode($buscar); foreach ($ejec_sel as $_ej) $_cot_exp .= '&ejecutivo[]='.(int)$_ej; foreach ($estados_sel as $_es) $_cot_exp .= '&estado[]='.urlencode($_es); ?>
    <a href="<?= APP_URL ?>/modules/cotizaciones/exportar.php?<?= $_cot_exp ?>"
       class="btn btn-outline btn-sm" title="Exportar CSV">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      CSV
    </a>
    <button type="button" class="btn btn-primary" onclick="ggiNuevaPropuesta()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nueva propuesta
    </button>
  </div>
</div>

<?php $_nfiltros = (!empty($estados_sel) ? 1 : 0) + (!empty($ejec_sel) ? 1 : 0); ?>
<div class="toolbar">
  <form method="GET" class="<?= $_nfiltros ? 'filtros-open' : '' ?>" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar por número, empresa, RUC..." aria-label="Buscar por número, empresa, RUC..." value="<?= sanitize($buscar) ?>" oninput="ggiSearch('cotizaciones-q',this,10000)" onkeydown="if(event.key==='Enter'){event.preventDefault();ggiSearch('cotizaciones-q',this,0)}">
    </div>
    <button type="button" class="ggi-filtros-toggle" onclick="this.closest('form').classList.toggle('filtros-open')" aria-label="Mostrar u ocultar filtros">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filtros<?php if ($_nfiltros): ?> <span class="ggi-filtros-badge"><?= $_nfiltros ?></span><?php endif; ?>
    </button>
    <div class="ggi-filtros">
    <?php
    $_cot_estados = [
      'activa'    => ['label'=>'Activas (borrador+enviada)', 'cls'=>'is-info'],
      'borrador'  => ['label'=>'Borrador',  'cls'=>'is-muted'],
      'enviada'   => ['label'=>'Enviada',   'cls'=>'is-info'],
      'aceptada'  => ['label'=>'Aceptada',  'cls'=>'is-ok'],
      'rechazada' => ['label'=>'Rechazada', 'cls'=>'is-err'],
      'vencida'   => ['label'=>'Vencida',   'cls'=>'is-warn'],
      'desistida' => ['label'=>'Desistida', 'cls'=>'is-muted'],
    ];
    $_cot_lbl = empty($estados_sel) ? 'Todos los estados'
      : (count($estados_sel) <= 2
          ? implode(', ', array_map(fn($v) => $_cot_estados[$v]['label'] ?? $v, $estados_sel))
          : implode(', ', array_map(fn($v) => $_cot_estados[$v]['label'] ?? $v, array_slice($estados_sel,0,2))).' +'.(count($estados_sel)-2).' más');
    ?>
    <div class="ms-drop" id="ms-cot-estado">
      <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('ms-cot-estado')" aria-haspopup="listbox" aria-label="Filtrar por estado">
        <span class="ms-drop-lbl <?= empty($estados_sel)?'is-placeholder':'' ?>"><?= htmlspecialchars($_cot_lbl) ?></span>
        <?php if (!empty($estados_sel)): ?><span class="ms-drop-count"><?= count($estados_sel) ?></span><?php endif; ?>
        <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="ms-drop-menu" hidden>
        <?php foreach ($_cot_estados as $_val => $_cfg): ?>
        <label class="ms-drop-item">
          <input type="checkbox" name="estado[]" value="<?= $_val ?>"
                 <?= in_array($_val, $estados_sel) ? 'checked' : '' ?>
                 onchange="msDrop.change('ms-cot-estado', this)">
          <span class="ms-drop-dot <?= $_cfg['cls'] ?>"></span>
          <span><?= $_cfg['label'] ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (in_array($user['rol'], ['gerente_general','gerente_operaciones'])): ?>
    <?php
      $_ej_opts = [];
      foreach ($ejecutivos_lista as $ej) $_ej_opts[(string)$ej['id']] = $ej['nombre'] . ' ' . $ej['apellido'];
      echo ggi_msdrop('ms-cot-ejec', 'ejecutivo', $_ej_opts, $ejec_sel, ['placeholder' => 'Todos los ejecutivos', 'aria' => 'Filtrar por ejecutivo']);
    ?>
    <?php endif; ?>
    </div><!-- /.ggi-filtros -->
    <?php if ($buscar || !empty($estados_sel) || $ejec_sel || $cliente_fil): ?>
    <a href="<?= APP_URL ?>/modules/cotizaciones/index.php" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($mes_fil): ?>
<div style="display:flex;align-items:center;gap:10px;background:var(--s-info-bg);border:1px solid #bfdbfe;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:13px;color:#1d4ed8;">
  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
  Filtrando por mes: <strong><?= htmlspecialchars($_mes_label) ?></strong>
  <?php $_cot_mes_back = empty($estados_sel) ? '' : ('?' . implode('&', array_map(fn($e) => 'estado[]='.urlencode($e), $estados_sel))); ?>
  <a href="<?= APP_URL ?>/modules/cotizaciones/index.php<?= $_cot_mes_back ?>" style="margin-left:auto;color:#3b82f6;font-weight:600;text-decoration:none;font-size:12px;white-space:nowrap;">
    × Ver todos los meses
  </a>
</div>
<?php endif; ?>

<?php if ($_nuevo_id && $_nuevo_num): ?>
<div class="cot-success-banner" id="cot-success-banner">
  <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
    <polyline points="22 4 12 14.01 9 11.01"/>
  </svg>
  <div class="cot-success-banner-text">
    Propuesta <strong><?= sanitize($_nuevo_num) ?></strong> creada correctamente.
    <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $_nuevo_id ?>"
       style="color:#1d4ed8;font-weight:600;margin-left:8px;text-decoration:none;">
      Ver detalle →
    </a>
    &nbsp;
    <a href="<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $_nuevo_id ?>&dl=1"
       style="color:#1d4ed8;font-weight:600;text-decoration:none;">
      ↓ Descargar PDF
    </a>
  </div>
  <button class="cot-success-banner-close" onclick="document.getElementById('cot-success-banner').remove();" title="Cerrar">×</button>
</div>
<?php endif; ?>

<div class="table-wrap">
  <?php if (empty($cotizaciones)): ?>
  <?php $_cot_filtrado = ($buscar || !empty($estados_sel) || $ejec_sel || $cliente_fil || $mes_fil); ?>
  <div class="empty-state">
    <?php if ($_cot_filtrado): ?>
    <svg class="empty-state-icon" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
    <p class="empty-state-title">Sin resultados</p>
    <p class="empty-state-sub">No hay propuestas que coincidan con los filtros seleccionados.</p>
    <a href="<?= APP_URL ?>/modules/cotizaciones/index.php" class="btn btn-outline" style="margin-top:18px;">Limpiar filtros</a>
    <?php else: ?>
    <svg class="empty-state-icon" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
    <p class="empty-state-title">Aún no hay propuestas</p>
    <p class="empty-state-sub">Crea tu primera propuesta para empezar a registrar oportunidades.</p>
    <button type="button" class="btn btn-primary" style="margin-top:18px;" onclick="ggiOpenForm('<?= APP_URL ?>/modules/cotizaciones/crear.php','Nueva propuesta')">Nueva propuesta</button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="data-table" style="min-width:620px;">
    <thead>
      <tr>
        <?php if (has_role('gerente_general','gerente_operaciones')): ?>
        <th scope="col" class="th-chk">
          <input type="checkbox" id="chk-all" title="Seleccionar todo" style="accent-color:var(--azul);width:15px;height:15px;cursor:pointer;">
        </th>
        <?php endif; ?>
        <th scope="col">N°</th>
        <th scope="col">Empresa</th>
        <th scope="col">Norma</th>
        <th scope="col">Ejecutivo</th>
        <th scope="col">Monto</th>
        <th scope="col" class="td-estado">Estado</th>
        <th scope="col">Fecha</th>
        <th scope="col">Acciones</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($cotizaciones as $cot):
      $ec     = $estado_config[$cot['estado']] ?? ['color'=>'var(--tx2)','label'=>$cot['estado']];
      $normas = $normas_map[$cot['id']] ?? [];
      $es_final = in_array($cot['estado'], ['aceptada','rechazada','vencida','desistida']);
    ?>
    <tr data-cot-id="<?= $cot['id'] ?>"<?= ($cot['id'] === $_nuevo_id) ? ' class="cot-row-new" id="cot-row-new"' : '' ?>>
      <?php if (has_role('gerente_general','gerente_operaciones')): ?>
      <td class="td-chk">
        <input type="checkbox" class="row-chk" aria-label="Seleccionar fila" value="<?= $cot['id'] ?>" style="accent-color:var(--azul);width:15px;height:15px;cursor:pointer;">
      </td>
      <?php endif; ?>
      <td>
        <?php $_num_display = preg_match('/^COT-\d{4}-(.+)$/', $cot['numero'], $_nm) ? $_nm[1] : $cot['numero']; ?>
        <code style="font-size:11px;color:var(--gris-dark);font-weight:700;"><?= sanitize($_num_display) ?></code>
      </td>
      <td data-num="<?= sanitize($cot['numero']) ?>">
        <div class="av-row">
          <span class="av-chip" data-name="<?= sanitize($cot['nombre_empresa']) ?>"></span>
          <div class="av-name">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <?php if ($cot['cliente_id']): ?>
              <a class="row-link" href="ver.php?id=<?= $cot['id'] ?>"><span class="txt-ellipsis" title="<?= sanitize($cot['nombre_empresa']) ?>"><?= sanitize($cot['nombre_empresa']) ?></span></a>
              <?php else: ?>
              <span class="txt-ellipsis" style="font-weight:600;font-size:13px;" title="<?= sanitize($cot['nombre_empresa']) ?>"><?= sanitize($cot['nombre_empresa']) ?></span>
              <?php endif; ?>
              <?php if ($cot['cliente_id'] && $cot['cliente_estado'] === 'activo'): ?>
              <span class="chip chip-ok chip-sm">Activo</span>
              <?php elseif ($cot['cliente_id'] && $cot['cliente_estado'] === 'inactivo'): ?>
              <span class="chip chip-neutral chip-sm">Inactivo</span>
              <?php elseif ($cot['tipo_empresa'] === 'existente'): ?>
              <span class="chip chip-ok chip-sm">Existente</span>
              <?php else: ?>
              <span class="chip chip-warn chip-sm">Prospecto</span>
              <?php endif; ?>
            </div>
            <div style="font-size:11px;color:var(--gris-texto);"><?= sanitize($cot['ruc_empresa']??'') ?></div>
            <?php if (!empty($cot['notas'])): ?>
            <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;font-style:italic;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($cot['notas']) ?>">
              <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:2px;opacity:.6"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?= sanitize($cot['notas']) ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </td>
      <td>
        <?= ggi_tags($normas, ['max' => 2, 'noun' => 'normas de esta propuesta']) ?>
      </td>
      <td>
        <?php if ($cot['ejecutivo_nombre']): ?>
        <span style="font-size:12px;font-weight:600;color:var(--gris-dark)"><?= sanitize($cot['ejecutivo_nombre'].' '.$cot['ejecutivo_apellido']) ?></span>
        <?php else: ?><span style="color:var(--gris-texto);font-size:12px;">—</span><?php endif; ?>
      </td>
      <td>
        <div style="display:flex;align-items:center;gap:8px;">
          <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          <?php if ($cot['monto_total']): ?>
          <strong class="ggi-num" style="color:var(--gris-dark);white-space:nowrap;">S/&nbsp;<?= number_format($cot['monto_total'],2) ?></strong>
          <?php else: ?><span style="color:var(--gris-texto);">—</span><?php endif; ?>
        </div>
      </td>
      <td class="td-estado">
        <?php if ($es_final): ?>
        <span class="estado-badge" data-estado="<?= htmlspecialchars($cot['estado']) ?>"><?= $ec['label'] ?></span>
        <?php else:
          // Valid next states by current state
          $transiciones = [
            'borrador' => ['borrador','enviada'],
            'enviada'  => ['enviada','aceptada','rechazada','vencida'],
          ];
          $opciones_validas = $transiciones[$cot['estado']] ?? array_keys($estado_config);
        ?>
        <div class="estado-sel"
             data-id="<?= $cot['id'] ?>"
             data-value="<?= $cot['estado'] ?>"
             data-color="<?= htmlspecialchars($ec['color']) ?>"
             data-module="cotizaciones"
             role="button" tabindex="0">
          <span class="estado-sel-label"><?= $ec['label'] ?></span>
          <div class="estado-sel-menu" hidden>
            <?php foreach ($estado_config as $ek => $ev):
              if (!in_array($ek, $opciones_validas)) continue; ?>
            <div class="estado-sel-option"
                 data-value="<?= $ek ?>"
                 data-color="<?= $ev['color'] ?>"
                 style="--opt-color:<?= $ev['color'] ?>">
              <?= $ev['label'] ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </td>
      <td>
        <div style="display:flex;flex-direction:column;gap:4px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" stroke-width="2" style="flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span style="font-size:12px;color:var(--gris-texto);white-space:nowrap;"><?= formato_fecha($cot['created_at']) ?></span>
          </div>
        </div>
      </td>
      <td>
        <div class="action-btns">
          <?php $seg_c = $seg_count_map[$cot['id']] ?? 0; if ($seg_c > 0): ?>
          <span class="chip chip-neutral chip-sm" title="<?= $seg_c ?> seguimiento(s) registrado(s)" style="gap:3px;">
            <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <?= $seg_c ?>
          </span>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $cot['id'] ?>" class="btn-ic btn-ic-blue" title="Ver">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </a>
          <a href="<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $cot['id'] ?>&inline=1"
             data-ggi-preview
             data-src="<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $cot['id'] ?>&inline=1"
             data-download-url="<?= APP_URL ?>/modules/cotizaciones/pdf.php?id=<?= $cot['id'] ?>&dl=1"
             data-title="Propuesta <?= sanitize($cot['numero']) ?>"
             class="btn-ic btn-ic-blue" title="Ver propuesta (PDF)" aria-label="Ver propuesta <?= sanitize($cot['numero']) ?> en PDF">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          </a>
          <?php if (in_array($cot['estado'], ['borrador','enviada'])): ?>
          <button type="button" onclick="ggiAbrirPropuesta('edit', <?= (int)$cot['id'] ?>)" class="btn-ic btn-ic-blue" title="Editar">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <?php endif; ?>
          <?php
            // ── Acciones secundarias → menú contextual (...) ──
            $_is_enviada = ($cot['estado'] === 'enviada');
            $_can_del    = has_role('gerente_general','gerente_operaciones');
            $_has_menu = $_is_enviada || $_can_del;
          ?>
          <?php if ($_has_menu): ?>
          <div class="ggi-row-menu-wrap">
            <button type="button" class="ggi-row-menu-btn" title="Más acciones" aria-label="Más acciones" aria-haspopup="true" aria-expanded="false">
              <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
            </button>
            <div class="ggi-row-menu">
              <?php if ($_is_enviada): ?>
                <button type="button" class="ggi-row-menu-item btn-seguimiento"
                  data-id="<?= $cot['id'] ?>" data-num="<?= sanitize($cot['numero']) ?>" data-empresa="<?= sanitize($cot['nombre_empresa']) ?>">
                  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                  Seguimiento rápido
                </button>
              <?php endif; ?>
              <?php if ($_can_del): ?>
                <?php if ($_is_enviada): ?><div class="ggi-row-menu-sep"></div><?php endif; ?>
                <button type="button" class="ggi-row-menu-item danger"
                  data-del-cot="<?= $cot['id'] ?>"
                  onclick="ggiEliminarCotizacion(<?= $cot['id'] ?>)">
                  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6m4-6v6"/><path d="M9 6V4h6v2"/></svg>
                  Eliminar propuesta
                </button>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($total_rows > 0): ?>
    <?= ggi_pager($total_rows, $page, $limit, [
        'per_page_options' => [10, 20, 30],
        'noun'             => 'propuesta',
        'noun_plural'      => 'propuestas',
    ]) ?>
  <?php endif; ?>
  <?php endif; /* cierra if (empty($cotizaciones)) / else */ ?>
</div>

<script>
function ggiEliminarCotizacion(id) {
  const fd = new FormData();
  fd.append('cot_id', id);
  fd.append('_csrf', document.querySelector('[name="_csrf"]')?.value || '');
  ggiEliminar(
    '<?= APP_URL ?>/modules/cotizaciones/ajax_eliminar.php',
    fd,
    function() {
      const btn = document.querySelector('[data-del-cot="' + id + '"]');
      if (btn) btn.closest('tr')?.remove();
    },
    'Eliminar propuesta',
    'propuesta'
  );
}

ggiReady(() => {
  const p = new URLSearchParams(window.location.search);
  const m = p.get('msg');
  if (m && typeof showToast === 'function') {
    showToast(decodeURIComponent(m), 'ok');
    history.replaceState({}, '', window.location.pathname);
  }

  // ── Estado select (estado-sel custom) ──
  document.addEventListener('estado-sel-change', function(e) {
    if (e.detail.module !== 'cotizaciones') return;
    const d      = e.detail;
    const estado = d.value;
    const prev   = d.prevValue;
    if (estado === prev) return;
    if (estado === 'rechazada') {
      abrirModalRechazo(d.id, estado, d.el, prev, d.prevColor, d.prevLabel);
    } else if (estado === 'aceptada') {
      abrirModalAceptar(d.id, estado, d.el, prev, d.prevColor, d.prevLabel);
    } else {
      enviarEstado(d.id, estado, d.el, prev, '', d.color, d.label);
    }
  });

  // ── Seguimiento rápido ──
  document.querySelectorAll('.btn-seguimiento').forEach(btn => {
    btn.addEventListener('click', function() {
      document.getElementById('seg-cot-id').value   = this.dataset.id;
      document.getElementById('seg-cot-title').textContent = this.dataset.num + ' — ' + this.dataset.empresa;
      document.getElementById('seg-notas').value    = '';
      document.getElementById('seg-tipo').value     = 'llamada';
      document.getElementById('seg-resultado').value= 'sin_respuesta';
      document.getElementById('modal-seguimiento').classList.add('open');
    });
  });
});

async function enviarEstado(cotId, estado, selEl, prev, motivo, color, label) {
  const labelFinal = label || estado;
  try {
    const r    = await fetch('<?= APP_URL ?>/modules/cotizaciones/ajax_estado_cot.php', {
      method:  'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':window.CSRF_TOKEN||''},
      body:    `cot_id=${cotId}&estado=${estado}&rechazo_motivo=${encodeURIComponent(motivo)}`
    });
    const data = await r.json();
    if (data.ok) {
      if (selEl) {
        if (['aceptada','rechazada','vencida'].includes(estado)) {
          /* Estado final → reemplazar con badge estático */
          const badge = document.createElement('span');
          badge.className = 'estado-badge';
          badge.dataset.estado = estado;
          badge.textContent = labelFinal;
          const tr = selEl.closest('tr');
          selEl.replaceWith(badge);
          if (tr) {
            tr.querySelectorAll('[title="Editar"],[title="WhatsApp"],.btn-seguimiento').forEach(el => el.remove());
          }
        } else {
          /* Estado intermedio → actualizar badge interactivo */
          selEl.setAttribute('data-color', color);
          selEl.setAttribute('data-value', estado);
          const lbl = selEl.querySelector('.estado-sel-label');
          if (lbl) lbl.textContent = labelFinal;
          if (typeof window.ggiApplySelectColors === 'function') window.ggiApplySelectColors(selEl.closest('tbody') || document);
        }
      }
      if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
      if (data._proceso_error) {
        console.error('[Propuesta aceptada] Error en creación de procesos:', data._proceso_error);
        if (typeof showToast==='function') showToast('Propuesta aceptada. Revisa la consola si faltan procesos.','ok');
      } else {
        if (typeof showToast==='function') showToast('Estado actualizado.','ok');
      }
    } else {
      /* Revertir la UI al estado anterior */
      if (selEl) {
        selEl.setAttribute('data-value', prev);
        const lbl = selEl.querySelector('.estado-sel-label');
        if (lbl && selEl._prevLabel) lbl.textContent = selEl._prevLabel;
      }
      if (typeof showToast==='function') showToast(data.msg||'Error.','error');
    }
  } catch(e) {
    if (selEl) { selEl.setAttribute('data-value', prev); }
  }
}

// ── Modal rechazo ──
var _rechazo = {};
function abrirModalRechazo(cotId, estado, selEl, prev, prevColor, prevLabel) {
  _rechazo = { cotId, estado, selEl, prev, prevColor, prevLabel };
  if (selEl) selEl._prevLabel = prevLabel;
  document.getElementById('rechazo-motivo-input').value = '';
  document.getElementById('modal-rechazo').classList.add('open');
  document.getElementById('rechazo-motivo-input').focus();
}

// ── Modal aceptar (usando GGIConfirm) ──
function abrirModalAceptar(cotId, estado, selEl, prev, prevColor, prevLabel) {
  GGIConfirm.show({
    title: '¿Aceptar esta propuesta?',
    message: 'Se crearán los certificados en proceso automáticamente. Esta acción no se puede deshacer.',
    icon: 'success',
    confirmText: 'Confirmar aceptación',
    confirmColor: '#10b981',
    onConfirm: () => {
      ejecutarAceptar(cotId, estado, selEl, prev, prevColor, prevLabel);
    }
  });
}

// Event listeners registrados al final del archivo, después de que los modales existan
</script>
<?php if ($_nuevo_id): ?>
<script>
ggiReady(function () {
  var newId  = <?= (int)$_nuevo_id ?>;
  var newNum = <?= json_encode($_nuevo_num) ?>;

  // 1. Scroll suave hacia la fila destacada
  var row = document.getElementById('cot-row-new');
  if (row) {
    setTimeout(function () {
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 120);
  }

  // 2. Toast de confirmación
  if (typeof showToast === 'function' && newNum) {
    setTimeout(function () {
      showToast('Propuesta ' + newNum + ' creada correctamente.', 'ok', 5000);
    }, 300);
  }

  // 3. Limpiar ?nuevo y ?num de la URL sin recargar
  try {
    var u = new URL(window.location.href);
    u.searchParams.delete('nuevo');
    u.searchParams.delete('num');
    history.replaceState({}, '', u.pathname + (u.search !== '?' ? u.search : ''));
  } catch(_) {}
});
</script>
<?php endif; ?>

<?php if (has_role('gerente_general','gerente_operaciones')): ?>
<script>
(function() {
  const BULK_URL = '<?= APP_URL ?>/modules/cotizaciones/ajax_bulk.php';

  // Eliminar barra anterior (limpieza SPA)
  const _oldCotBar = document.getElementById('_bulk-cot');
  if (_oldCotBar) _oldCotBar.remove();

  // Crear barra y adjuntar directamente a body
  const _cotBar = document.createElement('div');
  _cotBar.id = '_bulk-cot';
  _cotBar.className = 'ggi-bulk-bar';
  _cotBar.innerHTML =
    '<span class="ggi-bulk-count" id="_bulk-cot-cnt">0 seleccionadas</span>' +
    '<div class="ggi-bulk-sep"></div>' +
    '<button type="button" class="ggi-bulk-btn ggi-bulk-btn-ok"      onclick="bulkAccion(\'aceptada\')">Aceptar</button>' +
    '<button type="button" class="ggi-bulk-btn ggi-bulk-btn-warn"    onclick="bulkAccion(\'enviada\')">Enviada</button>' +
    '<button type="button" class="ggi-bulk-btn ggi-bulk-btn-err"     onclick="bulkAccion(\'rechazada\')">Rechazar</button>' +
    '<div class="ggi-bulk-sep"></div>' +
    '<button type="button" class="ggi-bulk-btn-cancel" onclick="bulkLimpiar()">Cancelar</button>';
  document.body.appendChild(_cotBar);

  window.addEventListener('spa:before', function _cotBarCleanup() {
    _cotBar.remove();
    window.removeEventListener('spa:before', _cotBarCleanup);
  });

  function getSelectedIds() {
    return [...document.querySelectorAll('.row-chk:checked')].map(c => parseInt(c.value)).filter(v => v > 0);
  }
  function updateBar() {
    const ids = getSelectedIds();
    const cnt = document.getElementById('_bulk-cot-cnt');
    if (cnt) cnt.textContent = ids.length + ' seleccionada' + (ids.length !== 1 ? 's' : '');
    if (ids.length > 0) _cotBar.classList.add('visible');
    else _cotBar.classList.remove('visible');
    document.querySelectorAll('.row-chk').forEach(cb => {
      const tr = cb.closest('tr');
      if (tr) tr.classList.toggle('bulk-selected', cb.checked);
    });
    const chkAll = document.getElementById('chk-all');
    if (chkAll) {
      const boxes = document.querySelectorAll('.row-chk');
      chkAll.checked = boxes.length > 0 && [...boxes].every(c => c.checked);
      chkAll.indeterminate = [...boxes].some(c => c.checked) && !chkAll.checked;
    }
  }
  window.bulkLimpiar = function() {
    document.querySelectorAll('.row-chk').forEach(c => { c.checked = false; });
    const all = document.getElementById('chk-all');
    if (all) { all.checked = false; all.indeterminate = false; }
    document.querySelectorAll('.bulk-selected').forEach(tr => tr.classList.remove('bulk-selected'));
    _cotBar.classList.remove('visible');
  };
  window.bulkAccion = async function(nuevoEstado) {
    const ids = getSelectedIds();
    if (!ids.length) return;
    const labels  = { aceptada:'Aceptar', enviada:'Marcar como enviada', rechazada:'Rechazar' };
    const typeMap = { aceptada:'info', enviada:'info', rechazada:'warning' };
    const ok = await ggiConfirm(
      labels[nuevoEstado] || nuevoEstado,
      `¿${labels[nuevoEstado] || nuevoEstado} las ${ids.length} propuesta(s) seleccionadas?`,
      { type: typeMap[nuevoEstado] || 'warning', confirmText: labels[nuevoEstado] || 'Confirmar' }
    );
    if (!ok) return;
    try {
      const r = await fetch(BULK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ ids, estado: nuevoEstado })
      });
      const d = await r.json();
      if (d.ok) {
        showToast(d.mensaje || `${d.actualizados} propuesta(s) actualizadas.`, 'ok');
        setTimeout(() => location.reload(), 800);
      } else { showToast(d.error || 'Error al procesar.', 'error'); }
    } catch(e) { showToast('Error de conexión.', 'error'); }
  };
  // Wire checkboxes
  const chkAll = document.getElementById('chk-all');
  if (chkAll) {
    chkAll.addEventListener('change', function() {
      document.querySelectorAll('.row-chk').forEach(c => { c.checked = this.checked; });
      updateBar();
    });
  }
  document.querySelectorAll('.row-chk').forEach(cb => {
    cb.addEventListener('change', updateBar);
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/_propuesta_modal.php'; ?>
<script>
// Crear/Editar/Duplicar usan el mismo modal unificado (ver _propuesta_modal.php).
function ggiNuevaPropuesta() { ggiAbrirPropuesta('create'); }
</script>

<!-- Modales fuera de <main> para evitar que position:fixed quede atrapado en el containing block del animation -->
<!-- Modal: Motivo de rechazo -->
<div id="modal-rechazo" class="ggi-modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ggi-modal-box" style="max-width:420px;">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">¿Por qué se rechaza?</h3>
      <button type="button" class="ggi-modal-close" onclick="document.getElementById('modal-rechazo').classList.remove('open')">✕</button>
    </div>
    <div class="ggi-modal-body">
      <p style="margin:0;font-size:13px;color:var(--gris-texto);">El motivo se guardará en el historial de la propuesta.</p>
      <textarea id="rechazo-motivo-input" rows="3" placeholder="Escribe el motivo de rechazo..."
        style="width:100%;padding:10px 13px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;resize:vertical;outline:none;box-sizing:border-box;"></textarea>
    </div>
    <div class="ggi-modal-footer">
      <button id="btn-rechazo-cancelar" type="button" class="ggi-btn-confirm-cancel">Cancelar</button>
      <button id="btn-rechazo-confirmar" type="button" class="ggi-btn-confirm-action" style="background:linear-gradient(135deg, #f87171 0%, #ef4444 100%);">Confirmar rechazo</button>
    </div>
  </div>
</div>

<!-- Modal: Seguimiento rápido -->
<div id="modal-seguimiento" class="ggi-modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ggi-modal-box" style="max-width:460px;">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">Seguimiento rápido</h3>
      <button type="button" class="ggi-modal-close" onclick="document.getElementById('modal-seguimiento').classList.remove('open')">✕</button>
    </div>
    <div class="ggi-modal-body">
      <p id="seg-cot-title" style="margin:0;font-size:12px;color:var(--gris-texto);font-weight:600;"></p>
      <input type="hidden" id="seg-cot-id">
      <div style="display:flex;gap:12px;margin-bottom:14px;">
        <div style="flex:1;">
          <label style="font-size:11px;font-weight:600;color:var(--gris-texto);display:block;margin-bottom:4px;">Tipo</label>
          <select id="seg-tipo" style="width:100%;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;outline:none;background:var(--bg-surface);color:var(--gris-dark);">
            <option value="llamada">Llamada</option>
            <option value="email">Email</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="reunion">Reunión</option>
            <option value="otro">Otro</option>
          </select>
        </div>
        <div style="flex:1;">
          <label style="font-size:11px;font-weight:600;color:var(--gris-texto);display:block;margin-bottom:4px;">Resultado</label>
          <select id="seg-resultado" style="width:100%;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;outline:none;background:var(--bg-surface);color:var(--gris-dark);">
            <option value="sin_respuesta">Sin respuesta</option>
            <option value="interesado">Interesado</option>
            <option value="pidio_tiempo">Pidió tiempo</option>
            <option value="rechazado">Rechazado</option>
            <option value="aceptado">Aceptado</option>
          </select>
        </div>
      </div>
      <div>
        <label style="font-size:11px;font-weight:600;color:var(--azul);display:block;margin-bottom:4px;">Notas <span class="req">*</span></label>
        <textarea id="seg-notas" rows="3" placeholder="¿Qué pasó? ¿Qué respondió el cliente?..."
          style="width:100%;padding:10px 13px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;resize:vertical;outline:none;box-sizing:border-box;"></textarea>
      </div>
    </div>
    <div class="ggi-modal-footer">
      <button id="btn-seg-cancelar" type="button" class="ggi-btn-confirm-cancel">Cancelar</button>
      <button id="btn-seg-guardar" type="button" class="ggi-btn-confirm-action">Guardar seguimiento</button>
    </div>
  </div>
</div>


<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<script>
if (window.ShortcutManager) {
  ShortcutManager.initListNav({
    rowSelector: 'tbody tr[data-cot-id]',
    getLink: function(row) {
      var a = row.querySelector('a.row-link');
      return a ? a.href : null;
    }
  });
}
</script>

<!-- Event listeners para modales (después de que los elementos existan) -->
<script>
ggiReady(() => {
  // Rechazo
  const btnReConfirmar = document.getElementById('btn-rechazo-confirmar');
  if (btnReConfirmar) {
    btnReConfirmar.addEventListener('click', async () => {
      const motivo = document.getElementById('rechazo-motivo-input').value.trim();
      if (!motivo) { document.getElementById('rechazo-motivo-input').style.borderColor='#ef4444'; return; }
      document.getElementById('modal-rechazo').classList.remove('open');
      const ec2 = {rechazada:{label:'Rechazada',color:'#DC2626'}};
      await enviarEstado(_rechazo.cotId, _rechazo.estado, _rechazo.selEl, _rechazo.prev, motivo, ec2.rechazada.color, ec2.rechazada.label);
    });
  }
  const btnReCancelar = document.getElementById('btn-rechazo-cancelar');
  if (btnReCancelar) {
    btnReCancelar.addEventListener('click', () => {
      document.getElementById('modal-rechazo').classList.remove('open');
    });
  }

  // Función para ejecutar aceptación (llamada desde GGIConfirm)
  window.ejecutarAceptar = async (cotId, estado, selEl, prev, prevColor, prevLabel) => {
    const ec2 = {aceptada:{label:'Aceptada',color:'#059669'}};
    await enviarEstado(cotId, estado, selEl, prev, '', ec2.aceptada.color, ec2.aceptada.label);
  };

  // Seguimiento
  const btnSegGuardar = document.getElementById('btn-seg-guardar');
  if (btnSegGuardar) {
    btnSegGuardar.addEventListener('click', async () => {
      const cotId     = document.getElementById('seg-cot-id').value;
      const tipo      = document.getElementById('seg-tipo').value;
      const notas     = document.getElementById('seg-notas').value.trim();
      const resultado = document.getElementById('seg-resultado').value;
      if (!notas) { document.getElementById('seg-notas').style.borderColor='#ef4444'; return; }
      document.getElementById('seg-notas').style.borderColor='';
      const r = await fetch('<?= APP_URL ?>/modules/cotizaciones/ajax_seguimiento.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':window.CSRF_TOKEN||''},
        body: `cotizacion_id=${cotId}&tipo=${tipo}&notas=${encodeURIComponent(notas)}&resultado=${resultado}`
      });
      const data = await r.json();
      document.getElementById('modal-seguimiento').classList.remove('open');
      if (typeof showToast==='function') showToast(data.ok ? 'Seguimiento registrado.' : (data.msg||'Error.'), data.ok ? 'ok' : 'error');
    });
  }
  const btnSegCancelar = document.getElementById('btn-seg-cancelar');
  if (btnSegCancelar) {
    btnSegCancelar.addEventListener('click', () => {
      document.getElementById('modal-seguimiento').classList.remove('open');
    });
  }
});
</script>
