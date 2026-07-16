<?php
$page_title  = 'Reportes';
$active_menu = 'reportes';
require_once __DIR__ . '/../../templates/layout.php';
require_role('gerente_general', 'gerente_operaciones');

$pdo  = Database::get();
$año  = (int)($_GET['año'] ?? date('Y'));
$años = $pdo->query("SELECT DISTINCT YEAR(created_at) y FROM clientes ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($año, $años ?: [])) $año = (int)date('Y');

// ══════════════════════════════════════════════════════════
//  BLOQUE 1 — KPIs principales
// ══════════════════════════════════════════════════════════
$kpi = [];
$kpi['clientes_activos']   = $pdo->query("SELECT COUNT(*) FROM clientes WHERE estado='activo'")->fetchColumn();
$kpi['clientes_nuevos_año']= $pdo->query("SELECT COUNT(*) FROM clientes WHERE YEAR(created_at)=$año")->fetchColumn();
$kpi['certs_vigentes']     = $pdo->query("SELECT COUNT(*) FROM certificados WHERE estado='vigente'")->fetchColumn();
$kpi['certs_por_vencer']   = $pdo->query("SELECT COUNT(*) FROM certificados WHERE estado='por_vencer'")->fetchColumn();
$kpi['certs_vencidos']     = $pdo->query("SELECT COUNT(*) FROM certificados WHERE estado='vencido'")->fetchColumn();
$kpi['envios_año']         = $pdo->query("SELECT COUNT(*) FROM envios WHERE YEAR(created_at)=$año")->fetchColumn();
$kpi['envios_entregados']  = $pdo->query("SELECT COUNT(*) FROM envios WHERE fecha_recibido IS NOT NULL AND YEAR(fecha_recibido)=$año")->fetchColumn();
$kpi['cot_aceptadas']      = $pdo->query("SELECT COUNT(*) FROM cotizaciones WHERE estado='aceptada' AND YEAR(created_at)=$año")->fetchColumn();
$kpi['cot_total_año']      = $pdo->query("SELECT COUNT(*) FROM cotizaciones WHERE YEAR(created_at)=$año")->fetchColumn();
$kpi['ingreso_estimado']   = $pdo->query("SELECT COALESCE(SUM(monto_total),0) FROM cotizaciones WHERE estado='aceptada' AND YEAR(created_at)=$año")->fetchColumn();
$kpi['auditorias_pendientes'] = $pdo->query("SELECT COUNT(*) FROM auditorias WHERE resultado='pendiente' AND fecha_programada >= CURDATE()")->fetchColumn();
$kpi['tasa_conversion']    = $kpi['cot_total_año'] > 0 ? round(($kpi['cot_aceptadas'] / $kpi['cot_total_año']) * 100) : 0;

// ══════════════════════════════════════════════════════════
//  BLOQUE 2 — Clientes nuevos por mes (año seleccionado)
// ══════════════════════════════════════════════════════════
$clientes_por_mes = array_fill(1, 12, 0);
$rows = $pdo->query("SELECT MONTH(created_at) m, COUNT(*) c FROM clientes WHERE YEAR(created_at)=$año GROUP BY m")->fetchAll();
foreach ($rows as $r) $clientes_por_mes[(int)$r['m']] = (int)$r['c'];

// ══════════════════════════════════════════════════════════
//  BLOQUE 3 — Estado de certificados (donut)
// ══════════════════════════════════════════════════════════
$certs_estado = $pdo->query("SELECT estado, COUNT(*) c FROM certificados GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);

// ══════════════════════════════════════════════════════════
//  BLOQUE 4 — Envíos por agencia
// ══════════════════════════════════════════════════════════
$envios_agencia = $pdo->query("
    SELECT ag.nombre, COUNT(e.id) c
    FROM envios e JOIN agencias_envio ag ON ag.id=e.agencia_envio_id
    WHERE YEAR(e.created_at)=$año
    GROUP BY ag.nombre ORDER BY c DESC LIMIT 6
")->fetchAll();

// ══════════════════════════════════════════════════════════
//  BLOQUE 5 — Certificados por norma ISO
// ══════════════════════════════════════════════════════════
$certs_norma = $pdo->query("
    SELECT n.codigo, COUNT(c.id) cnt
    FROM certificados c JOIN normas_iso n ON n.id=c.norma_iso_id
    GROUP BY n.codigo ORDER BY cnt DESC
")->fetchAll();

// ══════════════════════════════════════════════════════════
//  BLOQUE 6 — Cotizaciones por mes y estado
// ══════════════════════════════════════════════════════════
$cot_mes = array_fill(1, 12, ['total' => 0, 'aceptadas' => 0]);
$rows = $pdo->query("
    SELECT MONTH(created_at) m, estado, COUNT(*) c
    FROM cotizaciones WHERE YEAR(created_at)=$año
    GROUP BY m, estado
")->fetchAll();
foreach ($rows as $r) {
    $m = (int)$r['m'];
    $cot_mes[$m]['total'] += (int)$r['c'];
    if ($r['estado'] === 'aceptada') $cot_mes[$m]['aceptadas'] += (int)$r['c'];
}

// ══════════════════════════════════════════════════════════
//  BLOQUE 7 — Vencimientos próximos 6 meses
// ══════════════════════════════════════════════════════════
$vencimientos_proximos = $pdo->query("
    SELECT DATE_FORMAT(fecha_vencimiento,'%Y-%m') ym,
           DATE_FORMAT(fecha_vencimiento,'%b %Y') label,
           COUNT(*) c
    FROM certificados
    WHERE fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym, label ORDER BY ym
")->fetchAll();

// ══════════════════════════════════════════════════════════
//  BLOQUE 8 — Top 5 clientes por certs vigentes
// ══════════════════════════════════════════════════════════
$top_clientes = $pdo->query("
    SELECT cl.razon_social, COUNT(c.id) certs
    FROM clientes cl
    LEFT JOIN certificados c ON c.cliente_id=cl.id AND c.estado IN ('vigente','por_vencer')
    WHERE cl.estado='activo'
    GROUP BY cl.id ORDER BY certs DESC, cl.razon_social ASC LIMIT 5
")->fetchAll();

// ══════════════════════════════════════════════════════════
//  BLOQUE 9 — Auditorías: programadas vs realizadas por mes
// ══════════════════════════════════════════════════════════
$aud_mes_prog = array_fill(1, 12, 0);
$aud_mes_real = array_fill(1, 12, 0);
$rows = $pdo->query("SELECT MONTH(fecha_programada) m, COUNT(*) c FROM auditorias WHERE YEAR(fecha_programada)=$año GROUP BY m")->fetchAll();
foreach ($rows as $r) $aud_mes_prog[(int)$r['m']] = (int)$r['c'];
$rows = $pdo->query("SELECT MONTH(fecha_realizada) m, COUNT(*) c FROM auditorias WHERE YEAR(fecha_realizada)=$año AND fecha_realizada IS NOT NULL GROUP BY m")->fetchAll();
foreach ($rows as $r) $aud_mes_real[(int)$r['m']] = (int)$r['c'];

$meses_labels = json_encode(['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']);

// ══════════════════════════════════════════════════════════
//  BLOQUE 10 — Auditorías por resultado (donut)
// ══════════════════════════════════════════════════════════
$aud_resultado = $pdo->query("
    SELECT resultado, COUNT(*) c FROM auditorias
    WHERE fecha_programada <= CURDATE()
    GROUP BY resultado
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ══════════════════════════════════════════════════════════
//  BLOQUE 11 — Tasa de renovaciones exitosas
// ══════════════════════════════════════════════════════════
// Renovación = auditoria tipo 'renovacion' con resultado aprobado/observado/no_aprobado
$renov_total    = (int)$pdo->query("SELECT COUNT(*) FROM auditorias WHERE tipo='renovacion' AND resultado != 'pendiente'")->fetchColumn();
$renov_exitosas = (int)$pdo->query("SELECT COUNT(*) FROM auditorias WHERE tipo='renovacion' AND resultado='aprobado'")->fetchColumn();
$renov_tasa     = $renov_total > 0 ? round($renov_exitosas / $renov_total * 100) : 0;

// Seguimientos por resultado (seg1 + seg2)
$seg_total   = (int)$pdo->query("SELECT COUNT(*) FROM auditorias WHERE tipo IN ('seguimiento_1','seguimiento_2') AND resultado != 'pendiente'")->fetchColumn();
$seg_aprob   = (int)$pdo->query("SELECT COUNT(*) FROM auditorias WHERE tipo IN ('seguimiento_1','seguimiento_2') AND resultado='aprobado'")->fetchColumn();
$seg_observ  = (int)$pdo->query("SELECT COUNT(*) FROM auditorias WHERE tipo IN ('seguimiento_1','seguimiento_2') AND resultado='observado'")->fetchColumn();
$seg_noap    = (int)$pdo->query("SELECT COUNT(*) FROM auditorias WHERE tipo IN ('seguimiento_1','seguimiento_2') AND resultado='no_aprobado'")->fetchColumn();
$seg_tasa    = $seg_total > 0 ? round($seg_aprob / $seg_total * 100) : 0;

// Serie histórica anual de seguimientos aprobados
$renov_hist = [];
$rows = $pdo->query("
    SELECT YEAR(fecha_realizada) y,
           COUNT(*) total,
           SUM(resultado='aprobado') aprobadas
    FROM auditorias
    WHERE tipo IN ('seguimiento_1','seguimiento_2','renovacion')
      AND fecha_realizada IS NOT NULL
      AND YEAR(fecha_realizada) >= YEAR(CURDATE())-3
    GROUP BY y ORDER BY y
")->fetchAll();
foreach ($rows as $r) {
    $renov_hist[] = [
        'year'  => $r['y'],
        'total' => (int)$r['total'],
        'tasa'  => $r['total'] > 0 ? round($r['aprobadas'] / $r['total'] * 100) : 0,
    ];
}

// ══════════════════════════════════════════════════════════
//  BLOQUE 12 — Clientes por departamento del Perú
// ══════════════════════════════════════════════════════════
$rows = $pdo->query("
    SELECT UPPER(TRIM(departamento)) dept, COUNT(*) c
    FROM clientes
    WHERE departamento IS NOT NULL AND departamento != '' AND estado != 'inactivo'
    GROUP BY UPPER(TRIM(departamento))
    ORDER BY c DESC
")->fetchAll();
$clientes_dept = [];
foreach ($rows as $r) $clientes_dept[$r['dept']] = (int)$r['c'];

// Todos los departamentos del Perú para el mapa
$todos_depts = ['AMAZONAS','ANCASH','APURIMAC','AREQUIPA','AYACUCHO','CAJAMARCA',
    'CALLAO','CUSCO','HUANCAVELICA','HUANUCO','ICA','JUNIN','LA LIBERTAD',
    'LAMBAYEQUE','LIMA','LORETO','MADRE DE DIOS','MOQUEGUA','PASCO','PIURA',
    'PUNO','SAN MARTIN','TACNA','TUMBES','UCAYALI'];
$max_dept = max(array_values($clientes_dept) ?: [1]);
?>
<style>
.rep-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:24px; }
.rep-kpi {
  background:#fff; border-radius:12px; border:1px solid var(--gris-borde);
  padding:16px 18px; position:relative; overflow:hidden;
  border-left:3px solid var(--c,var(--azul));
}
.rep-kpi-val { font-family:'Outfit',sans-serif; font-size:28px; font-weight:700; color:var(--gris-dark); line-height:1; }
.rep-kpi-lbl { font-size:12px; color:var(--gris-texto); margin-top:5px; font-weight:500; }
.rep-kpi-sub { font-size:11px; color:var(--c,var(--azul)); margin-top:3px; font-weight:600; }

.rep-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px; }
.rep-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; margin-bottom:18px; }
.rep-card {
  background:#fff; border-radius:12px; border:1px solid var(--gris-borde);
  overflow:hidden;
}
.rep-card-head {
  padding:14px 18px; border-bottom:1px solid var(--gris-borde);
  display:flex; align-items:center; justify-content:space-between;
}
.rep-card-title { font-size:13px; font-weight:700; color:var(--azul); display:flex; align-items:center; gap:7px; }
.rep-card-body { padding:18px; }

.año-selector { display:flex; align-items:center; gap:8px; margin-bottom:20px; }
.año-selector select { padding:7px 12px; border:1.5px solid var(--gris-borde); border-radius:8px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; outline:none; background:#fff; color:var(--gris-dark); cursor:pointer; }
.año-selector select:focus { border-color:var(--azul); }
.año-selector label { font-size:13px; font-weight:600; color:var(--gris-texto); }

.bar-mini { display:flex; flex-direction:column; gap:8px; }
.bar-mini-row { display:flex; align-items:center; gap:10px; }
.bar-mini-label { font-size:12px; color:var(--gris-dark); width:130px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex-shrink:0; }
.bar-mini-track { flex:1; background:var(--gris-bg); border-radius:100px; height:8px; overflow:hidden; }
.bar-mini-fill  { height:100%; border-radius:100px; transition:width 0.6s ease; }
.bar-mini-val   { font-size:12px; font-weight:600; color:var(--gris-dark); width:24px; text-align:right; flex-shrink:0; }

.table-rep { width:100%; border-collapse:collapse; }
.table-rep th { font-size:10px; font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; padding:8px 12px; background:#fafbfe; border-bottom:1px solid var(--gris-borde); text-align:left; }
.table-rep td { padding:10px 12px; font-size:13px; border-bottom:1px solid #f0f2f8; color:var(--gris-dark); }
.table-rep tr:last-child td { border-bottom:none; }
.table-rep tr:hover td { background:#fafbfe; }

@media (max-width:1100px) { .rep-grid-3 { grid-template-columns:1fr 1fr; } }
@media (max-width:768px)  { .rep-grid-2,.rep-grid-3 { grid-template-columns:1fr; } .rep-kpis { grid-template-columns:repeat(2,1fr); } }
</style>

<!-- Header -->
<div style="display:flex;justify-content:flex-end;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
    <a href="<?= APP_URL ?>/modules/exportar.php?modulo=certificados" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar certificados
    </a>
    <a href="<?= APP_URL ?>/modules/exportar.php?modulo=clientes" class="btn btn-outline btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar clientes
    </a>
  </div>
</div>

<!-- Selector de año -->
<div class="año-selector">
  <label>Año de análisis:</label>
  <form method="GET" style="display:inline">
    <select name="año" onchange="this.form.submit()">
      <?php foreach (($años ?: [date('Y')]) as $a): ?>
      <option value="<?= $a ?>" <?= $a == $año ? 'selected' : '' ?>><?= $a ?></option>
      <?php endforeach; ?>
      <?php if (!in_array(date('Y'), $años ?: [])): ?>
      <option value="<?= date('Y') ?>" <?= date('Y') == $año ? 'selected' : '' ?>><?= date('Y') ?></option>
      <?php endif; ?>
    </select>
  </form>
</div>

<!-- KPIs -->
<div class="rep-kpis">
  <div class="rep-kpi" style="--c:#1A3A8F">
    <div class="rep-kpi-val"><?= $kpi['clientes_activos'] ?></div>
    <div class="rep-kpi-lbl">Clientes activos</div>
    <div class="rep-kpi-sub">+<?= $kpi['clientes_nuevos_año'] ?> en <?= $año ?></div>
  </div>
  <div class="rep-kpi" style="--c:#2DB140">
    <div class="rep-kpi-val"><?= $kpi['certs_vigentes'] ?></div>
    <div class="rep-kpi-lbl">Certificados vigentes</div>
    <div class="rep-kpi-sub"><?= $kpi['certs_por_vencer'] ?> por vencer</div>
  </div>
  <div class="rep-kpi" style="--c:#ef4444">
    <div class="rep-kpi-val"><?= $kpi['certs_vencidos'] ?></div>
    <div class="rep-kpi-lbl">Certificados vencidos</div>
    <div class="rep-kpi-sub">Requieren renovación</div>
  </div>
  <div class="rep-kpi" style="--c:#8b5cf6">
    <div class="rep-kpi-val"><?= $kpi['envios_año'] ?></div>
    <div class="rep-kpi-lbl">Envíos en <?= $año ?></div>
    <div class="rep-kpi-sub"><?= $kpi['envios_entregados'] ?> entregados</div>
  </div>
  <div class="rep-kpi" style="--c:#10b981">
    <div class="rep-kpi-val"><?= $kpi['tasa_conversion'] ?>%</div>
    <div class="rep-kpi-lbl">Conversión cotizaciones</div>
    <div class="rep-kpi-sub"><?= $kpi['cot_aceptadas'] ?>/<?= $kpi['cot_total_año'] ?> aceptadas</div>
  </div>
  <div class="rep-kpi" style="--c:#f59e0b">
    <div class="rep-kpi-val">S/ <?= number_format($kpi['ingreso_estimado'], 0, '.', ',') ?></div>
    <div class="rep-kpi-lbl">Ingreso estimado <?= $año ?></div>
    <div class="rep-kpi-sub">Cotizaciones aceptadas</div>
  </div>
  <div class="rep-kpi" style="--c:#0ea5e9">
    <div class="rep-kpi-val"><?= $kpi['auditorias_pendientes'] ?></div>
    <div class="rep-kpi-lbl">Auditorías pendientes</div>
    <div class="rep-kpi-sub">Próximas a realizarse</div>
  </div>
</div>

<!-- Fila 1: Clientes por mes + Estado certificados -->
<div class="rep-grid-2">
  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        Clientes nuevos por mes — <?= $año ?>
      </div>
    </div>
    <div class="rep-card-body"><canvas id="chart-clientes" height="200"></canvas></div>
  </div>
  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
        Estado de certificados
      </div>
    </div>
    <div class="rep-card-body" style="display:flex;align-items:center;justify-content:center;">
      <canvas id="chart-certs-estado" height="200" style="max-width:280px;"></canvas>
    </div>
  </div>
</div>

<!-- Fila 2: Cotizaciones por mes + Auditorías -->
<div class="rep-grid-2">
  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#10b981" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Cotizaciones por mes — <?= $año ?>
      </div>
    </div>
    <div class="rep-card-body"><canvas id="chart-cot" height="200"></canvas></div>
  </div>
  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#0ea5e9" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Auditorías programadas vs realizadas — <?= $año ?>
      </div>
    </div>
    <div class="rep-card-body"><canvas id="chart-aud" height="200"></canvas></div>
  </div>
</div>

<!-- Fila 3: Envíos por agencia + Certificados por norma + Vencimientos próximos -->
<div class="rep-grid-3">
  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#8b5cf6" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/></svg>
        Envíos por agencia — <?= $año ?>
      </div>
    </div>
    <div class="rep-card-body">
      <?php
      $max_env = max(array_column($envios_agencia, 'c') ?: [1]);
      $colores = ['#8b5cf6','#1A3A8F','#2DB140','#f59e0b','#0ea5e9','#ef4444'];
      ?>
      <?php if (empty($envios_agencia)): ?>
        <p style="color:var(--gris-texto);font-size:13px;text-align:center;padding:20px 0;">Sin envíos en <?= $año ?></p>
      <?php else: ?>
      <div class="bar-mini">
        <?php foreach ($envios_agencia as $i => $row): ?>
        <div class="bar-mini-row">
          <span class="bar-mini-label" title="<?= htmlspecialchars($row['nombre']) ?>"><?= htmlspecialchars($row['nombre']) ?></span>
          <div class="bar-mini-track">
            <div class="bar-mini-fill" style="width:<?= round(($row['c']/$max_env)*100) ?>%;background:<?= $colores[$i % count($colores)] ?>"></div>
          </div>
          <span class="bar-mini-val"><?= $row['c'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Vencimientos próximos 6 meses
      </div>
    </div>
    <div class="rep-card-body">
      <?php if (empty($vencimientos_proximos)): ?>
        <p style="color:var(--gris-texto);font-size:13px;text-align:center;padding:20px 0;">Sin vencimientos próximos</p>
      <?php else: ?>
        <canvas id="chart-venc" height="180"></canvas>
      <?php endif; ?>
    </div>
  </div>

  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Certificados por norma ISO
      </div>
    </div>
    <div class="rep-card-body">
      <?php if (empty($certs_norma)): ?>
        <p style="color:var(--gris-texto);font-size:13px;text-align:center;padding:20px 0;">Sin datos</p>
      <?php else: ?>
      <?php $max_cn = max(array_column($certs_norma, 'cnt') ?: [1]); ?>
      <div class="bar-mini">
        <?php foreach ($certs_norma as $i => $row): ?>
        <div class="bar-mini-row">
          <span class="bar-mini-label" title="<?= htmlspecialchars($row['codigo']) ?>"><?= htmlspecialchars($row['codigo']) ?></span>
          <div class="bar-mini-track">
            <div class="bar-mini-fill" style="width:<?= round(($row['cnt']/$max_cn)*100) ?>%;background:#1A3A8F"></div>
          </div>
          <span class="bar-mini-val"><?= $row['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Fila 4: Top clientes -->
<div class="rep-card" style="margin-bottom:18px;">
  <div class="rep-card-head">
    <div class="rep-card-title">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>
      Top 5 clientes por certificados activos
    </div>
  </div>
  <div class="rep-card-body" style="padding:0;">
    <table class="table-rep">
      <thead><tr><th>#</th><th>Empresa</th><th>Certificados vigentes</th></tr></thead>
      <tbody>
        <?php foreach ($top_clientes as $i => $row): ?>
        <tr>
          <td><strong style="color:var(--azul);font-size:14px;"><?= $i+1 ?></strong></td>
          <td><?= htmlspecialchars($row['razon_social']) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="flex:1;max-width:120px;background:var(--gris-bg);border-radius:100px;height:6px;overflow:hidden;">
                <div style="width:<?= $top_clientes[0]['certs'] > 0 ? round(($row['certs']/$top_clientes[0]['certs'])*100) : 0 ?>%;height:100%;background:var(--azul);border-radius:100px;"></div>
              </div>
              <strong><?= $row['certs'] ?></strong>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($top_clientes)): ?>
        <tr><td colspan="3" style="text-align:center;color:var(--gris-texto);padding:20px;">Sin datos disponibles</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     NUEVOS BLOQUES: Auditorías · Renovaciones · Mapa
     ══════════════════════════════════════════════════ -->

<!-- Auditorías por resultado + Tasa renovaciones -->
<div class="rep-grid-2" style="margin-top:18px">

  <!-- Donut auditorías por resultado -->
  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#0ea5e9" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Auditorías por resultado
      </div>
    </div>
    <div class="rep-card-body" style="display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:16px">
      <div style="position:relative;max-width:180px;margin:0 auto"><canvas id="chart-aud-res" height="180"></canvas></div>
      <div>
        <?php
        $aud_cfg = [
          'aprobado'    => ['#16a34a','Aprobado'],
          'observado'   => ['#f97316','Con observ.'],
          'no_aprobado' => ['#dc2626','No aprobado'],
          'pendiente'   => ['#6b7280','Pendiente'],
        ];
        $aud_total_res = array_sum($aud_resultado ?: [0]);
        foreach ($aud_cfg as $k => [$col,$lbl]):
          $n   = $aud_resultado[$k] ?? 0;
          $pct = $aud_total_res > 0 ? round($n/$aud_total_res*100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
          <div style="width:10px;height:10px;border-radius:3px;background:<?= $col ?>;flex-shrink:0"></div>
          <div style="flex:1;font-size:12px;color:var(--gris-texto)"><?= $lbl ?></div>
          <strong style="font-size:13px;color:<?= $col ?>"><?= $n ?></strong>
          <span style="font-size:11px;color:var(--gris-texto);min-width:32px;text-align:right"><?= $pct ?>%</span>
        </div>
        <?php endforeach; ?>
        <div style="border-top:1px solid var(--gris-borde);padding-top:8px;margin-top:4px;font-size:11px;color:var(--gris-texto)">
          Total: <strong style="color:var(--gris-dark)"><?= $aud_total_res ?></strong> auditorías realizadas
        </div>
      </div>
    </div>
  </div>

  <!-- Tasa de renovaciones y seguimientos exitosos -->
  <div class="rep-card">
    <div class="rep-card-head">
      <div class="rep-card-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Tasa de éxito en auditorías
      </div>
    </div>
    <div class="rep-card-body">
      <!-- Métricas principales -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
        <div style="background:#f0fdf4;border-radius:10px;padding:14px;text-align:center;border:1px solid #bbf7d0">
          <div style="font-family:'Outfit',sans-serif;font-size:32px;font-weight:700;color:#16a34a;line-height:1"><?= $seg_tasa ?>%</div>
          <div style="font-size:11px;color:#15803d;margin-top:4px;font-weight:600">Seguimientos aprobados</div>
          <div style="font-size:10px;color:#6b7a99;margin-top:2px"><?= $seg_aprob ?> de <?= $seg_total ?> realizados</div>
        </div>
        <div style="background:#eff6ff;border-radius:10px;padding:14px;text-align:center;border:1px solid #bfdbfe">
          <div style="font-family:'Outfit',sans-serif;font-size:32px;font-weight:700;color:#1A3A8F;line-height:1"><?= $renov_tasa ?>%</div>
          <div style="font-size:11px;color:#1A3A8F;margin-top:4px;font-weight:600">Renovaciones aprobadas</div>
          <div style="font-size:10px;color:#6b7a99;margin-top:2px"><?= $renov_exitosas ?> de <?= $renov_total ?> realizadas</div>
        </div>
      </div>
      <!-- Desglose seguimientos -->
      <div style="font-size:11px;color:var(--gris-texto);margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Desglose seguimientos</div>
      <?php foreach ([['Aprobado','#16a34a',$seg_aprob],['Con observaciones','#f97316',$seg_observ],['No aprobado','#dc2626',$seg_noap]] as [$lbl,$col,$n]):
        $pct = $seg_total > 0 ? round($n/$seg_total*100) : 0;
      ?>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
        <div style="font-size:12px;color:var(--gris-texto);width:130px"><?= $lbl ?></div>
        <div style="flex:1;height:6px;background:#f0f2f8;border-radius:3px;overflow:hidden">
          <div style="width:<?= $pct ?>%;height:100%;background:<?= $col ?>;border-radius:3px"></div>
        </div>
        <strong style="font-size:12px;color:<?= $col ?>;min-width:28px;text-align:right"><?= $n ?></strong>
      </div>
      <?php endforeach; ?>
      <!-- Histórico si hay datos -->
      <?php if (!empty($renov_hist)): ?>
      <div style="margin-top:14px">
        <div style="font-size:11px;color:var(--gris-texto);margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Tasa de éxito histórica</div>
        <canvas id="chart-renov-hist" height="70"></canvas>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Mapa de clientes por departamento -->
<div class="rep-card" style="margin-bottom:18px">
  <div class="rep-card-head">
    <div class="rep-card-title">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#8b5cf6" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      Distribución de clientes por departamento
    </div>
  </div>
  <div class="rep-card-body">
    <?php if (empty($clientes_dept)): ?>
    <p style="text-align:center;color:var(--gris-texto);font-size:13px;padding:20px 0">Sin datos de ubicación registrados</p>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">

      <!-- Lista con barras -->
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px">
          Clientes por departamento
        </div>
        <?php foreach ($clientes_dept as $dept => $n):
          $pct = round($n / $max_dept * 100);
          $color = $n === $max_dept ? '#1A3A8F' : '#8b5cf6';
        ?>
        <div style="margin-bottom:9px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
            <span style="font-size:12px;font-weight:600;color:var(--gris-dark)"><?= ucwords(strtolower($dept)) ?></span>
            <span style="font-size:12px;font-weight:700;color:<?= $color ?>"><?= $n ?></span>
          </div>
          <div style="height:6px;background:#f0f2f8;border-radius:3px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:3px"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Mapa SVG del Perú con regiones coloreadas -->
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px">
          Mapa del Perú
        </div>
        <!-- Grilla visual de departamentos como heatmap -->
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:4px">
          <?php foreach ($todos_depts as $dept):
            $n    = $clientes_dept[$dept] ?? 0;
            $pct  = $max_dept > 0 ? $n / $max_dept : 0;
            $r    = (int)(26  + (26 - 26) * $pct);
            // Color: de gris claro a azul GGI según densidad
            if ($n === 0)       $bg = '#f0f2f8';
            elseif ($pct < 0.3) $bg = '#dbeafe';
            elseif ($pct < 0.6) $bg = '#3b82f6';
            else                $bg = '#1A3A8F';
            $txt = $n === 0 ? '#9ca3af' : '#fff';
            $title = ucwords(strtolower($dept)) . ': ' . $n . ' cliente' . ($n !== 1 ? 's' : '');
          ?>
          <div title="<?= $title ?>" style="background:<?= $bg ?>;color:<?= $txt ?>;border-radius:6px;padding:6px 4px;text-align:center;font-size:9px;font-weight:700;line-height:1.2;cursor:default;border:1px solid rgba(0,0,0,.05)">
            <?= strtoupper(substr($dept,0,3)) ?>
            <?php if ($n > 0): ?>
            <div style="font-size:11px;font-weight:700;margin-top:2px"><?= $n ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <!-- Leyenda heatmap -->
        <div style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:10px;color:var(--gris-texto)">
          <span>Sin clientes</span>
          <div style="display:flex;gap:2px">
            <div style="width:16px;height:10px;border-radius:2px;background:#f0f2f8;border:1px solid #e5e7eb"></div>
            <div style="width:16px;height:10px;border-radius:2px;background:#dbeafe"></div>
            <div style="width:16px;height:10px;border-radius:2px;background:#3b82f6"></div>
            <div style="width:16px;height:10px;border-radius:2px;background:#1A3A8F"></div>
          </div>
          <span>Mayor concentración</span>
        </div>
      </div>

    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#6b7a99';

const MESES = <?= $meses_labels ?>;

// ── Clientes nuevos por mes ──
new Chart(document.getElementById('chart-clientes'), {
  type: 'bar',
  data: {
    labels: MESES,
    datasets: [{
      label: 'Clientes nuevos',
      data: <?= json_encode(array_values($clientes_por_mes)) ?>,
      backgroundColor: '#1A3A8F22',
      borderColor: '#1A3A8F',
      borderWidth: 2,
      borderRadius: 6,
    }]
  },
  options: { responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f2f8'}},x:{grid:{display:false}}} }
});

// ── Estado certificados (donut) ──
const certsData = <?= json_encode(array_values($certs_estado ?: ['vigente'=>0])) ?>;
const certsLabels = <?= json_encode(array_map(function($e){ $m=['vigente'=>'Vigente','por_vencer'=>'Por vencer','vencido'=>'Vencido','en_proceso'=>'En proceso','suspendido'=>'Suspendido']; return $m[$e] ?? $e; }, array_keys($certs_estado ?: ['vigente'=>''])))?>;
new Chart(document.getElementById('chart-certs-estado'), {
  type: 'doughnut',
  data: {
    labels: certsLabels,
    datasets:[{ data: certsData, backgroundColor:['#2DB140','#f59e0b','#ef4444','#3B82F6','#6B7280'], borderWidth:0, hoverOffset:6 }]
  },
  options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ padding:14, font:{size:11} } } }, cutout:'65%' }
});

// ── Cotizaciones por mes ──
new Chart(document.getElementById('chart-cot'), {
  type: 'bar',
  data: {
    labels: MESES,
    datasets: [
      { label:'Total',    data: <?= json_encode(array_values(array_map(function($v){return $v['total'];}, $cot_mes))) ?>, backgroundColor:'#10b98122', borderColor:'#10b981', borderWidth:2, borderRadius:4 },
      { label:'Aceptadas',data: <?= json_encode(array_values(array_map(function($v){return $v['aceptadas'];}, $cot_mes))) ?>, backgroundColor:'#1A3A8F44', borderColor:'#1A3A8F', borderWidth:2, borderRadius:4 },
    ]
  },
  options:{ responsive:true, plugins:{legend:{position:'bottom',labels:{padding:14,font:{size:11}}}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f2f8'}},x:{grid:{display:false}}} }
});

// ── Auditorías programadas vs realizadas ──
new Chart(document.getElementById('chart-aud'), {
  type: 'line',
  data: {
    labels: MESES,
    datasets: [
      { label:'Programadas', data: <?= json_encode(array_values($aud_mes_prog)) ?>, borderColor:'#0ea5e9', backgroundColor:'#0ea5e911', fill:true, tension:0.4, pointRadius:4 },
      { label:'Realizadas',  data: <?= json_encode(array_values($aud_mes_real)) ?>, borderColor:'#2DB140',  backgroundColor:'#2DB14011', fill:true, tension:0.4, pointRadius:4 },
    ]
  },
  options:{ responsive:true, plugins:{legend:{position:'bottom',labels:{padding:14,font:{size:11}}}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f2f8'}},x:{grid:{display:false}}} }
});

<?php if (!empty($vencimientos_proximos)): ?>
// ── Vencimientos próximos ──
new Chart(document.getElementById('chart-venc'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($vencimientos_proximos, 'label')) ?>,
    datasets:[{ label:'Certificados', data: <?= json_encode(array_column($vencimientos_proximos,'c')) ?>, backgroundColor:'#f59e0b33', borderColor:'#f59e0b', borderWidth:2, borderRadius:6 }]
  },
  options:{ responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f2f8'}},x:{grid:{display:false}}} }
});
<?php endif; ?>

// ── Auditorías por resultado (donut) ──
new Chart(document.getElementById('chart-aud-res'), {
  type: 'doughnut',
  data: {
    labels: ['Aprobado','Con observ.','No aprobado','Pendiente'],
    datasets: [{ data: [
      <?= (int)($aud_resultado['aprobado']    ?? 0) ?>,
      <?= (int)($aud_resultado['observado']   ?? 0) ?>,
      <?= (int)($aud_resultado['no_aprobado'] ?? 0) ?>,
      <?= (int)($aud_resultado['pendiente']   ?? 0) ?>
    ], backgroundColor:['#16a34a','#f97316','#dc2626','#e5e7eb'], borderWidth:2, borderColor:'#fff' }]
  },
  options:{ responsive:true, cutout:'68%', plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){ return ' '+c.label+': '+c.raw; }}}} }
});

<?php if (!empty($renov_hist)): ?>
// ── Histórico tasa de éxito ──
new Chart(document.getElementById('chart-renov-hist'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($renov_hist,'year')) ?>,
    datasets:[{ label:'Tasa de éxito %', data: <?= json_encode(array_column($renov_hist,'tasa')) ?>,
      borderColor:'#16a34a', backgroundColor:'#16a34a18', fill:true, tension:0.4, pointRadius:5,
      pointBackgroundColor:'#16a34a' }]
  },
  options:{ responsive:true, plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,max:100,ticks:{callback:function(v){return v+'%'}},grid:{color:'#f0f2f8'}},x:{grid:{display:false}}} }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
