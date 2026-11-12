<?php
// ============================================================
//  GGI SISTEMA — Cron: Generador de notificaciones automáticas
//  Ejecutar diariamente: 0 8 * * * php /ruta/cron/notificaciones.php
//
//  Genera notificaciones para:
//   1. Certificados por vencer (90, 60, 30, 15 días)
//   2. Certificados vencidos (primeros 7 días)
//   3. Auditorías próximas (30, 15, 7 días)
//   4. Envíos sin confirmar recepción (+7 días en tránsito)
//   5. Cotizaciones por vencer (3 días)
// ============================================================

// Protección: solo se puede ejecutar desde CLI o con token
if (PHP_SAPI !== 'cli') {
    $token_web = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || $token_web !== CRON_TOKEN) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}

define('RUNNING_FROM_CRON', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/PushService.php'; // push al Gerente General (alerta certificados)

date_default_timezone_set(APP_TIMEZONE);

$pdo  = Database::get();
$hoy  = date('Y-m-d');
$log  = [];

// ── Helper: insertar notificación solo si no existe una igual hoy ──
function crear_notificacion(PDO $pdo, int $usuario_id, string $tipo, string $titulo, string $mensaje, string $url = ''): bool {
    // Evitar duplicados del mismo día para el mismo tipo+entidad
    $hash = md5($tipo . $titulo . date('Y-m-d'));
    $existe = $pdo->prepare("
        SELECT id FROM notificaciones
        WHERE usuario_id = ? AND tipo = ? AND DATE(created_at) = CURDATE()
          AND titulo = ?
        LIMIT 1
    ");
    $existe->execute([$usuario_id, $tipo, $titulo]);
    if ($existe->fetch()) return false;

    $pdo->prepare("
        INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url_accion)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$usuario_id, $tipo, $titulo, $mensaje, $url]);
    return true;
}

// ── Obtener usuarios que deben recibir alertas por rol ──
$usuarios_alerta = $pdo->query("
    SELECT id, nombre, rol FROM usuarios
    WHERE activo = 1
      AND rol IN ('gerente_general', 'gerente_operaciones')
")->fetchAll(PDO::FETCH_ASSOC);

$usuarios_ventas = $pdo->query("
    SELECT id, nombre, rol FROM usuarios
    WHERE activo = 1
      AND rol IN ('gerente_general', 'gerente_operaciones', 'ventas')
")->fetchAll(PDO::FETCH_ASSOC);

$usuarios_delivery = $pdo->query("
    SELECT id, nombre, rol FROM usuarios
    WHERE activo = 1
      AND rol IN ('gerente_general', 'gerente_operaciones', 'delivery')
")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
//  1. CERTIFICADOS POR VENCER
// ============================================================
$cert_vencer = $pdo->query("
    SELECT c.id, c.fecha_vencimiento,
           DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias,
           cl.razon_social, n.codigo AS norma
    FROM certificados c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN normas_iso n ON n.id = c.norma_iso_id
    WHERE c.estado IN ('vigente', 'por_vencer')
      AND c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY c.fecha_vencimiento ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($cert_vencer as $c) {
    $dias = (int)$c['dias'];
    // Solo notificar en hitos específicos
    if (!in_array($dias, [90, 60, 30, 15, 7, 3, 1])) continue;

    $urgencia = $dias <= 15 ? '🔴' : ($dias <= 30 ? '🟡' : '🔵');
    $titulo   = "{$urgencia} Certificado por vencer: {$c['razon_social']}";
    $mensaje  = "El certificado {$c['norma']} de {$c['razon_social']} vence en {$dias} día" . ($dias !== 1 ? 's' : '') . " ({$c['fecha_vencimiento']}).";
    $url      = APP_URL . '/modules/certificados/ver.php?id=' . $c['id'];

    foreach ($usuarios_alerta as $u) {
        $creado = crear_notificacion($pdo, $u['id'], 'cert_por_vencer', $titulo, $mensaje, $url);
        if ($creado) $log[] = "  cert_por_vencer → usuario {$u['id']} ({$dias}d): {$c['razon_social']}";
    }
}

// ============================================================
//  2. CERTIFICADOS VENCIDOS (primeros 7 días para urge renovar)
// ============================================================
$cert_vencidos = $pdo->query("
    SELECT c.id, c.fecha_vencimiento,
           ABS(DATEDIFF(c.fecha_vencimiento, CURDATE())) AS dias_vencido,
           cl.razon_social, n.codigo AS norma
    FROM certificados c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN normas_iso n ON n.id = c.norma_iso_id
    WHERE c.estado = 'vencido'
      AND c.fecha_vencimiento >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY c.fecha_vencimiento DESC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($cert_vencidos as $c) {
    $dias    = (int)$c['dias_vencido'];
    if (!in_array($dias, [1, 3, 7])) continue;

    $titulo  = "🔴 Certificado VENCIDO: {$c['razon_social']}";
    $mensaje = "El certificado {$c['norma']} de {$c['razon_social']} venció hace {$dias} día" . ($dias !== 1 ? 's' : '') . ". Requiere renovación urgente.";
    $url     = APP_URL . '/modules/certificados/ver.php?id=' . $c['id'];

    foreach ($usuarios_alerta as $u) {
        $creado = crear_notificacion($pdo, $u['id'], 'cert_vencido', $titulo, $mensaje, $url);
        if ($creado) $log[] = "  cert_vencido → usuario {$u['id']} ({$dias}d vencido): {$c['razon_social']}";
    }
}

// ============================================================
//  3. AUDITORÍAS PRÓXIMAS
// ============================================================
$auditorias = $pdo->query("
    SELECT a.id, a.fecha_programada, a.tipo,
           DATEDIFF(a.fecha_programada, CURDATE()) AS dias,
           cl.razon_social, n.codigo AS norma
    FROM auditorias a
    JOIN certificados cert ON cert.id = a.certificado_id
    JOIN clientes cl ON cl.id = cert.cliente_id
    JOIN normas_iso n ON n.id = cert.norma_iso_id
    WHERE a.resultado = 'pendiente'
      AND a.fecha_programada BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY a.fecha_programada ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($auditorias as $a) {
    $dias = (int)$a['dias'];
    if (!in_array($dias, [30, 15, 7, 3, 1])) continue;

    $tipo_label = [
        'seguimiento_1' => 'Seguimiento 1',
        'seguimiento_2' => 'Seguimiento 2',
        'renovacion'    => 'Renovación',
        'extraordinaria'=> 'Extraordinaria',
    ][$a['tipo']] ?? $a['tipo'];

    $urgencia = $dias <= 3 ? '🔴' : ($dias <= 7 ? '🟡' : '🔵');
    $titulo   = "{$urgencia} Auditoría próxima: {$a['razon_social']}";
    $mensaje  = "Auditoría de {$tipo_label} ({$a['norma']}) para {$a['razon_social']} en {$dias} día" . ($dias !== 1 ? 's' : '') . " ({$a['fecha_programada']}).";
    $url      = APP_URL . '/modules/auditorias/ver.php?id=' . $a['id'];

    foreach ($usuarios_alerta as $u) {
        $creado = crear_notificacion($pdo, $u['id'], 'auditoria_proxima', $titulo, $mensaje, $url);
        if ($creado) $log[] = "  auditoria_proxima → usuario {$u['id']} ({$dias}d): {$a['razon_social']}";
    }
}

// ============================================================
//  4. ENVÍOS SIN CONFIRMAR RECEPCIÓN (+7 días en tránsito)
// ============================================================
$envios_pendientes = $pdo->query("
    SELECT e.id, e.fecha_envio, e.codigo_envio,
           DATEDIFF(CURDATE(), e.fecha_envio) AS dias_transito,
           cl.razon_social, ag.nombre AS agencia
    FROM envios e
    JOIN clientes cl ON cl.id = e.cliente_id
    JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    JOIN estados_envio es ON es.id = e.estado_envio_id
    WHERE e.fecha_recibido IS NULL
      AND e.fecha_envio IS NOT NULL
      AND DATEDIFF(CURDATE(), e.fecha_envio) >= 7
      AND es.nombre NOT IN ('Entregado', 'Devuelto')
    ORDER BY e.fecha_envio ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($envios_pendientes as $e) {
    $dias = (int)$e['dias_transito'];
    // Notificar en días 7, 14, 21...
    if ($dias % 7 !== 0) continue;

    $cod     = $e['codigo_envio'] ? " (#{$e['codigo_envio']})" : '';
    $titulo  = "📦 Envío sin confirmar: {$e['razon_social']}";
    $mensaje = "El envío{$cod} vía {$e['agencia']} para {$e['razon_social']} lleva {$dias} días sin confirmarse como recibido.";
    $url     = APP_URL . '/modules/envios/ver.php?id=' . $e['id'];

    foreach ($usuarios_delivery as $u) {
        $creado = crear_notificacion($pdo, $u['id'], 'envio_sin_confirmar', $titulo, $mensaje, $url);
        if ($creado) $log[] = "  envio_sin_confirmar → usuario {$u['id']} ({$dias}d): {$e['razon_social']}";
    }
}

// ============================================================
//  5. COTIZACIONES POR VENCER
// ============================================================
$cotizaciones = $pdo->query("
    SELECT id, codigo, empresa_nombre, fecha_vencimiento,
           DATEDIFF(fecha_vencimiento, CURDATE()) AS dias,
           monto_total, moneda
    FROM cotizaciones
    WHERE estado IN ('borrador', 'enviada')
      AND fecha_vencimiento IS NOT NULL
      AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)
    ORDER BY fecha_vencimiento ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($cotizaciones as $cot) {
    $dias    = (int)$cot['dias'];
    if (!in_array($dias, [5, 3, 1])) continue;

    $empresa = $cot['empresa_nombre'] ?: 'Sin nombre';
    $monto   = number_format($cot['monto_total'], 2) . ' ' . $cot['moneda'];
    $titulo  = "📄 Cotización por vencer: {$cot['codigo']}";
    $mensaje = "La cotización {$cot['codigo']} para {$empresa} ({$monto}) vence en {$dias} día" . ($dias !== 1 ? 's' : '') . ". Estado: pendiente de respuesta.";
    $url     = APP_URL . '/modules/cotizaciones/ver.php?id=' . $cot['id'];

    foreach ($usuarios_ventas as $u) {
        $creado = crear_notificacion($pdo, $u['id'], 'cotizacion_vencida', $titulo, $mensaje, $url);
        if ($creado) $log[] = "  cotizacion_vencida → usuario {$u['id']} ({$dias}d): {$cot['codigo']}";
    }
}

// ============================================================
//  6. CERTIFICADOS REQUERIDOS → movido a cron/recordatorio_certificados.php
//     (recordatorio al Gerente General 1 día antes, a las 22:00 Lima, para
//      GENERAR los certificados). Se sacó de aquí para no duplicar el aviso.
// ============================================================

// ============================================================
//  LOG DE EJECUCIÓN
// ============================================================
$total = count($log);
$ts    = date('Y-m-d H:i:s');
$resumen = "[$ts] Cron notificaciones: {$total} notificación(es) generada(s).\n";
foreach ($log as $linea) {
    $resumen .= $linea . "\n";
}

// Guardar log en archivo
$log_dir  = __DIR__ . '/../storage/logs/';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
$log_file = $log_dir . 'cron_' . date('Y-m') . '.log';
file_put_contents($log_file, $resumen, FILE_APPEND | LOCK_EX);

// También registrar en log_actividad de la BD
try {
    $pdo->prepare("
        INSERT INTO log_actividad (usuario_id, modulo, accion, descripcion, ip)
        VALUES (NULL, 'cron', 'notificaciones', ?, 'cron')
    ")->execute(["Generadas: {$total} notificaciones"]);
} catch (Exception $e) { /* silencioso */ }

if (PHP_SAPI === 'cli') {
    echo $resumen;
} else {
    header('Content-Type: text/plain');
    echo $resumen;
}
