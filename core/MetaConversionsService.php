<?php
/**
 * core/MetaConversionsService.php — Meta Conversions API (Click-to-WhatsApp Ads).
 *
 * Envía el cierre de venta a Meta para atribuirlo a la campaña de Click-to-WhatsApp
 * que originó el lead. La atribución usa el `ctwa_clid` que Meta manda en el
 * `referral` del primer mensaje entrante (lo captura modules/whatsapp/webhook_meta.php
 * y lo guarda en wa_mensajes.referral_json).
 *
 * Diseño:
 *   • No bloqueante: cualquier fallo se registra en error_log y NO rompe el flujo
 *     de aceptación de la propuesta (se llama dentro de su propio try/catch).
 *   • No-op silencioso si faltan credenciales (META_DATASET_ID / token) o si el
 *     cliente no tiene ctwa_clid (sin clic de anuncio no hay nada que atribuir).
 *   • event_id determinístico ("cot-<id>") → Meta deduplica si se reenvía.
 *
 * Referencia: Conversions API for Click to WhatsApp ads. Para CTWA el ctwa_clid y el
 * WABA id van en user_data; action_source = 'business_messaging', canal 'whatsapp'.
 */

require_once __DIR__ . '/../config/config.php';

class MetaConversionsService
{
    /** @var string|null Último error, para diagnóstico. */
    public static $lastError = null;

    /** ¿Está configurada la Conversions API? */
    public static function configurada(): bool
    {
        return defined('META_DATASET_ID') && META_DATASET_ID !== ''
            && defined('META_CAPI_ACCESS_TOKEN') && META_CAPI_ACCESS_TOKEN !== '';
    }

    /**
     * Busca el ctwa_clid del cliente (primer referral entrante con clic de anuncio).
     * Devuelve null si el cliente no vino de un Click-to-WhatsApp Ad.
     */
    public static function ctwaClidDeCliente(PDO $pdo, int $clienteId): ?string
    {
        if ($clienteId <= 0) return null;
        $st = $pdo->prepare("
            SELECT m.referral_json
              FROM wa_conversaciones c
              JOIN wa_mensajes m ON m.conversacion_id = c.id
             WHERE c.cliente_id = ?
               AND m.referral_json IS NOT NULL
             ORDER BY m.id ASC
        ");
        $st->execute([$clienteId]);
        foreach ($st as $row) {
            $ref = json_decode((string)$row['referral_json'], true);
            if (is_array($ref) && !empty($ref['ctwa_clid'])) {
                return (string)$ref['ctwa_clid'];
            }
        }
        return null;
    }

    /**
     * Envía el cierre de venta a Meta Conversions API. Se llama al validar el
     * PRIMER pago (pago inicial) — ver modules/pagos/ajax_pago.php.
     *
     * Reglas (todas deben cumplirse):
     *   1. La propuesta debe estar ACEPTADA (aceptación + primer pago = venta real).
     *   2. El cliente debe haber venido de un Click-to-WhatsApp Ad (tiene ctwa_clid).
     *   3. Una sola venta por CLIENTE: si el cliente ya fue reportado, no se repite
     *      (una 2ª ISO del mismo cliente no cuenta como nueva venta).
     *
     * @param PDO   $cot  Fila de cotizaciones (necesita: id, cliente_id, monto_total,
     *                    moneda, numero). Es la que ya se tiene en ajax_pago.php.
     * @return bool true si se envió; false si se omitió o falló (ver $lastError).
     */
    public static function enviarCierreVenta(PDO $pdo, array $cot): bool
    {
        self::$lastError = null;

        if (!self::configurada()) {
            self::$lastError = 'Conversions API no configurada (.env)';
            return false;
        }

        $clienteId = (int)($cot['cliente_id'] ?? 0);
        $cotId     = (int)($cot['id'] ?? 0);
        if ($clienteId <= 0 || $cotId <= 0) {
            self::$lastError = 'faltan ids (cliente/cotización)';
            return false;
        }

        // ── Regla 1: la propuesta debe estar aceptada ──
        $est = $pdo->prepare("SELECT estado FROM cotizaciones WHERE id = ?");
        $est->execute([$cotId]);
        if ($est->fetchColumn() !== 'aceptada') {
            self::$lastError = 'propuesta no aceptada';
            return false;
        }

        // ── Regla 2: debe venir de un anuncio Click-to-WhatsApp ──
        $clid = self::ctwaClidDeCliente($pdo, $clienteId);
        if (!$clid) {
            self::$lastError = 'cliente sin ctwa_clid';
            return false;
        }

        // ── Regla 3: una sola venta por cliente (no repetir en 2ª ISO) ──
        $ya = $pdo->prepare("SELECT meta_venta_reportada_at FROM clientes WHERE id = ?");
        $ya->execute([$clienteId]);
        if ($ya->fetchColumn() !== null) {
            self::$lastError = 'cliente ya reportado antes';
            return false;
        }

        $moneda = strtoupper(trim((string)($cot['moneda'] ?? 'PEN'))) ?: 'PEN';
        $valor  = number_format((float)($cot['monto_total'] ?? 0), 2, '.', '');

        $userData = ['ctwa_clid' => $clid];
        if (defined('META_WA_WABA_ID') && META_WA_WABA_ID !== '') {
            $userData['whatsapp_business_account_id'] = META_WA_WABA_ID;
        }

        $evento = [
            'event_name'        => 'Purchase',
            'event_time'        => time(),
            'action_source'     => 'business_messaging',
            'messaging_channel' => 'whatsapp',
            // Dedup a nivel CLIENTE (una venta por cliente), no por propuesta.
            'event_id'          => 'cli-' . $clienteId,
            'user_data'         => $userData,
            'custom_data'       => array_filter([
                'currency' => $moneda,
                'value'    => $valor,
                'order_id' => trim((string)($cot['numero'] ?? '')) ?: null,
            ], fn($v) => $v !== null && $v !== ''),
        ];

        if (self::enviar([$evento])) {
            // Marcar el cliente como reportado (idempotente: solo si aún estaba NULL).
            $pdo->prepare("UPDATE clientes SET meta_venta_reportada_at = NOW() WHERE id = ? AND meta_venta_reportada_at IS NULL")
                ->execute([$clienteId]);
            return true;
        }
        return false;
    }

    /**
     * POST del batch de eventos a graph.facebook.com/{version}/{dataset}/events.
     */
    private static function enviar(array $eventos): bool
    {
        $version = defined('META_WA_VERSION') && META_WA_VERSION !== '' ? META_WA_VERSION : 'v21.0';
        $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode(META_DATASET_ID)
             . '/events?access_token=' . rawurlencode(META_CAPI_ACCESS_TOKEN);

        $payload = json_encode(['data' => $eventos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 300) {
            self::$lastError = 'HTTP ' . $code . ' ' . ($cerr ?: substr((string)$resp, 0, 300));
            error_log('[MetaConversions] Fallo enviando evento: ' . self::$lastError);
            return false;
        }
        return true;
    }
}
