<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación

header('Content-Type: application/json; charset=utf-8');

$tabla = $_GET['tabla'] ?? '';
$id    = (int)($_GET['id'] ?? 0);

$tablas_validas = ['normas_iso','casas_certificadoras','agencias_envio','estados_envio','usuarios'];
if (!in_array($tabla, $tablas_validas) || !$id) {
    json_error('Parámetros inválidos.');
}

$pdo  = Database::get();
$stmt = $pdo->prepare("SELECT * FROM `$tabla` WHERE id = ?");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row) json_error('Registro no encontrado.');

// No exponer password_hash
unset($row['password_hash']);

json_ok($row);
