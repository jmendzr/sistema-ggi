<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/SerpostTrackingService.php';
require_once __DIR__ . '/../../core/envio_notificar.php';
session_init();
require_login();

header('Content-Type: application/json; charset=utf-8');

$envio_id = (int)($_GET['envio_id'] ?? 0);
if (!$envio_id) {
    echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    exit;
}

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT e.codigo_envio, ag.nombre AS agencia_nombre
    FROM envios e
    LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE e.id = ? AND e.deleted_at IS NULL
");
$stmt->execute([$envio_id]);
$envio = $stmt->fetch();

if (!$envio) {
    echo json_encode(['ok' => false, 'error' => 'Envío no encontrado']);
    exit;
}
if (!$envio['codigo_envio']) {
    echo json_encode(['ok' => false, 'error' => 'Este envío no tiene código de seguimiento registrado.']);
    exit;
}
if (stripos($envio['agencia_nombre'] ?? '', 'serpost') === false) {
    echo json_encode(['ok' => false, 'error' => 'El tracking automático Serpost solo aplica a envíos Serpost.']);
    exit;
}

$data = SerpostTrackingService::consultar($envio['codigo_envio']);

if (!$data) {
    echo json_encode(['ok' => false, 'error' => 'No se encontró información para el código <strong>' . htmlspecialchars($envio['codigo_envio']) . '</strong> en Serpost. Verifique el número de envío.']);
    exit;
}

// Auto-sincronizar: avanzar estado en BD si Serpost está más adelante
$nuevo_estado_id = SerpostTrackingService::sincronizar($envio_id, $pdo);
$data['estado_actualizado'] = $nuevo_estado_id !== null;
if ($nuevo_estado_id) {
    $est = $pdo->prepare("SELECT nombre, color_hex FROM estados_envio WHERE id=?");
    $est->execute([$nuevo_estado_id]);
    $data['nuevo_estado'] = $est->fetch() ?: null;
    // Notificar al cliente el avance de estado (solo cuando sincronizar avanzó)
    gg_envio_notificar_estado($pdo, $envio_id, (int)$nuevo_estado_id, auth_user()['id'] ?? null);
}

echo json_encode(['ok' => true, 'data' => $data]);
