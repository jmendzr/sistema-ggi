<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../config/config.php';
session_init();
require_login();

$id   = (int)($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? 'documentos';
if (!$id) { http_response_code(400); exit('ID inválido'); }

$pdo = Database::get();

$c = $pdo->prepare("SELECT razon_social, ruc FROM clientes WHERE id=?");
$c->execute([$id]);
$cliente = $c->fetch();
if (!$cliente) { http_response_code(404); exit('Cliente no encontrado'); }

$ruc = $cliente['ruc'] ?: $id;

/**
 * Convierte el código de norma ISO al formato usado en el nombre de archivo.
 * Ej: "ISO 9001:2015" → "ISO90012015"  /  "SA8000" → "SA8000"
 */
function sanitize_norma_codigo(string $codigo): string {
    return preg_replace('/[^A-Za-z0-9]/', '', $codigo);
}

$files    = [];
$zip_name = '';

// ── Documentos ──────────────────────────────────────────────────────────────
if ($tipo === 'documentos') {
    $stmt = $pdo->prepare("
        SELECT nombre_archivo, ruta_archivo, tipo_documento
        FROM documentos_cliente
        WHERE cliente_id = ? AND es_vigente = 1
        ORDER BY tipo_documento
    ");
    $stmt->execute([$id]);
    $nombres_usados = [];
    foreach ($stmt->fetchAll() as $d) {
        if (!$d['ruta_archivo']) continue;
        $full = UPLOAD_PATH . $d['ruta_archivo'];
        if (file_exists($full)) {
            $tipo = preg_replace('/[^A-Za-z0-9_\-]/', '_', strtolower($d['tipo_documento'] ?? 'documento'));
            $ext  = strtolower(pathinfo($d['ruta_archivo'], PATHINFO_EXTENSION));
            $base = $ruc . '_' . $tipo . ($ext ? '.' . $ext : '');
            // evitar colisión si hay dos documentos del mismo tipo
            $name = $base;
            $i    = 1;
            while (isset($nombres_usados[$name])) { $name = $ruc . '_' . $tipo . '_' . $i++ . ($ext ? '.' . $ext : ''); }
            $nombres_usados[$name] = true;
            $files[] = ['path' => $full, 'name' => $name];
        }
    }
    $zip_name = 'Documentos_RUC_' . $ruc . '.zip';
}

// ── Certificados ─────────────────────────────────────────────────────────────
else {
    $cert_ids_raw = $_GET['cert_ids'] ?? '';
    if ($cert_ids_raw) {
        $cert_ids = array_filter(array_map('intval', explode(',', $cert_ids_raw)));
    } else {
        $cert_ids = [];
    }

    if ($cert_ids) {
        $ph   = implode(',', array_fill(0, count($cert_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT ce.id, ce.certificado_archivo, ce.certificado_archivo_en, n.codigo
            FROM certificados ce
            JOIN normas_iso n ON n.id = ce.norma_iso_id
            WHERE ce.cliente_id = ? AND ce.id IN ($ph)
        ");
        $stmt->execute(array_merge([$id], $cert_ids));
    } else {
        $stmt = $pdo->prepare("
            SELECT ce.id, ce.certificado_archivo, ce.certificado_archivo_en, n.codigo
            FROM certificados ce
            JOIN normas_iso n ON n.id = ce.norma_iso_id
            WHERE ce.cliente_id = ?
        ");
        $stmt->execute([$id]);
    }

    foreach ($stmt->fetchAll() as $cert) {
        $codigo_clean = sanitize_norma_codigo($cert['codigo']);  // ej: ISO90012015

        // Certificado en Español
        if (!empty($cert['certificado_archivo'])) {
            $full = UPLOAD_PATH . $cert['certificado_archivo'];
            if (file_exists($full)) {
                $ext     = strtolower(pathinfo($full, PATHINFO_EXTENSION));
                $files[] = [
                    'path' => $full,
                    'name' => "{$ruc}_Certificado_{$codigo_clean}_ES.{$ext}",
                ];
            }
        }

        // Certificate in English
        if (!empty($cert['certificado_archivo_en'])) {
            $full = UPLOAD_PATH . $cert['certificado_archivo_en'];
            if (file_exists($full)) {
                $ext     = strtolower(pathinfo($full, PATHINFO_EXTENSION));
                $files[] = [
                    'path' => $full,
                    'name' => "{$ruc}_Certificate_{$codigo_clean}_EN.{$ext}",
                ];
            }
        }
    }

    $zip_name = 'Certificados_RUC_' . $ruc . '.zip';
}

// ── Sin archivos ─────────────────────────────────────────────────────────────
if (empty($files)) {
    http_response_code(404);
    echo '<script>alert("No hay archivos disponibles para descargar."); history.back();</script>';
    exit;
}

// ── Un solo archivo: descarga directa ────────────────────────────────────────
if (count($files) === 1) {
    $f = $files[0];
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
    header('Content-Length: ' . filesize($f['path']));
    readfile($f['path']);
    exit;
}

// ── Múltiples archivos: ZIP ───────────────────────────────────────────────────
$tmp = tempnam(sys_get_temp_dir(), 'ggi_zip_');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Error creando ZIP');
}
foreach ($files as $f) {
    $zip->addFile($f['path'], $f['name']);
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_name . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-cache');
readfile($tmp);
unlink($tmp);
exit;
