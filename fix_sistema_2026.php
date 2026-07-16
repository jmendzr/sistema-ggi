<?php
/**
 * fix_sistema_2026.php
 * Script de correcciones de base de datos — Sistema GGI
 * Requiere rol: gerente_general
 */

require_once __DIR__ . '/core/helpers.php';

session_init();
require_login();
require_role('gerente_general');

$pdo = Database::get();

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────
function stepResult(bool $ok, string $label, string $detail = ''): void
{
    $icon  = $ok ? '&#10003;' : '&#10007;';
    $color = $ok ? '#2d7a4f' : '#b91c1c';
    $bg    = $ok ? '#f0fdf4' : '#fef2f2';
    $border = $ok ? '#86efac' : '#fca5a5';
    echo "<div style=\"display:flex;align-items:flex-start;gap:10px;padding:10px 14px;margin:6px 0;background:{$bg};border:1px solid {$border};border-radius:6px;\">";
    echo "<span style=\"font-size:18px;color:{$color};line-height:1.4;\">{$icon}</span>";
    echo "<div><strong style=\"color:{$color};\">{$label}</strong>";
    if ($detail !== '') {
        echo "<br><span style=\"font-size:13px;color:#555;\">{$detail}</span>";
    }
    echo "</div></div>\n";
}

// ─────────────────────────────────────────────────────────────
// Cabecera HTML
// ─────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Correcciones BD 2026 — GGI Sistema</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
    margin: 0;
    padding: 30px 16px;
  }
  .card {
    max-width: 780px;
    margin: 0 auto;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 12px rgba(0,0,0,.10);
    padding: 32px 36px;
  }
  h1 {
    font-size: 1.4rem;
    margin: 0 0 4px;
    color: #0f172a;
  }
  .subtitle {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 28px;
  }
  .section-title {
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #475569;
    margin: 24px 0 8px;
    padding-bottom: 4px;
    border-bottom: 1px solid #e2e8f0;
  }
  .warn-box {
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: 6px;
    padding: 14px 18px;
    font-size: 14px;
    color: #92400e;
    margin-bottom: 24px;
  }
  .btn {
    display: inline-block;
    padding: 10px 24px;
    border: none;
    border-radius: 6px;
    font-size: 15px;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
    transition: opacity .15s;
  }
  .btn:hover { opacity: .88; }
  .btn-primary { background: #1d4ed8; color: #fff; }
  .btn-secondary { background: #e2e8f0; color: #334155; }
  .btn-back { background: #0f766e; color: #fff; }
  ul.ops {
    margin: 0 0 24px;
    padding-left: 20px;
    font-size: 14px;
    line-height: 1.8;
    color: #334155;
  }
  .footer-actions { margin-top: 28px; display: flex; gap: 12px; flex-wrap: wrap; }
  .error-global {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 6px;
    padding: 14px 18px;
    color: #991b1b;
    font-size: 14px;
    margin-top: 16px;
  }
</style>
</head>
<body>
<div class="card">
  <h1>Correcciones de Base de Datos — 2026</h1>
  <p class="subtitle">Sistema GGI &mdash; ejecutado por: <strong><?= htmlspecialchars(auth_user()['nombre'] ?? auth_user()['email'] ?? 'gerente') ?></strong></p>

<?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

  <!-- ══════════ FORMULARIO DE CONFIRMACIÓN ══════════ -->
  <div class="warn-box">
    <strong>Atenci&oacute;n:</strong> Esta operaci&oacute;n modificar&aacute; la base de datos de producci&oacute;n.
    Revisa los cambios antes de continuar.
  </div>

  <p class="section-title">Operaciones que se ejecutar&aacute;n</p>
  <ul class="ops">
    <li><strong>Paso 1</strong> &mdash; Agregar columna <code>orden</code> a <code>normas_iso</code> y poblar valores por c&oacute;digo.</li>
    <li><strong>Paso 2</strong> &mdash; Crear tabla <code>cotizacion_archivos</code> (si no existe).</li>
    <li><strong>Paso 3</strong> &mdash; Corregir montos y pagos de la cotizaci&oacute;n de <em>INVERSIONES DOMINGUEZ &amp; FALCON E.I.R.L.</em></li>
    <li><strong>Paso 4</strong> &mdash; Migrar registros <code>cotizacion_firmada</code> de <code>documentos_cliente</code> a <code>cotizacion_archivos</code>.</li>
  </ul>

  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bin2hex(random_bytes(16))) ?>">
    <div class="footer-actions">
      <button type="submit" class="btn btn-primary">Ejecutar correcciones</button>
      <a href="/modules/admin/" class="btn btn-secondary">Cancelar</a>
    </div>
  </form>

<?php else: ?>

  <!-- ══════════ EJECUCIÓN ══════════ -->
<?php
  $globalOk   = true;
  $results    = [];
  $redirectOk = true;

  try {
      $pdo->beginTransaction();

      // ──────────────────────────────────────────────
      // PASO 1 — Agregar columna `orden` a normas_iso
      // ──────────────────────────────────────────────
      echo '<p class="section-title">Paso 1 &mdash; Columna <code>orden</code> en <code>normas_iso</code></p>';
      flush();

      try {
          $pdo->exec("ALTER TABLE normas_iso ADD COLUMN IF NOT EXISTS orden TINYINT UNSIGNED DEFAULT 99");
          stepResult(true, 'ALTER TABLE normas_iso ADD COLUMN IF NOT EXISTS orden', 'Columna agregada o ya exist&iacute;a.');
      } catch (\Throwable $e) {
          stepResult(false, 'ALTER TABLE normas_iso', htmlspecialchars($e->getMessage()));
          $globalOk = false;
      }

      try {
          $sql = "UPDATE normas_iso SET orden = CASE
                    WHEN codigo LIKE '%9001%'  THEN 1
                    WHEN codigo LIKE '%14001%' THEN 2
                    WHEN codigo LIKE '%27001%' THEN 3
                    WHEN codigo LIKE '%37001%' THEN 4
                    WHEN codigo LIKE '%45001%' THEN 5
                    WHEN codigo LIKE '%22000%' THEN 6
                    WHEN codigo LIKE '%50001%' THEN 7
                    ELSE 99
                  END";
          $stmt = $pdo->query($sql);
          $rows = $stmt->rowCount();
          stepResult(true, 'UPDATE normas_iso SET orden = CASE ...', "Filas actualizadas: {$rows}");
      } catch (\Throwable $e) {
          stepResult(false, 'UPDATE normas_iso (orden)', htmlspecialchars($e->getMessage()));
          $globalOk = false;
      }

      // ──────────────────────────────────────────────
      // PASO 2 — Crear tabla cotizacion_archivos
      // ──────────────────────────────────────────────
      echo '<p class="section-title">Paso 2 &mdash; Tabla <code>cotizacion_archivos</code></p>';
      flush();

      try {
          $pdo->exec("CREATE TABLE IF NOT EXISTS cotizacion_archivos (
              id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              cotizacion_id   INT UNSIGNED NOT NULL,
              tipo            VARCHAR(50)  NOT NULL DEFAULT 'firmada',
              nombre_archivo  VARCHAR(255) NOT NULL,
              ruta_archivo    VARCHAR(500) NOT NULL,
              mime_type       VARCHAR(100),
              tamano_bytes    INT UNSIGNED,
              version         TINYINT UNSIGNED DEFAULT 1,
              subido_por      INT UNSIGNED,
              created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY idx_cot_tipo (cotizacion_id, tipo)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
          stepResult(true, 'CREATE TABLE IF NOT EXISTS cotizacion_archivos', 'Tabla creada o ya exist&iacute;a.');
      } catch (\Throwable $e) {
          stepResult(false, 'CREATE TABLE cotizacion_archivos', htmlspecialchars($e->getMessage()));
          $globalOk = false;
      }

      // ──────────────────────────────────────────────
      // PASO 3 — Corregir cotización DOMINGUEZ & FALCON
      // ──────────────────────────────────────────────
      echo '<p class="section-title">Paso 3 &mdash; Correcci&oacute;n INVERSIONES DOMINGUEZ &amp; FALCON</p>';
      flush();

      // Resolver IDs en PHP para evitar problemas con variables de sesión MySQL
      $clId  = null;
      $cotId = null;

      try {
          $stCl = $pdo->prepare(
              "SELECT id FROM clientes WHERE razon_social LIKE '%DOMINGUEZ%' AND razon_social LIKE '%FALCON%' LIMIT 1"
          );
          $stCl->execute();
          $clId = $stCl->fetchColumn() ?: null;

          if ($clId === null) {
              stepResult(false, 'Buscar cliente DOMINGUEZ & FALCON', 'Cliente no encontrado en la tabla clientes.');
              $globalOk = false;
          } else {
              stepResult(true, 'Buscar cliente DOMINGUEZ & FALCON', "cliente_id = {$clId}");

              $stCot = $pdo->prepare(
                  "SELECT id FROM cotizaciones WHERE cliente_id = ? ORDER BY id DESC LIMIT 1"
              );
              $stCot->execute([$clId]);
              $cotId = $stCot->fetchColumn() ?: null;

              if ($cotId === null) {
                  stepResult(false, 'Buscar cotizaci&oacute;n del cliente', 'No se encontr&oacute; cotizaci&oacute;n para este cliente.');
                  $globalOk = false;
              } else {
                  stepResult(true, 'Buscar cotizaci&oacute;n del cliente', "cotizacion_id = {$cotId}");
              }
          }
      } catch (\Throwable $e) {
          stepResult(false, 'Consulta de cliente / cotizaci&oacute;n', htmlspecialchars($e->getMessage()));
          $globalOk = false;
      }

      if ($clId !== null && $cotId !== null) {

          // Actualizar montos
          try {
              $stUpd = $pdo->prepare(
                  "UPDATE cotizaciones SET subtotal=1500.00, igv=270.00, monto_total=1770.00, descuento=0.00 WHERE id = ?"
              );
              $stUpd->execute([$cotId]);
              stepResult(true, 'UPDATE cotizaciones (montos)', "Filas afectadas: {$stUpd->rowCount()}");
          } catch (\Throwable $e) {
              stepResult(false, 'UPDATE cotizaciones (montos)', htmlspecialchars($e->getMessage()));
              $globalOk = false;
          }

          // Actualizar precio en cotizacion_normas
          try {
              $stNorma = $pdo->prepare(
                  "UPDATE cotizacion_normas SET precio=1500.00 WHERE cotizacion_id = ?"
              );
              $stNorma->execute([$cotId]);
              stepResult(true, 'UPDATE cotizacion_normas (precio)', "Filas afectadas: {$stNorma->rowCount()}");
          } catch (\Throwable $e) {
              stepResult(false, 'UPDATE cotizacion_normas (precio)', htmlspecialchars($e->getMessage()));
              $globalOk = false;
          }

          // Eliminar pagos anteriores
          try {
              $stDel = $pdo->prepare("DELETE FROM pagos_cliente WHERE cotizacion_id = ?");
              $stDel->execute([$cotId]);
              stepResult(true, 'DELETE pagos_cliente (anteriores)', "Filas eliminadas: {$stDel->rowCount()}");
          } catch (\Throwable $e) {
              stepResult(false, 'DELETE pagos_cliente', htmlspecialchars($e->getMessage()));
              $globalOk = false;
          }

          // Insertar pago inicial
          try {
              $stIni = $pdo->prepare(
                  "INSERT INTO pagos_cliente (cliente_id, cotizacion_id, tipo, porcentaje, monto, moneda, estado)
                   VALUES (?, ?, 'inicial', 50.00, 885.00, 'PEN', 'pendiente_voucher')"
              );
              $stIni->execute([$clId, $cotId]);
              stepResult(true, 'INSERT pago inicial (50% — S/ 885.00)', "ID insertado: {$pdo->lastInsertId()}");
          } catch (\Throwable $e) {
              stepResult(false, 'INSERT pago inicial', htmlspecialchars($e->getMessage()));
              $globalOk = false;
          }

          // Insertar pago final
          try {
              $stFin = $pdo->prepare(
                  "INSERT INTO pagos_cliente (cliente_id, cotizacion_id, tipo, porcentaje, monto, moneda, estado)
                   VALUES (?, ?, 'final', 50.00, 885.00, 'PEN', 'pendiente_voucher')"
              );
              $stFin->execute([$clId, $cotId]);
              stepResult(true, 'INSERT pago final (50% — S/ 885.00)', "ID insertado: {$pdo->lastInsertId()}");
          } catch (\Throwable $e) {
              stepResult(false, 'INSERT pago final', htmlspecialchars($e->getMessage()));
              $globalOk = false;
          }

      } else {
          echo "<div style=\"font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 14px;margin:6px 0;\">
              Paso 3 omitido parcialmente: no se pudo resolver cliente o cotizaci&oacute;n.
          </div>\n";
      }

      // ──────────────────────────────────────────────
      // PASO 4 — Migrar cotizacion_firmada
      // ──────────────────────────────────────────────
      echo '<p class="section-title">Paso 4 &mdash; Migrar <code>cotizacion_firmada</code> a <code>cotizacion_archivos</code></p>';
      flush();

      try {
          $sqlMigrate = "INSERT IGNORE INTO cotizacion_archivos
                           (cotizacion_id, tipo, nombre_archivo, ruta_archivo, mime_type, tamano_bytes, version, subido_por, created_at)
                         SELECT
                             cot.id          AS cotizacion_id,
                             'firmada'       AS tipo,
                             dc.nombre_archivo,
                             dc.ruta_archivo,
                             dc.mime_type,
                             dc.tamano_bytes,
                             dc.version,
                             dc.subido_por,
                             dc.uploaded_at
                         FROM documentos_cliente dc
                         JOIN clientes cl ON cl.id = dc.cliente_id
                         JOIN cotizaciones cot ON cot.cliente_id = cl.id
                         LEFT JOIN cotizacion_archivos ca
                               ON ca.cotizacion_id = cot.id AND ca.tipo = 'firmada'
                         WHERE dc.tipo_documento = 'cotizacion_firmada'
                           AND dc.es_vigente     = 1
                           AND ca.id IS NULL";

          $stMig = $pdo->query($sqlMigrate);
          $migrated = $stMig->rowCount();
          stepResult(true, 'INSERT INTO cotizacion_archivos (migraci&oacute;n)', "Registros migrados: {$migrated}");
      } catch (\Throwable $e) {
          stepResult(false, 'INSERT INTO cotizacion_archivos (migraci&oacute;n)', htmlspecialchars($e->getMessage()));
          $globalOk = false;
      }

      // ──────────────────────────────────────────────
      // Commit / Rollback
      // ──────────────────────────────────────────────
      if ($globalOk) {
          $pdo->commit();
          echo '<p class="section-title">Resultado global</p>';
          stepResult(true, 'Transacci&oacute;n confirmada (COMMIT)', 'Todos los pasos completados sin errores.');
      } else {
          $pdo->rollBack();
          echo '<p class="section-title">Resultado global</p>';
          stepResult(false, 'Transacci&oacute;n revertida (ROLLBACK)', 'Uno o m&aacute;s pasos fallaron. Ning&uacute;n cambio fue guardado.');
          $redirectOk = false;
      }

  } catch (\Throwable $e) {
      if ($pdo->inTransaction()) {
          $pdo->rollBack();
      }
      $globalOk   = false;
      $redirectOk = false;
      echo '<div class="error-global"><strong>Error cr&iacute;tico:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
  }
?>

  <div class="footer-actions">
    <a href="/modules/admin/" class="btn btn-back">Volver al panel de administraci&oacute;n</a>
  </div>

<?php if ($redirectOk): ?>
  <script>
    setTimeout(function () {
      window.location.href = '/modules/admin/';
    }, 5000);
  </script>
  <p style="margin-top:14px;font-size:13px;color:#64748b;">Redirigiendo al panel en 5 segundos&hellip;</p>
<?php endif; ?>

<?php endif; ?>

</div><!-- /.card -->
</body>
</html>
