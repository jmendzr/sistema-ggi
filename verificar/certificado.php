<?php
/**
 * verificar/certificado.php
 * Gateway PÚBLICO (sin login) que sirve el PDF de un certificado EMITIDO.
 * Solo entrega certificados no eliminados y en estado distinto de "en_proceso"
 * (los borradores/en proceso no se exponen). Sirve inline por defecto.
 *
 *   /verificar/certificado.php?id=123&lang=es|en[&descargar=1]
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

$id   = (int)($_GET['id'] ?? 0);
$lang = ($_GET['lang'] ?? 'es') === 'en' ? 'en' : 'es';
$dl   = isset($_GET['descargar']);

if (!$id) { http_response_code(404); exit('Certificado no encontrado.'); }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT ce.certificado_archivo, ce.certificado_archivo_en, ce.estado,
           ce.numero_certificado, ce.created_at, ce.cotizacion_id, cl.ruc, no.codigo AS norma_codigo,
           -- pago_total = tiene cotización con al menos un pago y todos validados
           (ce.cotizacion_id > 0
             AND EXISTS (SELECT 1 FROM pagos_cliente pc
                         WHERE pc.cotizacion_id = ce.cotizacion_id AND pc.deleted_at IS NULL)
             AND NOT EXISTS (SELECT 1 FROM pagos_cliente pc
                             WHERE pc.cotizacion_id = ce.cotizacion_id AND pc.deleted_at IS NULL
                               AND pc.estado <> 'validado')
           ) AS pago_total
    FROM certificados ce
    LEFT JOIN clientes cl   ON cl.id = ce.cliente_id
    LEFT JOIN normas_iso no ON no.id = ce.norma_iso_id
    WHERE ce.id = ?
      AND ce.deleted_at IS NULL
      AND ce.estado <> 'en_proceso'
      AND ce.numero_certificado IS NOT NULL AND ce.numero_certificado <> ''
");
$stmt->execute([$id]);
$cert = $stmt->fetch();
if (!$cert) { http_response_code(404); exit('Certificado no disponible.'); }

// Habilitación por pago total: los certificados creados desde el corte solo se
// entregan públicamente si el cliente pagó el TOTAL (mismo criterio que el verificador).
// Excepción: los certificados emitidos SIN cotización (standalone/manual, p. ej. BPL)
// no pasan por el flujo de venta → no se les aplica el candado de pago.
if (($cert['created_at'] ?? '') >= GGI_GATE_PAGO_TOTAL_DESDE && !empty($cert['cotizacion_id']) && empty($cert['pago_total'])) {
    http_response_code(404);
    exit('Certificado no disponible.');
}

// BPL solo se emite en español → no se entrega versión en inglés.
if ($lang === 'en' && strtoupper((string)($cert['norma_codigo'] ?? '')) === 'BPL') {
    http_response_code(404);
    exit('Certificado no disponible.');
}

$ruta_relativa = ($lang === 'en' ? $cert['certificado_archivo_en'] : $cert['certificado_archivo']) ?: '';
if (!$ruta_relativa) { http_response_code(404); exit('Archivo no disponible.'); }

$ruta_fisica = rtrim(UPLOAD_PATH, '/') . '/' . $ruta_relativa;
if (!is_file($ruta_fisica)) { http_response_code(404); exit('Archivo no disponible.'); }

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta_fisica) ?: 'application/pdf';
$ext  = strtolower(pathinfo($ruta_fisica, PATHINFO_EXTENSION));
$ruc  = preg_replace('/[^0-9]/', '', $cert['ruc'] ?? '');
$norma= preg_replace('/[^A-Za-z0-9]/', '', $cert['norma_codigo'] ?? '');
$nombre = ($ruc && $norma) ? "{$ruc}_{$norma}_" . strtoupper($lang) . ($ext ? ".$ext" : '') : basename($ruta_relativa);

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . '; filename="' . rawurlencode($nombre) . '"');
header('Content-Length: ' . filesize($ruta_fisica));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($ruta_fisica);
exit;
