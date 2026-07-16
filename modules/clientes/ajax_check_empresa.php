<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación
header('Content-Type: application/json');

$ruc    = trim($_GET['ruc'] ?? '');
$nombre = trim($_GET['nombre'] ?? '');

if (!$ruc && !$nombre) {
    echo json_encode(['existe' => false]); exit;
}

$pdo = Database::get();

if ($ruc && preg_match('/^\d{11}$/', $ruc)) {
    $st = $pdo->prepare("SELECT id, razon_social, estado FROM clientes WHERE ruc = ? LIMIT 1");
    $st->execute([$ruc]);
    if ($row = $st->fetch()) {
        echo json_encode(['existe' => true, 'razon_social' => $row['razon_social'], 'estado' => $row['estado'], 'id' => $row['id']]);
        exit;
    }
}

if ($nombre) {
    $st = $pdo->prepare("SELECT id, razon_social, estado FROM clientes WHERE razon_social = ? LIMIT 1");
    $st->execute([$nombre]);
    if ($row = $st->fetch()) {
        echo json_encode(['existe' => true, 'razon_social' => $row['razon_social'], 'estado' => $row['estado'], 'id' => $row['id']]);
        exit;
    }
}

echo json_encode(['existe' => false]);
