<?php
/**
 * cron/wa_campanas.php — Despacha las campañas de WhatsApp encoladas.
 *
 * Meta no tiene envío masivo: cada mensaje es una llamada. Este cron toma las
 * campañas en curso y despacha un lote de cada una, de forma que:
 *   - la petición web que crea la campaña responde al instante,
 *   - un corte a mitad no pierde el avance (la cola sabe qué queda),
 *   - y el ritmo se mantiene bajo control.
 *
 * Ejecutar cada 2 minutos. En crontab, la línea es:
 *   (cada dos minutos)  php /ruta/public_html/cron/wa_campanas.php
 * es decir, minuto "asterisco barra 2" y el resto asteriscos.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/WaCampanas.php';

// Por web exige token; por CLI no
if (PHP_SAPI !== 'cli') {
    $token = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || !CRON_TOKEN || !hash_equals(CRON_TOKEN, $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$pdo = Database::get();

// Un solo proceso a la vez: dos crons solapados enviarían el mismo lote dos
// veces y el cliente recibiría el mensaje duplicado.
$lock = fopen(sys_get_temp_dir() . '/ggi_wa_campanas.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Otro proceso ya está despachando. Se omite esta corrida.\n";
    exit(0);
}

$campanas = $pdo->query(
    "SELECT id, nombre FROM whatsapp_campanas
      WHERE estado IN ('encolada','enviando')
      ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$campanas) {
    echo "Sin campañas pendientes.\n";
    flock($lock, LOCK_UN);
    exit(0);
}

foreach ($campanas as $c) {
    $r = WaCampanas::procesarLote($pdo, (int) $c['id']);
    printf(
        "[%s] %s — enviados %d, fallidos %d, quedan %d\n",
        date('H:i:s'), $c['nombre'], $r['enviados'], $r['fallidos'], $r['restantes']
    );

    // Una sola campaña por corrida cuando aún queda cola: reparte el ritmo y
    // evita que una campaña grande acapare todas las ejecuciones.
    if ($r['restantes'] > 0) break;
}

flock($lock, LOCK_UN);
