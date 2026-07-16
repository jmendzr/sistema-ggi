<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/SunatService.php';
session_init();
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$ruc = trim($_GET['ruc'] ?? '');
if (empty($ruc) || !preg_match('/^\d{11}$/', $ruc)) {
    echo json_encode(['ok' => false, 'mensaje' => 'RUC inválido.']);
    exit;
}

// ── Solo_representante=1: reintentar únicamente la búsqueda del gerente ──
// Usado cuando el usuario hace clic en "Reintentar representante legal"
$solo_representante = !empty($_GET['solo_representante']);

if ($solo_representante) {
    // Invalidar caché parcial para forzar nueva consulta
    $cacheKey = 'sunat_ruc_' . $ruc;
    unset($_SESSION[$cacheKey]);
    session_write_close(); // liberar lock antes de llamar a la API

    $gerente = SunatService::consultarGerente($ruc);
    if ($gerente['ok']) {
        echo json_encode([
            'ok'               => true,
            'representante_ok' => true,
            'nombre'           => $gerente['nombre'],
            'apellido'         => $gerente['apellido'],
            'cargo'            => $gerente['cargo'],
        ]);
    } else {
        echo json_encode([
            'ok'               => false,
            'representante_ok' => false,
            'sin_representante'=> $gerente['sin_representante'] ?? false,
            'mensaje'          => $gerente['mensaje'] ?? 'No se pudo obtener el representante legal.',
        ]);
    }
    exit;
}

// ── Caché de sesión: solo se usa si el dato del representante también fue exitoso ──
$cacheKey = 'sunat_ruc_' . $ruc;
if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey]) && !empty($_SESSION[$cacheKey]['representante_ok'])) {
    // Liberar lock antes de responder (el caché ya estaba en memoria)
    session_write_close();
    echo json_encode($_SESSION[$cacheKey]);
    exit;
}

// ── Liberar el lock de sesión ANTES de las llamadas a APIs externas ──
// Esto evita bloquear otras pestañas del mismo usuario durante los ~10-20s de espera.
session_write_close();

// ── API 1: Datos principales del RUC ──
$resultado = SunatService::consultarRuc($ruc);
if (!$resultado['ok']) {
    echo json_encode(['ok' => false, 'mensaje' => $resultado['mensaje']]);
    exit;
}

// ── API 2: Representante legal (con reintentos automáticos) ──
$gerente = SunatService::consultarGerente($ruc);

// ── API 3: Trabajadores SUNAT ──
$trabajadores_res  = SunatService::consultarTrabajadores($ruc);
$num_trabajadores  = $trabajadores_res['ok'] ? $trabajadores_res['num_trabajadores'] : null;

$representante_ok = $gerente['ok'];
$sin_representante = $gerente['sin_representante'] ?? false;

$response = [
    'ok'               => true,
    'representante_ok' => $representante_ok,
    'sin_representante'=> $sin_representante,
    'data' => [
        'ruc'               => $resultado['ruc'],
        'razon_social'      => $resultado['razon_social'],
        'direccion'         => $resultado['direccion'],
        'departamento'      => $resultado['departamento'] ?? '',
        'provincia'         => $resultado['provincia']    ?? '',
        'distrito'          => $resultado['distrito']     ?? '',
        'ubigeo'            => $resultado['ubigeo']       ?? '',
        'estado_sunat'      => $resultado['estado']       ?? '',
        'condicion'         => $resultado['condicion']    ?? '',
        'contacto_nombre'   => $representante_ok ? $gerente['nombre']   : '',
        'contacto_apellido' => $representante_ok ? $gerente['apellido'] : '',
        'contacto_cargo'    => $representante_ok ? $gerente['cargo']    : '',
        'num_trabajadores'  => $num_trabajadores,
    ],
];

// ── Cachear en sesión SOLO si el representante se obtuvo correctamente ──
// Si falló, NO cachear para que el próximo intento vuelva a consultar la API.
if ($representante_ok) {
    // Re-abrir sesión brevemente para escribir el caché, luego cerrar
    session_start();
    $_SESSION[$cacheKey] = $response;
    session_write_close();
}

echo json_encode($response);
