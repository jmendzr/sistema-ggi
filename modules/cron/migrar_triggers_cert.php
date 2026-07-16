<?php
/**
 * Migración: actualizar triggers de certificados.
 * - 90 días → 30 días para por_vencer
 * - NULL fecha_vencimiento → en_proceso (no confundir con vigente)
 * - estado 'suspendido' no se sobreescribe
 *
 * Ejecutar UNA VEZ: php migrar_triggers_cert.php
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();

// Solo CLI o con token
if (PHP_SAPI !== 'cli' && ($_GET['token'] ?? '') !== (CRON_TOKEN ?? '')) {
    http_response_code(403); exit('Acceso denegado');
}

$pdo = Database::get();

// ── Trigger INSERT ──
$pdo->exec("DROP TRIGGER IF EXISTS trg_estado_certificado");
$pdo->exec("
CREATE TRIGGER trg_estado_certificado
BEFORE INSERT ON certificados
FOR EACH ROW
BEGIN
  IF NEW.estado != 'suspendido' THEN
    IF NEW.fecha_vencimiento IS NULL THEN
      SET NEW.estado = 'en_proceso';
    ELSEIF NEW.fecha_vencimiento < CURDATE() THEN
      SET NEW.estado = 'vencido';
    ELSEIF NEW.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN
      SET NEW.estado = 'por_vencer';
    ELSE
      SET NEW.estado = 'vigente';
    END IF;
  END IF;
  IF NEW.fecha_auditoria_1 IS NULL AND NEW.fecha_emision IS NOT NULL THEN
    SET NEW.fecha_auditoria_1 = DATE_ADD(NEW.fecha_emision, INTERVAL 1 YEAR);
  END IF;
  IF NEW.fecha_auditoria_2 IS NULL AND NEW.fecha_emision IS NOT NULL THEN
    SET NEW.fecha_auditoria_2 = DATE_ADD(NEW.fecha_emision, INTERVAL 2 YEAR);
  END IF;
END
");

// ── Trigger UPDATE ──
$pdo->exec("DROP TRIGGER IF EXISTS trg_estado_certificado_update");
$pdo->exec("
CREATE TRIGGER trg_estado_certificado_update
BEFORE UPDATE ON certificados
FOR EACH ROW
BEGIN
  IF NEW.estado != 'suspendido' THEN
    IF NEW.fecha_vencimiento IS NULL THEN
      SET NEW.estado = 'en_proceso';
    ELSEIF NEW.fecha_vencimiento < CURDATE() THEN
      SET NEW.estado = 'vencido';
    ELSEIF NEW.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN
      SET NEW.estado = 'por_vencer';
    ELSE
      SET NEW.estado = 'vigente';
    END IF;
  END IF;
END
");

echo "Triggers actualizados correctamente.\n";

// ── Actualizar estados existentes en BD ──
// Certificados sin fecha_vencimiento → en_proceso
$n1 = $pdo->exec("UPDATE certificados SET estado='en_proceso' WHERE estado NOT IN ('suspendido') AND fecha_vencimiento IS NULL");
// Vencidos
$n2 = $pdo->exec("UPDATE certificados SET estado='vencido' WHERE estado NOT IN ('suspendido') AND fecha_vencimiento < CURDATE()");
// Por vencer (30 días)
$n3 = $pdo->exec("UPDATE certificados SET estado='por_vencer' WHERE estado NOT IN ('suspendido','vencido') AND fecha_vencimiento >= CURDATE() AND fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
// Vigentes
$n4 = $pdo->exec("UPDATE certificados SET estado='vigente' WHERE estado NOT IN ('suspendido','vencido','por_vencer') AND fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY)");

echo "Estados actualizados: en_proceso=$n1, vencido=$n2, por_vencer=$n3, vigente=$n4\n";
