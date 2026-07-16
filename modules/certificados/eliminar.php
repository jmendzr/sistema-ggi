<?php
/**
 * Eliminación lógica de certificados (soft delete via deleted_at).
 * Reemplaza la eliminación física legacy. NO borra el registro de la BD
 * ni los archivos del disco (se conservan como respaldo histórico).
 * Redirige a index con mensaje de confirmación.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');
csrf_verify();

$id   = (int)($_POST['id'] ?? 0);
$user = auth_user();

if (!$id) {
    header('Location: ' . APP_URL . '/modules/certificados/index.php');
    exit;
}

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT c.*, cl.ruc FROM certificados c
    JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.id = ? AND c.deleted_at IS NULL
");
$stmt->execute([$id]);
$cert = $stmt->fetch();

if (!$cert) {
    flash('Certificado no encontrado o ya fue eliminado.', 'error');
    header('Location: ' . APP_URL . '/modules/certificados/index.php');
    exit;
}

// Soft delete — los archivos físicos permanecen como respaldo histórico
$pdo->prepare("
    UPDATE certificados SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
")->execute([$user['id'], $id]);

log_actividad('certificados', 'eliminar', 'Certificado eliminado (soft delete) ID: ' . $id . ' — RUC: ' . $cert['ruc']);

header('Location: ' . APP_URL . '/modules/certificados/index.php?msg=' . urlencode('Certificado eliminado exitosamente.'));
exit;
