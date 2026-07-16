<?php
// portal/download_certs.php — Descarga ZIP con todos los certificados del cliente
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

$token = trim($_GET['token'] ?? '');
if (!$token) { http_response_code(400); exit('Token requerido'); }

try {
    $pdo  = Database::get();
    $stmt = $pdo->prepare("SELECT id, razon_social, ruc FROM clientes WHERE portal_token=? AND portal_activo=1 AND estado!='inactivo' AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$token]);
    $cliente = $stmt->fetch();
} catch (Exception $e) {
    http_response_code(500); exit('Error de base de datos');
}
if (!$cliente) { http_response_code(403); exit('Enlace no válido'); }

// Buscar certificados con archivos
$stmt2 = $pdo->prepare("
    SELECT c.certificado_archivo, c.certificado_archivo_en, n.codigo AS norma
    FROM certificados c
    JOIN normas_iso n ON n.id = c.norma_iso_id
    WHERE c.cliente_id = ?
      AND (c.certificado_archivo IS NOT NULL AND c.certificado_archivo != ''
           OR c.certificado_archivo_en IS NOT NULL AND c.certificado_archivo_en != '')
    ORDER BY n.codigo
");
$stmt2->execute([$cliente['id']]);
$certs = $stmt2->fetchAll();

if (empty($certs)) { http_response_code(404); exit('No hay certificados disponibles'); }

if (!class_exists('ZipArchive')) { http_response_code(500); exit('ZIP no disponible'); }

$ruc     = $cliente['ruc'] ?: $cliente['id'];
$zip     = new ZipArchive();
$tmpfile = tempnam(sys_get_temp_dir(), 'ggi_certs_');
if ($zip->open($tmpfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); exit('No se pudo crear el archivo ZIP');
}

foreach ($certs as $cert) {
    if (!empty($cert['certificado_archivo'])) {
        $path = UPLOAD_PATH . ltrim($cert['certificado_archivo'], '/');
        if (file_exists($path)) {
            $ext      = pathinfo($cert['certificado_archivo'], PATHINFO_EXTENSION);
            $norma    = preg_replace('/[^A-Z0-9]/', '', strtoupper($cert['norma']));
            $filename = $ruc . '_Certificado_' . $norma . '_ES.' . $ext;
            $zip->addFile($path, $filename);
        }
    }
    if (!empty($cert['certificado_archivo_en'])) {
        $path = UPLOAD_PATH . ltrim($cert['certificado_archivo_en'], '/');
        if (file_exists($path)) {
            $ext      = pathinfo($cert['certificado_archivo_en'], PATHINFO_EXTENSION);
            $norma    = preg_replace('/[^A-Z0-9]/', '', strtoupper($cert['norma']));
            $filename = $ruc . '_Certificate_' . $norma . '_EN.' . $ext;
            $zip->addFile($path, $filename);
        }
    }
}

if ($zip->count() === 0) {
    $zip->close();
    unlink($tmpfile);
    http_response_code(404); exit('No se encontraron archivos de certificados');
}

$zip->close();

$filename = 'Certificados_RUC_' . $ruc . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpfile));
header('Cache-Control: no-cache');
readfile($tmpfile);
unlink($tmpfile);
exit;
