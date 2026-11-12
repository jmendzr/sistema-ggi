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

if (!has_role('gerente_general', 'gerente_operaciones','gerente_comercial')) {
    echo json_encode(['ok' => false, 'msg' => 'Solo gerencia puede gestionar campañas.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false]); exit; }
csrf_verify();

$pdo    = Database::get();
$user   = auth_user();
$accion = $_POST['accion'] ?? '';

/**
 * Clientes que YA compraron (venta cerrada): teléfonos (últimos 9 dígitos) y
 * cliente_ids con cotización 'aceptada' o certificado emitido. Sirve para NO
 * mandarles campañas de captación aunque tengan otra propuesta abierta con un
 * ejecutivo distinto (caso: no cerró con Andrés pero sí con Frank).
 */
function gg_campana_cerrados(PDO $pdo): array
{
    $phones = []; $clientes = [];
    $l9 = fn($t) => substr((string) $t, -9);

    // Cotizaciones aceptadas: teléfono de la cotización + del cliente, y el cliente
    $q = $pdo->query("SELECT cot.contacto_telefono ctel, cot.cliente_id, cl.contacto_telefono cltel
                        FROM cotizaciones cot LEFT JOIN clientes cl ON cl.id = cot.cliente_id
                       WHERE cot.estado = 'aceptada' AND cot.deleted_at IS NULL");
    foreach ($q as $r) {
        foreach ([$r['ctel'], $r['cltel']] as $p) {
            $n = WhatsAppService::normalizePhone((string) $p);
            if ($n) $phones[$l9($n)] = true;
        }
        if ($r['cliente_id']) $clientes[(int) $r['cliente_id']] = true;
    }

    // Clientes con certificado emitido (ya tienen una ISO con nosotros)
    $qc = $pdo->query("SELECT DISTINCT c.cliente_id, cl.contacto_telefono
                         FROM certificados c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                        WHERE c.cliente_id IS NOT NULL");
    foreach ($qc as $r) {
        if ($r['cliente_id']) $clientes[(int) $r['cliente_id']] = true;
        $n = WhatsAppService::normalizePhone((string) $r['contacto_telefono']);
        if ($n) $phones[$l9($n)] = true;
    }

    return ['phones' => $phones, 'clientes' => $clientes];
}

/**
 * Destinatarios de un segmento. Cada fila:
 *   {cliente_id, cot_id, empresa, numero, monto, telefono(normalizado o ''), telefono_raw}
 * Deduplicado por teléfono (se queda la primera aparición). En los segmentos de
 * recuperación se excluye además a quien YA compró (ver gg_campana_cerrados).
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

    // En segmentos de captación/recuperación, excluir a quien YA compró: un cliente
    // puede tener una propuesta abierta con un ejecutivo y otra ya cerrada con otro.
    $cerr = in_array($segmento, ['enviadas_sin_respuesta', 'desistidas'], true)
          ? gg_campana_cerrados($pdo) : ['phones' => [], 'clientes' => []];

    // Normalizar teléfono + deduplicar (+ descartar ya-clientes)
    $out  = [];
    $seen = [];
    foreach ($rows as $r) {
        $tel = WhatsAppService::normalizePhone($r['telefono_raw'] ?? '');
        if ($tel && isset($seen[$tel])) continue;

        // Ya es cliente (venta cerrada): no ofrecerle una promo de captación
        $l9 = $tel ? substr($tel, -9) : '';
        if (($l9 && isset($cerr['phones'][$l9])) ||
            (!empty($r['cliente_id']) && isset($cerr['clientes'][(int) $r['cliente_id']]))) {
            continue;
        }

        if ($tel) $seen[$tel] = true;
        $r['telefono'] = $tel;
        $out[] = $r;
    }
    return $out;
}

/**
 * Destinatarios de una lista manual / CSV pegada. Cada línea: telefono[,empresa]
 * (separador coma, punto y coma o tabulación). Deduplica por teléfono normalizado.
 */
function gg_campana_lista(string $texto): array
{
    $out = []; $seen = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($texto)) as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $partes  = preg_split('/[;,\t]/', $ln, 2);
        $telRaw  = trim($partes[0] ?? '');
        $empresa = trim($partes[1] ?? '');
        $tel = WhatsAppService::normalizePhone($telRaw);
        if ($tel && isset($seen[$tel])) continue;
        if ($tel) $seen[$tel] = true;
        $out[] = [
            'cliente_id' => null, 'cot_id' => '', 'numero' => '', 'monto' => null,
            'empresa' => $empresa !== '' ? $empresa : '—',
            'telefono_raw' => $telRaw, 'telefono' => $tel,
        ];
    }
    return $out;
}

/** Destinatarios de cualquier origen (segmento del sistema o lista manual/CSV). */
function gg_campana_rows(PDO $pdo, string $segmento, int $dias, string $lista): array
{
    return $segmento === 'lista' ? gg_campana_lista($lista) : gg_campana_segmento($pdo, $segmento, $dias);
}

const GG_CAMPANA_SEGMENTOS = ['enviadas_sin_respuesta', 'desistidas', 'sin_respuesta_wa', 'lista'];

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
        if (!in_array($segmento, GG_CAMPANA_SEGMENTOS, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Segmento inválido.']); exit;
        }
        $rows      = gg_campana_rows($pdo, $segmento, $dias, (string)($_POST['lista'] ?? ''));
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
                if (($c['type'] ?? '') === 'HEADER' && ($c['format'] ?? 'TEXT') !== 'TEXT') $headerMedia = true;
            }

            // Config local: nombres de variables (param_keys) y flyer fijo del header.
            $mp = $pdo->prepare("SELECT param_keys, header_media_url FROM whatsapp_templates
                                  WHERE template_name = ? LIMIT 1");
            $mp->execute([$t['name']]);
            $cfg       = $mp->fetch(PDO::FETCH_ASSOC) ?: [];
            $headerUrl = $cfg['header_media_url'] ?? null;

            // Un header de imagen/documento exige media por destinatario. Antes se
            // excluía siempre; ahora se PERMITE si tiene un flyer fijo configurado
            // (header_media_url): el envío masivo lo adjunta solo en cada mensaje.
            if ($headerMedia && !$headerUrl) continue;

            preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
            $nums = array_values(array_unique($m[1]));
            sort($nums, SORT_NUMERIC);

            // Nombres legibles de cada variable: si la plantilla ya está
            // mapeada a un evento, se usan sus param_keys; si no, el número.
            $pk     = json_decode($cfg['param_keys'] ?? '[]', true) ?: [];
            $claves = [];
            foreach ($nums as $i => $n) $claves[] = $pk[$i] ?? $n;

            $tpls[] = [
                'id'        => $t['name'],          // el nombre ES el identificador en Meta
                'name'      => $t['name'],
                'language'  => $t['language'],
                'category'  => $t['category'] ?? '',
                'body_text' => $body,               // la interfaz lo espera con este nombre
                'params'    => $claves,             // arreglo de nombres, no un contador
                'has_image' => $headerMedia,        // el header lleva flyer fijo
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

        if (!in_array($segmento, GG_CAMPANA_SEGMENTOS, true) || $template_name === '') {
            echo json_encode(['ok' => false, 'msg' => 'Datos incompletos.']); exit;
        }
        if ($nombre === '') $nombre = $template_name . '_' . date('Ymd_Hi');
        if (count($params_map) !== count($param_names)) {
            echo json_encode(['ok' => false, 'msg' => 'Falta mapear parámetros de la plantilla.']); exit;
        }

        // El segmento se recalcula en servidor: no se confía en la lista del cliente
        $rows    = gg_campana_rows($pdo, $segmento, $dias, (string)($_POST['lista'] ?? ''));
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

        $m    = WaCampanas::metricas($pdo, $campana_id);
        $sent = $m['enviado'] + $m['entregado'] + $m['leido'];
        $stats = [
            'sent'      => $sent,
            'delivered' => $m['entregado'] + $m['leido'],
            'read'      => $m['leido'],
            'failed'    => $m['fallido'],
            'pending'   => $m['pendiente'],
            'total'     => $m['total'],
            'responded' => $m['respondido'],
            // Tasa de respuesta: respondieron / enviados (%).
            'rate'      => $sent > 0 ? round($m['respondido'] * 100 / $sent) : 0,
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
