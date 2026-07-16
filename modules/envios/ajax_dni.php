<?php
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación
header('Content-Type: application/json');

$dni = preg_replace('/\D/', '', $_GET['dni'] ?? '');
if (strlen($dni) !== 8) {
    echo json_encode(['ok' => false, 'msg' => 'DNI debe tener 8 dígitos.']);
    exit;
}

$params = json_encode(['dni' => $dni]);
$curl   = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => 'https://apiperu.dev/api/dni',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POSTFIELDS     => $params,
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

if ($err) {
    echo json_encode(['ok' => false, 'msg' => 'Error de conexión: ' . $err]);
    exit;
}

$data = json_decode($response, true);
if (!$data || !isset($data['data'])) {
    echo json_encode(['ok' => false, 'msg' => 'DNI no encontrado.']);
    exit;
}

$d = $data['data'];
echo json_encode([
    'ok'       => true,
    'nombres'  => $d['nombres']       ?? '',
    'apellido_paterno' => $d['apellido_paterno'] ?? '',
    'apellido_materno' => $d['apellido_materno'] ?? '',
    'nombre_completo'  => trim(($d['nombres'] ?? '') . ' ' . ($d['apellido_paterno'] ?? '') . ' ' . ($d['apellido_materno'] ?? '')),
]);
