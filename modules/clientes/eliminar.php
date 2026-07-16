<?php
/**
 * Eliminación lógica de clientes (soft delete via deleted_at).
 * Reemplaza la eliminación física legacy. NO borra el registro de la BD.
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
    header('Location: ' . APP_URL . '/modules/clientes/index.php');
    exit;
}

$pdo  = Database::get();
$stmt = $pdo->prepare("SELECT razon_social FROM clientes WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    flash('Empresa no encontrada o ya fue eliminada.', 'error');
    header('Location: ' . APP_URL . '/modules/clientes/index.php');
    exit;
}

// Soft delete — los registros relacionados (certs, propuestas, envíos) quedan intactos
$pdo->prepare("
    UPDATE clientes SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
")->execute([$user['id'], $id]);

log_actividad('clientes', 'eliminar', 'Empresa eliminada (soft delete): ' . $cliente['razon_social']);

flash('Empresa eliminada exitosamente.', 'ok');
header('Location: ' . APP_URL . '/modules/clientes/index.php');
exit;
