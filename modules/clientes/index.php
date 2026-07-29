<?php
$page_title  = 'Empresas';
$active_menu = 'clientes';
require_once __DIR__ . '/../../templates/layout.php';
$user    = auth_user();

$pdo = Database::get();

// Filtros
$buscar         = trim($_GET['q'] ?? '');
// Multi-selección de ejecutivos y de estado de revisión (filtros ms-drop)
$ejec_sel       = array_values(array_filter(array_map('intval', (array)($_GET['ejecutivo'] ?? [])), fn($v) => $v > 0));
$revision_sel   = array_values(array_filter((array)($_GET['revision'] ?? []), fn($v) => in_array($v, ['pendiente','revisado'], true)));
$factura_pend   = !empty($_GET['factura_pend']);  // solo empresas con factura por emitir
require_once __DIR__ . '/../../core/pendientes.php';
$estado_explicit = array_key_exists('estado', $_GET); // ¿se envió el param explícitamente?
// Multi-selección de estados: default=['activo'] si no se especifica nada
if ($estado_explicit) {
    $_est_raw    = (array)$_GET['estado'];
    $estados_sel = array_values(array_filter($_est_raw, fn($v) => $v !== ''));
    // Si viene vacío explícito (?estado[]=) → mostrar todos
} else {
    $estados_sel = ['activo']; // default: solo activos
}
$_pp_opts    = [10, 20, 30];
$_pp_req     = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$limit       = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $limit;

// ── Paginación server-side offset + COUNT (estándar global, footer ggi_pager) ──
$page = max(1, (int)($_GET['p'] ?? 1));

$where  = ['1=1', 'c.deleted_at IS NULL'];  // Excluir empresas eliminadas
$params = [];

// Ejecutivo_ventas ve clientes donde captó, creó o es el responsable actual
if ($user['rol'] === 'ejecutivo_ventas') {
    $where[]  = '(c.ejecutivo_id = ? OR c.created_by_user_id = ? OR c.responsible_user_id = ?)';
    $params[] = $user['id'];
    $params[] = $user['id'];
    $params[] = $user['id'];
}

if ($buscar) {
    $where[]  = '(c.razon_social LIKE ? OR c.ruc LIKE ? OR c.codigo_interno LIKE ?)';
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
}
if (!empty($estados_sel)) {
    $ph = implode(',', array_fill(0, count($estados_sel), '?'));
    $where[]  = "c.estado IN ($ph)";
    $params   = array_merge($params, $estados_sel);
}
if ($ejec_sel) {
    $ph = implode(',', array_fill(0, count($ejec_sel), '?'));
    $where[]  = "c.ejecutivo_id IN ($ph)";
    $params   = array_merge($params, $ejec_sel);
}
if ($revision_sel && $user['rol'] === 'gerente_general') {
    $ph = implode(',', array_fill(0, count($revision_sel), '?'));
    $where[]  = "c.revision_gerente IN ($ph)";
    $params   = array_merge($params, $revision_sel);
}
// Filtro "con factura pendiente": empresas con propuesta aceptada+pagada sin factura.
// Útil para clientes antiguos que quedan lejos en el listado.
if ($factura_pend) {
    $where[] = gg_sql_factura_pendiente('c.id');
}

$sql_where = implode(' AND ', $where);

// COUNT sin cursor: total de registros con los filtros actuales
$total_s = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE $sql_where");
$total_s->execute($params);
$total_rows = (int)$total_s->fetchColumn();

// Página actual acotada al total real + offset
$total_pages = max(1, (int)ceil($total_rows / $limit));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $limit;

// Las 4 subqueries correlacionadas se reemplazan por LEFT JOINs con subconsultas
// agrupadas: 1 query total en vez de 1 + 4*N queries por página.
$stmt = $pdo->prepare("
    SELECT c.*, u.nombre AS creado_por_nombre,
           ej.nombre AS ejecutivo_nombre, ej.apellido AS ejecutivo_apellido
    FROM clientes c
    JOIN  usuarios u  ON u.id  = c.creado_por
    LEFT JOIN usuarios ej ON ej.id = c.ejecutivo_id
    WHERE $sql_where
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$clientes = $stmt->fetchAll();

// ── Pendientes de atención (sistema unificado) — cálculo en lote para la página ──
require_once __DIR__ . '/../../core/pendientes.php';
$_pend_map = gg_empresas_pendientes_counts($pdo, array_column($clientes, 'id'));

// Lista de ejecutivos para el filtro — solo Asesores Comerciales (ejecutivo_ventas)
$ejecutivos_lista = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol = 'ejecutivo_ventas' ORDER BY nombre")->fetchAll();
?>

<style>
  .toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; max-width:360px; }
  .search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); transition:border-color 0.2s; }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); color:var(--gris-dark); cursor:pointer; }
  .filter-select:focus { border-color:var(--azul); }

  .table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  /* .data-table, .action-btns, .btn-ic, .btn-ic-* → canónicos en design-system.css */
  /* badge-green / badge-amber / badge-gray: definidos globalmente en layout_end.php */
  .portal-on  { background:var(--s-ok-bg); color:var(--s-ok-tx); } .portal-on:hover  { background:#16a34a; color:#fff; }
  .portal-off { background:var(--s-info-bg); color:#94a3b8; } .portal-off:hover { background:#64748b; color:#fff; }
  .cli-portal-btn { position:relative; }
  .cli-portal-btn.loading { opacity:.5; pointer-events:none; }

  /* Paginación: estilos en layout_end.php */

  /* .empty-state base → canónico en design-system.css */
  .empty-state svg { margin-bottom:12px; opacity:0.25; }
  .empty-state h3 { font-size:15px; color:var(--gris-dark); margin-bottom:4px; }

  /* Ocultar iconos/labels que solo aparecen en móvil en desktop */
  .mobile-icon { display:none; }
  .mobile-only { display:none; }

  /* ── Bulk checkbox columns ── */
  .th-chk, .td-chk { width:36px; padding:8px 4px 8px 14px !important; }
  .row-chk, .bulk-chk { width:15px; height:15px; cursor:pointer; accent-color:var(--ac,#1d4ed8); }

  @media (max-width: 768px) {
    .mobile-icon { display:inline-block; }
    .mobile-only { display:block; }

    .data-table thead { display:none; }
    .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
    .data-table tr {
      background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde);
      margin-bottom:10px; padding:14px 16px; position:relative;
    }
    .data-table tr:hover td { background:transparent; }
    .data-table td { padding:5px 0; border-bottom:none; font-size:13px; }

    /* En móvil con layout de tarjeta, ocultar checkbox + fecha */
    .data-table td.td-chk, .data-table td.td-fecha { display:none; }

    /* Botones acción */
    .data-table td:last-child {
      margin-top:10px; padding-top:10px;
      border-top:1px solid var(--gris-borde);
    }
    /* Paginación responsive: en layout_end.php */
    .action-btns { gap:8px; }
    .btn-ic { width:36px; height:36px; }

    /* Toolbar */
    .toolbar { flex-direction:column; align-items:stretch; }
    .toolbar form { flex-direction:column; }
    .search-wrap { max-width:100%; }
    .toolbar > a { width:100%; justify-content:center; }
  }
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Empresas</h1>
    <p><?= $total_rows ?> empresa<?= $total_rows !== 1 ? 's' : '' ?> registrada<?= $total_rows !== 1 ? 's' : '' ?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <?php
      $_cli_exp = 'q='.urlencode($buscar);
      foreach ($ejec_sel as $_ej)     $_cli_exp .= '&ejecutivo[]='.(int)$_ej;
      foreach ($revision_sel as $_rv) $_cli_exp .= '&revision[]='.urlencode($_rv);
      foreach ($estados_sel as $_es)  $_cli_exp .= '&estado[]='.urlencode($_es);
    ?>
    <a href="<?= APP_URL ?>/modules/clientes/exportar.php?<?= $_cli_exp ?>" class="btn btn-outline btn-sm" title="Exportar CSV">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      CSV
    </a>
    <?php if (has_role('gerente_general','gerente_operaciones')): ?>
    <button type="button" class="btn btn-primary" onclick="ggiOpenForm('<?= APP_URL ?>/modules/clientes/crear.php','Nueva empresa')">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nueva empresa
    </button>
    <?php endif; ?>
  </div>
</div>

<?php
// Nº de filtros (de dropdown) activos → badge del botón "Filtros" en móvil
$_nfiltros = 0;
if ($estado_explicit && $estados_sel !== ['activo']) $_nfiltros++;
if (!empty($ejec_sel))     $_nfiltros++;
if (!empty($revision_sel)) $_nfiltros++;
if ($factura_pend)         $_nfiltros++;
?>
<div class="toolbar">
  <form method="GET" class="<?= $_nfiltros ? 'filtros-open' : '' ?>" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar por RUC o razón social..." aria-label="Buscar por RUC o razón social..." value="<?= sanitize($buscar) ?>" oninput="ggiSearch('clientes-q',this,10000)" onkeydown="if(event.key==='Enter'){event.preventDefault();ggiSearch('clientes-q',this,0)}">
    </div>
    <!-- Toggle de filtros (solo móvil) -->
    <button type="button" class="ggi-filtros-toggle" onclick="this.closest('form').classList.toggle('filtros-open')" aria-label="Mostrar u ocultar filtros">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filtros<?php if ($_nfiltros): ?> <span class="ggi-filtros-badge"><?= $_nfiltros ?></span><?php endif; ?>
    </button>
    <div class="ggi-filtros">
    <label class="ggi-chip-check<?= $factura_pend ? ' on' : '' ?>" title="Empresas con propuesta aceptada y pagada que aún no tienen factura emitida">
      <input type="checkbox" name="factura_pend" value="1" <?= $factura_pend ? 'checked' : '' ?> onchange="this.form.submit()" style="display:none">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
      Con factura pendiente
    </label>
    <?php
    $_cli_estados = [
      'activo'    => ['label'=>'Activo',    'cls'=>'is-ok'],
      'inactivo'  => ['label'=>'Inactivo',  'cls'=>'is-muted'],
      'prospecto' => ['label'=>'Prospecto', 'cls'=>'is-warn'],
    ];
    $_cli_lbl = empty($estados_sel) ? 'Todos los estados'
      : implode(', ', array_map(fn($v) => $_cli_estados[$v]['label'] ?? $v, $estados_sel));
    ?>
    <div class="ms-drop" id="ms-cli-estado">
      <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('ms-cli-estado')" aria-haspopup="listbox" aria-label="Filtrar por estado">
        <span class="ms-drop-lbl <?= empty($estados_sel)?'is-placeholder':'' ?>"><?= htmlspecialchars($_cli_lbl) ?></span>
        <?php if (!empty($estados_sel) && $estados_sel !== ['activo']): ?><span class="ms-drop-count"><?= count($estados_sel) ?></span><?php endif; ?>
        <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="ms-drop-menu" hidden>
        <?php foreach ($_cli_estados as $_val => $_cfg): ?>
        <label class="ms-drop-item">
          <input type="checkbox" name="estado[]" value="<?= $_val ?>"
                 <?= in_array($_val, $estados_sel) ? 'checked' : '' ?>
                 onchange="msDrop.change('ms-cli-estado', this)">
          <span class="ms-drop-dot <?= $_cfg['cls'] ?>"></span>
          <span><?= $_cfg['label'] ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (in_array($user['rol'], ['gerente_general','gerente_operaciones'])): ?>
    <?php $_ej_lbl = empty($ejec_sel) ? 'Todos los ejecutivos'
          : implode(', ', array_map(function($ej) { return $ej['nombre'].' '.$ej['apellido']; },
              array_filter($ejecutivos_lista, fn($e) => in_array((int)$e['id'], $ejec_sel, true)))); ?>
    <div class="ms-drop" id="ms-cli-ejec">
      <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('ms-cli-ejec')" aria-haspopup="listbox" aria-label="Filtrar por ejecutivo">
        <span class="ms-drop-lbl <?= empty($ejec_sel)?'is-placeholder':'' ?>" data-placeholder="Todos los ejecutivos"><?= htmlspecialchars($_ej_lbl) ?></span>
        <?php if (!empty($ejec_sel)): ?><span class="ms-drop-count"><?= count($ejec_sel) ?></span><?php endif; ?>
        <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="ms-drop-menu" hidden>
        <?php foreach ($ejecutivos_lista as $ej): ?>
        <label class="ms-drop-item">
          <input type="checkbox" name="ejecutivo[]" value="<?= $ej['id'] ?>"
                 <?= in_array((int)$ej['id'], $ejec_sel, true) ? 'checked' : '' ?>
                 onchange="msDrop.change('ms-cli-ejec', this)">
          <span><?= sanitize($ej['nombre'] . ' ' . $ej['apellido']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($user['rol'] === 'gerente_general'): ?>
    <?php
      $pendientes_revision = (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE revision_gerente='pendiente' AND deleted_at IS NULL AND estado NOT IN ('prospecto','inactivo')")->fetchColumn();
      $_rev_cfg = [
        'pendiente' => ['label' => 'Pendiente revisión' . ($pendientes_revision ? ' ('.$pendientes_revision.')' : ''), 'cls' => 'is-warn'],
        'revisado'  => ['label' => 'Revisados', 'cls' => 'is-ok'],
      ];
      $_rev_lbl = empty($revision_sel) ? 'Todos los clientes'
          : implode(', ', array_map(fn($v) => $_rev_cfg[$v]['label'] ?? $v, $revision_sel));
    ?>
    <div class="ms-drop" id="ms-cli-rev">
      <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('ms-cli-rev')" aria-haspopup="listbox" aria-label="Filtrar por revisión">
        <span class="ms-drop-lbl <?= empty($revision_sel)?'is-placeholder':'' ?>" data-placeholder="Todos los clientes"><?= htmlspecialchars($_rev_lbl) ?></span>
        <?php if (!empty($revision_sel)): ?><span class="ms-drop-count"><?= count($revision_sel) ?></span><?php endif; ?>
        <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="ms-drop-menu" hidden>
        <?php foreach ($_rev_cfg as $_val => $_cfg): ?>
        <label class="ms-drop-item">
          <input type="checkbox" name="revision[]" value="<?= $_val ?>"
                 <?= in_array($_val, $revision_sel, true) ? 'checked' : '' ?>
                 onchange="msDrop.change('ms-cli-rev', this)">
          <span class="ms-drop-dot <?= $_cfg['cls'] ?>"></span>
          <span><?= $_cfg['label'] ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    </div><!-- /.ggi-filtros -->
    <?php
    // Mostrar Limpiar solo cuando hay algo distinto al estado por defecto (activo)
    $hay_filtros = $buscar || $ejec_sel || $revision_sel
        || ($estado_explicit && $estados_sel !== ['activo'])
        || ($estado_explicit && empty($estados_sel));
    ?>
    <?php if ($hay_filtros): ?>
      <a href="<?= APP_URL ?>/modules/clientes/index.php" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($clientes)): ?>
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    <h3><?= $buscar ? 'Sin resultados para "'.$buscar.'"' : 'Aún no hay empresas registradas' ?></h3>
    <p style="margin-top:4px;font-size:13px;">
      <?= $buscar ? 'Prueba con otro término de búsqueda.' : 'Comienza registrando la primera empresa.' ?>
    </p>
    <?php if (!$buscar): ?>
    <button type="button" class="btn btn-primary" style="margin-top:16px;" onclick="ggiOpenForm('<?= APP_URL ?>/modules/clientes/crear.php','Nueva empresa')">Registrar primera empresa</button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <?php if (has_role('gerente_general','gerente_operaciones')): ?>
        <th scope="col" class="th-chk"><input type="checkbox" id="chk-all-cli" class="bulk-chk" title="Seleccionar todo"></th>
        <?php endif; ?>
        <th scope="col">Empresa</th>
        <th scope="col">Ejecutivo</th>
        <th scope="col" style="text-align:center;">Estado</th>
        <th scope="col">Registrado</th>
        <th scope="col">Acciones</th>
      </tr>
    </thead>
    <?php $puede_portal = has_role('gerente_general','gerente_operaciones','ejecutivo_ventas'); ?>
    <tbody>
      <?php foreach ($clientes as $c): ?>
      <?php
        // Colores alineados al design system global
        $estado_color_map = ['activo' => '#059669', 'inactivo' => '#6B7280', 'prospecto' => '#D97706'];
        $estado_label_map = ['activo' => 'Activo', 'inactivo' => 'Inactivo', 'prospecto' => 'Prospecto'];
        $estado_color = $estado_color_map[$c['estado']] ?? '#6B7280';
        $estado_label = $estado_label_map[$c['estado']] ?? ucfirst($c['estado']);
      ?>
      <?php $_pend = gg_pendientes_build($_pend_map[$c['id']] ?? []); ?>
      <tr>
        <?php if (has_role('gerente_general','gerente_operaciones')): ?>
        <td class="td-chk"><input type="checkbox" class="row-chk" aria-label="Seleccionar fila" value="<?= $c['id'] ?>"></td>
        <?php endif; ?>
        <td>
          <div class="av-row">
            <span class="av-chip" data-name="<?= sanitize($c['razon_social']) ?>"></span>
            <div class="av-name">
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <a class="row-link" href="ver.php?id=<?= $c['id'] ?>"><span class="txt-ellipsis" title="<?= sanitize($c['razon_social']) ?>"><?= sanitize($c['razon_social']) ?></span></a>
                <span style="font-size:11px;color:var(--gris-texto);font-family:monospace;font-weight:500;flex-shrink:0;"><?= ggi_copy($c['ruc']) ?></span>
                <?php if ($user['rol'] === 'gerente_general' && $c['revision_gerente'] === 'pendiente' && !in_array($c['estado'], ['prospecto','inactivo'])): ?>
                <span title="Pendiente de revisión por el gerente" style="display:inline-flex;align-items:center;gap:3px;background:var(--s-warn-bg);border:1px solid #fcd34d;color:var(--s-warn-tx);border-radius:100px;font-size:10px;font-weight:700;padding:2px 7px;cursor:default;white-space:nowrap;flex-shrink:0;">
                  <svg width="9" height="9" viewBox="0 0 8 8" fill="#f59e0b"><circle cx="4" cy="4" r="4"/></svg>
                  Por revisar
                </span>
                <?php endif; ?>
                <?php if ($_pend['total'] > 0): ?>
                <button type="button" class="ggi-pend" data-pop-hover
                        aria-label="<?= $_pend['total'] ?> pendiente<?= $_pend['total'] !== 1 ? 's' : '' ?> de atención. Ver detalle"
                        data-ggipop="<?= htmlspecialchars(gg_pendientes_popover_html($_pend), ENT_QUOTES) ?>"
                        onclick="ggiChipPop(this)"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();ggiChipPop(this);}">
                  <svg class="ggi-pend-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><?= $_pend['total'] ?>
                </button>
                <?php endif; ?>
              </div>
              <?php if ($c['distrito']): ?>
              <div style="font-size:11px;color:var(--gris-texto);"><?= sanitize($c['distrito'].', '.$c['departamento']) ?></div>
              <?php endif; ?>
              <?php if (!empty($c['notas'])): ?>
              <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;font-style:italic;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($c['notas']) ?>">
                <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:2px;flex-shrink:0;opacity:.6"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?= sanitize($c['notas']) ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </td>

        <td>
          <?php if ($c['ejecutivo_nombre']): ?>
          <span style="font-size:12px;font-weight:600;color:var(--gris-dark);display:inline-flex;align-items:center;gap:4px;">
            <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;opacity:0.7"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= sanitize($c['ejecutivo_nombre'] . ' ' . $c['ejecutivo_apellido']) ?>
          </span>
          <?php else: ?>
          <span style="color:var(--gris-texto);font-size:12px;">—</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;">
          <?php /* colores via estado_color_map y badge_clase() */ ?>
          <?php if (has_role('gerente_general','gerente_operaciones')): ?>
          <div class="estado-sel"
               data-id="<?= $c['id'] ?>"
               data-value="<?= htmlspecialchars($c['estado']) ?>"
               data-color="<?= htmlspecialchars($estado_color) ?>"
               data-module="clientes"
               role="button" tabindex="0">
            <span class="estado-sel-label"><?= $estado_label ?></span>
            <div class="estado-sel-menu" hidden>
              <div class="estado-sel-option" data-value="activo"    data-color="#059669" style="--opt-color:#059669">Activo</div>
              <div class="estado-sel-option" data-value="prospecto" data-color="#D97706" style="--opt-color:#D97706">Prospecto</div>
              <div class="estado-sel-option" data-value="inactivo"  data-color="#6B7280" style="--opt-color:#6B7280">Inactivo</div>
            </div>
          </div>
          <?php else: ?>
          <span class="<?= badge_clase($c['estado']) ?>"><?= $estado_label ?></span>
          <?php endif; ?>
        </td>
        <td class="td-fecha">
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" stroke-width="2" style="flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span style="font-size:12px;color:var(--gris-texto);"><?= formato_fecha($c['created_at']) ?></span>
          </div>
        </td>
        <td>
          <div class="action-btns">
            <a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $c['id'] ?>" class="btn-ic btn-ic-blue" title="Ver detalle">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <?php if (can('clientes')): ?>
            <button type="button" class="btn-ic btn-ic-blue" title="Editar empresa"
                    onclick="event.stopPropagation(); ggiAbrirEmpresa('edit', <?= (int)$c['id'] ?>)">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <?php endif; ?>
            <?php if ($puede_portal): ?>
            <a href="<?= APP_URL ?>/modules/clientes/portal_link.php?id=<?= $c['id'] ?>"
              target="_blank" rel="noopener"
              class="btn-ic <?= $c['portal_activo'] ? 'portal-on' : 'portal-off' ?>"
              title="Abrir portal del cliente">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </a>
            <?php endif; ?>
            <?php if (has_role('gerente_general')): ?>
            <div class="ggi-row-menu-wrap">
              <button type="button" class="ggi-row-menu-btn" title="Más acciones" aria-label="Más acciones" aria-haspopup="true" aria-expanded="false">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
              </button>
              <div class="ggi-row-menu">
                <button type="button" class="ggi-row-menu-item danger"
                  data-del-cli="<?= $c['id'] ?>"
                  onclick="ggiEliminarCliente(<?= $c['id'] ?>)">
                  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6m4-6v6"/><path d="M9 6V4h6v2"/></svg>
                  Eliminar empresa
                </button>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($total_rows > 0): ?>
    <?= ggi_pager($total_rows, $page, $limit, [
        'per_page_options' => [10, 20, 30],
        'noun'             => 'empresa',
        'noun_plural'      => 'empresas',
    ]) ?>
  <?php endif; ?>
  <?php endif; /* cierra if (empty($clientes)) / else */ ?>
</div>

<script>
function ggiEliminarCliente(id) {
  const fd = new FormData();
  fd.append('cliente_id', id);
  fd.append('_csrf', document.querySelector('[name="_csrf"]')?.value || '');
  ggiEliminar(
    '<?= APP_URL ?>/modules/clientes/ajax_eliminar.php',
    fd,
    function() {
      const btn = document.querySelector('[data-del-cli="' + id + '"]');
      if (btn) btn.closest('tr')?.remove();
    },
    'Eliminar empresa',
    'empresa'
  );
}

(function() {
  function initClientesUI() {
    // ── Estado inline (estado-sel) ──
    document.addEventListener('estado-sel-change', async function(e) {
      if (e.detail.module !== 'clientes') return;
      const d = e.detail;
      try {
        const data = await ggiPost('<?= APP_URL ?>/modules/clientes/ajax_estado_cliente.php',
                                   { cliente_id: d.id, estado: d.value }, { silent: true });
        if (data.ok) {
          d.el.setAttribute('data-color', d.color);
          d.el.setAttribute('data-value', d.value);
          const lbl = d.el.querySelector('.estado-sel-label');
          if (lbl) lbl.textContent = d.label;
          if (typeof window.ggiApplySelectColors === 'function') window.ggiApplySelectColors(d.el.closest('tbody') || document);
          if (typeof showToast==='function') showToast('Estado actualizado.','ok');
          if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
        } else {
          if (typeof showToast==='function') showToast(data.msg||'Error.','error');
        }
      } catch(e) { /* connection error */ }
    });

    // ── Portal toggle inline ──
    document.querySelectorAll('.cli-portal-btn').forEach(function(btn) {
      if (btn.__ggiInit) return;
      btn.__ggiInit = true;
      btn.addEventListener('click', function() {
        const clienteId = this.dataset.id;
        const activo    = this.dataset.activo === '1';
        const btnEl     = this;

        if (activo) {
          GGIConfirm.show({
            icon: 'warning',
            title: 'Desactivar portal',
            message: '¿Desactivar el portal de este cliente? El enlace dejará de funcionar.',
            confirmText: 'Desactivar',
            onConfirm: () => executePortalToggle(clienteId, btnEl)
          });
          return;
        }

        executePortalToggle(clienteId, btnEl);
      });
    });

    async function executePortalToggle(clienteId, btnEl) {
      const activo = btnEl.dataset.activo === '1';
      const accion = activo ? 'desactivar' : 'activar';
      btnEl.classList.add('loading');

      // ggiPost: adjunta el _csrf, absorbe su rotación y avisa si falla
      try {
        const data = await ggiPost('<?= APP_URL ?>/modules/clientes/portal_toggle.php',
                                   { cliente_id: clienteId, accion: accion });
        if (data.ok) {
          const nuevoActivo = !!data.portal_activo;
          btnEl.dataset.activo = nuevoActivo ? '1' : '0';
          btnEl.title = nuevoActivo
            ? 'Portal activo — clic para desactivar'
            : 'Portal inactivo — clic para activar';
          btnEl.className = 'btn-ic cli-portal-btn ' + (nuevoActivo ? 'portal-on' : 'portal-off');
          if (typeof showToast === 'function') showToast(data.msg, 'ok');
        }
      } finally {
        btnEl.classList.remove('loading');
      }
    }
  }

  <?php if (has_role('gerente_general','gerente_operaciones')): ?>
  // ── Bulk actions — bar appended to body (outside <main>) ──
  var CLI_BULK_URL = '<?= APP_URL ?>/modules/clientes/ajax_bulk.php';

  // Eliminar barra anterior (limpieza SPA)
  var _oldCliBar = document.getElementById('_bulk-cli');
  if (_oldCliBar) _oldCliBar.remove();

  var _cliBar = document.createElement('div');
  _cliBar.id = '_bulk-cli';
  _cliBar.className = 'ggi-bulk-bar';
  _cliBar.innerHTML =
    '<span class="ggi-bulk-count" id="_bulk-cli-cnt">0 seleccionadas</span>' +
    '<div class="ggi-bulk-sep"></div>' +
    '<button class="ggi-bulk-btn ggi-bulk-btn-ok"      onclick="bulkCliAccion(\'activo\')">Activo</button>' +
    '<button class="ggi-bulk-btn ggi-bulk-btn-warn"    onclick="bulkCliAccion(\'prospecto\')">Prospecto</button>' +
    '<button class="ggi-bulk-btn ggi-bulk-btn-neutral" onclick="bulkCliAccion(\'inactivo\')">Inactivo</button>' +
    '<div class="ggi-bulk-sep"></div>' +
    '<button class="ggi-bulk-btn-cancel" onclick="bulkCliLimpiar()">Cancelar</button>';
  document.body.appendChild(_cliBar);

  window.addEventListener('spa:before', function _cliBarCleanup() {
    _cliBar.remove();
    window.removeEventListener('spa:before', _cliBarCleanup);
  });

  function _cliGetIds() {
    return [...document.querySelectorAll('.row-chk:checked')].map(c => +c.value).filter(v => v > 0);
  }
  function _cliUpdateBar() {
    var ids = _cliGetIds();
    var cnt = document.getElementById('_bulk-cli-cnt');
    if (cnt) cnt.textContent = ids.length + ' seleccionada' + (ids.length !== 1 ? 's' : '');
    if (ids.length > 0) _cliBar.classList.add('visible');
    else _cliBar.classList.remove('visible');
    document.querySelectorAll('.row-chk').forEach(function(cb) {
      var tr = cb.closest('tr');
      if (tr) tr.classList.toggle('bulk-selected', cb.checked);
    });
    var all = document.getElementById('chk-all-cli');
    if (all) {
      var total = document.querySelectorAll('.row-chk').length;
      var checked = document.querySelectorAll('.row-chk:checked').length;
      all.checked = total > 0 && checked === total;
      all.indeterminate = checked > 0 && checked < total;
    }
  }
  window.bulkCliLimpiar = function() {
    document.querySelectorAll('.row-chk').forEach(function(c) { c.checked = false; });
    var all = document.getElementById('chk-all-cli');
    if (all) { all.checked = false; all.indeterminate = false; }
    document.querySelectorAll('.bulk-selected').forEach(function(tr) { tr.classList.remove('bulk-selected'); });
    _cliBar.classList.remove('visible');
  };
  window.bulkCliAccion = function(nuevoEstado) {
    var ids = _cliGetIds();
    if (!ids.length) return;
    var labels = { activo:'Marcar activas', prospecto:'Marcar como prospectos', inactivo:'Inactivar' };
    var title = labels[nuevoEstado] || nuevoEstado;
    var message = '¿' + title + ' las ' + ids.length + ' empresa(s) seleccionadas?';
    var icon = nuevoEstado === 'inactivo' ? 'warning' : 'info';

    GGIConfirm.show({
      icon: icon,
      title: title,
      message: message,
      confirmText: labels[nuevoEstado] || 'Confirmar',
      onConfirm: () => executeBulkCliAction(ids, nuevoEstado)
    });
  };

  async function executeBulkCliAction(ids, nuevoEstado) {
    try {
      var r = await fetch(CLI_BULK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ ids: ids, estado: nuevoEstado })
      });
      var d = await r.json();
      if (d.ok) {
        showToast(d.mensaje || d.actualizados + ' empresa(s) actualizadas.', 'ok');
        setTimeout(function() { location.reload(); }, 800);
      } else { showToast(d.error || 'Error al procesar.', 'error'); }
    } catch(e) { showToast('Error de conexión.', 'error'); }
  }
  document.querySelectorAll('.row-chk').forEach(function(chk) {
    chk.addEventListener('change', _cliUpdateBar);
  });
  var _allChk = document.getElementById('chk-all-cli');
  if (_allChk) {
    _allChk.addEventListener('change', function() {
      var me = this;
      document.querySelectorAll('.row-chk').forEach(function(c) { c.checked = me.checked; });
      _cliUpdateBar();
    });
  }
  <?php endif; ?>

  // Funciona en carga directa Y en navegación SPA
  ggiReady(initClientesUI);
})();
</script>
<?php include __DIR__ . '/_empresa_modal.php'; ?>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<script>
if (window.ShortcutManager) {
  ShortcutManager.initListNav({
    getLink: function(row) {
      var a = row.querySelector('a.row-link');
      return a ? a.href : null;
    }
  });
}
</script>
