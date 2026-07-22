<?php
/**
 * modules/inbox/media.php — Sirve un adjunto de una conversación de WhatsApp.
 *
 * Meta no envía el archivo en el webhook, solo un identificador. La URL de
 * descarga que devuelve caduca en minutos, así que NO se puede guardar; lo
 * que sí dura (~30 días) es el media_id. Por eso la descarga se hace la
 * primera vez que alguien abre el archivo y se cachea en disco.
 *
 * Requiere sesión: los archivos son de conversaciones con clientes y no
 * pueden quedar accesibles públicamente.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas');

$id = (int) ($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('ID inválido'); }

$pdo = Database::get();
$q   = $pdo->prepare("SELECT id, media_id, media_path, media_mime, media_nombre, tipo
                        FROM wa_mensajes WHERE id = ?");
$q->execute([$id]);
$m = $q->fetch(PDO::FETCH_ASSOC);

if (!$m || (!$m['media_id'] && !$m['media_path'])) { http_response_code(404); exit('No encontrado'); }

$dir = __DIR__ . '/../../storage/wa_media';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$ruta = $m['media_path'] ? $dir . '/' . basename($m['media_path']) : null;

// Descargar solo si aún no está en disco
if (!$ruta || !is_file($ruta)) {
    $bin = WhatsAppService::fetchMedia((string) $m['media_id']);
    if (!$bin) {
        http_response_code(502);
        exit('No se pudo obtener el archivo: ' . (WhatsAppService::lastError() ?? 'error desconocido'));
    }

    $ext = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4'  => 'mp4', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3',
        'application/pdf' => 'pdf',
    ][$bin['mime']] ?? 'bin';

    $nombre = 'wa_' . $m['id'] . '_' . substr(sha1((string) $m['media_id']), 0, 10) . '.' . $ext;
    $ruta   = $dir . '/' . $nombre;

    if (@file_put_contents($ruta, $bin['contenido']) === false) {
        http_response_code(500); exit('No se pudo guardar el archivo');
    }

    $pdo->prepare("UPDATE wa_mensajes SET media_path = ?, media_mime = COALESCE(media_mime, ?) WHERE id = ?")
        ->execute([$nombre, $bin['mime'], $m['id']]);

    $m['media_mime'] = $m['media_mime'] ?: $bin['mime'];
}

$mime = $m['media_mime'] ?: 'application/octet-stream';

// Los documentos se descargan; imágenes, audio y video se muestran en línea
$inline = preg_match('#^(image|audio|video)/#', $mime) || $mime === 'application/pdf';
$nombreVisible = $m['media_nombre'] ?: basename($ruta);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
       . '; filename="' . str_replace('"', '', $nombreVisible) . '"');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');

readfile($ruta);
