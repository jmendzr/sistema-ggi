<?php
/**
 * ShalomSedesService
 * Lista los destinos de Shalom Courier desde su API pública.
 * Incluye variante AEREO cuando aplica.
 * Cachea el resultado por 24 h en /tmp/shalom_sedes.json.
 */
class ShalomSedesService {

    // Hosts de la API Shalom. `newwebservices` NO exige las cabeceras de firma que
    // `serviceswebapi` empezó a pedir (jun→2026, HTTP 401 SIGNATURE_HEADERS_MISSING)
    // → se usa primero. post() itera y usa el primero que entregue data usable.
    private const API_BASES = [
        'https://newwebservices.shalomcontrol.com/api/v1/web/agencias/listar',
        'https://serviceswebapi.shalomcontrol.com/api/v1/web/agencias/listar',
    ];
    private const HMAC_KEY  = '.Ov3rsku112024l4r43l.';
    private const AES_KEY   = 'uQn/bQ94PXBEfId70zjN+VE1hSU7kh9VBXTOUd68Ssc='; // desencripta respuestas {encrypted:true}
    private const CACHE_TTL = 86400;

    // Cache DURABLE (storage/cache/) — antes vivía en /tmp y se perdía al limpiarse
    // el sistema, dejando el dropdown vacío cuando la API fallaba.
    private static function cacheFile(): string {
        $dir = __DIR__ . '/../storage/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/shalom_sedes.json';
    }

    private static function decrypt(string $b64): mixed {
        $key = base64_decode(self::AES_KEY);
        $raw = base64_decode($b64);
        $dec = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $dec ? (json_decode($dec, true) ?? $dec) : null;
    }

    private static function buildToken(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        $ts  = time() + 30;
        $pay = 'web-' . $uuid . '@' . $ts;
        return $pay . '@' . hash_hmac('sha256', $pay, self::HMAC_KEY);
    }

    /**
     * Devuelve el listado de destinos Shalom (terrestres + aéreos).
     * Formato label: "DEPARTAMENTO / PROVINCIA / ZONA / LUGAR" (y " - AEREO" cuando aplica).
     * Idéntico al dropdown de pro.shalom.pe/registroindividual.
     */
    public static function listar(): array {
        $cache = self::cacheFile();

        // Cargar cache existente (aunque esté expirado) para usarlo como respaldo.
        $cacheData = null;
        if (file_exists($cache)) {
            $d = json_decode(file_get_contents($cache), true);
            if (is_array($d) && count($d) > 10) $cacheData = $d;
        }

        // Cache fresco → devolver directo.
        if ($cacheData !== null && (time() - filemtime($cache)) < self::CACHE_TTL) {
            return $cacheData;
        }

        // Descargar fresco: iterar hosts. `newwebservices` responde sin exigir las
        // cabeceras de firma (serviceswebapi ahora da 401). Si llega encriptada
        // ({encrypted:true,data:...}) se desencripta con AES.
        $list = [];
        foreach (self::API_BASES as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . self::buildToken(),
                    'Content-Type: application/x-www-form-urlencoded',
                    'Origin: https://shalom.com.pe',
                    'Referer: https://shalom.com.pe/',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200 || !$body) continue;

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) continue;
            if (!empty($decoded['encrypted'])) $decoded = self::decrypt($decoded['data'] ?? '');
            $cand = is_array($decoded) ? ($decoded['data'] ?? $decoded) : [];
            if (is_array($cand) && count($cand) > 10) { $list = $cand; break; }
        }

        // Si ningún host dio data usable → respaldo con el cache previo (aunque venza).
        if (empty($list)) {
            if ($cacheData) @touch($cache); // evita reintentar en cada request por 24h
            return $cacheData ?? [];
        }

        $result = [];
        foreach ($list as $a) {
            // Solo destinos activos
            if ((int)($a['destino'] ?? 0) !== 1)        continue;
            if (empty($a['ter_habilitado_OS']))          continue;

            $label = trim($a['nombre'] ?? '');  // ya viene "DEP / PROV / ZONA / LUGAR"
            if (!$label) continue;

            $dep = strtoupper(trim($a['departamento'] ?? ''));

            // Entrada terrestre
            $result[] = [
                'id'           => (int)($a['ter_id'] ?? 0),
                'label'        => $label,
                'departamento' => $dep,
                'aereo'        => 0,
            ];

            // Entrada aérea (misma sede, sufijo - AEREO)
            if ((int)($a['ter_aereo'] ?? 0) === 1) {
                $result[] = [
                    'id'           => (int)('0' . ($a['ter_id'] ?? 0)),
                    'label'        => $label . ' - AEREO',
                    'departamento' => $dep,
                    'aereo'        => 1,
                ];
            }
        }

        usort($result, fn($a, $b) => strcmp($a['label'], $b['label']));

        file_put_contents($cache, json_encode($result, JSON_UNESCAPED_UNICODE));
        return $result;
    }

    public static function departamentos(): array {
        $deps = [];
        foreach (self::listar() as $a) {
            $dep = $a['departamento'];
            if ($dep && !in_array($dep, $deps)) $deps[] = $dep;
        }
        sort($deps);
        return $deps;
    }
}
