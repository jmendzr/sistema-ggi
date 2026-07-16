<?php
$page_title  = 'Empresas';
$active_menu = 'clientes';
require_once __DIR__ . '/../../templates/layout.php';

$pdo = Database::get();

// Filtros
$buscar   = trim($_GET['q'] ?? '');
$estado   = $_GET['estado'] ?? '';
$ejec_fil = (int)($_GET['ejecutivo'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 15;
$offset   = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];

if ($buscar) {
    $where[]  = '(c.razon_social LIKE ? OR c.ruc LIKE ? OR c.codigo_interno LIKE ?)';
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
}
if ($estado) {
    $where[]  = 'c.estado = ?';
    $params[] = $estado;
}
if ($ejec_fil) {
    $where[]  = 'c.ejecutivo_id = ?';
    $params[] = $ejec_fil;
}

$sql_where = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE $sql_where");
$total->execute($params);
$total_rows = $total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stmt = $pdo->prepare("
    SELECT c.*, u.nombre AS creado_por_nombre,
           ej.nombre AS ejecutivo_nombre, ej.apellido AS ejecutivo_apellido,
           (SELECT COUNT(*) FROM certificados WHERE cliente_id = c.id AND estado IN ('vigente','por_vencer')) AS total_certs
    FROM clientes c
    JOIN usuarios u ON u.id = c.creado_por
    LEFT JOIN usuarios ej ON ej.id = c.ejecutivo_id
    WHERE $sql_where
    ORDER BY c.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$clientes = $stmt->fetchAll();

// Lista de ejecutivos para el filtro
$ejecutivos_lista = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo = 1 ORDER BY nombre")->fetchAll();
?>

<style>
  .toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .search-wrap { position:relative; flex:1; max-width:360px; }
  .search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; transition:border-color 0.2s; }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; color:var(--gris-dark); cursor:pointer; }
  .filter-select:focus { border-color:var(--azul); }

  .table-wrap { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .data-table { width:100%; border-collapse:collapse; }
  .data-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; padding:12px 16px; text-align:left; background:#fafbfe; border-bottom:1px solid var(--gris-borde); white-space:nowrap; }
  .data-table td { padding:13px 16px; font-size:13px; border-bottom:1px solid #f0f2f8; color:var(--gris-dark); vertical-align:middle; }
  .data-table tr:last-child td { border-bottom:none; }
  .data-table tr:hover td { background:#fafbfe; }

  /* badge-*: globales en layout_end.php (dark mode incluido) */

  .action-btns { display:flex; gap:5px; align-items:center; }
  .btn-ic { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; border:none; cursor:pointer; text-decoration:none; transition:all 0.15s; flex-shrink:0; }
  .btn-ic-blue  { background:#e8edf8; color:#1A3A8F; } .btn-ic-blue:hover  { background:#1A3A8F; color:#fff; }
  .btn-ic-amber { background:#fff7e6; color:#b45309; } .btn-ic-amber:hover { background:#f59e0b; color:#fff; }
  .btn-ic-red   { background:#fff5f5; color:#dc2626; } .btn-ic-red:hover   { background:#ef4444; color:#fff; }

  .pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-top:1px solid var(--gris-borde); }
  .pagination-info { font-size:12px; color:var(--gris-texto); }
  .pagination-btns { display:flex; gap:6px; }
  .page-btn { width:32px; height:32px; border-radius:7px; display:flex; align-items:center; justify-content:center; border:1.5px solid var(--gris-borde); background:#fff; cursor:pointer; font-size:12px; font-weight:600; text-decoration:none; color:var(--gris-dark); transition:all 0.15s; }
  .page-btn:hover, .page-btn.active { background:var(--azul); color:#fff; border-color:var(--azul); }
  .page-btn.disabled { opacity:0.4; pointer-events:none; }

  .empty-state { padding:48px; text-align:center; color:var(--gris-texto); }
  .empty-state svg { margin-bottom:12px; opacity:0.25; }
  .empty-state h3 { font-size:15px; color:var(--gris-dark); margin-bottom:4px; }
</style>

<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end;margin-bottom:12px;">
    <a href="<?= APP_URL ?>/modules/exportar.php?modulo=clientes&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>"
       class="btn btn-outline btn-sm" title="Exportar a CSV/Excel">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar
    </a>
    <a href="<?= APP_URL ?>/modules/clientes/crear.php" class="btn btn-primary">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nueva empresa
    </a>
  </div>
</div>

<?php if (isset($_GET['eliminado'])): ?>
<div style="background:#fff5f5;border:1px solid #fecaca;border-left:4px solid #ef4444;padding:12px 16px;border-radius:8px;font-size:13px;color:#c0392b;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
  Cliente eliminado exitosamente.
</div>
<?php endif; ?>
<div class="toolbar">
  <form method="GET" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar por RUC o razón social..." value="<?= sanitize($buscar) ?>">
    </div>
    <select name="estado" class="filter-select" onchange="this.form.submit()">
      <option value="">Todos los estados</option>
      <option value="activo"    <?= $estado==='activo'    ?'selected':'' ?>>Activo</option>
      <option value="inactivo"  <?= $estado==='inactivo'  ?'selected':'' ?>>Inactivo</option>
      <option value="prospecto" <?= $estado==='prospecto' ?'selected':'' ?>>Prospecto</option>
    </select>
    <select name="ejecutivo" class="filter-select" onchange="this.form.submit()">
      <option value="">Todos los ejecutivos</option>
      <?php foreach ($ejecutivos_lista as $ej): ?>
      <option value="<?= $ej['id'] ?>" <?= $ejec_fil===$ej['id']?'selected':'' ?>>
        <?= sanitize($ej['nombre'] . ' ' . $ej['apellido']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php if ($buscar || $estado || $ejec_fil): ?>
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
    <a href="<?= APP_URL ?>/modules/clientes/crear.php" class="btn btn-primary" style="margin-top:16px;">Registrar primera empresa</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th>RUC</th>
        <th>Razón social</th>
        <th>Contacto</th>
        <th>Ejecutivo</th>
        <th>Certificados</th>
        <th>Estado</th>
        <th>Registrado</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($clientes as $c): ?>
      <?php
        $estado_color_map = ['activo' => '#10B981', 'inactivo' => '#6B7280', 'prospecto' => '#F59E0B'];
        $estado_color = $estado_color_map[$c['estado']] ?? '#6B7280';
        $estado_label = ucfirst($c['estado']);
      ?>
      <tr>
        <td style="font-family:monospace;font-size:12px;font-weight:600;"><?= sanitize($c['ruc']) ?></td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <div>
              <div style="font-weight:600;"><?= sanitize($c['razon_social']) ?></div>
              <?php if ($c['distrito']): ?>
              <div style="font-size:11px;color:var(--gris-texto);"><?= sanitize($c['distrito'].', '.$c['departamento']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <div>
              <?php if ($c['contacto_nombre']): ?>
              <div><?= sanitize($c['contacto_nombre'].' '.$c['contacto_apellido']) ?></div>
              <div style="font-size:11px;color:var(--gris-texto);"><?= sanitize($c['contacto_telefono']) ?></div>
              <?php else: ?>
              <span style="color:var(--gris-texto);">—</span>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <?php if ($c['ejecutivo_nombre']): ?>
          <span style="font-size:12px;font-weight:600;color:var(--azul)">
            <?= sanitize($c['ejecutivo_nombre'] . ' ' . $c['ejecutivo_apellido']) ?>
          </span>
          <?php else: ?>
          <span style="color:var(--gris-texto);font-size:12px;">—</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
            <div>
              <span style="font-weight:600;color:var(--azul)"><?= $c['total_certs'] ?></span>
              <span style="color:var(--gris-texto);font-size:12px;"> activo<?= $c['total_certs'] !== '1' ? 's' : '' ?></span>
            </div>
          </div>
        </td>
        <td><span style="background:<?= $estado_color ?>22;color:<?= $estado_color ?>;border:1px solid <?= $estado_color ?>55;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:600;white-space:nowrap;display:inline-block;"><?= $estado_label ?></span></td>
        <td style="font-size:12px;color:var(--gris-texto);"><?= formato_fecha($c['created_at']) ?></td>
        <td>
          <div class="action-btns">
            <a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $c['id'] ?>" class="btn-ic btn-ic-blue" title="Ver detalle">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <a href="<?= APP_URL ?>/modules/clientes/editar.php?id=<?= $c['id'] ?>&from=listado" class="btn-ic btn-ic-blue" title="Editar">
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
    <div class="pagination-info">
      Mostrando <?= $offset + 1 ?>–<?= min($offset + $limit, $total_rows) ?> de <?= $total_rows ?> clientes
    </div>
    <div class="pagination-btns">
      <a href="?page=<?= $page-1 ?>&q=<?= urlencode($buscar) ?>&estado=<?= $estado ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
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
  document.querySelectorAll('.alert-nuevo').forEach(el => {
    const msg = el.textContent.trim();
    if (msg && typeof showToast === 'function') {
      showToast(msg, 'ok');
      el.remove();
    }
  });
  document.querySelectorAll('[style*="fff5f5"]').forEach(el => {
    const msg = el.textContent.trim();
    if (msg && typeof showToast === 'function') {
      showToast(msg, 'error');
      el.remove();
    }
  });
});
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<!-- MOBILE RESPONSIVE -->
<style>
/* Ocultar iconos móvil en desktop */
.mobile-icon { display:none; }
.mobile-only { display:none; }

@media (max-width: 768px) {
  /* Mostrar iconos solo en móvil */
  .mobile-icon { display:inline-block; }
  .mobile-only { display:block; }

  .data-table thead { display:none; }
  .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
  .data-table tr {
    background:#fff; border-radius:12px; border:1px solid var(--gris-borde);
    margin-bottom:10px; padding:14px 16px; position:relative;
  }
  .data-table tr:hover td { background:transparent; }
  .data-table td { padding:5px 0; border-bottom:none; font-size:13px; }

  /* Ocultar columnas en móvil (RUC ya aparece en razon_social, fecha) */
  .data-table td:nth-child(1),
  .data-table td:nth-child(6) { display:none; }

  /* Estado con separador */
  .data-table td:nth-child(6) { margin-top:4px; }

  /* Botones acción */
  .data-table td:last-child {
    margin-top:10px; padding-top:10px;
    border-top:1px solid #f0f2f8;
  }
  .action-btns { gap:8px; }
  .btn-ic { width:36px; height:36px; }

  /* Toolbar */
  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar form { flex-direction:column; }
  .search-wrap { max-width:100%; }
  .toolbar > a { width:100%; justify-content:center; }
}
</style>
