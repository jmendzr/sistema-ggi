<?php
/**
 * core/WhatsAppService.php
 *
 * Servicio de notificaciones automáticas vía WhatsApp Business (Meta Cloud API).
 * Proveedor conmutable por WA_PROVIDER (.env):
 *   'kapso' → api.kapso.ai/meta/whatsapp/v24.0/{phone_id}/messages   (X-API-Key)
 *   'meta'  → graph.facebook.com/{v}/{phone_id}/messages             (Bearer token)
 * El payload es idéntico en ambos casos (Kapso solo proxea a Meta).
 *
 * Solo envía mensajes usando Message Templates aprobados por Meta.
 * Las notificaciones a clientes son siempre business-initiated → templates obligatorios.
 *
 * Uso:
 *   WhatsAppService::notifyClient($clienteId, 'envio_registrado', $context);
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class WhatsAppService
{
    /**
     * Motivo del último fallo de sendTemplate(), para poder persistirlo en
     * whatsapp_log. Sin esto el error real solo llegaba al error_log de PHP y
     * en la base quedaba un genérico imposible de diagnosticar.
     */
    private static ?string $lastError = null;

    /**
     * Reconstruye el texto que efectivamente vio el cliente, sustituyendo
     * {{1}}, {{2}}… del cuerpo de la plantilla por los parámetros enviados.
     * Se guarda en el hilo del inbox para que el ejecutivo lea el mensaje real
     * y no solo el nombre técnico de la plantilla.
     */
    private static function renderTemplateTexto(?string $body, array $params, string $templateName): string
    {
        if ($body === null || trim($body) === '') {
            return 'Plantilla: ' . $templateName;
        }

        $i = 0;
        foreach ($params as $valor) {
            $body = str_replace('{{' . (++$i) . '}}', (string) $valor, $body);
        }

        return $body;
    }

    /** Motivo del último fallo de envío (null si el último intento fue bien). */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    // ── Configuración ──────────────────────────────────────────────────────────

    /**
     * Proveedor activo: 'kapso' (proxy, por defecto) o 'meta' (Cloud API nativa).
     * El formato del payload es el MISMO en ambos (Kapso solo proxea a Meta);
     * solo cambian la URL base y el header de autenticación.
     */
    private static function provider(): string
    {
        $p = defined('WA_PROVIDER') ? WA_PROVIDER : 'kapso';
        return strtolower(trim((string) $p)) === 'meta' ? 'meta' : 'kapso';
    }

    /** Credencial del proveedor activo (token de Meta o API key de Kapso). */
    private static function apiKey(): string
    {
        if (self::provider() === 'meta') {
            return defined('META_WA_TOKEN') ? META_WA_TOKEN : '';
        }
        return defined('KAPSO_API_KEY') ? KAPSO_API_KEY : '';
    }

    /** Phone Number ID del proveedor activo. */
    private static function phoneId(): string
    {
        if (self::provider() === 'meta') {
            return defined('META_WA_PHONE_NUMBER_ID') ? META_WA_PHONE_NUMBER_ID : '';
        }
        return defined('KAPSO_PHONE_NUMBER_ID') ? KAPSO_PHONE_NUMBER_ID : '';
    }

    /** Base del endpoint de mensajes según proveedor. */
    private static function apiBase(): string
    {
        if (self::provider() === 'meta') {
            $v = defined('META_WA_VERSION') ? META_WA_VERSION : 'v21.0';
            return 'https://graph.facebook.com/' . $v;
        }
        return 'https://api.kapso.ai/meta/whatsapp/v24.0';
    }

    /** Header de autenticación según proveedor. */
    private static function authHeader(): string
    {
        return self::provider() === 'meta'
            ? 'Authorization: Bearer ' . self::apiKey()
            : 'X-API-Key: ' . self::apiKey();
    }

    // ── Método principal ───────────────────────────────────────────────────────

    /**
     * Envía una notificación WA al contacto del cliente usando el template configurado.
     *
     * @param int    $clienteId  ID del cliente en la tabla clientes
     * @param string $eventCode  Código de evento (debe tener template en whatsapp_templates)
     * @param array  $context    Variables del contexto del evento (razon_social, codigo_envio, etc.)
     */
    public static function notifyClient(int $clienteId, string $eventCode, array $context = []): void
    {
        $pdo = Database::get();

        // ── Cargar template del evento ─────────────────────────────────────────
        $tplStmt = $pdo->prepare(
            "SELECT template_name, language_code, param_keys, url_button_key, body_text FROM whatsapp_templates WHERE event_code = ? AND activo = 1"
        );
        $tplStmt->execute([$eventCode]);
        $tpl = $tplStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tpl) {
            error_log("[WhatsAppService] Sin template activo para evento: {$eventCode}");
            return;
        }

        // ── Cargar datos del cliente ───────────────────────────────────────────
        $cliStmt = $pdo->prepare(
            "SELECT razon_social, contacto_telefono, whatsapp_opt_in FROM clientes WHERE id = ? AND deleted_at IS NULL"
        );
        $cliStmt->execute([$clienteId]);
        $cliente = $cliStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$cliente) {
            error_log("[WhatsAppService] Cliente id={$clienteId} no encontrado");
            return;
        }

        // ── Verificar opt-in ──────────────────────────────────────────────────
        if (!$cliente['whatsapp_opt_in']) {
            self::log($pdo, $clienteId, $eventCode, '', $tpl['template_name'], [], null, 'failed', 'opt_out', $context);
            return;
        }

        // ── Resolver teléfono ─────────────────────────────────────────────────
        // propuesta_aceptada: usar el contacto de la cotización (persona que negoció)
        // demás eventos: usar el contacto principal del cliente empresa
        $rawPhone = $cliente['contacto_telefono'];
        if ($eventCode === 'propuesta_aceptada' && !empty($context['cotizacion_id'])) {
            $cotStmt = $pdo->prepare("SELECT contacto_telefono FROM cotizaciones WHERE id = ?");
            $cotStmt->execute([$context['cotizacion_id']]);
            $cotPhone = $cotStmt->fetchColumn();
            if ($cotPhone) $rawPhone = $cotPhone;
        }

        $phone = self::normalizePhone($rawPhone ?? '');

        if (!$phone) {
            error_log("[WhatsAppService] Sin número válido para cliente_id={$clienteId} evento={$eventCode}");
            self::log($pdo, $clienteId, $eventCode, $rawPhone ?? '', $tpl['template_name'], [], null, 'failed', 'numero_invalido', $context);
            return;
        }

        // ── Construir parámetros del template ─────────────────────────────────
        $paramKeys = json_decode($tpl['param_keys'] ?? '[]', true) ?? [];
        $params    = [];
        foreach ($paramKeys as $key) {
            if ($key === 'razon_social') {
                $params[] = $cliente['razon_social'];
            } elseif ($key === 'portal_url') {
                $params[] = defined('APP_URL') ? APP_URL . '/portal/' : '';
            } else {
                $params[] = (string)($context[$key] ?? '');
            }
        }

        // ── Enviar template (con documento si el contexto lo incluye) ─────────
        $docUrl      = $context['doc_url']      ?? null;  // URL pública del PDF
        $docFilename = $context['doc_filename']  ?? null;  // nombre visible del archivo

        // Botón URL con sufijo dinámico: la plantilla define en whatsapp_templates.url_button_key
        // la clave del contexto cuyo valor es el sufijo (ej: url_button_key='portal_token').
        $btnParam = null;
        if (!empty($tpl['url_button_key'])) {
            $btnParam = (string)($context[$tpl['url_button_key']] ?? '');
        }

        $waId   = self::sendTemplate($phone, $tpl['template_name'], $params, $tpl['language_code'], $docUrl, $docFilename, $btnParam);
        $estado = $waId ? 'sent' : 'failed';
        $error  = $waId ? null   : (self::lastError() ?? 'No se obtuvo message_id de la API');

        self::log($pdo, $clienteId, $eventCode, $phone, $tpl['template_name'], $params, $waId, $estado, $error, $context);

        // El ejecutivo debe ver en el hilo lo que el sistema ya le dijo al cliente,
        // o responderá a ciegas. enviado_por = NULL marca "automático".
        require_once __DIR__ . '/WaInbox.php';
        WaInbox::registrarSaliente(
            $pdo, $phone, $waId, 'template',
            self::renderTemplateTexto($tpl['body_text'] ?? null, $params, $tpl['template_name']),
            $tpl['template_name'], null, $estado, $error
        );
    }

    // ── Envío de template ──────────────────────────────────────────────────────

    /**
     * Llama a la API (Kapso proxy de Meta) y envía un template.
     *
     * @param string      $to           Número normalizado (ej: "51987654321")
     * @param string      $templateName Nombre del template aprobado en Meta
     * @param string[]    $params       Valores para {{1}}, {{2}}, … del body del template
     * @param string      $lang         Código de idioma del template (ej: "es")
     * @param string|null $docUrl       URL pública del PDF a adjuntar como header (opcional)
     * @param string|null $docFilename  Nombre visible del archivo adjunto (opcional)
     *
     * @return string|null  WhatsApp message ID devuelto por la API, o null si falló
     */
    /**
     * Descarga un archivo recibido, a partir del media_id de Meta.
     *
     * Son DOS pasos: primero se pide la metadata (que incluye una URL firmada
     * de vida corta) y después se descarga esa URL con el mismo token. Por eso
     * no se puede guardar la URL: caduca en minutos. El media_id, en cambio,
     * sirve unos 30 días, así que la descarga puede hacerse bajo demanda.
     *
     * @return array{contenido:string,mime:string}|null
     */
    public static function fetchMedia(string $mediaId): ?array
    {
        self::$lastError = null;

        $base  = self::apiBase();
        $token = self::apiKey();
        if (!$token) { self::$lastError = 'Sin credenciales'; return null; }

        $cab = [self::authHeader()];

        // 1) Metadata (url + mime)
        $ch = curl_init($base . '/' . rawurlencode($mediaId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $cab,
        ]);
        $meta = json_decode((string) curl_exec($ch), true);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || empty($meta['url'])) {
            self::$lastError = 'No se pudo resolver el archivo (HTTP ' . $code . ')';
            return null;
        }

        // 2) Descarga real. Requiere el token: la URL sola devuelve 401.
        $ch = curl_init($meta['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $cab, CURLOPT_FOLLOWLOCATION => true,
        ]);
        $bin  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || $bin === false || $bin === '') {
            self::$lastError = 'No se pudo descargar el archivo (HTTP ' . $code . ')';
            return null;
        }

        return ['contenido' => $bin, 'mime' => $meta['mime_type'] ?? 'application/octet-stream'];
    }

    // ══ Gestión de plantillas en Meta ═════════════════════════════════════════

    /** WABA sobre la que se administran las plantillas. */
    private static function wabaId(): string
    {
        return defined('META_WA_WABA_ID') ? META_WA_WABA_ID : '';
    }

    /**
     * Llamada genérica a Graph para la gestión de plantillas.
     * Devuelve [httpCode, arrayDecodificado].
     */
    private static function graph(string $path, string $metodo = 'GET', array $datos = []): array
    {
        $url = self::apiBase() . '/' . ltrim($path, '/');
        $ch  = curl_init();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', self::authHeader()],
            CURLOPT_CUSTOMREQUEST  => $metodo,
        ];
        if ($datos && $metodo !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($datos, JSON_UNESCAPED_UNICODE);
        }
        if ($datos && $metodo === 'GET') {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($datos);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $opts);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) { self::$lastError = 'Error de red: ' . $err; return [0, []]; }

        $d = json_decode((string) $res, true) ?? [];
        if ($code < 200 || $code >= 300) {
            $e = $d['error'] ?? [];
            self::$lastError = mb_substr(
                'HTTP ' . $code . ': ' . ($e['error_user_msg'] ?? $e['message'] ?? (string) $res), 0, 400
            );
        }

        return [$code, $d];
    }

    /**
     * Números conectados a la WABA, con su estado y calidad.
     *
     * Es la vista que reemplaza al panel de Kapso: qué números hay, cuál está
     * conectado y cómo está su reputación ante Meta.
     */
    public static function listarNumeros(): ?array
    {
        self::$lastError = null;
        if (!self::wabaId()) { self::$lastError = 'Falta META_WA_WABA_ID'; return null; }

        [$c, $d] = self::graph(self::wabaId() . '/phone_numbers', 'GET', [
            'limit'  => 50,
            'fields' => 'id,display_phone_number,verified_name,quality_rating,'
                      . 'code_verification_status,platform_type,throughput,status,name_status',
        ]);

        return ($c === 200) ? ($d['data'] ?? []) : null;
    }

    /**
     * Registra un número en Cloud API. Sin esto el número aparece en la cuenta
     * pero no puede enviar ni recibir: es el paso que la gente olvida.
     *
     * @param string $pin PIN de verificación en dos pasos (6 dígitos).
     */
    public static function registrarNumero(string $phoneNumberId, string $pin): bool
    {
        self::$lastError = null;

        [$c, $d] = self::graph($phoneNumberId . '/register', 'POST', [
            'messaging_product' => 'whatsapp',
            'pin'               => $pin,
        ]);

        return ($c >= 200 && $c < 300) && !empty($d['success']);
    }

    /** Lista TODAS las plantillas de la WABA, con su estado y componentes. */
    public static function listarPlantillas(): ?array
    {
        self::$lastError = null;
        if (!self::wabaId()) { self::$lastError = 'Falta META_WA_WABA_ID'; return null; }

        [$c, $d] = self::graph(self::wabaId() . '/message_templates', 'GET', [
            'limit'  => 200,
            'fields' => 'name,language,status,category,components,rejected_reason,quality_score',
        ]);

        return ($c === 200) ? ($d['data'] ?? []) : null;
    }

    /**
     * Crea una plantilla en Meta y la deja EN REVISIÓN.
     *
     * Meta exige ejemplos para cada variable: sin ellos rechaza la creación.
     * La categoría es lo que más rechazos provoca — un texto promocional
     * enviado como UTILITY se rechaza con INCORRECT_CATEGORY.
     *
     * @param array $comp Componentes ya armados (BODY obligatorio).
     */
    public static function crearPlantilla(string $nombre, string $categoria, string $idioma, array $comp): ?array
    {
        self::$lastError = null;
        if (!self::wabaId()) { self::$lastError = 'Falta META_WA_WABA_ID'; return null; }

        [$c, $d] = self::graph(self::wabaId() . '/message_templates', 'POST', [
            'name'       => $nombre,
            'category'   => $categoria,
            'language'   => $idioma,
            'components' => $comp,
        ]);

        return ($c >= 200 && $c < 300) ? $d : null;
    }

    /** Elimina una plantilla de Meta por nombre (borra todos sus idiomas). */
    public static function eliminarPlantilla(string $nombre): bool
    {
        self::$lastError = null;
        if (!self::wabaId()) { self::$lastError = 'Falta META_WA_WABA_ID'; return false; }

        [$c, $d] = self::graph(self::wabaId() . '/message_templates', 'DELETE', ['name' => $nombre]);

        return ($c >= 200 && $c < 300) && !empty($d['success']);
    }

    /**
     * Convierte un GIF animado a WebP animado para poder enviarlo como sticker.
     *
     * Meta no admite image/gif, y sin ffmpeg no se puede pasar a MP4. Pero
     * WhatsApp SÍ anima los stickers en WebP animado, y de eso sí es capaz
     * Imagick. Es la única vía para que un GIF se mueva en el chat.
     *
     * Límite del sticker animado: 500 KB. Si no entra ni bajando calidad,
     * devuelve null y quien llama debe caer al envío como documento.
     *
     * @return string|null  Ruta temporal del .webp, o null si no fue posible.
     */
    public static function gifAStickerWebp(string $rutaGif): ?string
    {
        if (!class_exists('Imagick')) return null;

        try {
            $im = new \Imagick($rutaGif);
            if ($im->getNumberImages() < 1) { $im->clear(); return null; }

            $im = $im->coalesceImages();
            foreach ($im as $frame) {
                // Los stickers son cuadrados de 512 px; bestfit conserva proporción
                $frame->resizeImage(512, 512, \Imagick::FILTER_LANCZOS, 1, true);
                $frame->setImageBackgroundColor(new \ImagickPixel('transparent'));
            }
            $im = $im->deconstructImages();
            $im->setImageFormat('webp');
            // setImageIterations, no setImageLoopCount: esa no existe en la
            // versión de Imagick del servidor (6.9.13) y lanza excepción.
            $im->setImageIterations(0);   // animación infinita

            $salida = tempnam(sys_get_temp_dir(), 'ggisticker') . '.webp';

            // Bajar calidad progresivamente hasta entrar en los 500 KB
            foreach ([80, 60, 45, 30] as $calidad) {
                $im->setOption('webp:method', '6');
                $im->setImageCompressionQuality($calidad);
                $im->writeImages($salida, true);   // true = un solo archivo animado

                if (is_file($salida) && filesize($salida) <= 500 * 1024) {
                    $im->clear();
                    return $salida;
                }
            }

            $im->clear();
            @unlink($salida);
            return null;   // no entra en el límite ni al mínimo de calidad
        } catch (\Throwable $e) {
            error_log('[WhatsAppService] gifAStickerWebp: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sube un archivo a Meta y devuelve su media_id, necesario para enviarlo.
     */
    public static function uploadMedia(string $rutaLocal, string $mime): ?string
    {
        self::$lastError = null;

        $phoneId = self::phoneId();
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return null; }
        if (!is_readable($rutaLocal))     { self::$lastError = 'Archivo ilegible'; return null; }

        $ch = curl_init(self::apiBase() . "/{$phoneId}/media");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [self::authHeader()],
            CURLOPT_POSTFIELDS     => [
                'messaging_product' => 'whatsapp',
                'type'              => $mime,
                'file'              => new \CURLFile($rutaLocal, $mime, basename($rutaLocal)),
            ],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true);
        if ($code >= 200 && $code < 300 && !empty($d['id'])) return $d['id'];

        self::$lastError = mb_substr('HTTP ' . $code . ': ' . ($d['error']['message'] ?? (string) $res), 0, 400);
        error_log('[WhatsAppService] uploadMedia: ' . self::$lastError);

        return null;
    }

    /**
     * Envía un archivo ya subido (media_id). Como el texto libre, solo es
     * válido dentro de la ventana de 24 h.
     */
    public static function sendMedia(string $to, string $tipo, string $mediaId,
                                     ?string $caption = null, ?string $nombre = null): ?string
    {
        self::$lastError = null;

        $phoneId = self::phoneId();
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return null; }

        $obj = ['id' => $mediaId];
        // Solo image, video y document admiten pie de foto
        if ($caption !== null && $caption !== '' && in_array($tipo, ['image', 'video', 'document'], true)) {
            $obj['caption'] = $caption;
        }
        if ($tipo === 'document' && $nombre) $obj['filename'] = $nombre;

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $tipo,
            $tipo               => $obj,
        ];

        $ch = curl_init(self::apiBase() . "/{$phoneId}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', self::authHeader()],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true);
        if ($code >= 200 && $code < 300) return $d['messages'][0]['id'] ?? null;

        $e      = $d['error'] ?? [];
        $errMsg = $e['message'] ?? (string) $res;
        if (!empty($e['code'])) $errMsg .= ' [code ' . $e['code'] . ']';
        self::$lastError = mb_substr('HTTP ' . $code . ': ' . $errMsg, 0, 400);
        error_log('[WhatsAppService] sendMedia: ' . self::$lastError);

        return null;
    }

    /**
     * Envía un mensaje INTERACTIVO (botones de respuesta o lista).
     * Solo dentro de la ventana de 24 h, como el texto libre.
     *
     * @param array $interactive Objeto 'interactive' ya armado por quien llama.
     */
    public static function sendInteractive(string $to, array $interactive): ?string
    {
        self::$lastError = null;
        $phoneId = self::phoneId();
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return null; }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ];

        $ch = curl_init(self::apiBase() . "/{$phoneId}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', self::authHeader()],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true);
        if ($code >= 200 && $code < 300) return $d['messages'][0]['id'] ?? null;

        $e      = $d['error'] ?? [];
        $errMsg = $e['message'] ?? (string) $res;
        if (!empty($e['code'])) $errMsg .= ' [code ' . $e['code'] . ']';
        if (!empty($e['error_data']['details'])) $errMsg .= ' — ' . $e['error_data']['details'];
        self::$lastError = mb_substr('HTTP ' . $code . ': ' . $errMsg, 0, 400);
        error_log('[WhatsAppService] sendInteractive: ' . self::$lastError);

        return null;
    }

    /**
     * Envía una tarjeta de contacto. Solo dentro de la ventana de 24 h.
     */
    public static function sendContacto(string $to, string $nombre, string $telefono, string $empresa = ''): ?string
    {
        self::$lastError = null;
        $phoneId = self::phoneId();
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return null; }

        $contacto = [
            'name'   => ['formatted_name' => $nombre, 'first_name' => $nombre],
            'phones' => [['phone' => $telefono, 'type' => 'CELL', 'wa_id' => preg_replace('/\D/', '', $telefono)]],
        ];
        if ($empresa !== '') $contacto['org'] = ['company' => $empresa];

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'contacts',
            'contacts'          => [$contacto],
        ];

        $ch = curl_init(self::apiBase() . "/{$phoneId}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', self::authHeader()],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true);
        if ($code >= 200 && $code < 300) return $d['messages'][0]['id'] ?? null;

        $e = $d['error'] ?? [];
        self::$lastError = mb_substr('HTTP ' . $code . ': ' . ($e['message'] ?? (string) $res), 0, 400);
        return null;
    }

    /**
     * Reacciona a un mensaje con un emoji. Emoji vacío = quita la reacción.
     * Solo dentro de la ventana de 24 h.
     */
    public static function reaccionar(string $to, string $waMessageId, string $emoji): ?string
    {
        self::$lastError = null;
        $phoneId = self::phoneId();
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return null; }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'reaction',
            'reaction'          => ['message_id' => $waMessageId, 'emoji' => $emoji],
        ];

        $ch = curl_init(self::apiBase() . "/{$phoneId}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', self::authHeader()],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true);
        if ($code >= 200 && $code < 300) return $d['messages'][0]['id'] ?? 'ok';

        $e = $d['error'] ?? [];
        self::$lastError = mb_substr('HTTP ' . $code . ': ' . ($e['message'] ?? (string) $res), 0, 400);
        return null;
    }

    /**
     * Controla una llamada de la Calling API: pre_accept / accept / reject /
     * terminate. Para pre_accept y accept hay que mandar nuestro SDP answer
     * (generado por WebRTC en el navegador).
     *
     * @param string      $phoneId  phone_number_id que recibió la llamada
     * @param string      $callId   id de la llamada (del webhook connect)
     * @param string      $action   pre_accept | accept | reject | terminate
     * @param string|null $sdp      SDP answer (solo pre_accept/accept)
     * @return array  ['ok'=>bool, 'error'=>string|null, 'raw'=>array]
     */
    public static function llamadaAccion(string $phoneId, string $callId, string $action, ?string $sdp = null): array
    {
        self::$lastError = null;
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return ['ok' => false, 'error' => 'Sin credenciales']; }

        $payload = ['messaging_product' => 'whatsapp', 'call_id' => $callId, 'action' => $action];
        if (in_array($action, ['pre_accept', 'accept'], true) && $sdp) {
            $payload['session'] = ['sdp_type' => 'answer', 'sdp' => $sdp];
        }

        $ch = curl_init(self::apiBase() . "/{$phoneId}/calls");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', self::authHeader()],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true) ?: [];
        if ($code >= 200 && $code < 300) return ['ok' => true, 'error' => null, 'raw' => $d];

        $e = $d['error'] ?? [];
        self::$lastError = mb_substr('HTTP ' . $code . ': ' . ($e['message'] ?? (string) $res), 0, 400);
        error_log('[WhatsAppService] llamadaAccion(' . $action . '): ' . self::$lastError);
        return ['ok' => false, 'error' => self::$lastError, 'raw' => $d];
    }

    /**
     * Inicia una llamada saliente (business-initiated): manda nuestro SDP offer
     * y Meta responde con el call_id. El SDP answer llega después por webhook.
     * @return array ['ok'=>bool, 'call_id'=>?string, 'error'=>?string]
     */
    public static function llamadaIniciar(string $phoneId, string $to, string $sdpOffer): array
    {
        self::$lastError = null;
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return ['ok' => false, 'call_id' => null, 'error' => 'Sin credenciales']; }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'action'            => 'connect',
            'session'           => ['sdp_type' => 'offer', 'sdp' => $sdpOffer],
        ];
        $ch = curl_init(self::apiBase() . "/{$phoneId}/calls");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', self::authHeader()],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true) ?: [];
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'call_id' => $d['calls'][0]['id'] ?? null, 'error' => null];
        }
        $e = $d['error'] ?? [];
        self::$lastError = mb_substr('HTTP ' . $code . ': ' . ($e['message'] ?? (string) $res), 0, 400);
        error_log('[WhatsAppService] llamadaIniciar: ' . self::$lastError);
        return ['ok' => false, 'call_id' => null, 'error' => self::$lastError];
    }

    /** Lee el perfil de negocio (about, descripción, dirección, email, web…). */
    public static function perfilLeer(?string $phoneId = null): ?array
    {
        self::$lastError = null;
        $phoneId = $phoneId ?: self::phoneId();   // por número; default = el del .env
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return null; }

        $fields = 'about,address,description,email,profile_picture_url,websites,vertical';
        $ch = curl_init(self::apiBase() . "/{$phoneId}/whatsapp_business_profile?fields={$fields}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json', self::authHeader()],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true) ?: [];
        if ($code >= 200 && $code < 300) return $d['data'][0] ?? [];
        $e = $d['error'] ?? [];
        self::$lastError = mb_substr('HTTP ' . $code . ': ' . ($e['message'] ?? (string) $res), 0, 400);
        return null;
    }

    /**
     * Actualiza el perfil de negocio. $campos admite: about, address,
     * description, email, vertical, websites (array) y profile_picture_handle.
     */
    public static function perfilActualizar(array $campos, ?string $phoneId = null): bool
    {
        self::$lastError = null;
        $phoneId = $phoneId ?: self::phoneId();   // por número; default = el del .env
        if (!$phoneId || !self::apiKey()) { self::$lastError = 'Sin credenciales'; return false; }

        $payload = array_merge(['messaging_product' => 'whatsapp'], $campos);
        $ch = curl_init(self::apiBase() . "/{$phoneId}/whatsapp_business_profile");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', self::authHeader()],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string) $res, true) ?: [];
        if ($code >= 200 && $code < 300 && !empty($d['success'])) return true;
        if ($code >= 200 && $code < 300) return true;
        $e = $d['error'] ?? [];
        self::$lastError = mb_substr('HTTP ' . $code . ': ' . ($e['message'] ?? (string) $res), 0, 400);
        return false;
    }

    /**
     * Sube una imagen por el "resumable upload" de Meta y devuelve el handle
     * para usar como profile_picture_handle. Requiere APP_ID y APP_SECRET.
     */
    public static function perfilSubirFoto(string $rutaTmp, string $mime): ?string
    {
        self::$lastError = null;
        $appId  = defined('META_APP_ID') ? META_APP_ID : '';
        $secret = defined('META_APP_SECRET') ? META_APP_SECRET : '';
        if (!$appId || !$secret) { self::$lastError = 'Falta META_APP_ID/META_APP_SECRET'; return null; }
        $appTok = $appId . '|' . $secret;
        $len    = filesize($rutaTmp);
        $v      = defined('META_WA_VERSION') ? META_WA_VERSION : 'v21.0';

        // 1) Iniciar sesión de subida
        $ch = curl_init("https://graph.facebook.com/{$v}/{$appId}/uploads?file_length={$len}&file_type=" . rawurlencode($mime) . "&access_token=" . rawurlencode($appTok));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $r = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        $session = $r['id'] ?? null;   // "upload:XXXX"
        if (!$session) { self::$lastError = 'No se pudo iniciar la subida: ' . json_encode($r); return null; }

        // 2) Subir los bytes (file_offset: 0)
        $ch = curl_init("https://graph.facebook.com/{$v}/{$session}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => file_get_contents($rutaTmp),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . $appTok, 'file_offset: 0'],
        ]);
        $r = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        if (empty($r['h'])) { self::$lastError = 'No se obtuvo el handle de la imagen: ' . json_encode($r); return null; }
        return $r['h'];
    }

    /**
     * Envía un mensaje de TEXTO LIBRE (no plantilla).
     *
     * Solo es válido dentro de la ventana de servicio de 24 h de Meta, contada
     * desde el último mensaje entrante del cliente. Fuera de ella la API
     * rechaza el envío (error 131047) y hay que usar una plantilla aprobada.
     * Quien llama debe verificar la ventana ANTES: aquí no se puede saber.
     *
     * @return string|null  message_id de WhatsApp, o null si falló
     *                      (el motivo queda en self::lastError()).
     */
    public static function sendText(string $to, string $texto, ?string $responderA = null): ?string
    {
        self::$lastError = null;

        $apiKey  = self::apiKey();
        $phoneId = self::phoneId();

        if (!$apiKey || !$phoneId) {
            self::$lastError = 'Credenciales de WhatsApp (' . self::provider() . ') no configuradas en .env';
            error_log('[WhatsAppService] ' . self::$lastError);
            return null;
        }

        $texto = trim($texto);
        if ($texto === '') {
            self::$lastError = 'El mensaje está vacío';
            return null;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            // preview_url=false: evita que WhatsApp genere tarjetas de enlaces
            // que descuadran el hilo del inbox.
            'text'              => ['preview_url' => false, 'body' => $texto],
        ];

        // Responder citando un mensaje anterior (el cliente ve la cita)
        if ($responderA) $payload['context'] = ['message_id' => $responderA];

        $ch = curl_init(self::apiBase() . "/{$phoneId}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                self::authHeader(),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            self::$lastError = "Error de red: {$curlErr}";
            error_log("[WhatsAppService] cURL error (texto): {$curlErr}");
            return null;
        }

        $data = $response ? json_decode($response, true) : [];

        if ($httpCode >= 200 && $httpCode < 300) {
            $id = $data['messages'][0]['id'] ?? null;
            if (!$id) {
                self::$lastError = mb_substr('HTTP ' . $httpCode . ' sin message_id: ' . (string) $response, 0, 400);
            }
            return $id;
        }

        $e      = $data['error'] ?? [];
        $errMsg = $e['message'] ?? (string) $response;
        if (!empty($e['code']))                  $errMsg .= ' [code ' . $e['code'] . ']';
        if (!empty($e['error_data']['details'])) $errMsg .= ' — ' . $e['error_data']['details'];

        self::$lastError = mb_substr('HTTP ' . $httpCode . ': ' . $errMsg, 0, 400);
        error_log("[WhatsAppService] API error texto HTTP {$httpCode} to={$to}: {$errMsg}");

        return null;
    }

    public static function sendTemplate(
        string  $to,
        string  $templateName,
        array   $params         = [],
        string  $lang           = 'es',
        ?string $docUrl         = null,
        ?string $docFilename    = null,
        ?string $urlButtonParam = null   // sufijo dinámico del botón URL (ej: token del portal)
    ): ?string {
        self::$lastError = null;

        $apiKey  = self::apiKey();
        $phoneId = self::phoneId();

        if (!$apiKey || !$phoneId) {
            self::$lastError = 'Credenciales de WhatsApp (' . self::provider() . ') no configuradas en .env';
            error_log('[WhatsAppService] ' . self::$lastError);
            return null;
        }

        $components = [];

        // Header con documento PDF (si aplica)
        if ($docUrl) {
            $components[] = [
                'type'       => 'header',
                'parameters' => [[
                    'type'     => 'document',
                    'document' => array_filter([
                        'link'     => $docUrl,
                        'filename' => $docFilename ?: basename($docUrl),
                    ]),
                ]],
            ];
        }

        if (!empty($params)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_values(array_map(
                    fn($v) => ['type' => 'text', 'text' => (string)$v],
                    $params
                )),
            ];
        }

        // Botón URL con sufijo dinámico (la plantilla en Meta define la URL base + {{1}};
        // aquí se envía solo el sufijo, ej. el token del portal).
        if ($urlButtonParam !== null && $urlButtonParam !== '') {
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => '0',
                'parameters' => [['type' => 'text', 'text' => (string)$urlButtonParam]],
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $lang],
                'components' => $components,
            ],
        ];

        // URL según proveedor activo (kapso proxy | meta Cloud API nativa)
        $url = self::apiBase() . "/{$phoneId}/messages";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                self::authHeader(),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            self::$lastError = "Error de red: {$curlErr}";
            error_log("[WhatsAppService] cURL error: {$curlErr}");
            return null;
        }

        $data = $response ? json_decode($response, true) : [];

        if ($httpCode >= 200 && $httpCode < 300) {
            $id = $data['messages'][0]['id'] ?? null;
            if (!$id) {
                self::$lastError = mb_substr('HTTP ' . $httpCode . ' sin message_id: ' . (string) $response, 0, 400);
                error_log("[WhatsAppService] {$templateName}: " . self::$lastError);
            }
            return $id;
        }

        // Detalle completo de Meta: message + code + error_data.details, que es
        // donde viene el motivo real (plantilla inexistente, no aprobada, etc.)
        $e      = $data['error'] ?? [];
        $errMsg = $e['message'] ?? (string) $response;
        if (!empty($e['code']))                  $errMsg .= ' [code ' . $e['code'] . ']';
        if (!empty($e['error_data']['details'])) $errMsg .= ' — ' . $e['error_data']['details'];

        // whatsapp_log.error_msg es varchar(400): recortar el total, no solo el detalle
        self::$lastError = mb_substr('HTTP ' . $httpCode . ': ' . $errMsg, 0, 400);
        error_log("[WhatsAppService] API error HTTP {$httpCode} template={$templateName} to={$to}: {$errMsg}");
        return null;
    }

    // ── Utilities ──────────────────────────────────────────────────────────────

    /**
     * Normaliza un número de teléfono peruano al formato E.164 sin '+'.
     * Acepta: "987654321", "+51987654321", "51987654321", "987 654 321"
     * Devuelve "" si el número no es válido.
     */
    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);

        // Quitar prefijo internacional duplicado
        if (strlen($digits) === 11 && str_starts_with($digits, '51')) {
            // ya tiene prefijo 51 + 9 dígitos → OK
        } elseif (strlen($digits) === 9) {
            $digits = '51' . $digits;
        } else {
            return '';  // longitud inesperada
        }

        // Verificar formato final: 51 + 9 dígitos, comenzando con 9 (móvil Perú)
        if (!preg_match('/^519\d{8}$/', $digits)) {
            return '';
        }

        return $digits;
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    private static function log(
        \PDO    $pdo,
        ?int    $clienteId,
        string  $eventCode,
        string  $telefono,
        string  $templateName,
        array   $params,
        ?string $waId,
        string  $estado,
        ?string $errorMsg,
        ?array  $context = null
    ): void {
        try {
            // Guardamos el contexto (sin claves ruidosas de UI) para poder reintentar fielmente.
            $ctxClean = null;
            if (is_array($context) && $context) {
                $drop = ['title', 'message', 'push_body', 'triggered_by'];
                $ctxClean = array_diff_key($context, array_flip($drop));
                $ctxClean = $ctxClean ? json_encode($ctxClean, JSON_UNESCAPED_UNICODE) : null;
            }
            $pdo->prepare("
                INSERT INTO whatsapp_log
                  (cliente_id, event_code, telefono, template_name, params_json, context_json, wa_message_id, estado, error_msg)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $clienteId,
                $eventCode,
                $telefono,
                $templateName,
                $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
                $ctxClean,
                $waId,
                $estado,
                $errorMsg,
            ]);
        } catch (\Throwable $e) {
            error_log('[WhatsAppService] log() error: ' . $e->getMessage());
        }
    }
}
