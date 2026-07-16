<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas');

$pdo = Database::get();

$where = ['1=1']; $params = [];
if ($user['rol'] === 'ejecutivo_ventas') {
    $where[] = 'cot.ejecutivo_id = ?';
    $params[] = $user['id'];
}
$sql_where = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT cot.id, cot.numero, cot.estado, cot.monto_total, cot.fecha_emision, cot.fecha_vencimiento,
           COALESCE(cl.razon_social, cot.empresa_nombre) AS nombre_empresa,
           ej.nombre AS ej_nombre, ej.apellido AS ej_apellido,
           DATEDIFF(CURDATE(), cot.fecha_emision) AS dias_enviada,
           (SELECT COUNT(*) FROM cotizacion_seguimientos WHERE cotizacion_id=cot.id) AS seg_count
    FROM cotizaciones cot
    LEFT JOIN clientes cl ON cl.id=cot.cliente_id
    LEFT JOIN usuarios ej ON ej.id=cot.ejecutivo_id
    WHERE $sql_where
    ORDER BY cot.monto_total DESC
");
$stmt->execute($params);
$all = $stmt->fetchAll();

// Group by estado
$cols = ['borrador'=>[],'enviada'=>[],'aceptada'=>[],'rechazada'=>[],'vencida'=>[],'desistida'=>[]];
foreach ($all as $c) $cols[$c['estado']][] = $c;

$col_config = [
    'borrador'  => ['label'=>'Borrador',   'color'=>'var(--tx2)','bg'=>'var(--s-info-bg)'],
    'enviada'   => ['label'=>'Enviada',    'color'=>'#3B82F6','bg'=>'var(--s-info-bg)'],
    'aceptada'  => ['label'=>'Aceptada',   'color'=>'#10B981','bg'=>'var(--s-ok-bg)'],
    'rechazada' => ['label'=>'Rechazada',  'color'=>'#EF4444','bg'=>'var(--s-err-bg)'],
    'vencida'   => ['label'=>'Vencida',    'color'=>'#F59E0B','bg'=>'var(--s-warn-bg)'],
    'desistida' => ['label'=>'Desistida',  'color'=>'var(--tx2)','bg'=>'var(--s-neutral-bg)'],
];

$page_title  = 'Pipeline de Ventas';
$active_menu = 'propuestas';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .pipeline-wrap { display:flex; gap:14px; overflow-x:auto; padding-bottom:16px; align-items:flex-start; }
  .pipeline-col { min-width:260px; max-width:280px; flex-shrink:0; }
  .pipeline-col-hdr { padding:10px 14px; border-radius:10px 10px 0 0; display:flex; align-items:center; justify-content:space-between; }
  .pipeline-col-hdr-title { font-size:12px; font-weight:700; display:flex; align-items:center; gap:6px; }
  .pipeline-col-body { background:var(--gris-bg); border-radius:0 0 10px 10px; border:1px solid var(--gris-borde); border-top:none; padding:10px; display:flex; flex-direction:column; gap:8px; min-height:120px; }
  .pipeline-card { background:var(--gris-card); border:1px solid var(--gris-borde); border-radius:10px; padding:12px 14px; text-decoration:none; display:block; transition:box-shadow 0.15s, border-color 0.15s; }
  .pipeline-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); border-color:var(--azul); }
  .pc-numero { font-size:10px; font-weight:700; color:var(--gris-texto); margin-bottom:3px; }
  .pc-empresa { font-size:12px; font-weight:600; color:var(--gris-dark); margin-bottom:6px; line-height:1.3; }
  .pc-monto { font-size:13px; font-weight:700; color:var(--verde); }
  .pc-footer { display:flex; align-items:center; justify-content:space-between; margin-top:8px; }
  .pc-dias { font-size:10px; color:var(--gris-texto); }
  .pc-seg { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:600; color:#475569; background:var(--s-info-bg); padding:1px 6px; border-radius:100px; }
  .col-total { font-size:11px; font-weight:600; color:var(--gris-dark); }
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Pipeline de Ventas</h1>
    <p><?= count($all) ?> propuestas en total</p>
  </div>
  <a href="<?= APP_URL ?>/modules/cotizaciones/index.php" class="btn btn-outline btn-sm">← Lista</a>
</div>

<div class="pipeline-wrap">
<?php foreach ($col_config as $estado => $cfg):
  $items = $cols[$estado];
  $total_monto = array_sum(array_column($items, 'monto_total'));
?>
<div class="pipeline-col">
  <div class="pipeline-col-hdr" style="background:<?= $cfg['color'] ?>22;border:1px solid <?= $cfg['color'] ?>44;">
    <div class="pipeline-col-hdr-title">
      <span style="width:8px;height:8px;border-radius:50%;background:<?= $cfg['color'] ?>;display:inline-block;"></span>
      <?= $cfg['label'] ?>
      <span style="background:<?= $cfg['color'] ?>33;color:<?= $cfg['color'] ?>;font-size:10px;padding:1px 7px;border-radius:100px;"><?= count($items) ?></span>
    </div>
    <span class="col-total"><?= $total_monto ? 'S/ '.number_format($total_monto,0) : '' ?></span>
  </div>
  <div class="pipeline-col-body">
    <?php if (empty($items)): ?>
    <div style="text-align:center;padding:20px 0;font-size:12px;color:var(--gris-texto);">Sin propuestas</div>
    <?php else: ?>
    <?php foreach ($items as $c): ?>
    <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $c['id'] ?>" class="pipeline-card">
      <div class="pc-numero"><?= sanitize($c['numero']) ?></div>
      <div class="pc-empresa"><?= sanitize($c['nombre_empresa']) ?></div>
      <?php if ($c['monto_total']): ?>
      <div class="pc-monto">S/ <?= number_format($c['monto_total'],2) ?></div>
      <?php endif; ?>
      <div class="pc-footer">
        <span class="pc-dias">
          <?php if ($c['fecha_emision']): ?>
          <?= (int)$c['dias_enviada'] ?>d
          <?php if (!in_array($c['ej_nombre'] ?? '', ['',''])): ?>
          · <?= sanitize($c['ej_nombre'].' '.($c['ej_apellido']??'')) ?>
          <?php endif; ?>
          <?php endif; ?>
        </span>
        <?php if ($c['seg_count'] > 0): ?>
        <span class="pc-seg">
          <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <?= $c['seg_count'] ?>
        </span>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
