<?php
/**
 * modules/whatsapp/ajax_campana.php — Backend de campañas masivas WhatsApp (Kapso Broadcasts).
 *
 * Acciones (POST, solo gerencia):
 *   segmento  → preview de destinatarios del segmento elegido
 *   templates → plantillas APROBADAS en Meta (vía Kapso)
 *   enviar    → crea el broadcast en Kapso, agrega destinatarios y dispara el envío
 *   sync      → refresca métricas de una campaña desde Kapso
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/KapsoBroadcastService.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
session_init();
require_login();
header('Content-Type: application/json');

if (!has_role('gerente_general', 'gerente_operaciones')) {
    echo json_encode(['ok' => false, 'msg' => 'Solo gerencia puede gestionar campañas.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false]); exit; }
csrf_verify();

$pdo    = Database::get();
$user   = auth_user();
$accion = $_POST['accion'] ?? '';

/**
 * Destinatarios de un segmento. Cada fila:
 *   {cliente_id, cot_id, empresa, numero, monto, telefono(normalizado o ''), telefono_raw}
 * Deduplicado por teléfono (se queda la primera aparición).
 */
function gg_campana_segmento(PDO $pdo, string $segmento, int $dias): array
{
    $rows = [];

    if ($segmento === 'enviadas_sin_respuesta') {
        // Propuestas ENVIADAS con ≥ X días de antigüedad y sin aceptar/rechazar
        $st = $pdo->prepare("
            SELECT cot.id AS cot_id, cot.cliente_id, cot.numero, cot.monto_total AS monto,
                   COALESCE(NULLIF(cot.empresa_nombre,''), cl.razon_social, '—') AS empresa,
                   COALESCE(NULLIF(cot.contacto_telefono,''), cl.contacto_telefono, '') AS telefono_raw
            FROM cotizaciones cot
            LEFT JOIN clientes cl ON cl.id = cot.cliente_id AND cl.deleted_at IS NULL
            WHERE cot.estado = 'enviada' AND cot.deleted_at IS NULL
              AND cot.fecha_emision <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY cot.fecha_emision ASC
        ");
        $st->execute([$dias]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($segmento === 'desistidas') {
        // Aceptaron y luego dejaron de responder (estado desistida)
        $st = $pdo->prepare("
            SELECT cot.id AS cot_id, cot.cliente_id, cot.numero, cot.monto_total AS monto,
                   COALESCE(NULLIF(cot.empresa_nombre,''), cl.razon_social, '—') AS empresa,
                   COALESCE(NULLIF(cot.contacto_telefono,''), cl.contacto_telefono, '') AS telefono_raw
            FROM cotizaciones cot
            LEFT JOIN clientes cl ON cl.id = cot.cliente_id
            WHERE cot.estado = 'desistida' AND cot.deleted_at IS NULL
              AND cot.desistida_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY cot.desistida_at DESC
        ");
        $st->execute([max($dias, 1)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($segmento === 'sin_respuesta_wa') {
        // Clientes con WhatsApp enviado en los últimos X días que NO respondieron
        // (sin mensaje entrante posterior al último envío registrado)
        $st = $pdo->prepare("
            SELECT cl.id AS cliente_id, NULL AS cot_id, cl.razon_social AS empresa,
                   '' AS numero, NULL AS monto, COALESCE(cl.contacto_telefono,'') AS telefono_raw
            FROM clientes cl
            JOIN (
                SELECT cliente_id, MAX(created_at) AS ultimo_envio
                FROM whatsapp_log
                WHERE estado IN ('sent','delivered','read')
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND cliente_id IS NOT NULL
                GROUP BY cliente_id
            ) wl ON wl.cliente_id = cl.id
            WHERE cl.deleted_at IS NULL
              AND cl.whatsapp_opt_in = 1
              AND NOT EXISTS (
                  SELECT 1 FROM whatsapp_inbound wi
                  WHERE wi.cliente_id = cl.id AND wi.created_at > wl.ultimo_envio
              )
            ORDER BY cl.razon_social
        ");
        $st->execute([max($dias, 1)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Normalizar teléfono + deduplicar
    $out  = [];
    $seen = [];
    foreach ($rows as $r) {
        $tel = WhatsAppService::normalizePhone($r['telefono_raw'] ?? '');
        if ($tel && isset($seen[$tel])) continue;
        if ($tel) $seen[$tel] = true;
        $r['telefono'] = $tel;
        $out[] = $r;
    }
    return $out;
}

/** Valor de un parámetro de plantilla para un destinatario según el mapeo elegido. */
function gg_campana_param(array $row, array $map): string
{
    $src = $map['src'] ?? 'fijo';
    return match ($src) {
        'empresa' => (string)($row['empresa'] ?? ''),
        'numero'  => (string)($row['numero'] ?? ''),
        'monto'   => $row['monto'] !== null ? 'S/ ' . number_format((float)$row['monto'], 2) : '',
        default   => (string)($map['valor'] ?? ''),
    } ?: '—';  // Meta rechaza parámetros vacíos
}

try {
    // ── Preview de segmento ────────────────────────────────────────────────────
    if ($accion === 'segmento') {
        $segmento = $_POST['segmento'] ?? '';
        $dias     = max(0, (int)($_POST['dias'] ?? 7));
        if (!in_array($segmento, ['enviadas_sin_respuesta', 'desistidas', 'sin_respuesta_wa'], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Segmento inválido.']); exit;
        }
        $rows      = gg_campana_segmento($pdo, $segmento, $dias);
        $validos   = array_values(array_filter($rows, fn($r) => $r['telefono'] !== ''));
        $invalidos = count($rows) - count($validos);
        echo json_encode([
            'ok'        => true,
            'total'     => count($rows),
            'validos'   => count($validos),
            'invalidos' => $invalidos,
            'rows'      => array_map(fn($r) => [
                'empresa'  => $r['empresa'],
                'numero'   => $r['numero'],
                'telefono' => $r['telefono'] ?: ($r['telefono_raw'] ?: '—'),
                'valido'   => $r['telefono'] !== '',
            ], $rows),
        ]);
        exit;
    }

    // ── Plantillas aprobadas ───────────────────────────────────────────────────
    if ($accion === 'templates') {
        $tpls = KapsoBroadcastService::listTemplates();
        // Las plantillas con header multimedia requieren un archivo por destinatario → fuera de campañas
        $tpls = array_values(array_filter($tpls, fn($t) => empty($t['header_media'])));
        echo json_encode(['ok' => true, 'templates' => $tpls]);
        exit;
    }

    // ── Crear + enviar campaña ─────────────────────────────────────────────────
    if ($accion === 'enviar') {
        $segmento      = $_POST['segmento'] ?? '';
        $dias          = max(0, (int)($_POST['dias'] ?? 7));
        $template_id   = trim($_POST['template_id'] ?? '');
        $template_name = trim($_POST['template_name'] ?? '');
        $param_names   = json_decode($_POST['param_names'] ?? '[]', true) ?: [];
        $params_map    = json_decode($_POST['params_map'] ?? '[]', true) ?: [];
        $nombre        = trim($_POST['nombre'] ?? '');

        if (!in_array($segmento, ['enviadas_sin_respuesta', 'desistidas', 'sin_respuesta_wa'], true) || !$template_id) {
            echo json_encode(['ok' => false, 'msg' => 'Datos incompletos.']); exit;
        }
        if ($nombre === '') $nombre = $template_name . '_' . date('Ymd_Hi');
        if (count($params_map) !== count($param_names)) {
            echo json_encode(['ok' => false, 'msg' => 'Falta mapear parámetros de la plantilla.']); exit;
        }

        // El segmento se recalcula en servidor: no se confía en la lista del cliente
        $rows       = gg_campana_segmento($pdo, $segmento, $dias);
        $validos    = array_values(array_filter($rows, fn($r) => $r['telefono'] !== ''));
        $excluidos  = count($rows) - count($validos);
        if (!$validos) { echo json_encode(['ok' => false, 'msg' => 'El segmento no tiene destinatarios con teléfono válido.']); exit; }

        $recipients = array_map(fn($r) => [
            'phone_number' => '+' . $r['telefono'],
            'params'       => array_map(fn($m) => gg_campana_param($r, $m), $params_map),
        ], $validos);

        $broadcastId = KapsoBroadcastService::createBroadcast($nombre, $template_id);
        if (!$broadcastId) { echo json_encode(['ok' => false, 'msg' => 'Kapso no aceptó la creación del broadcast (revisa el log del servidor).']); exit; }

        $res = KapsoBroadcastService::addRecipients($broadcastId, $recipients, $param_names);
        if (($res['added'] ?? 0) === 0) {
            echo json_encode(['ok' => false, 'msg' => 'Kapso no aceptó los destinatarios: ' . implode(' | ', array_slice($res['errors'], 0, 3))]); exit;
        }

        $enviado = KapsoBroadcastService::sendBroadcast($broadcastId);

        $pdo->prepare("
            INSERT INTO whatsapp_campanas
              (kapso_broadcast_id, nombre, template_id, template_name, segmento, segmento_json,
               total_destinatarios, excluidos, estado, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $broadcastId, $nombre, $template_id, $template_name, $segmento,
            json_encode(['dias' => $dias, 'params_map' => $params_map], JSON_UNESCAPED_UNICODE),
            $res['added'], $excluidos + (int)$res['duplicates'],
            $enviado ? 'sending' : 'draft',
            $user['id'],
        ]);

        log_actividad('whatsapp', 'campana',
            "Campaña '$nombre' ({$template_name}) → {$res['added']} destinatarios (segmento: $segmento" . ($dias ? ", {$dias}d" : '') . ")");

        echo json_encode([
            'ok'        => true,
            'enviado'   => $enviado,
            'agregados' => $res['added'],
            'excluidos' => $excluidos,
            'msg'       => $enviado
                ? "Campaña enviada a {$res['added']} destinatario(s)." . ($excluidos ? " $excluidos excluido(s) por teléfono inválido." : '')
                : 'Broadcast creado pero el envío falló; reintenta con Sincronizar.',
        ]);
        exit;
    }

    // ── Sincronizar métricas ───────────────────────────────────────────────────
    if ($accion === 'sync') {
        $campana_id = (int)($_POST['campana_id'] ?? 0);
        $st = $pdo->prepare("SELECT kapso_broadcast_id FROM whatsapp_campanas WHERE id = ?");
        $st->execute([$campana_id]);
        $bid = $st->fetchColumn();
        if (!$bid) { echo json_encode(['ok' => false, 'msg' => 'Campaña no encontrada.']); exit; }

        $b = KapsoBroadcastService::getBroadcast($bid);
        if (!$b) { echo json_encode(['ok' => false, 'msg' => 'Kapso no devolvió datos.']); exit; }

        $stats = [
            'sent'      => (int)($b['sent_count']      ?? 0),
            'delivered' => (int)($b['delivered_count'] ?? 0),
            'read'      => (int)($b['read_count']      ?? 0),
            'responded' => (int)($b['responded_count'] ?? 0),
            'failed'    => (int)($b['failed_count']    ?? 0),
            'pending'   => (int)($b['pending_count']   ?? 0),
            'rate'      => (float)($b['response_rate'] ?? 0),
        ];
        $pdo->prepare("UPDATE whatsapp_campanas SET estado = ?, stats_json = ? WHERE id = ?")
            ->execute([(string)($b['status'] ?? 'sending'), json_encode($stats), $campana_id]);

        echo json_encode(['ok' => true, 'estado' => $b['status'] ?? 'sending', 'stats' => $stats]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Acción inválida.']);
} catch (\Throwable $e) {
    error_log('[ajax_campana] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'msg' => 'Error interno al procesar la campaña.']);
}
