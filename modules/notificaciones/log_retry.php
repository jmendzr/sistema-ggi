<?php
/**
 * modules/notificaciones/log_retry.php
 * Reintenta el envío de una notificación WhatsApp fallida a partir de su registro
 * en whatsapp_log. Usa el contexto guardado (context_json) para reproducir el mensaje
 * con fidelidad (normas, documento adjunto, token del portal, etc.).
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');
csrf_verify();

header('Content-Type: application/json');

$pdo    = Database::get();
$log_id = (int)($_POST['id'] ?? 0);

if (!$log_id) {
    echo json_encode(['ok' => false, 'msg' => 'ID inválido.', 'csrf' => csrf_token()]);
    exit;
}

$st = $pdo->prepare("SELECT * FROM whatsapp_log WHERE id = ?");
$st->execute([$log_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Registro no encontrado.', 'csrf' => csrf_token()]);
    exit;
}
if (empty($row['cliente_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'El registro no tiene cliente asociado; no se puede reintentar.', 'csrf' => csrf_token()]);
    exit;
}

// Contexto original (si se guardó). Para registros antiguos sin contexto, se reintenta
// con lo mínimo (razón social se reconstruye sola; otras variables podrían ir vacías).
$context = [];
if (!empty($row['context_json'])) {
    $context = json_decode($row['context_json'], true) ?: [];
}

// Reintentar: notifyClient vuelve a resolver plantilla/idioma actuales y registra un
// NUEVO renglón en whatsapp_log con el resultado.
WhatsAppService::notifyClient((int)$row['cliente_id'], $row['event_code'], $context);

// Leer el resultado del último intento para este cliente+evento
$res = $pdo->prepare("SELECT estado, error_msg FROM whatsapp_log WHERE cliente_id = ? AND event_code = ? ORDER BY id DESC LIMIT 1");
$res->execute([(int)$row['cliente_id'], $row['event_code']]);
$last = $res->fetch(PDO::FETCH_ASSOC) ?: ['estado' => 'failed', 'error_msg' => 'Sin resultado'];

$ok = ($last['estado'] === 'sent');
echo json_encode([
    'ok'     => $ok,
    'estado' => $last['estado'],
    'msg'    => $ok ? 'Reenviado correctamente.' : ('No se pudo reenviar: ' . ($last['error_msg'] ?: 'error desconocido')),
    'csrf'   => csrf_token(),
]);
