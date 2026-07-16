<?php
/**
 * modules/certificados/stream.php
 * Sirve archivos de certificados de forma autenticada (inline o descarga).
 * Requiere sesión activa; evita depender del .htaccess para el acceso.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id   = (int)($_GET['id']   ?? 0);
$lang = $_GET['lang']        ?? 'es';   // 'es' | 'en'
$dl   = isset($_GET['descargar']);       // descarga forzada

if (!$id || !in_array($lang, ['es', 'en'])) { http_response_code(404); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT ce.*, cl.ruc, cl.razon_social,
           no.codigo AS norma_codigo
    FROM certificados ce
    LEFT JOIN clientes cl ON cl.id = ce.cliente_id
    LEFT JOIN normas_iso no ON no.id = ce.norma_iso_id
    WHERE ce.id = ?
");
$stmt->execute([$id]);
$cert = $stmt->fetch();
if (!$cert) { http_response_code(404); exit; }

$campo = $lang === 'en' ? 'certificado_archivo_en' : 'certificado_archivo';
$ruta_relativa = $cert[$campo] ?? '';
if (!$ruta_relativa) { http_response_code(404); exit; }

$ruta_fisica = rtrim(UPLOAD_PATH, '/') . '/' . $ruta_relativa;
if (!file_exists($ruta_fisica)) { http_response_code(404); exit; }

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($ruta_fisica) ?: 'application/octet-stream';
$ext   = strtolower(pathinfo($ruta_fisica, PATHINFO_EXTENSION));

// Nomenclatura: {RUC}_{NormaCodigo}_{lang}.{ext}
// Ej: 20615242463_ISO90012015_ES.pdf
$ruc_safe    = preg_replace('/[^0-9]/', '', $cert['ruc'] ?? '');
$norma_safe  = preg_replace('/[^A-Za-z0-9]/', '', $cert['norma_codigo'] ?? '');
$lang_upper  = strtoupper($lang);
if ($ruc_safe && $norma_safe) {
    $nombre = $ruc_safe . '_' . $norma_safe . '_' . $lang_upper . ($ext ? '.' . $ext : '');
} else {
    $nombre = basename($ruta_relativa); // fallback si no hay RUC
}

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . '; filename="' . rawurlencode($nombre) . '"');
header('Content-Length: ' . filesize($ruta_fisica));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($ruta_fisica);
exit;
