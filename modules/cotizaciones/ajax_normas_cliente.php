<?php
/**
 * Devuelve los IDs de normas ISO que el cliente ya tiene certificadas (activas).
 * Usado en crear/editar cotización para deshabilitar normas ya certificadas.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$cliente_id = (int)($_GET['cliente_id'] ?? 0);
if (!$cliente_id) {
    echo json_encode(['ok' => false, 'normas' => []]);
    exit;
}

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT DISTINCT norma_iso_id
    FROM certificados
    WHERE cliente_id = ?
      AND estado NOT IN ('vencido', 'suspendido', 'rechazado')
");
$stmt->execute([$cliente_id]);
$normas = $stmt->fetchAll(\PDO::FETCH_COLUMN);

echo json_encode(['ok' => true, 'normas' => array_map('intval', $normas)]);
