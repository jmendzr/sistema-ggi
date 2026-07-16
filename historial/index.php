<?php
$page_title  = 'Historial de actividad';
$active_menu = 'historial';
require_once __DIR__ . '/../../templates/layout.php';

require_role('gerente_general');

$pdo = Database::get();

// Filtros
$modulo   = $_GET['modulo']   ?? '';
$uid_fil  = (int)($_GET['usuario'] ?? 0);
$desde    = $_GET['desde']    ?? date('Y-m-d', strtotime('-30 days'));
$hasta    = $_GET['hasta']    ?? date('Y-m-d');
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 30;
$offset   = ($page - 1) * $limit;

$where  = ['l.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$desde, $hasta];

if ($modulo) {
    $where[]  = 'l.modulo = ?';
    $params[] = $modulo;
}
if ($uid_fil) {
    $where[]  = 'l.usuario_id = ?';
    $params[] = $uid_fil;
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
  font-size:13px; font-family:'Plus Jakarta Sans',sans-serif;
  background:#fff; color:var(--gris-dark); outline:none; cursor:pointer;
}
.filter-select:focus, .filter-input:focus { border-color:var(--azul); }
.filter-input[type=date] { padding:7px 10px; }

.table-wrap { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
.data-table { width:100%; border-collapse:collapse; }
.data-table th {
  font-size:10px; font-weight:700; color:var(--gris-texto);
  text-transform:uppercase; letter-spacing:.5px;
  padding:10px 14px; text-align:left; background:#fafbfe;
  border-bottom:1px solid var(--gris-borde); white-space:nowrap;
}
.data-table td { padding:10px 14px; font-size:13px; border-bottom:1px solid #f0f2f8; color:var(--gris-dark); vertical-align:middle; }
.data-table tr:last-child td { border-bottom:none; }
.data-table tr:hover td { background:#fafbfe; }
.empty-state { text-align:center; padding:48px 20px; color:var(--gris-texto); font-size:14px; }

.badge-modulo {
  display:inline-block; padding:2px 9px; border-radius:100px;
  font-size:11px; font-weight:600; white-space:nowrap;
}
.user-chip { display:inline-flex; align-items:center; gap:6px; }
.avatar-xs {
  width:24px; height:24px; border-radius:50%; background:var(--azul-light);
  color:var(--azul); font-size:10px; font-weight:700;
  display:inline-flex; align-items:center; justify-content:center;
  flex-shrink:0; text-transform:uppercase;
}
.ip-tag { font-family:monospace; font-size:11px; color:var(--gris-texto); background:#f0f2f8; padding:2px 6px; border-radius:4px; }
.td-desc { max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.pagination { display:flex; align-items:center; justify-content:space-between; margin-top:16px; flex-wrap:wrap; gap:10px; }
.pag-info { font-size:12px; color:var(--gris-texto); }
.pag-links { display:flex; gap:4px; }
.pag-btn {
  padding:5px 10px; border-radius:7px; border:1.5px solid var(--gris-borde);
  background:#fff; color:var(--gris-dark); font-size:12px; text-decoration:none;
  transition:.15s;
}
.pag-btn:hover, .pag-btn.active { background:var(--azul); border-color:var(--azul); color:#fff; }
.pag-btn.disabled { opacity:.4; pointer-events:none; }

.stat-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.stat-mini { background:#fff; border-radius:10px; border:1px solid var(--gris-borde); padding:14px 16px; }
.stat-mini-val { font-family:'Outfit',sans-serif; font-size:22px; font-weight:700; color:var(--azul); }
.stat-mini-lbl { font-size:11px; color:var(--gris-texto); margin-top:2px; }
@media(max-width:768px){ .stat-row{grid-template-columns:repeat(2,1fr)} .toolbar{flex-direction:column;align-items:stretch} }
</style>

<div class="page-header" style="margin-bottom:20px;">

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
<form method="GET" class="toolbar">
  <select name="modulo" class="filter-select" onchange="this.form.submit()">
    <option value="">Todos los módulos</option>
    <?php foreach ($modulos as $m): ?>
    <option value="<?= $m ?>" <?= $modulo===$m?'selected':'' ?>><?= ucfirst($m) ?></option>
    <?php endforeach; ?>
  </select>

  <select name="usuario" class="filter-select" onchange="this.form.submit()">
    <option value="">Todos los usuarios</option>
    <?php foreach ($usuarios as $u): ?>
    <option value="<?= $u['id'] ?>" <?= $uid_fil===$u['id']?'selected':'' ?>>
      <?= htmlspecialchars($u['nombre'].' '.$u['apellido']) ?>
    </option>
    <?php endforeach; ?>
  </select>

  <input type="date" name="desde" value="<?= $desde ?>" class="filter-input" onchange="this.form.submit()">
  <input type="date" name="hasta" value="<?= $hasta ?>" class="filter-input" onchange="this.form.submit()">

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
  <table class="data-table">
    <thead>
      <tr>
        <th>Fecha y hora</th>
        <th>Usuario</th>
        <th>Módulo</th>
        <th>Acción</th>
        <th>Descripción</th>
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $colores_modulo = [
      'auth'          => ['#1A3A8F','#e8edf8'],
      'clientes'      => ['#10b981','#d1fae5'],
      'certificados'  => ['#2DB140','#e8f7eb'],
      'envios'        => ['#8b5cf6','#f5f3ff'],
      'cotizaciones'  => ['#f59e0b','#fff7e6'],
      'auditorias'    => ['#0ea5e9','#e0f2fe'],
      'cuenta'        => ['#6366f1','#eef2ff'],
      'usuarios'      => ['#ef4444','#fee2e2'],
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
  <?php endif; ?>
</div>

<!-- Paginación -->
<?php if ($total_pages > 1): ?>
<div class="pagination">
  <span class="pag-info">Página <?= $page ?> de <?= $total_pages ?></span>
  <div class="pag-links">
    <?php
    $qs = http_build_query(array_merge($_GET, ['page'=>1]));
    $qp = http_build_query(array_merge($_GET, ['page'=>max(1,$page-1)]));
    $qn = http_build_query(array_merge($_GET, ['page'=>min($total_pages,$page+1)]));
    $ql = http_build_query(array_merge($_GET, ['page'=>$total_pages]));
    ?>
    <a href="?<?= $qs ?>" class="pag-btn <?= $page<=1?'disabled':'' ?>">«</a>
    <a href="?<?= $qp ?>" class="pag-btn <?= $page<=1?'disabled':'' ?>">‹</a>
    <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>" class="pag-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <a href="?<?= $qn ?>" class="pag-btn <?= $page>=$total_pages?'disabled':'' ?>">›</a>
    <a href="?<?= $ql ?>" class="pag-btn <?= $page>=$total_pages?'disabled':'' ?>">»</a>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
