<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';
require_once __DIR__ . '/../../core/PushService.php';
require_once __DIR__ . '/../../vendor/autoload.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas','gerente_comercial');

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']); exit;
}
csrf_verify();

$doc_id = (int)($_POST['doc_id'] ?? 0);
if (!$doc_id) { echo json_encode(['ok' => false, 'msg' => 'ID de documento inválido.']); exit; }

$pdo = Database::get();

$doc = $pdo->prepare("
    SELECT d.id, d.cliente_id, d.cotizacion_id, d.version, d.confirmada,
           d.nombre_archivo, cl.razon_social, cl.ruc, cot.codigo AS cot_codigo
    FROM documentos_cliente d
    JOIN clientes cl      ON cl.id  = d.cliente_id
    JOIN cotizaciones cot ON cot.id = d.cotizacion_id
    WHERE d.id = ? AND d.tipo_documento = 'acta_alcance' AND d.es_vigente = 1
    LIMIT 1
");
$doc->execute([$doc_id]);
$doc = $doc->fetch();

if (!$doc) {
    echo json_encode(['ok' => false, 'msg' => 'Documento no encontrado o no está vigente.']); exit;
}
if ($doc['confirmada']) {
    echo json_encode(['ok' => false, 'msg' => 'Este acta ya está confirmada.']); exit;
}

$user = auth_user();

// Validar que ejecutivo sea el responsable ACTUAL del cliente.
// Se usa el mismo fallback que el resto del sistema: responsible_user_id es el
// campo canónico, pero cuando está NULL (clientes existentes que nunca tuvieron
// transferencia explícita) se considera ejecutivo_id como responsable legado.
if ($user['rol'] === 'ejecutivo_ventas') {
    $cliente = $pdo->prepare("SELECT COALESCE(responsible_user_id, ejecutivo_id) AS responsable FROM clientes WHERE id = ?");
    $cliente->execute([$doc['cliente_id']]);
    $cliente = $cliente->fetch();

    if (!$cliente || $user['id'] != $cliente['responsable']) {
        echo json_encode(['ok' => false, 'msg' => 'Solo el ejecutivo responsable puede aprobar.']); exit;
    }
}

// Marcar acta como confirmada
$pdo->prepare("
    UPDATE documentos_cliente
    SET confirmada = 1, confirmada_por = ?, confirmada_at = NOW(), estado_acta = 'confirmada'
    WHERE id = ?
")->execute([$user['id'], $doc_id]);

$version_pad = sprintf('%02d', $doc['version']);
$roles_label = [
    'gerente_general'     => 'Gerente General',
    'gerente_operaciones' => 'Gerente de Operaciones',
    'ejecutivo_ventas'    => 'Ejecutivo de Ventas',
];
// Nombre del actor (primer nombre + primer apellido) para las notificaciones del acta.
$rol_label = trim((explode(' ', trim($user['nombre'] ?? ''))[0] ?? '') . ' ' . (explode(' ', trim($user['apellido'] ?? ''))[0] ?? '')) ?: ($roles_label[$user['rol']] ?? $user['rol']);

log_actividad(
    'documentos', 'acta_alcance_confirmada',
    "{$rol_label} confirmó Acta de Aprobación de Alcance v{$version_pad} para {$doc['razon_social']} "
    . "— RUC: {$doc['ruc']} — Propuesta: {$doc['cot_codigo']}"
);

// ── Responder al navegador YA ──────────────────────────────────────────────
// El trabajo pesado (push web, que abre conexiones HTTPS a cada dispositivo
// suscrito) se realiza DESPUÉS de cerrar la respuesta para que la aprobación
// no quede bloqueada esperando a endpoints lentos o muertos.
echo json_encode([
    'ok'  => true,
    'msg' => "Acta v{$version_pad} confirmada y bloqueada correctamente.",
]);

// Liberar el lock de sesión y entregar la respuesta sin esperar al post-proceso.
if (function_exists('session_write_close')) session_write_close();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Fallback para SAPIs sin PHP-FPM: vaciar buffers para entregar ya.
    while (ob_get_level() > 0) { ob_end_flush(); }
    flush();
}

// ── A partir de aquí el cliente ya recibió su respuesta ────────────────────
// Una sola vía de notificación: el evento 'acta_alcance_aprobada' ya está
// configurado en notification_rules para gerente_general y gerente_operaciones
// (via_interna + via_push), por lo que dispatch() cubre el aviso completo.
// Se elimina el bucle manual que duplicaba notificación interna y push.
NotificationService::dispatch('acta_alcance_aprobada', [
    'cliente_id'    => (int)$doc['cliente_id'],
    'cotizacion_id' => (int)$doc['cotizacion_id'],
    'triggered_by'  => $user['id'],
    'title'         => 'Acta de Alcance aprobada — ' . $doc['razon_social'],
    'message'       => "{$rol_label} aprobó el Acta de Alcance v{$version_pad} de {$doc['razon_social']} "
                     . "(Propuesta {$doc['cot_codigo']}).",
    'url'           => APP_URL . '/modules/clientes/ver.php?id=' . (int)$doc['cliente_id'] . '#acta',
]);

// Al aprobar el Acta, verificar si la Ficha RUC ya está cargada → documentación completa.
require_once __DIR__ . '/../../core/doc_completa.php';
gg_verificar_documentacion_completa($pdo, (int)$doc['cliente_id'], (int)$user['id']);
