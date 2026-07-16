<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$pdo    = Database::get();
$buscar = trim($_GET['q'] ?? '');
$filtro = $_GET['filtro'] ?? '';  // pendiente, aprobado, con_observaciones, no_conforme, reprogramado
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];

if ($buscar) {
    $where[]  = '(cl.razon_social LIKE ? OR n.codigo LIKE ? OR a.auditor_externo LIKE ?)';
    $params   = array_merge($params, ["%$buscar%", "%$buscar%", "%$buscar%"]);
}
if ($filtro) {
    $where[]  = 'a.resultado = ?';
    $params[] = $filtro;
}

$sql_where = implode(' AND ', $where);

$total = $pdo->prepare("
    SELECT COUNT(*) FROM auditorias a
    JOIN certificados c ON c.id = a.certificado_id
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN normas_iso n ON n.id = c.norma_iso_id
    WHERE $sql_where
");
$total->execute($params);
$total_rows  = $total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stmt = $pdo->prepare("
    SELECT a.*,
           cl.razon_social, cl.ruc,
           n.codigo AS norma_codigo,
           cc.nombre AS casa_nombre
    FROM auditorias a
    JOIN certificados c  ON c.id  = a.certificado_id
    JOIN clientes cl     ON cl.id = c.cliente_id
    JOIN normas_iso n    ON n.id  = c.norma_iso_id
    LEFT JOIN casas_certificadoras cc ON cc.id = a.casa_certificadora_id
    WHERE $sql_where
    ORDER BY a.fecha_programada ASC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$auditorias = $stmt->fetchAll();

$resultado_map = [
    'pendiente'         => ['label' => 'Pendiente',         'color' => '#6B7280'],
    'aprobado'          => ['label' => 'Aprobado',          'color' => '#10B981'],
    'con_observaciones' => ['label' => 'Con observaciones', 'color' => '#F59E0B'],
    'no_conforme'       => ['label' => 'No conforme',       'color' => '#EF4444'],
    'reprogramado'      => ['label' => 'Reprogramado',      'color' => '#8B5CF6'],
];

$tipo_map = [
    'seguimiento_1' => 'Seguimiento 1',
    'seguimiento_2' => 'Seguimiento 2',
    'renovacion'    => 'Renovación',
];

$page_title  = 'Auditorías';
$active_menu = 'auditorias';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .toolbar { display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; max-width:360px; }
  .search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; cursor:pointer; }
  .table-wrap { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .data-table { width:100%; border-collapse:collapse; }
  .data-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; padding:12px 16px; background:#fafbfe; border-bottom:1px solid var(--gris-borde); text-align:left; white-space:nowrap; }
  .data-table td { padding:13px 16px; font-size:13px; border-bottom:1px solid #f0f2f8; vertical-align:middle; }
  .data-table tr:last-child td { border-bottom:none; }
  .data-table tr:hover td { background:#fafbfe; }
  .badge { display:inline-block; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; }
  .btn-ic { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; border:none; cursor:pointer; text-decoration:none; transition:all 0.15s; }
  .btn-ic-blue { background:#e8edf8; color:#1A3A8F; } .btn-ic-blue:hover { background:#1A3A8F; color:#fff; }
  .alerta-vence { color:#b45309; font-size:11px; font-weight:600; }
  .empty-state { padding:48px; text-align:center; color:var(--gris-texto); }
  .pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-top:1px solid var(--gris-borde); }
  .pagination-info { font-size:12px; color:var(--gris-texto); }
  .pagination-btns { display:flex; gap:6px; }
  .page-btn { width:32px; height:32px; border-radius:7px; display:flex; align-items:center; justify-content:center; border:1.5px solid var(--gris-borde); background:#fff; font-size:12px; font-weight:600; text-decoration:none; color:var(--gris-dark); transition:all 0.15s; }
  .page-btn:hover, .page-btn.active { background:var(--azul); color:#fff; border-color:var(--azul); }
  .page-btn.disabled { opacity:0.4; pointer-events:none; }
</style>



<div class="toolbar">
  <form method="GET" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar cliente, norma, auditor..." value="<?= sanitize($buscar) ?>">
    </div>
    <select name="filtro" class="filter-select" onchange="this.form.submit()">
      <option value="">Todos los estados</option>
      <option value="pendiente"         <?= $filtro==='pendiente'?'selected':'' ?>>Pendiente</option>
      <option value="aprobado"          <?= $filtro==='aprobado'?'selected':'' ?>>Aprobado</option>
      <option value="con_observaciones" <?= $filtro==='con_observaciones'?'selected':'' ?>>Con observaciones</option>
      <option value="no_conforme"       <?= $filtro==='no_conforme'?'selected':'' ?>>No conforme</option>
      <option value="reprogramado"      <?= $filtro==='reprogramado'?'selected':'' ?>>Reprogramado</option>
    </select>
    <?php if ($buscar || $filtro): ?>
    <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($auditorias)): ?>
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:0.25;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    <h3 style="font-size:15px;color:var(--gris-dark);margin-bottom:4px;">Sin auditorías</h3>
    <p style="font-size:13px;">Las auditorías se generan automáticamente al registrar un certificado.</p>
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="data-table">
    <thead>
      <tr>
        <th>Empresa</th>
        <th>Norma</th>
        <th>Tipo</th>
        <th>Fecha programada</th>
        <th>Fecha realizada</th>
        <th>Casa certif.</th>
        <th>Resultado</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($auditorias as $a):
        $res    = $resultado_map[$a['resultado']] ?? $resultado_map['pendiente'];
        $dias   = dias_hasta($a['fecha_programada']);
        $vence_pronto = $a['resultado'] === 'pendiente' && $dias >= 0 && $dias <= 30;
        $vencida      = $a['resultado'] === 'pendiente' && $dias < 0;
      ?>
      <tr>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <div>
              <div style="font-weight:600;"><?= sanitize($a['razon_social']) ?></div>
              <div style="font-size:11px;color:var(--gris-texto);"><?= sanitize($a['ruc']) ?></div>
            </div>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
            <strong style="color:var(--azul);"><?= sanitize($a['norma_codigo']) ?></strong>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <?= sanitize($tipo_map[$a['tipo']] ?? $a['tipo']) ?>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#F59E0B" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <div>
              <?= formato_fecha($a['fecha_programada']) ?>
              <?php if ($vence_pronto): ?>
              <div class="alerta-vence">⚠ En <?= $dias ?> días</div>
              <?php elseif ($vencida): ?>
              <div style="color:#dc2626;font-size:11px;font-weight:600;">⚠ Vencida hace <?= abs($dias) ?> días</div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td><?= $a['fecha_realizada'] ? formato_fecha($a['fecha_realizada']) : '<span style="color:var(--gris-texto)">—</span>' ?></td>
        <td><?= $a['casa_nombre'] ? sanitize($a['casa_nombre']) : '<span style="color:var(--gris-texto)">—</span>' ?></td>
        <td>
          <span class="badge" style="background:<?= $res['color'] ?>22;color:<?= $res['color'] ?>;border:1px solid <?= $res['color'] ?>55;white-space:nowrap;">
            <?= $res['label'] ?>
          </span>
        </td>
        <td>
          <a href="<?= APP_URL ?>/modules/auditorias/ver.php?id=<?= $a['id'] ?>" class="btn-ic btn-ic-blue" title="Ver / Registrar resultado">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <div class="pagination-info">Mostrando <?= $offset+1 ?>–<?= min($offset+$limit,$total_rows) ?> de <?= $total_rows ?></div>
    <div class="pagination-btns">
      <a href="?page=<?= $page-1 ?>&q=<?= urlencode($buscar) ?>&filtro=<?= $filtro ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
      <a href="?page=<?= $i ?>&q=<?= urlencode($buscar) ?>&filtro=<?= $filtro ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <a href="?page=<?= $page+1 ?>&q=<?= urlencode($buscar) ?>&filtro=<?= $filtro ?>" class="page-btn <?= $page>=$total_pages?'disabled':'' ?>">›</a>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>


<style>
.mobile-icon { display:none; }
@media (max-width: 768px) {
  .mobile-icon { display:inline-block; }
  .data-table thead { display:none; }
  .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
  .data-table tr {
    background:#fff; border-radius:12px; border:1px solid var(--gris-borde);
    margin-bottom:10px; padding:14px 16px;
  }
  .data-table tr:hover td { background:transparent; }
  .data-table td { padding:5px 0; border-bottom:none; font-size:13px; }
  /* Ocultar fecha realizada y casa en móvil */
  .data-table td:nth-child(5),
  .data-table td:nth-child(6) { display:none; }
  .data-table td:last-child { margin-top:10px; padding-top:10px; border-top:1px solid #f0f2f8; }
  .btn-ic { width:36px; height:36px; }
  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar form { flex-direction:column; }
  .search-wrap { max-width:100%; }
}
</style>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
