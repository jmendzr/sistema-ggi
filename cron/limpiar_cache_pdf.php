<?php
// ============================================================
//  GGI SISTEMA — Cron: Limpieza de caché de PDFs
//  Ejecutar semanalmente: 0 3 * * 0 php /ruta/cron/limpiar_cache_pdf.php
//
//  Borra los PDFs cacheados en storage/cache/pdf/ que no se han
//  usado (mtime) en más de CACHE_TTL_DIAS. La caché es puramente
//  derivada: cada archivo se regenera solo al volver a pedirlo
//  (ver modules/cotizaciones/pdf.php), así que borrarlos es seguro.
// ============================================================

define('RUNNING_FROM_CRON', true);
require_once __DIR__ . '/../config/config.php';

if (PHP_SAPI !== 'cli') {
    $token_web = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || $token_web !== CRON_TOKEN) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}

date_default_timezone_set(APP_TIMEZONE);

// Días de inactividad tras los que se borra un PDF cacheado.
// Configurable por .env (CACHE_PDF_TTL_DIAS); por defecto 30.
$ttl_dias   = (int) (env('CACHE_PDF_TTL_DIAS', '30') ?: 30);
$cache_dir  = __DIR__ . '/../storage/cache/pdf/';
$now        = time();
$corte      = $now - ($ttl_dias * 86400);

$borrados   = 0;
$bytes      = 0;
$errores    = 0;

if (is_dir($cache_dir)) {
    foreach (glob($cache_dir . '*.pdf') ?: [] as $f) {
        if (!is_file($f)) continue;
        if (filemtime($f) >= $corte) continue;   // aún reciente: conservar
        $sz = filesize($f);
        if (@unlink($f)) {
            $borrados++;
            $bytes += $sz;
        } else {
            $errores++;
        }
    }
}

$mb   = number_format($bytes / 1048576, 1);
$line = sprintf(
    "[%s] limpiar_cache_pdf: %d archivos borrados (%s MB liberados), TTL=%dd, errores=%d\n",
    date('Y-m-d H:i:s'), $borrados, $mb, $ttl_dias, $errores
);

@file_put_contents(__DIR__ . '/../logs/cache_pdf_cron.log', $line, FILE_APPEND | LOCK_EX);

if (PHP_SAPI === 'cli') {
    echo $line;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $line;
}
