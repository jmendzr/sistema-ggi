<?php
/**
 * ver_voucher.php — Sirve el archivo de voucher de pago
 * Solo usuarios con permiso pueden verlo.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$pago_id = (int)($_GET['id'] ?? 0);
if (!$pago_id) { http_response_code(404); exit('No encontrado'); }

$pdo = Database::get();
$stmt = $pdo->prepare("
    SELECT p.*, cl.ruc
    FROM pagos_cliente p
    LEFT JOIN clientes cl ON cl.id = p.cliente_id
    WHERE p.id = ?
");
$stmt->execute([$pago_id]);
$pago = $stmt->fetch();

if (!$pago || !$pago['voucher_path']) { http_response_code(404); exit('Voucher no disponible'); }
if (!file_exists($pago['voucher_path'])) { http_response_code(404); exit('Archivo no encontrado en servidor'); }

// Verificar que el usuario tenga acceso (gerente o ejecutivo del cliente)
$user = auth_user();
if ($user['rol'] !== 'gerente_general' && $user['rol'] !== 'gerente_operaciones' && $user['rol'] !== 'administracion_finanzas') {
    // Ejecutivo: solo puede ver si es ejecutivo asignado al cliente
    $cli = $pdo->prepare("SELECT ejecutivo_id FROM clientes WHERE id = ?");
    $cli->execute([$pago['cliente_id']]);
    $c = $cli->fetch();
    if (!$c || (int)$c['ejecutivo_id'] !== (int)$user['id']) {
        http_response_code(403); exit('Sin permiso');
    }
}

$ext  = strtolower(pathinfo($pago['voucher_path'], PATHINFO_EXTENSION));
$mime_map = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

// Nomenclatura: {RUC}_voucher_{pago_id}.{ext}
// Ej: 20615242463_voucher_45.pdf
$ruc_safe    = preg_replace('/[^0-9]/', '', $pago['ruc'] ?? '');
$nombre_base = $pago['voucher_nombre'] ?: ('voucher_' . $pago_id . '.' . $ext);
$nombre_safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $nombre_base);
$nombre_final = $ruc_safe ? $ruc_safe . '_' . $nombre_safe : $nombre_safe;

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $nombre_final . '"');
header('Content-Length: ' . filesize($pago['voucher_path']));
header('Cache-Control: private, max-age=3600');
readfile($pago['voucher_path']);
exit;
