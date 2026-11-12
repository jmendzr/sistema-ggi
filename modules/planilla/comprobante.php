<?php
/**
 * modules/planilla/comprobante.php — Sirve el comprobante de un pago de planilla.
 * Acceso restringido: gerencia general + Administración y Finanzas.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general');
if (!defined('MODULO_PLANILLA_ACTIVO') || !MODULO_PLANILLA_ACTIVO) {
    http_response_code(403); exit('Módulo de Planilla desactivado.');
}

$id = (int)($_GET['id'] ?? 0);
$pdo = Database::get();
$q = $pdo->prepare("SELECT comprobante_path FROM planilla WHERE id = ?");
$q->execute([$id]);
$path = $q->fetchColumn();

// Debe existir y estar dentro de storage/planilla (evita path traversal)
$base = realpath(__DIR__ . '/../../storage/planilla');
$real = $path ? realpath($path) : false;
if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) {
    http_response_code(404); exit('Comprobante no encontrado');
}

$mime = mime_content_type($real) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: inline; filename="comprobante_planilla_' . $id . '.' . pathinfo($real, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($real);
