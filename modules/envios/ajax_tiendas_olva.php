<?php
/**
 * Lista completa de puntos de recojo Olva (tiendas / oficinas / agentes) del
 * distrito de destino. Recibe el ubigeo (6 díg.) del distrito seleccionado.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/OlvaSedesService.php';
session_init();
require_login();
session_write_close();
header('Content-Type: application/json; charset=utf-8');

$ubigeo = preg_replace('/\D/', '', $_GET['ubigeo'] ?? '');
if ($ubigeo === '') { echo json_encode(['tiendas' => []]); exit; }

echo json_encode(['tiendas' => OlvaSedesService::tiendasPorDistrito($ubigeo)], JSON_UNESCAPED_UNICODE);
