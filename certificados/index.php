<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$pdo = Database::get();

$buscar  = trim($_GET['q'] ?? '');
$estado  = $_GET['estado'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 15;
$offset  = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];

if ($buscar) {
    $where[]  = '(cl.razon_social LIKE ? OR cl.ruc LIKE ? OR n.codigo LIKE ? OR c.numero_certificado LIKE ?)';
    $params   = array_merge($params, ["%$buscar%","%$buscar%","%$buscar%","%$buscar%"]);
}
if ($estado) {
    $where[]  = 'c.estado = ?';
    $params[] = $estado;
}

$sql_where = implode(' AND ', $where);

$total     = $pdo->prepare("SELECT COUNT(*) FROM certificados c JOIN clientes cl ON cl.id=c.cliente_id JOIN normas_iso n ON n.id=c.norma_iso_id WHERE $sql_where");
$total->execute($params);
$total_rows  = $total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stmt = $pdo->prepare("
    SELECT c.*, cl.razon_social, cl.codigo_interno, cl.ruc,
           n.codigo AS norma_codigo, n.nombre AS norma_nombre,
           cc.nombre AS casa_nombre,
           DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_vencimiento
    FROM certificados c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN normas_iso n ON n.id = c.norma_iso_id
    JOIN casas_certificadoras cc ON cc.id = c.casa_certificadora_id
    WHERE $sql_where
    ORDER BY c.fecha_vencimiento ASC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$certificados = $stmt->fetchAll();

$page_title  = 'Certificados';
$active_menu = 'certificados';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; max-width:360px; }
  .search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; }
  .table-wrap { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .data-table { width:100%; border-collapse:collapse; }
  .data-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; padding:12px 16px; background:#fafbfe; border-bottom:1px solid var(--gris-borde); text-align:left; white-space:nowrap; }
  .data-table td { padding:13px 16px; font-size:13px; border-bottom:1px solid #f0f2f8; vertical-align:middle; }
  .data-table tr:last-child td { border-bottom:none; }
  .data-table tr:hover td { background:#fafbfe; }
  /* badge-*, dias-chip: globales en layout_end.php (dark mode incluido) */
  .dias-chip { font-size:11px; font-weight:600; border-radius:100px; }
  .empty-state { padding:48px; text-align:center; color:var(--gris-texto); }
  .pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-top:1px solid var(--gris-borde); }
  .pagination-info { font-size:12px; color:var(--gris-texto); }
  .pagination-btns { display:flex; gap:6px; }
  .page-btn { width:32px; height:32px; border-radius:7px; display:flex; align-items:center; justify-content:center; border:1.5px solid var(--gris-borde); background:#fff; cursor:pointer; font-size:12px; font-weight:600; text-decoration:none; color:var(--gris-dark); transition:all 0.15s; }
  .page-btn:hover, .page-btn.active { background:var(--azul); color:#fff; border-color:var(--azul); }
  .page-btn.disabled { opacity:0.4; pointer-events:none; }
  .action-btns { display:flex; gap:5px; }
  .btn-ic { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; border:none; cursor:pointer; text-decoration:none; transition:all 0.15s; }
  .btn-ic-blue { background:#e8edf8; color:#1A3A8F; } .btn-ic-blue:hover { background:#1A3A8F; color:#fff; }
  .btn-ic-red  { background:#fff5f5; color:#dc2626; } .btn-ic-red:hover  { background:#ef4444; color:#fff; }
  @media (max-width:768px) {
    .data-table thead { display:none; }
    .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
    .data-table tr { background:#fff; border-radius:10px; border:1px solid var(--gris-borde); margin-bottom:10px; padding:14px 16px; }
    .data-table td { padding:3px 0; border:none; }
    .action-btns { margin-top:10px; padding-top:10px; border-top:1px solid #f0f2f8; }
    .toolbar { flex-direction:column; align-items:stretch; }
    .search-wrap { max-width:100%; }
  }
</style>

<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end;margin-bottom:12px;">
    <a href="<?= APP_URL ?>/modules/exportar.php?modulo=certificados&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>"
       class="btn btn-outline btn-sm" title="Exportar a CSV/Excel">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar
    </a>
    <a href="<?= APP_URL ?>/modules/certificados/crear.php" class="btn btn-primary">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nuevo certificado
    </a>
  </div>
</div>

<div class="toolbar">
  <form method="GET" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar por cliente, norma, número..." value="<?= sanitize($buscar) ?>">
    </div>
    <select name="estado" class="filter-select" onchange="this.form.submit()">
      <option value="">Todos los estados</option>
      <option value="vigente"    <?= $estado==='vigente'    ?'selected':'' ?>>Vigente</option>
      <option value="por_vencer" <?= $estado==='por_vencer' ?'selected':'' ?>>Por vencer</option>
      <option value="vencido"    <?= $estado==='vencido'    ?'selected':'' ?>>Vencido</option>
      <option value="en_proceso" <?= $estado==='en_proceso' ?'selected':'' ?>>En proceso</option>
      <option value="suspendido" <?= $estado==='suspendido' ?'selected':'' ?>>Suspendido</option>
    </select>
    <?php if ($buscar || $estado): ?>
      <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($certificados)): ?>
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:0.25;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
    <h3 style="font-size:15px;color:var(--gris-dark);margin-bottom:4px;"><?= $buscar ? 'Sin resultados' : 'Aún no hay certificados' ?></h3>
    <p style="font-size:13px;">
      <?= $buscar ? 'Prueba con otro término.' : 'Registra el primer certificado.' ?>
    </p>
    <?php if (!$buscar): ?>
    <a href="<?= APP_URL ?>/modules/certificados/crear.php" class="btn btn-primary" style="margin-top:16px;">Registrar certificado</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th>Empresa</th>
        <th>Norma</th>
        <th>Casa certif.</th>
        <th>N° Certificado</th>
        <th>Emisión</th>
        <th>Vencimiento</th>
        <th>Estado</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($certificados as $c): ?>
      <?php
        $dias = (int)$c['dias_vencimiento'];
        $estado_color_map = [
          'vigente'    => '#10B981',
          'por_vencer' => '#F59E0B',
          'vencido'    => '#EF4444',
          'en_proceso' => '#3B82F6',
          'suspendido' => '#6B7280',
        ];
        $estado_color = $estado_color_map[$c['estado']] ?? '#6B7280';
        $label_estado_map = [
          'vigente'    => 'Vigente',
          'por_vencer' => 'Por vencer',
          'vencido'    => 'Vencido',
          'en_proceso' => 'En proceso',
          'suspendido' => 'Suspendido',
        ];
        $label_estado = $label_estado_map[$c['estado']] ?? $c['estado'];
      ?>
      <tr>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <div>
              <div style="font-weight:600;"><?= sanitize($c['razon_social']) ?></div>
              <div style="font-size:11px;color:var(--gris-texto);"><?= sanitize($c['ruc']) ?></div>
            </div>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
            <strong style="color:var(--azul)"><?= sanitize($c['norma_codigo']) ?></strong>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <?= sanitize($c['casa_nombre']) ?>
          </div>
        </td>
        <td style="font-family:monospace;font-size:12px;"><?= $c['numero_certificado'] ? sanitize($c['numero_certificado']) : '—' ?></td>
        <td><?= formato_fecha($c['fecha_emision']) ?></td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#F59E0B" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <div>
              <?= formato_fecha($c['fecha_vencimiento']) ?>
              <?php if ($c['estado'] !== 'vencido' && $c['estado'] !== 'suspendido'): ?>
              <div style="margin-top:2px;">
                <span class="dias-chip <?= $dias <= 30 ? 'badge-red' : ($dias <= 90 ? 'badge-amber' : 'badge-green') ?>">
                  <?= $dias > 0 ? "$dias días" : 'Vencido' ?>
                </span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td><span style="background:<?= $estado_color ?>22;color:<?= $estado_color ?>;border:1px solid <?= $estado_color ?>55;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:600;white-space:nowrap;display:inline-block;"><?= $label_estado ?></span></td>
        <td>
          <div class="action-btns">
            <a href="<?= APP_URL ?>/modules/certificados/ver.php?id=<?= $c['id'] ?>" class="btn-ic btn-ic-blue" title="Ver detalle">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <a href="<?= APP_URL ?>/modules/certificados/editar.php?id=<?= $c['id'] ?>&from=listado" class="btn-ic btn-ic-blue" title="Editar">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <div class="pagination-info">Mostrando <?= $offset+1 ?>–<?= min($offset+$limit,$total_rows) ?> de <?= $total_rows ?></div>
    <div class="pagination-btns">
      <a href="?page=<?= $page-1 ?>&q=<?= urlencode($buscar) ?>&estado=<?= $estado ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for ($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
      <a href="?page=<?= $i ?>&q=<?= urlencode($buscar) ?>&estado=<?= $estado ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <a href="?page=<?= $page+1 ?>&q=<?= urlencode($buscar) ?>&estado=<?= $estado ?>" class="page-btn <?= $page>=$total_pages?'disabled':'' ?>">›</a>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const msg = params.get('msg');
  if (msg && typeof showToast === 'function') {
    showToast(decodeURIComponent(msg), 'ok');
    history.replaceState({}, '', window.location.pathname);
  }
});
</script>

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
  /* Ocultar: N° cert, emisión en móvil */
  .data-table td:nth-child(4),
  .data-table td:nth-child(5) { display:none; }
  .data-table td:last-child { margin-top:10px; padding-top:10px; border-top:1px solid #f0f2f8; }
  .action-btns { gap:8px; }
  .btn-ic { width:36px; height:36px; }
  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar form { flex-direction:column; flex-wrap:nowrap; }
  .search-wrap { max-width:100%; }
}
</style>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
