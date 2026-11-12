<?php
/**
 * modules/reportes/gasto_anuncios.php — Gasto en anuncios de Meta por mes.
 *
 * Lee el gasto de la cuenta publicitaria (act_XXXX) vía Marketing API con el
 * token de solo-lectura (ads_read). Muestra el total del año, el gasto por mes
 * (gráfico + tabla) y el desglose por campaña del año seleccionado.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/MetaAdsService.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones', 'administracion_finanzas');

$ads = new MetaAdsService();

$anioActual = (int)date('Y');
$anio = (int)($_GET['anio'] ?? $anioActual);
if ($anio < 2018 || $anio > $anioActual) $anio = $anioActual;

$cuenta   = $ads->configurado() ? $ads->cuenta() : null;
$moneda   = $cuenta['currency'] ?? 'PEN';
$simbolo  = ['PEN' => 'S/', 'USD' => '$', 'EUR' => '€'][$moneda] ?? ($moneda . ' ');

$mensual  = $ads->configurado() ? $ads->gastoMensual($anio) : array_fill(1, 12, 0.0);
$totalAnio = array_sum($mensual);
$mesesConGasto = count(array_filter($mensual, fn($v) => $v > 0));
$promedioMes   = $mesesConGasto ? $totalAnio / $mesesConGasto : 0;
$maxMes    = max($mensual ?: [0]);
$mesActualGasto = $anio === $anioActual ? ($mensual[(int)date('n')] ?? 0) : 0;

// Desglose por campaña del año seleccionado.
$since = sprintf('%04d-01-01', $anio);
$until = $anio === $anioActual ? date('Y-m-d') : sprintf('%04d-12-31', $anio);
$campanias = $ads->configurado() ? $ads->gastoPorCampania($since, $until) : [];

// ── Rendimiento por anuncio: gasto (Meta) vs resultados (CTWA) ────────────────
//  Cruza el gasto por anuncio (Meta, key ad_id) con los leads/ventas del CTWA
//  (referral source_id). Costo por lead, costo por venta e ingreso/ROAS.
$pdo     = Database::get();
$adSpend = $ads->configurado() ? $ads->gastoPorAnuncio($since, $until) : [];

$ctwa = []; // sid => ['titulo','leads','clientes'=>[],'ventas'=>[],'ingreso'=>0]
$lst = $pdo->prepare(
    "SELECT JSON_UNQUOTE(JSON_EXTRACT(m.referral_json,'$.source_id')) sid,
            JSON_UNQUOTE(JSON_EXTRACT(m.referral_json,'$.headline'))  h,
            c.cliente_id
       FROM wa_mensajes m JOIN wa_conversaciones c ON c.id = m.conversacion_id
      WHERE m.referral_json IS NOT NULL AND m.created_at BETWEEN ? AND ?
     HAVING sid IS NOT NULL"
);
$lst->execute([$since . ' 00:00:00', $until . ' 23:59:59']);
$leadRows = $lst->fetchAll(PDO::FETCH_ASSOC);

// Venta ganada por cliente = propuesta aceptada (monto). Una vez por cliente.
$cliIds = array_values(array_unique(array_filter(array_map(fn($r) => $r['cliente_id'], $leadRows))));
$ventaCli = []; // cliente_id => monto_total
if ($cliIds) {
    $in = implode(',', array_fill(0, count($cliIds), '?'));
    $qa = $pdo->prepare("SELECT cliente_id, monto_total FROM cotizaciones
                          WHERE estado='aceptada' AND deleted_at IS NULL AND cliente_id IN ($in)
                          ORDER BY fecha_emision DESC, id DESC");
    $qa->execute($cliIds);
    foreach ($qa->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($ventaCli[(int)$r['cliente_id']])) $ventaCli[(int)$r['cliente_id']] = (float)$r['monto_total'];
    }
}
foreach ($leadRows as $r) {
    $sid = (string)$r['sid'];
    if (!isset($ctwa[$sid])) $ctwa[$sid] = ['titulo' => $r['h'] ?: '', 'leads' => 0, 'clientes' => [], 'ventas' => [], 'ingreso' => 0.0];
    $ctwa[$sid]['leads']++;
    $cid = $r['cliente_id'] ? (int)$r['cliente_id'] : 0;
    if ($cid) {
        $ctwa[$sid]['clientes'][$cid] = 1;
        if (isset($ventaCli[$cid]) && !isset($ctwa[$sid]['ventas'][$cid])) {
            $ctwa[$sid]['ventas'][$cid] = 1;
            $ctwa[$sid]['ingreso'] += $ventaCli[$cid];
        }
    }
}

// Unir por ad_id (∪ de los que tienen gasto y los que tienen leads).
$rend = [];
foreach (array_unique(array_merge(array_keys($adSpend), array_keys($ctwa))) as $id) {
    $sp = $adSpend[$id] ?? null;
    $ct = $ctwa[$id]   ?? null;
    $spend   = $sp['spend'] ?? 0.0;
    $leads   = $ct['leads'] ?? 0;
    $ventas  = $ct ? count($ct['ventas']) : 0;
    $ingreso = $ct['ingreso'] ?? 0.0;
    $rend[] = [
        'ad_id'    => $id,
        'nombre'   => $sp['ad_name'] ?? ($ct['titulo'] ?? ''),
        'spend'    => $spend,
        'leads'    => $leads,
        'clientes' => $ct ? count($ct['clientes']) : 0,
        'ventas'   => $ventas,
        'ingreso'  => $ingreso,
        'cpl'      => $leads  > 0 ? $spend / $leads  : null,
        'cpa'      => $ventas > 0 ? $spend / $ventas : null,
        'roas'     => $spend  > 0 ? $ingreso / $spend : null,
    ];
}
usort($rend, fn($a, $b) => $b['spend'] <=> $a['spend']);
// Desde cuándo hay captura de leads CTWA (antes de esto el gasto no tiene leads).
$ctwaDesde = $pdo->query("SELECT MIN(created_at) FROM wa_mensajes WHERE referral_json IS NOT NULL")->fetchColumn();
$rTot = ['spend'=>0,'leads'=>0,'ventas'=>0,'ingreso'=>0];
foreach ($rend as $r) { $rTot['spend']+=$r['spend']; $rTot['leads']+=$r['leads']; $rTot['ventas']+=$r['ventas']; $rTot['ingreso']+=$r['ingreso']; }

$MESES = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Oct',11=>'Nov',12=>'Dic'];
$fmt = fn($n) => $simbolo . number_format((float)$n, 2);

// Exportar CSV.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gasto_anuncios_' . $anio . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Mes', 'Gasto (' . $moneda . ')']);
    foreach ($MESES as $i => $lbl) fputcsv($out, [$lbl . ' ' . $anio, number_format($mensual[$i], 2, '.', '')]);
    fputcsv($out, ['TOTAL ' . $anio, number_format($totalAnio, 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, ['Campaña', 'Gasto (' . $moneda . ')', 'Impresiones', 'Clics']);
    foreach ($campanias as $c) fputcsv($out, [$c['campaign'], number_format($c['spend'], 2, '.', ''), $c['impressions'], $c['clicks']]);
    fputcsv($out, []);
    fputcsv($out, ['Anuncio', 'Gasto', 'Leads', 'Costo/lead', 'Ventas', 'Costo/venta', 'Ingreso', 'ROAS']);
    foreach ($rend as $r) {
        if ($r['spend'] <= 0 && $r['leads'] <= 0) continue;
        fputcsv($out, [
            $r['nombre'], number_format($r['spend'], 2, '.', ''), $r['leads'],
            $r['cpl'] !== null ? number_format($r['cpl'], 2, '.', '') : '',
            $r['ventas'], $r['cpa'] !== null ? number_format($r['cpa'], 2, '.', '') : '',
            number_format($r['ingreso'], 2, '.', ''), $r['roas'] !== null && $r['ingreso'] > 0 ? number_format($r['roas'], 2, '.', '') : '',
        ]);
    }
    fclose($out);
    exit;
}

$page_title  = 'Gasto en anuncios';
$active_menu = 'gasto_anuncios';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .ga-wrap { max-width: 1100px; }
  .ga-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
  .ga-kpi { background:var(--bg-surface); border:1px solid var(--brd); border-radius:12px; padding:14px 16px; }
  .ga-kpi .v { font-size:23px; font-weight:800; color:var(--tx); line-height:1.15; }
  .ga-kpi .l { font-size:12px; color:var(--tx2); margin-top:3px; }
  .ga-card { background:var(--bg-surface); border:1px solid var(--brd); border-radius:12px; padding:16px 18px; margin-bottom:16px; }
  .ga-card h2 { font-size:14px; margin:0 0 14px; }
  .ga-bars { display:flex; align-items:flex-end; gap:10px; height:220px; padding-top:8px; }
  .ga-bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; height:100%; justify-content:flex-end; }
  .ga-bar { width:100%; max-width:46px; background:linear-gradient(180deg,#1877f2,#0a5fd0); border-radius:6px 6px 0 0; min-height:2px; transition:opacity .15s; position:relative; }
  .ga-bar-col:hover .ga-bar { opacity:.82; }
  .ga-bar.cur { background:linear-gradient(180deg,#16a34a,#0f7a37); }
  .ga-bar-val { font-size:10.5px; font-weight:700; color:var(--tx2); white-space:nowrap; }
  .ga-bar-lbl { font-size:11px; color:var(--tx2); font-weight:600; }
  .ga-tbl { width:100%; border-collapse:collapse; font-size:13px; }
  .ga-tbl th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--tx2); padding:8px 10px; border-bottom:1px solid var(--brd); }
  .ga-tbl td { padding:9px 10px; border-bottom:1px solid var(--brd); }
  .ga-tbl td.num, .ga-tbl th.num { text-align:right; font-variant-numeric:tabular-nums; }
  .ga-tbl tr:hover td { background:var(--bg-hover); }
  .ga-tbl tfoot td { font-weight:800; border-top:2px solid var(--brd); border-bottom:none; }
  .ga-scroll { overflow-x:auto; }
  .ga-note { font-size:12px; color:var(--tx2); margin-top:4px; }
  .ga-alert { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; }
  :root[data-tema="oscuro"] .ga-alert { background:#3a2413; border-color:#7c3d12; color:#fdba74; }
  @media (max-width:760px){ .ga-kpis { grid-template-columns:repeat(2,1fr); } .ga-bars { height:170px; } .ga-bar-val { display:none; } }
</style>

<div class="ga-wrap">
  <div class="page-header" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div>
      <h1>Gasto en anuncios</h1>
      <p>Inversión publicitaria en Meta (Facebook / Instagram)<?= $cuenta ? ' · cuenta <b>' . sanitize($cuenta['name']) . '</b> · ' . sanitize($moneda) : '' ?>.</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <select name="anio" onchange="this.form.submit()" style="padding:8px 10px;border:1.5px solid var(--brd);border-radius:9px;background:var(--bg-surface);color:var(--tx);font-size:13px;">
          <?php for ($y = $anioActual; $y >= 2020; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $anio ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>
      <?php if ($ads->configurado() && $totalAnio > 0): ?>
      <a class="btn btn-outline btn-sm" href="?anio=<?= $anio ?>&export=csv">⬇ CSV</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$ads->configurado()): ?>
    <div class="ga-alert">La Marketing API aún no está configurada (falta el token o el ID de cuenta en <code>.env</code>).</div>
  <?php elseif (!$cuenta && $ads->lastError): ?>
    <div class="ga-alert">No se pudo leer la cuenta publicitaria: <?= sanitize($ads->lastError) ?></div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="ga-kpis">
    <div class="ga-kpi"><div class="v"><?= $fmt($totalAnio) ?></div><div class="l">Total gastado <?= $anio ?></div></div>
    <div class="ga-kpi"><div class="v"><?= $fmt($promedioMes) ?></div><div class="l">Promedio por mes activo</div></div>
    <div class="ga-kpi"><div class="v"><?= $fmt($maxMes) ?></div><div class="l">Mes de mayor gasto</div></div>
    <div class="ga-kpi" style="<?= $anio === $anioActual ? 'border-color:var(--gr,#16a34a);' : '' ?>">
      <div class="v" style="<?= $anio === $anioActual ? 'color:var(--gr,#16a34a);' : '' ?>"><?= $anio === $anioActual ? $fmt($mesActualGasto) : '—' ?></div>
      <div class="l"><?= $anio === $anioActual ? 'Este mes (' . $MESES[(int)date('n')] . ')' : 'Año cerrado' ?></div>
    </div>
  </div>

  <!-- Gráfico por mes -->
  <div class="ga-card">
    <h2>Gasto por mes · <?= $anio ?></h2>
    <?php if ($totalAnio <= 0): ?>
      <div class="empty-state">No hay gasto registrado en <?= $anio ?>.</div>
    <?php else: ?>
    <div class="ga-bars">
      <?php foreach ($MESES as $i => $lbl): $h = $maxMes > 0 ? max(2, round($mensual[$i] / $maxMes * 100)) : 2; $cur = ($anio === $anioActual && $i === (int)date('n')); ?>
        <div class="ga-bar-col" title="<?= $lbl . ' ' . $anio . ': ' . $fmt($mensual[$i]) ?>">
          <div class="ga-bar-val"><?= $mensual[$i] > 0 ? $simbolo . number_format($mensual[$i], 0) : '' ?></div>
          <div class="ga-bar <?= $cur ? 'cur' : '' ?>" style="height:<?= $h ?>%"></div>
          <div class="ga-bar-lbl"><?= $lbl ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($anio === $anioActual): ?><div class="ga-note">El mes en curso (verde) va parcial, hasta hoy.</div><?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Tabla por mes -->
  <?php if ($totalAnio > 0): ?>
  <div class="ga-card">
    <h2>Detalle mensual</h2>
    <div class="ga-scroll">
    <table class="ga-tbl">
      <thead><tr><th>Mes</th><th class="num">Gasto</th><th class="num">% del año</th></tr></thead>
      <tbody>
        <?php foreach ($MESES as $i => $lbl): if ($mensual[$i] <= 0 && !($anio === $anioActual && $i <= (int)date('n'))) continue; ?>
        <tr>
          <td><?= $lbl . ' ' . $anio ?><?= ($anio === $anioActual && $i === (int)date('n')) ? ' <span style="color:var(--gr,#16a34a);font-size:11px;font-weight:700;">· en curso</span>' : '' ?></td>
          <td class="num"><?= $fmt($mensual[$i]) ?></td>
          <td class="num"><?= $totalAnio > 0 ? number_format($mensual[$i] / $totalAnio * 100, 1) . '%' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>TOTAL <?= $anio ?></td><td class="num"><?= $fmt($totalAnio) ?></td><td class="num">100%</td></tr></tfoot>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Rendimiento por anuncio: gasto vs resultados -->
  <?php if ($rend): ?>
  <div class="ga-card">
    <h2>Rendimiento por anuncio · <?= $anio ?> <span style="font-weight:400;color:var(--tx2);font-size:12px;">(gasto de Meta cruzado con leads y ventas del CTWA)</span></h2>
    <?php if ($ctwaDesde): ?>
      <div class="ga-alert" style="margin-bottom:14px;">El seguimiento de leads por anuncio empezó el <b><?= sanitize(date('d/m/Y', strtotime($ctwaDesde))) ?></b>. El gasto anterior a esa fecha aparece <b>sin leads/ventas</b> (aún no los registrábamos), así que su ROAS no es comparable.</div>
    <?php endif; ?>
    <div class="ga-scroll">
    <table class="ga-tbl">
      <thead><tr>
        <th>Anuncio</th><th class="num">Gasto</th><th class="num">Leads</th>
        <th class="num">Costo / lead</th><th class="num">Ventas</th><th class="num">Costo / venta</th>
        <th class="num">Ingreso</th><th class="num">ROAS</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rend as $r): if ($r['spend'] <= 0 && $r['leads'] <= 0) continue; ?>
        <tr>
          <td style="max-width:260px;"><?= sanitize($r['nombre'] ?: '—') ?><?php if (!$r['spend']): ?> <span style="font-size:10.5px;color:var(--tx3,#94a3b8);">(sin gasto en rango)</span><?php endif; ?></td>
          <td class="num"><?= $r['spend'] > 0 ? $fmt($r['spend']) : '—' ?></td>
          <td class="num"><?= number_format($r['leads']) ?></td>
          <td class="num"><?= $r['cpl'] !== null ? $fmt($r['cpl']) : '—' ?></td>
          <td class="num"><?php if ($r['ventas']): ?><b style="color:var(--gr,#16a34a);"><?= number_format($r['ventas']) ?></b><?php else: ?><span style="color:var(--tx3,#94a3b8);">0</span><?php endif; ?></td>
          <td class="num"><?= $r['cpa'] !== null ? $fmt($r['cpa']) : '—' ?></td>
          <td class="num"><?= $r['ingreso'] > 0 ? $fmt($r['ingreso']) : '—' ?></td>
          <td class="num"><?php if ($r['roas'] !== null && $r['ingreso'] > 0): ?><span style="font-weight:700;color:<?= $r['roas'] >= 1 ? 'var(--gr,#16a34a)' : '#dc2626' ?>;"><?= number_format($r['roas'], 2) ?>×</span><?php else: ?>—<?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td>TOTAL</td>
        <td class="num"><?= $fmt($rTot['spend']) ?></td>
        <td class="num"><?= number_format($rTot['leads']) ?></td>
        <td class="num"><?= $rTot['leads'] > 0 ? $fmt($rTot['spend'] / $rTot['leads']) : '—' ?></td>
        <td class="num"><?= number_format($rTot['ventas']) ?></td>
        <td class="num"><?= $rTot['ventas'] > 0 ? $fmt($rTot['spend'] / $rTot['ventas']) : '—' ?></td>
        <td class="num"><?= $fmt($rTot['ingreso']) ?></td>
        <td class="num"><?= $rTot['spend'] > 0 ? number_format($rTot['ingreso'] / $rTot['spend'], 2) . '×' : '—' ?></td>
      </tr></tfoot>
    </table>
    </div>
    <div class="ga-note">
      <b>Leads / Ventas</b> vienen del Click-to-WhatsApp (por <code>ad_id</code>). <b>Ingreso</b> = suma de propuestas aceptadas de esos clientes (una vez por cliente). <b>ROAS</b> = ingreso ÷ gasto. Un cliente que llegó por varios anuncios cuenta en cada uno; anuncios sin gasto en el rango pueden ser publicaciones promocionadas.
    </div>
  </div>
  <?php endif; ?>

  <!-- Por campaña -->
  <?php if ($campanias): ?>
  <div class="ga-card">
    <h2>Por campaña · <?= $anio ?></h2>
    <div class="ga-scroll">
    <table class="ga-tbl">
      <thead><tr><th>Campaña</th><th class="num">Gasto</th><th class="num">Impresiones</th><th class="num">Clics</th><th class="num">CPC</th></tr></thead>
      <tbody>
        <?php foreach ($campanias as $c): $cpc = $c['clicks'] > 0 ? $c['spend'] / $c['clicks'] : 0; ?>
        <tr>
          <td><?= sanitize($c['campaign']) ?></td>
          <td class="num"><?= $fmt($c['spend']) ?></td>
          <td class="num"><?= number_format($c['impressions']) ?></td>
          <td class="num"><?= number_format($c['clicks']) ?></td>
          <td class="num"><?= $c['clicks'] > 0 ? $fmt($cpc) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="ga-note">CPC = costo por clic. Datos directos de Meta Ads.</div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
