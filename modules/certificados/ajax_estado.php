<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }
csrf_verify();

$envio_id  = (int)($_POST['envio_id']  ?? 0);
$estado_id = (int)($_POST['estado_id'] ?? 0);

if (!$envio_id || !$estado_id) { echo json_encode(['ok'=>false,'msg'=>'Datos inválidos']); exit; }

$pdo = Database::get();

// Verificar que el estado existe y está activo
$est = $pdo->prepare("SELECT id, nombre FROM estados_envio WHERE id=? AND activo=1");
$est->execute([$estado_id]);
if (!$est->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Estado inválido']); exit; }

$pdo->prepare("UPDATE envios SET estado_envio_id=? WHERE id=?")->execute([$estado_id, $envio_id]);

// Si el estado es "Entregado", registrar fecha de recepción automáticamente si no tiene
$est2 = $pdo->prepare("SELECT nombre FROM estados_envio WHERE id=?");
$est2->execute([$estado_id]);
$nombre_estado = strtolower($est2->fetchColumn());
if (strpos($nombre_estado, 'entregado') !== false) {
    $pdo->prepare("UPDATE envios SET fecha_recibido = COALESCE(fecha_recibido, CURDATE()) WHERE id=?")->execute([$envio_id]);
}

log_actividad('envios', 'estado', 'Envío ID: ' . $envio_id . ' → estado ID: ' . $estado_id);
echo json_encode(['ok'=>true]);
