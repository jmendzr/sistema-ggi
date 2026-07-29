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

    /** ¿Hay un motor con llave lista? Sirve para mostrar/ocultar el botón. */
    public static function disponible(): bool
    {
        return self::provider() === 'openai'
            ? IA_OPENAI_KEY !== ''
            : IA_ANTHROPIC_KEY !== '';
    }

    private static function provider(): string
    {
        $p = strtolower(trim((string) IA_PROVIDER));
        return $p === 'openai' ? 'openai' : 'claude';
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

        $texto = self::provider() === 'openai'
            ? self::llamarOpenAI($system, $user)
            : self::llamarClaude($system, $user);

        if ($texto === null) {
            return ['ok' => false, 'error' => self::$lastError ?: 'No se pudo generar la sugerencia.'];
        }

        return ['ok' => true, 'texto' => trim($texto), 'error' => null];
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

    /** POST JSON genérico; devuelve el array decodificado o null (con lastError). */
    private static function http(string $url, array $payload, array $headers): ?array
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            self::$lastError = 'Error de red con el motor de IA: ' . $curlErr;
            error_log('[IaAgente] cURL: ' . $curlErr);
            return null;
        }
        $data = $response ? json_decode($response, true) : [];
        if ($httpCode >= 200 && $httpCode < 300) return is_array($data) ? $data : [];

        $msg = $data['error']['message'] ?? (string) $response;
        self::$lastError = mb_substr('IA HTTP ' . $httpCode . ': ' . $msg, 0, 400);
        error_log('[IaAgente] API error HTTP ' . $httpCode . ': ' . $msg);
        return null;
    }
}
