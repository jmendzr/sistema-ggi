<?php
/**
 * Eliminación lógica de envíos (soft delete via deleted_at).
 * NO restringe por estado — evalúa dependencias e informa al usuario.
 * POST preview=1  → devuelve info de dependencias sin eliminar
 * POST preview=0  → elimina definitivamente
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json');

if (!has_role('gerente_general', 'gerente_operaciones')) {
    echo json_encode(['ok' => false, 'msg' => 'Sin permiso para eliminar envíos.']);
    exit;
}

csrf_verify();

$pdo      = Database::get();
$envio_id = (int)($_POST['envio_id'] ?? 0);
$preview  = (int)($_POST['preview']  ?? 0);
$user     = auth_user();

if (!$envio_id) {
    echo json_encode(['ok' => false, 'msg' => 'ID de envío inválido.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT e.*, cl.razon_social, es.nombre AS estado_nombre
    FROM envios e
    LEFT JOIN clientes cl ON cl.id = e.cliente_id
    JOIN estados_envio es ON es.id = e.estado_envio_id
    WHERE e.id = ? AND e.deleted_at IS NULL
");
$stmt->execute([$envio_id]);
$envio = $stmt->fetch();

if (!$envio) {
    echo json_encode(['ok' => false, 'msg' => 'Envío no encontrado o ya fue eliminado.']);
    exit;
}

// Dependencias informativas (no bloquean)
$certs_vinculados = (int)$pdo->prepare("SELECT COUNT(*) FROM envios_certificados WHERE envio_id = ?")
    ->execute([$envio_id]) ? $pdo->query("SELECT COUNT(*) FROM envios_certificados WHERE envio_id=$envio_id")->fetchColumn() : 0;
$ec = $pdo->prepare("SELECT COUNT(*) FROM envios_certificados WHERE envio_id = ?");
$ec->execute([$envio_id]);
$certs_vinculados = (int)$ec->fetchColumn();

if ($preview) {
    // Solo devolver info de dependencias
    $deps = [];
    if ($certs_vinculados) $deps[] = "$certs_vinculados certificado(s) vinculado(s)";
    echo json_encode([
        'ok'       => true,
        'preview'  => true,
        'codigo'   => $envio['codigo_envio'] ?: "Envío #{$envio_id}",
        'estado'   => $envio['estado_nombre'],
        'empresa'  => $envio['razon_social'] ?? '—',
        'deps'     => $deps,
        'hay_deps' => !empty($deps),
    ]);
    exit;
}

// Eliminación lógica
$pdo->prepare("
    UPDATE envios SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
")->execute([$user['id'], $envio_id]);

log_actividad('envios', 'eliminar',
    "Envío #{$envio_id} eliminado (estado: {$envio['estado_nombre']}, código: " . ($envio['codigo_envio'] ?: 'sin código') . ", empresa: " . ($envio['razon_social'] ?? '—') . ")");

echo json_encode(['ok' => true, 'msg' => 'Envío eliminado correctamente.']);
