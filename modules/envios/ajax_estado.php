<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';
require_once __DIR__ . '/../../core/envio_notificar.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación
// Ventas no puede cambiar el estado de envíos; lo gestionan operaciones y logística.
require_role('gerente_general','gerente_operaciones','gestor_logistico','delivery');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }

$estado_id  = (int)($_POST['estado_id'] ?? 0);
$envio_id   = (int)($_POST['envio_id']  ?? 0);  // individual
$envio_ids_raw = trim($_POST['envio_ids'] ?? ''); // bulk

if (!$estado_id) { echo json_encode(['ok'=>false,'msg'=>'Estado inválido']); exit; }

$pdo = Database::get();

// Verificar que el estado existe y está activo
$est = $pdo->prepare("SELECT id, nombre FROM estados_envio WHERE id=? AND activo=1");
$est->execute([$estado_id]);
$estado_row = $est->fetch();
if (!$estado_row) { echo json_encode(['ok'=>false,'msg'=>'Estado inválido']); exit; }

$nombre_estado = strtolower($estado_row['nombre']);
$es_entregado  = str_contains($nombre_estado, 'entregado');
$es_no_enviado = gg_es_estado_no_enviado($estado_row['nombre']);

// "No Enviado" exige motivo estructurado (así el equipo sabe POR QUÉ no salió,
// típicamente porque el cliente nunca compartió sus datos).
$motivo         = trim($_POST['motivo_no_envio'] ?? '');
$motivo_detalle = trim($_POST['motivo_no_envio_detalle'] ?? '');
if ($es_no_enviado) {
    if (!isset(gg_motivos_no_envio()[$motivo])) {
        echo json_encode(['ok'=>false,'msg'=>'Indica el motivo por el que no se envió.','requiere_motivo'=>true]); exit;
    }
    if ($motivo === 'otro' && $motivo_detalle === '') {
        echo json_encode(['ok'=>false,'msg'=>'Describe el motivo en el detalle.','requiere_motivo'=>true]); exit;
    }
    if (mb_strlen($motivo_detalle) > 500) $motivo_detalle = mb_substr($motivo_detalle, 0, 500);
}

// Construir lista de IDs
if ($envio_ids_raw) {
    $ids = array_filter(array_map('intval', explode(',', $envio_ids_raw)));
} elseif ($envio_id) {
    $ids = [$envio_id];
} else {
    echo json_encode(['ok'=>false,'msg'=>'Sin envíos']); exit;
}

if (empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'Sin envíos válidos']); exit; }

// El estado de couriers con tracking (Olva/Shalom/Serpost) lo maneja la sincronización
// automática; el cambio manual solo se permite para agencias sin integración.
$ph_chk = implode(',', array_fill(0, count($ids), '?'));
$chk = $pdo->prepare("
    SELECT e.id, ag.nombre AS agencia_nombre
    FROM envios e LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE e.id IN ($ph_chk)
");
$chk->execute($ids);
$bloqueados = 0;
$ids_ok = [];
foreach ($chk->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    if (gg_courier_con_tracking($r['agencia_nombre'])) $bloqueados++;
    else $ids_ok[] = (int)$r['id'];
}
if (empty($ids_ok)) {
    echo json_encode(['ok'=>false,'msg'=>'El estado de envíos Olva, Shalom y Serpost se actualiza automáticamente por su tracking; no puede cambiarse manualmente.']);
    exit;
}
$ids = $ids_ok;

$placeholders = implode(',', array_fill(0, count($ids), '?'));

if ($es_no_enviado) {
    // Guardar motivo junto con el estado
    $params = array_merge([$estado_id, $motivo, ($motivo_detalle !== '' ? $motivo_detalle : null)], $ids);
    $pdo->prepare("UPDATE envios SET estado_envio_id=?, motivo_no_envio=?, motivo_no_envio_detalle=? WHERE id IN ($placeholders)")->execute($params);
} else {
    // Al salir de "No Enviado" el motivo deja de aplicar → limpiar
    $params = array_merge([$estado_id], $ids);
    $pdo->prepare("UPDATE envios SET estado_envio_id=?, motivo_no_envio=NULL, motivo_no_envio_detalle=NULL WHERE id IN ($placeholders)")->execute($params);
}

if ($es_entregado) {
    $pdo->prepare("UPDATE envios SET fecha_recibido = COALESCE(fecha_recibido, CURDATE()) WHERE id IN ($placeholders)")->execute($ids);
}

// Notificar al cliente el cambio de estado (mapeo id→evento en el helper central)
$uid = auth_user()['id'] ?? null;
foreach ($ids as $eid) {
    gg_envio_notificar_estado($pdo, (int)$eid, $estado_id, $uid);
}

log_actividad('envios', 'estado', 'Envíos ['.implode(',',$ids).'] → estado ID: ' . $estado_id
    . ($es_no_enviado ? ' · motivo: ' . $motivo . ($motivo_detalle !== '' ? " ($motivo_detalle)" : '') : ''));
echo json_encode(['ok'=>true, 'count'=>count($ids), 'bloqueados'=>$bloqueados]);
