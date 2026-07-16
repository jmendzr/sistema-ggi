<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

header('Content-Type: application/json; charset=utf-8');

$doc_id = (int)($_GET['doc_id'] ?? 0);
if (!$doc_id) {
    echo json_encode(['ok' => false, 'cambios' => '']); exit;
}

$pdo = Database::get();

// Get the latest changes for this document
$stmt = $pdo->prepare("
    SELECT cambios_notas FROM acta_alcance_cambios
    WHERE documento_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$doc_id]);
$result = $stmt->fetch();

echo json_encode([
    'ok' => true,
    'cambios' => $result ? $result['cambios_notas'] : ''
]);
?>
