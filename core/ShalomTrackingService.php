<?php
/**
 * ShalomTrackingService
 * Consulta el estado de guías Shalom Envíos via su API interna.
 * Requiere: numero_orden (N° de Guía, mínimo 8 dígitos) + codigo_envio (código de orden).
 * Ambos campos aparecen en el comprobante de Shalom que se entrega al remitente.
 */
class ShalomTrackingService {

    // Hosts de la API Shalom (ambos de su propia producción). El primario
    // `newwebservices` NO exige reCAPTCHA en rastrea/buscar; `serviceswebapi` SÍ lo
    // exige (jun 2026) → queda como respaldo por si el primario cae. post() itera.
    private const API_BASES = [
        'https://newwebservices.shalomcontrol.com/api/v1/web',
        'https://serviceswebapi.shalomcontrol.com/api/v1/web',
    ];
    private const HMAC_KEY = '.Ov3rsku112024l4r43l.';
    private const AES_KEY  = 'uQn/bQ94PXBEfId70zjN+VE1hSU7kh9VBXTOUd68Ssc=';

    // Mapeo fase Shalom → id de estado GGI
    // IDs: 7=Por preparar, 1=Preparado, 8=Recepcionado, 3=En tránsito,
    //      4=En distribución, 5=Entregado, 6=Devuelto
    private const MAPA_ESTADOS = [
        'origen'    => 8,   // Recepcionado
        'transito'  => 3,   // En tránsito
        'demora'    => 3,   // En tránsito (con retraso)
        'destino'   => 4,   // En distribución
        'reparto'   => 4,   // En distribución (reparto a domicilio)
        'entregado' => 5,   // Entregado
    ];

    // Etiquetas amigables por fase
    private const FASE_LABELS = [
        'origen'    => 'Recepcionado en agencia de origen',
        'transito'  => 'En tránsito hacia destino',
        'demora'    => 'Demora registrada',
        'destino'   => 'Llegó a agencia de destino',
        'reparto'   => 'En reparto a domicilio',
        'entregado' => 'Entregado al destinatario',
    ];

    // Estado equivalente (estilo Olva) por fase — usado para colorear el timeline en ver.php
    private const FASE_ESTADO = [
        'entregado' => 'ENTREGADO',
        'reparto'   => 'ASIGNADO',
        'destino'   => 'ASIGNADO',
        'transito'  => 'DESPACHADO',
        'demora'    => 'DESPACHADO',
        'origen'    => 'REGISTRADO',
    ];

    // Las 4 fases para el stepper del portal
    public const FASES_STEPPER = [
        1 => ['label' => 'Recepcionado', 'fases' => ['origen']],
        2 => ['label' => 'En tránsito',  'fases' => ['transito', 'demora']],
        3 => ['label' => 'En destino',   'fases' => ['destino', 'reparto']],
        4 => ['label' => 'Entregado',    'fases' => ['entregado']],
    ];

    private static function generateToken(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        $ts   = time() + 30;
        $pay  = 'web-' . $uuid . '@' . $ts;
        return $pay . '@' . hash_hmac('sha256', $pay, self::HMAC_KEY);
    }

    private static function decrypt(string $b64): mixed
    {
        $key = base64_decode(self::AES_KEY);
        $raw = base64_decode($b64);
        $dec = openssl_decrypt(
            substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16)
        );
        return $dec ? (json_decode($dec, true) ?? $dec) : null;
    }

    private static function post(string $path, array $params): array
    {
        $token = self::generateToken();
        $last  = [];
        // Itera hosts: usa el primero que responda sin el bloqueo de reCAPTCHA.
        foreach (self::API_BASES as $base) {
            $ch = curl_init($base . '/' . ltrim($path, '/'));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Origin: https://shalom.com.pe',
                    'Referer: https://shalom.com.pe/',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$body) continue;                       // host caído → siguiente
            $json = json_decode($body, true);
            if (!$json) continue;
            if ($json['encrypted'] ?? false) $json = self::decrypt($json['data']) ?? [];
            if (!is_array($json)) continue;

            // Si este host devolvió el bloqueo de reCAPTCHA (403), probar el siguiente.
            $msg = strtolower((string)($json['message'] ?? ''));
            if ($code === 403 || strpos($msg, 'token de seguridad') !== false) { $last = $json; continue; }

            return $json;   // respuesta utilizable
        }
        return $last;       // todos bloqueados/caídos
    }

    /**
     * Consulta una guía Shalom.
     * $numero  = N° de Guía (campo numero_orden en GGI, mínimo 8 dígitos)
     * $codigo  = Código de orden (campo codigo_envio en GGI)
     */
    public static function consultar(string $numero, string $codigo = ''): ?array
    {
        $numero = trim($numero);
        $codigo = trim($codigo);

        if (!preg_match('/^\d{8,}$/', $numero) || $codigo === '') return null;

        // IMPORTANTE (jun 2026): Shalom cerró `rastrea/buscar` con reCAPTCHA v3 (403
        // "Token de seguridad requerido"). buscar es el ÚNICO que mapea (numero+codigo)→ose_id.
        // NO se puede usar `rastrea/estados` con el numero_orden como ose_id: el numero_orden
        // es el "N° de orden" de Shalom, NO el ose_id → colisiona con OTRA guía y devuelve el
        // tracking equivocado (bug real detectado: marcó "Entregado" con datos de otra orden).
        // Sin ose_id fiable, el tracking automático Shalom NO está disponible → return null.
        $res = self::post('rastrea/buscar', [
            'numero'  => $numero,
            'codigo'  => $codigo,
            'ose_id'  => '',
        ]);

        if (empty($res['success']) || empty($res['data']) || empty($res['data']['ose_id'])) return null;
        $o      = $res['data'];
        $ose_id = $o['ose_id'];   // ÚNICA fuente fiable del ose_id

        // Consultar fases de estado con el ose_id REAL devuelto por buscar
        $fases_raw = [];
        $est = self::post('rastrea/estados', ['ose_id' => $ose_id]);
        if (!empty($est['success'])) {
            $fases_raw = $est['data'] ?? [];
        }

        // Determinar la fase más avanzada alcanzada
        $fase_activa    = 0;
        $estado_ggi_id  = null;
        $estado_label   = 'Recepcionado';

        $orden_fases = ['origen','transito','demora','destino','reparto','entregado'];
        foreach (array_reverse($orden_fases) as $fase) {
            if (!empty($fases_raw[$fase])) {
                $estado_ggi_id = self::MAPA_ESTADOS[$fase];
                $estado_label  = self::FASE_LABELS[$fase];
                foreach (self::FASES_STEPPER as $num => $def) {
                    if (in_array($fase, $def['fases'])) {
                        $fase_activa = $num;
                        break;
                    }
                }
                break;
            }
        }
        if (!$estado_ggi_id && !empty($ose_id)) { $estado_ggi_id = 8; $fase_activa = 1; }

        $sede_origen  = self::formatSede($o['origen']  ?? []);
        $sede_destino = self::formatSede($o['destino'] ?? []);
        $sede_por_fase = [
            'origen' => $sede_origen, 'transito' => '', 'demora' => '',
            'destino' => $sede_destino, 'reparto' => $sede_destino, 'entregado' => $sede_destino,
        ];

        $eventos_friendly = [];
        foreach ($orden_fases as $fase) {
            if (!empty($fases_raw[$fase]) && is_array($fases_raw[$fase])) {
                $ev = $fases_raw[$fase];
                $eventos_friendly[] = [
                    'fase'   => $fase,
                    'estado' => self::FASE_ESTADO[$fase] ?? strtoupper($fase),
                    'label'  => self::FASE_LABELS[$fase],
                    'fecha'  => self::formatFecha($ev['fecha'] ?? ($ev['created_at'] ?? '')),
                    'sede'   => $sede_por_fase[$fase] ?? '',
                    'obs'    => $ev['obs'] ?? '',
                ];
            }
        }
        $eventos_friendly = array_reverse($eventos_friendly);

        return [
            'ose_id'           => $ose_id,
            'numero_orden'     => $o['numero_orden']           ?? $numero,
            'estado_ggi_id'    => $estado_ggi_id,
            'fase_stepper'     => $fase_activa,
            'estado_label'     => $estado_label,
            'stepper_type'     => 'shalom',
            'origen'           => self::formatAgencia($o['origen']  ?? []),
            'destino'          => self::formatAgencia($o['destino'] ?? []),
            'remitente'        => $o['remitente']['nombre']    ?? '',
            'destinatario'     => $o['destinatario']['nombre'] ?? '',
            'contenido'        => $o['contenido']              ?? '',
            'monto'            => $o['monto']                  ?? '',
            'fases_raw'        => $fases_raw,
            'eventos_friendly' => $eventos_friendly,
        ];
    }

    /**
     * Sincroniza el estado del envío si Shalom está más avanzado.
     * Solo avanza, nunca retrocede. Retorna nuevo estado_id o null.
     */
    public static function sincronizar(int $envio_id, PDO $pdo): ?int
    {
        $stmt = $pdo->prepare("
            SELECT e.numero_orden, e.codigo_envio, e.estado_envio_id, e.fecha_recibido,
                   es.orden AS orden_actual
            FROM envios e
            JOIN estados_envio es ON es.id = e.estado_envio_id
            WHERE e.id = ? AND e.deleted_at IS NULL
        ");
        $stmt->execute([$envio_id]);
        $row = $stmt->fetch();
        if (!$row || empty($row['numero_orden']) || empty($row['codigo_envio'])) return null;

        $data = self::consultar($row['numero_orden'], $row['codigo_envio']);
        if (!$data || !$data['estado_ggi_id']) return null;

        $new_id = $data['estado_ggi_id'];

        $ord = $pdo->prepare("SELECT orden FROM estados_envio WHERE id = ?");
        $ord->execute([$new_id]);
        $orden_nuevo = (int)$ord->fetchColumn();

        // Sin cambio de estado — pero corregir fecha_recibido si falta estando Entregado
        if ($orden_nuevo <= (int)$row['orden_actual']) {
            if ((int)$row['estado_envio_id'] === 5 && empty($row['fecha_recibido'])
                && !empty($data['fases_raw']['entregado']['fecha'])) {
                $fecha_rec = date('Y-m-d', strtotime($data['fases_raw']['entregado']['fecha']));
                $pdo->prepare("UPDATE envios SET fecha_recibido=? WHERE id=?")
                    ->execute([$fecha_rec, $envio_id]);
            }
            return null;
        }

        // Avance de estado
        if ($new_id === 5 && !empty($data['fases_raw']['entregado']['fecha'])) {
            $fecha_rec = date('Y-m-d', strtotime($data['fases_raw']['entregado']['fecha']));
            $pdo->prepare("UPDATE envios SET estado_envio_id=?, fecha_recibido=? WHERE id=?")
                ->execute([$new_id, $fecha_rec, $envio_id]);
        } else {
            $pdo->prepare("UPDATE envios SET estado_envio_id=? WHERE id=?")
                ->execute([$new_id, $envio_id]);
        }

        return $new_id;
    }

    /**
     * Sede para el timeline: solo provincia. Ejemplo: "Piura", "Lima"
     */
    private static function formatSede(array $ag): string
    {
        if (empty($ag)) return '';
        $provincia = $ag['provincia'] ?? '';
        return $provincia ? ucwords(strtolower($provincia)) : ($ag['nombre'] ?? '');
    }

    /**
     * Etiqueta completa de agencia para metadata: "Nombre · Distrito, Departamento"
     * Ejemplo: "AV TACNA · Castilla, Piura"
     */
    private static function formatAgencia(array $ag): string
    {
        if (empty($ag)) return '';
        $nombre = $ag['nombre'] ?? '';
        $parts  = array_filter([
            isset($ag['distrito'])     ? ucwords(strtolower($ag['distrito']))     : '',
            isset($ag['departamento']) ? ucwords(strtolower($ag['departamento'])) : '',
        ]);
        $lugar = implode(', ', $parts);
        return $lugar ? $nombre . ' · ' . $lugar : $nombre;
    }

    /**
     * Formatea un datetime de la API de Shalom a formato legible.
     * Entrada: "2026-01-16 17:55:00" → salida: "16/01/2026 17:55"
     * Si no tiene hora significativa → "16/01/2026"
     */
    private static function formatFecha(string $raw): string
    {
        if ($raw === '') return '';
        $ts = strtotime($raw);
        if ($ts === false) return $raw;
        $hora = date('H:i', $ts);
        return $hora !== '00:00'
            ? date('d/m/Y', $ts) . ' ' . $hora
            : date('d/m/Y', $ts);
    }
}
