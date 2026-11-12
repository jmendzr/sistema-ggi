<?php
// ============================================================
//  GGI SISTEMA — Cron: Re-chequeo de aceptación SUNAT (Alegra)
//  Ejecutar cada 5 min: */5 * * * * php /ruta/cron/facturas_sunat_recheck.php
//
//  SUNAT es asíncrono: al emitir, la factura queda
//  STAMPED_AND_WAITING_RESPONSE. Este cron relee su estado en Alegra y,
//  cuando pasa a STAMPED_AND_ACCEPTED, descarga el PDF definitivo y la
//  envía al cliente por WhatsApp (una sola vez). Si SUNAT la rechaza,
//  marca el estado y NO envía nada al cliente.
// ============================================================

if (PHP_SAPI !== 'cli') {
    $token_web = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || $token_web !== CRON_TOKEN) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}

define('RUNNING_FROM_CRON', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/AlegraService.php';
require_once __DIR__ . '/../modules/facturas/factura_finalizar.php';

date_default_timezone_set(APP_TIMEZONE);

if (!AlegraService::configurada()) { echo "Alegra no configurado.\n"; exit; }

$pdo = Database::get();

// Facturas Alegra pendientes de respuesta SUNAT (últimos 7 días).
$rows = $pdo->query("
    SELECT id, alegra_id, numero_factura, cliente_id
    FROM facturas_cliente
    WHERE origen = 'alegra'
      AND alegra_id IS NOT NULL
      AND estado_timbrado NOT IN ('STAMPED_AND_ACCEPTED','draft','error')
      AND (estado_timbrado LIKE '%WAITING%' OR estado_timbrado LIKE '%PROCESS%' OR estado_timbrado = 'STAMPED')
      AND created_at >= (NOW() - INTERVAL 7 DAY)
    ORDER BY id ASC
")->fetchAll(\PDO::FETCH_ASSOC);

$n_acept = 0; $n_rech = 0; $n_espera = 0;

foreach ($rows as $f) {
    $alegraId = (int)$f['alegra_id'];
    $est = AlegraService::estadoTimbradoDe($alegraId);
    $clase = $est['clase'];

    if ($clase === 'accepted') {
        $fin = factura_finalizar_aceptada($pdo, (int)$f['id'], null);
        $n_acept++;
        echo "ACEPTADA {$f['numero_factura']} (id {$f['id']}) — pdf=".(!empty($fin['pdf'])?'si':'no')." wa=".(!empty($fin['wa'])?'enviada':'no')."\n";
        log_actividad('facturas', 'sunat_aceptada', "SUNAT aceptó {$f['numero_factura']}; PDF descargado".(!empty($fin['wa'])?' y enviado al cliente':''));

    } elseif ($clase === 'rejected') {
        $pdo->prepare("UPDATE facturas_cliente SET estado_timbrado=? WHERE id=?")
            ->execute([$est['emissionStatus'] ?: 'STAMPED_AND_REJECTED', (int)$f['id']]);
        $n_rech++;
        echo "RECHAZADA {$f['numero_factura']} (id {$f['id']}) — {$est['legalStatus']}\n";
        error_log("[facturas_sunat_recheck] SUNAT rechazó {$f['numero_factura']}: ".json_encode($est['resp'], JSON_UNESCAPED_UNICODE));
        log_actividad('facturas', 'sunat_rechazada', "SUNAT rechazó {$f['numero_factura']} — revisar en Alegra");

    } else {
        // Sigue esperando: refrescar el estado si cambió de matiz.
        if (!empty($est['emissionStatus'])) {
            $pdo->prepare("UPDATE facturas_cliente SET estado_timbrado=? WHERE id=? AND estado_timbrado<>?")
                ->execute([$est['emissionStatus'], (int)$f['id'], $est['emissionStatus']]);
        }
        $n_espera++;
    }
}

echo "Revisadas: ".count($rows)." · aceptadas: $n_acept · rechazadas: $n_rech · en espera: $n_espera\n";
