<?php
// modules/cotizaciones/ajax_bulk.php — Acciones masivas sobre cotizaciones
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();

header('Content-Type: application/json; charset=utf-8');

// Solo gerentes pueden hacer acciones masivas
if (!has_role('gerente_general', 'gerente_operaciones')) {
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.']);
    exit;
}

// Leer JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos.']);
    exit;
}

// Verificar CSRF (token enviado en header X-CSRF-Token)
csrf_verify();

$ids        = array_filter(array_map('intval', $data['ids'] ?? []), fn($id) => $id > 0);
$nuevo_estado = $data['estado'] ?? '';

$estados_validos = ['borrador', 'enviada', 'aceptada', 'rechazada', 'vencida'];
if (empty($ids) || !in_array($nuevo_estado, $estados_validos)) {
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos.']);
    exit;
}

if (count($ids) > 200) {
    echo json_encode(['ok' => false, 'error' => 'Máximo 200 registros por operación.']);
    exit;
}

$pdo = Database::get();

// Build placeholders
$ph = implode(',', array_fill(0, count($ids), '?'));

// Update — only records the user can touch
// gerente_general: all; gerente_operaciones: all
// Al aceptar, registrar también la fecha de cierre de cada propuesta.
$set_fecha = $nuevo_estado === 'aceptada' ? ", fecha_aceptacion=COALESCE(fecha_aceptacion, NOW())" : "";
// Los estados finales no se tocan en masa (mismo guard que ajax_estado_cot.php)
$stmt = $pdo->prepare("UPDATE cotizaciones SET estado = ?$set_fecha WHERE id IN ($ph) AND estado NOT IN ('aceptada','rechazada','vencida','desistida') AND deleted_at IS NULL");
$params = array_merge([$nuevo_estado], array_values($ids));
$stmt->execute($params);
$actualizados = $stmt->rowCount();

// Nota: los bonos ya NO se generan al aceptar. Se asignan al ejecutivo cuando el cliente
// paga el TOTAL (todos los pagos validados) → modules/pagos/ajax_pago.php (acción 'validar').

// Log actividad
$label_estados = ['borrador'=>'borrador','enviada'=>'enviada','aceptada'=>'aceptada','rechazada'=>'rechazada','vencida'=>'vencida'];
log_actividad('cotizaciones', 'bulk_estado', "Estado masivo → {$nuevo_estado} en " . count($ids) . " propuestas");

echo json_encode([
    'ok'          => true,
    'actualizados' => $actualizados,
    'mensaje'     => $actualizados . ' propuesta' . ($actualizados !== 1 ? 's' : '') . ' actualizadas a "' . ($label_estados[$nuevo_estado] ?? $nuevo_estado) . '".',
]);
