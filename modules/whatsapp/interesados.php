<?php
/**
 * modules/whatsapp/interesados.php — Clics de botones de interés ("Quiero aprovechar").
 *
 * Lista quién pulsó el botón de una campaña/promo (o del aviso de cotización por vencer),
 * con el cliente, el teléfono, la fecha del clic y el ejecutivo a cargo — para que
 * el asesor correspondiente contacte. Cada clic llega como un mensaje entrante cuyo
 * texto es el título del botón (lo captura modules/whatsapp/webhook_meta.php →
 * WaInbox::marcarLeadCaliente, que además notifica al ejecutivo del cliente).
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones','gerente_comercial');

$pdo = Database::get();

// ── Filtros ─────────────────────────────────────────────────────────────────
$desde  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : '';
$hasta  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : '';
$fuente = in_array($_GET['fuente'] ?? '', ['promo', 'cotizacion'], true) ? $_GET['fuente'] : '';

$where = ["m.direccion = 'in'", "LOWER(TRIM(m.texto)) IN ('quiero aprovechar','quiero aprovecharlo')"];
$args  = [];
if ($desde) { $where[] = 'm.created_at >= ?'; $args[] = $desde . ' 00:00:00'; }
if ($hasta) { $where[] = 'm.created_at <= ?'; $args[] = $hasta . ' 23:59:59'; }
$sqlWhere = implode(' AND ', $where);

$sql = "
    SELECT x.conv_id, x.fuente, x.fecha, c.telefono, c.cliente_id, c.lead_caliente_at,
           COALESCE(cl.razon_social, c.perfil_nombre, CONCAT('+', c.telefono)) AS cliente,
           cl.ejecutivo_id,
           NULLIF(TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))), '') AS asesor
    FROM (
        SELECT conversacion_id AS conv_id,
               CASE WHEN LOWER(TRIM(texto)) = 'quiero aprovecharlo' THEN 'cotizacion' ELSE 'promo' END AS fuente,
               MIN(created_at) AS fecha
        FROM wa_mensajes m
        WHERE $sqlWhere
        GROUP BY conversacion_id, fuente
    ) x
    JOIN wa_conversaciones c ON c.id = x.conv_id
    LEFT JOIN clientes cl ON cl.id = c.cliente_id
    LEFT JOIN usuarios  u  ON u.id = cl.ejecutivo_id
    " . ($fuente ? "WHERE x.fuente = " . $pdo->quote($fuente) . " " : "") . "
    ORDER BY x.fecha DESC";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Exportar CSV ────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="interesados_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Cliente', 'Teléfono', 'Origen', 'Asesor a cargo']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['fecha'], $r['cliente'], $r['telefono'], $r['fuente'] === 'promo' ? 'Promoción' : 'Cotización por vencer', $r['asesor'] ?: 'Sin asignar']);
    }
    fclose($out);
    exit;
}

// ── KPIs ────────────────────────────────────────────────────────────────────
$kpiTotal = count($rows);
$kpiPromo = count(array_filter($rows, fn($r) => $r['fuente'] === 'promo'));
$kpiCot   = $kpiTotal - $kpiPromo;
$kpiSinAsesor = count(array_filter($rows, fn($r) => empty($r['asesor'])));

$page_title  = 'Interesados (clics)';
$active_menu = 'wa_campanas';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .in-wrap { max-width: 1100px; }
  .in-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
  .in-kpi { background:var(--bg-surface); border:1px solid var(--brd); border-radius:12px; padding:14px 16px; }
  .in-kpi .v { font-size:24px; font-weight:800; color:var(--tx); line-height:1.1; }
  .in-kpi .l { font-size:12px; color:var(--tx2); margin-top:3px; }
  .in-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin:16px 0; }
  .in-filtros label { font-size:11px; font-weight:700; color:var(--tx2); display:block; margin-bottom:3px; }
  .in-filtros input, .in-filtros select { padding:8px 10px; border:1.5px solid var(--brd); border-radius:9px; background:var(--bg-surface); color:var(--tx); font-size:13px; }
  .in-tbl { width:100%; border-collapse:collapse; font-size:13px; }
  .in-tbl th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--tx2); padding:8px 10px; border-bottom:1px solid var(--brd); white-space:nowrap; }
  .in-tbl td { padding:9px 10px; border-bottom:1px solid var(--brd); vertical-align:top; }
  .in-tbl tr:hover td { background:var(--bg-hover); }
  .in-pill { display:inline-block; font-size:11px; font-weight:700; padding:2px 9px; border-radius:20px; }
  .in-pill.promo { color:#15803d; background:#dcfce7; }
  .in-pill.cot { color:#8a5b00; background:#fff3d6; }
  .in-scroll { overflow-x:auto; }
  @media (max-width:720px){ .in-kpis{ grid-template-columns:repeat(2,1fr); } }
</style>

<div class="in-wrap">
  <div class="page-header" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div>
      <h1>Interesados (clics de botón)</h1>
      <p>Clientes que pulsaron "Quiero aprovechar" en una promoción (o "Quiero aprovecharlo" en el aviso de cotización). El ejecutivo a cargo ya recibió una notificación al clic.</p>
    </div>
    <a class="btn btn-outline btn-sm" href="?<?= sanitize(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">⬇ Exportar CSV</a>
  </div>

  <div class="in-kpis">
    <div class="in-kpi"><div class="v"><?= number_format($kpiTotal) ?></div><div class="l">Clics totales</div></div>
    <div class="in-kpi" style="border-color:var(--gr,#16a34a);"><div class="v" style="color:var(--gr,#16a34a);"><?= number_format($kpiPromo) ?></div><div class="l">De promoción</div></div>
    <div class="in-kpi"><div class="v"><?= number_format($kpiCot) ?></div><div class="l">De cotización por vencer</div></div>
    <div class="in-kpi"><div class="v"><?= number_format($kpiSinAsesor) ?></div><div class="l">Sin asesor asignado</div></div>
  </div>

  <form method="GET" class="in-filtros">
    <div><label>Desde</label><input type="date" name="desde" value="<?= sanitize($desde) ?>" onchange="this.form.submit()"></div>
    <div><label>Hasta</label><input type="date" name="hasta" value="<?= sanitize($hasta) ?>" onchange="this.form.submit()"></div>
    <div><label>Origen</label>
      <select name="fuente" onchange="this.form.submit()">
        <option value="">Todos</option>
        <option value="promo" <?= $fuente === 'promo' ? 'selected' : '' ?>>Promoción</option>
        <option value="cotizacion" <?= $fuente === 'cotizacion' ? 'selected' : '' ?>>Cotización por vencer</option>
      </select>
    </div>
    <div><button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
      <?php if ($desde || $hasta || $fuente): ?><a class="btn btn-outline btn-sm" href="?">Limpiar</a><?php endif; ?>
    </div>
  </form>

  <?php if (!$rows): ?>
    <div class="empty-state">Aún no hay clics registrados con estos filtros.</div>
  <?php else: ?>
  <div class="in-scroll">
    <table class="in-tbl">
      <thead><tr><th>Fecha del clic</th><th>Cliente</th><th>Teléfono</th><th>Origen</th><th>Asesor a cargo</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="white-space:nowrap;"><?= sanitize(date('d/m/Y H:i', strtotime($r['fecha']))) ?></td>
          <td>
            <?php if ($r['cliente_id']): ?><a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= (int)$r['cliente_id'] ?>"><?= sanitize($r['cliente']) ?></a>
            <?php else: ?><?= sanitize($r['cliente']) ?><?php endif; ?>
          </td>
          <td><a href="<?= APP_URL ?>/modules/inbox/index.php?tel=<?= urlencode($r['telefono']) ?>" title="Abrir chat">+<?= sanitize($r['telefono']) ?></a></td>
          <td><?php if ($r['fuente'] === 'promo'): ?><span class="in-pill promo">Promoción</span><?php else: ?><span class="in-pill cot">Cotización</span><?php endif; ?></td>
          <td><?= $r['asesor'] ? sanitize($r['asesor']) : '<span style="color:var(--s-warn,#b45309);">Sin asignar</span>' ?></td>
          <td><a class="btn btn-outline btn-sm" style="padding:3px 10px;font-size:11px;" href="<?= APP_URL ?>/modules/inbox/index.php?tel=<?= urlencode($r['telefono']) ?>">Abrir chat</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
