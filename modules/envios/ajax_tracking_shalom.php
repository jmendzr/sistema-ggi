<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/ShalomTrackingService.php';
require_once __DIR__ . '/../../core/envio_notificar.php';
session_init();
require_login();
session_write_close();  // solo lee $_SESSION → no serializar (auditoría 2026-07)

header('Content-Type: application/json; charset=utf-8');

$envio_id = (int)($_GET['envio_id'] ?? 0);
if (!$envio_id) {
    echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    exit;
}

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT e.numero_orden, e.codigo_envio, ag.nombre AS agencia_nombre
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

if (stripos($envio['agencia_nombre'] ?? '', 'shalom') === false) {
    echo json_encode(['ok' => false, 'error' => 'El tracking automático Shalom solo está disponible para envíos de Shalom Envíos.']);
    exit;
}

if (empty($envio['numero_orden'])) {
    echo json_encode(['ok' => false, 'error' => 'Este envío no tiene N° de Guía registrado. Agrégalo en <strong>N° de orden</strong> desde Editar.']);
    exit;
}

if (empty($envio['codigo_envio'])) {
    echo json_encode(['ok' => false, 'error' => 'Este envío no tiene código de orden registrado. Agrégalo en <strong>Código de seguimiento</strong> desde Editar.']);
    exit;
}

$data = ShalomTrackingService::consultar($envio['numero_orden'], $envio['codigo_envio']);

if (!$data) {
    echo json_encode(['ok' => false, 'error' => 'El rastreo automático de Shalom no está disponible por el momento (Shalom protegió su rastreador con reCAPTCHA). Consulta el estado directamente en Shalom con el N° de guía <strong>' . htmlspecialchars($envio['numero_orden']) . '</strong>.']);
    exit;
}

// Auto-sincronizar estado si Shalom está más avanzado
$nuevo_estado_id = ShalomTrackingService::sincronizar($envio_id, $pdo);
$data['estado_actualizado'] = $nuevo_estado_id !== null;
if ($nuevo_estado_id) {
    $est = $pdo->prepare("SELECT nombre, color_hex FROM estados_envio WHERE id=?");
    $est->execute([$nuevo_estado_id]);
    $data['nuevo_estado'] = $est->fetch() ?: null;
    // Notificar al cliente el avance de estado (solo cuando sincronizar avanzó)
    gg_envio_notificar_estado($pdo, $envio_id, (int)$nuevo_estado_id, auth_user()['id'] ?? null);
}

echo json_encode(['ok' => true, 'data' => $data]);
