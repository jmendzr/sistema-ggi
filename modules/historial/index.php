<?php
$page_title  = 'Historial de actividad';
$active_menu = 'historial';
require_once __DIR__ . '/../../templates/layout.php';

require_role('gerente_general');

$pdo = Database::get();

// Filtros
$modulo_sel = array_values(array_filter((array)($_GET['modulo'] ?? []), fn($v) => $v !== ''));
$uid_sel    = array_values(array_filter(array_map('intval', (array)($_GET['usuario'] ?? [])), fn($v) => $v > 0));
$desde    = $_GET['desde']    ?? date('Y-m-d', strtotime('-30 days'));
$hasta    = $_GET['hasta']    ?? date('Y-m-d');
$page     = max(1, (int)($_GET['page'] ?? 1));
$_pp_opts = [10, 20, 30];
$_pp_req  = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$limit    = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $limit;
$offset   = ($page - 1) * $limit;

$where  = ['l.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$desde, $hasta];

if ($modulo_sel) {
    $ph = implode(',', array_fill(0, count($modulo_sel), '?'));
    $where[]  = "l.modulo IN ($ph)";
    $params   = array_merge($params, $modulo_sel);
}
if ($uid_sel) {
    $ph = implode(',', array_fill(0, count($uid_sel), '?'));
    $where[]  = "l.usuario_id IN ($ph)";
    $params   = array_merge($params, $uid_sel);
}

$sql_where = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM log_actividad l WHERE $sql_where");
$total->execute($params);
$total_rows  = $total->fetchColumn();
$total_pages = max(1, ceil($total_rows / $limit));

$stmt = $pdo->prepare("
    SELECT l.*, u.nombre, u.apellido, u.rol
    FROM log_actividad l
    LEFT JOIN usuarios u ON u.id = l.usuario_id
    WHERE $sql_where
    ORDER BY l.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Para filtros
$modulos  = $pdo->query("SELECT DISTINCT modulo FROM log_actividad ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);
$usuarios = $pdo->query("SELECT id, nombre, apellido FROM usuarios ORDER BY nombre")->fetchAll();
?>
<style>
.toolbar { display:flex; align-items:center; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
.filter-select, .filter-input {
  padding:8px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
  font-size:13px; font-family:'Inter',sans-serif;
  background:var(--bg-surface); color:var(--gris-dark); outline:none; cursor:pointer;
}
.filter-select:focus, .filter-input:focus { border-color:var(--azul); }
.filter-input[type=date] { padding:7px 10px; }

.table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
/* .data-table base y .empty-state → canónicos en design-system.css */

.badge-modulo {
  display:inline-block; padding:2px 9px; border-radius:100px;
  font-size:11px; font-weight:600; white-space:nowrap;
}
.user-chip { display:inline-flex; align-items:center; gap:6px; }
.avatar-xs {
  width:24px; height:24px; border-radius:50%; background:var(--s-info-bg);
  color:var(--s-info-tx); font-size:10px; font-weight:700;
  display:inline-flex; align-items:center; justify-content:center;
  flex-shrink:0; text-transform:uppercase;
}
.ip-tag { font-family:monospace; font-size:11px; color:var(--gris-texto); background:var(--bg-surface2); padding:2px 6px; border-radius:4px; }
.td-desc { max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* Paginación: estilos en layout_end.php */
.pagination { margin-top:16px; }

.stat-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.stat-mini { background:var(--bg-surface); border-radius:10px; border:1px solid var(--gris-borde); padding:14px 16px; }
.stat-mini-val { font-family:'Inter',sans-serif; font-size:22px; font-weight:700; color:var(--gris-dark); }
.stat-mini-lbl { font-size:11px; color:var(--gris-texto); margin-top:2px; }
@media(max-width:768px){
  .stat-row { grid-template-columns:repeat(2,1fr); }
  .toolbar { flex-direction:column; align-items:stretch; }
  /* Cards en móvil */
  .data-table thead { display:none; }
  .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
  .data-table tr {
    background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde);
    margin-bottom:10px; padding:12px 14px; box-sizing:border-box;
  }
  .data-table tr:hover td { background:transparent; }
  .data-table td { padding:4px 0; border-bottom:none; font-size:13px; }
  /* Ocultar IP y rol en móvil (datos secundarios) */
  .data-table td:nth-child(6) { display:none; }
  .data-table td:nth-child(3) { display:none; }
  .td-desc { max-width:100%; white-space:normal; }
  .filter-input[type=date] { width:100%; }
}
@media(max-width:480px){ .stat-row{grid-template-columns:1fr 1fr;} }
</style>

<div class="page-header" style="margin-bottom:20px;">
  <div>
    <h1>Historial de actividad</h1>
    <p>Registro completo de acciones realizadas en el sistema</p>
  </div>

</div>

<?php
// Stats rápidas
$stats_hoy    = $pdo->query("SELECT COUNT(*) FROM log_actividad WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$stats_semana = $pdo->query("SELECT COUNT(*) FROM log_actividad WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
$stats_logins = $pdo->query("SELECT COUNT(*) FROM log_actividad WHERE modulo='auth' AND accion='login' AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
$stats_total  = $pdo->query("SELECT COUNT(*) FROM log_actividad")->fetchColumn();
?>
<div class="stat-row">
  <div class="stat-mini">
    <div class="stat-mini-val"><?= number_format($stats_hoy) ?></div>
    <div class="stat-mini-lbl">Acciones hoy</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini-val"><?= number_format($stats_semana) ?></div>
    <div class="stat-mini-lbl">Últimos 7 días</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini-val"><?= number_format($stats_logins) ?></div>
    <div class="stat-mini-lbl">Inicios de sesión (7d)</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini-val"><?= number_format($stats_total) ?></div>
    <div class="stat-mini-lbl">Total registros</div>
  </div>
</div>

<!-- Filtros -->
<?php $_nfiltros = (!empty($modulo_sel) ? 1 : 0) + (!empty($uid_sel) ? 1 : 0) + (($desde || $hasta) ? 1 : 0); ?>
<form method="GET" class="toolbar <?= $_nfiltros ? 'filtros-open' : '' ?>">
  <button type="button" class="ggi-filtros-toggle" onclick="this.closest('form').classList.toggle('filtros-open')" aria-label="Mostrar u ocultar filtros">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
    Filtros<?php if ($_nfiltros): ?> <span class="ggi-filtros-badge"><?= $_nfiltros ?></span><?php endif; ?>
  </button>
  <div class="ggi-filtros">
  <?php
    $_mod_opts = [];
    foreach ($modulos as $m) $_mod_opts[(string)$m] = ucfirst($m);
    echo ggi_msdrop('ms-hist-modulo', 'modulo', $_mod_opts, $modulo_sel, ['placeholder' => 'Todos los módulos', 'aria' => 'Filtrar por módulo']);

    $_usr_opts = [];
    foreach ($usuarios as $u) $_usr_opts[(string)$u['id']] = $u['nombre'] . ' ' . $u['apellido'];
    echo ggi_msdrop('ms-hist-usuario', 'usuario', $_usr_opts, $uid_sel, ['placeholder' => 'Todos los usuarios', 'aria' => 'Filtrar por usuario']);
  ?>

  <input type="date" name="desde" value="<?= $desde ?>" class="filter-input" onchange="this.form.submit()">
  <input type="date" name="hasta" value="<?= $hasta ?>" class="filter-input" onchange="this.form.submit()">
  </div><!-- /.ggi-filtros -->

  <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
  <span style="font-size:12px;color:var(--gris-texto);margin-left:auto;"><?= number_format($total_rows) ?> registros</span>
</form>

<!-- Tabla -->
<div class="table-wrap">
  <?php if (empty($logs)): ?>
  <div class="empty-state">
    <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 10px;display:block;opacity:.25"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"/></svg>
    Sin registros para los filtros seleccionados
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="data-table">
    <thead>
      <tr>
        <th scope="col">Fecha y hora</th>
        <th scope="col">Usuario</th>
        <th scope="col">Módulo</th>
        <th scope="col">Acción</th>
        <th scope="col">Descripción</th>
        <th scope="col">IP</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $colores_modulo = [
      'auth'          => ['#1A3A8F','#e8edf8'],
      'clientes'      => ['#10b981','var(--s-ok-bg)'],
      'certificados'  => ['#2DB140','var(--s-ok-bg)'],
      'envios'        => ['#8b5cf6','var(--s-purple-bg)'],
      'propuestas'  => ['#f59e0b','var(--s-warn-bg)'],
      'auditorias'    => ['#0ea5e9','#e0f2fe'],
      'cuenta'        => ['#6366f1','var(--s-info-bg)'],
      'usuarios'      => ['#ef4444','var(--s-err-bg)'],
    ];
    foreach ($logs as $log):
        $col = $colores_modulo[$log['modulo']] ?? ['#6b7280','#f3f4f6'];
        $ini = $log['nombre'] ? strtoupper(substr($log['nombre'],0,1).substr($log['apellido']??'',0,1)) : '?';
        $nombre_u = $log['nombre'] ? htmlspecialchars($log['nombre'].' '.$log['apellido']) : '<em style="color:var(--gris-texto)">Sistema</em>';
    ?>
      <tr>
        <td style="white-space:nowrap;font-size:12px;color:var(--gris-texto);">
          <?= date('d/m/Y', strtotime($log['created_at'])) ?><br>
          <strong style="color:var(--gris-dark)"><?= date('H:i:s', strtotime($log['created_at'])) ?></strong>
        </td>
        <td>
          <div class="user-chip">
            <div class="avatar-xs"><?= $ini ?></div>
            <div>
              <div style="font-size:12px;font-weight:600"><?= $nombre_u ?></div>
              <?php if ($log['rol']): ?>
              <div style="font-size:10px;color:var(--gris-texto);text-transform:capitalize"><?= str_replace('_',' ',$log['rol']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <span class="badge-modulo" style="background:<?= $col[1] ?>;color:<?= $col[0] ?>;border:1px solid <?= $col[0] ?>33">
            <?= ucfirst($log['modulo']) ?>
          </span>
        </td>
        <td style="font-size:12px;font-weight:600"><?= htmlspecialchars($log['accion']) ?></td>
        <td class="td-desc" title="<?= htmlspecialchars($log['descripcion'] ?? '') ?>">
          <?= htmlspecialchars($log['descripcion'] ?? '—') ?>
        </td>
        <td>
          <?php if ($log['ip']): ?>
          <span class="ip-tag"><?= htmlspecialchars($log['ip']) ?></span>
          <?php else: ?>
          <span style="color:var(--gris-texto);font-size:11px">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div><!-- /overflow-x -->
  <?php endif; ?>
</div>

<!-- Paginación -->
<?php if ($total_rows > 0): ?>
  <?= ggi_pager((int)$total_rows, $page, $limit, [
      'per_page_options' => [10, 20, 30],
      'param_page'       => 'page',
      'noun'             => 'registro',
      'noun_plural'      => 'registros',
  ]) ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
