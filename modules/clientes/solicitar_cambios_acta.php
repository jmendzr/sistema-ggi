<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';
require_once __DIR__ . '/../../core/PushService.php';
require_once __DIR__ . '/../../vendor/autoload.php';
session_init();
require_login();
require_role('ejecutivo_ventas', 'gerente_general', 'gerente_operaciones');

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']); exit;
}
if (empty($_POST['_csrf'])) {
    $_POST['_csrf'] = $_POST['csrf_token'] ?? '';
}
csrf_verify();

$doc_id = (int)($_POST['doc_id'] ?? 0);
$cambios_notas = trim($_POST['cambios_notas'] ?? '');

if (!$doc_id) {
    echo json_encode(['ok' => false, 'msg' => 'ID de documento inválido.']); exit;
}
if (empty($cambios_notas)) {
    echo json_encode(['ok' => false, 'msg' => 'Debe describir los cambios solicitados.']); exit;
}

$pdo = Database::get();
$user = auth_user();

// Verificar que el acta existe y está vigente
$doc = $pdo->prepare("
    SELECT d.id, d.cliente_id, d.cotizacion_id, d.version, d.confirmada,
           cl.razon_social, cl.ruc, cot.codigo AS cot_codigo
    FROM documentos_cliente d
    JOIN clientes cl ON cl.id = d.cliente_id
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

// Guardar cambios
try {
    $pdo->prepare("
        INSERT INTO acta_alcance_cambios (documento_id, cliente_id, cotizacion_id, cambios_notas, agregado_por)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$doc_id, $doc['cliente_id'], $doc['cotizacion_id'], $cambios_notas, $user['id']]);

    // Actualizar estado del acta a "pendiente_cambios"
    $pdo->prepare("
        UPDATE documentos_cliente
        SET estado_acta = 'pendiente_cambios'
        WHERE id = ?
    ")->execute([$doc_id]);

    // Log de actividad
    $version_pad = sprintf('%02d', $doc['version']);
    $roles_label = [
        'gerente_general'     => 'Gerente General',
        'gerente_operaciones' => 'Gerente de Operaciones',
        'ejecutivo_ventas'    => 'Ejecutivo de Ventas',
    ];
    // Nombre del actor (primer nombre + primer apellido) para las notificaciones del acta.
    $rol_label = trim((explode(' ', trim($user['nombre'] ?? ''))[0] ?? '') . ' ' . (explode(' ', trim($user['apellido'] ?? ''))[0] ?? '')) ?: ($roles_label[$user['rol']] ?? $user['rol']);

    log_actividad(
        'documentos', 'acta_alcance_cambios_solicitados',
        "{$rol_label} solicitó cambios al Acta de Alcance v{$version_pad} para {$doc['razon_social']} "
        . "— RUC: {$doc['ruc']}"
        // El detalle de los cambios queda en la tabla acta_alcance_cambios (consultable
        // en el acta), no se repite aquí para no inflar el historial.
    );

    // Notificar a gerentes via NotificationService (in-app + push + WhatsApp según reglas)
    $sent = NotificationService::dispatch('acta_alcance_cambios_solicitados', [
        'cliente_id'    => (int)$doc['cliente_id'],
        'cotizacion_id' => (int)$doc['cotizacion_id'],
        'triggered_by'  => $user['id'],
        'title'         => 'Cambios solicitados en Acta de Alcance — ' . $doc['razon_social'],
        'message'       => "{$rol_label} solicitó cambios al Acta de Alcance v{$version_pad} de {$doc['razon_social']} "
                         . "(Propuesta {$doc['cot_codigo']}). Revisa el detalle en el acta.",
    ]);

    // Fallback directo SOLO si el evento no está configurado (dispatch devolvió 0).
    // Antes corría siempre → cada gerente recibía DOS notificaciones (una del evento
    // y otra de aquí). Ahora se evita la duplicación.
    if ($sent === 0) {
        $gerentes = $pdo->prepare("
            SELECT id FROM usuarios
            WHERE rol IN ('gerente_general', 'gerente_operaciones') AND activo = 1 AND id != ?
        ");
        $gerentes->execute([$user['id']]);

        $notif_stmt = $pdo->prepare("
            INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, priority, reference_id, event_code)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($gerentes->fetchAll() as $gerente) {
            $titulo_notif = 'Acta de Alcance — ' . $doc['razon_social'];
            $mensaje_notif = 'Cliente solicitó cambios';

            $notif_stmt->execute([
                $gerente['id'],
                'acta_alcance_cambios_solicitados',
                $titulo_notif,
                $mensaje_notif,
                5,
                $doc['cliente_id'],
                'acta_alcance_cambios_solicitados'
            ]);

            // Enviar push notification
            $url_cliente = APP_URL . '/modules/clientes/ver.php?id=' . $doc['cliente_id'] . '#acta';
            PushService::notify(
                (int)$gerente['id'],
                $titulo_notif,
                $mensaje_notif,
                $url_cliente,
                'acta-cambios-' . $doc_id
            );
        }
    }

    echo json_encode([
        'ok'  => true,
        'msg' => "Cambios registrados. Se notificó a los gerentes para que regeneren el acta.",
    ]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
}
?>
