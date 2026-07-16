<?php
/**
 * portal/ajax_dni_portal.php
 * Consulta de DNI para el portal de clientes (auth por token, sin sesión).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$token = trim($_GET['token'] ?? '');
$dni   = preg_replace('/\D/', '', $_GET['dni'] ?? '');

if (!$token) {
    echo json_encode(['ok' => false, 'msg' => 'No autorizado.']); exit;
}
if (strlen($dni) !== 8) {
    echo json_encode(['ok' => false, 'msg' => 'DNI debe tener 8 dígitos.']); exit;
}

// Validar token
$pdo  = Database::get();
$stmt = $pdo->prepare("SELECT id FROM clientes WHERE portal_token=? AND portal_activo=1 AND estado!='inactivo' AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$token]);
if (!$stmt->fetchColumn()) {
    echo json_encode(['ok' => false, 'msg' => 'No autorizado.']); exit;
}

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => 'https://apiperu.dev/api/dni',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POSTFIELDS     => json_encode(['dni' => $dni]),
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer d5912fceb9d7a134f9526390243ceca5021fdcf79f2ce34dad1bce2c7c78c3b8',
    ],
    CURLOPT_TIMEOUT => 8,
]);
$response = curl_exec($curl);
$err      = curl_error($curl);
curl_close($curl);

if ($err) { echo json_encode(['ok' => false, 'msg' => 'Error de conexión.']); exit; }

$data = json_decode($response, true);
if (!$data || empty($data['data'])) {
    echo json_encode(['ok' => false, 'msg' => 'DNI no encontrado.']); exit;
}

$d = $data['data'];
echo json_encode([
    'ok'             => true,
    'nombre_completo'=> trim(($d['nombres'] ?? '') . ' ' . ($d['apellido_paterno'] ?? '') . ' ' . ($d['apellido_materno'] ?? '')),
]);
