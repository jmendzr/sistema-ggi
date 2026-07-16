<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general');
$pdo = Database::get();

// Ensure tables exist (no FK to avoid errors if referenced tables don't exist yet)
$pdo->exec("CREATE TABLE IF NOT EXISTS bonos_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    norma_iso_id INT NULL,
    monto_bono DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS bonos_ejecutivos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ejecutivo_id INT NOT NULL,
    cotizacion_id INT NOT NULL,
    norma_iso_id INT NOT NULL,
    monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
    fecha_pago DATE NULL,
    pagado_por INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Seed default bono row if missing
$default_exists = $pdo->query("SELECT COUNT(*) FROM bonos_config WHERE norma_iso_id IS NULL")->fetchColumn();
if (!$default_exists) {
    $pdo->exec("INSERT INTO bonos_config (norma_iso_id, monto_bono) VALUES (NULL, 150.00)");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? 'bonos';

    if ($accion === 'salarios') {
        foreach ($_POST['salario'] ?? [] as $uid => $val) {
            $uid = (int)$uid;
            $sal = max(0, (float)$val);
            if ($uid > 0) {
                $pdo->prepare("UPDATE usuarios SET salario_mensual=? WHERE id=? AND rol='ejecutivo_ventas'")
                    ->execute([$sal, $uid]);
            }
        }
        log_actividad('bonos', 'config', 'Salarios de ejecutivos actualizados');
        header('Location: ' . APP_URL . '/modules/bonos/config.php?ok=salarios#salarios');
        exit;
    }

    $montos = $_POST['monto'] ?? [];
    foreach ($montos as $key => $val) {
        $monto = max(0, (float)$val);
        if ($key === 'default') {
            // OJO: norma_iso_id NULL NO dispara ON DUPLICATE KEY (MySQL trata cada NULL como
            // distinto), lo que duplicaba la fila default en cada guardado. Se hace UPDATE
            // explícito, o INSERT solo si aún no existe.
            $existe_def = (int)$pdo->query("SELECT COUNT(*) FROM bonos_config WHERE norma_iso_id IS NULL")->fetchColumn();
            if ($existe_def > 0) {
                $pdo->prepare("UPDATE bonos_config SET monto_bono=?, updated_at=NOW() WHERE norma_iso_id IS NULL")
                    ->execute([$monto]);
            } else {
                $pdo->prepare("INSERT INTO bonos_config (norma_iso_id, monto_bono) VALUES (NULL, ?)")
                    ->execute([$monto]);
            }
        } else {
            $nid = (int)$key;
            if ($nid <= 0) continue;
            $pdo->prepare("INSERT INTO bonos_config (norma_iso_id, monto_bono)
                           VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE monto_bono=?, updated_at=NOW()")
                ->execute([$nid, $monto, $monto]);
        }
    }
    log_actividad('bonos', 'config', 'Configuración de bonos actualizada');
    header('Location: ' . APP_URL . '/modules/bonos/config.php?ok=1');
    exit;
}

$normas = $pdo->query("
    SELECT n.id, n.codigo, n.nombre,
           COALESCE(bc.monto_bono, '') AS monto_config,
           bc.id AS config_id
    FROM normas_iso n
    LEFT JOIN bonos_config bc ON bc.norma_iso_id = n.id
    WHERE n.activo = 1
    ORDER BY n.codigo
")->fetchAll();

$ejecutivos = $pdo->query("
    SELECT id, nombre, apellido, salario_mensual
    FROM usuarios
    WHERE rol = 'ejecutivo_ventas' AND activo = 1
    ORDER BY nombre, apellido
")->fetchAll();

$default_bono = $pdo->query("SELECT monto_bono FROM bonos_config WHERE norma_iso_id IS NULL LIMIT 1")->fetchColumn();
if ($default_bono === false) $default_bono = 150.00;

$page_title  = 'Configuración de Bonos';
$active_menu = 'bonos';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .bonos-config-wrap { max-width: 760px; }
  .page-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
  .page-header h1 { font-family:'Inter',sans-serif; font-size:22px; font-weight:700; color:var(--gris-dark); margin-bottom:2px; }
  .page-header p { font-size:13px; color:var(--gris-texto); }
  .card { background:var(--gris-card); border-radius:14px; border:1px solid var(--gris-borde); overflow:hidden; }
  .card-header { padding:18px 22px 16px; border-bottom:1px solid var(--gris-borde); display:flex; align-items:center; gap:12px; }
  .card-header-icon { width:36px; height:36px; border-radius:9px; background:var(--azul-light); color:var(--azul); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .card-header-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:700; color:var(--azul); }
  .card-header-sub { font-size:12px; color:var(--gris-texto); margin-top:1px; }
  .card-body { padding:22px; }

  /* Default bono row */
  .default-row { display:flex; align-items:center; justify-content:space-between; gap:16px;
                 padding:14px 16px; background:var(--azul-light); border-radius:10px;
                 border:1.5px solid var(--gris-borde); margin-bottom:22px; flex-wrap:wrap; }
  .default-row-label { display:flex; flex-direction:column; gap:2px; }
  .default-row-label strong { font-size:13px; font-weight:700; color:var(--gris-dark); }
  .default-row-label span { font-size:12px; color:var(--gris-texto); }
  .default-row-input { display:flex; align-items:center; gap:8px; }
  .currency-prefix { font-size:13px; font-weight:700; color:var(--gris-texto); }

  /* Table */
  .config-table-wrap { border:1px solid var(--gris-borde); border-radius:10px; overflow:hidden; }
  .config-table { width:100%; border-collapse:collapse; }
  .config-table th { font-size:11px; font-weight:700; color:var(--gris-texto); text-transform:uppercase;
                     letter-spacing:0.5px; padding:10px 14px; background:var(--gris-bg);
                     border-bottom:1px solid var(--gris-borde); text-align:left; white-space:nowrap; }
  .config-table td { padding:10px 14px; font-size:13px; border-bottom:1px solid var(--gris-borde); vertical-align:middle; }
  .config-table tr:last-child td { border-bottom:none; }
  .config-table tr:hover td { background:var(--gris-bg); }
  .norma-code { display:inline-block; background:var(--azul-light); color:var(--azul);
                font-size:11px; font-weight:700; padding:2px 8px; border-radius:6px; white-space:nowrap; }
  .bono-input { width:110px; padding:7px 10px; border:1.5px solid var(--gris-borde); border-radius:8px;
                font-size:13px; font-family:'Inter',sans-serif; color:var(--gris-dark);
                background:var(--gris-card); outline:none; transition:border-color 0.2s, box-shadow 0.2s; }
  .bono-input:focus { border-color:var(--azul); box-shadow:0 0 0 3px rgba(26,58,143,0.09); }
  .bono-input.default-input { width:120px; border-color:var(--azul); background:var(--bg-surface); }
  .bono-input.default-input:focus { box-shadow:0 0 0 3px rgba(26,58,143,0.14); }

  /* Hint text */
  .hint-empty { font-size:11px; color:var(--gris-texto); margin-left:6px; }

  /* Actions */
  .form-actions { padding:16px 22px; border-top:1px solid var(--gris-borde); display:flex; align-items:center; gap:10px; justify-content:flex-end; background:var(--gris-bg); }

  /* .btn / .btn-primary / .btn-outline → globales (layout.php / design-system.css) */

  /* Alert */
  .alert { padding:12px 16px; border-radius:9px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; margin-bottom:18px; }
  .alert-ok { background:var(--s-ok-bg); border:1px solid #b7e4c0; color:var(--s-ok-tx); }

  /* Dark mode extras */
  :root[data-tema="oscuro"] .default-row { background:#2a2e48 !important; border-color:#3c4060 !important; }
  :root[data-tema="oscuro"] .default-row-label strong { color:#dde1ef !important; }
  :root[data-tema="oscuro"] .bono-input { background:#2e3145 !important; border-color:#3c4060 !important; color:#dde1ef !important; }
  :root[data-tema="oscuro"] .bono-input.default-input { background:#252838 !important; border-color:#6aabff !important; }
  :root[data-tema="oscuro"] .bono-input:focus { border-color:#6aabff !important; box-shadow:0 0 0 3px rgba(106,171,255,0.14) !important; }
  :root[data-tema="oscuro"] .config-table th { background:#1e2235 !important; }
  :root[data-tema="oscuro"] .config-table tr:hover td { background:#2e3145 !important; }
  :root[data-tema="oscuro"] .form-actions { background:#1c1f2e !important; border-color:#3c4060 !important; }
  :root[data-tema="oscuro"] .alert-ok { background:#1e3028 !important; border-color:#2e5e3a !important; color:#4cc267 !important; }
  :root[data-tema="oscuro"] .norma-code { background:#1c2a50 !important; color:#6aabff !important; }
  :root[data-tema="oscuro"] .card-header-icon { background:#2a2e48 !important; }
</style>

<div class="bonos-config-wrap">

  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1>Configuración de Bonos</h1>
      <p>Define el monto de bono por norma ISO vendida</p>
    </div>
    <a href="<?= APP_URL ?>/modules/bonos/index.php" class="btn btn-outline">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      Ver bonos
    </a>
  </div>

  <?php if (isset($_GET['ok'])): ?>
  <script>
  ggiReady(function() {
    showToast(<?= json_encode($_GET['ok'] === 'salarios' ? 'Salarios actualizados correctamente.' : 'Configuración de bonos guardada correctamente.') ?>, 'ok');
    history.replaceState({}, '', window.location.pathname);
  });
  </script>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <div class="card-header-icon">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M12 2v2M12 20v2M2 12h2M20 12h2M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41"/></svg>
      </div>
      <div>
        <div class="card-header-title">Montos de bonos por norma ISO</div>
        <div class="card-header-sub">Si una norma no tiene monto propio, se aplica el bono por defecto</div>
      </div>
    </div>

    <form method="POST">
      <?= csrf_field() ?>

      <div class="card-body">

        <!-- Default bono -->
        <div class="default-row">
          <div class="default-row-label">
            <strong>Bono por defecto (todas las normas)</strong>
            <span>Se aplica cuando una norma no tiene configuración específica</span>
          </div>
          <div class="default-row-input">
            <span class="currency-prefix">S/</span>
            <input
              type="number"
              name="monto[default]"
              value="<?= htmlspecialchars(number_format((float)$default_bono, 2, '.', '')) ?>"
              min="0"
              step="0.01"
              class="bono-input default-input"
              placeholder="0.00"
              required
            >
          </div>
        </div>

        <!-- Per-norma table -->
        <?php if ($normas): ?>
        <div class="config-table-wrap">
          <table class="config-table">
            <thead>
              <tr>
                <th scope="col">Norma</th>
                <th scope="col">Nombre</th>
                <th scope="col" style="text-align:right;">Bono (S/)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($normas as $norma): ?>
              <tr>
                <td>
                  <span class="norma-code"><?= sanitize($norma['codigo']) ?></span>
                </td>
                <td style="color:var(--gris-dark);"><?= sanitize($norma['nombre']) ?></td>
                <td style="text-align:right;">
                  <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;">
                    <span class="currency-prefix">S/</span>
                    <input
                      type="number"
                      name="monto[<?= (int)$norma['id'] ?>]"
                      value="<?= $norma['monto_config'] !== '' ? htmlspecialchars(number_format((float)$norma['monto_config'], 2, '.', '')) : '' ?>"
                      min="0"
                      step="0.01"
                      class="bono-input"
                      placeholder="Usar defecto"
                    >
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:32px;text-align:center;color:var(--gris-texto);">
          <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="margin-bottom:8px;opacity:0.4;"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          <p>No hay normas ISO activas registradas.</p>
        </div>
        <?php endif; ?>

      </div><!-- /card-body -->

      <div class="form-actions">
        <a href="<?= APP_URL ?>/modules/bonos/index.php" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Guardar configuración
        </button>
      </div>

    </form>
  </div><!-- /card -->

  <!-- ─── Salarios por ejecutivo ─── -->
  <div id="salarios" style="margin-top:28px;">
    <div class="card">
      <div class="card-header">
        <div class="card-header-icon">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        </div>
        <div>
          <div class="card-header-title">Salario mensual por ejecutivo</div>
          <div class="card-header-sub">Solo visible para gerentes — el ejecutivo no puede ver su propio salario</div>
        </div>
      </div>

      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="salarios">
        <div class="card-body">
          <?php if ($ejecutivos): ?>
          <div class="config-table-wrap">
            <table class="config-table">
              <thead>
                <tr>
                  <th scope="col">Ejecutivo</th>
                  <th scope="col" style="text-align:right;">Salario mensual (S/)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ejecutivos as $ej): ?>
                <tr>
                  <td style="font-weight:600;color:var(--gris-dark);">
                    <?= sanitize($ej['nombre'] . ' ' . $ej['apellido']) ?>
                  </td>
                  <td style="text-align:right;">
                    <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;">
                      <span class="currency-prefix">S/</span>
                      <input type="number"
                             name="salario[<?= (int)$ej['id'] ?>]"
                             value="<?= number_format((float)$ej['salario_mensual'], 2, '.', '') ?>"
                             min="0" step="0.01" class="bono-input" placeholder="0.00">
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p style="color:var(--gris-texto);font-size:13px;">No hay ejecutivos de ventas activos registrados.</p>
          <?php endif; ?>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Guardar salarios
          </button>
        </div>
      </form>
    </div>
  </div>

</div><!-- /bonos-config-wrap -->

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
