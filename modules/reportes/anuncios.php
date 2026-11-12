<?php
/**
 * modules/reportes/anuncios.php — Seguimiento de anuncios Click-to-WhatsApp (CTWA).
 *
 * Lista cada lead que llegó desde un anuncio de Meta (Facebook/Instagram) con los
 * datos del `referral` que Meta manda en el primer mensaje: fuente, ID de anuncio,
 * título, CTWA Click ID y enlace. La materia prima ya se guarda en
 * wa_mensajes.referral_json (la captura modules/whatsapp/webhook_meta.php).
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones', 'administracion_finanzas');

$pdo = Database::get();

// ── Filtros ─────────────────────────────────────────────────────────────────
$desde   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : '';
$hasta   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : '';
$anuncio = trim($_GET['anuncio'] ?? '');   // source_id
$q       = trim($_GET['q'] ?? '');

$where = ['m.referral_json IS NOT NULL'];
$args  = [];
if ($desde)   { $where[] = 'm.created_at >= ?'; $args[] = $desde . ' 00:00:00'; }
if ($hasta)   { $where[] = 'm.created_at <= ?'; $args[] = $hasta . ' 23:59:59'; }
if ($anuncio) { $where[] = "JSON_UNQUOTE(JSON_EXTRACT(m.referral_json,'$.source_id')) = ?"; $args[] = $anuncio; }
if ($q)       { $where[] = '(c.telefono LIKE ? OR cl.razon_social LIKE ? OR cl.contacto_nombre LIKE ? OR c.perfil_nombre LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; }
$sqlWhere = implode(' AND ', $where);

$sql = "SELECT m.id, m.created_at, m.referral_json, c.id AS conv_id, c.telefono, c.cliente_id,
               COALESCE(cl.razon_social, cl.contacto_nombre, c.perfil_nombre, CONCAT('+', c.telefono)) AS contacto
        FROM wa_mensajes m
        JOIN wa_conversaciones c ON c.id = m.conversacion_id
        LEFT JOIN clientes cl ON cl.id = c.cliente_id
        WHERE $sqlWhere
        ORDER BY m.created_at DESC";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Normalizar el referral de cada fila.
$data = [];
foreach ($rows as $r) {
    $ref = json_decode((string)$r['referral_json'], true) ?: [];
    $data[] = [
        'fecha'    => $r['created_at'],
        'telefono' => $r['telefono'],
        'conv_id'  => (int)$r['conv_id'],
        'cliente_id' => $r['cliente_id'] ? (int)$r['cliente_id'] : null,
        'contacto' => $r['contacto'],
        'fuente'   => $ref['source_type'] ?? '',
        'ad_id'    => $ref['source_id'] ?? '',
        'titulo'   => $ref['headline'] ?? '',
        'clid'     => $ref['ctwa_clid'] ?? '',
        'url'      => $ref['source_url'] ?? '',
        'body'     => $ref['body'] ?? '',
    ];
}

// ── ¿Qué clientes cerraron como propuesta ACEPTADA? (venta ganada desde el anuncio) ──
$cliIds = array_values(array_unique(array_filter(array_map(fn($d) => $d['cliente_id'], $data))));
$aceptadas = [];   // cliente_id => ['numero'=>.., 'monto'=>..]
if ($cliIds) {
    $in = implode(',', array_fill(0, count($cliIds), '?'));
    $qa = $pdo->prepare("SELECT cliente_id, numero, monto_total FROM cotizaciones
                         WHERE estado = 'aceptada' AND deleted_at IS NULL AND cliente_id IN ($in)
                         ORDER BY fecha_emision DESC, id DESC");
    $qa->execute($cliIds);
    foreach ($qa->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!isset($aceptadas[(int)$c['cliente_id']])) $aceptadas[(int)$c['cliente_id']] = ['numero' => $c['numero'], 'monto' => $c['monto_total']];
    }
}
foreach ($data as &$_d) {
    $_d['aceptada'] = $_d['cliente_id'] && isset($aceptadas[$_d['cliente_id']]);
    $_d['cot']      = $_d['aceptada'] ? $aceptadas[$_d['cliente_id']] : null;
}
unset($_d);
// Filtro "solo cerradas".
$soloCerradas = !empty($_GET['cerradas']);
if ($soloCerradas) $data = array_values(array_filter($data, fn($d) => $d['aceptada']));

// ── Exportar CSV (mismos campos que Kapso + resultado) ──────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anuncios_ctwa_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // BOM para Excel
    fputcsv($out, ['Fecha', 'Número de teléfono', 'Contacto', 'Fuente', 'ID de anuncio', 'Título del anuncio', 'CTWA Click ID', 'Enlace del anuncio', 'Resultado']);
    foreach ($data as $d) {
        $res = $d['aceptada'] ? ('Propuesta aceptada' . ($d['cot']['numero'] ? ' ' . $d['cot']['numero'] : '')) : '';
        fputcsv($out, [$d['fecha'], $d['telefono'], $d['contacto'], $d['fuente'], $d['ad_id'], $d['titulo'], $d['clid'], $d['url'], $res]);
    }
    fclose($out);
    exit;
}

// ── Resumen por anuncio + KPIs ──────────────────────────────────────────────
$porAnuncio = [];
$convSet = []; $cliSet = []; $ventaSet = [];
foreach ($data as $d) {
    $k = $d['ad_id'] !== '' ? $d['ad_id'] : ('h:' . $d['titulo']);
    if (!isset($porAnuncio[$k])) $porAnuncio[$k] = ['titulo' => $d['titulo'], 'ad_id' => $d['ad_id'], 'leads' => 0, 'convs' => [], 'clis' => [], 'ventas' => [], 'ultimo' => $d['fecha']];
    $porAnuncio[$k]['leads']++;
    $porAnuncio[$k]['convs'][$d['conv_id']] = 1;
    if ($d['cliente_id']) $porAnuncio[$k]['clis'][$d['cliente_id']] = 1;
    if ($d['aceptada']) { $porAnuncio[$k]['ventas'][$d['cliente_id']] = 1; $ventaSet[$d['cliente_id']] = 1; }
    if ($d['fecha'] > $porAnuncio[$k]['ultimo']) $porAnuncio[$k]['ultimo'] = $d['fecha'];
    $convSet[$d['conv_id']] = 1;
    if ($d['cliente_id']) $cliSet[$d['cliente_id']] = 1;
}
uasort($porAnuncio, fn($a, $b) => $b['leads'] <=> $a['leads']);
$kpiLeads    = count($data);
$kpiConvs    = count($convSet);
$kpiAnuncios = count($porAnuncio);
$kpiClientes = count($cliSet);
$kpiVentas   = count($ventaSet);

// Opciones del filtro por anuncio (todos los anuncios vistos).
$opcAnuncios = $pdo->query("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(referral_json,'$.source_id')) sid,
                                   JSON_UNQUOTE(JSON_EXTRACT(referral_json,'$.headline')) h
                            FROM wa_mensajes WHERE referral_json IS NOT NULL
                            HAVING sid IS NOT NULL ORDER BY h")->fetchAll(PDO::FETCH_ASSOC);

$page_title  = 'Anuncios (CTWA)';
$active_menu = 'anuncios_ctwa';
require_once __DIR__ . '/../../templates/layout.php';

function ct_badge_fuente(string $f): string {
    $map = ['ad' => ['Anuncio', '#1461b8', '#e7f3ff'], 'post' => ['Publicación', '#8a5b00', '#fff3d6']];
    [$lbl, $c, $bg] = $map[$f] ?? [ucfirst($f ?: '—'), 'var(--tx2)', 'var(--bg-surface2)'];
    return '<span class="ct-pill" style="color:' . $c . ';background:' . $bg . ';">' . sanitize($lbl) . '</span>';
}
?>
<style>
  .ct-wrap { max-width: 1180px; }
  .ct-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
  .ct-kpi { background:var(--bg-surface); border:1px solid var(--brd); border-radius:12px; padding:14px 16px; }
  .ct-kpi .v { font-size:24px; font-weight:800; color:var(--tx); line-height:1.1; }
  .ct-kpi .l { font-size:12px; color:var(--tx2); margin-top:3px; }
  .ct-card { background:var(--bg-surface); border:1px solid var(--brd); border-radius:12px; padding:16px 18px; margin-bottom:16px; }
  .ct-card h2 { font-size:14px; margin:0 0 12px; }
  .ct-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:16px; }
  .ct-filtros label { font-size:11px; font-weight:700; color:var(--tx2); display:block; margin-bottom:3px; }
  .ct-filtros input, .ct-filtros select { padding:8px 10px; border:1.5px solid var(--brd); border-radius:9px; background:var(--bg-surface); color:var(--tx); font-size:13px; }
  .ct-pill { display:inline-block; font-size:11px; font-weight:700; padding:2px 9px; border-radius:20px; }
  .ct-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
  .ct-tbl th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--tx2); padding:8px 10px; border-bottom:1px solid var(--brd); white-space:nowrap; }
  .ct-tbl td { padding:9px 10px; border-bottom:1px solid var(--brd); vertical-align:top; }
  .ct-tbl tr:hover td { background:var(--bg-hover); }
  .ct-mono { font-family:monospace; font-size:11.5px; color:var(--tx2); }
  .ct-clid { max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:inline-block; cursor:copy; }
  .ct-titulo { max-width:230px; }
  .ct-scroll { overflow-x:auto; }
  .ct-kpis-5 { grid-template-columns:repeat(5,1fr); }
  .ct-won { display:inline-block; font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; background:#dcfce7; color:#15803d; white-space:nowrap; }
  @media (max-width:720px){ .ct-kpis, .ct-kpis-5 { grid-template-columns:repeat(2,1fr); } }
</style>

<div class="ct-wrap">
  <div class="page-header" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div>
      <h1>Anuncios (CTWA)</h1>
      <p>Leads que llegaron por anuncios Click-to-WhatsApp de Meta (Facebook / Instagram).</p>
    </div>
    <a class="btn btn-outline btn-sm" href="?<?= sanitize(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">⬇ Exportar CSV</a>
  </div>

  <!-- KPIs -->
  <div class="ct-kpis ct-kpis-5">
    <div class="ct-kpi"><div class="v"><?= number_format($kpiLeads) ?></div><div class="l">Clics de anuncio (leads)</div></div>
    <div class="ct-kpi"><div class="v"><?= number_format($kpiConvs) ?></div><div class="l">Conversaciones distintas</div></div>
    <div class="ct-kpi"><div class="v"><?= number_format($kpiAnuncios) ?></div><div class="l">Anuncios distintos</div></div>
    <div class="ct-kpi"><div class="v"><?= number_format($kpiClientes) ?></div><div class="l">Vinculados a un cliente</div></div>
    <div class="ct-kpi" style="border-color:var(--gr,#16a34a);"><div class="v" style="color:var(--gr,#16a34a);"><?= number_format($kpiVentas) ?></div><div class="l">Cerradas (propuesta aceptada)</div></div>
  </div>

  <!-- Filtros -->
  <form method="GET" class="ct-filtros">
    <div><label>Desde</label><input type="date" name="desde" value="<?= sanitize($desde) ?>" onchange="this.form.submit()"></div>
    <div><label>Hasta</label><input type="date" name="hasta" value="<?= sanitize($hasta) ?>" onchange="this.form.submit()"></div>
    <div><label>Anuncio</label>
      <select name="anuncio" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach ($opcAnuncios as $a): ?>
          <option value="<?= sanitize($a['sid']) ?>" <?= $anuncio === $a['sid'] ? 'selected' : '' ?>><?= sanitize(($a['h'] ?: 'Sin título') . ' · ' . $a['sid']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Buscar</label><input type="text" name="q" value="<?= sanitize($q) ?>" placeholder="Teléfono o contacto"></div>
    <div><label>&nbsp;</label><label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;cursor:pointer;padding:8px 0;">
      <input type="checkbox" name="cerradas" value="1" <?= $soloCerradas ? 'checked' : '' ?> onchange="this.form.submit()"> Solo cerradas
    </label></div>
    <div><button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
      <?php if ($desde || $hasta || $anuncio || $q || $soloCerradas): ?><a class="btn btn-outline btn-sm" href="?">Limpiar</a><?php endif; ?>
    </div>
  </form>

  <!-- Resumen por anuncio -->
  <?php if ($porAnuncio): ?>
  <div class="ct-card">
    <h2>Por anuncio</h2>
    <div class="ct-scroll">
    <table class="ct-tbl">
      <thead><tr><th>Título del anuncio</th><th>ID de anuncio</th><th>Leads</th><th>Conversaciones</th><th>Clientes</th><th>Cerradas</th><th>Último lead</th></tr></thead>
      <tbody>
      <?php foreach ($porAnuncio as $a): $nv = count($a['ventas']); ?>
        <tr>
          <td class="ct-titulo"><b><?= sanitize($a['titulo'] ?: '—') ?></b></td>
          <td class="ct-mono"><?php if ($a['ad_id']): ?><a href="?anuncio=<?= urlencode($a['ad_id']) ?>"><?= sanitize($a['ad_id']) ?></a><?php else: ?>—<?php endif; ?></td>
          <td><b><?= number_format($a['leads']) ?></b></td>
          <td><?= number_format(count($a['convs'])) ?></td>
          <td><?= number_format(count($a['clis'])) ?></td>
          <td><?php if ($nv): ?><span class="ct-won"><?= number_format($nv) ?></span><?php else: ?><span style="color:var(--tx3,#94a3b8)">0</span><?php endif; ?></td>
          <td class="ct-mono"><?= sanitize(date('d/m/Y', strtotime($a['ultimo']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Detalle -->
  <div class="ct-card">
    <h2>Detalle de leads<?= $data ? ' (' . number_format(count($data)) . ')' : '' ?></h2>
    <?php if (!$data): ?>
      <div class="empty-state">Aún no hay leads de anuncios CTWA con estos filtros.</div>
    <?php else: ?>
    <div class="ct-scroll">
    <table class="ct-tbl">
      <thead>
        <tr>
          <th>Fecha</th><th>Número de teléfono</th><th>Contacto</th><th>Fuente</th>
          <th>ID de anuncio</th><th>Título del anuncio</th><th>CTWA Click ID</th><th>Enlace</th><th>Resultado</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($data as $d): ?>
        <tr>
          <td class="ct-mono"><?= sanitize(date('d/m/Y H:i', strtotime($d['fecha']))) ?></td>
          <td><a href="<?= APP_URL ?>/modules/inbox/index.php?tel=<?= urlencode($d['telefono']) ?>" title="Abrir chat">+<?= sanitize($d['telefono']) ?></a></td>
          <td>
            <?php if ($d['cliente_id']): ?><a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $d['cliente_id'] ?>"><?= sanitize($d['contacto']) ?></a>
            <?php else: ?><?= sanitize($d['contacto']) ?><?php endif; ?>
          </td>
          <td><?= ct_badge_fuente($d['fuente']) ?></td>
          <td class="ct-mono"><?php if ($d['ad_id']): ?><a href="?anuncio=<?= urlencode($d['ad_id']) ?>"><?= sanitize($d['ad_id']) ?></a><?php else: ?>—<?php endif; ?></td>
          <td class="ct-titulo" title="<?= sanitize($d['body']) ?>"><?= sanitize($d['titulo'] ?: '—') ?></td>
          <td><span class="ct-mono ct-clid" title="Clic para copiar: <?= sanitize($d['clid']) ?>" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= sanitize($d['clid']) ?>')"><?= sanitize($d['clid'] ?: '—') ?></span></td>
          <td><?php if ($d['url']): ?><a href="<?= sanitize($d['url']) ?>" target="_blank" rel="noopener">ver ↗</a><?php else: ?>—<?php endif; ?></td>
          <td><?php if ($d['aceptada']): ?><span class="ct-won" title="Propuesta <?= sanitize($d['cot']['numero'] ?? '') ?>">✅ Aceptada</span><?php else: ?><span style="color:var(--tx3,#94a3b8)">—</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
