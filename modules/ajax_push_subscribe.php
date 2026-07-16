<?php
/**
 * ajax_push_subscribe.php
 * POST { endpoint, p256dh, auth }  → guarda suscripción push del usuario
 * POST { unsubscribe: true, endpoint } → elimina suscripción
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/PushService.php';
require_once __DIR__ . '/../vendor/autoload.php';

session_init();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$user = auth_user();

// Leer JSON o POST normal
$raw  = file_get_contents('php://input');
$data = $raw ? (json_decode($raw, true) ?? $_POST) : $_POST;

session_write_close(); // liberar lock de sesión lo antes posible

// ── Baja de suscripción ──────────────────────────────────────────────────
if (!empty($data['unsubscribe'])) {
    $endpoint = trim($data['endpoint'] ?? '');
    if ($endpoint) PushService::unsubscribe((int)$user['id'], $endpoint);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Alta de suscripción ──────────────────────────────────────────────────
$endpoint = trim($data['endpoint'] ?? '');
$p256dh   = trim($data['p256dh']   ?? '');
$auth     = trim($data['auth']     ?? '');

if (!$endpoint || !$p256dh || !$auth) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Datos incompletos']);
    exit;
}

$ok = PushService::subscribe((int)$user['id'], $endpoint, $p256dh, $auth);
echo json_encode(['ok' => $ok]);
