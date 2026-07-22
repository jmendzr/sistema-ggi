<?php
/**
 * modules/whatsapp/ajax_campana.php — Backend de campañas masivas WhatsApp (Meta nativo).
 *
 * Acciones (POST, solo gerencia):
 *   segmento  → preview de destinatarios del segmento elegido
 *   templates → plantillas APROBADAS, leídas directo de la WABA
 *   enviar    → ENCOLA la campaña; el cron cron/wa_campanas.php la despacha por lotes
 *   sync      → métricas calculadas de nuestra propia cola (sin llamadas externas)
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
require_once __DIR__ . '/../../core/WaCampanas.php';
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

    // ── Plantillas aprobadas (nativo, desde Meta) ─────────────────────────────
    if ($accion === 'templates') {
        $meta = WhatsAppService::listarPlantillas();
        if ($meta === null) {
            echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo consultar Meta']);
            exit;
        }

        $tpls = [];
        foreach ($meta as $t) {
            if (($t['status'] ?? '') !== 'APPROVED') continue;

            $body = ''; $headerMedia = false;
            foreach ($t['components'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'BODY')   $body = $c['text'] ?? '';
                // Un header de imagen o documento exige un archivo por destinatario:
                // no tiene sentido en un envío masivo.
                if (($c['type'] ?? '') === 'HEADER' && ($c['format'] ?? 'TEXT') !== 'TEXT') $headerMedia = true;
            }
            if ($headerMedia) continue;

            preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
            $tpls[] = [
                'id'        => $t['name'],          // el nombre ES el identificador en Meta
                'name'      => $t['name'],
                'language'  => $t['language'],
                'category'  => $t['category'] ?? '',
                'body'      => $body,
                'params'    => count(array_unique($m[1])),
            ];
        }

        echo json_encode(['ok' => true, 'templates' => $tpls]);
        exit;
    }

    // ── Crear campaña: se ENCOLA, la despacha el cron ─────────────────────────
    if ($accion === 'enviar') {
        $segmento      = $_POST['segmento'] ?? '';
        $dias          = max(0, (int)($_POST['dias'] ?? 7));
        $template_name = trim($_POST['template_name'] ?? '') ?: trim($_POST['template_id'] ?? '');
        $template_lang = trim($_POST['template_lang'] ?? 'es');
        $param_names   = json_decode($_POST['param_names'] ?? '[]', true) ?: [];
        $params_map    = json_decode($_POST['params_map'] ?? '[]', true) ?: [];
        $nombre        = trim($_POST['nombre'] ?? '');

        if (!in_array($segmento, ['enviadas_sin_respuesta', 'desistidas', 'sin_respuesta_wa'], true) || $template_name === '') {
            echo json_encode(['ok' => false, 'msg' => 'Datos incompletos.']); exit;
        }
        if ($nombre === '') $nombre = $template_name . '_' . date('Ymd_Hi');
        if (count($params_map) !== count($param_names)) {
            echo json_encode(['ok' => false, 'msg' => 'Falta mapear parámetros de la plantilla.']); exit;
        }

        // El segmento se recalcula en servidor: no se confía en la lista del cliente
        $rows    = gg_campana_segmento($pdo, $segmento, $dias);
        $validos = array_values(array_filter($rows, fn($r) => $r['telefono'] !== ''));
        if (!$validos) { echo json_encode(['ok' => false, 'msg' => 'El segmento no tiene destinatarios con teléfono válido.']); exit; }

        $dest = array_map(fn($r) => [
            'telefono'   => $r['telefono'],
            'cliente_id' => $r['cliente_id'] ?? null,
            'empresa'    => $r['empresa'] ?? null,
            'params'     => array_map(fn($m) => gg_campana_param($r, $m), $params_map),
        ], $validos);

        $res = WaCampanas::encolar($pdo, $nombre, $template_name, $template_lang,
                                   $param_names, $segmento, $dest, (int) $user['id']);

        $pdo->prepare("UPDATE whatsapp_campanas SET segmento_json = ? WHERE id = ?")
            ->execute([json_encode(['dias' => $dias, 'params_map' => $params_map], JSON_UNESCAPED_UNICODE),
                       $res['campana_id']]);

        log_actividad('whatsapp', 'campana',
            "Campaña '$nombre' ({$template_name}) encolada → {$res['encolados']} destinatarios (segmento: $segmento" . ($dias ? ", {$dias}d" : '') . ")");

        echo json_encode([
            'ok'        => true,
            'campana_id'=> $res['campana_id'],
            'agregados' => $res['encolados'],
            'excluidos' => $res['omitidos'],
            'msg'       => "Campaña encolada: {$res['encolados']} destinatario(s). "
                         . "El envío se despacha por lotes en los próximos minutos."
                         . ($res['omitidos'] ? " {$res['omitidos']} excluido(s) por teléfono inválido." : ''),
        ]);
        exit;
    }

    // ── Métricas: se calculan de nuestra cola, sin llamar a nadie ─────────────
    if ($accion === 'sync') {
        $campana_id = (int)($_POST['campana_id'] ?? 0);
        $st = $pdo->prepare("SELECT estado FROM whatsapp_campanas WHERE id = ?");
        $st->execute([$campana_id]);
        $estado = $st->fetchColumn();
        if ($estado === false) { echo json_encode(['ok' => false, 'msg' => 'Campaña no encontrada.']); exit; }

        $m = WaCampanas::metricas($pdo, $campana_id);
        $stats = [
            'sent'      => $m['enviado'] + $m['entregado'] + $m['leido'],
            'delivered' => $m['entregado'] + $m['leido'],
            'read'      => $m['leido'],
            'failed'    => $m['fallido'],
            'pending'   => $m['pendiente'],
            'responded' => 0,
            'rate'      => 0,
        ];
        $pdo->prepare("UPDATE whatsapp_campanas SET stats_json = ? WHERE id = ?")
            ->execute([json_encode($stats), $campana_id]);

        echo json_encode(['ok' => true, 'estado' => $estado, 'stats' => $stats]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Acción inválida.']);
} catch (\Throwable $e) {
    error_log('[ajax_campana] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'msg' => 'Error interno al procesar la campaña.']);
}
