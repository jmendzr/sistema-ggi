<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode([]); exit; }

$pdo  = Database::get();
$ag   = $pdo->prepare("SELECT nombre, sede_modo FROM agencias_envio WHERE id=?");
$ag->execute([$id]);
$row  = $ag->fetch();

// Config desde el helper compartido (misma lógica que el render de <option> en crear/editar)
echo json_encode(ggi_agencia_envio_config($row['nombre'] ?? '', $row['sede_modo'] ?? 'manual'));
