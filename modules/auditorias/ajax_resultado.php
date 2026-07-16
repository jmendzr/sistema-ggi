<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }
csrf_verify();

$aud_id    = (int)($_POST['aud_id'] ?? 0);
$resultado = trim($_POST['resultado'] ?? '');
$validos   = ['pendiente','aprobado','con_observaciones','no_conforme','reprogramado'];

if (!$aud_id || !in_array($resultado, $validos)) {
    echo json_encode(['ok'=>false,'msg'=>'Datos inválidos']); exit;
}

$pdo = Database::get();
$stmt = $pdo->prepare("SELECT id FROM auditorias WHERE id=?");
$stmt->execute([$aud_id]);
if (!$stmt->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Auditoría no encontrada']); exit; }

$pdo->prepare("UPDATE auditorias SET resultado=? WHERE id=?")
    ->execute([$resultado, $aud_id]);

log_actividad('auditorias','resultado',"Auditoría #$aud_id → $resultado");
echo json_encode(['ok'=>true, 'resultado'=>$resultado]);
