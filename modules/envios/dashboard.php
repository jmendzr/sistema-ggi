<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial','gestor_logistico','administracion_finanzas');

$pdo  = Database::get();
$user = auth_user();

// ── Restricción por rol ──────────────────────────────────────────────────────
$where_rol    = '1=1';
$params_rol   = [];
if ($user['rol'] === 'ejecutivo_ventas') {
    $where_rol  = '(e.ejecutivo_id=? OR cl.ejecutivo_id=? OR cl.responsible_user_id=? OR cl.created_by_user_id=?)';
    $params_rol = [$user['id'], $user['id'], $user['id'], $user['id']];
} elseif ($user['rol'] === 'gestor_logistico') {
    $where_rol  = '(e.agente_id=? OR (e.agente_id IS NULL AND e.estado_envio_id NOT IN (5,6)))';
    $params_rol = [$user['id']];
}

// ── Helper: ejecutar query con filtro de rol ─────────────────────────────────
$sql_from = "FROM envios e
             JOIN clientes cl ON cl.id=e.cliente_id
             LEFT JOIN agencias_envio ag ON ag.id=e.agencia_envio_id
             WHERE e.deleted_at IS NULL AND $where_rol";

$qcount = function(string $extra = '1=1') use ($pdo, $sql_from, $params_rol): int {
    $st = $pdo->prepare("SELECT COUNT(*) $sql_from AND $extra");
    $st->execute($params_rol);
    return (int)$st->fetchColumn();
};

// ── KPIs ─────────────────────────────────────────────────────────────────────
$total        = $qcount();
$entregados   = $qcount('e.estado_envio_id=5');
$devueltos    = $qcount('e.estado_envio_id=6');
$en_proceso   = $total - $entregados - $devueltos;

// Tiempo promedio entrega
$st = $pdo->prepare("SELECT ROUND(AVG(DATEDIFF(e.fecha_recibido,e.fecha_envio)),1) $sql_from AND e.fecha_recibido IS NOT NULL AND e.fecha_envio IS NOT NULL");
$st->execute($params_rol);
$prom_dias = $st->fetchColumn() ?: 0;

// Tasa de entrega
$tasa_entrega = $total > 0 ? round($entregados / $total * 100, 1) : 0;

// Mes actual vs anterior
$mes_actual_count = $qcount("MONTH(e.fecha_envio)=MONTH(NOW()) AND YEAR(e.fecha_envio)=YEAR(NOW())");
$mes_anterior_count = $qcount("MONTH(e.fecha_envio)=MONTH(DATE_SUB(NOW(),INTERVAL 1 MONTH)) AND YEAR(e.fecha_envio)=YEAR(DATE_SUB(NOW(),INTERVAL 1 MONTH))");
$delta_mes = $mes_anterior_count > 0 ? round(($mes_actual_count - $mes_anterior_count) / $mes_anterior_count * 100) : null;

// Estancados
$estancados_count = $qcount("e.estado_envio_id NOT IN (5,6) AND DATEDIFF(NOW(),e.updated_at)>7");

// ── Por estado ────────────────────────────────────────────────────────────────
$st = $pdo->prepare("
    SELECT es.nombre, es.color_hex, COUNT(*) AS total
    FROM envios e
    JOIN clientes cl ON cl.id=e.cliente_id
    LEFT JOIN agencias_envio ag ON ag.id=e.agencia_envio_id
    JOIN estados_envio es ON es.id=e.estado_envio_id
    WHERE e.deleted_at IS NULL AND $where_rol
    GROUP BY es.id, es.nombre, es.color_hex, es.orden
    ORDER BY es.orden");
$st->execute($params_rol);
$por_estado = $st->fetchAll(PDO::FETCH_ASSOC);
$max_estado = max(array_column($por_estado, 'total') ?: [1]);

// ── Por agencia ───────────────────────────────────────────────────────────────
$st = $pdo->prepare("
    SELECT COALESCE(ag.nombre,'Sin agencia') AS nombre, COUNT(*) AS total
    FROM envios e
    JOIN clientes cl ON cl.id=e.cliente_id
    LEFT JOIN agencias_envio ag ON ag.id=e.agencia_envio_id
    WHERE e.deleted_at IS NULL AND $where_rol
    GROUP BY ag.id, ag.nombre
    ORDER BY total DESC");
$st->execute($params_rol);
$por_agencia = $st->fetchAll(PDO::FETCH_ASSOC);
$max_agencia = max(array_column($por_agencia, 'total') ?: [1]);

// ── Por mes (últimos 6 meses) ─────────────────────────────────────────────────
$st = $pdo->prepare("
    SELECT DATE_FORMAT(e.fecha_envio,'%Y-%m') AS mes,
           DATE_FORMAT(e.fecha_envio,'%b') AS mes_label,
           COUNT(*) AS total
    FROM envios e
    JOIN clientes cl ON cl.id=e.cliente_id
    LEFT JOIN agencias_envio ag ON ag.id=e.agencia_envio_id
    WHERE e.deleted_at IS NULL AND $where_rol
      AND e.fecha_envio >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY mes, mes_label
    ORDER BY mes");
$st->execute($params_rol);
$por_mes    = $st->fetchAll(PDO::FETCH_ASSOC);
$max_mes    = max(array_column($por_mes, 'total') ?: [1]);
$mes_actual_key = date('Y-m');
$meses_es = ['Jan'=>'Ene','Feb'=>'Feb','Mar'=>'Mar','Apr'=>'Abr','May'=>'May','Jun'=>'Jun',
             'Jul'=>'Jul','Aug'=>'Ago','Sep'=>'Sep','Oct'=>'Oct','Nov'=>'Nov','Dec'=>'Dic'];

// ── Envíos estancados (tabla) ─────────────────────────────────────────────────
$st = $pdo->prepare("
    SELECT e.id, cl.razon_social, es.nombre AS estado_nombre, es.color_hex,
           ag.nombre AS agencia, e.destinatario, e.fecha_envio,
           DATEDIFF(NOW(), e.updated_at) AS dias_sin_cambio
    FROM envios e
    JOIN clientes cl ON cl.id=e.cliente_id
    LEFT JOIN agencias_envio ag ON ag.id=e.agencia_envio_id
    JOIN estados_envio es ON es.id=e.estado_envio_id
    WHERE e.deleted_at IS NULL AND $where_rol
      AND e.estado_envio_id NOT IN (5,6)
      AND DATEDIFF(NOW(), e.updated_at) > 7
    ORDER BY dias_sin_cambio DESC
    LIMIT 15");
$st->execute($params_rol);
$estancados = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Envíos recientes ──────────────────────────────────────────────────────────
$st = $pdo->prepare("
    SELECT e.id, cl.razon_social, es.nombre AS estado_nombre, es.color_hex,
           ag.nombre AS agencia, e.destinatario, e.fecha_envio
    FROM envios e
    JOIN clientes cl ON cl.id=e.cliente_id
    LEFT JOIN agencias_envio ag ON ag.id=e.agencia_envio_id
    JOIN estados_envio es ON es.id=e.estado_envio_id
    WHERE e.deleted_at IS NULL AND $where_rol
    ORDER BY e.id DESC
    LIMIT 8");
$st->execute($params_rol);
$recientes = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title    = 'Dashboard Envíos';
$page_subtitle = 'Métricas y seguimiento de envíos';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  /* ── KPI cards ── */
  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px; margin-bottom: 24px;
  }
  .stat-card {
    background: var(--gris-card); border-radius: 12px;
    border: 1px solid var(--gris-borde);
    padding: 18px 20px; position: relative; overflow: hidden;
    transition: box-shadow .2s, transform .2s;
  }
  .stat-card::before {
    content: ''; position: absolute;
    top: 0; left: 0; right: 0; height: 3px;
    background: var(--card-color, var(--azul));
  }
  .stat-card:hover { box-shadow: 0 6px 24px rgba(26,58,143,.1); transform: translateY(-2px); }
  .stat-icon {
    width: 38px; height: 38px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    background: var(--card-bg, var(--s-info-bg)); color: var(--card-color, var(--azul));
    margin-bottom: 10px;
  }
  .stat-value { font-family:'Inter',sans-serif; font-size: 30px; font-weight: 700; color: var(--gris-dark); line-height: 1; }
  .stat-label { font-size: 12px; color: var(--gris-texto); margin-top: 4px; font-weight: 500; }
  .stat-sub   { font-size: 11px; margin-top: 5px; color: var(--gris-texto); }
  .delta-up   { color: #10b981; font-weight: 700; }
  .delta-down { color: #ef4444; font-weight: 700; }

  /* ── Panels ── */
  .panels-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px; margin-bottom: 20px;
  }
  @media(max-width:768px){ .panels-grid { grid-template-columns: 1fr; } }
  .panel-full { grid-column: 1 / -1; }
  .panel {
    background: var(--gris-card); border-radius: 12px;
    border: 1px solid var(--gris-borde); overflow: hidden;
  }
  .panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; border-bottom: 1px solid var(--gris-borde);
  }
  .panel-title {
    font-family:'Inter',sans-serif; font-size: 13.5px;
    font-weight: 600; color: var(--gris-dark);
    display: flex; align-items: center; gap: 7px;
  }
  .panel-badge {
    font-size: 11px; font-weight: 600; padding: 2px 8px;
    border-radius: 20px; background: var(--gris-bg); color: var(--gris-texto);
  }
  .panel-link { font-size: 12px; color: var(--gris-texto); text-decoration: none; font-weight: 500; }
  .panel-link:hover { text-decoration: underline; color: var(--azul); }

  /* ── Bar charts ── */
  .chart-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 18px; border-bottom: 1px solid var(--gris-borde);
  }
  .chart-row:last-child { border-bottom: none; }
  .chart-label { font-size: 12px; font-weight: 600; color: var(--gris-texto); width: 110px; flex-shrink: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .chart-bar-wrap { flex: 1; height: 16px; background: var(--gris-bg); border-radius: 5px; overflow: hidden; }
  .chart-bar { height: 100%; border-radius: 5px; transition: width .7s cubic-bezier(.4,0,.2,1); min-width: 4px; }
  .chart-qty { font-size: 12px; font-weight: 700; color: var(--gris-dark); width: 28px; text-align: right; flex-shrink: 0; }

  /* ── Mes chart ── */
  .mes-chart { padding: 6px 0 0; }
  .mes-row {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 18px; border-bottom: 1px solid var(--gris-borde);
  }
  .mes-row:last-child { border-bottom: none; }
  .mes-label { font-size: 11px; font-weight: 700; color: var(--gris-texto); width: 30px; flex-shrink: 0; text-transform: uppercase; }
  .mes-label.current { color: var(--azul); }
  .mes-bar-wrap { flex: 1; height: 18px; background: var(--gris-bg); border-radius: 6px; overflow: hidden; }
  .mes-bar { height: 100%; background: var(--azul); border-radius: 6px; transition: width .7s cubic-bezier(.4,0,.2,1); min-width: 4px; }
  .mes-bar.current { background: #10b981; }
  .mes-qty { font-size: 12px; font-weight: 700; color: var(--gris-dark); width: 24px; text-align: right; flex-shrink: 0; }

  /* ── Tabla estancados ── */
  .tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
  .tbl th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 600; color: var(--gris-texto); text-transform: uppercase; letter-spacing: .03em; border-bottom: 1px solid var(--gris-borde); }
  .tbl td { padding: 10px 14px; border-bottom: 1px solid var(--gris-borde); vertical-align: middle; }
  .tbl tr:last-child td { border-bottom: none; }
  .tbl tr:hover td { background: var(--gris-bg); }
  .dias-badge {
    display: inline-block; padding: 2px 9px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
  }
  .dias-badge.warn  { background:var(--s-warn-bg); color:var(--s-warn-tx); }
  .dias-badge.alert { background:var(--s-err-bg); color:var(--s-err-tx); }
  .estado-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
  .empty-row td { text-align: center; color: var(--gris-texto); padding: 24px; font-size: 13px; }
</style>

<div class="section-header">
  <div>
    <h1 class="section-title">Dashboard Envíos</h1>
    <p class="section-subtitle">Métricas generales · Actualizado ahora</p>
  </div>
  <a href="<?= APP_URL ?>/modules/envios/index.php" class="btn btn-outline btn-sm">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"/></svg>
    Volver a listado
  </a>
</div>

<!-- ── KPI Cards ── -->
<div class="kpi-grid">
  <!-- Total -->
  <div class="stat-card" style="--card-color:#6366F1;--card-bg:var(--s-info-bg);">
    <div class="stat-icon">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
    </div>
    <div class="stat-value"><?= $total ?></div>
    <div class="stat-label">Total envíos</div>
    <div class="stat-sub">
      <?php if ($delta_mes !== null): ?>
        <span class="<?= $delta_mes >= 0 ? 'delta-up' : 'delta-down' ?>">
          <?= $delta_mes >= 0 ? '↑' : '↓' ?> <?= abs($delta_mes) ?>%
        </span> vs mes anterior
      <?php else: ?>
        <?= $mes_actual_count ?> este mes
      <?php endif; ?>
    </div>
  </div>

  <!-- En proceso -->
  <div class="stat-card" style="--card-color:#F59E0B;--card-bg:var(--s-warn-bg);">
    <div class="stat-icon">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="stat-value"><?= $en_proceso ?></div>
    <div class="stat-label">En proceso</div>
    <div class="stat-sub">
      <?php if ($estancados_count > 0): ?>
        <span class="delta-down">⚠ <?= $estancados_count ?> estancado<?= $estancados_count > 1 ? 's' : '' ?></span>
      <?php else: ?>
        Sin envíos estancados
      <?php endif; ?>
    </div>
  </div>

  <!-- Entregados -->
  <div class="stat-card" style="--card-color:#10B981;--card-bg:var(--s-ok-bg);">
    <div class="stat-icon">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div class="stat-value"><?= $entregados ?></div>
    <div class="stat-label">Entregados</div>
    <div class="stat-sub"><?= $tasa_entrega ?>% tasa de entrega</div>
  </div>

  <!-- Tiempo promedio -->
  <div class="stat-card" style="--card-color:#3B82F6;--card-bg:#DBEAFE;">
    <div class="stat-icon">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="stat-value"><?= $prom_dias ?></div>
    <div class="stat-label">Días prom. entrega</div>
    <div class="stat-sub">Basado en <?= $entregados ?> entrega<?= $entregados !== 1 ? 's' : '' ?></div>
  </div>

  <!-- Devueltos -->
  <?php if ($devueltos > 0): ?>
  <div class="stat-card" style="--card-color:#EF4444;--card-bg:var(--s-err-bg);">
    <div class="stat-icon">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
    </div>
    <div class="stat-value"><?= $devueltos ?></div>
    <div class="stat-label">Devueltos</div>
    <div class="stat-sub"><?= $total > 0 ? round($devueltos/$total*100,1) : 0 ?>% del total</div>
  </div>
  <?php endif; ?>

  <!-- Este mes -->
  <div class="stat-card" style="--card-color:#8B5CF6;--card-bg:#EDE9FE;">
    <div class="stat-icon">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
    <div class="stat-value"><?= $mes_actual_count ?></div>
    <div class="stat-label">Envíos este mes</div>
    <div class="stat-sub">
      <?php if ($delta_mes !== null): ?>
        <span class="<?= $delta_mes >= 0 ? 'delta-up' : 'delta-down' ?>">
          <?= $delta_mes >= 0 ? '↑' : '↓' ?> <?= abs($delta_mes) ?>%
        </span> vs <?= $mes_anterior_count ?> el mes pasado
      <?php else: ?>
        Primer mes registrado
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Panel grid: estado + agencia + mes ── -->
<div class="panels-grid">

  <!-- Por estado -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Por estado
      </div>
      <span class="panel-badge"><?= count($por_estado) ?> estados</span>
    </div>
    <?php foreach ($por_estado as $row):
      $pct = $max_estado > 0 ? round($row['total'] / $max_estado * 100) : 0;
    ?>
    <div class="chart-row">
      <div class="chart-label">
        <span class="estado-dot" style="background:<?= sanitize($row['color_hex']) ?>"></span>
        <?= sanitize($row['nombre']) ?>
      </div>
      <div class="chart-bar-wrap">
        <div class="chart-bar" style="width:<?= $pct ?>%;background:<?= sanitize($row['color_hex']) ?>"></div>
      </div>
      <div class="chart-qty"><?= $row['total'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Por agencia -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11a2 2 0 012 2v3"/><rect x="9" y="11" width="14" height="10" rx="2"/><line x1="13" y1="16" x2="13" y2="16"/></svg>
        Por agencia
      </div>
      <span class="panel-badge"><?= count($por_agencia) ?> agencias</span>
    </div>
    <?php
    $ag_colors = ['#10B981','#3B82F6','#F59E0B','#8B5CF6','#EF4444','#06B6D4','#64748B'];
    foreach ($por_agencia as $i => $row):
      $pct = $max_agencia > 0 ? round($row['total'] / $max_agencia * 100) : 0;
      $color = $ag_colors[$i % count($ag_colors)];
    ?>
    <div class="chart-row">
      <div class="chart-label"><?= sanitize($row['nombre']) ?></div>
      <div class="chart-bar-wrap">
        <div class="chart-bar" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
      </div>
      <div class="chart-qty"><?= $row['total'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Envíos por mes -->
  <div class="panel panel-full">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Evolución mensual
      </div>
      <span class="panel-badge">Últimos 6 meses</span>
    </div>
    <div class="mes-chart">
      <?php foreach ($por_mes as $row):
        $pct = $max_mes > 0 ? round($row['total'] / $max_mes * 100) : 0;
        $es_actual = $row['mes'] === $mes_actual_key;
        $label = $meses_es[$row['mes_label']] ?? $row['mes_label'];
      ?>
      <div class="mes-row">
        <div class="mes-label <?= $es_actual ? 'current' : '' ?>"><?= $label ?></div>
        <div class="mes-bar-wrap">
          <div class="mes-bar <?= $es_actual ? 'current' : '' ?>" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="mes-qty"><?= $row['total'] ?></div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($por_mes)): ?>
        <div style="padding:20px;text-align:center;color:var(--gris-texto);font-size:13px;">Sin datos de los últimos 6 meses.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Envíos estancados -->
  <?php if (!empty($estancados)): ?>
  <div class="panel panel-full">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:#F59E0B"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Envíos estancados
        <span style="font-size:11px;font-weight:400;color:var(--gris-texto)">(activos sin cambio de estado > 7 días)</span>
      </div>
      <a href="<?= APP_URL ?>/modules/envios/index.php" class="panel-link">Ver todos →</a>
    </div>
    <table class="tbl">
      <thead>
        <tr>
          <th scope="col">#</th>
          <th scope="col">Cliente</th>
          <th scope="col">Destinatario</th>
          <th scope="col">Agencia</th>
          <th scope="col">Estado</th>
          <th scope="col">Fecha envío</th>
          <th scope="col">Sin avance</th>
          <th scope="col"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($estancados as $e): ?>
        <tr>
          <td style="font-size:11px;color:var(--gris-texto);font-weight:600;">#<?= $e['id'] ?></td>
          <td style="font-weight:500;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($e['razon_social']) ?></td>
          <td style="color:var(--gris-texto)"><?= sanitize($e['destinatario'] ?? '—') ?></td>
          <td style="color:var(--gris-texto)"><?= sanitize($e['agencia'] ?? '—') ?></td>
          <td>
            <span class="estado-dot" style="background:<?= sanitize($e['color_hex']) ?>"></span>
            <?= sanitize($e['estado_nombre']) ?>
          </td>
          <td style="color:var(--gris-texto);font-size:12px;"><?= $e['fecha_envio'] ? formato_fecha($e['fecha_envio']) : '—' ?></td>
          <td>
            <span class="dias-badge <?= (int)$e['dias_sin_cambio'] >= 20 ? 'alert' : 'warn' ?>">
              <?= $e['dias_sin_cambio'] ?> días
            </span>
          </td>
          <td>
            <a href="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>" class="btn-ic btn-ic-blue" title="Ver">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Envíos recientes -->
  <div class="panel panel-full">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Envíos recientes
      </div>
      <a href="<?= APP_URL ?>/modules/envios/index.php" class="panel-link">Ver todos →</a>
    </div>
    <table class="tbl">
      <thead>
        <tr><th scope="col">#</th><th scope="col">Cliente</th><th scope="col">Destinatario</th><th scope="col">Agencia</th><th scope="col">Estado</th><th scope="col">Fecha envío</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recientes as $e): ?>
        <tr onclick="window.location='<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>'" style="cursor:pointer;">
          <td style="font-size:11px;color:var(--gris-texto);font-weight:600;">#<?= $e['id'] ?></td>
          <td style="font-weight:500;"><?= sanitize($e['razon_social']) ?></td>
          <td style="color:var(--gris-texto)"><?= sanitize($e['destinatario'] ?? '—') ?></td>
          <td style="color:var(--gris-texto)"><?= sanitize($e['agencia'] ?? '—') ?></td>
          <td>
            <span class="env-estado-chip" style="--ec:<?= sanitize($e['color_hex']) ?>">
              <?= sanitize($e['estado_nombre']) ?>
            </span>
          </td>
          <td style="color:var(--gris-texto);font-size:12px;"><?= $e['fecha_envio'] ? formato_fecha($e['fecha_envio']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recientes)): ?>
        <tr class="empty-row"><td colspan="6">No hay envíos registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
