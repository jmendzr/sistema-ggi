<?php
/**
 * core/KapsoBroadcastService.php
 *
 * Cliente de la Broadcasts API de Kapso para campañas masivas de WhatsApp.
 *   - Platform API: https://api.kapso.ai/platform/v1  (broadcasts, phone numbers)
 *   - Meta proxy:   https://api.kapso.ai/meta/whatsapp/v23.0  (listado de plantillas)
 * Autenticación: header X-API-Key (misma KAPSO_API_KEY del .env que usa WhatsAppService).
 *
 * Flujo de una campaña:
 *   1. listTemplates()                 → plantillas APROBADAS con sus parámetros
 *   2. createBroadcast(nombre, tplId)  → broadcast en draft, devuelve id
 *   3. addRecipients(id, destinatarios) → lotes de hasta 1000
 *   4. sendBroadcast(id)
 *   5. getBroadcast(id)                → métricas (sent/delivered/read/responded)
 */

require_once __DIR__ . '/../config/config.php';

class KapsoBroadcastService
{
    private const PLATFORM_BASE = 'https://api.kapso.ai/platform/v1';
    private const META_BASE     = 'https://api.kapso.ai/meta/whatsapp/v23.0';

    /** @var string|null cache del business_account_id resuelto vía API */
    private static ?string $waba = null;

    // ── HTTP ───────────────────────────────────────────────────────────────────

    /**
     * @return array{0:int,1:array}  [httpCode, jsonDecodificado]
     */
    private static function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . (defined('KAPSO_API_KEY') ? KAPSO_API_KEY : ''),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[KapsoBroadcast] cURL error {$method} {$url}: {$err}");
            return [0, ['error' => $err]];
        }
        return [$code, json_decode((string)$resp, true) ?: []];
    }

    // ── Descubrimiento de cuenta ───────────────────────────────────────────────

    /** business_account_id (WABA) del número configurado en KAPSO_PHONE_NUMBER_ID. */
    public static function businessAccountId(): ?string
    {
        if (self::$waba !== null) return self::$waba;
        [$code, $data] = self::request('GET', self::PLATFORM_BASE . '/whatsapp/phone_numbers');
        if ($code !== 200) return null;
        $phoneId = defined('KAPSO_PHONE_NUMBER_ID') ? KAPSO_PHONE_NUMBER_ID : '';
        foreach (($data['data'] ?? []) as $pn) {
            if (($pn['id'] ?? '') === $phoneId || ($pn['phone_number_id'] ?? '') === $phoneId) {
                return self::$waba = (string)$pn['business_account_id'];
            }
        }
        // Fallback: primer número de la cuenta
        $first = $data['data'][0]['business_account_id'] ?? null;
        return self::$waba = ($first ? (string)$first : null);
    }

    // ── Plantillas ─────────────────────────────────────────────────────────────

    /**
     * Plantillas APROBADAS con cuerpo y parámetros detectados.
     * @return array[] [{id, name, category, language, body_text, params: string[]}]
     */
    public static function listTemplates(): array
    {
        $waba = self::businessAccountId();
        if (!$waba) return [];

        [$code, $data] = self::request('GET', self::META_BASE . "/{$waba}/message_templates?limit=100");
        if ($code !== 200) return [];

        $out = [];
        foreach (($data['data'] ?? []) as $t) {
            if (($t['status'] ?? '') !== 'APPROVED') continue;

            $components = $t['components'] ?? [];
            if (isset($components['components'])) $components = $components['components'];

            $body   = '';
            $params = [];
            $header_media = null;
            foreach ($components as $c) {
                $type = strtoupper($c['type'] ?? '');
                if ($type === 'BODY') {
                    $body = $c['text'] ?? '';
                    if (preg_match_all('/\{\{([^}]+)\}\}/', $body, $m)) {
                        $params = $m[1];  // ['1','2'] o nombres
                    }
                } elseif ($type === 'HEADER' && in_array(strtoupper($c['format'] ?? ''), ['IMAGE','VIDEO','DOCUMENT'], true)) {
                    $header_media = strtolower($c['format']);
                }
            }

            $out[] = [
                'id'           => (string)$t['id'],
                'name'         => $t['name'],
                'category'     => $t['category'] ?? '',
                'language'     => $t['language'] ?? $t['language_code'] ?? 'es',
                'body_text'    => $body,
                'params'       => $params,
                'header_media' => $header_media,  // plantillas con media header no se soportan en campañas
            ];
        }
        return $out;
    }

    // ── Broadcasts ─────────────────────────────────────────────────────────────

    /** Crea el broadcast en draft. Devuelve el id de Kapso o null. */
    public static function createBroadcast(string $nombre, string $templateId): ?string
    {
        [$code, $data] = self::request('POST', self::PLATFORM_BASE . '/whatsapp/broadcasts', [
            'whatsapp_broadcast' => [
                'name'                 => $nombre,
                'phone_number_id'      => defined('KAPSO_PHONE_NUMBER_ID') ? KAPSO_PHONE_NUMBER_ID : '',
                'whatsapp_template_id' => $templateId,
            ],
        ]);
        if ($code < 200 || $code >= 300) {
            error_log('[KapsoBroadcast] createBroadcast HTTP ' . $code . ': ' . json_encode($data));
            return null;
        }
        return (string)($data['data']['id'] ?? '') ?: null;
    }

    /**
     * Agrega destinatarios en lotes de hasta 1000.
     * @param array $recipients  [['phone_number'=>'+51...', 'params'=>['v1','v2']], ...]
     * @param array $paramNames  nombres de parámetros de la plantilla (['1','2'] posicionales o nombres)
     * @return array{added:int, duplicates:int, errors:array}
     */
    public static function addRecipients(string $broadcastId, array $recipients, array $paramNames = []): array
    {
        $tot = ['added' => 0, 'duplicates' => 0, 'errors' => []];

        foreach (array_chunk($recipients, 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $r) {
                $row = ['phone_number' => $r['phone_number']];
                $vals = $r['params'] ?? [];
                if ($vals) {
                    $parameters = [];
                    foreach ($vals as $i => $v) {
                        $p = ['type' => 'text', 'text' => (string)$v];
                        $name = $paramNames[$i] ?? (string)($i + 1);
                        if (!ctype_digit($name)) $p['parameter_name'] = $name;  // plantillas con params nombrados
                        $parameters[] = $p;
                    }
                    $row['components'] = [['type' => 'body', 'parameters' => $parameters]];
                }
                $rows[] = $row;
            }

            [$code, $data] = self::request(
                'POST',
                self::PLATFORM_BASE . "/whatsapp/broadcasts/{$broadcastId}/recipients",
                ['whatsapp_broadcast' => ['recipients' => $rows]]
            );
            if ($code < 200 || $code >= 300) {
                $tot['errors'][] = 'HTTP ' . $code . ': ' . json_encode($data);
                continue;
            }
            $d = $data['data'] ?? [];
            $tot['added']      += (int)($d['added'] ?? 0);
            $tot['duplicates'] += (int)($d['duplicates'] ?? 0);
            foreach (($d['errors'] ?? []) as $e) $tot['errors'][] = $e;
        }
        return $tot;
    }

    /** Dispara el envío del broadcast. */
    public static function sendBroadcast(string $broadcastId): bool
    {
        [$code, $data] = self::request('POST', self::PLATFORM_BASE . "/whatsapp/broadcasts/{$broadcastId}/send");
        if ($code < 200 || $code >= 300) {
            error_log('[KapsoBroadcast] sendBroadcast HTTP ' . $code . ': ' . json_encode($data));
            return false;
        }
        return true;
    }

    /** Estado + métricas del broadcast (status, sent/delivered/read/responded_count, response_rate). */
    public static function getBroadcast(string $broadcastId): ?array
    {
        [$code, $data] = self::request('GET', self::PLATFORM_BASE . "/whatsapp/broadcasts/{$broadcastId}");
        return ($code === 200) ? ($data['data'] ?? null) : null;
    }
}
