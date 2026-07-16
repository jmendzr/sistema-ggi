<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]); exit;
}

$cliente_id = (int)($_POST['cliente_id'] ?? 0);
if (!$cliente_id) {
    echo json_encode(['ok' => false, 'msg' => 'Cliente inválido.']); exit;
}

$pdo = Database::get();

$allowed = ['razon_social', 'direccion', 'departamento', 'provincia', 'distrito', 'ubigeo', 'num_trabajadores'];
$sets    = [];
$params  = [];

foreach ($allowed as $field) {
    if (array_key_exists($field, $_POST) && $_POST[$field] !== '') {
        $sets[]   = "$field = ?";
        $params[] = ($field === 'num_trabajadores') ? (int)$_POST[$field] : trim($_POST[$field]);
    }
}

if (empty($sets)) {
    echo json_encode(['ok' => false, 'msg' => 'Nada que actualizar.']); exit;
}

$params[] = $cliente_id;
$pdo->prepare("UPDATE clientes SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

$campo_list = implode(', ', array_map(fn($s) => explode(' =', $s)[0], $sets));
log_actividad('clientes', 'editar', "Datos actualizados desde SUNAT para cliente #$cliente_id: $campo_list");

echo json_encode(['ok' => true]);
