<?php
/**
 * core/IaAgente.php — Copiloto de IA del inbox (Fase C).
 *
 * Rol: NO habla con el cliente por su cuenta. Cuando el ejecutivo lo pide
 * ("Ayúdame a responder") lee el hilo de la conversación + datos internos
 * del cliente y devuelve UNA sugerencia de respuesta que el ejecutivo edita
 * y envía (o descarta). La IA nunca envía nada.
 *
 * Motor conmutable por IA_PROVIDER (.env): 'claude' (Anthropic) u 'openai'.
 * Las llaves viven en .env; sin llave configurada el copiloto queda inactivo.
 */

require_once __DIR__ . '/../config/config.php';

class IaAgente
{
    private static ?string $lastError = null;

    public static function lastError(): ?string { return self::$lastError; }

    /** ¿Hay al menos un motor con llave listo? Sirve para mostrar/ocultar el botón. */
    public static function disponible(): bool
    {
        return count(self::chain()) > 0;
    }

    /**
     * Orden de motores a intentar. IA_PROVIDER admite:
     *   - 'auto' (o vacío): usa TODOS los configurados, orden por defecto gemini→claude→openai
     *     (el gratis primero para ahorrar costo).
     *   - una lista separada por comas ('claude,gemini') para fijar tu propio orden.
     *   - un solo nombre ('gemini') para usar solo ese.
     * Siempre se filtra a los que realmente tienen llave.
     */
    private static function chain(): array
    {
        $raw   = strtolower(trim((string) IA_PROVIDER));
        $known = ['gemini', 'claude', 'openai'];

        if ($raw === '' || $raw === 'auto' || $raw === 'all' || $raw === 'todos') {
            $order = ['gemini', 'claude', 'openai'];
        } elseif (str_contains($raw, ',')) {
            $order = array_map('trim', explode(',', $raw));
        } else {
            $order = [$raw];
        }

        $out = [];
        foreach ($order as $p) {
            if (!in_array($p, $known, true)) $p = 'claude';
            if (!in_array($p, $out, true) && self::configurado($p)) $out[] = $p;
        }
        return $out;
    }

    private static function configurado(string $p): bool
    {
        switch ($p) {
            case 'openai': return IA_OPENAI_KEY !== '';
            case 'gemini': return count(self::geminiKeys()) > 0;
            default:       return IA_ANTHROPIC_KEY !== '';
        }
    }

    /** Llaves de Gemini configuradas, en orden (1ª preferida, 2ª de respaldo). */
    private static function geminiKeys(): array
    {
        return array_values(array_filter(
            [IA_GEMINI_KEY, IA_GEMINI_KEY_2],
            fn($k) => trim((string) $k) !== ''
        ));
    }

    // ── Contexto interno del cliente (solo lectura) ──────────────────────────
    /**
     * Resumen compacto y legible de la situación del cliente para que la IA
     * redacte con datos reales. Devuelve '' si no hay cliente identificado.
     */
    public static function contextoCliente(\PDO $pdo, ?int $clienteId): string
    {
        if (!$clienteId) return '';

        $L = [];

        // Datos base
        $c = $pdo->prepare("SELECT razon_social, ruc, contacto_nombre, contacto_apellido, estado
                              FROM clientes WHERE id = ? AND deleted_at IS NULL");
        $c->execute([$clienteId]);
        $cli = $c->fetch(\PDO::FETCH_ASSOC);
        if (!$cli) return '';

        $L[] = 'Empresa: ' . trim($cli['razon_social'] ?? '') . ($cli['ruc'] ? ' (RUC ' . $cli['ruc'] . ')' : '');
        $contacto = trim(($cli['contacto_nombre'] ?? '') . ' ' . ($cli['contacto_apellido'] ?? ''));
        if ($contacto !== '') $L[] = 'Contacto: ' . $contacto;

        // Propuestas / cotizaciones (con normas)
        $q = $pdo->prepare(
            "SELECT cot.numero, cot.estado, cot.monto_total, cot.moneda, cot.fecha_requerida_certificados,
                    (SELECT GROUP_CONCAT(n.codigo SEPARATOR ', ')
                       FROM cotizacion_normas cn JOIN normas_iso n ON n.id = cn.norma_iso_id
                      WHERE cn.cotizacion_id = cot.id) AS normas
               FROM cotizaciones cot
              WHERE cot.cliente_id = ? AND cot.deleted_at IS NULL AND cot.estado <> 'borrador'
              ORDER BY cot.id DESC LIMIT 4");
        $q->execute([$clienteId]);
        foreach ($q as $r) {
            $t = 'Propuesta ' . $r['numero'] . ': ' . self::estadoPropuestaLabel($r['estado']);
            if ($r['normas'])      $t .= ' — normas: ' . $r['normas'];
            if ($r['monto_total']) $t .= ' — total: ' . ($r['moneda'] ?: 'S/') . ' ' . number_format((float) $r['monto_total'], 2);
            if ($r['fecha_requerida_certificados']) $t .= ' — certificados requeridos para ' . $r['fecha_requerida_certificados'];
            $L[] = $t;
        }

        // Certificados
        $q = $pdo->prepare(
            "SELECT c.numero_certificado, c.estado, c.fecha_emision, c.fecha_vencimiento, n.codigo AS norma
               FROM certificados c LEFT JOIN normas_iso n ON n.id = c.norma_iso_id
              WHERE c.cliente_id = ? AND c.deleted_at IS NULL
              ORDER BY c.id DESC LIMIT 5");
        $q->execute([$clienteId]);
        foreach ($q as $r) {
            $t = 'Certificado ' . ($r['norma'] ?: '') . ' ' . ($r['numero_certificado'] ?: '') . ': '
               . ($r['estado'] === 'vigente' ? 'vigente' : 'en proceso');
            if ($r['fecha_vencimiento']) $t .= ' — vence ' . $r['fecha_vencimiento'];
            $L[] = trim($t);
        }

        // Envíos (con estado legible)
        $q = $pdo->prepare(
            "SELECT e.codigo_envio, e.numero_orden, es.nombre AS estado, ag.nombre AS agencia
               FROM envios e
               LEFT JOIN estados_envio es ON es.id = e.estado_envio_id
               LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
              WHERE e.cliente_id = ? AND e.deleted_at IS NULL
              ORDER BY e.id DESC LIMIT 3");
        $q->execute([$clienteId]);
        foreach ($q as $r) {
            $t = 'Envío' . ($r['codigo_envio'] ? ' ' . $r['codigo_envio'] : '') . ': ' . ($r['estado'] ?: 'sin estado');
            if ($r['agencia'])      $t .= ' por ' . $r['agencia'];
            if ($r['numero_orden']) $t .= ' (orden ' . $r['numero_orden'] . ')';
            $L[] = $t;
        }

        // Pagos: validados vs pendientes de validación
        $q = $pdo->prepare(
            "SELECT estado, COUNT(*) n, COALESCE(SUM(monto),0) monto, MAX(moneda) moneda
               FROM pagos_cliente
              WHERE cliente_id = ? AND deleted_at IS NULL
              GROUP BY estado");
        $q->execute([$clienteId]);
        foreach ($q as $r) {
            $lbl = $r['estado'] === 'validado' ? 'pagos validados'
                 : ($r['estado'] === 'pendiente' ? 'pagos por validar' : 'pagos (' . $r['estado'] . ')');
            $L[] = ucfirst($lbl) . ': ' . $r['n'] . ' — ' . ($r['moneda'] ?: 'S/') . ' ' . number_format((float) $r['monto'], 2);
        }

        return implode("\n", $L);
    }

    private static function estadoPropuestaLabel(string $e): string
    {
        return [
            'enviada'   => 'enviada (pendiente de respuesta del cliente)',
            'aceptada'  => 'aceptada',
            'rechazada' => 'rechazada',
            'vencida'   => 'vencida',
            'desistida' => 'el cliente desistió',
        ][$e] ?? $e;
    }

    // ── Sugerencia de respuesta ──────────────────────────────────────────────
    /**
     * @param array  $mensajes  Hilo: [['dir'=>'in|out','texto'=>..,'tipo'=>..], ...] orden cronológico.
     * @param string $ctxCliente Resumen interno (contextoCliente) o ''.
     * @param string $empresaTitulo Nombre visible del contacto/empresa.
     * @return array ['ok'=>bool, 'texto'=>string, 'error'=>?string]
     */
    public static function sugerir(array $mensajes, string $ctxCliente, string $empresaTitulo = ''): array
    {
        self::$lastError = null;

        if (!self::disponible()) {
            return ['ok' => false, 'error' => 'El copiloto de IA no está configurado (falta la API key en .env).'];
        }

        $hilo = self::hiloTexto($mensajes);
        if ($hilo === '') {
            return ['ok' => false, 'error' => 'No hay mensajes en la conversación para sugerir una respuesta.'];
        }

        $system = self::promptSistema();
        $user   = "DATOS INTERNOS DEL CLIENTE (para responder con información real; no los repitas textualmente si no vienen al caso):\n"
                . ($ctxCliente !== '' ? $ctxCliente : '(sin datos internos vinculados a este número)')
                . "\n\n----\nCONVERSACIÓN DE WHATSAPP (in = cliente, out = nosotros)"
                . ($empresaTitulo !== '' ? " con {$empresaTitulo}" : '') . ":\n"
                . $hilo
                . "\n\n----\nRedacta ÚNICAMENTE el texto del próximo mensaje que nosotros (GGI) le enviaríamos al cliente. "
                . "Sin saludos protocolarios largos, sin explicar tu razonamiento, solo el mensaje listo para enviar.";

        // Cadena de respaldo: intenta cada motor configurado hasta que uno responda.
        $chain = self::chain();
        if (!$chain) {
            return ['ok' => false, 'error' => 'El copiloto de IA no está configurado (falta la API key en .env).'];
        }

        $errs = [];
        foreach ($chain as $prov) {
            switch ($prov) {
                case 'openai': $texto = self::llamarOpenAI($system, $user); break;
                case 'gemini': $texto = self::llamarGemini($system, $user); break;
                default:       $texto = self::llamarClaude($system, $user); break;
            }
            if ($texto !== null && trim($texto) !== '') {
                return ['ok' => true, 'texto' => trim($texto), 'error' => null, 'motor' => $prov];
            }
            if (self::$lastError) $errs[] = self::$lastError;
        }

        self::$lastError = $errs ? implode(' | ', $errs) : null;
        return ['ok' => false, 'error' => self::$lastError ?: 'No se pudo generar la sugerencia.'];
    }

    private static function hiloTexto(array $mensajes): string
    {
        $out = [];
        // Solo los últimos ~25 mensajes con texto real, para no inflar el prompt.
        $mensajes = array_slice($mensajes, -25);
        foreach ($mensajes as $m) {
            $dir  = ($m['dir'] ?? 'in') === 'out' ? 'NOSOTROS' : 'CLIENTE';
            $txt  = trim((string) ($m['texto'] ?? ''));
            if ($txt === '') {
                $tipo = $m['tipo'] ?? '';
                $txt  = $tipo ? '[' . $tipo . ']' : '';
            }
            if ($txt === '') continue;
            $out[] = $dir . ': ' . $txt;
        }
        return implode("\n", $out);
    }

    private static function promptSistema(): string
    {
        return "Eres el asistente de redacción del equipo de Group Global ISO (GGI), una empresa peruana "
             . "que gestiona certificaciones de normas ISO para otras empresas. Ayudas a un EJECUTIVO a "
             . "responderle a un cliente por WhatsApp: redactas el mensaje que el ejecutivo va a revisar y enviar.\n\n"
             . "Reglas:\n"
             . "- Español peruano, tono profesional pero cercano y claro. Trata de \"usted\".\n"
             . "- Sé breve y directo, apto para WhatsApp. Sin firmas ni encabezados de correo.\n"
             . "- Usa SOLO la información de la conversación y de los datos internos provistos. "
             . "NUNCA inventes precios, fechas, números de certificado ni estados que no estén ahí.\n"
             . "- Si falta un dato para responder bien, redacta un mensaje que pida ese dato de forma amable, "
             . "en vez de inventarlo.\n"
             . "- No prometas descuentos ni plazos que no aparezcan en los datos.\n"
             . "- Si el cliente está molesto o el tema es delicado (reclamo, negociación), redacta con empatía "
             . "y ofrece escalarlo con el equipo, sin comprometer nada.\n"
             . "- Devuelve únicamente el texto del mensaje, sin comillas ni explicaciones.";
    }

    // ── Motores ──────────────────────────────────────────────────────────────
    private static function llamarClaude(string $system, string $user): ?string
    {
        $payload = [
            'model'      => IA_ANTHROPIC_MODEL,
            'max_tokens' => 700,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ];
        $res = self::http('https://api.anthropic.com/v1/messages', $payload, [
            'x-api-key: ' . IA_ANTHROPIC_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ]);
        if ($res === null) return null;
        $t = $res['content'][0]['text'] ?? null;
        if ($t === null) { self::$lastError = 'Respuesta de Claude sin texto.'; return null; }
        return $t;
    }

    private static function llamarOpenAI(string $system, string $user): ?string
    {
        $payload = [
            'model'    => IA_OPENAI_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'max_tokens'  => 700,
            'temperature' => 0.5,
        ];
        $res = self::http('https://api.openai.com/v1/chat/completions', $payload, [
            'Authorization: Bearer ' . IA_OPENAI_KEY,
            'Content-Type: application/json',
        ]);
        if ($res === null) return null;
        $t = $res['choices'][0]['message']['content'] ?? null;
        if ($t === null) { self::$lastError = 'Respuesta de OpenAI sin texto.'; return null; }
        return $t;
    }

    private static function llamarGemini(string $system, string $user): ?string
    {
        $keys = self::geminiKeys();
        if (!$keys) { self::$lastError = 'Falta IA_GEMINI_KEY en .env'; return null; }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig'   => ['maxOutputTokens' => 700, 'temperature' => 0.5],
        ];

        $ultimoErr = null;
        foreach ($keys as $i => $key) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                 . IA_GEMINI_MODEL . ':generateContent?key=' . urlencode($key);
            $r = self::postJson($url, $payload, ['Content-Type: application/json']);

            if ($r['code'] >= 200 && $r['code'] < 300) {
                $t = $r['body']['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($t !== null && trim($t) !== '') return $t;
                // Bloqueado por filtros de seguridad u otra razón sin texto
                $ultimoErr = 'Gemini (cuenta ' . ($i + 1) . ') no devolvió texto'
                           . (!empty($r['body']['promptFeedback']['blockReason'])
                               ? ' (bloqueado: ' . $r['body']['promptFeedback']['blockReason'] . ')' : '');
                break; // otra cuenta daría lo mismo con el mismo contenido
            }

            $msg = $r['body']['error']['message'] ?? $r['err'] ?? ('HTTP ' . $r['code']);
            $ultimoErr = 'Gemini (cuenta ' . ($i + 1) . '): ' . $msg;
            error_log('[IaAgente] Gemini cuenta ' . ($i + 1) . ' HTTP ' . $r['code'] . ': ' . $msg);

            // Cuota agotada (429) o servicio saturado (503) → probar la siguiente cuenta.
            // Cualquier otro error (llave inválida, request malo) no se arregla cambiando de cuenta.
            if (!in_array($r['code'], [429, 503], true)) break;
        }

        self::$lastError = mb_substr($ultimoErr ?? 'No se pudo generar con Gemini.', 0, 400);
        return null;
    }

    /** POST JSON genérico; devuelve el array decodificado o null (con lastError). */
    private static function http(string $url, array $payload, array $headers): ?array
    {
        $r = self::postJson($url, $payload, $headers);
        if ($r['err'] !== null) {
            self::$lastError = 'Error de red con el motor de IA: ' . $r['err'];
            error_log('[IaAgente] cURL: ' . $r['err']);
            return null;
        }
        if ($r['code'] >= 200 && $r['code'] < 300) return $r['body'];

        $msg = $r['body']['error']['message'] ?? ('HTTP ' . $r['code']);
        self::$lastError = mb_substr('IA HTTP ' . $r['code'] . ': ' . $msg, 0, 400);
        error_log('[IaAgente] API error HTTP ' . $r['code'] . ': ' . $msg);
        return null;
    }

    /**
     * cURL POST JSON de bajo nivel.
     * @return array ['code'=>int, 'body'=>array, 'err'=>?string]
     */
    private static function postJson(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) return ['code' => 0, 'body' => [], 'err' => $curlErr];
        $data = $response ? json_decode($response, true) : [];
        return ['code' => $httpCode, 'body' => is_array($data) ? $data : [], 'err' => null];
    }
}
