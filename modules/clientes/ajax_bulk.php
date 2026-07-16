<?php
// modules/clientes/ajax_bulk.php — Acciones masivas sobre empresas/clientes
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

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

// Verificar CSRF
csrf_verify();

$ids          = array_filter(array_map('intval', $data['ids'] ?? []), fn($id) => $id > 0);
$nuevo_estado = $data['estado'] ?? '';

$estados_validos = ['activo', 'inactivo', 'prospecto'];
if (empty($ids) || !in_array($nuevo_estado, $estados_validos)) {
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos.']);
    exit;
}

if (count($ids) > 200) {
    echo json_encode(['ok' => false, 'error' => 'Máximo 200 registros por operación.']);
    exit;
}

$pdo = Database::get();

$ph   = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("UPDATE clientes SET estado = ? WHERE id IN ($ph)");
$params = array_merge([$nuevo_estado], array_values($ids));
$stmt->execute($params);
$actualizados = $stmt->rowCount();

$label_estados = ['activo' => 'Activo', 'inactivo' => 'Inactivo', 'prospecto' => 'Prospecto'];

log_actividad('clientes', 'bulk_estado', "Estado masivo → {$nuevo_estado} en " . count($ids) . " empresas");

echo json_encode([
    'ok'          => true,
    'actualizados' => $actualizados,
    'mensaje'     => $actualizados . ' empresa' . ($actualizados !== 1 ? 's' : '') . ' actualizadas a "' . ($label_estados[$nuevo_estado] ?? $nuevo_estado) . '".',
]);
