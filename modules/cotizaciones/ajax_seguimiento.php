<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }
csrf_verify();

$cotizacion_id  = (int)($_POST['cotizacion_id'] ?? 0);
$tipo           = $_POST['tipo'] ?? '';
$notas          = trim($_POST['notas'] ?? '');
$resultado      = $_POST['resultado'] ?? 'sin_respuesta';
$proxima_accion = $_POST['proxima_accion'] ?? null;

$tipos_validos    = ['llamada','email','reunion','whatsapp','otro'];
$resultados_validos = ['sin_respuesta','interesado','pidio_tiempo','rechazado','aceptado'];

if (!$cotizacion_id || !in_array($tipo, $tipos_validos) || empty($notas) || !in_array($resultado, $resultados_validos)) {
    echo json_encode(['ok'=>false,'msg'=>'Datos inválidos']); exit;
}

$pdo  = Database::get();
$user = auth_user();

// Verificar que la propuesta pertenece al ejecutivo (si es ejecutivo_ventas)
if ($user['rol'] === 'ejecutivo_ventas') {
    $check = $pdo->prepare("SELECT id FROM cotizaciones WHERE id=? AND ejecutivo_id=?");
    $check->execute([$cotizacion_id, $user['id']]);
    if (!$check->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Sin permiso']); exit; }
}

$pdo->prepare("
    INSERT INTO cotizacion_seguimientos (cotizacion_id, usuario_id, tipo, notas, resultado, proxima_accion)
    VALUES (?,?,?,?,?,?)
")->execute([
    $cotizacion_id,
    $user['id'],
    $tipo,
    $notas,
    $resultado,
    $proxima_accion ?: null,
]);

log_actividad('propuestas', 'seguimiento', "Seguimiento en propuesta #$cotizacion_id — tipo: $tipo");
echo json_encode(['ok'=>true,'msg'=>'Seguimiento registrado correctamente.']);
