<?php
/**
 * Eliminación lógica de auditorías (soft delete via deleted_at).
 * NO elimina físicamente — el registro se conserva en la BD con trazabilidad.
 * POST: auditoria_id
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json');

if (!has_role('gerente_general', 'gerente_operaciones')) {
    echo json_encode(['ok' => false, 'msg' => 'Sin permiso para eliminar auditorías.']);
    exit;
}

csrf_verify();

$pdo          = Database::get();
$auditoria_id = (int)($_POST['auditoria_id'] ?? 0);
$user         = auth_user();

if (!$auditoria_id) {
    echo json_encode(['ok' => false, 'msg' => 'ID de auditoría inválido.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM auditorias WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$auditoria_id]);
$aud = $stmt->fetch();

if (!$aud) {
    echo json_encode(['ok' => false, 'msg' => 'Auditoría no encontrada o ya fue eliminada.']);
    exit;
}

// Auditorías aprobadas con informe: requerir permiso de gerente_general
if ($aud['resultado'] === 'aprobado' && !has_role('gerente_general')) {
    echo json_encode([
        'ok'  => false,
        'msg' => 'Las auditorías aprobadas solo pueden ser eliminadas por el Gerente General.',
    ]);
    exit;
}

// Soft delete — NO se elimina físicamente ni se toca el archivo en disco
$pdo->prepare("
    UPDATE auditorias
    SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
")->execute([$user['id'], $auditoria_id]);

log_actividad('auditorias', 'eliminar',
    "Auditoría #$auditoria_id eliminada (certificado #{$aud['certificado_id']}, resultado: {$aud['resultado']})");

echo json_encode([
    'ok'  => true,
    'msg' => 'Auditoría eliminada correctamente.',
]);
