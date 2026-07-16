<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }
csrf_verify();

$user      = auth_user();
$cert_id   = (int)($_POST['cert_id'] ?? 0);
$nuevo_est = trim($_POST['estado'] ?? '');

// Solo gerentes pueden cambiar estado de certificados manualmente.
// Los estados vigente/por_vencer/vencido/en_proceso son automáticos (trigger + cron).
// 'suspendido' solo lo asignan gerentes.
if (!in_array($user['rol'], ['gerente_general','gerente_operaciones'])) {
    echo json_encode(['ok'=>false,'msg'=>'Sin permiso para cambiar el estado del certificado']); exit;
}

$validos = ['vigente','por_vencer','vencido','en_proceso','suspendido'];
if (!$cert_id || !in_array($nuevo_est, $validos)) {
    echo json_encode(['ok'=>false,'msg'=>'Datos inválidos']); exit;
}

$pdo  = Database::get();
$cert = $pdo->prepare("SELECT id FROM certificados WHERE id=?")->execute([$cert_id]);

$pdo->prepare("UPDATE certificados SET estado=? WHERE id=?")->execute([$nuevo_est, $cert_id]);
log_actividad('certificados','estado',"Certificado #$cert_id → $nuevo_est");

// ── Trigger de envío: si el certificado pasa a vigente, verificar si ya
//    todos los certs de su propuesta son activos → crear envío automáticamente
$envio_generado = null;
if ($nuevo_est === 'vigente') {
    require_once __DIR__ . '/../envios/auto_generar.php';
    $envio_generado = verificar_envio_por_cotizacion($pdo, $cert_id, $user['id']);
    if ($envio_generado) {
        log_actividad('envios', 'auto_crear',
            "Envío #{$envio_generado} generado automáticamente: todos los certs de la propuesta son vigentes");
    }
}

echo json_encode(['ok'=>true,'estado'=>$nuevo_est,'envio_id'=>$envio_generado]);
