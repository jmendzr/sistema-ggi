<?php
/**
 * Eliminación lógica de pagos (soft delete via deleted_at).
 * NO elimina físicamente ni el voucher del disco.
 * Solo permite eliminar si el pago no ha sido validado.
 * POST: pago_id
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json');

if (!has_role('gerente_general')) {
    echo json_encode(['ok' => false, 'msg' => 'Solo el Gerente General puede eliminar pagos.']);
    exit;
}

csrf_verify();

$pdo     = Database::get();
$pago_id = (int)($_POST['pago_id'] ?? 0);
$user    = auth_user();

if (!$pago_id) {
    echo json_encode(['ok' => false, 'msg' => 'ID de pago inválido.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM pagos_cliente WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$pago_id]);
$pago = $stmt->fetch();

if (!$pago) {
    echo json_encode(['ok' => false, 'msg' => 'Pago no encontrado o ya fue eliminado.']);
    exit;
}

// No eliminar pagos ya validados
if ($pago['estado'] === 'validado') {
    echo json_encode([
        'ok'  => false,
        'msg' => 'No se puede eliminar un pago ya validado. Contacte al administrador si es un error.',
    ]);
    exit;
}

// Soft delete — el voucher permanece en disco como respaldo histórico
$pdo->prepare("
    UPDATE pagos_cliente
    SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
")->execute([$user['id'], $pago_id]);

log_actividad('pagos', 'eliminar',
    "Pago #$pago_id eliminado (cotización #{$pago['cotizacion_id']}, estado: {$pago['estado']}, monto: {$pago['monto']} {$pago['moneda']})");

echo json_encode([
    'ok'  => true,
    'msg' => 'Pago eliminado correctamente.',
]);
