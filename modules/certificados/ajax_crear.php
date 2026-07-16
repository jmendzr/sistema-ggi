<?php
/**
 * modules/certificados/ajax_crear.php
 * Crea un certificado vía AJAX desde la ficha del cliente (popup).
 * No incluye archivos adjuntos; se suben desde el detalle del certificado.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

header('Content-Type: application/json; charset=utf-8');

if (!can('certificados')) {
    echo json_encode(['ok' => false, 'msg' => 'Sin permisos para crear certificados.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']);
    exit;
}

csrf_verify();

$pdo  = Database::get();
$user = auth_user();

$cliente_id   = (int)($_POST['cliente_id']            ?? 0);
$norma_iso_id = (int)($_POST['norma_iso_id']           ?? 0);
$casa_id      = (int)($_POST['casa_certificadora_id']  ?? 0) ?: null;
$numero       = trim($_POST['numero_certificado']       ?? '');
$fecha_em     = trim($_POST['fecha_emision']            ?? '');
$estado       = $_POST['estado']                        ?? 'en_proceso';

if (!$cliente_id) {
    echo json_encode(['ok' => false, 'msg' => 'Cliente requerido.']);
    exit;
}
if (!$norma_iso_id) {
    echo json_encode(['ok' => false, 'msg' => 'Selecciona una norma ISO.']);
    exit;
}

$estados_validos = ['en_proceso', 'vigente', 'por_vencer', 'suspendido'];
if (!in_array($estado, $estados_validos)) $estado = 'en_proceso';

// Calcular fechas automáticamente si se provee fecha de emisión
$fecha_venc = null;
$fecha_aud1 = null;
$fecha_aud2 = null;
if (!empty($fecha_em)) {
    $fecha_venc = date('Y-m-d', strtotime($fecha_em . ' +3 years'));
    $fecha_aud1 = date('Y-m-d', strtotime($fecha_em . ' +1 year'));
    $fecha_aud2 = date('Y-m-d', strtotime($fecha_em . ' +2 years'));
}

$stmt = $pdo->prepare("
    INSERT INTO certificados
      (cliente_id, norma_iso_id, casa_certificadora_id, numero_certificado,
       fecha_emision, fecha_vencimiento, fecha_auditoria_1,
       fecha_auditoria_2, estado, creado_por)
    VALUES (?,?,?,?,?,?,?,?,?,?)
");
$stmt->execute([
    $cliente_id, $norma_iso_id, $casa_id,
    $numero ?: null,
    $fecha_em ?: null, $fecha_venc,
    $fecha_aud1, $fecha_aud2,
    $estado, $user['id'],
]);
$cert_id = (int)$pdo->lastInsertId();

// Crear auditorías automáticamente si hay fecha de emisión
if ($fecha_aud1) {
    $pdo->prepare("INSERT INTO auditorias (certificado_id, tipo, fecha_programada, registrado_por) VALUES (?,?,?,?)")
        ->execute([$cert_id, 'seguimiento_1', $fecha_aud1, $user['id']]);
}
if ($fecha_aud2) {
    $pdo->prepare("INSERT INTO auditorias (certificado_id, tipo, fecha_programada, registrado_por) VALUES (?,?,?,?)")
        ->execute([$cert_id, 'seguimiento_2', $fecha_aud2, $user['id']]);
}

log_actividad('certificados', 'crear', 'Certificado ID: ' . $cert_id . ' vía popup ficha cliente');

// ── Trigger de envío ──
$envio_auto_id = null;
if ($estado === 'vigente') {
    require_once __DIR__ . '/../envios/auto_generar.php';
    $envio_auto_id = verificar_envio_por_cotizacion($pdo, $cert_id, $user['id']);
    if ($envio_auto_id) {
        log_actividad('envios', 'auto_crear',
            "Envío #{$envio_auto_id} generado automáticamente desde certificado #{$cert_id} (popup ficha)");
    }
}

echo json_encode([
    'ok'       => true,
    'msg'      => 'Certificado registrado correctamente.',
    'cert_id'  => $cert_id,
    'envio_id' => $envio_auto_id,
]);
