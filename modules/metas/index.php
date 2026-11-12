<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas','gerente_comercial');

$pdo = Database::get();

// ── Task 1: Create table if not exists ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS metas_ejecutivos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ejecutivo_id INT NOT NULL,
        mes TINYINT NOT NULL,
        año SMALLINT NOT NULL,
        meta_monto DECIMAL(12,2) NOT NULL DEFAULT 0,
        meta_cotizaciones INT NOT NULL DEFAULT 0,
        creado_por INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ejec_mes_año (ejecutivo_id, mes, año)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* tabla ya existe o error no crítico */ }

$es_gerente = in_array($user['rol'], ['gerente_general', 'gerente_operaciones']);

// ── Month/year selector ──
$mes  = (int)($_GET['mes']  ?? date('n'));
$año  = (int)($_GET['año']  ?? date('Y'));
if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($año < 2020 || $año > 2040) $año = (int)date('Y');

// ── POST handler (gerentes only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $es_gerente) {
    csrf_verify();
    $post_mes = (int)($_POST['mes'] ?? $mes);
    $post_año = (int)($_POST['año'] ?? $año);
    $metas_monto = $_POST['meta_monto']        ?? [];
    $metas_cots  = $_POST['meta_cotizaciones'] ?? [];

    foreach ($metas_monto as $ejec_id => $monto) {
        $ejec_id = (int)$ejec_id;
        if ($ejec_id <= 0) continue;
        $monto_val = max(0, (float)str_replace(',', '', $monto));
        $cots_val  = max(0, (int)($metas_cots[$ejec_id] ?? 0));
        try {
            $pdo->prepare("
                INSERT INTO metas_ejecutivos (ejecutivo_id, mes, año, meta_monto, meta_cotizaciones, creado_por)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    meta_monto        = VALUES(meta_monto),
                    meta_cotizaciones = VALUES(meta_cotizaciones),
                    creado_por        = VALUES(creado_por)
            ")->execute([$ejec_id, $post_mes, $post_año, $monto_val, $cots_val, $user['id']]);
        } catch (Exception $e) { /* continue on error */ }
    }
    header('Location: ' . APP_URL . '/modules/metas/index.php?mes=' . $post_mes . '&año=' . $post_año . '&ok=1');
    exit;
}

// ── Load ejecutivos ──
if ($es_gerente) {
    $ejecutivos = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='ejecutivo_ventas' ORDER BY nombre")->fetchAll();
} else {
    $ejecutivos = [['id' => $user['id'], 'nombre' => $user['nombre'], 'apellido' => $user['apellido']]];
}

// ── Load metas and real performance per ejecutivo ──
$datos = [];
foreach ($ejecutivos as $ej) {
    $eid = (int)$ej['id'];

    // Meta
    $stmt = $pdo->prepare("SELECT meta_monto, meta_cotizaciones FROM metas_ejecutivos WHERE ejecutivo_id=? AND mes=? AND año=?");
    $stmt->execute([$eid, $mes, $año]);
    $meta = $stmt->fetch() ?: ['meta_monto' => 0, 'meta_cotizaciones' => 0];

    // Real monto (propuestas aceptadas)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto_total),0) FROM cotizaciones WHERE ejecutivo_id=? AND estado='aceptada' AND deleted_at IS NULL AND MONTH(created_at)=? AND YEAR(created_at)=?");
    $stmt->execute([$eid, $mes, $año]);
    $real_monto = (float)$stmt->fetchColumn();

    // Real propuestas (enviadas + aceptadas)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE ejecutivo_id=? AND estado IN('enviada','aceptada') AND deleted_at IS NULL AND MONTH(created_at)=? AND YEAR(created_at)=?");
    $stmt->execute([$eid, $mes, $año]);
    $real_cots = (int)$stmt->fetchColumn();

    // Progress percentages
    $pct_monto = (float)$meta['meta_monto'] > 0 ? min(100, round($real_monto / (float)$meta['meta_monto'] * 100)) : 0;
    $pct_cots  = (int)$meta['meta_cotizaciones'] > 0 ? min(100, round($real_cots / (int)$meta['meta_cotizaciones'] * 100)) : 0;

    $datos[] = [
        'id'                => $eid,
        'nombre'            => $ej['nombre'] . ' ' . $ej['apellido'],
        'meta_monto'        => (float)$meta['meta_monto'],
        'meta_cotizaciones' => (int)$meta['meta_cotizaciones'],
        'real_monto'        => $real_monto,
        'real_cots'         => $real_cots,
        'pct_monto'         => $pct_monto,
        'pct_cots'          => $pct_cots,
    ];
}

// ── Month names ──
$meses_nombres = [
    1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
    7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
];

$page_title  = $es_gerente ? 'Metas del equipo' : 'Mi meta';
$active_menu = 'metas';
require_once __DIR__ . '/../../templates/layout.php';
?>

<style>
  .metas-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 18px;
    margin-bottom: 24px;
  }
  .meta-card {
    background: var(--gris-card);
    border: 1px solid var(--gris-borde);
    border-radius: 12px;
    padding: 20px 22px;
  }
  .meta-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
  }
  .meta-card-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--gris-dark);
  }
  .meta-row {
    margin-bottom: 14px;
  }
  .meta-row:last-child { margin-bottom: 0; }
  .meta-row-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
  }
  .meta-row-label span:first-child {
    font-size: 12px;
    color: var(--gris-texto);
  }
  .meta-row-label span:last-child {
    font-size: 12px;
    font-weight: 700;
  }
  .progress-track {
    height: 8px;
    background: var(--gris-borde);
    border-radius: 100px;
    overflow: hidden;
  }
  .progress-fill {
    height: 100%;
    border-radius: 100px;
    transition: width 0.6s ease;
  }
  .meta-sub {
    font-size: 11px;
    color: var(--gris-texto);
    margin-top: 4px;
  }
  .meta-input-row {
    display: flex;
    gap: 10px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--gris-borde);
  }
  .meta-input-group {
    flex: 1;
  }
  .meta-input-group label {
    display: block;
    font-size: 11px;
    color: var(--gris-texto);
    margin-bottom: 4px;
    font-weight: 600;
  }
  .meta-input-group input {
    width: 100%;
    padding: 7px 10px;
    border: 1px solid var(--gris-borde);
    border-radius: 8px;
    font-size: 13px;
    color: var(--gris-dark);
    background: var(--gris-bg);
    transition: border-color 0.15s;
  }
  .meta-input-group input:focus {
    outline: none;
    border-color: var(--azul);
    background:var(--bg-surface);
  }
  .month-selector {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  /* Selector de período */
  .month-sel-wrap { display:inline-block; }
  .month-sel-wrap select {
    padding: 8px 12px;
    border: 1.5px solid var(--gris-borde);
    border-radius: 8px;
    font-size: 13px;
    color: var(--gris-dark);
    background: var(--gris-card);
    cursor: pointer;
    outline: none;
    font-family: 'Inter', sans-serif;
    transition: border-color 0.15s;
    min-width: 155px;
  }
  .month-sel-wrap select:focus { border-color: var(--azul); }
  .month-sel-wrap:last-of-type select { min-width: 90px; }
  /* .btn-primary → global (layout.php). El markup ahora usa class="btn btn-primary". */
  .btn-save-all {
    margin-top: 8px;
    margin-bottom: 24px;
  }
  /* Animación de aparición: utilidad global .ggi-stagger (design-system.css) */
  @media (max-width: 600px) {
    .metas-grid { grid-template-columns: 1fr; }
    .meta-input-row { flex-direction: column; gap: 8px; }
  }
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1><?= $es_gerente ? 'Metas del equipo' : 'Mi meta mensual' ?></h1>
    <p>Seguimiento de objetivos mensuales de ventas — <?= $meses_nombres[$mes] ?> <?= $año ?></p>
  </div>
</div>

<?php if (isset($_GET['ok'])): ?>
<script>
ggiReady(function() {
  showToast('Metas guardadas correctamente.', 'ok');
  history.replaceState({}, '', window.location.pathname);
});
</script>
<?php endif; ?>

<!-- Month/year selector -->
<form method="GET" action="" id="form-periodo" style="margin-bottom:22px;">
  <div class="month-selector">
    <label style="font-size:13px;font-weight:600;color:var(--gris-texto);">Período:</label>
    <div class="month-sel-wrap">
      <select name="mes" data-no-search onchange="document.getElementById('form-periodo').submit()">
        <?php foreach ($meses_nombres as $n => $nombre): ?>
        <option value="<?= $n ?>" <?= $n === $mes ? 'selected' : '' ?>><?= $nombre ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="month-sel-wrap">
      <select name="año" onchange="document.getElementById('form-periodo').submit()">
        <?php for ($y = (int)date('Y') - 2; $y <= (int)date('Y') + 1; $y++): ?>
        <option value="<?= $y ?>" <?= $y === $año ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>
</form>

<?php if ($es_gerente): ?>
<form method="POST" action="">
  <?= csrf_field() ?>
  <input type="hidden" name="mes" value="<?= $mes ?>">
  <input type="hidden" name="año" value="<?= $año ?>">
<?php endif; ?>

<?php if (empty($ejecutivos)): ?>
<div style="background:var(--gris-card);border:1px solid var(--gris-borde);border-radius:12px;padding:40px;text-align:center;color:var(--gris-texto);">
  No hay ejecutivos de ventas registrados.
</div>
<?php else: ?>

<div class="metas-grid ggi-stagger">
  <?php foreach ($datos as $i => $d):
    $c_m = $d['pct_monto'] >= 100 ? '#16a34a' : ($d['pct_monto'] >= 60 ? '#d97706' : '#dc2626');
    $c_c = $d['pct_cots'] >= 100  ? '#16a34a' : ($d['pct_cots'] >= 60  ? '#d97706' : '#dc2626');
  ?>
  <div class="meta-card">
    <div class="meta-card-header">
      <div class="meta-card-name"><?= sanitize($d['nombre']) ?></div>
      <?php if ($d['meta_monto'] > 0 || $d['meta_cotizaciones'] > 0): ?>
      <span style="font-size:11px;padding:3px 8px;border-radius:100px;background:var(--s-info-bg);color:#475569;font-weight:600;">
        <?= $meses_nombres[$mes] ?> <?= $año ?>
      </span>
      <?php endif; ?>
    </div>

    <!-- Monto progress -->
    <div class="meta-row">
      <div class="meta-row-label">
        <span>Monto aceptado</span>
        <?php if ($d['meta_monto'] > 0): ?>
        <span style="color:<?= $c_m ?>"><?= $d['pct_monto'] ?>%</span>
        <?php else: ?>
        <span style="color:var(--gris-texto);">Sin meta</span>
        <?php endif; ?>
      </div>
      <div class="progress-track">
        <div class="progress-fill" style="width:<?= $d['pct_monto'] ?>%;background:<?= $d['meta_monto'] > 0 ? $c_m : 'var(--gris-borde)' ?>;"></div>
      </div>
      <div class="meta-sub">
        S/ <?= number_format($d['real_monto'], 0) ?>
        <?php if ($d['meta_monto'] > 0): ?>
          / S/ <?= number_format($d['meta_monto'], 0) ?> meta
        <?php endif; ?>
      </div>
    </div>

    <!-- Propuestas progress -->
    <div class="meta-row">
      <div class="meta-row-label">
        <span>Cotizaciones activas</span>
        <?php if ($d['meta_cotizaciones'] > 0): ?>
        <span style="color:<?= $c_c ?>"><?= $d['pct_cots'] ?>%</span>
        <?php else: ?>
        <span style="color:var(--gris-texto);">Sin meta</span>
        <?php endif; ?>
      </div>
      <div class="progress-track">
        <div class="progress-fill" style="width:<?= $d['pct_cots'] ?>%;background:<?= $d['meta_cotizaciones'] > 0 ? $c_c : 'var(--gris-borde)' ?>;"></div>
      </div>
      <div class="meta-sub">
        <?= $d['real_cots'] ?> propuestas
        <?php if ($d['meta_cotizaciones'] > 0): ?>
          / <?= $d['meta_cotizaciones'] ?> meta
        <?php endif; ?>
      </div>
    </div>

    <?php if ($es_gerente): ?>
    <!-- Inline edit inputs for gerentes -->
    <div class="meta-input-row">
      <div class="meta-input-group">
        <label>Meta monto (S/)</label>
        <input type="number" name="meta_monto[<?= $d['id'] ?>]"
               value="<?= $d['meta_monto'] > 0 ? number_format($d['meta_monto'], 2, '.', '') : '' ?>"
               placeholder="0.00" min="0" step="0.01">
      </div>
      <div class="meta-input-group">
        <label>Meta propuestas</label>
        <input type="number" name="meta_cotizaciones[<?= $d['id'] ?>]"
               value="<?= $d['meta_cotizaciones'] > 0 ? $d['meta_cotizaciones'] : '' ?>"
               placeholder="0" min="0" step="1">
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($es_gerente): ?>
<div class="btn-save-all">
  <button type="submit" class="btn btn-primary" style="padding:11px 32px;font-size:14px;">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:6px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
    Guardar metas
  </button>
</div>

<?php endif; ?>
<?php endif; ?>

<?php if ($es_gerente): ?>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
