<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gestor_logistico');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'msg'=>'Método inválido']); exit;
}
csrf_verify();

$envio_id = (int)($_POST['envio_id'] ?? 0);
$user     = auth_user();

if (!$envio_id) {
    echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit;
}

$pdo = Database::get();

// Verificar que el envío existe y no tiene gestor y no es estado final
$stmt = $pdo->prepare("SELECT agente_id, estado_envio_id FROM envios WHERE id=?");
$stmt->execute([$envio_id]);
$envio = $stmt->fetch();

if (!$envio) {
    echo json_encode(['ok'=>false,'msg'=>'Envío no encontrado']); exit;
}
if (!empty($envio['agente_id'])) {
    echo json_encode(['ok'=>false,'msg'=>'Este envío ya tiene un gestor asignado']); exit;
}
if (in_array($envio['estado_envio_id'], [5,6])) {
    echo json_encode(['ok'=>false,'msg'=>'No se puede asumir un envío finalizado']); exit;
}

$pdo->prepare("UPDATE envios SET agente_id=? WHERE id=? AND agente_id IS NULL")
    ->execute([$user['id'], $envio_id]);

log_actividad('envios','asumir',"Gestor #{$user['id']} asumió envío #$envio_id");

echo json_encode(['ok'=>true]);
