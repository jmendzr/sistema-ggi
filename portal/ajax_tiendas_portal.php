<?php
/**
 * portal/ajax_tiendas_portal.php
 * Lista de puntos de recojo Olva (tiendas/oficinas/agentes) del distrito,
 * para el portal de clientes (auth por token, sin sesión).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/OlvaSedesService.php';

header('Content-Type: application/json; charset=utf-8');

$token  = trim($_GET['token'] ?? '');
$ubigeo = preg_replace('/\D/', '', $_GET['ubigeo'] ?? '');
if ($token === '' || $ubigeo === '') { echo json_encode(['tiendas' => []]); exit; }

// Validar token
$pdo  = Database::get();
$stmt = $pdo->prepare("SELECT id FROM clientes WHERE portal_token=? AND portal_activo=1 AND estado!='inactivo' AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$token]);
if (!$stmt->fetchColumn()) { echo json_encode(['tiendas' => []]); exit; }

echo json_encode(['tiendas' => OlvaSedesService::tiendasPorDistrito($ubigeo)], JSON_UNESCAPED_UNICODE);
