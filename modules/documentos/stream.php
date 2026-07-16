<?php
/**
 * modules/documentos/stream.php
 * Sirve archivos de documentos_cliente de forma autenticada (inline).
 * El directorio físico está bloqueado por .htaccess; este script
 * lee el archivo desde el servidor y lo envía con el MIME correcto.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT dc.*, cl.ruc
    FROM documentos_cliente dc
    LEFT JOIN clientes cl ON cl.id = dc.cliente_id
    WHERE dc.id = ?
");
$stmt->execute([$id]);
$doc  = $stmt->fetch();
if (!$doc) { http_response_code(404); exit; }

$ruta_fisica = rtrim(UPLOAD_PATH, '/') . '/' . $doc['ruta_archivo'];
if (!file_exists($ruta_fisica)) { http_response_code(404); exit; }

$mime = $doc['mime_type'] ?: 'application/octet-stream';

// Nomenclatura: {RUC}_{nombre_original}
// Ej: 20615242463_ficharuc.pdf
$ruc_safe      = preg_replace('/[^0-9]/', '', $doc['ruc'] ?? '');
$tipo_safe    = preg_replace('/[^A-Za-z0-9_\-]/', '_', strtolower($doc['tipo_documento'] ?? 'documento'));
$ext_safe     = strtolower(pathinfo($doc['ruta_archivo'], PATHINFO_EXTENSION));
$nombre_final = ($ruc_safe ? $ruc_safe . '_' : '') . $tipo_safe . ($ext_safe ? '.' . $ext_safe : '');

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($nombre_final) . '"');
header('Content-Length: ' . filesize($ruta_fisica));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($ruta_fisica);
exit;
