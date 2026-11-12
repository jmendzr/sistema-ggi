<?php
/**
 * modules/facturas/reporte.php — Reporte de facturación.
 *
 * Facturado del mes, IGV, detracciones, por ejecutivo, facturado por mes del año
 * y cotizaciones pendientes de facturar. Requiere el permiso 'facturas_emitir'.
 *
 * Nota: los montos provienen de las facturas del sistema (Alegra tienen base/IGV/
 * total/detracción; las cargadas a mano históricas pueden no tener monto → cuentan
 * por número pero no suman importe).
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
if (!can('facturas_emitir')) { http_response_code(403); require __DIR__ . '/../../templates/403.php'; exit; }

$pdo = Database::get();

// ── Periodo ──
$hoy  = new DateTime('now');
$anio = (int)($_GET['anio'] ?? $hoy->format('Y'));
$mes  = (int)($_GET['mes']  ?? $hoy->format('n'));
if ($mes < 1 || $mes > 12) $mes = (int)$hoy->format('n');
$aniosDisp = $pdo->query("SELECT DISTINCT YEAR(created_at) y FROM facturas_cliente ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$aniosDisp) $aniosDisp = [(int)$hoy->format('Y')];
if (!in_array($anio, array_map('intval', $aniosDisp), true)) $anio = (int)$aniosDisp[0];

$MESES = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

// Filtro común: facturas realmente emitidas (Alegra no error/borrador, o manuales).
$WHERE_EMITIDA = "(f.origen='manual' OR (f.origen='alegra' AND f.estado_timbrado NOT IN ('error','draft')))";

// ── Facturas del mes (una sola query → KPIs + por ejecutivo + detalle) ──
$q = $pdo->prepare("
    SELECT f.id, f.numero_factura, f.origen, f.tipo_factura, f.estado_timbrado,
           f.monto_base, f.monto_igv, f.monto_total, f.detraccion_monto, f.created_at,
           cl.razon_social,
           cot.numero AS cot_numero,
           TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS ejecutivo
    FROM facturas_cliente f
    JOIN clientes cl ON cl.id = f.cliente_id
    LEFT JOIN cotizaciones cot ON cot.id = f.cotizacion_id
    LEFT JOIN usuarios u ON u.id = COALESCE(cot.ejecutivo_id, cl.ejecutivo_id)
    WHERE $WHERE_EMITIDA AND YEAR(f.created_at)=? AND MONTH(f.created_at)=?
    ORDER BY f.created_at DESC, f.id DESC
");
$q->execute([$anio, $mes]);
$facturas = $q->fetchAll(PDO::FETCH_ASSOC);

$tot_facturado = 0.0; $tot_igv = 0.0; $tot_base = 0.0; $tot_detra = 0.0;
$porEjec = [];
foreach ($facturas as $f) {
    $tot_facturado += (float)$f['monto_total'];
    $tot_igv       += (float)$f['monto_igv'];
    $tot_base      += (float)$f['monto_base'];
    $tot_detra     += (float)$f['detraccion_monto'];
    $e = $f['ejecutivo'] !== '' ? $f['ejecutivo'] : '— Sin ejecutivo —';
    if (!isset($porEjec[$e])) $porEjec[$e] = ['n'=>0,'total'=>0.0,'detra'=>0.0];
    $porEjec[$e]['n']++;
    $porEjec[$e]['total'] += (float)$f['monto_total'];
    $porEjec[$e]['detra'] += (float)$f['detraccion_monto'];
}
uasort($porEjec, fn($a,$b) => $b['total'] <=> $a['total']); // por facturado desc

// ── Facturado por mes del año (mini-gráfico) ──
$pm = $pdo->prepare("
    SELECT MONTH(f.created_at) m, COALESCE(SUM(f.monto_total),0) total, COUNT(*) n
    FROM facturas_cliente f
    WHERE $WHERE_EMITIDA AND YEAR(f.created_at)=?
    GROUP BY MONTH(f.created_at)
");
$pm->execute([$anio]);
$porMes = array_fill(1, 12, ['total'=>0.0,'n'=>0]);
$tot_anio = 0.0;
foreach ($pm->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $porMes[(int)$r['m']] = ['total'=>(float)$r['total'],'n'=>(int)$r['n']];
    $tot_anio += (float)$r['total'];
}
$maxMes = max(array_map(fn($x) => $x['total'], $porMes)) ?: 1;

// ── Pendiente de facturar: cotizaciones aceptadas, con IGV, PAGADAS por completo,
//    sin factura de cierre (ni 'total'/'saldo' Alegra ni manual). ──
$pend = $pdo->query("
    SELECT c.id, c.numero, c.monto_total, c.fecha_aceptacion,
           cl.razon_social,
           TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS ejecutivo
    FROM cotizaciones c
    JOIN clientes cl ON cl.id = c.cliente_id
    LEFT JOIN usuarios u ON u.id = COALESCE(c.ejecutivo_id, cl.ejecutivo_id)
    WHERE c.deleted_at IS NULL AND c.estado='aceptada' AND c.incluye_igv=1
      AND UPPER(COALESCE(c.moneda,'PEN'))='PEN'
      AND (SELECT COUNT(*) FROM pagos_cliente p WHERE p.cotizacion_id=c.id AND p.deleted_at IS NULL) > 0
      AND (SELECT COUNT(*) FROM pagos_cliente p WHERE p.cotizacion_id=c.id AND p.deleted_at IS NULL AND p.estado<>'validado') = 0
      AND NOT EXISTS (
          SELECT 1 FROM facturas_cliente f
          WHERE f.cotizacion_id=c.id
            AND ( (f.origen='alegra' AND f.tipo_factura IN ('total','saldo') AND f.estado_timbrado<>'error')
                  OR f.origen='manual' )
      )
    ORDER BY c.fecha_aceptacion ASC
")->fetchAll(PDO::FETCH_ASSOC);
$pend_monto = array_sum(array_map(fn($p) => (float)$p['monto_total'], $pend));

function _s($v){ return 'S/ ' . number_format((float)$v, 2, '.', ','); }
function _estadoSunat($e){
    $e = strtoupper((string)$e);
    if ($e === 'STAMPED_AND_ACCEPTED') return ['Aceptada','ok'];
    if (strpos($e,'REJECTED') !== false) return ['Rechazada','err'];
    if ($e === '' || $e === 'DRAFT')     return ['—','muted'];
    return ['En SUNAT','warn'];
}

// ── Recuperación por aviso de "cotización por vencer" (todo el histórico) ──
//    Avisadas = recibieron el WhatsApp; recuperadas = luego se aceptaron.
$rec = $pdo->query("
    SELECT COUNT(*) AS avisadas,
           COALESCE(SUM(estado='aceptada'),0) AS recuperadas,
           COALESCE(SUM(CASE WHEN estado='aceptada' THEN monto_total ELSE 0 END),0) AS monto_rec
    FROM cotizaciones
    WHERE deleted_at IS NULL
      AND (aviso_vence_3d_at IS NOT NULL OR aviso_vence_1d_at IS NOT NULL)
")->fetch(PDO::FETCH_ASSOC);
$rec_avisadas   = (int)($rec['avisadas'] ?? 0);
$rec_recuperadas= (int)($rec['recuperadas'] ?? 0);
$rec_monto      = (float)($rec['monto_rec'] ?? 0);
$rec_tasa       = $rec_avisadas > 0 ? round($rec_recuperadas / $rec_avisadas * 100) : 0;

$page_title  = 'Reporte de facturación';
$active_menu = 'reportes';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .fr-wrap { max-width: 1150px; }
  .fr-head { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:20px; }
  .fr-title { font-size:22px; font-weight:700; color:var(--gris-dark); display:flex; align-items:center; gap:10px; }
  .fr-filtros { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .fr-filtros select { padding:8px 30px 8px 11px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px;
    font-family:inherit; background:var(--bg-surface); color:var(--gris-dark);
    appearance:none; -webkit-appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none' stroke='%23667085' stroke-width='2' viewBox='0 0 24 24'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 10px center; cursor:pointer; }
  .fr-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:22px; }
  .fr-kpi { background:var(--gris-card); border:1px solid var(--gris-borde); border-radius:12px; padding:16px 18px; }
  .fr-kpi-val { font-size:24px; font-weight:700; color:var(--gris-dark); line-height:1.1; }
  .fr-kpi-lbl { font-size:12px; color:var(--gris-texto); margin-top:5px; font-weight:500; }
  .fr-kpi-sub { font-size:11px; margin-top:3px; font-weight:600; color:var(--ac); }
  .fr-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px; }
  .fr-card { background:var(--gris-card); border:1px solid var(--gris-borde); border-radius:12px; overflow:hidden; }
  .fr-card-head { padding:13px 18px; border-bottom:1px solid var(--gris-borde); background:var(--gris-bg); font-size:13px; font-weight:700; color:var(--gris-dark); }
  .fr-card-body { padding:6px 0; }
  .fr-table { width:100%; border-collapse:collapse; font-size:12.5px; }
  .fr-table th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--gris-texto); font-weight:700; padding:9px 14px; border-bottom:1px solid var(--gris-borde); }
  .fr-table td { padding:9px 14px; border-bottom:1px solid var(--gris-borde); color:var(--gris-dark); }
  .fr-table tr:last-child td { border-bottom:none; }
  .fr-table .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .fr-bar-row { display:flex; align-items:center; gap:10px; padding:5px 14px; }
  .fr-bar-lbl { width:78px; font-size:12px; color:var(--gris-texto); flex-shrink:0; }
  .fr-bar-track { flex:1; height:16px; background:var(--gris-bg); border-radius:6px; overflow:hidden; }
  .fr-bar-fill { height:100%; background:var(--ac); border-radius:6px; }
  .fr-bar-val { width:110px; text-align:right; font-size:12px; font-weight:600; color:var(--gris-dark); flex-shrink:0; font-variant-numeric:tabular-nums; }
  .fr-pill { display:inline-block; font-size:10px; font-weight:700; border-radius:20px; padding:2px 8px; }
  .fr-pill.ok   { background:var(--s-ok-bg); color:var(--s-ok-tx); }
  .fr-pill.warn { background:var(--s-warn-bg); color:var(--s-warn-tx); }
  .fr-pill.err  { background:var(--s-err-bg); color:var(--s-err-tx); }
  .fr-pill.muted{ background:var(--s-info-bg); color:var(--gris-texto); }
  .fr-empty { text-align:center; padding:26px 14px; color:var(--gris-texto); font-size:13px; }
  .fr-scroll { overflow-x:auto; }
  @media (max-width:820px){ .fr-grid-2 { grid-template-columns:1fr; } .fr-kpis { grid-template-columns:1fr 1fr; } }
</style>

<div class="fr-wrap">
  <div class="fr-head">
    <div>
      <a href="<?= APP_URL ?>/modules/reportes/index.php" class="btn-back" style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:var(--gris-texto);text-decoration:none;margin-bottom:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Volver a Reportes
      </a>
      <div class="fr-title">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="var(--ac)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
        Reporte de facturación
      </div>
    </div>
    <form class="fr-filtros" method="get" action="<?= APP_URL ?>/modules/facturas/reporte.php">
      <select name="mes" onchange="this.form.submit()">
        <?php foreach ($MESES as $mn => $ml): ?>
        <option value="<?= $mn ?>" <?= $mn === $mes ? 'selected' : '' ?>><?= $ml ?></option>
        <?php endforeach; ?>
      </select>
      <select name="anio" onchange="this.form.submit()">
        <?php foreach ($aniosDisp as $ay): ?>
        <option value="<?= (int)$ay ?>" <?= (int)$ay === $anio ? 'selected' : '' ?>><?= (int)$ay ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- KPIs del mes -->
  <div class="fr-kpis">
    <div class="fr-kpi">
      <div class="fr-kpi-val"><?= _s($tot_facturado) ?></div>
      <div class="fr-kpi-lbl">Facturado — <?= $MESES[$mes] ?> <?= $anio ?></div>
      <div class="fr-kpi-sub"><?= count($facturas) ?> factura<?= count($facturas) === 1 ? '' : 's' ?></div>
    </div>
    <div class="fr-kpi">
      <div class="fr-kpi-val"><?= _s($tot_base) ?></div>
      <div class="fr-kpi-lbl">Base gravada</div>
      <div class="fr-kpi-sub">IGV <?= _s($tot_igv) ?></div>
    </div>
    <div class="fr-kpi">
      <div class="fr-kpi-val"><?= _s($tot_detra) ?></div>
      <div class="fr-kpi-lbl">Detracciones del mes</div>
      <div class="fr-kpi-sub">12% (operaciones &gt; S/700)</div>
    </div>
    <div class="fr-kpi">
      <div class="fr-kpi-val" style="color:var(--s-warn-tx);"><?= _s($pend_monto) ?></div>
      <div class="fr-kpi-lbl">Pendiente de facturar</div>
      <div class="fr-kpi-sub" style="color:var(--s-warn-tx);"><?= count($pend) ?> cotización<?= count($pend) === 1 ? '' : 'es' ?> pagada<?= count($pend) === 1 ? '' : 's' ?></div>
    </div>
    <div class="fr-kpi" title="Cotizaciones que recibieron el aviso de WhatsApp 'por vencer' y luego se aceptaron (histórico).">
      <div class="fr-kpi-val" style="color:var(--verde,#16a34a);"><?= $rec_recuperadas ?><span style="font-size:15px;color:var(--gris-texto);font-weight:600;"> / <?= $rec_avisadas ?></span></div>
      <div class="fr-kpi-lbl">Recuperadas por aviso de vencer</div>
      <div class="fr-kpi-sub" style="color:var(--verde,#16a34a);"><?= $rec_tasa ?>% cierre<?= $rec_monto > 0 ? ' · ' . _s($rec_monto) : '' ?></div>
    </div>
  </div>

  <div class="fr-grid-2">
    <!-- Facturado por mes -->
    <div class="fr-card">
      <div class="fr-card-head">Facturado por mes — <?= $anio ?> · total <?= _s($tot_anio) ?></div>
      <div class="fr-card-body" style="padding:10px 0;">
        <?php foreach ($MESES as $mn => $ml): $row = $porMes[$mn]; $pct = $maxMes > 0 ? round($row['total'] / $maxMes * 100) : 0; ?>
        <div class="fr-bar-row">
          <div class="fr-bar-lbl"><?= substr($ml,0,3) ?><?= $mn === $mes ? ' •' : '' ?></div>
          <div class="fr-bar-track"><div class="fr-bar-fill" style="width:<?= $pct ?>%;<?= $mn === $mes ? 'background:var(--s-warn-tx);' : '' ?>"></div></div>
          <div class="fr-bar-val"><?= $row['total'] > 0 ? _s($row['total']) : '—' ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Por ejecutivo -->
    <div class="fr-card">
      <div class="fr-card-head">Por ejecutivo — <?= $MESES[$mes] ?> <?= $anio ?></div>
      <div class="fr-card-body fr-scroll">
        <?php if (!$porEjec): ?>
        <div class="fr-empty">Sin facturas este mes.</div>
        <?php else: ?>
        <table class="fr-table">
          <thead><tr><th>Ejecutivo</th><th class="num">Facturas</th><th class="num">Facturado</th><th class="num">Detracción</th></tr></thead>
          <tbody>
            <?php foreach ($porEjec as $nom => $d): ?>
            <tr>
              <td><?= sanitize($nom) ?></td>
              <td class="num"><?= $d['n'] ?></td>
              <td class="num"><?= _s($d['total']) ?></td>
              <td class="num"><?= _s($d['detra']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Pendiente de facturar -->
  <div class="fr-card" style="margin-bottom:18px;">
    <div class="fr-card-head">Pendiente de facturar — cotizaciones aceptadas y pagadas sin factura de cierre (<?= count($pend) ?>)</div>
    <div class="fr-card-body fr-scroll">
      <?php if (!$pend): ?>
      <div class="fr-empty">🎉 No hay cotizaciones pagadas pendientes de facturar.</div>
      <?php else: ?>
      <table class="fr-table">
        <thead><tr><th>Cotización</th><th>Cliente</th><th>Ejecutivo</th><th>Aceptada</th><th class="num">Monto</th></tr></thead>
        <tbody>
          <?php foreach ($pend as $p): ?>
          <tr>
            <td><?= sanitize($p['numero']) ?></td>
            <td><?= sanitize($p['razon_social']) ?></td>
            <td><?= sanitize($p['ejecutivo'] ?: '—') ?></td>
            <td><?= $p['fecha_aceptacion'] ? date('d/m/Y', strtotime($p['fecha_aceptacion'])) : '—' ?></td>
            <td class="num"><?= _s($p['monto_total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Detalle de facturas del mes -->
  <div class="fr-card">
    <div class="fr-card-head">Detalle de facturas — <?= $MESES[$mes] ?> <?= $anio ?> (<?= count($facturas) ?>)</div>
    <div class="fr-card-body fr-scroll">
      <?php if (!$facturas): ?>
      <div class="fr-empty">Sin facturas emitidas en este periodo.</div>
      <?php else: ?>
      <table class="fr-table">
        <thead><tr>
          <th>Fecha</th><th>N°</th><th>Cliente</th><th>Tipo</th><th>Ejecutivo</th>
          <th class="num">Base</th><th class="num">IGV</th><th class="num">Total</th><th class="num">Detracción</th><th>SUNAT</th>
        </tr></thead>
        <tbody>
          <?php foreach ($facturas as $f): [$et,$ec] = _estadoSunat($f['estado_timbrado']); ?>
          <tr>
            <td><?= date('d/m', strtotime($f['created_at'])) ?></td>
            <td><?= sanitize($f['numero_factura'] ?: '—') ?></td>
            <td><?= sanitize($f['razon_social']) ?></td>
            <td><?= sanitize(ucfirst($f['tipo_factura'] ?: '—')) ?><?= $f['origen'] === 'manual' ? ' <span style="font-size:9px;color:var(--gris-texto);">(manual)</span>' : '' ?></td>
            <td><?= sanitize($f['ejecutivo'] ?: '—') ?></td>
            <td class="num"><?= (float)$f['monto_base'] ? _s($f['monto_base']) : '—' ?></td>
            <td class="num"><?= (float)$f['monto_igv'] ? _s($f['monto_igv']) : '—' ?></td>
            <td class="num"><?= (float)$f['monto_total'] ? _s($f['monto_total']) : '—' ?></td>
            <td class="num"><?= (float)$f['detraccion_monto'] ? _s($f['detraccion_monto']) : '—' ?></td>
            <td><span class="fr-pill <?= $ec ?>"><?= $et ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
