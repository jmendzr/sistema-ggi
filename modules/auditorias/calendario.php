<?php
$page_title  = 'Calendario de auditorías';
$active_menu = 'auditorias';
require_once __DIR__ . '/../../templates/layout.php';

$pdo = Database::get();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

// Validar rango
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$prev_month = $month - 1 ?: 12;
$prev_year  = $prev_month == 12 ? $year - 1 : $year;
$next_month = $month % 12 + 1;
$next_year  = $month == 12 ? $year + 1 : $year;

// Auditorías del mes
$desde = sprintf('%04d-%02d-01', $year, $month);
$hasta = date('Y-m-t', strtotime($desde));

$stmt = $pdo->prepare("
    SELECT a.id, a.fecha_programada, a.fecha_realizada, a.tipo, a.resultado,
           cl.razon_social, cl.id AS cliente_id,
           n.codigo AS norma,
           c.id AS certificado_id
    FROM auditorias a
    JOIN certificados c  ON c.id  = a.certificado_id
    JOIN clientes cl     ON cl.id = c.cliente_id
    JOIN normas_iso n    ON n.id  = c.norma_iso_id
    WHERE a.fecha_programada BETWEEN ? AND ?
    ORDER BY a.fecha_programada ASC
");
$stmt->execute([$desde, $hasta]);
$auditorias = $stmt->fetchAll();

// Indexar por día
$por_dia = [];
foreach ($auditorias as $a) {
    $dia = (int)date('j', strtotime($a['fecha_programada']));
    $por_dia[$dia][] = $a;
}

$dias_mes    = (int)date('t', strtotime($desde));
$primer_dia  = (int)date('N', strtotime($desde)); // 1=Lun, 7=Dom

$meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$dias_es  = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

$col_resultado = [
    'pendiente'    => ['#f59e0b','var(--s-warn-bg)'],
    'aprobado'     => ['#10b981','var(--s-ok-bg)'],
    'observado'    => ['#f97316','var(--s-warn-bg)'],
    'no_aprobado'  => ['#ef4444','var(--s-err-bg)'],
];
$tipo_label = [
    'seguimiento_1' => 'Seg. 1',
    'seguimiento_2' => 'Seg. 2',
    'renovacion'    => 'Renov.',
    'extraordinaria'=> 'Extra',
];
?>
<style>
.cal-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
.cal-nav { display:flex; align-items:center; gap:8px; }
.cal-mes { font-family:'Inter',sans-serif; font-size:22px; font-weight:700; color:var(--azul); }
.nav-btn {
  width:34px; height:34px; border-radius:8px; border:1.5px solid var(--gris-borde);
  background:var(--bg-surface); cursor:pointer; display:flex; align-items:center; justify-content:center;
  color:var(--gris-texto); text-decoration:none; transition:.15s;
}
.nav-btn:hover { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }

.cal-legend { display:flex; gap:12px; flex-wrap:wrap; }
.leg-item { display:flex; align-items:center; gap:5px; font-size:11px; color:var(--gris-texto); }
.leg-dot { width:10px; height:10px; border-radius:3px; flex-shrink:0; }

.cal-grid { background:var(--bg-surface); border-radius:14px; border:1px solid var(--gris-borde); overflow:hidden; }
.cal-weekdays {
  display:grid; grid-template-columns:repeat(7,1fr);
  border-bottom:1px solid var(--gris-borde);
}
.cal-weekday {
  padding:10px 8px; text-align:center; font-size:11px;
  font-weight:700; color:var(--gris-texto); text-transform:uppercase; letter-spacing:.4px;
}
.cal-days { display:grid; grid-template-columns:repeat(7,1fr); }
.cal-day {
  min-height:110px; padding:8px; border-right:1px solid var(--bg-surface2);
  border-bottom:1px solid var(--bg-surface2); position:relative; vertical-align:top;
}
.cal-day:nth-child(7n) { border-right:none; }
.cal-day.empty { background:var(--bg-surface2); }
.cal-day.today { background:var(--s-info-bg); }
.cal-day.today .day-num { color:var(--azul); font-weight:700; }
.cal-day.weekend .day-num { color:var(--gris-texto); }
.day-num { font-size:13px; font-weight:600; color:var(--gris-dark); margin-bottom:4px; }
.today-badge {
  display:inline-block; background:var(--azul); color:#fff;
  width:22px; height:22px; border-radius:50%; text-align:center;
  line-height:22px; font-size:12px; font-weight:700;
}

.aud-chip {
  display:block; padding:2px 7px; border-radius:5px; font-size:10px;
  font-weight:600; margin-bottom:3px; cursor:pointer; text-decoration:none;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  transition:.1s;
}
.aud-chip:hover { opacity:.8; transform:translateX(2px); }

.cal-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-top:20px; }
.sum-card { background:var(--bg-surface); border-radius:10px; border:1px solid var(--gris-borde); padding:14px 16px; text-align:center; }
.sum-val { font-family:'Inter',sans-serif; font-size:26px; font-weight:700; }
.sum-lbl { font-size:11px; color:var(--gris-texto); margin-top:3px; }

@media(max-width:768px){
  .cal-day { min-height:60px; padding:4px; }
  .aud-chip { font-size:9px; padding:1px 4px; }
  .cal-summary { grid-template-columns:repeat(2,1fr); }
  .cal-legend { display:none; }
}
</style>

<div class="cal-header">
  <div class="cal-nav">
    <a href="?year=<?= $prev_year ?>&month=<?= $prev_month ?>" class="nav-btn">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <div class="cal-mes"><?= $meses_es[$month] ?> <?= $year ?></div>
    <a href="?year=<?= $next_year ?>&month=<?= $next_month ?>" class="nav-btn">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <a href="?year=<?= date('Y') ?>&month=<?= date('m') ?>" class="nav-btn" title="Hoy" style="margin-left:4px;font-size:11px;font-weight:600;width:auto;padding:0 10px">Hoy</a>
  </div>

  <div class="cal-legend">
    <span class="leg-item"><span class="leg-dot" style="background:var(--s-warn-bg);border:1.5px solid #f59e0b"></span>Pendiente</span>
    <span class="leg-item"><span class="leg-dot" style="background:var(--s-ok-bg);border:1.5px solid #10b981"></span>Aprobado</span>
    <span class="leg-item"><span class="leg-dot" style="background:var(--s-warn-bg);border:1.5px solid #f97316"></span>Observado</span>
    <span class="leg-item"><span class="leg-dot" style="background:var(--s-err-bg);border:1.5px solid #ef4444"></span>No aprobado</span>
  </div>

  <a href="<?= APP_URL ?>/modules/auditorias/index.php" class="btn btn-outline btn-sm">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
    Vista lista
  </a>
</div>

<div class="cal-grid">
  <!-- Días de la semana -->
  <div class="cal-weekdays">
    <?php foreach ($dias_es as $d): ?>
    <div class="cal-weekday"><?= $d ?></div>
    <?php endforeach; ?>
  </div>

  <!-- Celdas del mes -->
  <div class="cal-days">
    <?php
    // Celdas vacías antes del primer día
    for ($i = 1; $i < $primer_dia; $i++):
    ?>
    <div class="cal-day empty"></div>
    <?php endfor; ?>

    <?php for ($dia = 1; $dia <= $dias_mes; $dia++):
      $hoy     = (date('Y-m-d') === sprintf('%04d-%02d-%02d', $year, $month, $dia));
      $dow     = (int)date('N', mktime(0,0,0,$month,$dia,$year)); // 1=Lun
      $weekend = $dow >= 6;
      $clases  = 'cal-day' . ($hoy?' today':'') . ($weekend?' weekend':'');
    ?>
    <div class="<?= $clases ?>">
      <div class="day-num">
        <?php if ($hoy): ?>
        <span class="today-badge"><?= $dia ?></span>
        <?php else: ?>
        <?= $dia ?>
        <?php endif; ?>
      </div>
      <?php if (!empty($por_dia[$dia])): ?>
        <?php foreach ($por_dia[$dia] as $a):
          $c = $col_resultado[$a['resultado']] ?? ['#6b7280','#f3f4f6'];
          $label = ($tipo_label[$a['tipo']] ?? $a['tipo']) . ' · ' . htmlspecialchars($a['razon_social']);
          $url = APP_URL . '/modules/auditorias/ver.php?id=' . $a['id'];
        ?>
        <a href="<?= $url ?>" class="aud-chip"
           style="background:<?= $c[1] ?>;color:<?= $c[0] ?>;border:1px solid <?= $c[0] ?>44"
           title="<?= htmlspecialchars($a['razon_social']) ?> — <?= htmlspecialchars($a['norma']) ?>">
          <?= $label ?>
        </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endfor; ?>

    <?php
    // Celdas vacías al final para completar la grilla
    $celdas_usadas = ($primer_dia - 1) + $dias_mes;
    $celdas_vacias = (7 - ($celdas_usadas % 7)) % 7;
    for ($i = 0; $i < $celdas_vacias; $i++): ?>
    <div class="cal-day empty"></div>
    <?php endfor; ?>
  </div>
</div>

<!-- Resumen del mes -->
<?php
$total_mes  = count($auditorias);
$pendientes = count(array_filter($auditorias, fn($a) => $a['resultado']==='pendiente'));
$aprobadas  = count(array_filter($auditorias, fn($a) => $a['resultado']==='aprobado'));
$otras      = $total_mes - $pendientes - $aprobadas;
?>
<div class="cal-summary">
  <div class="sum-card">
    <div class="sum-val" style="color:var(--azul)"><?= $total_mes ?></div>
    <div class="sum-lbl">Total en <?= $meses_es[$month] ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-val" style="color:#f59e0b"><?= $pendientes ?></div>
    <div class="sum-lbl">Pendientes</div>
  </div>
  <div class="sum-card">
    <div class="sum-val" style="color:#10b981"><?= $aprobadas ?></div>
    <div class="sum-lbl">Aprobadas</div>
  </div>
  <div class="sum-card">
    <div class="sum-val" style="color:#6b7280"><?= $otras ?></div>
    <div class="sum-lbl">Otros resultados</div>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
