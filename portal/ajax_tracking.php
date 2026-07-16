<?php
/**
 * portal/ajax_tracking.php
 * Tracking de guía (Olva o Shalom) para el portal de clientes (sin sesión, usa token).
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/OlvaTrackingService.php';
require_once __DIR__ . '/../core/ShalomTrackingService.php';

header('Content-Type: application/json; charset=utf-8');

$token    = trim($_GET['token']    ?? '');
$envio_id = (int)($_GET['envio_id'] ?? 0);

if (!$token || !$envio_id) {
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos']);
    exit;
}

$pdo = Database::get();

// Verificar que el token corresponde al cliente dueño del envío
$stmt = $pdo->prepare("
    SELECT e.codigo_envio, e.numero_orden, ag.nombre AS agencia_nombre
    FROM envios e
    JOIN clientes cl ON cl.id = e.cliente_id
    JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE e.id = ?
      AND cl.portal_token = ?
      AND cl.deleted_at IS NULL
      AND e.deleted_at IS NULL
");
$stmt->execute([$envio_id, $token]);
$envio = $stmt->fetch();

if (!$envio) {
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$agencia  = strtolower($envio['agencia_nombre'] ?? '');
$is_olva  = str_contains($agencia, 'olva');
$is_shalom = str_contains($agencia, 'shalom');

if (!$is_olva && !$is_shalom) {
    echo json_encode(['ok' => false, 'error' => 'Tracking automático no disponible para esta agencia']);
    exit;
}

// ── Olva ──────────────────────────────────────────────────────────────────────
if ($is_olva) {
    if (empty($envio['codigo_envio'])) {
        echo json_encode(['ok' => false, 'error' => 'sin_codigo']);
        exit;
    }
    $data = OlvaTrackingService::consultar($envio['codigo_envio']);
    if (!$data) {
        echo json_encode(['ok' => false, 'error' => 'no_data']);
        exit;
    }
    $data['stepper_type'] = 'olva';
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

// ── Shalom ────────────────────────────────────────────────────────────────────
if (empty($envio['numero_orden']) || empty($envio['codigo_envio'])) {
    echo json_encode(['ok' => false, 'error' => 'sin_codigo']);
    exit;
}
$data = ShalomTrackingService::consultar($envio['numero_orden'], $envio['codigo_envio']);
if (!$data) {
    echo json_encode(['ok' => false, 'error' => 'no_data']);
    exit;
}
echo json_encode(['ok' => true, 'data' => $data]);
