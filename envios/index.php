<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$pdo    = Database::get();
$buscar = trim($_GET['q'] ?? '');
$estado = (int)($_GET['estado'] ?? 0);
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];
if ($buscar) {
    $where[]  = '(cl.razon_social LIKE ? OR e.codigo_envio LIKE ? OR e.destinatario LIKE ?)';
    $params   = array_merge($params, ["%$buscar%", "%$buscar%", "%$buscar%"]);
}
if ($estado) {
    $where[]  = 'e.estado_envio_id = ?';
    $params[] = $estado;
}
$sql_where = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM envios e JOIN clientes cl ON cl.id=e.cliente_id WHERE $sql_where");
$total->execute($params);
$total_rows  = $total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stmt = $pdo->prepare("
    SELECT e.*, cl.razon_social, cl.ruc,
           ag.nombre AS agencia,
           es.nombre AS estado_nombre, es.color_hex
    FROM envios e
    JOIN clientes cl       ON cl.id = e.cliente_id
    JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    JOIN estados_envio es  ON es.id = e.estado_envio_id
    WHERE $sql_where
    ORDER BY e.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$envios  = $stmt->fetchAll();
$estados = $pdo->query("SELECT * FROM estados_envio WHERE activo=1 ORDER BY orden")->fetchAll();

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
  .search-input { width:100%; padding:9px 12px 9px 38px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; }
  .search-input:focus { border-color:var(--azul); }
  .filter-select { padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; cursor:pointer; }
  .table-wrap { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .data-table { width:100%; border-collapse:collapse; }
  .data-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; padding:12px 16px; background:#fafbfe; border-bottom:1px solid var(--gris-borde); text-align:left; white-space:nowrap; }
  .data-table td { padding:13px 16px; font-size:13px; border-bottom:1px solid #f0f2f8; vertical-align:middle; }
  .data-table tr:last-child td { border-bottom:none; }
  .data-table tr:hover td { background:#fafbfe; }
  .norma-tag { display:inline-block; background:#e8edf8; color:var(--azul); font-size:11px; font-weight:700; padding:2px 7px; border-radius:6px; margin:2px 2px 2px 0; }
  .btn-ic { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; border:none; cursor:pointer; text-decoration:none; transition:all 0.15s; }
  .btn-ic-blue { background:#e8edf8; color:#1A3A8F; } .btn-ic-blue:hover { background:#1A3A8F; color:#fff; }
  .empty-state { padding:48px; text-align:center; color:var(--gris-texto); }
  .pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-top:1px solid var(--gris-borde); }
  .pagination-info { font-size:12px; color:var(--gris-texto); }
  .pagination-btns { display:flex; gap:6px; }
  .page-btn { width:32px; height:32px; border-radius:7px; display:flex; align-items:center; justify-content:center; border:1.5px solid var(--gris-borde); background:#fff; font-size:12px; font-weight:600; text-decoration:none; color:var(--gris-dark); transition:all 0.15s; }
  .page-btn:hover, .page-btn.active { background:var(--azul); color:#fff; border-color:var(--azul); }
  .page-btn.disabled { opacity:0.4; pointer-events:none; }
</style>

<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end;margin-bottom:12px;">
    <a href="<?= APP_URL ?>/modules/exportar.php?modulo=envios&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>"
       class="btn btn-outline btn-sm" title="Exportar a CSV/Excel">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar
    </a>
    <a href="<?= APP_URL ?>/modules/envios/crear.php" class="btn btn-primary">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Registrar envío
    </a>
  </div>
</div>

<div class="toolbar">
  <form method="GET" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;">
    <div class="search-wrap">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="search-input" placeholder="Buscar por cliente, código de envío..." value="<?= sanitize($buscar) ?>">
    </div>
    <select name="estado" class="filter-select" onchange="this.form.submit()">
      <option value="">Todos los estados</option>
      <?php foreach ($estados as $est): ?>
      <option value="<?= $est['id'] ?>" <?= $estado==$est['id']?'selected':'' ?>><?= sanitize($est['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($buscar || $estado): ?>
    <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <?php if (empty($envios)): ?>
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:0.25;"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
    <h3 style="font-size:15px;color:var(--gris-dark);margin-bottom:4px;"><?= $buscar?'Sin resultados':'Aún no hay envíos' ?></h3>
    <p style="font-size:13px;"><?= $buscar?'Prueba con otro término.':'Registra el primer envío.' ?></p>
    <?php if (!$buscar): ?>
    <a href="<?= APP_URL ?>/modules/envios/crear.php" class="btn btn-primary" style="margin-top:16px;">Registrar envío</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:28px;padding:12px 8px;">
          <input type="checkbox" id="chk-all" style="width:14px;height:14px;accent-color:var(--azul);cursor:pointer;" title="Seleccionar todos">
        </th>
        <th>Empresa</th>
        <th>ISOs enviados</th>
        <th>Agencia</th>
        <th>Receptor / DNI</th>
        <th>Cód. seguimiento</th>
        <th>Estado</th>
        <th>Fecha envío</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($envios as $e): $certs = $certs_map[$e['id']] ?? []; ?>
      <tr>
        <td style="width:28px;padding:13px 8px;vertical-align:middle;">
          <input type="checkbox" class="chk-envio" value="<?= $e['id'] ?>" style="width:14px;height:14px;accent-color:var(--azul);cursor:pointer;">
        </td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <div>
              <div style="font-weight:600;"><?= sanitize($e['razon_social']) ?></div>
              <div style="font-size:11px;color:var(--gris-texto);"><?= sanitize($e['ruc']) ?></div>
            </div>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
            <div>
              <?php if (empty($certs)): ?>
              <span style="color:var(--gris-texto);">—</span>
              <?php else: ?>
              <?php foreach ($certs as $c): ?>
              <span class="norma-tag"><?= sanitize($c['norma_codigo']) ?></span>
              <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0;"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            <?= sanitize($e['agencia']) ?>
          </div>
        </td>
        <td>
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <svg class="mobile-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6b7a99" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <div>
              <?php if ($e['destinatario']): ?>
              <div style="font-weight:600;"><?= sanitize($e['destinatario']) ?></div>
              <?php endif; ?>
              <?php if ($e['destinatario_dni']): ?>
              <div style="font-size:11px;color:var(--gris-texto);">DNI: <?= sanitize($e['destinatario_dni']) ?></div>
              <?php endif; ?>
              <?php if (!$e['destinatario'] && !$e['destinatario_dni']): ?>
              <span style="color:var(--gris-texto);">—</span>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <?php if ($e['codigo_envio']): ?>
          <span style="font-family:monospace;font-size:12px;background:#f0f2f8;padding:2px 8px;border-radius:6px;"><?= sanitize($e['codigo_envio']) ?></span>
          <?php else: ?>
          <span style="color:var(--gris-texto);">—</span>
          <?php endif; ?>
        </td>
        <td>
          <select class="estado-select" data-id="<?= $e['id'] ?>"
            style="border:1.5px solid <?= $e['color_hex'] ?>55;background:<?= $e['color_hex'] ?>22;color:<?= $e['color_hex'] ?>;border-radius:100px;font-size:11px;font-weight:600;padding:3px 10px;outline:none;cursor:pointer;appearance:none;-webkit-appearance:none;font-family:'Plus Jakarta Sans',sans-serif;max-width:130px;">
            <?php foreach ($estados as $est): ?>
            <option value="<?= $est['id'] ?>" <?= $est['id']==$e['estado_envio_id']?'selected':'' ?>
              data-color="<?= $est['color_hex'] ?>"><?= sanitize($est['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td style="font-size:12px;"><?= formato_fecha($e['fecha_envio']) ?></td>
        <td>
          <div style="display:flex;gap:4px;">
            <a href="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>" class="btn-ic btn-ic-blue" title="Ver detalle">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <a href="<?= APP_URL ?>/modules/envios/editar.php?id=<?= $e['id'] ?>&from=listado" class="btn-ic btn-ic-blue" title="Editar">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <!-- Desktop bar is now floating (see bottom of page) -->

  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <div class="pagination-info">Mostrando <?= $offset+1 ?>–<?= min($offset+$limit,$total_rows) ?> de <?= $total_rows ?></div>
    <div class="pagination-btns">
      <a href="?page=<?= $page-1 ?>&q=<?= urlencode($buscar) ?>&estado=<?= $estado ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
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
  const p = new URLSearchParams(window.location.search);
  const m = p.get('msg');
  if (m && typeof showToast === 'function') {
    showToast(decodeURIComponent(m), 'ok');
    history.replaceState({}, '', window.location.pathname);
  }

  // ── Estado inline individual ──
  document.querySelectorAll('.estado-select').forEach(sel => {
    sel.addEventListener('change', async function() {
      const envioId  = this.dataset.id;
      const estadoId = this.value;
      const opt      = this.options[this.selectedIndex];
      const color    = opt.dataset.color;
      try {
        const r = await fetch(`<?= APP_URL ?>/modules/envios/ajax_estado.php`, {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':window.CSRF_TOKEN||''},
          body: `envio_id=${envioId}&estado_id=${estadoId}`
        });
        const data = await r.json();
        if (data.ok) {
          this.style.borderColor = color + '55';
          this.style.background  = color + '22';
          this.style.color       = color;
          if (typeof showToast === 'function') showToast('Estado actualizado.', 'ok');
        } else {
          if (typeof showToast === 'function') showToast('Error al actualizar.', 'error');
        }
      } catch(e) {
        if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
      }
    });
  });

  // ── Checkboxes ──
  const chkAll = document.getElementById('chk-all');
  const isMobile = () => window.innerWidth <= 768;

  function textoSeleccionados(n) {
    return n + ' envío' + (n !== 1 ? 's' : '') + ' seleccionado' + (n !== 1 ? 's' : '');
  }

  function actualizarBulkBar() {
    const selected = document.querySelectorAll('.chk-envio:checked');
    const n = selected.length;
    const total = document.querySelectorAll('.chk-envio').length;

    // Desktop
    const barDesktop = document.getElementById('bulk-bar-desktop');
    const cntDesktop = document.getElementById('bulk-count-desktop');
    // Mobile
    const barMobile  = document.getElementById('bulk-bar-mobile');
    const cntMobile  = document.getElementById('bulk-count-mobile');

    if (n > 0) {
      barMobile.style.setProperty('display', 'block', 'important');
      // Respetar si el sidebar está colapsado
      const sidebarCollapsed = document.querySelector('.sidebar')?.classList.contains('collapsed');
      barMobile.style.left = (sidebarCollapsed || window.innerWidth <= 768) ? '0' : 'var(--sidebar-w)';
      document.querySelector('.main').style.paddingBottom = '100px';
    } else {
      barMobile.style.removeProperty('display');
      document.querySelector('.main').style.paddingBottom = '';
    }

    if (cntDesktop) cntDesktop.textContent = textoSeleccionados(n);
    if (cntMobile)  cntMobile.textContent  = textoSeleccionados(n);

    if (chkAll) {
      chkAll.indeterminate = n > 0 && n < total;
      chkAll.checked = n === total && total > 0;
    }
  }

  chkAll?.addEventListener('change', function() {
    document.querySelectorAll('.chk-envio').forEach(c => c.checked = this.checked);
    actualizarBulkBar();
  });

  document.querySelectorAll('.chk-envio').forEach(c => {
    c.addEventListener('change', actualizarBulkBar);
  });
});

function deseleccionarTodo() {
  document.querySelectorAll('.chk-envio').forEach(c => c.checked = false);
  const chkAll = document.getElementById('chk-all');
  if (chkAll) { chkAll.checked = false; chkAll.indeterminate = false; }
  document.getElementById('bulk-bar-mobile').style.removeProperty('display');
  document.querySelector('.main').style.paddingBottom = '';
}

async function aplicarEstadoMasivo(origen) {
  const selectId = origen === 'mobile' ? 'bulk-estado-mobile' : 'bulk-estado-desktop';
  const estadoId = document.getElementById(selectId).value;
  if (!estadoId) {
    if (typeof showToast === 'function') showToast('Selecciona un estado.', 'error');
    return;
  }

  const ids = [...document.querySelectorAll('.chk-envio:checked')].map(c => c.value);
  if (!ids.length) return;

  try {
    const r = await fetch(`<?= APP_URL ?>/modules/envios/ajax_estado.php`, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':window.CSRF_TOKEN||''},
      body: `envio_ids=${ids.join(',')}&estado_id=${estadoId}`
    });
    const data = await r.json();
    if (data.ok) {
      if (typeof showToast === 'function') showToast('Estado actualizado en ' + ids.length + ' envío(s).', 'ok');
      if (typeof _mantRefreshTable === 'function') _mantRefreshTable();
    } else {
      if (typeof showToast === 'function') showToast('Error al actualizar.', 'error');
    }
  } catch(e) {
    if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
  }
}
</script>

<style>
.mobile-icon { display:none; }
/* Barra móvil oculta en desktop */
#bulk-bar-mobile { display:none !important; }
@media (max-width: 768px) {
  /* Barra desktop oculta en móvil */
  #bulk-bar-desktop { display:none !important; }
  /* Barra móvil manejada por JS */
  #bulk-bar-mobile { display:none; }
  .mobile-icon { display:inline-block; }
  .data-table thead { display:none; }
  .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
  .data-table tr {
    background:#fff; border-radius:12px; border:1px solid var(--gris-borde);
    margin-bottom:10px; padding:14px 16px; position:relative;
  }
  .data-table tr:hover td { background:transparent; }
  .data-table td { padding:5px 0; border-bottom:none; font-size:13px; }

  /* Checkbox: pequeño, posición absoluta arriba a la derecha */
  .data-table td:nth-child(1) {
    position:absolute; top:14px; right:14px;
    width:auto; padding:0;
  }
  .chk-envio { width:14px !important; height:14px !important; }

  /* Ocultar código seguimiento */
  .data-table td:nth-child(6) { display:none; }

  /* Estado: tamaño compacto, no full width */
  .estado-select {
    max-width:140px !important;
    border-radius:100px !important;
    font-size:11px !important;
    padding:4px 10px !important;
  }

  .data-table td:last-child { margin-top:10px; padding-top:10px; border-top:1px solid #f0f2f8; }
  .action-btns { gap:8px; }
  .btn-ic { width:36px; height:36px; }
  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar form { flex-direction:column; }
  .search-wrap { max-width:100%; }
}
</style>

<!-- Barra flotante fija inferior (móvil y desktop) -->
<div id="bulk-bar-mobile" style="display:none;position:fixed;bottom:0;right:0;left:var(--sidebar-w);z-index:150;background:#fff;border-top:2px solid var(--gris-borde);padding:12px 20px 16px;box-shadow:0 -4px 20px rgba(0,0,0,0.12);">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
    <span id="bulk-count-mobile" style="font-size:13px;font-weight:600;color:var(--azul);">0 seleccionados</span>
    <button onclick="deseleccionarTodo()" style="background:none;border:none;font-size:12px;color:var(--gris-texto);cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;font-weight:600;">Cancelar</button>
  </div>
  <div style="display:flex;gap:8px;">
    <div style="position:relative;flex:1;">
      <select id="bulk-estado-mobile" style="width:100%;padding:10px 32px 10px 12px;border:1.5px solid var(--azul);border-radius:9px;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--azul);font-weight:600;background:#fff;outline:none;appearance:none;-webkit-appearance:none;cursor:pointer;">
        <option value="">— Cambiar estado —</option>
        <?php foreach ($estados as $est): ?>
        <option value="<?= $est['id'] ?>"><?= sanitize($est['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--azul);"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <button onclick="aplicarEstadoMasivo('mobile')" style="padding:10px 20px;background:var(--azul);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;white-space:nowrap;">
      Aplicar
    </button>
  </div>
</div>
<style>
@media (max-width: 768px) {
  #bulk-bar-mobile { left: 0 !important; }
}
</style>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
