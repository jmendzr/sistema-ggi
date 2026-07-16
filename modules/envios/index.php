<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gestor_logistico','delivery');

$pdo    = Database::get();
$buscar      = trim($_GET['q'] ?? '');
// Gestor: multiselect; el valor especial 'sin' = sin asignar
$_g_raw      = array_map('strval', (array)($_GET['gestor'] ?? []));
$gestor_ids  = array_values(array_filter(array_map('intval', $_g_raw), fn($v) => $v > 0));
$sin_gestor  = in_array('sin', $_g_raw, true);
$agencia_sel = array_values(array_filter(array_map('intval', (array)($_GET['agencia'] ?? [])), fn($v) => $v > 0));
// Hay algún filtro de gestor activo (ids o sin asignar)
$gestor_activo = $gestor_ids || $sin_gestor;

// Multi-selección de estados: acepta ?estado[]=1&estado[]=3 O legado ?estado=1
$_est_raw    = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$estados_sel = array_values(array_filter(array_map('intval', $_est_raw)));
// $estados_sel = array de IDs enteros de estados_envio
$page       = max(1, (int)($_GET['page'] ?? 1));
$_pp_opts   = [10, 20, 30];
$_pp_req    = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$limit      = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $limit;
$offset     = ($page - 1) * $limit;

$where  = ['1=1', 'e.deleted_at IS NULL'];  // Excluir envíos eliminados
$params = [];

// Ejecutivo de ventas: sus envíos propios O envíos de clientes donde es ejecutivo,
// responsable actual o captador original.
if ($user['rol'] === 'ejecutivo_ventas') {
    $where[]  = '(e.ejecutivo_id = ? OR cl.ejecutivo_id = ? OR cl.responsible_user_id = ? OR cl.created_by_user_id = ?)';
    $params[] = $user['id'];
    $params[] = $user['id'];
    $params[] = $user['id'];
    $params[] = $user['id'];
}

// Gestor logístico: solo sus envíos + sin gestor asignado (estados no finales)
if ($user['rol'] === 'gestor_logistico') {
    $where[]  = '(e.agente_id = ? OR (e.agente_id IS NULL AND e.estado_envio_id NOT IN (5,6)))';
    $params[] = $user['id'];
}

if ($buscar) {
    $where[]  = '(cl.razon_social LIKE ? OR e.codigo_envio LIKE ? OR e.destinatario LIKE ?)';
    $params   = array_merge($params, ["%$buscar%", "%$buscar%", "%$buscar%"]);
}
if (!empty($estados_sel)) {
    $ph = implode(',', array_fill(0, count($estados_sel), '?'));
    $where[]  = "e.estado_envio_id IN ($ph)";
    $params   = array_merge($params, $estados_sel);
}
// Gestor: combinar ids + "sin asignar" en un grupo OR
$_gestor_conds = [];
if ($gestor_ids) {
    $ph = implode(',', array_fill(0, count($gestor_ids), '?'));
    $_gestor_conds[] = "e.agente_id IN ($ph)";
    $params = array_merge($params, $gestor_ids);
}
if ($sin_gestor) {
    $_gestor_conds[] = '(e.agente_id IS NULL AND e.estado_envio_id NOT IN (5,6))';
}
if ($_gestor_conds) {
    $where[] = '(' . implode(' OR ', $_gestor_conds) . ')';
}
if ($agencia_sel) {
    $ph = implode(',', array_fill(0, count($agencia_sel), '?'));
    $where[]  = "e.agencia_envio_id IN ($ph)";
    $params   = array_merge($params, $agencia_sel);
}
$sql_where = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM envios e LEFT JOIN clientes cl ON cl.id=e.cliente_id WHERE $sql_where");
$total->execute($params);
$total_rows  = $total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stmt = $pdo->prepare("
    SELECT e.*, cl.razon_social, cl.ruc,
           cl.contacto_telefono,
           COALESCE(ag.nombre, 'Por asignar') AS agencia,
           es.nombre AS estado_nombre, es.color_hex,
           agt.nombre AS agente_nombre, agt.apellido AS agente_apellido
    FROM envios e
    JOIN clientes cl          ON cl.id = e.cliente_id
    LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    JOIN estados_envio es     ON es.id = e.estado_envio_id
    LEFT JOIN usuarios agt    ON agt.id = e.agente_id
    WHERE $sql_where
    ORDER BY COALESCE(e.cotizacion_id, 0) DESC, e.id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$envios  = $stmt->fetchAll();
$estados  = $pdo->query("SELECT * FROM estados_envio WHERE activo=1 ORDER BY orden")->fetchAll();
$gestores  = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='gestor_logistico' ORDER BY nombre")->fetchAll();
$agencias  = $pdo->query("SELECT id, nombre FROM agencias_envio WHERE activo=1 ORDER BY nombre")->fetchAll();
// Index estados by id for order lookup
$estados_by_id = [];
foreach ($estados as $est) { $estados_by_id[$est['id']] = $est; }

// Cargar certificados de todos los envíos en una sola query
$envio_ids = array_column($envios, 'id');
$certs_map = [];
if (!empty($envio_ids)) {
    $placeholders = implode(',', array_fill(0, count($envio_ids), '?'));
    $certs_q = $pdo->prepare("
        SELECT ec.envio_id, n.codigo AS norma_codigo, c.numero_certificado
        FROM envios_certificados ec
        JOIN certificados c ON c.id = ec.certificado_id
        JOIN normas_iso n   ON n.id = c.norma_iso_id
        WHERE ec.envio_id IN ($placeholders)
        ORDER BY n.codigo
    ");
    $certs_q->execute($envio_ids);
    foreach ($certs_q->fetchAll() as $row) {
        $certs_map[$row['envio_id']][] = $row;
    }
}

$page_title  = 'Envíos';
$active_menu = 'envios';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .toolbar { display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; max-width:360px; }
  .search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; outline:none; background:var(--bg-surface); cursor:pointer; }
  .table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  /* .data-table base, .btn-ic, .btn-ic-blue, .empty-state → canónicos en design-system.css */
  /* Columna Norma: ancho compacto en desktop (específico del módulo) */
  .data-table td:nth-child(2) { max-width:140px; }
  /* Tags de normas: helper global ggi_tags() + .ggi-tag* (design-system.css) */
  /* estado-sel: global en layout_end.php */
  /* env-estado-chip: estilos globales en layout_end.php */
  .env-sin-asignar {
    background:var(--s-err-bg); color:#ef4444; border:1px solid var(--s-err-brd);
    padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600;
    white-space:nowrap; display:inline-block;
  }
  /* Dark mode env-estado-chip: global en layout_end.php */
  :root[data-tema="oscuro"] .env-sin-asignar { background:#450a0a !important; color:#fca5a5 !important; border-color:#7f1d1d !important; }
  /* Paginación: estilos en layout_end.php */
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Envíos</h1>
    <p><?= $total_rows ?> envío<?= $total_rows!=1?'s':'' ?> registrado<?= $total_rows!=1?'s':'' ?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <a href="<?= APP_URL ?>/modules/envios/dashboard.php" class="btn btn-outline btn-sm" title="Dashboard métricas">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>
    <a href="<?= APP_URL ?>/modules/envios/kanban.php<?= ($buscar||!empty($estados_sel)||$gestor_activo) ? '?q='.urlencode($buscar) : '' ?>" class="btn btn-outline btn-sm" title="Vista Kanban">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="11" rx="1"/><rect x="17" y="3" width="5" height="15" rx="1"/></svg>
      Kanban
    </a>
    <?php $_exp_env = 'modulo=envios&q='.urlencode($buscar); foreach ($estados_sel as $_es) $_exp_env .= '&estado[]='.urlencode($_es); ?>
    <a href="<?= APP_URL ?>/modules/exportar.php?<?= $_exp_env ?>"
       class="btn btn-outline btn-sm" title="Exportar a CSV/Excel">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar
    </a>
    <?php if (has_role('gerente_general','gerente_operaciones','gestor_logistico','delivery')):
      $_exp_c = 'q='.urlencode($buscar); foreach ($estados_sel as $_es) $_exp_c .= '&estado[]='.urlencode($_es); ?>
    <a href="<?= APP_URL ?>/modules/envios/exportar_courier.php?courier=olva&descargar=1&<?= $_exp_c ?>"
       class="btn btn-outline btn-sm" title="Exportar formato de carga Olva">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      Olva
    </a>
    <a href="<?= APP_URL ?>/modules/envios/exportar_courier.php?courier=shalom&descargar=1&<?= $_exp_c ?>"
       class="btn btn-outline btn-sm" title="Exportar formato de carga Shalom">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      Shalom
    </a>
    <?php endif; ?>
    <?php if (has_role('gerente_general','gerente_operaciones','gestor_logistico','delivery')): ?>
    <button type="button" class="btn btn-primary" onclick="ggiOpenForm('<?= APP_URL ?>/modules/envios/crear.php','Registrar envío')">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Registrar envío
    </button>
    <?php endif; ?>
  </div>
</div>

<?php $_nfiltros = (!empty($estados_sel) ? 1 : 0) + (!empty($gestor_activo) ? 1 : 0) + (!empty($agencia_sel) ? 1 : 0); ?>
<div class="toolbar">
  <form method="GET" class="<?= $_nfiltros ? 'filtros-open' : '' ?>" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar por cliente, código de envío..." aria-label="Buscar por cliente, código de envío..." value="<?= sanitize($buscar) ?>" oninput="ggiSearch('envios-q',this,10000)" onkeydown="if(event.key==='Enter'){event.preventDefault();ggiSearch('envios-q',this,0)}">
    </div>
    <button type="button" class="ggi-filtros-toggle" onclick="this.closest('form').classList.toggle('filtros-open')" aria-label="Mostrar u ocultar filtros">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filtros<?php if ($_nfiltros): ?> <span class="ggi-filtros-badge"><?= $_nfiltros ?></span><?php endif; ?>
    </button>
    <div class="ggi-filtros">
    <?php
    $_env_lbl = empty($estados_sel) ? 'Todos los estados'
      : (count($estados_sel) <= 2
          ? implode(', ', array_map(fn($id) => $estados_by_id[$id]['nombre'] ?? '#'.$id, $estados_sel))
          : implode(', ', array_map(fn($id) => $estados_by_id[$id]['nombre'] ?? '#'.$id, array_slice($estados_sel,0,2))).' +'.(count($estados_sel)-2).' más');
    ?>
    <div class="ms-drop" id="ms-env-estado">
      <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('ms-env-estado')" aria-haspopup="listbox" aria-label="Filtrar por estado">
        <span class="ms-drop-lbl <?= empty($estados_sel)?'is-placeholder':'' ?>"><?= htmlspecialchars($_env_lbl) ?></span>
        <?php if (!empty($estados_sel)): ?><span class="ms-drop-count"><?= count($estados_sel) ?></span><?php endif; ?>
        <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="ms-drop-menu" hidden id="ms-env-estado-menu">
        <?php foreach ($estados as $est): ?>
        <label class="ms-drop-item">
          <input type="checkbox" name="estado[]" value="<?= $est['id'] ?>"
                 <?= in_array($est['id'], $estados_sel) ? 'checked' : '' ?>
                 onchange="msDrop.change('ms-env-estado', this)">
          <span class="ms-drop-dot" style="background:<?= htmlspecialchars($est['color_hex']) ?>"></span>
          <span><?= sanitize($est['nombre']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($user['rol'] !== 'gestor_logistico'): ?>
    <?php
      $_gestor_opts = ['sin' => ['label' => 'Sin asignar', 'cls' => 'is-err']];
      foreach ($gestores as $g) $_gestor_opts[(string)$g['id']] = $g['nombre'] . ' ' . $g['apellido'];
      $_gestor_sel = $gestor_ids; if ($sin_gestor) $_gestor_sel[] = 'sin';
      echo ggi_msdrop('ms-env-gestor', 'gestor', $_gestor_opts, $_gestor_sel, ['placeholder' => 'Todos los gestores', 'aria' => 'Filtrar por gestor']);
    ?>
    <?php endif; ?>
    <?php
      $_ag_opts = [];
      foreach ($agencias as $ag) $_ag_opts[(string)$ag['id']] = $ag['nombre'];
      echo ggi_msdrop('ms-env-agencia', 'agencia', $_ag_opts, $agencia_sel, ['placeholder' => 'Todas las agencias', 'aria' => 'Filtrar por agencia']);
    ?>
    </div><!-- /.ggi-filtros -->
    <?php if ($buscar || !empty($estados_sel) || $gestor_activo || $agencia_sel): ?>
    <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($envios)): ?>
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:0.25;"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
    <h3 style="font-size:15px;color:var(--gris-dark);margin-bottom:4px;"><?= ($buscar||!empty($estados_sel)||$gestor_activo||$agencia_sel)?'Sin resultados':'Aún no hay envíos' ?></h3>
    <p style="font-size:13px;"><?= ($buscar||!empty($estados_sel)||$gestor_activo||$agencia_sel)?'Prueba con otros filtros.':'Registra el primer envío.' ?></p>
    <?php if ($buscar || !empty($estados_sel) || $gestor_activo || $agencia_sel): ?>
    <a href="<?= APP_URL ?>/modules/envios/index.php" class="btn btn-outline" style="margin-top:16px;">Limpiar filtros</a>
    <?php elseif (has_role('gerente_general','gerente_operaciones','gestor_logistico','delivery')): ?>
    <button type="button" class="btn btn-primary" style="margin-top:16px;" onclick="ggiOpenForm('<?= APP_URL ?>/modules/envios/crear.php','Registrar envío')">Registrar envío</button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="table-scroll-wrap" style="overflow-x:auto;">
  <table class="data-table" style="min-width:700px;">
    <thead>
      <tr>
        <th scope="col">Empresa</th>
        <th scope="col">Norma</th>
        <th scope="col">Agencia / Código</th>
        <th scope="col">Destino</th>
        <th scope="col">Receptor</th>
        <th scope="col">Gestor</th>
        <th scope="col" style="text-align:center;">Estado</th>
        <th scope="col">Fecha</th>
        <th scope="col" style="width:70px;">Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($envios as $e): $certs = $certs_map[$e['id']] ?? []; ?>
      <tr>
        <td>
          <div class="av-row">
            <span class="av-chip" data-name="<?= sanitize($e['razon_social']) ?>"></span>
            <div class="av-name">
              <a class="row-link" href="ver.php?id=<?= $e['id'] ?>"><span class="txt-ellipsis" title="<?= sanitize($e['razon_social']) ?>"><?= sanitize($e['razon_social']) ?></span></a>
              <div style="font-size:11px;color:var(--gris-texto);"><?= ggi_copy($e['ruc']) ?></div>
            </div>
          </div>
        </td>
        <td>
          <?= ggi_tags(array_map(fn($c) => $c['norma_codigo'], $certs), ['max' => 1, 'noun' => 'normas de este envío']) ?>
        </td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#F59E0B" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            <div>
              <div style="font-size:12px;font-weight:600;color:var(--gris-dark);"><?= sanitize($e['agencia']) ?></div>
              <?php if ($e['codigo_envio']): ?>
              <div style="font-family:monospace;font-size:11px;background:var(--bg-surface2);padding:1px 6px;border-radius:5px;display:inline-block;margin-top:2px;"><?= sanitize($e['codigo_envio']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <?php
            // Destino: 1° sede; si no hay, la dirección libre. Para Olva se muestra además el punto de recojo.
            $destino_main = trim($e['sede_registro'] ?? '') ?: trim($e['direccion_destino'] ?? '');
            $destino_sub  = trim($e['tienda_destino'] ?? '');
          ?>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#10B981" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <div style="min-width:0;">
              <?php if ($destino_main !== ''): ?>
                <div class="txt-ellipsis" style="font-size:12px;color:var(--gris-dark);max-width:230px;" title="<?= sanitize($destino_main) ?>"><?= sanitize($destino_main) ?></div>
                <?php if ($destino_sub !== ''): ?>
                <div class="txt-ellipsis" style="font-size:11px;color:var(--gris-texto);max-width:230px;margin-top:2px;" title="<?= sanitize($destino_sub) ?>">📍 <?= sanitize($destino_sub) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--gris-texto);">—</span>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            <div style="min-width:0;">
              <?php if (!empty($e['sin_datos_envio'])): ?>
              <span style="display:inline-flex;align-items:center;gap:4px;background:var(--s-warn-bg);color:var(--s-warn-tx);border:1px solid var(--s-warn-brd,#fcd34d);border-radius:100px;font-size:10px;font-weight:600;padding:2px 8px;white-space:nowrap;">
                <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Sin datos de envío
              </span>
              <?php else: ?>
              <?php if ($e['destinatario']): ?>
              <div class="txt-ellipsis" style="font-size:12px;font-weight:500;max-width:230px;" title="<?= sanitize($e['destinatario']) ?>"><?= sanitize($e['destinatario']) ?></div>
              <?php endif; ?>
              <?php if ($e['destinatario_dni']): ?>
              <div style="font-size:11px;color:var(--gris-texto);">DNI: <?= sanitize($e['destinatario_dni']) ?></div>
              <?php endif; ?>
              <?php if (!$e['destinatario'] && !$e['destinatario_dni']): ?><span style="color:var(--gris-texto);">—</span><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <?php if ($e['agente_nombre']): ?>
            <div style="display:flex;align-items:center;gap:7px;">
              <div style="width:26px;height:26px;border-radius:50%;background:#1D9E75;color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;"><?= strtoupper(substr($e['agente_nombre'],0,1).substr($e['agente_apellido'],0,1)) ?></div>
              <span style="font-size:12px;color:var(--gris-dark);font-weight:500;"><?= sanitize($e['agente_nombre']) ?></span>
            </div>
          <?php else: ?>
            <?php if ($user['rol'] === 'gestor_logistico'): ?>
              <?php if (!in_array($e['estado_envio_id'], [5, 6])): ?>
              <form method="POST" action="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>" style="display:inline;" onsubmit="return asumir(event,<?= $e['id'] ?>)">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="asumir_envio">
                <button type="submit" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#1D9E75;color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">
                  Asumir
                </button>
              </form>
              <?php else: ?>
              <span style="font-size:11px;color:var(--gris-texto);">Finalizado</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="env-sin-asignar">Sin asignar</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td style="text-align:center;">
          <?php if (in_array($e['estado_envio_id'], [5, 6])): ?>
            <span class="env-estado-chip" style="--chip-color:<?= htmlspecialchars($e['color_hex']) ?>;">
              <?= sanitize($e['estado_nombre']) ?>
            </span>
          <?php else: ?>
            <?php $orden_actual_envio = (int)($estados_by_id[$e['estado_envio_id']]['orden'] ?? 0); ?>
            <div class="estado-sel"
                 data-id="<?= $e['id'] ?>"
                 data-value="<?= $e['estado_envio_id'] ?>"
                 data-color="<?= htmlspecialchars($e['color_hex']) ?>"
                 data-module="envios"
                 role="button" tabindex="0"
                 aria-label="Cambiar estado de envío">
              <span class="estado-sel-label"><?= sanitize($e['estado_nombre']) ?></span>
              <div class="estado-sel-menu" hidden>
                <?php foreach ($estados as $est):
                  if ($est['orden'] < $orden_actual_envio) continue; ?>
                <div class="estado-sel-option"
                     data-value="<?= $est['id'] ?>"
                     data-color="<?= htmlspecialchars($est['color_hex']) ?>"
                     style="--opt-color:<?= $est['color_hex'] ?>">
                  <?= sanitize($est['nombre']) ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php if (gg_es_estado_no_enviado($e['estado_nombre']) && !empty($e['motivo_no_envio'])): ?>
            <button type="button" class="motivo-ne-ic"
                    data-motivo="<?= sanitize(gg_motivo_no_envio_label($e['motivo_no_envio'])) ?>"
                    data-detalle="<?= sanitize($e['motivo_no_envio_detalle'] ?? '') ?>"
                    aria-label="Ver motivo de no envío" title="Ver motivo">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            </button>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" stroke-width="2" style="flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span style="font-size:12px;"><?= formato_fecha($e['fecha_envio']) ?></span>
          </div>
        </td>
        <td>
          <div class="action-btns" style="gap:4px;">
            <a href="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>" class="btn-ic btn-ic-blue" title="Ver detalle">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <button type="button" onclick="ggiOpenForm('<?= APP_URL ?>/modules/envios/editar.php?id=<?= $e['id'] ?>&from=listado','Editar envío')" class="btn-ic btn-ic-blue" title="Editar">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <?php if (has_role('gerente_general','gerente_operaciones')): ?>
            <div class="ggi-row-menu-wrap">
              <button type="button" class="ggi-row-menu-btn" title="Más acciones" aria-label="Más acciones" aria-haspopup="true" aria-expanded="false">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
              </button>
              <div class="ggi-row-menu">
                <button type="button" class="ggi-row-menu-item danger"
                  data-del-envio="<?= $e['id'] ?>"
                  onclick="ggiEliminarEnvio(<?= $e['id'] ?>)">
                  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6m4-6v6"/><path d="M9 6V4h6v2"/></svg>
                  Eliminar envío
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
  </div>
  <!-- Desktop bar is now floating (see bottom of page) -->

  <?php if ($total_rows > 0): ?>
    <?= ggi_pager($total_rows, $page, $limit, [
        'per_page_options' => [10, 20, 30],
        'param_page'       => 'page',
        'noun'             => 'envío',
        'noun_plural'      => 'envíos',
    ]) ?>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
async function asumir(e, id) {
  e.preventDefault();
  const form = e.target;
  try {
    const r    = await fetch('<?= APP_URL ?>/modules/envios/ajax_asumir.php', {
      method:  'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':window.CSRF_TOKEN||''},
      body:    'envio_id='+id+'&_csrf='+encodeURIComponent(document.querySelector('[name="_csrf"]')?.value||'')
    });
    const data = await r.json();
    if (data.ok) {
      if (typeof showToast === 'function') showToast('Envío asumido correctamente.','ok');
      // Actualizar fila sin recargar: ocultar botón "Asumir" y actualizar gestor
      const row = form.closest('tr') || form.closest('[data-row]');
      if (row) {
        const aBtn = row.querySelector('.btn-asumir, form[onsubmit]');
        if (aBtn) aBtn.remove();
        const gesCell = row.querySelector('[data-gestor]');
        if (gesCell && data.gestor) gesCell.textContent = data.gestor;
      }
    } else {
      if (typeof showToast === 'function') showToast(data.msg||'No se pudo asumir.','error');
    }
  } catch(err) {
    if (typeof showToast === 'function') showToast('Error de conexión.','error');
  }
  return false;
}

function ggiEliminarEnvio(id) {
  const fd = new FormData();
  fd.append('envio_id', id);
  fd.append('_csrf', document.querySelector('[name="_csrf"]')?.value || '');
  ggiEliminar(
    '<?= APP_URL ?>/modules/envios/ajax_eliminar.php',
    fd,
    function() {
      const btn = document.querySelector('[data-del-envio="' + id + '"]');
      if (btn) btn.closest('tr')?.remove();
    },
    'Eliminar envío',
    'envío'
  );
}

ggiReady(() => {
  const p = new URLSearchParams(window.location.search);
  const m = p.get('msg');
  if (m && typeof showToast === 'function') {
    showToast(decodeURIComponent(m), 'ok');
    history.replaceState({}, '', window.location.pathname);
  }

  // ── Motivos de "No Enviado" (catálogo central en helpers.php) ──
  const MOTIVOS_NE = <?= json_encode(gg_motivos_no_envio(), JSON_UNESCAPED_UNICODE) ?>;

  // Modal que exige el motivo antes de pasar un envío a "No Enviado".
  // Devuelve Promise<{motivo, detalle}|null> (null = canceló).
  function pedirMotivoNoEnvio() {
    return new Promise(resolve => {
      const prev = document.getElementById('ne-motivo-ov');
      if (prev) prev.remove();
      const ov = document.createElement('div');
      ov.id = 'ne-motivo-ov';
      ov.className = 'ggi-modal-overlay open';
      let radios = '';
      Object.entries(MOTIVOS_NE).forEach(([cod, lbl], i) => {
        radios += `<label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1.5px solid var(--brd,#e2e8f0);border-radius:9px;margin-bottom:7px;cursor:pointer;font-size:13px;min-height:44px;">
          <input type="radio" name="ne-motivo" value="${cod}" ${i===0?'checked':''} style="accent-color:var(--ac,#1A3A8F);width:16px;height:16px;flex-shrink:0;">
          ${lbl}
        </label>`;
      });
      ov.innerHTML =
        '<div class="ggi-modal-box" style="max-width:420px;" role="dialog" aria-modal="true" aria-label="Motivo de no envío">' +
          '<div class="ggi-modal-header"><h3 style="font-size:15px;margin:0;">¿Por qué no se envió?</h3></div>' +
          '<div class="ggi-modal-body">' +
            '<p style="font-size:12.5px;color:var(--tx2,#64748b);margin:0 0 12px;">El motivo queda visible para todo el equipo en el listado y la ficha del envío.</p>' +
            radios +
            '<textarea id="ne-motivo-det" maxlength="500" rows="2" placeholder="Detalle (opcional; obligatorio en “Otro motivo”)" style="width:100%;padding:10px 12px;border:1.5px solid var(--brd,#e2e8f0);border-radius:9px;font-size:13px;font-family:inherit;resize:vertical;margin-top:4px;"></textarea>' +
          '</div>' +
          '<div class="ggi-modal-footer">' +
            '<button type="button" class="ggi-btn-confirm-cancel" id="ne-motivo-cancel">Cancelar</button>' +
            '<button type="button" class="ggi-btn-confirm-action" id="ne-motivo-ok">Marcar No Enviado</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(ov);
      const done = v => { ov.remove(); resolve(v); };
      ov.addEventListener('click', ev => { if (ev.target === ov) done(null); });
      document.getElementById('ne-motivo-cancel').onclick = () => done(null);
      document.getElementById('ne-motivo-ok').onclick = () => {
        const motivo  = ov.querySelector('[name="ne-motivo"]:checked')?.value || '';
        const detalle = document.getElementById('ne-motivo-det').value.trim();
        if (motivo === 'otro' && !detalle) {
          if (typeof showToast === 'function') showToast('Describe el motivo en el detalle.', 'warning');
          return;
        }
        done({ motivo, detalle });
      };
    });
  }

  // ── Popover del motivo de "No Enviado" (ícono ⓘ) ──
  function cerrarMotivoPop() {
    document.getElementById('motivo-ne-pop')?.remove();
  }
  document.addEventListener('click', function(ev) {
    const ic = ev.target.closest('.motivo-ne-ic');
    if (!ic) { cerrarMotivoPop(); return; }
    ev.stopPropagation();
    // Toggle: si ya está abierto para este ícono, cerrar
    const abierto = document.getElementById('motivo-ne-pop');
    if (abierto && abierto._src === ic) { cerrarMotivoPop(); return; }
    cerrarMotivoPop();
    const pop = document.createElement('div');
    pop.id = 'motivo-ne-pop';
    pop.className = 'motivo-ne-pop';
    pop._src = ic;
    pop.setAttribute('role', 'tooltip');
    const det = ic.dataset.detalle || '';
    pop.innerHTML = '<strong>Motivo de no envío</strong>' +
      (ic.dataset.motivo || '') +
      (det ? '<div style="margin-top:5px;color:var(--gris-texto,#64748b);">' + det.replace(/</g,'&lt;') + '</div>' : '');
    document.body.appendChild(pop);
    // Posicionar bajo el ícono sin salirse del viewport
    const r = ic.getBoundingClientRect();
    const pw = pop.offsetWidth, ph = pop.offsetHeight;
    let left = Math.min(Math.max(8, r.left + r.width/2 - pw/2), window.innerWidth - pw - 8);
    let top  = r.bottom + 6;
    if (top + ph > window.innerHeight - 8) top = r.top - ph - 6; // no cabe abajo → arriba
    pop.style.left = left + 'px';
    pop.style.top  = top + 'px';
  });
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape') cerrarMotivoPop(); });
  window.addEventListener('scroll', cerrarMotivoPop, { passive: true });
  window.addEventListener('resize', cerrarMotivoPop);

  // ── Estado inline individual (estado-sel) ──
  document.addEventListener('estado-sel-change', async function(e) {
    if (e.detail.module !== 'envios') return;
    const d = e.detail;

    // "No Enviado" exige motivo → pedirlo antes de llamar al backend
    let extra = '';
    const esNoEnviado = /no enviado/i.test(d.label || '');
    if (esNoEnviado) {
      const m = await pedirMotivoNoEnvio();
      if (!m) return; // canceló: no se cambia nada
      extra = `&motivo_no_envio=${encodeURIComponent(m.motivo)}&motivo_no_envio_detalle=${encodeURIComponent(m.detalle)}`;
      d._motivoLabel = MOTIVOS_NE[m.motivo] || '';
      d._motivoDetalle = m.detalle;
    }

    try {
      const r = await fetch(`<?= APP_URL ?>/modules/envios/ajax_estado.php`, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':window.CSRF_TOKEN||''},
        body: `envio_id=${d.id}&estado_id=${d.value}` + extra
      });
      const data = await r.json();
      if (data.ok) {
        d.el.setAttribute('data-color', d.color);
        d.el.setAttribute('data-value', d.value);
        const lbl = d.el.querySelector('.estado-sel-label');
        if (lbl) lbl.textContent = d.label;
        // Ícono ⓘ del motivo: crear/actualizar/quitar según el nuevo estado
        const td = d.el.closest('td');
        let ic = td ? td.querySelector('.motivo-ne-ic') : null;
        if (esNoEnviado && d._motivoLabel) {
          if (!ic && td) {
            ic = document.createElement('button');
            ic.type = 'button';
            ic.className = 'motivo-ne-ic';
            ic.setAttribute('aria-label', 'Ver motivo de no envío');
            ic.title = 'Ver motivo';
            ic.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
            td.appendChild(ic);
          }
          if (ic) {
            ic.dataset.motivo  = d._motivoLabel;
            ic.dataset.detalle = d._motivoDetalle || '';
          }
        } else if (ic) {
          ic.remove(); // salió de No Enviado → el motivo ya no aplica
        }
        if (typeof window.ggiApplySelectColors === 'function') window.ggiApplySelectColors(d.el.closest('tbody') || document);
        if (typeof showToast === 'function') showToast('Estado actualizado.', 'ok');
        if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'Error al actualizar.', 'error');
      }
    } catch(e) {
      if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
    }
  });

});
</script>
<style>
/* Motivo de "No Enviado": ícono ⓘ junto al badge; el texto va en un popover
   al hacer clic/tap (el subtexto truncado ensuciaba el listado). */
.motivo-ne-ic {
  display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 26px; margin-left: 4px; padding: 0;
  vertical-align: middle; flex-shrink: 0;
  border: none; border-radius: 50%;
  background: transparent; color: var(--s-warn, #d97706);
  cursor: pointer; transition: background .14s;
}
.motivo-ne-ic:hover { background: var(--s-warn-bg, #fef3c7); }
@media (hover: none) { .motivo-ne-ic { width: 36px; height: 36px; } }

.motivo-ne-pop {
  position: fixed; z-index: 9700;
  max-width: 280px; padding: 11px 13px;
  background: var(--bg-surface, #fff);
  border: 1px solid var(--brd, #e2e8f0); border-radius: 10px;
  box-shadow: 0 12px 32px rgba(15,23,42,.14), 0 2px 6px rgba(15,23,42,.08);
  font-size: 12.5px; line-height: 1.5; color: var(--gris-dark, #0f172a);
}
.motivo-ne-pop strong { display: block; color: var(--s-warn, #d97706); margin-bottom: 2px; font-size: 12px; }
.mobile-icon { display:none; }
@media (max-width: 768px) {
  .mobile-icon { display:inline-block; }
  /* Neutralizar min-width y overflow-x del wrapper para que el layout de tarjetas funcione */
  .data-table { min-width: unset !important; }
  .table-scroll-wrap { overflow-x:visible !important; }
  .data-table thead { display:none; }
  .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; box-sizing:border-box; }
  .data-table tr {
    background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde);
    margin-bottom:10px; padding:14px 16px;
  }
  .data-table tr:hover td { background:transparent; }
  .data-table td { padding:5px 0; border-bottom:none; font-size:13px; }
  /* Columnas: 1 Empresa · 2 Norma · 3 Agencia · 4 Destino · 5 Receptor · 6 Gestor · 7 Estado · 8 Fecha · 9 Acciones.
     En móvil mostramos lo esencial (Empresa, Agencia, Destino, Receptor, Estado, Acciones) y ocultamos el resto. */
  .data-table td:nth-child(2),  /* Norma */
  .data-table td:nth-child(6),  /* Gestor */
  .data-table td:nth-child(8) { display:none; } /* Fecha */
  .data-table td:last-child { margin-top:10px; padding-top:10px; border-top:1px solid var(--gris-borde); }
  .action-btns { gap:8px; }
  .btn-ic { width:36px; height:36px; }
  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar form { flex-direction:column; flex-wrap:nowrap; }
  .search-wrap { max-width:100%; }
  .filter-select { width:100%; }
  .estado-sel { max-width:100% !important; }
  .estado-sel-menu { left:0; transform:none; } /* menú alineado a la izquierda en mobile */
  /* .pagination responsive: en layout_end.php */
}
</style>
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
