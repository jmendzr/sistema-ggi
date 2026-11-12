<?php
/**
 * modules/notificaciones/log.php
 * Log interno de notificaciones WhatsApp: qué se envió, qué falló, y reintento manual.
 * Usa componentes canónicos: .kpi-* / .ggi-pill / .data-table / .empty-state / .ggi-filtros / ggi_pager / .btn.
 */
$page_title  = 'Log de WhatsApp';
$active_menu = 'notif_log';
require_once __DIR__ . '/../../templates/layout.php';

require_role('gerente_general', 'gerente_operaciones');

$pdo = Database::get();

// ── Catálogo de eventos (para etiquetas y filtro) ───────────────────────────
$eventos = [];
foreach ($pdo->query("SELECT code, name FROM notification_events ORDER BY name") as $e) {
    $eventos[$e['code']] = $e['name'];
}

// ── Filtros ─────────────────────────────────────────────────────────────────
$estado_sel = array_values(array_filter((array)($_GET['estado'] ?? []), fn($v) => $v !== ''));
$event_sel  = array_values(array_filter((array)($_GET['evento'] ?? []), fn($v) => $v !== ''));
$q          = trim((string)($_GET['q'] ?? ''));
$desde      = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta      = $_GET['hasta'] ?? date('Y-m-d');
$page       = max(1, (int)($_GET['page'] ?? 1));
$_pp_opts   = [10, 20, 30];
$_pp_req    = (int)($_GET['per_page'] ?? $_SESSION['per_page_list'] ?? 10);
$limit      = in_array($_pp_req, $_pp_opts) ? $_pp_req : 10;
$_SESSION['per_page_list'] = $limit;
$offset     = ($page - 1) * $limit;

$where  = ['w.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$desde, $hasta];

if ($estado_sel) {
    $ph = implode(',', array_fill(0, count($estado_sel), '?'));
    $where[] = "w.estado IN ($ph)";
    $params  = array_merge($params, $estado_sel);
}
if ($event_sel) {
    $ph = implode(',', array_fill(0, count($event_sel), '?'));
    $where[] = "w.event_code IN ($ph)";
    $params  = array_merge($params, $event_sel);
}
if ($q !== '') {
    $where[] = "(c.razon_social LIKE ? OR w.telefono LIKE ? OR w.template_name LIKE ?)";
    $like    = "%$q%";
    $params  = array_merge($params, [$like, $like, $like]);
}
$sql_where = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_log w LEFT JOIN clientes c ON c.id = w.cliente_id WHERE $sql_where");
$total->execute($params);
$total_rows = (int)$total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT w.*, c.razon_social
    FROM whatsapp_log w
    LEFT JOIN clientes c ON c.id = w.cliente_id
    WHERE $sql_where
    ORDER BY w.id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ───────────────────────────────────────────────────────────────────
$st_hoy   = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_log WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$st_fail  = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_log WHERE estado='failed'")->fetchColumn();
$st_ok7   = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_log WHERE estado IN ('sent','delivered','read') AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
$st_total = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_log")->fetchColumn();

// estado → [etiqueta, clase de pill canónica]
$estado_meta = [
    'sent'      => ['Enviado',   'ggi-pill-ok'],
    'delivered' => ['Entregado', 'ggi-pill-ok'],
    'read'      => ['Leído',     'ggi-pill-info'],
    'queued'    => ['En cola',   'ggi-pill-muted'],
    'failed'    => ['Fallido',   'ggi-pill-err'],
];
?>
<style>
  /* Patrón local canónico de listado (design-system.css: cada módulo define .toolbar/.filter-input). */
  .toolbar { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; align-items:center; }
  .filter-input {
    padding:8px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif;
    background:var(--bg-surface); color:var(--gris-dark); outline:none;
  }
  .filter-input:focus { border-color:var(--azul); }
  .table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .wl-err { font-size:11px; color:#c53030; max-width:220px; display:inline-block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle; }
  .wl-tpl { font-size:11px; font-family:monospace; color:var(--gris-texto); }
  @media(max-width:768px){
    .toolbar { flex-direction:column; align-items:stretch; }
    .data-table thead { display:none; }
    .data-table, .data-table tbody, .data-table tr, .data-table td { display:block; width:100%; }
    .data-table tr {
      background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde);
      margin-bottom:10px; padding:12px 14px; box-sizing:border-box;
    }
    .data-table tr:hover td { background:transparent; }
    .data-table td { padding:4px 0; border-bottom:none; font-size:13px; }
    .data-table td:nth-child(4), .data-table td:nth-child(5) { display:none; } /* Plantilla y Teléfono */
    .data-table td.wl-act-empty { display:none; }   /* sin acción → no ocupar línea en la tarjeta */
    .data-table td.wl-det-min { display:none; }      /* "ID ✓"/"—" no aportan en la tarjeta móvil */
    .data-table td.wl-act { text-align:right; margin-top:2px; }
    .wl-err { max-width:100%; white-space:normal; }
    .filter-input[type=date] { width:100%; }
  }
</style>

<div class="page-header" style="margin-bottom:20px;">
  <div>
    <h1>Log de WhatsApp</h1>
    <p>Notificaciones enviadas al cliente por WhatsApp: estado, fallos y reintento</p>
  </div>
</div>

<div class="kpi-grid ggi-stagger">
  <div class="kpi-card"><div class="kpi-label">Mensajes hoy</div><div class="kpi-value ggi-num"><?= number_format($st_hoy) ?></div></div>
  <div class="kpi-card"><div class="kpi-label">Fallidos (total)</div><div class="kpi-value ggi-num <?= $st_fail ? 'red' : 'muted' ?>"><?= number_format($st_fail) ?></div></div>
  <div class="kpi-card"><div class="kpi-label">Enviados (7 días)</div><div class="kpi-value ggi-num"><?= number_format($st_ok7) ?></div></div>
  <div class="kpi-card"><div class="kpi-label">Total registros</div><div class="kpi-value ggi-num"><?= number_format($st_total) ?></div></div>
</div>

<!-- Filtros -->
<?php $_nfiltros = (!empty($estado_sel) ? 1 : 0) + (!empty($event_sel) ? 1 : 0) + ($q !== '' ? 1 : 0); ?>
<form method="GET" class="toolbar <?= $_nfiltros ? 'filtros-open' : '' ?>">
  <button type="button" class="ggi-filtros-toggle" onclick="this.closest('form').classList.toggle('filtros-open')" aria-label="Mostrar u ocultar filtros">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
    Filtros<?php if ($_nfiltros): ?> <span class="ggi-filtros-badge"><?= $_nfiltros ?></span><?php endif; ?>
  </button>
  <div class="ggi-filtros">
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" class="filter-input" placeholder="Empresa, teléfono o plantilla…" aria-label="Buscar">
    <?php
      $_est_opts = [];
      foreach ($estado_meta as $k => $m) $_est_opts[$k] = $m[0];
      echo ggi_msdrop('ms-wl-estado', 'estado', $_est_opts, $estado_sel, ['placeholder' => 'Todos los estados', 'aria' => 'Filtrar por estado']);
      echo ggi_msdrop('ms-wl-evento', 'evento', $eventos, $event_sel, ['placeholder' => 'Todos los eventos', 'aria' => 'Filtrar por evento']);
    ?>
    <input type="date" name="desde" value="<?= $desde ?>" class="filter-input" onchange="this.form.submit()">
    <input type="date" name="hasta" value="<?= $hasta ?>" class="filter-input" onchange="this.form.submit()">
  </div><!-- /.ggi-filtros -->
  <div class="ggi-filtros-actions">
    <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
    <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
  </div>
  <span style="font-size:12px;color:var(--gris-texto);margin-left:auto;"><?= number_format($total_rows) ?> registros</span>
</form>

<!-- Tabla -->
<div class="table-wrap">
  <?php if (empty($logs)): ?>
  <div class="empty-state">
    <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 10px;display:block;opacity:.25"><path d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-5l-5 4v-4z"/></svg>
    Sin notificaciones para los filtros seleccionados
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="data-table">
    <thead>
      <tr>
        <th scope="col">Fecha</th>
        <th scope="col">Empresa</th>
        <th scope="col">Evento</th>
        <th scope="col">Plantilla</th>
        <th scope="col">Teléfono</th>
        <th scope="col">Estado</th>
        <th scope="col">Detalle</th>
        <th scope="col">Acción</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $l):
        $em = $estado_meta[$l['estado']] ?? [$l['estado'], 'ggi-pill-muted'];
        $ev = $eventos[$l['event_code']] ?? $l['event_code'];
        $ic = gg_notif_icono($l['event_code']);
    ?>
      <tr data-id="<?= (int)$l['id'] ?>">
        <td style="white-space:nowrap;font-size:12px;color:var(--gris-texto);">
          <?= date('d/m/Y', strtotime($l['created_at'])) ?><br>
          <strong style="color:var(--gris-dark)"><?= date('H:i:s', strtotime($l['created_at'])) ?></strong>
        </td>
        <td style="font-size:12px;font-weight:600"><?= $l['razon_social'] ? htmlspecialchars($l['razon_social']) : '<em style="color:var(--gris-texto)">—</em>' ?></td>
        <td style="font-size:12px">
          <span style="display:inline-flex;align-items:center;gap:8px;">
            <span style="width:26px;height:26px;border-radius:7px;background:<?= $ic['bg'] ?>;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">
              <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="<?= $ic['color'] ?>" stroke-width="2"><?= $ic['icon'] ?></svg>
            </span>
            <?= htmlspecialchars($ev) ?>
          </span>
        </td>
        <td><span class="wl-tpl"><?= htmlspecialchars($l['template_name']) ?></span></td>
        <td style="font-size:12px;font-family:monospace"><?= htmlspecialchars($l['telefono'] ?: '—') ?></td>
        <td><span class="ggi-pill <?= $em[1] ?>"><?= htmlspecialchars($em[0]) ?></span></td>
        <?php if ($l['estado'] === 'failed' && $l['error_msg']): ?>
        <td><span class="wl-err" title="<?= htmlspecialchars($l['error_msg']) ?>"><?= htmlspecialchars($l['error_msg']) ?></span></td>
        <?php elseif ($l['wa_message_id']): ?>
        <td class="wl-det-min"><span style="font-size:10px;color:var(--gris-texto);font-family:monospace" title="<?= htmlspecialchars($l['wa_message_id']) ?>">ID ✓</span></td>
        <?php else: ?>
        <td class="wl-det-min"><span style="color:var(--gris-texto)">—</span></td>
        <?php endif; ?>
        <?php if ($l['estado'] === 'failed'): ?>
        <td class="wl-act">
          <div class="ggi-row-menu-wrap">
            <button type="button" class="ggi-row-menu-btn" title="Más acciones" aria-label="Más acciones" aria-haspopup="true" aria-expanded="false">
              <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
            </button>
            <div class="ggi-row-menu">
              <button type="button" class="ggi-row-menu-item" onclick="wlRetry(this, <?= (int)$l['id'] ?>)">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Reintentar
              </button>
            </div>
          </div>
        </td>
        <?php else: ?>
        <td class="wl-act-empty"></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div><!-- /overflow-x -->
  <?php endif; ?>
</div>

<!-- Paginación -->
<?php if ($total_rows > 0): ?>
  <?= ggi_pager($total_rows, $page, $limit, [
      'per_page_options' => [10, 20, 30],
      'param_page'       => 'page',
      'noun'             => 'registro',
      'noun_plural'      => 'registros',
  ]) ?>
<?php endif; ?>

<script>
function wlRetry(btn, id) {
  if (btn.disabled) return;
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = 'Enviando…';
  // ggiPost: adjunta el _csrf y absorbe su rotación. silent porque este
  // sitio muestra su propio aviso con GGIToast.
  ggiPost('<?= APP_URL ?>/modules/notificaciones/log_retry.php', { id: id }, { silent: true })
  .then(d => {
      const msg = d.msg || d.mensaje || (d.ok ? 'Reintentado.' : 'No se pudo reintentar.');
      if (typeof GGIToast !== 'undefined') GGIToast[d.ok ? 'success' : 'error'](msg);
      else (window.showToast ? showToast(msg, 'error') : alert(msg));
      if (d.ok) {
          const row = btn.closest('tr');
          if (row) row.style.opacity = '.5';
          setTimeout(() => location.reload(), 900);
      } else {
          btn.disabled = false;
          btn.innerHTML = original;
      }
  });
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
