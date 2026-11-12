<?php
/**
 * modules/llamadas/index.php — Calendario de llamadas agendadas.
 *
 * Reutiliza la tabla llamadas_programadas (agenda del CRM) y muestra sus
 * registros en una vista de calendario mensual. Las acciones (realizar /
 * cancelar / reprogramar) van al endpoint existente clientes/ajax_llamada.php.
 */
$page_title  = 'Calendario de llamadas';
$active_menu = 'llamadas';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas','gerente_comercial');
$pdo = Database::get();

// ── Mes visible (?ym=YYYY-MM) ──
$ym = (string)($_GET['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$primerDia = DateTime::createFromFormat('Y-m-d', $ym . '-01') ?: new DateTime('first day of this month');
$primerDia->setTime(0, 0, 0);
$anio = (int)$primerDia->format('Y');
$mes  = (int)$primerDia->format('n');
$diasEnMes = (int)$primerDia->format('t');
// Lunes = 0 … Domingo = 6
$offset = ((int)$primerDia->format('N')) - 1;

$prevYm = (clone $primerDia)->modify('-1 month')->format('Y-m');
$nextYm = (clone $primerDia)->modify('+1 month')->format('Y-m');

// ── Visibilidad: gerencia ve todo; ejecutivo ve las suyas (asignadas o creadas) ──
$esJefe = has_role('gerente_general', 'gerente_operaciones');
$rango_ini = $primerDia->format('Y-m-d 00:00:00');
$rango_fin = (clone $primerDia)->modify('+1 month')->format('Y-m-d 00:00:00');

$where = ['lp.fecha_hora >= ?', 'lp.fecha_hora < ?'];
$args  = [$rango_ini, $rango_fin];
if (!$esJefe) {
    $where[] = '(lp.usuario_id = ? OR lp.created_by = ?)';
    $args[]  = $user['id'];
    $args[]  = $user['id'];
}
$q = $pdo->prepare("
    SELECT lp.id, lp.cliente_id, lp.cotizacion_id, lp.fecha_hora, lp.nota, lp.estado,
           cl.razon_social,
           TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS ejecutivo
      FROM llamadas_programadas lp
      LEFT JOIN clientes cl ON cl.id = lp.cliente_id
      LEFT JOIN usuarios u  ON u.id  = lp.usuario_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY lp.fecha_hora ASC
");
$q->execute($args);

// Agrupar por día (1..31)
$porDia = [];
$total  = 0;
foreach ($q as $r) {
    $d = (int)date('j', strtotime($r['fecha_hora']));
    $porDia[$d][] = $r;
    $total++;
}

$hoy = date('Y-m-d');
$meses = ['', 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$dias  = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

require_once __DIR__ . '/../../templates/layout.php';
?>
<!-- data-spa-nocache: el calendario cambia al marcar llamadas en otras páginas → siempre fresco -->
<div class="page-header cal-head" data-spa-nocache style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
  <div>
    <h1>Calendario de llamadas</h1>
    <p style="color:var(--tx3);font-size:13px;margin-top:2px;"><?= $total ?> llamada<?= $total !== 1 ? 's' : '' ?> en <?= $meses[$mes] ?> <?= $anio ?></p>
  </div>
  <div class="cal-nav" style="display:flex;align-items:center;gap:8px;">
    <a href="?ym=<?= $prevYm ?>" class="btn btn-outline btn-sm" title="Mes anterior" aria-label="Mes anterior">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <a href="?ym=<?= date('Y-m') ?>" class="btn btn-outline btn-sm">Hoy</a>
    <a href="?ym=<?= $nextYm ?>" class="btn btn-outline btn-sm" title="Mes siguiente" aria-label="Mes siguiente">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <span class="cal-mes" style="min-width:150px;text-align:center;font-weight:700;font-size:15px;color:var(--tx);"><?= $meses[$mes] ?> <?= $anio ?></span>
  </div>
</div>

<style>
  .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:1px; background:var(--brd); border:1px solid var(--brd); border-radius:12px; overflow:hidden; }
  .cal-dow  { background:var(--bg-surface); padding:8px 10px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--tx3); text-align:left; }
  .cal-cell { background:var(--bg-surface); min-height:104px; padding:6px 7px; display:flex; flex-direction:column; gap:4px; }
  .cal-cell.otro { background:var(--bg-base); }
  .cal-num  { font-size:12px; font-weight:600; color:var(--tx2); align-self:flex-start; width:24px; height:24px; display:flex; align-items:center; justify-content:center; border-radius:50%; }
  .cal-cell.hoy .cal-num { background:var(--ac); color:#fff; }
  .cal-ll   { display:block; text-decoration:none; font-size:11.5px; line-height:1.3; padding:3px 6px; border-radius:6px;
              background:var(--ac-soft); color:var(--tx); border-left:3px solid var(--ac); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .cal-ll:hover { filter:brightness(.97); }
  .cal-ll.venc  { background:var(--s-err-bg); border-left-color:var(--s-err); }
  .cal-ll.hecha { background:var(--s-ok-bg,#ecfdf5); border-left-color:var(--gr,#10b981); opacity:.75; }
  .cal-ll b { font-weight:700; }
  .cal-empty { color:var(--tx3); font-size:12px; padding:40px; text-align:center; }
  /* Scroll horizontal en pantallas estrechas: el grid mantiene celdas legibles */
  .cal-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  @media (max-width:768px){
    .cal-grid { min-width:640px; }   /* evita columnas apretadas ilegibles */
    .cal-cell { min-height:80px; }
    .cal-dow  { padding:6px 6px; font-size:10px; }
    .cal-ll   { font-size:11px; }
    .cal-head { flex-direction:column; align-items:stretch !important; gap:10px !important; }
    .cal-head .cal-nav { justify-content:space-between; }
    .cal-head .cal-mes { flex:1; text-align:center; }
  }
</style>

<?php if ($total === 0): ?>
  <div class="cal-empty">No hay llamadas agendadas en <?= $meses[$mes] ?> <?= $anio ?>.</div>
<?php else: ?>
<div class="cal-scroll">
<div class="cal-grid" role="grid" aria-label="Calendario de llamadas">
  <?php foreach ($dias as $d): ?><div class="cal-dow"><?= $d ?></div><?php endforeach; ?>
  <?php
  // Celdas vacías antes del día 1
  for ($i = 0; $i < $offset; $i++) echo '<div class="cal-cell otro"></div>';
  for ($dia = 1; $dia <= $diasEnMes; $dia++):
      $fecha   = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
      $esHoy   = ($fecha === $hoy);
      $llamadas = $porDia[$dia] ?? [];
  ?>
    <div class="cal-cell<?= $esHoy ? ' hoy' : '' ?>">
      <span class="cal-num"><?= $dia ?></span>
      <?php foreach ($llamadas as $ll):
        $ts    = strtotime($ll['fecha_hora']);
        $venc  = ($ts < time() && in_array($ll['estado'], ['pendiente','notificada'], true));
        $hecha = ($ll['estado'] === 'realizada');
        $cls   = $hecha ? 'hecha' : ($venc ? 'venc' : '');
        $emp   = $ll['razon_social'] ?: 'Cliente';
        $href  = $ll['cliente_id'] ? (APP_URL.'/modules/clientes/ver.php?id='.(int)$ll['cliente_id']) : '#';
        $tt    = date('H:i', $ts).' · '.$emp.($ll['ejecutivo'] ? ' → '.$ll['ejecutivo'] : '').($ll['nota'] ? ' · '.$ll['nota'] : '');
      ?>
        <a class="cal-ll <?= $cls ?>" href="<?= $href ?>" title="<?= sanitize($tt) ?>">
          <b><?= date('H:i', $ts) ?></b> <?= sanitize(mb_strimwidth($emp, 0, 22, '…')) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endfor;
  // Rellenar la última semana
  $celdasUsadas = $offset + $diasEnMes;
  $resto = (7 - ($celdasUsadas % 7)) % 7;
  for ($i = 0; $i < $resto; $i++) echo '<div class="cal-cell otro"></div>';
  ?>
</div>
</div>
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;font-size:12px;color:var(--tx3);">
  <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--ac);vertical-align:middle;margin-right:5px;"></span>Programada</span>
  <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--s-err,#dc2626);vertical-align:middle;margin-right:5px;"></span>Vencida (sin realizar)</span>
  <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--gr,#10b981);vertical-align:middle;margin-right:5px;"></span>Realizada</span>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
