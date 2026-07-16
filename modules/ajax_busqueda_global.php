<?php
// modules/ajax_busqueda_global.php — Buscador global del header
require_once __DIR__ . '/../core/helpers.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode(['ok' => true, 'resultados' => []]); exit; }

$pdo  = Database::get();
$like = '%' . $q . '%';
$resultados = [];

// ── Clientes ──
if (can('clientes')) {
    $stmt = $pdo->prepare("
        SELECT id, codigo_interno, razon_social, ruc, estado
        FROM clientes
        WHERE deleted_at IS NULL
          AND (razon_social LIKE ? OR ruc LIKE ? OR codigo_interno LIKE ?)
        LIMIT 5
    ");
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $resultados[] = [
            'tipo'     => 'cliente',
            'icono'    => 'empresa',
            'titulo'   => $r['razon_social'],
            'subtitulo'=> 'RUC: ' . $r['ruc'] . ' · ' . $r['codigo_interno'],
            'estado'   => $r['estado'],
            'url'      => APP_URL . '/modules/clientes/ver.php?id=' . $r['id'],
        ];
    }
}

// ── Certificados ──
if (can('certificados')) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.numero_certificado, c.estado,
               cl.razon_social, n.codigo AS norma
        FROM certificados c
        JOIN clientes cl ON cl.id = c.cliente_id
        JOIN normas_iso n ON n.id = c.norma_iso_id
        WHERE c.deleted_at IS NULL
          AND cl.deleted_at IS NULL
          AND (cl.razon_social LIKE ? OR cl.ruc LIKE ?
           OR c.numero_certificado LIKE ? OR n.codigo LIKE ?)
        LIMIT 4
    ");
    $stmt->execute([$like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $resultados[] = [
            'tipo'     => 'certificado',
            'icono'    => 'certificado',
            'titulo'   => $r['razon_social'] . ' — ' . $r['norma'],
            'subtitulo'=> $r['numero_certificado'] ? 'N°: ' . $r['numero_certificado'] : 'Sin número',
            'estado'   => $r['estado'],
            'url'      => APP_URL . '/modules/certificados/ver.php?id=' . $r['id'],
        ];
    }
}

// ── Envíos ──
if (can('envios')) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.codigo_envio, cl.razon_social, ag.nombre AS agencia
        FROM envios e
        JOIN clientes cl ON cl.id = e.cliente_id
        JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
        WHERE e.deleted_at IS NULL
          AND cl.deleted_at IS NULL
          AND (cl.razon_social LIKE ? OR cl.ruc LIKE ? OR e.codigo_envio LIKE ?)
        ORDER BY e.created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $resultados[] = [
            'tipo'     => 'envio',
            'icono'    => 'envio',
            'titulo'   => $r['razon_social'],
            'subtitulo'=> 'Envío vía ' . $r['agencia'] . ($r['codigo_envio'] ? ' · ' . $r['codigo_envio'] : ''),
            'estado'   => '',
            'url'      => APP_URL . '/modules/envios/ver.php?id=' . $r['id'],
        ];
    }
}

// ── Cotizaciones ──
if (can('cotizaciones')) {
    $stmt = $pdo->prepare("
        SELECT id, numero, empresa_nombre, empresa_ruc, estado, monto_total, moneda
        FROM cotizaciones
        WHERE deleted_at IS NULL
          AND (empresa_nombre LIKE ? OR empresa_ruc LIKE ? OR numero LIKE ?)
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $resultados[] = [
            'tipo'     => 'propuesta',
            'icono'    => 'propuesta',
            'titulo'   => $r['numero'] . ' — ' . ($r['empresa_nombre'] ?: 'Sin nombre'),
            'subtitulo'=> ($r['moneda'] === 'USD' ? 'USD ' : 'S/ ') . number_format($r['monto_total'], 2) . ' · ' . ucfirst($r['estado']),
            'estado'   => $r['estado'],
            'url'      => APP_URL . '/modules/cotizaciones/ver.php?id=' . $r['id'],
        ];
    }
}

echo json_encode(['ok' => true, 'resultados' => $resultados, 'total' => count($resultados)]);
