<?php
/**
 * ajax_fecha_cert.php — Editar la fecha en que el cliente necesita sus certificados
 * (propuesta ya aceptada). Solo gerentes. Resetea alerta_cert_at si cambia la fecha,
 * para que el cron pueda re-disparar la alerta push 2 días antes.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false]); exit; }
csrf_verify();

$pdo    = Database::get();
$cot_id = (int)($_POST['cot_id'] ?? 0);
$fecha  = trim($_POST['fecha_requerida_certificados'] ?? '');

if (!$cot_id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }
if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
    echo json_encode(['ok' => false, 'msg' => 'Fecha inválida']); exit;
}

$stmt = $pdo->prepare("SELECT estado, fecha_requerida_certificados FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$cot_id]);
$cot = $stmt->fetch();
if (!$cot) { echo json_encode(['ok' => false, 'msg' => 'Propuesta no encontrada']); exit; }
if ($cot['estado'] !== 'aceptada') {
    echo json_encode(['ok' => false, 'msg' => 'Solo se puede editar en propuestas aceptadas']); exit;
}

// Si la fecha cambió, resetear la alerta para que el cron la re-programe.
$reset = ($cot['fecha_requerida_certificados'] !== $fecha) ? ', alerta_cert_at = NULL' : '';
$pdo->prepare("UPDATE cotizaciones SET fecha_requerida_certificados = ?$reset WHERE id = ?")
    ->execute([$fecha, $cot_id]);

log_actividad('propuestas', 'fecha_cert', "Propuesta #$cot_id — fecha certificados requeridos → $fecha");

echo json_encode(['ok' => true, 'fecha' => $fecha]);
