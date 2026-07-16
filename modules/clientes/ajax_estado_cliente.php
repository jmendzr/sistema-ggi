<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
// Solo gerentes pueden cambiar el estado de un cliente manualmente.
// Ventas lo ve como badge de solo lectura; los cambios son automáticos.
require_role('gerente_general','gerente_operaciones');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }
csrf_verify();

$cliente_id = (int)($_POST['cliente_id'] ?? 0);
$nuevo_est  = trim($_POST['estado'] ?? '');
$validos    = ['activo','inactivo','prospecto'];

if (!$cliente_id || !in_array($nuevo_est, $validos)) {
    echo json_encode(['ok'=>false,'msg'=>'Datos inválidos']); exit;
}

$pdo = Database::get();
$pdo->prepare("UPDATE clientes SET estado=? WHERE id=?")->execute([$nuevo_est, $cliente_id]);
log_actividad('clientes','estado',"Cliente #$cliente_id → $nuevo_est");

echo json_encode(['ok'=>true]);
